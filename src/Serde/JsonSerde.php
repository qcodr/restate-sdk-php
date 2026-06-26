<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Serde;

use JsonException;

/**
 * JSON serialization (the default content type for Restate handlers).
 *
 * Values are encoded with {@see json_encode}; on the way back, scalar type hints
 * coerce the decoded value so a handler declaring `int`/`string`/etc. receives the
 * expected PHP type. Objects/arrays are returned as associative arrays.
 */
final class JsonSerde implements Serde
{
    public function serialize(mixed $value): string
    {
        try {
            return \json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new SerializationException('Failed to JSON-encode value: ' . $e->getMessage(), previous: $e);
        }
    }

    public function deserialize(string $bytes, ?string $type = null): mixed
    {
        if ($bytes === '') {
            return null;
        }

        try {
            $decoded = \json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SerializationException('Failed to JSON-decode value: ' . $e->getMessage(), previous: $e);
        }

        return self::coerce($decoded, $type);
    }

    private static function coerce(mixed $value, ?string $type): mixed
    {
        if ($value === null || !\is_scalar($value)) {
            return $value;
        }

        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'string' => (string) $value,
            'bool' => (bool) $value,
            default => $value,
        };
    }
}
