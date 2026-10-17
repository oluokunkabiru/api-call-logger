<?php

namespace Oluokunkabiru\ApiCallLogger\Tests;

use Illuminate\Support\Facades\DB;
use Oluokunkabiru\ApiCallLogger\Facades\ApiCallLogger;
use Oluokunkabiru\ApiCallLogger\Models\ApiCallLog;

class EncryptionTest extends TestCase
{
    protected function raw(int $id): object
    {
        return DB::table('api_call_logs')->find($id);
    }

    public function test_payloads_are_encrypted_at_rest_and_read_back(): void
    {
        config(['api-call-logger.encryption.enabled' => true]);

        $log = ApiCallLogger::log('dojah', 'POST', 'https://api.dojah.io/kyc', ['first_name' => 'Ada'], ['status' => 'verified'], 200);

        $raw = $this->raw($log->id);
        $this->assertStringNotContainsString('Ada', $raw->request_body);
        $this->assertStringNotContainsString('verified', $raw->response_body);
        $this->assertStringStartsWith('"eyJpdiI6', $raw->request_body);

        $fresh = ApiCallLog::find($log->id);
        $this->assertSame(['first_name' => 'Ada'], $fresh->request_body);
        $this->assertSame(['status' => 'verified'], $fresh->response_body);
    }

    public function test_switching_encryption_off_keeps_old_records_readable(): void
    {
        config(['api-call-logger.encryption.enabled' => true]);
        $encrypted = ApiCallLogger::log('a', 'GET', 'https://a.test', response: ['x' => 1], status: 200);

        config(['api-call-logger.encryption.enabled' => false]);
        $plain = ApiCallLogger::log('b', 'GET', 'https://b.test', response: ['y' => 2], status: 200);

        $this->assertSame(['x' => 1], ApiCallLog::find($encrypted->id)->response_body);
        $this->assertSame(['y' => 2], ApiCallLog::find($plain->id)->response_body);
        $this->assertStringContainsString('"y"', $this->raw($plain->id)->response_body);
    }

    public function test_only_configured_columns_are_encrypted(): void
    {
        config(['api-call-logger.encryption.enabled' => true, 'api-call-logger.encryption.columns' => ['response_body']]);

        $log = ApiCallLogger::log('a', 'POST', 'https://a.test', ['amount' => 5], ['secret' => 'data-x'], 200);

        $this->assertStringContainsString('amount', $this->raw($log->id)->request_body);
        $this->assertStringNotContainsString('data-x', $this->raw($log->id)->response_body);
    }

    public function test_dedicated_key_and_wrong_key_handling(): void
    {
        config([
            'api-call-logger.encryption.enabled' => true,
            'api-call-logger.encryption.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
        ]);
        $log = ApiCallLogger::log('a', 'GET', 'https://a.test', response: ['ok' => true], status: 200);
        $this->assertSame(['ok' => true], ApiCallLog::find($log->id)->response_body);

        config(['api-call-logger.encryption.key' => 'base64:' . base64_encode(str_repeat('z', 32))]);
        $this->assertArrayHasKey('_encrypted', ApiCallLog::find($log->id)->response_body);
    }
}
