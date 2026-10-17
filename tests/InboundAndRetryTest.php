<?php

namespace Oluokunkabiru\ApiCallLogger\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Oluokunkabiru\ApiCallLogger\Exceptions\RetryNotPossible;
use Oluokunkabiru\ApiCallLogger\Facades\ApiCallLogger;
use Oluokunkabiru\ApiCallLogger\Models\ApiCallLog;

class InboundAndRetryTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('api-call-logger.providers', [
            'vtpass' => ['retry_headers' => ['api-key' => 'from-config']],
        ]);
    }

    protected function defineRoutes($router): void
    {
        Route::post('api/webhooks/fyatu', fn () => response()->json(['received' => true]))
            ->middleware('api-call-logger.inbound');
        Route::post('callbacks/charge', fn () => response()->json(['ok' => false], 400))
            ->middleware('api-call-logger.inbound:paystack,charge-success');
    }

    public function test_inbound_webhooks_are_logged(): void
    {
        $this->postJson('api/webhooks/fyatu?x=1', ['event' => 'card.debit', 'card_number' => '4111'], ['X-Signature' => 'sig'])
            ->assertOk();
        $this->postJson('callbacks/charge', ['event' => 'charge.success'])->assertStatus(400);

        [$fyatu, $paystack] = ApiCallLog::orderBy('id')->get();

        $this->assertSame('inbound', $fyatu->direction);
        $this->assertSame('fyatu', $fyatu->provider);
        $this->assertSame('inbound-webhook', $fyatu->context);
        $this->assertSame(['x' => '1'], $fyatu->query);
        $this->assertSame('[REDACTED]', $fyatu->request_body['card_number']);
        $this->assertSame(['received' => true], $fyatu->response_body);
        $this->assertTrue($fyatu->success);

        $this->assertSame('paystack', $paystack->provider);
        $this->assertSame('charge-success', $paystack->context);
        $this->assertFalse($paystack->success);
    }

    public function test_retry_replays_the_call_with_provider_headers(): void
    {
        Http::fake(['*' => Http::response(['code' => '000'])]);

        $original = ApiCallLogger::log('vtpass', 'POST', 'https://sandbox.vtpass.com/api/pay', ['amount' => 100], ['code' => '099'], 500, context: 'buy-airtime', requestHeaders: ['api-key' => 'live', 'Accept' => 'application/json']);

        $retry = ApiCallLogger::retry($original, force: true);

        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox.vtpass.com/api/pay'
            && $request['amount'] === 100
            && $request->header('api-key') === ['from-config']
            && $request->header('Accept') === ['application/json']);

        $this->assertSame($original->id, $retry->retry_of_id);
        $this->assertSame('retry:buy-airtime', $retry->context);
        $this->assertTrue($retry->success);
        $this->assertCount(1, $original->retries);
    }

    public function test_retry_refuses_redacted_payloads(): void
    {
        $original = ApiCallLogger::log('vtpass', 'POST', 'https://sandbox.vtpass.com/api/pay', ['pin' => '1234'], status: 200);

        $this->expectException(RetryNotPossible::class);

        ApiCallLogger::retry($original);
    }

    public function test_prune_command_removes_old_records(): void
    {
        ApiCallLogger::log('a', 'GET', 'https://a.test', status: 200);
        ApiCallLog::query()->update(['created_at' => now()->subDays(40)]);
        ApiCallLogger::log('b', 'GET', 'https://b.test', status: 200);

        $this->artisan('api-call-logger:prune', ['--days' => 30])->assertSuccessful();

        $this->assertSame(['b'], ApiCallLog::pluck('provider')->all());
    }
}
