<div class="bg-white shadow rounded-lg">
    <div class="px-4 py-3 border-b text-sm font-semibold text-gray-700">{{ $title }}</div>
    @if ($data === null || $data === [])
        <p class="px-4 py-4 text-sm text-gray-400">Empty</p>
    @else
        <pre class="p-4 text-xs overflow-x-auto max-h-[32rem] bg-gray-900 text-gray-100 rounded-b-lg">{{ json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    @endif
</div>
