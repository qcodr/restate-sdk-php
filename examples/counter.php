<?php

declare(strict_types=1);

namespace Restate\Examples;

use Restate\Sdk\Context\ObjectContext;
use Restate\Sdk\Context\SharedObjectContext;
use Restate\Sdk\Endpoint\Endpoint;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Shared;
use Restate\Sdk\Service\Attribute\VirtualObject;

require __DIR__ . '/../vendor/autoload.php';

/**
 * A keyed counter Virtual Object: per-key state, an exclusive writer, and a shared
 * (concurrent, read-only) getter.
 *
 * Run:   php bin/restate-serve examples/counter.php
 * Try:   curl localhost:8080/Counter/my-key/add -d '3'
 *        curl localhost:8080/Counter/my-key/increment
 *        curl localhost:8080/Counter/my-key/get
 *        curl localhost:8080/Counter/my-key/reset
 */
#[VirtualObject]
final class Counter
{
    private const COUNT = 'count';

    #[Shared]
    public function get(SharedObjectContext $ctx): int
    {
        return $ctx->get(self::COUNT) ?? 0;
    }

    #[Handler]
    public function add(ObjectContext $ctx, int $value): int
    {
        $next = ($ctx->get(self::COUNT) ?? 0) + $value;
        $ctx->set(self::COUNT, $next);

        return $next;
    }

    #[Handler]
    public function increment(ObjectContext $ctx): int
    {
        return $this->add($ctx, 1);
    }

    #[Handler]
    public function reset(ObjectContext $ctx): void
    {
        $ctx->clear(self::COUNT);
    }
}

return Endpoint::builder()->bind(new Counter())->build();
