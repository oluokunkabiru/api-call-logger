<?php

namespace Oluokunkabiru\ApiCallLogger\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Guards the dashboard with the `viewApiCallLogs` gate.
 */
class Authorize
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(Gate::allows('viewApiCallLogs'), 403);

        return $next($request);
    }
}
