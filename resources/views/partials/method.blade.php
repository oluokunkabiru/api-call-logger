@php
    $class = match ($log->method) {
        'GET' => 'bg-gray-100 text-gray-700',
        'POST' => 'bg-green-100 text-green-800',
        'PUT', 'PATCH' => 'bg-amber-100 text-amber-800',
        'DELETE' => 'bg-red-100 text-red-800',
        default => 'bg-gray-100 text-gray-700',
    };
@endphp
<span class="inline-block px-2 py-0.5 rounded text-xs font-semibold font-mono {{ $class }}">{{ $log->method }}</span>
