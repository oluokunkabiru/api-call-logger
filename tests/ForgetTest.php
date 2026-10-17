<?php

namespace Oluokunkabiru\ApiCallLogger\Tests;

use Oluokunkabiru\ApiCallLogger\Facades\ApiCallLogger;
use Oluokunkabiru\ApiCallLogger\Models\ApiCallLog;

class ForgetTest extends TestCase
{
    public function test_forget_by_user_and_tenant(): void
    {
        ApiCallLogger::record(['provider' => 'a', 'method' => 'GET', 'url' => 'https://a.test', 'user_id' => '7', 'tenant' => 'T1']);
        ApiCallLogger::record(['provider' => 'a', 'method' => 'GET', 'url' => 'https://a.test', 'user_id' => '8', 'tenant' => 'T1']);
        ApiCallLogger::record(['provider' => 'a', 'method' => 'GET', 'url' => 'https://a.test', 'user_id' => '8', 'tenant' => 'T2']);

        $this->assertSame(1, ApiCallLogger::forget(userId: '7'));
        $this->artisan('api-call-logger:forget', ['--tenant' => 'T1', '--force' => true])->assertSuccessful();

        $this->assertSame(['T2'], ApiCallLog::pluck('tenant')->all());
    }

    public function test_forget_requires_a_target(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ApiCallLogger::forget();
    }
}
