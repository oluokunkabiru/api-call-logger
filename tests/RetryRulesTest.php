<?php

namespace Oluokunkabiru\ApiCallLogger\Tests;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Oluokunkabiru\ApiCallLogger\Exceptions\RetryNeedsConfirmation;
use Oluokunkabiru\ApiCallLogger\Exceptions\RetryNotPossible;
use Oluokunkabiru\ApiCallLogger\Facades\ApiCallLogger;
use Oluokunkabiru\ApiCallLogger\Models\ApiCallLog;

class RetryRulesTest extends TestCase
{
    protected function failedPost(array $headers = []): ApiCallLog
    {
        return ApiCallLogger::log('paystack', 'POST', 'https://api.paystack.co/transfer', ['amount' => 100], ['status' => false], 500, requestHeaders: $headers);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['status' => true])]);
    }

    public function test_failed_get_retries_without_confirmation(): void
    {
        $log = ApiCallLogger::log('vtpass', 'GET', 'https://sandbox.vtpass.com/api/balance', status: 500);

        $this->assertTrue(ApiCallLogger::retryDecision($log)->allowed());
        $this->assertNotNull(ApiCallLogger::retry($log));
    }

    public function test_unsafe_methods_need_confirmation_by_default(): void
    {
        $log = $this->failedPost();

        $this->assertTrue(ApiCallLogger::retryDecision($log)->needsConfirmation());

        try {
            ApiCallLogger::retry($log);
            $this->fail('Expected RetryNeedsConfirmation');
        } catch (RetryNeedsConfirmation) {
        }

        Http::assertNothingSent();
        $this->assertNotNull(ApiCallLogger::retry($log, force: true));
    }

    public function test_unsafe_methods_can_be_allowed_or_denied(): void
    {
        config(['api-call-logger.retry.unsafe_methods' => 'allow']);
        $this->freshLogger();
        $this->assertTrue(ApiCallLogger::retryDecision($this->failedPost())->allowed());

        config(['api-call-logger.retry.unsafe_methods' => 'deny']);
        $this->freshLogger();
        $this->expectException(RetryNotPossible::class);
        ApiCallLogger::retry($this->failedPost(), force: true);
    }

    public function test_successful_calls_need_confirmation_unless_switched_off(): void
    {
        $log = ApiCallLogger::log('vtpass', 'GET', 'https://sandbox.vtpass.com/api/balance', status: 200);
        $this->assertTrue(ApiCallLogger::retryDecision($log)->needsConfirmation());

        config(['api-call-logger.retry.only_failed' => false]);
        $this->freshLogger();
        $this->assertTrue(ApiCallLogger::retryDecision($log)->allowed());
    }

    public function test_require_idempotency_key_blocks_even_when_forced(): void
    {
        config(['api-call-logger.retry.require_idempotency_key' => true]);
        $this->freshLogger();

        $this->assertTrue(ApiCallLogger::retryDecision($this->failedPost())->blocked());

        $withKey = $this->failedPost(['Idempotency-Key' => 'tx-1']);
        ApiCallLogger::retry($withKey, force: true);

        Http::assertSent(fn ($request) => $request->header('Idempotency-Key') === ['tx-1']);
    }

    public function test_max_retries_per_log_and_master_switch(): void
    {
        config(['api-call-logger.retry.max_per_log' => 1]);
        $this->freshLogger();
        $log = $this->failedPost();

        $retry = ApiCallLogger::retry($log, force: true);
        $this->assertSame($log->id, $retry->retry_of_id);
        $this->assertTrue(ApiCallLogger::retryDecision($log)->blocked());
        $this->assertTrue(ApiCallLogger::retryDecision($retry)->blocked()); // retries of a retry count against the original

        config(['api-call-logger.retry.enabled' => false, 'api-call-logger.retry.max_per_log' => null]);
        $this->freshLogger();
        $this->assertTrue(ApiCallLogger::retryDecision($this->failedPost())->blocked());
    }

    public function test_masked_url_paths_block_retry(): void
    {
        $log = ApiCallLogger::log('dojah', 'GET', 'https://api.dojah.io/kyc/bvn/22222222222', status: 500);

        $this->assertSame('/kyc/bvn/*******2222', $log->path);
        $this->assertTrue(ApiCallLogger::retryDecision($log)->blocked());
    }

    public function test_dashboard_asks_for_confirmation_and_passes_it_on(): void
    {
        Gate::define('viewApiCallLogs', fn ($user = null) => true);
        Gate::define('retryApiCallLogs', fn ($user = null) => true);
        $log = $this->failedPost();

        $this->get("api-call-logs/{$log->id}")->assertOk()->assertSee('I understand the risks below')->assertSee('is not idempotent');

        $this->post("api-call-logs/{$log->id}/retry")->assertSessionHas('api-call-logger.error');
        Http::assertNothingSent();

        $this->post("api-call-logs/{$log->id}/retry", ['confirm' => 1])->assertRedirect();
        Http::assertSentCount(1);
    }

    protected function freshLogger(): void
    {
        $this->app->forgetScopedInstances();
        ApiCallLogger::clearResolvedInstances();
    }
}
