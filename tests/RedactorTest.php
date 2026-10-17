<?php

namespace Oluokunkabiru\ApiCallLogger\Tests;

use Oluokunkabiru\ApiCallLogger\Support\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_it_redacts_keys_regardless_of_case_and_separators(): void
    {
        $redactor = new Redactor(always: ['secret_key', 'pin']);

        $clean = $redactor->redact([
            'Secret-Key' => 'abc',
            'secretKey' => 'abc',
            'data' => ['transaction' => ['PIN' => '1234', 'amount' => 500]],
        ]);

        $this->assertSame('[REDACTED]', $clean['Secret-Key']);
        $this->assertSame('[REDACTED]', $clean['secretKey']);
        $this->assertSame('[REDACTED]', $clean['data']['transaction']['PIN']);
        $this->assertSame(500, $clean['data']['transaction']['amount']);
    }

    public function test_sensitive_keys_can_be_relaxed_but_credentials_cannot(): void
    {
        $redactor = new Redactor(always: ['password'], sensitive: ['bvn'], redactSensitive: false);

        $clean = $redactor->redact(['password' => 'x', 'bvn' => '22222222222']);

        $this->assertSame('[REDACTED]', $clean['password']);
        $this->assertSame('22222222222', $clean['bvn']);
    }

    public function test_it_truncates_long_strings_and_limits_depth(): void
    {
        $redactor = new Redactor(maxStringLength: 10, maxDepth: 2);

        $clean = $redactor->redact(['logo' => str_repeat('x', 50), 'a' => ['b' => ['c' => 1]]]);

        $this->assertSame('<truncated 50 bytes>', $clean['logo']);
        $this->assertSame('<max-depth>', $clean['a']['b']);
        $this->assertTrue($redactor->isLossy($clean));
        $this->assertFalse($redactor->isLossy(['amount' => 10]));
    }
}
