<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Support\Fixtures;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;
use RuntimeException;

/**
 * Fixture whose handler throws an ordinary (non-terminal) exception, exercising the
 * retryable-attempt-failure path and the stacktrace forwarded to the runtime.
 */
#[Service]
final class ThrowingService
{
    public const MESSAGE = 'handler exploded';

    #[Handler]
    public function boom(Context $ctx): string
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
