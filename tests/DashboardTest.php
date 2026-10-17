<?php

namespace Oluokunkabiru\ApiCallLogger\Tests;

use Illuminate\Support\Facades\Gate;
use Oluokunkabiru\ApiCallLogger\Facades\ApiCallLogger;

class DashboardTest extends TestCase
{
    public function test_dashboard_is_forbidden_outside_local_by_default(): void
    {
        $this->get('api-call-logs')->assertForbidden();
    }

    public function test_dashboard_lists_and_shows_logs_when_gate_allows(): void
    {
        Gate::define('viewApiCallLogs', fn ($user = null) => true);
        $log = ApiCallLogger::log('vtpass', 'POST', 'https://sandbox.vtpass.com/api/pay', ['amount' => 100], ['code' => '000'], 200, context: 'buy-airtime');

        $this->get('api-call-logs')->assertOk()->assertSee('buy-airtime')->assertSee('/api/pay');
        $this->get('api-call-logs?provider=other')->assertOk()->assertDontSee('buy-airtime');
        $this->get("api-call-logs/{$log->id}")->assertOk()->assertSee('&quot;amount&quot;: 100', false)->assertDontSee('Retry call');
    }
}
