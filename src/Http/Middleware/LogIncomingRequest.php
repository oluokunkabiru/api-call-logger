<?php

namespace Oluokunkabiru\ApiCallLogger\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Oluokunkabiru\ApiCallLogger\ApiCallLogger;
use Oluokunkabiru\ApiCallLogger\Models\ApiCallLog;
use Oluokunkabiru\ApiCallLogger\Support\Payload;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs inbound calls (provider webhooks, partner callbacks) next to outbound ones.
 *
 *   Route::post('webhooks/fyatu', ...)->middleware('api-call-logger.inbound');           // provider from path
 *   Route::post('callbacks/pay', ...)->middleware('api-call-logger.inbound:paystack');    // explicit provider
 *   Route::post('callbacks/pay', ...)->middleware('api-call-logger.inbound:paystack,charge-success');
 */
class LogIncomingRequest
{
    public function __construct(protected ApiCallLogger $logger)
    {
    }

    public function handle(Request $request, Closure $next, ?string $provider = null, ?string $context = null): Response
    {
        $start = hrtime(true);
        $response = null;

        try {
            return $response = $next($request);
        } finally {
            $this->logger->record([
                'direction' => ApiCallLog::INBOUND,
                'provider' => $provider ?? $this->providerFor($request),
                'context' => $context ?? config('api-call-logger.inbound.context', 'inbound-webhook'),
                'method' => $request->method(),
                'url' => $request->url(),
                'query' => $request->query(),
                'request_headers' => $request->headers->all(),
                'request_body' => $this->requestBody($request),
                'status_code' => $response?->getStatusCode() ?? 500,
                'duration_ms' => (hrtime(true) - $start) / 1e6,
                'response_headers' => $response?->headers->all() ?? [],
                'response_body' => $this->responseBody($response),
                'meta' => array_filter(['ip' => $request->ip()]),
            ]);
        }
    }

    protected function providerFor(Request $request): string
    {
        $segments = $request->segments();
        $after = config('api-call-logger.inbound.provider_segment_after', 'webhooks');
        $index = $after ? array_search($after, $segments, true) : false;

        if ($index !== false && isset($segments[$index + 1])) {
            return $segments[$index + 1];
        }

        return 'unknown';
    }

    protected function requestBody(Request $request): ?array
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();

        foreach ($request->allFiles() as $name => $files) {
            $body[$name] = collect(is_array($files) ? $files : [$files])
                ->map(fn (UploadedFile $file) => ['name' => $file->getClientOriginalName(), 'mime' => $file->getClientMimeType(), 'size' => $file->getSize()])
                ->all();
        }

        if ($body === [] && ($raw = $request->getContent()) !== '') {
            return Payload::fromString($raw, $request->header('Content-Type'));
        }

        return $body ?: null;
    }

    protected function responseBody(?Response $response): ?array
    {
        $content = $response?->getContent();

        return is_string($content) ? Payload::fromString($content, $response->headers->get('Content-Type')) : null;
    }
}
