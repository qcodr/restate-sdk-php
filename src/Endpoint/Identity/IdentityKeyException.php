<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Endpoint\Identity;

use InvalidArgumentException;

/**
 * Thrown when a configured request-identity public key cannot be parsed.
 *
 * Misconfigured keys are a deployment error (not a per-request failure), so this
 * surfaces eagerly when the key is registered rather than silently disabling
 * verification.
 */
final class IdentityKeyException extends InvalidArgumentException
{
}
