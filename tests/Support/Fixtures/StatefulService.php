<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Support\Fixtures;

use Restate\Sdk\Context\Context;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Service;

/**
 * Footgun fixture: a service carrying mutable public instance state. Registration
 * must reject it because the instance is shared across concurrent invocations.
 */
#[Service]
final class StatefulService
{
    public int $counter = 0;

    #[Handler]
    public function process(Context $ctx): string
    {
        return 'ok';
    }
}
