<?php

namespace Oluokunkabiru\ApiCallLogger\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Oluokunkabiru\ApiCallLogger\Models\ApiCallLog;

/**
 * Fired after a call has been written to the log — hook alerts, metrics or
 * Slack notifications for failing providers onto it.
 */
class ApiCallLogged
{
    use Dispatchable;

    public function __construct(public ApiCallLog $log) {}
}
