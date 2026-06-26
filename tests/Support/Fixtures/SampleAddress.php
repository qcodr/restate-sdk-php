<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Support\Fixtures;

/**
 * Nested DTO used by {@see SampleDto} to verify one level of schema recursion.
 */
final class SampleAddress
{
    public function __construct(
        public readonly string $city,
        public readonly int $zip,
    ) {
    }
}
