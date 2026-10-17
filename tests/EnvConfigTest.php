<?php

namespace Oluokunkabiru\ApiCallLogger\Tests;

class EnvConfigTest extends TestCase
{
    protected array $env = [];

    protected function tearDown(): void
    {
        foreach (array_keys($this->env) as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        parent::tearDown();
    }

    protected function configWith(array $env): array
    {
        $this->env = $env;

        foreach ($env as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        return require __DIR__ . '/../config/api-call-logger.php';
    }

    public function test_defaults_without_env(): void
    {
        $config = $this->configWith([]);

        $this->assertTrue($config['enabled']);
        $this->assertSame(30, $config['prune_after_days']);
        $this->assertSame(500, $config['redaction']['max_string_length']);
        $this->assertSame(['web'], $config['dashboard']['middleware']);
        $this->assertSame([], $config['capture']['only_hosts']);
        $this->assertContains('pin', $config['redaction']['always']);
    }

    public function test_env_values_override_defaults(): void
    {
        $config = $this->configWith([
            'API_CALL_LOGGER_ENABLED' => 'false',
            'API_CALL_LOGGER_CAPTURE_ONLY_HOSTS' => '*.vtpass.com, api.paystack.co',
            'API_CALL_LOGGER_REDACT_KEYS' => 'merchant_key,signature',
            'API_CALL_LOGGER_MAX_STRING_LENGTH' => 'null',
            'API_CALL_LOGGER_MAX_BODY_BYTES' => '1024',
            'API_CALL_LOGGER_PRUNE_DAYS' => '',
            'API_CALL_LOGGER_DASHBOARD_MIDDLEWARE' => 'web,auth',
            'API_CALL_LOGGER_DASHBOARD_PER_PAGE' => '50',
        ]);

        $this->assertFalse($config['enabled']);
        $this->assertSame(['*.vtpass.com', 'api.paystack.co'], $config['capture']['only_hosts']);
        $this->assertContains('merchant_key', $config['redaction']['always']);
        $this->assertContains('signature', $config['redaction']['always']);
        $this->assertContains('pin', $config['redaction']['always']);
        $this->assertNull($config['redaction']['max_string_length']);
        $this->assertSame(1024, $config['max_body_bytes']);
        $this->assertNull($config['prune_after_days']);
        $this->assertSame(['web', 'auth'], $config['dashboard']['middleware']);
        $this->assertSame(50, $config['dashboard']['per_page']);
    }
}
