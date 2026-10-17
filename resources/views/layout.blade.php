<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'API Call Logs')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800">
    <div class="min-h-screen">
        <!-- Header -->
        <div class="bg-white shadow">
            <div class="max-w-7xl mx-auto py-5 px-4 sm:px-6 lg:px-8 flex items-center justify-between">
                <div>
                    <a href="{{ route('api-call-logger.index') }}" class="text-2xl font-bold text-gray-900">API Call Logs</a>
                    <p class="text-gray-500 text-sm mt-1">Third-party API calls and incoming webhooks</p>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
            @if (session('api-call-logger.success'))
                <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm">{{ session('api-call-logger.success') }}</div>
            @endif
            @if (session('api-call-logger.error'))
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm">{{ session('api-call-logger.error') }}</div>
            @endif

            @yield('content')
        </div>
    </div>
</body>
</html>
