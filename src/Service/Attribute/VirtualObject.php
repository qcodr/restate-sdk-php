<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Service\Attribute;

use Attribute;

/**
 * Marks a class as a Restate Virtual Object: per-key state with a single writer
 * (exclusive handlers) and concurrent readers ({@see Shared} handlers).
 * Exclusive handlers receive a {@see \Qcodr\Restate\Sdk\Context\ObjectContext}.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class VirtualObject
{
    public function __construct(public readonly ?string $name = null)
    {
    }
}
