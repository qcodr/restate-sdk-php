<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Error;

use Throwable;

/**
 * Raised when the invocation has been cancelled: the runtime delivered a built-in
 * CANCEL signal and the handler then awaited a result that is not yet available.
 *
 * It is a {@see TerminalException} (HTTP 409), so the endpoint ends the invocation
 * with a terminal failure rather than retrying it.
 */
final class CancelledException extends TerminalException
{
    public const CODE = 409;

    public function __construct(string $message = 'cancelled', ?Throwable $previous = null)
    {
        parent::__construct($message, self::CODE, $previous);
    }
}
