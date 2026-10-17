<?php

namespace Oluokunkabiru\ApiCallLogger\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Oluokunkabiru\ApiCallLogger\Events\ApiCallLogged;
use Oluokunkabiru\ApiCallLogger\Facades\ApiCallLogger;
use Oluokunkabiru\ApiCallLogger\Models\ApiCallLog;

class OutboundLoggingTest extends TestCase
{
    public function test_http_macro_logs_request_and_response(): void
    {
        Event::fake([ApiCallLogged::class]);
        Http::fake(['sandbox.vtpass.com/*' => Http::response(['code' => '000', 'token' => 'secret-token'], 200)]);

        $response = Http::withApiLogging('vtpass', 'buy-airtime')
            ->withHeaders(['api-key' => 'live-key', 'Accept' => 'application/json'])
            ->post('https://sandbox.vtpass.com/api/pay?ref=abc', ['phone' => '0803', 'amount' => 100, 'pin' => '1234']);

        $this->assertSame('000', $response->json('code'));

        $log = ApiCallLog::sole();
        $this->assertSame('vtpass', $log->provider);
        $this->assertSame('buy-airtime', $log->context);
        $this->assertSame('POST', $log->method);
        $this->assertSame('https://sandbox.vtpass.com/api/pay', $log->url);
        $this->assertSame('/api/pay', $log->path);
        $this->assertSame(['ref' => 'abc'], $log->query);
        $this->assertSame(200, $log->status_code);
        $this->assertTrue($log->success);
        $this->assertNotNull($log->duration_ms);
        $this->assertSame(['phone' => '0803', 'amount' => 100, 'pin' => '[REDACTED]'], $log->request_body);
        $this->assertSame('[REDACTED]', $log->response_body['token']);
        $this->assertSame('[REDACTED]', $log->request_headers['api-key']);
        $this->assertNotNull($log->correlation_id);

        Event::assertDispatched(ApiCallLogged::class);
    }

    public function test_failed_status_and_custom_success_rule(): void
    {
        Http::fake([
            'api.gobeller.cc/fail' => Http::response(['message' => 'nope'], 422),
            'api.gobeller.cc/soft' => Http::response(['status' => false], 200),
        ]);

        Http::withApiLogging('gobeller')->get('https://api.gobeller.cc/fail');
        ApiCallLogger::http('gobeller', null, ['success' => fn ($response) => (bool) $response->json('status')])
            ->get('https://api.gobeller.cc/soft');

        [$hard, $soft] = ApiCallLog::orderBy('id')->get();
        $this->assertSame(422, $hard->status_code);
        $this->assertFalse($hard->success);
        $this->assertSame(200, $soft->status_code);
        $this->assertFalse($soft->success);
    }

    public function test_connection_failures_are_logged_and_still_thrown(): void
    {
        Http::fake(fn () => throw new \GuzzleHttp\Exception\ConnectException('Could not resolve host', new \GuzzleHttp\Psr7\Request('GET', 'https://down.example.com')));

        try {
            Http::withApiLogging('down')->get('https://down.example.com/ping');
            $this->fail('Expected a ConnectionException');
        } catch (ConnectionException) {
        }

        $log = ApiCallLog::sole();
        $this->assertNull($log->status_code);
        $this->assertFalse($log->success);
        $this->assertStringContainsString('Could not resolve host', $log->error);
    }

    public function test_manual_log_with_tenant_loggable_and_meta(): void
    {
        ApiCallLogger::withTenant('INST-01')->withMeta(['channel' => 'mobile']);

        $log = ApiCallLogger::log(
            provider: 'Dojah',
            method: 'get',
            url: 'https://api.dojah.io/api/v1/kyc/bvn?bvn=22222222222',
            response: '{"entity":{"first_name":"Ada"}}',
            status: 200,
            durationMs: 123.6,
            context: 'verify-bvn',
        );

        $this->assertSame('dojah', $log->provider);
        $this->assertSame('GET', $log->method);
        $this->assertSame('[REDACTED]', $log->query['bvn']);
        $this->assertSame('Ada', $log->response_body['entity']['first_name']);
        $this->assertSame(124, $log->duration_ms);
        $this->assertSame('INST-01', $log->tenant);
        $this->assertSame(['channel' => 'mobile'], $log->meta);
    }

    public function test_logging_errors_never_break_the_call(): void
    {
        config(['api-call-logger.table' => 'missing_table']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $response = Http::withApiLogging('any')->get('https://example.com');

        $this->assertTrue($response->json('ok'));
    }

    public function test_disabled_logger_writes_nothing(): void
    {
        $this->app->forgetScopedInstances();
        config(['api-call-logger.enabled' => false]);
        $this->app->forgetInstance(\Oluokunkabiru\ApiCallLogger\ApiCallLogger::class);
        ApiCallLogger::clearResolvedInstances();
        Http::fake(['*' => Http::response(['ok' => true])]);

        Http::withApiLogging('any')->get('https://example.com');

        $this->assertSame(0, ApiCallLog::count());
    }

    public function test_measure_logs_non_http_client_calls(): void
    {
        $result = ApiCallLogger::measure('soap-bank', 'POST', 'https://bank.example.com/soap', fn () => ['balance' => 10], ['account' => '123']);

        $this->assertSame(['balance' => 10], $result);
        $log = ApiCallLog::sole();
        $this->assertSame(['account' => '123'], $log->request_body);
        $this->assertSame(['balance' => 10], $log->response_body);
        $this->assertTrue($log->success);
    }
}
