@extends('api-call-logger::layout')

@section('content')
    <!-- Last 24h -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white shadow rounded-lg p-5">
            <p class="text-sm text-gray-500">Calls (24h)</p>
            <p class="text-2xl font-semibold">{{ number_format($stats['total']) }}</p>
        </div>
        <div class="bg-white shadow rounded-lg p-5">
            <p class="text-sm text-gray-500">Failed (24h)</p>
            <p class="text-2xl font-semibold {{ $stats['failed'] ? 'text-red-600' : '' }}">
                {{ number_format($stats['failed']) }}
                @if ($stats['total'])
                    <span class="text-sm text-gray-400 font-normal">({{ round($stats['failed'] / $stats['total'] * 100, 1) }}%)</span>
                @endif
            </p>
        </div>
        <div class="bg-white shadow rounded-lg p-5">
            <p class="text-sm text-gray-500">Average latency (24h)</p>
            <p class="text-2xl font-semibold">{{ number_format($stats['avg_ms']) }} ms</p>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="bg-white shadow rounded-lg p-4 mb-6 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3 text-sm">
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="URL or correlation id" class="col-span-2 border rounded px-3 py-2">
        <select name="provider" class="border rounded px-2 py-2">
            <option value="">All providers</option>
            @foreach ($providers as $provider)
                <option value="{{ $provider }}" @selected(($filters['provider'] ?? '') === $provider)>{{ $provider }}</option>
            @endforeach
        </select>
        <select name="direction" class="border rounded px-2 py-2">
            <option value="">Both directions</option>
            <option value="outbound" @selected(($filters['direction'] ?? '') === 'outbound')>Outbound</option>
            <option value="inbound" @selected(($filters['direction'] ?? '') === 'inbound')>Inbound</option>
        </select>
        <select name="outcome" class="border rounded px-2 py-2">
            <option value="">Any outcome</option>
            <option value="success" @selected(($filters['outcome'] ?? '') === 'success')>Success</option>
            <option value="failed" @selected(($filters['outcome'] ?? '') === 'failed')>Failed</option>
        </select>
        <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="border rounded px-2 py-2" title="From">
        <input type="date" name="until" value="{{ $filters['until'] ?? '' }}" class="border rounded px-2 py-2" title="Until">
        <div class="flex gap-2">
            <button class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded px-3 py-2">Filter</button>
            <a href="{{ route('api-call-logger.index') }}" class="border rounded px-3 py-2 text-gray-600">Reset</a>
        </div>
    </form>

    <!-- Table -->
    <div class="bg-white shadow rounded-lg overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3">Provider</th>
                    <th class="px-4 py-3">Method</th>
                    <th class="px-4 py-3">Path</th>
                    <th class="px-4 py-3">Context</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Latency</th>
                    <th class="px-4 py-3">When</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($logs as $log)
                    <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('api-call-logger.show', $log->id) }}'">
                        <td class="px-4 py-3">
                            <span class="font-semibold uppercase text-xs">{{ $log->provider }}</span>
                            @if ($log->isInbound())
                                <span class="ml-1 inline-block px-1.5 rounded bg-sky-100 text-sky-800 text-[10px] font-semibold">IN</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">@include('api-call-logger::partials.method')</td>
                        <td class="px-4 py-3 font-mono text-xs max-w-md truncate" title="{{ $log->url }}">{{ $log->path }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $log->context ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @include('api-call-logger::partials.status')
                            @unless ($log->success)
                                <span class="ml-1 text-red-600 text-xs">failed</span>
                            @endunless
                            @if (($log->attempt ?? 1) > 1)
                                <span class="ml-1 text-gray-500 text-xs" title="Http::retry() attempt">#{{ $log->attempt }}</span>
                            @endif
                            @if ($log->retry_of_id)
                                <span class="ml-1 text-amber-700 text-xs">retry</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">@include('api-call-logger::partials.duration')</td>
                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap" title="{{ $log->created_at }}">{{ $log->created_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">No API calls logged yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $logs->links() }}</div>
@endsection
