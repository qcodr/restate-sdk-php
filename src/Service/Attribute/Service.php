<?php

declare(strict_types=1);

namespace Restate\Sdk\Service\Attribute;

use Attribute;

/**
 * Marks a class as a Restate Service: a set of stateless handlers with unlimited
 * concurrency. Handlers receive a {@see \Restate\Sdk\Context\Context}.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Service
{
    public function __construct(public readonly ?string $name = null)
    {
    }
}
