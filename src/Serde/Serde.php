<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Serde;

/**
 * Serializes handler inputs/outputs and state values to and from the raw bytes that
 * travel on the wire. The default implementation is {@see JsonSerde}; custom
 * implementations can support other content types.
 */
interface Serde
{
    public function serialize(mixed $value): string;

    /**
     * @param string|null $type the expected PHP type hint, when known (e.g. 'int', 'array', a class name)
     */
    public function deserialize(string $bytes, ?string $type = null): mixed;
}
