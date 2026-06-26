<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Context;

use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Protocol\ErrorBehavior;
use Qcodr\Restate\Sdk\Protocol\Message\CompleteAwakeableCommand;
use Qcodr\Restate\Sdk\Protocol\Message\Failure;
use Qcodr\Restate\Sdk\Protocol\Message\Header;
use Qcodr\Restate\Sdk\Serde\Serde;
use Qcodr\Restate\Sdk\Serde\SerializationException;
use Qcodr\Restate\Sdk\Vm\InvocationInput;
use Qcodr\Restate\Sdk\Vm\StateMachine;
use Qcodr\Restate\Sdk\Vm\SuspendException;
use Throwable;

/**
 * The concrete context handed to handlers. It adapts the ergonomic, typed context
 * API onto the low-level state machine syscalls, applying serde on the boundary.
 *
 * One instance is created per invocation. State writes are gated by {@see $writable}
 * so shared handlers (typed as {@see SharedObjectContext}) cannot mutate state even
 * though the concrete object exposes the methods.
 */
final class RestateContext implements WorkflowContext, SharedWorkflowContext
{
    public function __construct(
        private readonly StateMachine $vm,
        private readonly InvocationInput $input,
        private readonly Serde $serde,
        private readonly Clock $clock,
        private readonly ContextRand $rand,
        private readonly bool $writable,
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
    ) {
    }

    public function invocationId(): string
    {
        return $this->input->invocationId;
    }

    /**
     * @return array<string, string>
     */
    public function requestHeaders(): array
    {
        $headers = [];
        foreach ($this->input->headers as $header) {
            $headers[$header->key] = $header->value;
        }

        return $headers;
    }

    public function requestIdempotencyKey(): ?string
    {
        return $this->input->idempotencyKey;
    }

    public function traceContext(): ?TraceContext
    {
        return TraceContext::fromHeaders($this->requestHeaders());
    }

    public function key(): string
    {
        return $this->input->key;
    }

    public function random(): ContextRand
    {
        return $this->rand;
    }

    public function logger(): LoggerInterface
    {
        return new ReplayAwareLogger($this->logger, fn (): bool => $this->vm->isProcessing());
    }

    public function run(string $name, callable $action, ?RunOptions $options = null): mixed
    {
        $completionId = $this->vm->sysRun($name);

        if ($this->vm->isCompletionReady($completionId)) {
            return $this->completionFuture($completionId)->await();
        }

        try {
            $result = $action();
        } catch (TerminalException $e) {
            $this->vm->proposeRunCompletionFailure($completionId, new Failure($e->statusCode(), $e->getMessage()));

            // In request/response, awaiting the just-proposed (not-yet-replayed)
            // completion writes the suspension and unwinds; in streaming it parks until
            // the runtime echoes the completion, where await() raises the terminal
            // failure carried by it.
            return $this->completionFuture($completionId)->await();
        } catch (Throwable $e) {
            return $this->handleRunFailure($completionId, $e, $options?->retryPolicy);
        }

        try {
            $serialized = $this->serde->serialize($result);
        } catch (SerializationException $e) {
            // A non-serializable result must fail terminally, not retry forever: the
            // RunCommand is already journaled, so without a proposed completion the
            // invocation would re-run the closure on every attempt.
            $this->vm->proposeRunCompletionFailure(
                $completionId,
                new Failure(TerminalException::DEFAULT_CODE, 'run result is not serializable: ' . $e->getMessage()),
            );

            return $this->completionFuture($completionId)->await();
        }

        $this->vm->proposeRunCompletionSuccess($completionId, $serialized);

        return $this->completionFuture($completionId)->await();
    }

    /**
     * Handles a non-terminal failure inside a run closure.
     *
     * Without a retry policy (or one without a max-attempts bound) the original
     * behavior is preserved: the throwable propagates and the invocation fails as a
     * generic retryable attempt. With a bounded policy, the run either gives up with
     * a terminal failure once attempts are exhausted, or reports a retryable attempt
     * failure carrying a computed backoff so the whole invocation re-runs the closure.
     */
    private function handleRunFailure(int $completionId, Throwable $error, ?RetryPolicy $policy): mixed
    {
        if ($policy === null || $policy->maxAttempts === null) {
            throw $error;
        }

        $retryCount = $this->vm->retryCount();
        if ($retryCount + 1 >= $policy->maxAttempts) {
            $this->vm->proposeRunCompletionFailure(
                $completionId,
                new Failure(TerminalException::DEFAULT_CODE, $error->getMessage()),
            );

            // The proposed failure is terminal; in request/response await() writes the
            // suspension and unwinds, in streaming it raises the carried failure.
            return $this->completionFuture($completionId)->await();
        }

        $this->logger->warning('Durable run failed (retryable): ' . $error->getMessage(), ['exception' => $error]);
        $this->vm->notifyError(
            TerminalException::DEFAULT_CODE,
            $error->getMessage(),
            // Reduced to the class name in production so the runtime never receives
            // absolute file paths or secrets a wrapped message might carry (see $debug).
            $this->debug ? (string) $error : \get_class($error),
            self::backoffDelayMillis($policy, $retryCount),
            ErrorBehavior::Retry,
        );

        // The ErrorMessage already closed the stream; unwind user code without
        // emitting a suspension frame.
        throw new SuspendException();
    }

    private static function backoffDelayMillis(RetryPolicy $policy, int $retryCount): int
    {
        $delay = $policy->initialIntervalMillis * ($policy->exponentiationFactor ** $retryCount);
        $capped = \min($delay, (float) $policy->maxIntervalMillis);

        return (int) \round($capped);
    }

    public function sleep(float $seconds): void
    {
        $this->timer($seconds)->await();
    }

    public function timer(float $seconds): DurableFuture
    {
        $wakeUpTime = $this->clock->nowMillis() + self::toMillis($seconds);

        return $this->completionFuture($this->vm->sysSleep($wakeUpTime));
    }

    public function serviceCall(
        string $service,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): mixed {
        return $this->callAsync($service, '', $handler, $input, $idempotencyKey, $headers)->await();
    }

    public function objectCall(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): mixed {
        return $this->callAsync($object, $key, $handler, $input, $idempotencyKey, $headers)->await();
    }

    public function workflowCall(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): mixed {
        return $this->callAsync($workflow, $key, $handler, $input, $idempotencyKey, $headers)->await();
    }

    public function genericCall(
        string $service,
        string $key,
        string $handler,
        string $parameter,
        ?string $idempotencyKey = null,
    ): string {
        // Forward the raw bytes untouched: no serde on the request or the response.
        [, $resultId] = $this->vm->sysCall($service, $handler, $key, $parameter, [], $idempotencyKey);

        // A decoder-less future yields the raw value string (and throws on a failure).
        $result = (new DurableFuture($this->vm, $resultId, isSignal: false, decoder: null))->await();

        return \is_string($result) ? $result : '';
    }

    public function serviceCallAsync(
        string $service,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): DurableFuture {
        return $this->callAsync($service, '', $handler, $input, $idempotencyKey, $headers);
    }

    public function objectCallAsync(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): DurableFuture {
        return $this->callAsync($object, $key, $handler, $input, $idempotencyKey, $headers);
    }

    public function workflowCallAsync(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): DurableFuture {
        return $this->callAsync($workflow, $key, $handler, $input, $idempotencyKey, $headers);
    }

    public function serviceCallHandle(
        string $service,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): CallHandle {
        return $this->callHandle($service, '', $handler, $input, $idempotencyKey, $headers);
    }

    public function objectCallHandle(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): CallHandle {
        return $this->callHandle($object, $key, $handler, $input, $idempotencyKey, $headers);
    }

    public function workflowCallHandle(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): CallHandle {
        return $this->callHandle($workflow, $key, $handler, $input, $idempotencyKey, $headers);
    }

    public function select(DurableFuture ...$futures): array
    {
        if ($futures === []) {
            throw new InvalidArgumentException('select() requires at least one future');
        }

        // In request/response suspendAny() throws, so this loop runs once; in streaming
        // it parks, and on resume the readiness scan re-runs and returns the winner.
        while (true) {
            for ($index = 0, $count = \count($futures); $index < $count; $index++) {
                if ($futures[$index]->isReady()) {
                    return [$index, $futures[$index]->take()];
                }
            }

            [$completions, $signals] = self::partitionFutures($futures);
            $this->vm->suspendAny($completions, $signals);
        }
    }

    public function awaitAll(array $futures): array
    {
        while (true) {
            $unresolved = \array_filter($futures, static fn (DurableFuture $future): bool => !$future->isReady());
            if ($unresolved === []) {
                return \array_map(static fn (DurableFuture $future): mixed => $future->take(), $futures);
            }

            // Request/response: suspendAll() throws here. Streaming: it parks, then the
            // loop re-checks until every future is ready.
            [$completions, $signals] = self::partitionFutures(\array_values($unresolved));
            $this->vm->suspendAll($completions, $signals);
        }
    }

    public function awaitAny(DurableFuture ...$futures): mixed
    {
        while (true) {
            $unresolved = [];
            $lastFailure = null;

            foreach ($futures as $future) {
                if (!$future->isReady()) {
                    $unresolved[] = $future;

                    continue;
                }

                try {
                    // A ready, successful future wins immediately (Promise.any).
                    return $future->take();
                } catch (TerminalException $failure) {
                    // A ready, failed future is skipped; remember it in case all fail.
                    $lastFailure = $failure;
                }
            }

            if ($unresolved !== []) {
                // Request/response: suspendAnySucceeded() throws. Streaming: it parks,
                // then the loop re-scans for the first success.
                [$completions, $signals] = self::partitionFutures($unresolved);
                $this->vm->suspendAnySucceeded($completions, $signals);

                continue;
            }

            // Every future was ready and failed: rethrow the last failure observed.
            throw $lastFailure ?? new TerminalException('awaitAny requires at least one future');
        }
    }

    public function awaitAllSucceeded(array $futures): array
    {
        while (true) {
            $unresolved = [];
            foreach ($futures as $future) {
                if (!$future->isReady()) {
                    $unresolved[] = $future;

                    continue;
                }
                if ($future->isFailed()) {
                    // Short-circuit on the first failure (Promise.all): take() rethrows it.
                    $future->take();
                }
            }

            if ($unresolved !== []) {
                // Request/response: suspendAllSucceeded() throws. Streaming: it parks,
                // then the loop re-checks until all are ready (or one fails).
                [$completions, $signals] = self::partitionFutures($unresolved);
                $this->vm->suspendAllSucceeded($completions, $signals);

                continue;
            }

            return \array_map(static fn (DurableFuture $future): mixed => $future->take(), $futures);
        }
    }

    public function serviceSend(
        string $service,
        string $handler,
        mixed $input = null,
        float $delaySeconds = 0.0,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): void {
        $this->send($service, '', $handler, $input, $delaySeconds, $idempotencyKey, $headers);
    }

    public function objectSend(
        string $object,
        string $key,
        string $handler,
        mixed $input = null,
        float $delaySeconds = 0.0,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): void {
        $this->send($object, $key, $handler, $input, $delaySeconds, $idempotencyKey, $headers);
    }

    public function workflowSend(
        string $workflow,
        string $key,
        string $handler,
        mixed $input = null,
        float $delaySeconds = 0.0,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): void {
        $this->send($workflow, $key, $handler, $input, $delaySeconds, $idempotencyKey, $headers);
    }

    public function genericSend(
        string $service,
        string $key,
        string $handler,
        string $parameter,
        ?int $delayMillis = null,
        ?string $idempotencyKey = null,
    ): string {
        $invokeTime = ($delayMillis !== null && $delayMillis > 0) ? $this->clock->nowMillis() + $delayMillis : 0;

        // Send the raw bytes untouched; the returned future resolves the callee's id.
        $invIdId = $this->vm->sysOneWayCall($service, $handler, $key, $parameter, $invokeTime, [], $idempotencyKey);
        $invocationId = (new DurableFuture($this->vm, $invIdId, isSignal: false, decoder: null))->await();

        return \is_string($invocationId) ? $invocationId : '';
    }

    public function cancel(string $invocationId): void
    {
        $this->vm->sysCancel($invocationId);
    }

    public function awakeable(): Awakeable
    {
        [$id, $signalId] = $this->vm->createAwakeable();

        return new Awakeable($id, new DurableFuture(
            $this->vm,
            $signalId,
            isSignal: true,
            decoder: fn (string $bytes): mixed => $this->serde->deserialize($bytes),
        ));
    }

    public function resolveAwakeable(string $id, mixed $value = null): void
    {
        $this->vm->sysCompleteAwakeable(
            CompleteAwakeableCommand::resolve($id, $this->serde->serialize($value)),
        );
    }

    public function rejectAwakeable(string $id, string $message): void
    {
        $this->vm->sysCompleteAwakeable(
            CompleteAwakeableCommand::reject($id, new Failure(TerminalException::DEFAULT_CODE, $message)),
        );
    }

    public function get(string $key): mixed
    {
        [$found, $value] = $this->vm->sysGetState($key);

        return $found ? $this->serde->deserialize((string) $value) : null;
    }

    /**
     * @return list<string>
     */
    public function stateKeys(): array
    {
        return $this->vm->sysGetStateKeys();
    }

    public function set(string $key, mixed $value): void
    {
        $this->assertWritable('set state');
        $this->vm->sysSetState($key, $this->serde->serialize($value));
    }

    public function clear(string $key): void
    {
        $this->assertWritable('clear state');
        $this->vm->sysClearState($key);
    }

    public function clearAll(): void
    {
        $this->assertWritable('clear all state');
        $this->vm->sysClearAllState();
    }

    public function promise(string $name): mixed
    {
        return $this->completionFuture($this->vm->sysGetPromise($name))->await();
    }

    public function peekPromise(string $name): mixed
    {
        return $this->completionFuture($this->vm->sysPeekPromise($name))->await();
    }

    public function resolvePromise(string $name, mixed $value = null): void
    {
        $this->vm->sysResolvePromise($name, $this->serde->serialize($value));
    }

    public function rejectPromise(string $name, string $reason): void
    {
        $this->vm->sysRejectPromise($name, new Failure(TerminalException::DEFAULT_CODE, $reason));
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    private function callAsync(
        string $service,
        string $key,
        string $handler,
        mixed $input,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): DurableFuture {
        return $this->callHandle($service, $key, $handler, $input, $idempotencyKey, $headers)->result();
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    private function callHandle(
        string $service,
        string $key,
        string $handler,
        mixed $input,
        ?string $idempotencyKey,
        array $headers,
    ): CallHandle {
        [$invocationIdCompletionId, $resultCompletionId] = $this->vm->sysCall(
            $service,
            $handler,
            $key,
            $this->serde->serialize($input),
            self::toHeaderList($headers),
            $idempotencyKey,
        );

        return new CallHandle(
            $this->completionFuture($resultCompletionId),
            $this->invocationIdFuture($invocationIdCompletionId),
        );
    }

    /**
     * @param array<array-key, DurableFuture> $futures
     *
     * @return array{0: list<int>, 1: list<int>} [completionIds, signalIds]
     */
    private static function partitionFutures(array $futures): array
    {
        $completions = [];
        $signals = [];
        foreach ($futures as $future) {
            if ($future->isSignal()) {
                $signals[] = $future->id();
            } else {
                $completions[] = $future->id();
            }
        }

        return [$completions, $signals];
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    private function send(
        string $service,
        string $key,
        string $handler,
        mixed $input,
        float $delaySeconds,
        ?string $idempotencyKey = null,
        array $headers = [],
    ): void {
        $invokeTime = $delaySeconds > 0.0 ? $this->clock->nowMillis() + self::toMillis($delaySeconds) : 0;
        $this->vm->sysOneWayCall(
            $service,
            $handler,
            $key,
            $this->serde->serialize($input),
            $invokeTime,
            self::toHeaderList($headers),
            $idempotencyKey,
        );
    }

    private function completionFuture(int $completionId): DurableFuture
    {
        return new DurableFuture(
            $this->vm,
            $completionId,
            isSignal: false,
            decoder: fn (string $bytes): mixed => $this->serde->deserialize($bytes),
        );
    }

    /**
     * The future resolving to the callee's invocation id. No serde decoder is needed:
     * the notification carries the id as a string, surfaced directly by the future.
     */
    private function invocationIdFuture(int $completionId): DurableFuture
    {
        return new DurableFuture($this->vm, $completionId, isSignal: false);
    }

    /**
     * Converts an assoc name => value header map into the protocol's header list,
     * skipping any non-string value defensively.
     *
     * @param array<array-key, mixed> $headers
     *
     * @return list<Header>
     */
    private static function toHeaderList(array $headers): array
    {
        $list = [];
        foreach ($headers as $name => $value) {
            if (\is_string($value)) {
                $list[] = new Header((string) $name, $value);
            }
        }

        return $list;
    }

    private function assertWritable(string $operation): void
    {
        if (!$this->writable) {
            throw new LogicException("Cannot {$operation} from a shared (read-only) handler");
        }
    }

    private static function toMillis(float $seconds): int
    {
        return (int) \round($seconds * 1000);
    }
}
