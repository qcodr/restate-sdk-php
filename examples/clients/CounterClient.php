<?php

declare(strict_types=1);

namespace Restate\Examples\Clients;

use Restate\Sdk\Context\Context;
use Restate\Sdk\Context\DurableFuture;

/**
 * Typed Restate client for the "Counter" virtual object.
 *
 * Generated from Restate\Sdk\Tests\Support\Fixtures\Counter by Restate\Sdk\Codegen\ClientGenerator;
 * do not edit by hand — re-run restate-codegen to regenerate.
 */
final class CounterClient
{
    private function __construct(
        private readonly Context $ctx,
        private readonly string $key,
    ) {
    }

    public static function fromContext(Context $ctx, string $key): self
    {
        return new self($ctx, $key);
    }

    /**
     * Calls the "add" handler and awaits its result.
     *
     * @param array<string, string> $headers extra request headers forwarded to the callee
     */
    public function add(int $delta, ?string $idempotencyKey = null, array $headers = []): int
    {
        /** @var int $result */
        $result = $this->ctx->objectCall('Counter', $this->key, 'add', $delta, $idempotencyKey, $headers);

        return $result;
    }

    /**
     * Calls the "add" handler without awaiting it, for concurrent composition.
     *
     * @param array<string, string> $headers extra request headers forwarded to the callee
     */
    public function addAsync(int $delta, ?string $idempotencyKey = null, array $headers = []): DurableFuture
    {
        return $this->ctx->objectCallAsync('Counter', $this->key, 'add', $delta, $idempotencyKey, $headers);
    }

    /**
     * Sends a one-way request to the "add" handler (fire-and-forget).
     *
     * @param array<string, string> $headers extra request headers forwarded to the callee
     */
    public function addSend(int $delta, float $delaySeconds = 0.0, ?string $idempotencyKey = null, array $headers = []): void
    {
        $this->ctx->objectSend('Counter', $this->key, 'add', $delta, $delaySeconds, $idempotencyKey, $headers);
    }

    /**
     * Calls the "get" handler and awaits its result.
     *
     * @param array<string, string> $headers extra request headers forwarded to the callee
     */
    public function get(?string $idempotencyKey = null, array $headers = []): int
    {
        /** @var int $result */
        $result = $this->ctx->objectCall('Counter', $this->key, 'get', null, $idempotencyKey, $headers);

        return $result;
    }

    /**
     * Calls the "get" handler without awaiting it, for concurrent composition.
     *
     * @param array<string, string> $headers extra request headers forwarded to the callee
     */
    public function getAsync(?string $idempotencyKey = null, array $headers = []): DurableFuture
    {
        return $this->ctx->objectCallAsync('Counter', $this->key, 'get', null, $idempotencyKey, $headers);
    }

    /**
     * Sends a one-way request to the "get" handler (fire-and-forget).
     *
     * @param array<string, string> $headers extra request headers forwarded to the callee
     */
    public function getSend(float $delaySeconds = 0.0, ?string $idempotencyKey = null, array $headers = []): void
    {
        $this->ctx->objectSend('Counter', $this->key, 'get', null, $delaySeconds, $idempotencyKey, $headers);
    }
}
