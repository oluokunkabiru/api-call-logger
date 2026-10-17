@php
    $status = $log->status_code;
    $class = match (true) {
        $status === null => 'bg-gray-200 text-gray-700',
        $status < 300 => 'bg-green-100 text-green-800',
        $status < 500 => 'bg-amber-100 text-amber-800',
        default => 'bg-red-100 text-red-800',
    };
@endphp
<span class="inline-block px-2 py-0.5 rounded text-xs font-semibold {{ $class }}">{{ $status ?? 'ERR' }}</span>
