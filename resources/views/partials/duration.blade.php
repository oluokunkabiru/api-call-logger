@if ($log->duration_ms === null)
    <span class="text-gray-400">—</span>
@else
    <span class="{{ $log->duration_ms < 1000 ? 'text-green-700' : ($log->duration_ms < 4000 ? 'text-amber-700' : 'text-red-700') }}">{{ number_format($log->duration_ms) }} ms</span>
@endif
