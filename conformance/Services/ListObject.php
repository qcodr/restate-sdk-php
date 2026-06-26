<?php

declare(strict_types=1);

namespace Restate\Conformance;

use Restate\Sdk\Context\ObjectContext;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\VirtualObject;

/**
 * Conformance list Virtual Object: a per-key list<string> kept under a single
 * state key. Mirrors the Rust test-service.
 */
#[VirtualObject(name: 'ListObject')]
final class ListObject
{
    private const LIST = 'list';

    #[Handler]
    public function append(ObjectContext $ctx, string $value): void
    {
        $current = $ctx->get(self::LIST);
        $list = \is_array($current) ? $current : [];
        $list[] = $value;
        $ctx->set(self::LIST, $list);
    }

    /**
     * @return list<string>
     */
    #[Handler]
    public function get(ObjectContext $ctx): array
    {
        $current = $ctx->get(self::LIST);

        return \is_array($current) ? $current : [];
    }

    /**
     * @return list<string>
     */
    #[Handler]
    public function clear(ObjectContext $ctx): array
    {
        $current = $ctx->get(self::LIST);
        $old = \is_array($current) ? $current : [];
        $ctx->clear(self::LIST);

        return $old;
    }
}
