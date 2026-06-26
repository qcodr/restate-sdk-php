<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Context;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Restate\Sdk\Context\Awakeable;
use Restate\Sdk\Context\CallHandle;
use Restate\Sdk\Context\ContextRand;
use Restate\Sdk\Context\DurableFuture;
use Restate\Sdk\Context\RestateContext;
use Restate\Sdk\Context\RetryPolicy;
use Restate\Sdk\Context\RunOptions;
use Restate\Sdk\Context\SystemClock;
use Restate\Sdk\Error\TerminalException;
use Restate\Sdk\Protocol\MessageCodec;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\ServiceProtocolVersion;
use Restate\Sdk\Serde\JsonSerde;
use Restate\Sdk\Tests\Support\JournalBuilder;
use Restate\Sdk\Vm\StateMachine;
use Restate\Sdk\Vm\SuspendException;
use RuntimeException;

final class RestateContextTest extends TestCase
{
    // --- Invocation metadata ---

    public function testExposesInvocationMetadata(): void
    {
        [, $ctx] = $this->build(
            (new JournalBuilder(invocationId: 'inv-9', key: 'obj-key', idempotencyKey: 'idem-1'))
                ->input('"x"', ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']),
        );

        self::assertSame('inv-9', $ctx->invocationId());
        self::assertSame('obj-key', $ctx->key());
        self::assertSame('idem-1', $ctx->requestIdempotencyKey());
        self::assertSame(
            ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'],
            $ctx->requestHeaders(),
        );
        self::assertNotNull($ctx->traceContext());
        self::assertInstanceOf(ContextRand::class, $ctx->random());
        self::assertInstanceOf(LoggerInterface::class, $ctx->logger());
    }

    public function testTraceContextIsNullWithoutTraceparent(): void
    {
        [, $ctx] = $this->build((new JournalBuilder())->input('"x"'));

        self::assertNull($ctx->traceContext());
    }

    // --- run() ---

    public function testRunReplayReturnsStoredResultWithoutInvokingAction(): void
    {
        [, $ctx] = $this->build(
            (new JournalBuilder())
                ->input('1')
                ->command(MessageType::RunCommand)
                ->runCompletion(1, '"stored"'),
        );

        $invoked = false;
        $result = $ctx->run('step', static function () use (&$invoked): string {
            $invoked = true;

            return 'fresh';
        });

        self::assertSame('stored', $result);
        self::assertFalse($invoked, 'a replayed run must not re-execute its action');
    }

    public function testRunLiveProposesSuccessThenSuspends(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        try {
            $ctx->run('step', static fn (): string => 'value');
            self::fail('expected suspension after proposing the run completion');
        } catch (SuspendException) {
            // expected
        }

        self::assertSame(
            [MessageType::RunCommand, MessageType::ProposeRunCompletion, MessageType::Suspension],
            $this->frameTypes($vm),
        );
    }

    public function testRunWithTerminalExceptionProposesFailureAndSuspends(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        try {
            $ctx->run('step', static fn (): mixed => throw new TerminalException('boom', 418));
        } catch (SuspendException) {
            // suspension expected; verified through the emitted frames below
        }

        self::assertSame(
            [MessageType::RunCommand, MessageType::ProposeRunCompletion, MessageType::Suspension],
            $this->frameTypes($vm),
        );
    }

    public function testRunWithNonSerializableResultFailsTerminally(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        try {
            // NAN cannot be JSON-encoded, so the result is not serializable.
            $ctx->run('step', static fn (): float => \NAN);
            self::fail('expected suspension');
        } catch (SuspendException) {
            // expected
        }

        self::assertSame(
            [MessageType::RunCommand, MessageType::ProposeRunCompletion, MessageType::Suspension],
            $this->frameTypes($vm),
        );
    }

    public function testRunWithoutRetryPolicyRethrowsNonTerminalError(): void
    {
        [, $ctx] = $this->build((new JournalBuilder())->input('1'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('flaky');
        $ctx->run('step', static fn (): mixed => throw new RuntimeException('flaky'));
    }

    public function testRunRetryPolicyGivesUpWhenAttemptsExhausted(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));
        $options = new RunOptions(new RetryPolicy(initialIntervalMillis: 10, maxIntervalMillis: 100, maxAttempts: 1));

        try {
            $ctx->run('step', static fn (): mixed => throw new RuntimeException('always fails'), $options);
        } catch (SuspendException) {
            // suspension expected; verified through the emitted frames below
        }

        // Attempts exhausted -> a terminal failure is proposed, then suspension.
        self::assertSame(
            [MessageType::RunCommand, MessageType::ProposeRunCompletion, MessageType::Suspension],
            $this->frameTypes($vm),
        );
    }

    public function testRunRetryPolicyReportsRetryableAttempt(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));
        $options = new RunOptions(new RetryPolicy(initialIntervalMillis: 10, maxIntervalMillis: 100, maxAttempts: 5));

        try {
            $ctx->run('step', static fn (): mixed => throw new RuntimeException('transient'), $options);
        } catch (SuspendException) {
            // suspension expected; verified through the emitted frames below
        }

        // A retryable attempt is reported as an Error frame carrying the backoff.
        $frames = $this->frameTypes($vm);
        self::assertSame(MessageType::RunCommand, $frames[0]);
        self::assertSame(MessageType::Error, $frames[1]);
    }

    // --- Calls (request/response) ---

    public function testServiceCallReplayReturnsDeserializedResult(): void
    {
        [, $ctx] = $this->build($this->journalWithReadyCall('"hello"'));

        self::assertSame('hello', $ctx->serviceCall('Greeter', 'greet', 'x'));
    }

    public function testObjectCallReplayReturnsDeserializedResult(): void
    {
        [, $ctx] = $this->build($this->journalWithReadyCall('"hi"'));

        self::assertSame('hi', $ctx->objectCall('Counter', 'k', 'add', 1));
    }

    public function testWorkflowCallReplayReturnsDeserializedResult(): void
    {
        [, $ctx] = $this->build($this->journalWithReadyCall('"done"'));

        self::assertSame('done', $ctx->workflowCall('Flow', 'k', 'run', null));
    }

    public function testGenericCallReturnsRawBytes(): void
    {
        [, $ctx] = $this->build($this->journalWithReadyCall('raw-bytes'));

        self::assertSame('raw-bytes', $ctx->genericCall('Svc', 'k', 'h', 'param'));
    }

    public function testAsyncCallVariantsReturnFutures(): void
    {
        [, $ctx] = $this->build((new JournalBuilder())->input('1'));

        self::assertInstanceOf(DurableFuture::class, $ctx->serviceCallAsync('S', 'h', null));
        self::assertInstanceOf(DurableFuture::class, $ctx->objectCallAsync('O', 'k', 'h', null));
        self::assertInstanceOf(DurableFuture::class, $ctx->workflowCallAsync('W', 'k', 'h', null));
    }

    public function testCallHandleVariantsReturnHandles(): void
    {
        [, $ctx] = $this->build((new JournalBuilder())->input('1'));

        self::assertInstanceOf(CallHandle::class, $ctx->serviceCallHandle('S', 'h', null));
        self::assertInstanceOf(CallHandle::class, $ctx->objectCallHandle('O', 'k', 'h', null));
        self::assertInstanceOf(CallHandle::class, $ctx->workflowCallHandle('W', 'k', 'h', null));
    }

    // --- Combinators ---

    public function testSelectRejectsEmptyFutureList(): void
    {
        [, $ctx] = $this->build((new JournalBuilder())->input('1'));

        $this->expectException(InvalidArgumentException::class);
        $ctx->select();
    }

    public function testSelectReturnsFirstReadyFutureWithIndex(): void
    {
        [, $ctx] = $this->build($this->journalWithTwoReadyCalls('"a"', '"b"'));

        $first = $ctx->serviceCallAsync('S', 'h', null);
        $second = $ctx->serviceCallAsync('S', 'h', null);

        self::assertSame([0, 'a'], $ctx->select($first, $second));
    }

    public function testSelectSuspendsWhenNothingReady(): void
    {
        [, $ctx] = $this->build((new JournalBuilder())->input('1'));
        $future = $ctx->serviceCallAsync('S', 'h', null);

        $this->expectException(SuspendException::class);
        $ctx->select($future);
    }

    public function testAwaitAllReturnsEveryValueWhenReady(): void
    {
        [, $ctx] = $this->build($this->journalWithTwoReadyCalls('"a"', '"b"'));

        $first = $ctx->serviceCallAsync('S', 'h', null);
        $second = $ctx->serviceCallAsync('S', 'h', null);

        self::assertSame(['a', 'b'], $ctx->awaitAll([$first, $second]));
    }

    public function testAwaitAllSuspendsOnUnresolvedSignalAndCompletion(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));
        $completion = $ctx->serviceCallAsync('S', 'h', null);
        [, $signalId] = $vm->createAwakeable();
        $signal = new DurableFuture($vm, $signalId, isSignal: true);

        $this->expectException(SuspendException::class);
        $ctx->awaitAll([$completion, $signal]);
    }

    public function testAwaitAnyReturnsFirstReadySuccess(): void
    {
        [, $ctx] = $this->build($this->journalWithReadyCall('"win"'));
        $future = $ctx->serviceCallAsync('S', 'h', null);

        self::assertSame('win', $ctx->awaitAny($future));
    }

    public function testAwaitAnyThrowsLastFailureWhenAllReadyFutured(): void
    {
        [, $ctx] = $this->build($this->journalWithFailedCall('nope'));
        $future = $ctx->serviceCallAsync('S', 'h', null);

        $this->expectException(TerminalException::class);
        $ctx->awaitAny($future);
    }

    public function testAwaitAnySuspendsWhenUnresolved(): void
    {
        [, $ctx] = $this->build((new JournalBuilder())->input('1'));
        $future = $ctx->serviceCallAsync('S', 'h', null);

        $this->expectException(SuspendException::class);
        $ctx->awaitAny($future);
    }

    public function testAwaitAllSucceededShortCircuitsOnReadyFailure(): void
    {
        [, $ctx] = $this->build($this->journalWithFailedCall('bad'));
        $future = $ctx->serviceCallAsync('S', 'h', null);

        $this->expectException(TerminalException::class);
        $ctx->awaitAllSucceeded([$future]);
    }

    public function testAwaitAllSucceededReturnsValuesWhenAllReady(): void
    {
        [, $ctx] = $this->build($this->journalWithReadyCall('"ok"'));
        $future = $ctx->serviceCallAsync('S', 'h', null);

        self::assertSame(['ok'], $ctx->awaitAllSucceeded([$future]));
    }

    public function testAwaitAllSucceededSuspendsWhenUnresolved(): void
    {
        [, $ctx] = $this->build((new JournalBuilder())->input('1'));
        $future = $ctx->serviceCallAsync('S', 'h', null);

        $this->expectException(SuspendException::class);
        $ctx->awaitAllSucceeded([$future]);
    }

    // --- Sends (one-way) ---

    public function testSendVariantsEmitOneWayCalls(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        $ctx->serviceSend('S', 'h', null);
        $ctx->objectSend('O', 'k', 'h', null, delaySeconds: 1.5);
        $ctx->workflowSend('W', 'k', 'h', null, headers: ['x-trace' => 'v']);

        self::assertSame(
            [MessageType::OneWayCallCommand, MessageType::OneWayCallCommand, MessageType::OneWayCallCommand],
            $this->frameTypes($vm),
        );
    }

    public function testGenericSendReturnsInvocationId(): void
    {
        [, $ctx] = $this->build(
            (new JournalBuilder())
                ->input('1')
                ->command(MessageType::OneWayCallCommand)
                ->invocationIdCompletion(1, 'inv-xyz'),
        );

        self::assertSame('inv-xyz', $ctx->genericSend('Svc', 'k', 'h', 'param', 1000));
    }

    public function testCancelEmitsSignalCommand(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        $ctx->cancel('inv-target');

        self::assertSame([MessageType::SendSignalCommand], $this->frameTypes($vm));
    }

    // --- Awakeables ---

    public function testAwakeableExposesPublicId(): void
    {
        [, $ctx] = $this->build((new JournalBuilder(invocationId: 'inv-1'))->input('1'));

        $awakeable = $ctx->awakeable();

        self::assertInstanceOf(Awakeable::class, $awakeable);
        self::assertStringStartsWith('prom_1', $awakeable->id());
    }

    public function testResolveAndRejectAwakeableEmitCompletionCommands(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        $ctx->resolveAwakeable('prom_1abc', 'value');
        $ctx->rejectAwakeable('prom_1abc', 'denied');

        self::assertSame(
            [MessageType::CompleteAwakeableCommand, MessageType::CompleteAwakeableCommand],
            $this->frameTypes($vm),
        );
    }

    // --- State ---

    public function testStateKeysReturnsEagerKeys(): void
    {
        [, $ctx] = $this->build(
            (new JournalBuilder(stateMap: ['alpha' => '1', 'beta' => '2']))->input('1'),
        );

        self::assertSame(['alpha', 'beta'], $ctx->stateKeys());
    }

    public function testStateMutatorsEmitCommandsWhenWritable(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        $ctx->set('count', 1);
        $ctx->clear('count');
        $ctx->clearAll();

        self::assertSame(
            [MessageType::SetStateCommand, MessageType::ClearStateCommand, MessageType::ClearAllStateCommand],
            $this->frameTypes($vm),
        );
    }

    public function testSharedContextRejectsStateMutation(): void
    {
        [, $ctx] = $this->build((new JournalBuilder())->input('1'), writable: false);

        $this->expectException(LogicException::class);
        $ctx->set('count', 1);
    }

    public function testSharedContextRejectsClearAll(): void
    {
        [, $ctx] = $this->build((new JournalBuilder())->input('1'), writable: false);

        $this->expectException(LogicException::class);
        $ctx->clearAll();
    }

    // --- Workflow promises ---

    public function testPromiseReplayReturnsValue(): void
    {
        [, $ctx] = $this->build(
            (new JournalBuilder())
                ->input('1')
                ->command(MessageType::GetPromiseCommand)
                ->callCompletion(1, '"resolved"'),
        );

        self::assertSame('resolved', $ctx->promise('p'));
    }

    public function testPeekPromiseReplayReturnsValue(): void
    {
        [, $ctx] = $this->build(
            (new JournalBuilder())
                ->input('1')
                ->command(MessageType::PeekPromiseCommand)
                ->callCompletion(1, '"peeked"'),
        );

        self::assertSame('peeked', $ctx->peekPromise('p'));
    }

    public function testResolveAndRejectPromiseEmitCompletionCommands(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        $ctx->resolvePromise('p', 'v');
        $ctx->rejectPromise('p', 'reason');

        self::assertSame(
            [MessageType::CompletePromiseCommand, MessageType::CompletePromiseCommand],
            $this->frameTypes($vm),
        );
    }

    // --- Timers & state reads ---

    public function testSleepSuspendsUntilTimerFires(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        try {
            $ctx->sleep(1.5);
        } catch (SuspendException) {
            // a freshly-issued timer is not yet elapsed, so the handler suspends
        }

        self::assertSame(
            [MessageType::SleepCommand, MessageType::Suspension],
            $this->frameTypes($vm),
        );
    }

    public function testGetReturnsDeserializedState(): void
    {
        [, $ctx] = $this->build((new JournalBuilder(stateMap: ['count' => '5']))->input('1'));

        self::assertSame(5, $ctx->get('count'));
    }

    // --- Helpers ---

    /**
     * @return array{0: StateMachine, 1: RestateContext}
     */
    private function build(JournalBuilder $builder, bool $writable = true): array
    {
        $vm = new StateMachine(ServiceProtocolVersion::V7);
        $vm->notifyInput($builder->build());
        $vm->notifyInputClosed();
        $input = $vm->sysInput();

        $ctx = new RestateContext(
            $vm,
            $input,
            new JsonSerde(),
            new SystemClock(),
            ContextRand::fromSeed($input->randomSeed),
            writable: $writable,
            logger: new NullLogger(),
        );

        return [$vm, $ctx];
    }

    private function journalWithReadyCall(string $resultValue): JournalBuilder
    {
        return (new JournalBuilder())
            ->input('1')
            ->command(MessageType::CallCommand)
            ->callCompletion(2, $resultValue);
    }

    private function journalWithFailedCall(string $message): JournalBuilder
    {
        return (new JournalBuilder())
            ->input('1')
            ->command(MessageType::CallCommand)
            ->failedCallCompletion(2, $message);
    }

    private function journalWithTwoReadyCalls(string $firstValue, string $secondValue): JournalBuilder
    {
        return (new JournalBuilder())
            ->input('1')
            ->command(MessageType::CallCommand)
            ->callCompletion(2, $firstValue)
            ->command(MessageType::CallCommand)
            ->callCompletion(4, $secondValue);
    }

    /**
     * @return list<MessageType|null>
     */
    private function frameTypes(StateMachine $vm): array
    {
        return \array_map(
            static fn ($frame): ?MessageType => $frame->type(),
            MessageCodec::decodeAll($vm->takeOutput()),
        );
    }
}
