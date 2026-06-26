<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Serde;

use RuntimeException;

/**
 * Raised when a handler input/output or state value cannot be (de)serialized.
 */
final class SerializationException extends RuntimeException
{
}
