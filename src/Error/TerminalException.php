<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Error;

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

    /**
     * @param array<string, string> $metadata user error metadata propagated with the
     *                                         failure (service protocol V7)
     */
    public function __construct(
        string $message,
        int $code = self::DEFAULT_CODE,
        ?Throwable $previous = null,
        public readonly array $metadata = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function statusCode(): int
    {
        $code = $this->getCode();

        return \is_int($code) && $code > 0 ? $code : self::DEFAULT_CODE;
    }
}
