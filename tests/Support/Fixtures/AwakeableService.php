<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Support\Fixtures;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

/**
 * Fixture exercising awakeables: a handler that creates an awakeable (signal idx 17,
 * emitting no command frame) and awaits it. Over streaming the resolving signal is fed
 * on the open channel; on EOF the await suspends on the awakeable signal (guarded by
 * the CANCEL signal).
 */
#[Service]
final class AwakeableService
{
    #[Handler]
    public function awaitOne(Context $ctx): string
    {
        $value = $ctx->awakeable()->await();

        return \is_string($value) ? $value : '';
    }
}
