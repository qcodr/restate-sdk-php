<?php

declare(strict_types=1);

namespace Restate\Conformance;

use Qcodr\Restate\Sdk\Context\ObjectContext;
use Qcodr\Restate\Sdk\Context\SharedObjectContext;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Shared;
use Qcodr\Restate\Sdk\Service\Attribute\VirtualObject;

/**
 * Conformance counter Virtual Object: a per-key u64 counter with an exclusive
 * writer and a shared, read-only getter. Mirrors the Rust test-service.
 */
#[VirtualObject(name: 'Counter')]
final class Counter
{
    private const COUNTER = 'counter';

    /**
     * @return array{oldValue: int, newValue: int} CounterUpdateResponse
     */
    #[Handler]
    public function add(ObjectContext $ctx, int $value): array
    {
        $current = $ctx->get(self::COUNTER);
        $old = \is_int($current) ? $current : 0;
        $new = $old + $value;
        $ctx->set(self::COUNTER, $new);

        return ['oldValue' => $old, 'newValue' => $new];
    }

    #[Handler]
    public function addThenFail(ObjectContext $ctx, int $value): void
    {
        $current = $ctx->get(self::COUNTER);
        $old = \is_int($current) ? $current : 0;
        $ctx->set(self::COUNTER, $old + $value);

        throw new TerminalException($ctx->key());
    }

    #[Shared]
    public function get(SharedObjectContext $ctx): int
    {
        $current = $ctx->get(self::COUNTER);

        return \is_int($current) ? $current : 0;
    }

    #[Handler]
    public function reset(ObjectContext $ctx): void
    {
        $ctx->clear(self::COUNTER);
    }
}
