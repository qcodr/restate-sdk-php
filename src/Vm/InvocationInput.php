<?php

declare(strict_types=1);

namespace Restate\Sdk\Vm;

use Restate\Sdk\Protocol\Message\Header;

/**
 * The invocation envelope handed to user code at the start of a handler: the input
 * body plus the metadata needed to build the context (object/workflow key, request
 * headers, deterministic RNG seed, idempotency key).
 */
final class InvocationInput
{
    /** @param list<Header> $headers */
    public function __construct(
        public readonly string $invocationId,
        public readonly string $key,
        public readonly string $body,
        public readonly array $headers,
        public readonly int $randomSeed,
        public readonly ?string $idempotencyKey,
        public readonly int $retryCount = 0,
    ) {
    }
}
