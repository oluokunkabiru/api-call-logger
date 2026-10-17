<?php

namespace Oluokunkabiru\ApiCallLogger\Tests;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Oluokunkabiru\ApiCallLogger\ApiCallLogger as Logger;
use Oluokunkabiru\ApiCallLogger\Facades\ApiCallLogger;
use Oluokunkabiru\ApiCallLogger\Models\ApiCallLog;

class AttemptsIdempotencyCorrelationTest extends TestCase
{
    protected function freshLogger(): void
    {
        $this->app->forgetScopedInstances();
        ApiCallLogger::clearResolvedInstances();
    }

    public function test_http_retry_attempts_are_numbered(): void
    {
        Http::fake(['*' => Http::sequence()->push(['e' => 1], 500)->push(['e' => 2], 503)->push(['ok' => true], 200)]);

        Http::withApiLogging('vtpass')->retry(3, 0)->get('https://sandbox.vtpass.com/api/balance');

        $this->assertSame([1, 2, 3], ApiCallLog::orderBy('id')->pluck('attempt')->all());
    }

    public function test_a_new_call_after_success_starts_again_at_attempt_one(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $client = Http::withApiLogging('vtpass');

        $client->get('https://sandbox.vtpass.com/api/balance');
        $client->get('https://sandbox.vtpass.com/api/balance');

        $this->assertSame([1, 1], ApiCallLog::orderBy('id')->pluck('attempt')->all());
    }

    public function test_idempotency_key_is_stored_from_the_request_header(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        Http::withApiLogging('paystack')->withHeaders(['Idempotency-Key' => 'order-42'])->post('https://api.paystack.co/transfer', ['amount' => 1]);

        $this->assertSame('order-42', ApiCallLog::sole()->idempotency_key);
    }

    public function test_auto_generated_idempotency_key_is_reused_across_attempts(): void
    {
        config(['api-call-logger.idempotency.auto_generate' => true]);
        $this->freshLogger();
        Http::fake(['*' => Http::sequence()->push([], 500)->push(['ok' => true], 200)->push(['ok' => true], 200)]);

        Http::withApiLogging('paystack')->retry(2, 0)->post('https://api.paystack.co/transfer', ['amount' => 1]);
        Http::withApiLogging('paystack')->get('https://api.paystack.co/balance');

        [$first, $second, $get] = ApiCallLog::orderBy('id')->get();
        $this->assertNotNull($first->idempotency_key);
        $this->assertSame($first->idempotency_key, $second->idempotency_key);
        $this->assertNull($get->idempotency_key); // safe methods get no key

        $keys = [];
        Http::assertSent(function ($request) use (&$keys) {
            $keys[] = $request->header('Idempotency-Key')[0] ?? null;

            return true;
        });
        $this->assertSame($first->idempotency_key, $keys[0]);
    }

    public function test_no_extra_headers_are_sent_by_default(): void
    {
        Http::fake(['*' => Http::response([])]);

        Http::withApiLogging('vtpass')->post('https://sandbox.vtpass.com/api/pay', ['a' => 1]);

        Http::assertSent(fn ($request) => ! $request->hasHeader('Idempotency-Key') && ! $request->hasHeader('X-Correlation-ID'));
    }

    public function test_correlation_id_can_be_forwarded_to_providers(): void
    {
        config(['api-call-logger.correlation.forward_header' => 'X-Correlation-ID']);
        $this->freshLogger();
        Http::fake(['*' => Http::response([])]);

        Http::withApiLogging('vtpass')->get('https://sandbox.vtpass.com/api/balance');

        $id = ApiCallLog::sole()->correlation_id;
        Http::assertSent(fn ($request) => $request->header('X-Correlation-ID') === [$id]);
    }

    public function test_correlation_id_is_shared_through_context_for_queued_jobs(): void
    {
        if (! class_exists(Context::class)) {
            $this->markTestSkipped('Context requires Laravel 11+.');
        }

        $id = ApiCallLogger::correlationId();
        $this->assertSame($id, Context::getHidden(Logger::CONTEXT_KEY));

        // A queued job gets a fresh logger instance but the hydrated Context.
        $inJob = new Logger(app(Logger::class)->redactor(), app(Logger::class)->providers(), config('api-call-logger'));
        $this->assertSame($id, $inJob->correlationId());
    }

    public function test_correlation_id_is_not_shared_when_switched_off(): void
    {
        if (! class_exists(Context::class)) {
            $this->markTestSkipped('Context requires Laravel 11+.');
        }

        config(['api-call-logger.correlation.propagate_to_jobs' => false]);
        $this->freshLogger();

        ApiCallLogger::correlationId();

        $this->assertNull(Context::getHidden(Logger::CONTEXT_KEY));
    }

    public function test_correlation_id_adopts_the_w3c_traceparent_trace_id(): void
    {
        config(['api-call-logger.correlation.request_header' => 'traceparent']);
        Route::post('api/webhooks/fyatu', fn () => ['ok' => true])->middleware('api-call-logger.inbound');
        $this->freshLogger();

        $this->postJson('api/webhooks/fyatu', [], ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])->assertOk();

        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', ApiCallLog::sole()->correlation_id);
    }

    public function test_correlation_id_adopts_a_plain_request_header_and_rejects_garbage(): void
    {
        config(['api-call-logger.correlation.request_header' => 'X-Request-Id']);
        Route::post('api/webhooks/fyatu', fn () => ['ok' => true])->middleware('api-call-logger.inbound');

        $this->freshLogger();
        $this->postJson('api/webhooks/fyatu', [], ['X-Request-Id' => 'req-123'])->assertOk();

        $this->freshLogger();
        $this->postJson('api/webhooks/fyatu', [], ['X-Request-Id' => 'bad value; drop table'])->assertOk();

        [$adopted, $generated] = ApiCallLog::orderBy('id')->get();
        $this->assertSame('req-123', $adopted->correlation_id);
        $this->assertNotSame('bad value; drop table', $generated->correlation_id);
    }
}
