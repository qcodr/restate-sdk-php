<?php

declare(strict_types=1);

namespace Restate\Sdk\Service;

/**
 * A single invokable handler resolved from a service class by reflection: its
 * Restate-facing name and type, the PHP method to call, and the input/output
 * typing used for (de)serialization and discovery.
 */
final class HandlerDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly ?HandlerType $type,
        public readonly string $method,
        public readonly bool $hasInput,
        public readonly ?string $inputType,
        public readonly bool $hasOutput,
        public readonly ?string $outputType,
    ) {
    }

    public function isShared(): bool
    {
        return $this->type !== null && $this->type->isShared();
    }
}
