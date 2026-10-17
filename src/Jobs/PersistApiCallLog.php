<?php

namespace Oluokunkabiru\ApiCallLogger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Oluokunkabiru\ApiCallLogger\ApiCallLogger;

/**
 * Writes an already redacted log record when `api-call-logger.queue.enabled` is on.
 * The job payload is encrypted on the queue, so bodies never sit there in plain text.
 */
class PersistApiCallLog implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public array $attributes) {}

    public function handle(ApiCallLogger $logger): void
    {
        $logger->persist($this->attributes);
    }
}
