<?php

declare(strict_types=1);

namespace Restate\Examples\Clients;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Context\DurableFuture;

/**
 * Typed Restate client for the "Greeter" service.
 *
 * Generated from Qcodr\Restate\Sdk\Tests\Support\Fixtures\Greeter by Qcodr\Restate\Sdk\Codegen\ClientGenerator;
 * do not edit by hand — re-run restate-codegen to regenerate.
 */
final class GreeterClient
{
    private function __construct(
        private readonly Context $ctx,
    ) {
    }

    public static function fromContext(Context $ctx): self
    {
        return new self($ctx);
    }

    /**
     * Calls the "greet" handler and awaits its result.
     *
     * @param array<string, string> $headers extra request headers forwarded to the callee
     */
    public function greet(string $name, ?string $idempotencyKey = null, array $headers = []): string
    {
        /** @var string $result */
        $result = $this->ctx->serviceCall('Greeter', 'greet', $name, $idempotencyKey, $headers);

        return $result;
    }

    /**
     * Calls the "greet" handler without awaiting it, for concurrent composition.
     *
     * @param array<string, string> $headers extra request headers forwarded to the callee
     */
    public function greetAsync(string $name, ?string $idempotencyKey = null, array $headers = []): DurableFuture
    {
        return $this->ctx->serviceCallAsync('Greeter', 'greet', $name, $idempotencyKey, $headers);
    }

    /**
     * Sends a one-way request to the "greet" handler (fire-and-forget).
     *
     * @param array<string, string> $headers extra request headers forwarded to the callee
     */
    public function greetSend(string $name, float $delaySeconds = 0.0, ?string $idempotencyKey = null, array $headers = []): void
    {
        $this->ctx->serviceSend('Greeter', 'greet', $name, $delaySeconds, $idempotencyKey, $headers);
    }
}
