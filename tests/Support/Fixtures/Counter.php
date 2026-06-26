<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Support\Fixtures;

use Qcodr\Restate\Sdk\Context\ObjectContext;
use Qcodr\Restate\Sdk\Context\SharedObjectContext;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Shared;
use Qcodr\Restate\Sdk\Service\Attribute\VirtualObject;

#[VirtualObject]
final class Counter
{
    #[Handler]
    public function add(ObjectContext $ctx, int $delta): int
    {
        $current = $ctx->get('count');
        $next = (\is_int($current) ? $current : 0) + $delta;
        $ctx->set('count', $next);

        return $next;
    }

    #[Shared]
    public function get(SharedObjectContext $ctx): int
    {
        $current = $ctx->get('count');

        return \is_int($current) ? $current : 0;
    }
}
