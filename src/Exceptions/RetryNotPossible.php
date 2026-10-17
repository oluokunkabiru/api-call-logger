<?php

namespace Oluokunkabiru\ApiCallLogger\Exceptions;

use Oluokunkabiru\ApiCallLogger\Support\RetryDecision;
use RuntimeException;

class RetryNotPossible extends RuntimeException
{
    public function __construct(string $message, public ?RetryDecision $decision = null)
    {
        parent::__construct($message);
    }
}
