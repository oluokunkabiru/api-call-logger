<?php

namespace Oluokunkabiru\ApiCallLogger\Support;

/**
 * Remembers the previous request sent through one logging middleware, so the
 * attempts Http::retry() makes can be numbered and keep one idempotency key.
 */
class AttemptTracker
{
    public ?string $key = null;

    public bool $failed = false;

    public int $attempt = 0;

    public ?string $idempotencyKey = null;

    /**
     * A request is a retry when it repeats the previous one right after it failed.
     */
    public function isRetryOf(string $key): bool
    {
        return $this->key === $key && $this->failed;
    }

    public function start(string $key, int $attempt, ?string $idempotencyKey): void
    {
        $this->key = $key;
        $this->attempt = $attempt;
        $this->idempotencyKey = $idempotencyKey;
        $this->failed = true; // until a successful response says otherwise
    }
}
