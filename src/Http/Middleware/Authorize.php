<?php

namespace Oluokunkabiru\ApiCallLogger\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the dashboard with the `viewApiCallLogs` gate.
 */
class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Gate::allows('viewApiCallLogs'), 403);

        return $next($request);
    }
}
