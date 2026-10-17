<?php

namespace Oluokunkabiru\ApiCallLogger;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Oluokunkabiru\ApiCallLogger\Commands\ForgetApiCallLogsCommand;
use Oluokunkabiru\ApiCallLogger\Commands\PruneApiCallLogsCommand;
use Oluokunkabiru\ApiCallLogger\Http\Middleware\LogIncomingRequest;
use Oluokunkabiru\ApiCallLogger\Listeners\CaptureHttpClientCalls;
use Oluokunkabiru\ApiCallLogger\Support\ProviderResolver;
use Oluokunkabiru\ApiCallLogger\Support\Redactor;

class ApiCallLoggerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        // ── Config ───────────────────────────────────────────
        $this->publishes([
            __DIR__ . '/../config/api-call-logger.php' => config_path('api-call-logger.php'),
        ], 'api-call-logger-config');

        // ── Migrations ────────────────────────────────────────
        $this->publishes([
            __DIR__ . '/../database/migrations/create_api_call_logs_table.php' => database_path('migrations/' . date('Y_m_d_His') . '_create_api_call_logs_table.php'),
        ], 'api-call-logger-migrations');

        if (config('api-call-logger.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }

        // ── Views ─────────────────────────────────────────────
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'api-call-logger');
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/api-call-logger'),
        ], 'api-call-logger-views');

        // ── Routes ────────────────────────────────────────────
        if (config('api-call-logger.dashboard.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        }

        // ── Middleware ────────────────────────────────────────
        $this->app->make(Router::class)->aliasMiddleware('api-call-logger.inbound', LogIncomingRequest::class);

        // ── Http client: Http::withApiLogging('vtpass', 'buy-airtime')->post(...) ──
        PendingRequest::macro('withApiLogging', function (?string $provider = null, ?string $context = null, array $options = []) {
            /** @var PendingRequest $this */
            return $this->withMiddleware(app(ApiCallLogger::class)->middleware($provider, $context, $options));
        });

        // ── Automatic capture of every Http client call ──────
        if (config('api-call-logger.capture.http_client', false)) {
            Event::listen(ResponseReceived::class, [CaptureHttpClientCalls::class, 'handleResponse']);
            Event::listen(ConnectionFailed::class, [CaptureHttpClientCalls::class, 'handleConnectionFailed']);
        }

        // ── Dashboard gates (local only until the app defines them) ──
        foreach (['viewApiCallLogs', 'retryApiCallLogs'] as $ability) {
            if (! Gate::has($ability)) {
                Gate::define($ability, fn ($user = null) => $this->app->environment('local'));
            }
        }

        // ── Artisan Commands ──────────────────────────────────
        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneApiCallLogsCommand::class,
                ForgetApiCallLogsCommand::class,
            ]);
        }
    }

    /**
     * Register package bindings.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/api-call-logger.php',
            'api-call-logger'
        );

        // Scoped: tenant, meta and correlation id live for one request / queued job.
        $this->app->scoped(ApiCallLogger::class, function ($app) {
            $config = $app['config']->get('api-call-logger', []);

            return new ApiCallLogger(
                Redactor::fromConfig($config['redaction'] ?? []),
                new ProviderResolver($config['providers'] ?? []),
                $config,
            );
        });

        $this->app->alias(ApiCallLogger::class, 'api-call-logger');

        $this->app->when(CaptureHttpClientCalls::class)
            ->needs('$config')
            ->giveConfig('api-call-logger.capture', []);
    }
}
