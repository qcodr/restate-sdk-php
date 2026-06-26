<?php

declare(strict_types=1);

namespace Restate\Sdk\Service;

use RuntimeException;

/**
 * Raised when a class cannot be registered as a Restate service: missing service
 * attribute, no handlers, or a malformed handler signature.
 */
final class ServiceDefinitionException extends RuntimeException
{
}
