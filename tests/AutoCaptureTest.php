<?php

namespace Oluokunkabiru\ApiCallLogger\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Oluokunkabiru\ApiCallLogger\Models\ApiCallLog;

class AutoCaptureTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('api-call-logger.capture.http_client', true);
        $app['config']->set('api-call-logger.capture.except_hosts', ['*.internal']);
        $app['config']->set('api-call-logger.providers', [
            'paystack' => ['hosts' => ['api.paystack.co']],
        ]);
    }

    public function test_plain_http_calls_are_captured_with_provider_from_host(): void
    {
        Http::fake(['*' => Http::response(['status' => true])]);

        Http::post('https://api.paystack.co/transaction/initialize', ['amount' => 5000]);
        Http::get('https://unknown.example.com/ping');
        Http::get('https://metrics.internal/ping');

        $logs = ApiCallLog::orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('paystack', $logs[0]->provider);
        $this->assertSame(['amount' => 5000], $logs[0]->request_body);
        $this->assertSame('unknown.example.com', $logs[1]->provider);
    }

    public function test_calls_logged_by_the_macro_are_not_logged_twice(): void
    {
        Http::fake(['*' => Http::response(['status' => true])]);

        Http::withApiLogging('paystack', 'init')->post('https://api.paystack.co/transaction/initialize');

        $this->assertSame(1, ApiCallLog::count());
        $this->assertSame('init', ApiCallLog::sole()->context);
    }

    public function test_real_connection_failures_logged_by_the_macro_are_not_logged_twice(): void
    {
        try {
            // Nothing listens on port 1, so cURL fails without any fake involved.
            Http::withApiLogging('local')->timeout(2)->get('http://127.0.0.1:1/nothing');
        } catch (ConnectionException) {
        }

        $this->assertSame(1, ApiCallLog::count());
        $this->assertSame('local', ApiCallLog::sole()->provider);
    }
}
