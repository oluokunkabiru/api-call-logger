@extends('api-call-logger::layout')

@section('title', strtoupper($log->provider) . ' ' . $log->method . ' ' . $log->path)

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-3 min-w-0">
            <a href="{{ route('api-call-logger.index') }}" class="text-gray-500 hover:text-gray-800">&larr;</a>
            <span class="font-semibold uppercase">{{ $log->provider }}</span>
            @include('api-call-logger::partials.method')
            <code class="text-sm truncate">{{ $log->fullUrl() }}</code>
        </div>
        @if ($canRetry && ! $retryDecision->blocked())
            <form method="POST" action="{{ route('api-call-logger.retry', $log->id) }}" class="flex items-center gap-3"
                  onsubmit="return confirm('Re-send this request to {{ $log->provider }}? A new log record will be created.')">
                @csrf
                @if ($retryDecision->needsConfirmation())
                    <label class="flex items-center gap-2 text-sm text-amber-800">
                        <input type="checkbox" name="confirm" value="1" required class="rounded">
                        I understand the risks below
                    </label>
                @endif
                <button class="bg-amber-500 hover:bg-amber-600 text-white text-sm rounded px-4 py-2">Retry call</button>
            </form>
        @endif
    </div>

    @if ($canRetry && ($retryDecision->blockers || $retryDecision->confirmations))
        <div class="mb-6 rounded-lg border px-4 py-3 text-sm {{ $retryDecision->blocked() ? 'bg-gray-50 border-gray-200 text-gray-700' : 'bg-amber-50 border-amber-200 text-amber-800' }}">
            <p class="font-semibold mb-1">{{ $retryDecision->blocked() ? 'Retry is not available' : 'Retrying needs confirmation' }}</p>
            <ul class="list-disc ml-5 space-y-0.5">
                @foreach (array_merge($retryDecision->blockers, $retryDecision->blocked() ? [] : $retryDecision->confirmations) as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Details -->
    <div class="bg-white shadow rounded-lg p-6 mb-6 grid grid-cols-2 md:grid-cols-4 gap-6 text-sm">
        <div><p class="text-gray-500">Direction</p><p class="font-semibold capitalize">{{ $log->direction }}</p></div>
        <div><p class="text-gray-500">Context</p><p class="font-semibold">{{ $log->context ?? '—' }}</p></div>
        <div><p class="text-gray-500">Status</p><p>@include('api-call-logger::partials.status') <span class="ml-1 {{ $log->success ? 'text-green-700' : 'text-red-600' }}">{{ $log->success ? 'success' : 'failed' }}</span></p></div>
        <div><p class="text-gray-500">Latency</p><p class="font-semibold">@include('api-call-logger::partials.duration')</p></div>
        <div><p class="text-gray-500">Tenant</p><p class="font-semibold">{{ $log->tenant ?? '—' }}</p></div>
        <div><p class="text-gray-500">User</p><p class="font-semibold">{{ $log->user_id ?? '—' }}</p></div>
        <div><p class="text-gray-500">Related record</p><p class="font-semibold">{{ $log->loggable_type ? class_basename($log->loggable_type) . ' #' . $log->loggable_id : '—' }}</p></div>
        <div><p class="text-gray-500">Logged at</p><p class="font-semibold">{{ $log->created_at }}</p></div>
        <div><p class="text-gray-500">Attempt</p><p class="font-semibold">{{ $log->attempt ?? 1 }}</p></div>
        <div><p class="text-gray-500">Idempotency key</p><p class="font-mono text-xs break-all">{{ $log->idempotency_key ?? '—' }}</p></div>
        <div class="col-span-2"><p class="text-gray-500">Correlation id</p><p class="font-mono text-xs">{{ $log->correlation_id ?? '—' }}</p></div>
        @if ($log->retry_of_id)
            <div class="col-span-2"><p class="text-gray-500">Retry of</p><a class="text-indigo-600" href="{{ route('api-call-logger.show', $log->retry_of_id) }}">#{{ $log->retry_of_id }}</a></div>
        @endif
        @if ($log->error)
            <div class="col-span-full"><p class="text-gray-500">Error</p><p class="text-red-700 font-mono text-xs break-all">{{ $log->error }}</p></div>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        @include('api-call-logger::partials.json', ['title' => 'Request body', 'data' => $log->request_body])
        @include('api-call-logger::partials.json', ['title' => 'Response body', 'data' => $log->response_body])
        @include('api-call-logger::partials.json', ['title' => 'Request headers', 'data' => $log->request_headers])
        @include('api-call-logger::partials.json', ['title' => 'Response headers', 'data' => $log->response_headers])
        @if ($log->query)
            @include('api-call-logger::partials.json', ['title' => 'Query string', 'data' => $log->query])
        @endif
        @if ($log->meta)
            @include('api-call-logger::partials.json', ['title' => 'Meta', 'data' => $log->meta])
        @endif
    </div>

    @foreach (['Retries' => $log->retries, 'Other calls in the same request' => $related] as $heading => $items)
        @if ($items->isNotEmpty())
            <div class="bg-white shadow rounded-lg mb-6">
                <div class="px-4 py-3 border-b text-sm font-semibold text-gray-700">{{ $heading }}</div>
                <ul class="divide-y text-sm">
                    @foreach ($items as $item)
                        <li class="px-4 py-2 flex items-center gap-3">
                            @include('api-call-logger::partials.status', ['log' => $item])
                            <a class="text-indigo-600 font-mono text-xs truncate" href="{{ route('api-call-logger.show', $item->id) }}">{{ strtoupper($item->provider) }} {{ $item->method }} {{ $item->path }}</a>
                            <span class="ml-auto text-gray-400 whitespace-nowrap">{{ $item->created_at?->diffForHumans() }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endforeach
@endsection
