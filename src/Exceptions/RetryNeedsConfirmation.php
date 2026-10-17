<?php

namespace Oluokunkabiru\ApiCallLogger\Exceptions;

/**
 * The retry is allowed but risky; call retry($log, force: true) to accept the risks.
 */
class RetryNeedsConfirmation extends RetryNotPossible {}
