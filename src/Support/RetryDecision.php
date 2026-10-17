<?php

namespace Oluokunkabiru\ApiCallLogger\Support;

/**
 * The outcome of checking a log record against the retry rules.
 */
class RetryDecision
{
    /**
     * @param  string[]  $blockers       Reasons the retry is not allowed at all.
     * @param  string[]  $confirmations  Risks the caller must accept explicitly (force).
     */
    public function __construct(
        public array $blockers = [],
        public array $confirmations = [],
    ) {
    }

    public function allowed(bool $force = false): bool
    {
        return $this->blockers === [] && ($force || $this->confirmations === []);
    }

    public function blocked(): bool
    {
        return $this->blockers !== [];
    }

    public function needsConfirmation(): bool
    {
        return $this->blockers === [] && $this->confirmations !== [];
    }
}
