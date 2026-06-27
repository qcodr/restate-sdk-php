<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Context;

use Psr\Log\LoggerInterface;

/**
 * The context available to every handler (Service, Virtual Object, Workflow).
 *
 * Every method here is a durable building block: results of {@see run}, calls,
 * timers and awakeables are journaled, so the handler replays deterministically
 * after a failure. Plain PHP code between these calls must stay side-effect free —
 * use {@see run} to capture anything non-deterministic.
 */
interface Context
{
    public function invocationId(): string;

    /**
     * The request headers forwarded with this invocation, as a name => value map.
     *
     * @return array<string, string>
     */
    public function requestHeaders(): array;

    /** The idempotency key the caller attached to this invocation, if any. */
    public function requestIdempotencyKey(): ?string;

    /**
     * The W3C trace context the runtime propagated for this invocation, parsed from
     * the `traceparent` / `tracestate` request headers, or null when absent or
     * malformed.
     *
     * The SDK emits no spans itself — it stays dependency-free. Bridge the returned
     * {@see TraceContext} into the OpenTelemetry PHP SDK (build a `SpanContext` from
     * its trace/span ids and flags, or re-inject {@see TraceContext::toTraceparent()}
     * through a propagator) to start spans that nest under the incoming trace.
     *
     * Propagation boundary: trace propagation *across the service graph* (to the
     * services this invocation calls or sends to) is the **Restate runtime's** job —
     * it stamps `traceparent` on the request it hands the SDK and links child
     * invocations. Do not manually forward `traceparent` on outgoing call headers;
     * this context is for spans around your own work *inside* the handler.
     * See `examples/tracing.php` for the OpenTelemetry bridge.
     */
    public function traceContext(): ?TraceContext;

    /**
     * Executes a side effect durably: the closure runs once, its result is persisted
     * to the journal, and on replay the stored result is returned without re-running.
     *
     * When a {@see RunOptions} carrying a {@see RetryPolicy} is supplied, a closure
     * that fails with a non-terminal error is retried per that policy (exponential
     * backoff, capped attempts) before giving up with a terminal failure.
     *
     * @template T
     * @param callable():T $action
     *
     * @return T
     */
    public function run(string $name, callable $action, ?RunOptions $options = null): mixed;

    /**
     * Executes a side effect durably WITHOUT awaiting it, returning a future that
     * resolves to the journaled result. Like {@see run} the closure runs once and its
     * result is persisted, but control returns immediately so the run can be composed
     * concurrently (e.g. raced via {@see select} / {@see awaitAll} against timers,
     * calls or signals). Await the returned future to obtain the value.
     *
     * @param callable():mixed $action
     */
    public function runAsync(string $name, callable $action): DurableFuture;

    /** Suspends the invocation for the given duration using a durable timer. */
    public function sleep(float $seconds): void;

    /**
     * Starts a durable timer without awaiting it, returning a future that resolves
     * when the duration elapses. Useful for fan-out (await several timers/calls
     * concurrently via {@see select} / {@see awaitAll}).
     */
    public function timer(float $seconds): DurableFuture;

    /**
     * Calls a service handler and awaits its result.
     *
     * @param array<string, string> $headers extra request headers forwarded to the callee
     */
    public function serviceCall(
        string $service,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): mixed;

    /** @param array<string, string> $headers extra request headers forwarded to the callee */
    public function objectCall(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): mixed;

    /** @param array<string, string> $headers extra request headers forwarded to the callee */
    public function workflowCall(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): mixed;

    /**
     * Calls a handler forwarding the RAW request bytes and awaiting the RAW response
     * bytes, bypassing serde entirely.
     *
     * Unlike {@see serviceCall} / {@see objectCall} no (de)serialization is applied:
     * `$parameter` is sent verbatim and the callee's response is returned verbatim. A
     * failed call surfaces as a {@see \Qcodr\Restate\Sdk\Error\TerminalException}.
     *
     * `$key === ''` targets a Service; a non-empty `$key` targets a Virtual Object or
     * Workflow — the same convention as {@see objectCall}.
     */
    public function genericCall(
        string $service,
        string $key,
        string $handler,
        string $parameter,
        ?string $idempotencyKey = null,
    ): string;

    /**
     * Issues a call without awaiting it, returning a future for concurrent composition.
     *
     * @param array<string, string> $headers extra request headers forwarded to the callee
     */
    public function serviceCallAsync(
        string $service,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): DurableFuture;

    /** @param array<string, string> $headers extra request headers forwarded to the callee */
    public function objectCallAsync(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): DurableFuture;

    /** @param array<string, string> $headers extra request headers forwarded to the callee */
    public function workflowCallAsync(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): DurableFuture;

    /**
     * Issues a service call, returning a {@see CallHandle} exposing both the result
     * future and the callee's invocation-id future.
     *
     * @param array<string, string> $headers extra request headers forwarded to the callee
     */
    public function serviceCallHandle(
        string $service,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): CallHandle;

    /** @param array<string, string> $headers extra request headers forwarded to the callee */
    public function objectCallHandle(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): CallHandle;

    /** @param array<string, string> $headers extra request headers forwarded to the callee */
    public function workflowCallHandle(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): CallHandle;

    /**
     * Awaits the first of several futures to complete (race semantics).
     *
     * @param DurableFuture ...$futures
     *
     * @return array{0: int, 1: mixed} [winningIndex, value]
     */
    public function select(DurableFuture ...$futures): array;

    /**
     * Awaits every future to complete, returning their values in order.
     *
     * @param list<DurableFuture> $futures
     *
     * @return list<mixed>
     */
    public function awaitAll(array $futures): array;

    /**
     * Awaits the first future to complete *successfully* (JS `Promise.any`).
     *
     * Ready futures that have failed are skipped; a failure is only surfaced when
     * every future has failed, in which case the last failure is rethrown as a
     * {@see \Qcodr\Restate\Sdk\Error\TerminalException}.
     *
     * @param DurableFuture ...$futures
     *
     * @return mixed the value of the first future to complete successfully
     */
    public function awaitAny(DurableFuture ...$futures): mixed;

    /**
     * Awaits every future to succeed, short-circuiting on the first failure
     * (JS `Promise.all`): if any future has failed its
     * {@see \Qcodr\Restate\Sdk\Error\TerminalException} is rethrown immediately; otherwise
     * the values are returned in order.
     *
     * @param list<DurableFuture> $futures
     *
     * @return list<mixed>
     */
    public function awaitAllSucceeded(array $futures): array;

    /** @param array<string, string> $headers extra request headers forwarded to the callee */
    public function serviceSend(
        string $service,
        string $handler,
        mixed $input = null,
        float $delaySeconds = 0.0,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): void;

    /** @param array<string, string> $headers extra request headers forwarded to the callee */
    public function objectSend(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        float $delaySeconds = 0.0,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): void;

    /** @param array<string, string> $headers extra request headers forwarded to the callee */
    public function workflowSend(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        float $delaySeconds = 0.0,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): void;

    /**
     * Sends the RAW request bytes one-way (fire-and-forget), bypassing serde, and
     * returns the callee's invocation id.
     *
     * Unlike {@see serviceSend} / {@see objectSend} no serialization is applied:
     * `$parameter` is sent verbatim. When `$delayMillis` is a positive value the call
     * is scheduled that many milliseconds into the future.
     *
     * `$key === ''` targets a Service; a non-empty `$key` targets a Virtual Object or
     * Workflow — the same convention as {@see objectSend}.
     */
    public function genericSend(
        string $service,
        string $key,
        string $handler,
        string $parameter,
        ?int $delayMillis = null,
        ?string $idempotencyKey = null,
    ): string;

    /**
     * Requests cancellation of another invocation by id, delivering it the built-in
     * CANCEL signal. The target observes the cancellation at its next await point.
     */
    public function cancel(string $invocationId): void;

    /** Creates an awakeable: an external callback handle that resolves a durable future. */
    public function awakeable(): Awakeable;

    public function resolveAwakeable(string $id, mixed $value = null): void;

    public function rejectAwakeable(string $id, string $message): void;

    /**
     * Creates a future that resolves when a named signal is delivered to THIS
     * invocation. The signal is addressed by the chosen `$name`: another invocation
     * resolves it via {@see resolveSignal} (or rejects it via {@see rejectSignal})
     * targeting this invocation's id and the same name.
     */
    public function createSignal(string $name): DurableFuture;

    /** Resolves a named signal on another invocation with a value. */
    public function resolveSignal(string $invocationId, string $name, mixed $value = null): void;

    /** Rejects a named signal on another invocation with a terminal failure reason. */
    public function rejectSignal(string $invocationId, string $name, string $reason): void;

    /** Deterministic, replay-stable randomness seeded by the runtime. */
    public function random(): ContextRand;

    /**
     * A replay-aware PSR-3 logger: log lines emitted while replaying are suppressed,
     * so only the processing-phase output reaches the underlying logger.
     */
    public function logger(): LoggerInterface;
}
