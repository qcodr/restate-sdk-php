<?php

declare(strict_types=1);

namespace Restate\Conformance;

use Qcodr\Restate\Sdk\Context\ObjectContext;
use Qcodr\Restate\Sdk\Context\RetryPolicy;
use Qcodr\Restate\Sdk\Context\RunOptions;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\VirtualObject;
use RuntimeException;

/**
 * Conformance `Failing` Virtual Object: exercises terminal vs. retryable failures
 * across calls and side effects. Mirrors the Rust test-service `failing.rs`.
 *
 * The eventual-success / eventual-failure counters are deliberately IN-MEMORY,
 * non-durable instance properties: the cross-SDK test harness runs a single worker
 * that reuses this one instance across invocations, so the counters survive retries
 * (which is exactly what the "succeeds/fails after N attempts" semantics require).
 * They must NOT be backed by Restate state.
 */
#[VirtualObject(name: 'Failing')]
final class Failing
{
    private int $eventualSuccessCalls = 0;
    private int $eventualSuccessSideEffects = 0;
    private int $eventualFailureSideEffects = 0;

    #[Handler]
    public function terminallyFailingCall(ObjectContext $ctx, string $errorMessage): void
    {
        throw new TerminalException($errorMessage);
    }

    #[Handler]
    public function callTerminallyFailingCall(ObjectContext $ctx, string $errorMessage): string
    {
        $uuid = $ctx->random()->uuidV4();

        // The callee fails terminally; awaiting the call rethrows that terminal
        // failure here, so it propagates to our caller and the line below is never
        // reached (mirrors the Rust `unreachable!`).
        $ctx->objectCall('Failing', $uuid, 'terminallyFailingCall', $errorMessage);

        throw new TerminalException('This should be unreachable');
    }

    #[Handler]
    public function failingCallWithEventualSuccess(ObjectContext $ctx): int
    {
        $currentAttempt = ++$this->eventualSuccessCalls;

        if ($currentAttempt >= 4) {
            $this->eventualSuccessCalls = 0;

            return $currentAttempt;
        }

        // Non-terminal (retryable) failure: the runtime retries the whole invocation.
        // The message is intentionally a literal (no interpolation), matching Rust.
        throw new RuntimeException('Failed at attempt ${current_attempt}');
    }

    #[Handler]
    public function terminallyFailingSideEffect(ObjectContext $ctx, string $errorMessage): void
    {
        // A terminal failure raised inside a run is not retried: it is journaled and
        // propagates out of the handler.
        $ctx->run('sideEffect', static function () use ($errorMessage): void {
            throw new TerminalException($errorMessage);
        });
    }

    #[Handler]
    public function sideEffectSucceedsAfterGivenAttempts(ObjectContext $ctx, int $minimumAttempts): int
    {
        // Infinite retries (maxAttempts null): each retryable failure re-runs the
        // whole invocation, so the in-memory counter climbs until it reaches the
        // requested minimum and the side effect finally succeeds.
        $result = $ctx->run('failing_side_effect', function () use ($minimumAttempts): int {
            $currentAttempt = ++$this->eventualSuccessSideEffects;

            if ($currentAttempt >= $minimumAttempts) {
                $this->eventualSuccessSideEffects = 0;

                return $currentAttempt;
            }

            throw new RuntimeException("Failed at attempt {$currentAttempt}");
        }, new RunOptions(retryPolicy: RetryPolicy::exponential(
            initialIntervalMillis: 10,
            maxIntervalMillis: 10000,
            exponentiationFactor: 1.0,
        )));

        return \is_int($result) ? $result : (int) $result;
    }

    #[Handler]
    public function sideEffectFailsAfterGivenAttempts(ObjectContext $ctx, int $retryPolicyMaxRetryCount): int
    {
        try {
            // The closure always fails. Once the bounded retry policy is exhausted the
            // run gives up by surfacing a terminal failure, which we catch below. The
            // SuspendException thrown on the non-final attempts is NOT a
            // TerminalException, so it deliberately escapes this catch and lets the
            // invocation suspend/retry normally.
            $ctx->run('failing_side_effect', function (): void {
                $currentAttempt = ++$this->eventualFailureSideEffects;

                throw new RuntimeException("Failed at attempt {$currentAttempt}");
            }, new RunOptions(retryPolicy: RetryPolicy::exponential(
                initialIntervalMillis: 10,
                maxIntervalMillis: 10000,
                maxAttempts: $retryPolicyMaxRetryCount,
                exponentiationFactor: 1.0,
            )));
        } catch (TerminalException $e) {
            return $this->eventualFailureSideEffects;
        }

        throw new TerminalException('Expecting the side effect to fail!');
    }
}
