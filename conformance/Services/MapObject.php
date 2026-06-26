<?php

declare(strict_types=1);

namespace Restate\Conformance;

use Restate\Sdk\Context\ObjectContext;
use Restate\Sdk\Error\TerminalException;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\VirtualObject;

/**
 * Conformance map Virtual Object: stores string values under dynamic state keys
 * (the entry key). Mirrors the Rust test-service.
 */
#[VirtualObject(name: 'MapObject')]
final class MapObject
{
    /**
     * @param array{key: string, value: string} $entry
     */
    #[Handler]
    public function set(ObjectContext $ctx, array $entry): void
    {
        $key = $entry['key'] ?? null;
        $value = $entry['value'] ?? null;
        if (!\is_string($key) || !\is_string($value)) {
            throw new TerminalException('Invalid entry: expected string key and value');
        }

        $ctx->set($key, $value);
    }

    #[Handler]
    public function get(ObjectContext $ctx, string $key): string
    {
        $value = $ctx->get($key);

        return \is_string($value) ? $value : '';
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    #[Handler]
    public function clearAll(ObjectContext $ctx): array
    {
        $entries = [];
        foreach ($ctx->stateKeys() as $key) {
            $value = $ctx->get($key);
            if (!\is_string($value)) {
                throw new TerminalException("Missing key {$key}");
            }
            $entries[] = ['key' => $key, 'value' => $value];
        }

        $ctx->clearAll();

        return $entries;
    }
}
