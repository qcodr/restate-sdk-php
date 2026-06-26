<?php

declare(strict_types=1);

namespace Restate\Conformance;

use Restate\Sdk\Context\Context;
use Restate\Sdk\Context\DurableFuture;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Service;

/**
 * Grab-bag of small utility handlers used across the conformance suite to exercise
 * echoing, header propagation, concurrent timers, side-effect journaling and
 * cancellation.
 *
 * Mirrors the Rust `test-services/src/test_utils_service.rs`.
 */
#[Service(name: 'TestUtilsService')]
final class TestUtilsService
{
    /** Returns the input unchanged. */
    #[Handler]
    public function echo(Context $ctx, string $input): string
    {
        return $input;
    }

    /** Returns the input ASCII-uppercased. */
    #[Handler]
    public function uppercaseEcho(Context $ctx, string $input): string
    {
        return \strtoupper($input);
    }

    /**
     * Returns the input unchanged.
     *
     * TODO: raw/octet-stream serde — the Rust handler takes `bytes::Bytes` and returns
     * `Vec<u8>` verbatim over `application/octet-stream`. Our default serde here is JSON,
     * so this is implemented as a plain string passthrough; the conformance orchestrator
     * wires the per-handler raw serde separately.
     */
    #[Handler]
    public function rawEcho(Context $ctx, string $input): string
    {
        return $input;
    }

    /**
     * Echoes back the request headers as a name => value map.
     *
     * @return array<string, string>
     */
    #[Handler]
    public function echoHeaders(Context $ctx): array
    {
        return $ctx->requestHeaders();
    }

    /**
     * Starts one durable timer per requested duration and awaits them all concurrently.
     *
     * @param list<int> $millisDurations sleep durations in milliseconds
     */
    #[Handler]
    public function sleepConcurrently(Context $ctx, array $millisDurations): void
    {
        /** @var list<DurableFuture> $timers */
        $timers = [];
        foreach ($millisDurations as $millis) {
            $timers[] = $ctx->timer(((int) $millis) / 1000);
        }

        $ctx->awaitAll($timers);
    }

    /**
     * Runs `$increments` durable side effects, each incrementing a per-invocation local
     * counter, and returns the resulting count.
     *
     * The counter is plain in-memory state, created fresh per invocation (matching the
     * Rust `AtomicU8`). Each `run` is journaled, so on replay the closures are NOT
     * re-executed and the returned count reflects only the side effects actually executed
     * during this attempt.
     */
    #[Handler]
    public function countExecutedSideEffects(Context $ctx, int $increments): int
    {
        $counter = 0;
        for ($i = 0; $i < $increments; $i++) {
            $ctx->run("side-effect-{$i}", static function () use (&$counter): int {
                return ++$counter;
            });
        }

        return $counter;
    }

    /** Requests cancellation of another invocation by id. */
    #[Handler]
    public function cancelInvocation(Context $ctx, string $invocationId): void
    {
        $ctx->cancel($invocationId);
    }
}
