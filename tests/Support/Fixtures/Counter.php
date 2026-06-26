<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Support\Fixtures;

use Restate\Sdk\Context\ObjectContext;
use Restate\Sdk\Context\SharedObjectContext;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Shared;
use Restate\Sdk\Service\Attribute\VirtualObject;

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
