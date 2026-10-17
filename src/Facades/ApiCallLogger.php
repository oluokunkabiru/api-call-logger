<?php

namespace Oluokunkabiru\ApiCallLogger\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Oluokunkabiru\ApiCallLogger\Models\ApiCallLog|null log(string $provider, string $method, string $url, array|string|null $request = null, array|string|null $response = null, ?int $status = null, ?bool $success = null, ?float $durationMs = null, ?string $context = null, array $requestHeaders = [], array $responseHeaders = [], ?string $error = null, ?\Illuminate\Database\Eloquent\Model $loggable = null, array $meta = [], string $direction = 'outbound')
 * @method static mixed measure(string $provider, string $method, string $url, callable $call, array|string|null $request = null, ?string $context = null, ?\Illuminate\Database\Eloquent\Model $loggable = null)
 * @method static \Oluokunkabiru\ApiCallLogger\Models\ApiCallLog|null record(array $attributes, bool $sync = false)
 * @method static \Illuminate\Http\Client\PendingRequest http(?string $provider = null, ?string $context = null, array $options = [])
 * @method static callable middleware(?string $provider = null, ?string $context = null, array $options = [])
 * @method static \Oluokunkabiru\ApiCallLogger\Models\ApiCallLog|null retry(\Oluokunkabiru\ApiCallLogger\Models\ApiCallLog $log, array $headers = [], bool $force = false)
 * @method static \Oluokunkabiru\ApiCallLogger\Support\RetryDecision retryDecision(\Oluokunkabiru\ApiCallLogger\Models\ApiCallLog $log)
 * @method static int forget(?string $userId = null, ?string $tenant = null, ?\Illuminate\Database\Eloquent\Model $loggable = null)
 * @method static string|null idempotencyKeyFrom(array $headers)
 * @method static \Oluokunkabiru\ApiCallLogger\ApiCallLogger withTenant(?string $tenant)
 * @method static \Oluokunkabiru\ApiCallLogger\ApiCallLogger withMeta(array $meta)
 * @method static \Oluokunkabiru\ApiCallLogger\ApiCallLogger withCorrelationId(string $correlationId)
 * @method static string correlationId()
 * @method static \Oluokunkabiru\ApiCallLogger\ApiCallLogger resolveTenantUsing(?callable $resolver)
 * @method static \Oluokunkabiru\ApiCallLogger\ApiCallLogger resolveUserUsing(?callable $resolver)
 * @method static \Oluokunkabiru\ApiCallLogger\ApiCallLogger retryHeadersUsing(string $provider, callable $resolver)
 * @method static \Oluokunkabiru\ApiCallLogger\Support\Redactor redactor()
 * @method static bool enabled()
 *
 * @see \Oluokunkabiru\ApiCallLogger\ApiCallLogger
 */
class ApiCallLogger extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Oluokunkabiru\ApiCallLogger\ApiCallLogger::class;
    }
}
