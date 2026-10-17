<?php

namespace Oluokunkabiru\ApiCallLogger\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Oluokunkabiru\ApiCallLogger\Casts\EncryptablePayload;

/**
 * @property int $id
 * @property string $direction
 * @property string $provider
 * @property string|null $context
 * @property string $method
 * @property string $url
 * @property string|null $host
 * @property string|null $path
 * @property array|null $query
 * @property array|null $request_headers
 * @property array|null $request_body
 * @property int|null $status_code
 * @property bool $success
 * @property int|null $duration_ms
 * @property array|null $response_headers
 * @property array|null $response_body
 * @property string|null $error
 * @property string|null $tenant
 * @property string|null $user_id
 * @property string|null $correlation_id
 * @property string|null $idempotency_key
 * @property int $attempt
 * @property int|null $retry_of_id
 * @property array|null $meta
 */
class ApiCallLog extends Model
{
    use MassPrunable;

    public const OUTBOUND = 'outbound';
    public const INBOUND = 'inbound';

    protected $guarded = ['id'];

    protected $casts = [
        'query' => EncryptablePayload::class,
        'request_headers' => EncryptablePayload::class,
        'request_body' => EncryptablePayload::class,
        'response_headers' => EncryptablePayload::class,
        'response_body' => EncryptablePayload::class,
        'meta' => 'array',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'attempt' => 'integer',
        'success' => 'boolean',
    ];

    public function getTable(): string
    {
        return config('api-call-logger.table', 'api_call_logs');
    }

    public function getConnectionName(): ?string
    {
        return config('api-call-logger.connection') ?? parent::getConnectionName();
    }

    // ── Relations ─────────────────────────────────────────

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(static::class, 'retry_of_id');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(static::class, 'retry_of_id');
    }

    // ── Scopes ────────────────────────────────────────────

    public function scopeProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    public function scopeContext(Builder $query, string $context): Builder
    {
        return $query->where('context', $context);
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('success', true);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('success', false);
    }

    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', self::OUTBOUND);
    }

    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', self::INBOUND);
    }

    /**
     * Records belonging to a user, a tenant or a related model — for data-subject
     * erasure requests (see ApiCallLogger::forget()).
     */
    public function scopeBelongingTo(Builder $query, ?string $userId = null, ?string $tenant = null, ?Model $loggable = null): Builder
    {
        return $query
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when($tenant !== null, fn ($q) => $q->where('tenant', $tenant))
            ->when($loggable !== null, fn ($q) => $q->where('loggable_type', $loggable->getMorphClass())->where('loggable_id', $loggable->getKey()));
    }

    public function scopeSlowerThan(Builder $query, int $milliseconds): Builder
    {
        return $query->where('duration_ms', '>', $milliseconds);
    }

    // ── Helpers ───────────────────────────────────────────

    public function isSuccess(): bool
    {
        return (bool) $this->success;
    }

    public function isInbound(): bool
    {
        return $this->direction === self::INBOUND;
    }

    /**
     * The URL including its query string.
     */
    public function fullUrl(): string
    {
        return $this->query ? $this->url . '?' . http_build_query($this->query) : $this->url;
    }

    // ── Pruning ───────────────────────────────────────────

    public function prunable(): Builder
    {
        $days = config('api-call-logger.prune_after_days');

        return $days === null
            ? static::query()->whereRaw('1 = 0')
            : static::query()->where('created_at', '<', now()->subDays((int) $days));
    }
}
