<?php

namespace Oluokunkabiru\ApiCallLogger\Tests;

use Oluokunkabiru\ApiCallLogger\Support\Redactor;
use PHPUnit\Framework\TestCase;

class ValuePatternRedactionTest extends TestCase
{
    protected function redactor(array $patterns): Redactor
    {
        return new Redactor(patterns: $patterns);
    }

    public function test_luhn_valid_card_numbers_are_masked_anywhere(): void
    {
        $redactor = $this->redactor(['card_numbers' => true]);

        $clean = $redactor->redact([
            'note' => 'paid with 4111 1111 1111 1111 yesterday',
            'meta' => ['ref' => '4111111111111112'],          // fails Luhn → untouched
            'raw' => 5500005555555559,                         // integer card number
            'amount' => '1500000.00',
        ]);

        $this->assertSame('paid with 411111******1111 yesterday', $clean['note']);
        $this->assertSame('4111111111111112', $clean['meta']['ref']);
        $this->assertSame('550000******5559', $clean['raw']);
        $this->assertSame('1500000.00', $clean['amount']);
    }

    public function test_jwt_and_auth_schemes_inside_strings(): void
    {
        $redactor = $this->redactor(['jwt' => true, 'auth_schemes' => true]);
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

        $clean = $redactor->redact(['message' => "token {$jwt} expired", 'debug' => 'sent Bearer abcdef123456789']);

        $this->assertSame('token [REDACTED:jwt] expired', $clean['message']);
        $this->assertSame('sent Bearer [REDACTED]', $clean['debug']);
        $this->assertSame('a token is required', $redactor->redact('a token is required'));
    }

    public function test_emails_and_phones_are_only_masked_when_switched_on(): void
    {
        $off = $this->redactor([]);
        $on = $this->redactor(['emails' => true, 'phones' => true]);
        $data = ['email' => 'john.doe@gmail.com', 'phone' => '+2348031234567'];

        $this->assertSame($data, $off->redact($data));
        $this->assertSame(['email' => 'jo***@gmail.com', 'phone' => '+*********4567'], $on->redact($data));
    }

    public function test_custom_patterns(): void
    {
        $redactor = $this->redactor(['custom' => ['/sk_(live|test)_[A-Za-z0-9]+/' => '[REDACTED:paystack-key]']]);

        $this->assertSame(['k' => 'key=[REDACTED:paystack-key]'], $redactor->redact(['k' => 'key=sk_live_abc123XYZ']));
    }

    public function test_url_path_digits_are_masked_by_minimum_length(): void
    {
        $redactor = $this->redactor(['url_digits_min_length' => 10]);

        $this->assertSame('/api/v1/kyc/bvn/*******2222', $redactor->maskPath('/api/v1/kyc/bvn/22222222222'));
        $this->assertSame('/orders/12345/items', $redactor->maskPath('/orders/12345/items'));
        $this->assertSame('/kyc/bvn/22222222222', $this->redactor([])->maskPath('/kyc/bvn/22222222222'));
    }

    public function test_masked_values_make_a_payload_lossy(): void
    {
        $redactor = $this->redactor(['card_numbers' => true]);

        $this->assertTrue($redactor->isLossy($redactor->redact(['card' => '4111111111111111'])));
        $this->assertTrue($redactor->isLossy('/kyc/bvn/*******2222'));
    }
}
