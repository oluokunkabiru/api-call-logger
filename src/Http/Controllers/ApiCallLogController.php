<?php

namespace Oluokunkabiru\ApiCallLogger\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Oluokunkabiru\ApiCallLogger\ApiCallLogger;
use Oluokunkabiru\ApiCallLogger\Exceptions\RetryNotPossible;
use Oluokunkabiru\ApiCallLogger\Models\ApiCallLog;

class ApiCallLogController extends Controller
{
    /**
     * List and filter logged calls.
     */
    public function index(Request $request)
    {
        $model = $this->model();

        $logs = $model::query()
            ->when($request->query('provider'), fn ($q, $v) => $q->where('provider', $v))
            ->when($request->query('direction'), fn ($q, $v) => $q->where('direction', $v))
            ->when($request->query('method'), fn ($q, $v) => $q->where('method', $v))
            ->when($request->query('context'), fn ($q, $v) => $q->where('context', $v))
            ->when($request->query('tenant'), fn ($q, $v) => $q->where('tenant', $v))
            ->when($request->query('outcome') === 'success', fn ($q) => $q->where('success', true))
            ->when($request->query('outcome') === 'failed', fn ($q) => $q->where('success', false))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status_code', (int) $v))
            ->when($request->query('search'), fn ($q, $v) => $q->where(fn ($q) => $q->where('url', 'like', "%{$v}%")->orWhere('correlation_id', $v)))
            ->when($request->query('from'), fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->query('until'), fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('id')
            ->paginate((int) config('api-call-logger.dashboard.per_page', 25))
            ->withQueryString();

        $since = now()->subDay();
        $stats = [
            'total' => $model::query()->where('created_at', '>=', $since)->count(),
            'failed' => $model::query()->where('created_at', '>=', $since)->where('success', false)->count(),
            'avg_ms' => (int) $model::query()->where('created_at', '>=', $since)->avg('duration_ms'),
        ];

        return view('api-call-logger::index', [
            'logs' => $logs,
            'stats' => $stats,
            'providers' => $model::query()->distinct()->orderBy('provider')->pluck('provider'),
            'filters' => $request->query(),
        ]);
    }

    public function show(int $log, ApiCallLogger $logger)
    {
        $log = $this->model()::query()->with('retries')->findOrFail($log);

        return view('api-call-logger::show', [
            'log' => $log,
            'related' => $log->correlation_id
                ? $this->model()::query()->where('correlation_id', $log->correlation_id)->whereKeyNot($log->getKey())->latest('id')->limit(20)->get()
                : collect(),
            'canRetry' => ! $log->isInbound() && Gate::allows('retryApiCallLogs', [$log]),
            'retryDecision' => $logger->retryDecision($log),
        ]);
    }

    public function retry(Request $request, int $log, ApiCallLogger $logger)
    {
        $log = $this->model()::query()->findOrFail($log);

        abort_unless(Gate::allows('retryApiCallLogs', [$log]), 403);

        try {
            $retried = $logger->retry($log, force: $request->boolean('confirm'));
        } catch (RetryNotPossible $e) {
            return back()->with('api-call-logger.error', $e->getMessage());
        }

        if (! $retried) {
            return back()->with('api-call-logger.error', 'The call was retried but its log record could not be written. Check the application log.');
        }

        return redirect()
            ->route('api-call-logger.show', $retried->getKey())
            ->with($retried->success ? 'api-call-logger.success' : 'api-call-logger.error', $retried->success
                ? "Retry succeeded with HTTP {$retried->status_code}."
                : 'Retry failed: ' . ($retried->status_code ? "HTTP {$retried->status_code}" : ($retried->error ?? 'connection failed')));
    }

    /**
     * @return class-string<ApiCallLog>
     */
    protected function model(): string
    {
        return config('api-call-logger.model', ApiCallLog::class);
    }
}
