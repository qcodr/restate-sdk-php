<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol;

use RuntimeException;

/**
 * Raised when the wire protocol is violated: malformed frames, truncated
 * payloads, unsupported versions, or unexpected message types for the VM state.
 */
final class ProtocolException extends RuntimeException
{
}
