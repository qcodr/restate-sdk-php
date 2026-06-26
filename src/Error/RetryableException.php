<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Error;

use RuntimeException;
use Throwable;

/**
 * A transient (retryable) failure a handler can throw to control the retry of the
 * current attempt.
 *
 * Unlike {@see TerminalException} (which ends the invocation), throwing this closes
 * the stream with an attempt failure that the runtime retries. The optional
 * {@see $retryDelayMillis} overrides the runtime retry delay for the next attempt,
 * and {@see $pause} asks the runtime to pause the invocation rather than retry it.
 *
 * Any other (non-{@see TerminalException}) throwable behaves like this with the
 * runtime's default retry policy.
 */
final class RetryableException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $retryDelayMillis = null,
        public readonly bool $pause = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
