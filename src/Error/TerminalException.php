<?php

declare(strict_types=1);

namespace Restate\Sdk\Error;

use RuntimeException;
use Throwable;

/**
 * A terminal (non-retryable) failure.
 *
 * When a handler throws this, the invocation ends with a failure result that is
 * journaled and returned to the caller — the runtime does not retry. Any other
 * throwable is treated as a transient error and triggers a retry.
 */
class TerminalException extends RuntimeException
{
    public const DEFAULT_CODE = 500;

    public function __construct(string $message, int $code = self::DEFAULT_CODE, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function statusCode(): int
    {
        $code = $this->getCode();

        return \is_int($code) && $code > 0 ? $code : self::DEFAULT_CODE;
    }
}
