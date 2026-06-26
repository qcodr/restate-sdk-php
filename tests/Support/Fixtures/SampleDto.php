<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Support\Fixtures;

/**
 * A small DTO exercising the {@see \Restate\Sdk\Discovery\JsonSchemaGenerator}:
 * required scalars, a nullable-and-defaulted property, a defaulted property, and a
 * nested object that should recurse one level.
 */
final class SampleDto
{
    public function __construct(
        public readonly string $name,
        public readonly int $age,
        public readonly ?string $nickname = null,
        public readonly bool $active = true,
        public readonly ?SampleAddress $address = null,
    ) {
    }
}
