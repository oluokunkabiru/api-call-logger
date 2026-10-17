<?php

namespace Oluokunkabiru\ApiCallLogger\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Oluokunkabiru\ApiCallLogger\ApiCallLoggerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [ApiCallLoggerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        if (extension_loaded('pdo_sqlite')) {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
        } else {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', [
                'driver' => 'mysql',
                'host' => env('TEST_DB_HOST', '127.0.0.1'),
                'port' => env('TEST_DB_PORT', 3306),
                'database' => env('TEST_DB_DATABASE', 'api_call_logger_test'),
                'username' => env('TEST_DB_USERNAME', 'root'),
                'password' => env('TEST_DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ]);
        }

        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
    }
}
