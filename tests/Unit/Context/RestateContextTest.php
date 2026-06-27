<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Context;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qcodr\Restate\Sdk\Context\Awakeable;
use Qcodr\Restate\Sdk\Context\CallHandle;
use Qcodr\Restate\Sdk\Context\Clock;
use Qcodr\Restate\Sdk\Context\ContextRand;
use Qcodr\Restate\Sdk\Context\DurableFuture;
use Qcodr\Restate\Sdk\Context\RestateContext;
use Qcodr\Restate\Sdk\Context\RetryPolicy;
use Qcodr\Restate\Sdk\Context\RunOptions;
use Qcodr\Restate\Sdk\Context\SystemClock;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Protocol\Frame;
use Qcodr\Restate\Sdk\Protocol\Message\Failure;
use Qcodr\Restate\Sdk\Protocol\Message\Header;
use Qcodr\Restate\Sdk\Protocol\Message\Value;
use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\Protobuf\WireType;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Serde\JsonSerde;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;
use Qcodr\Restate\Sdk\Vm\StateMachine;
use Qcodr\Restate\Sdk\Vm\SuspendException;
use RuntimeException;
use Stringable;

final class RestateContextTest extends TestCase
{
    /** A fixed wall-clock instant so timer/delay deadlines decode to exact values. */
    private const NOW_MILLIS = 1_700_000_000_000;

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

        $frames = $this->frames($vm);
        self::assertSame(
            [MessageType::RunCommand, MessageType::ProposeRunCompletion, MessageType::Suspension],
            $this->typesOf($frames),
        );

        // The proposed completion carries the serialized success value (raw bytes,
        // field 14) and no failure (field 15).
        $proposal = self::fields(self::frameOfType($frames, MessageType::ProposeRunCompletion)->payload);
        self::assertSame('"value"', $proposal[14]);
        self::assertArrayNotHasKey(15, $proposal);
    }

    public function testRunWithTerminalExceptionProposesFailureAndSuspends(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        try {
            $ctx->run('step', static fn (): mixed => throw new TerminalException('boom', 418));
        } catch (SuspendException) {
            // suspension expected; verified through the emitted frames below
        }

        $frames = $this->frames($vm);
        self::assertSame(
            [MessageType::RunCommand, MessageType::ProposeRunCompletion, MessageType::Suspension],
            $this->typesOf($frames),
        );

        // A terminal failure is proposed verbatim (status code and message preserved).
        $failure = self::proposedFailure($frames);
        self::assertSame(418, $failure->code);
        self::assertSame('boom', $failure->message);
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

        $frames = $this->frames($vm);
        self::assertSame(
            [MessageType::RunCommand, MessageType::ProposeRunCompletion, MessageType::Suspension],
            $this->typesOf($frames),
        );

        // The terminal failure prefixes the serde error; the prefix must lead and the
        // underlying message must still be appended.
        $failure = self::proposedFailure($frames);
        self::assertSame(TerminalException::DEFAULT_CODE, $failure->code);
        self::assertStringStartsWith('run result is not serializable: ', $failure->message);
        self::assertGreaterThan(
            \strlen('run result is not serializable: '),
            \strlen($failure->message),
            'the underlying serde error must be appended after the prefix',
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

        $frames = $this->frames($vm);
        // Attempts exhausted -> a terminal failure is proposed, then suspension.
        self::assertSame(
            [MessageType::RunCommand, MessageType::ProposeRunCompletion, MessageType::Suspension],
            $this->typesOf($frames),
        );

        $failure = self::proposedFailure($frames);
        self::assertSame(TerminalException::DEFAULT_CODE, $failure->code);
        self::assertSame('always fails', $failure->message);
    }

    public function testRunRetryPolicyRetriesWhileAttemptsRemain(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));
        // maxAttempts 2 with retryCount 0 leaves one attempt: the run must report a
        // retryable Error, NOT give up with a proposed terminal failure.
        $options = new RunOptions(new RetryPolicy(initialIntervalMillis: 10, maxIntervalMillis: 100, maxAttempts: 2));

        try {
            $ctx->run('step', static fn (): mixed => throw new RuntimeException('transient'), $options);
        } catch (SuspendException) {
            // suspension expected; verified through the emitted frames below
        }

        self::assertSame(
            [MessageType::RunCommand, MessageType::Error],
            $this->frameTypes($vm),
        );
    }

    public function testRunRetryPolicyReportsRetryableAttempt(): void
    {
        $error = new RuntimeException('transient');
        $logger = self::recordingLogger();
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'), logger: $logger);
        $options = new RunOptions(new RetryPolicy(initialIntervalMillis: 10, maxIntervalMillis: 100, maxAttempts: 5));

        try {
            $ctx->run('step', static fn (): mixed => throw $error, $options);
        } catch (SuspendException) {
            // suspension expected; verified through the emitted frames below
        }

        $frames = $this->frames($vm);
        self::assertSame(MessageType::RunCommand, $frames[0]->type());

        // A retryable attempt is reported as an Error frame carrying the message, the
        // class name (not the full exception, in non-debug mode) and the backoff.
        $error_frame = self::fields(self::frameOfType($frames, MessageType::Error)->payload);
        self::assertSame(TerminalException::DEFAULT_CODE, $error_frame[1]);
        self::assertSame('transient', $error_frame[2]);
        self::assertSame('RuntimeException', $error_frame[3]);
        self::assertSame(10, $error_frame[8]);

        // The failure is also logged once, with the prefixed message and the exception.
        self::assertCount(1, $logger->records);
        self::assertSame('Durable run failed (retryable): transient', $logger->records[0]['message']);
        self::assertSame(['exception' => $error], $logger->records[0]['context']);
    }

    public function testRunRetryableAttemptReportsFullErrorInDebugMode(): void
    {
        $error = new RuntimeException('transient');
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'), debug: true);
        $options = new RunOptions(new RetryPolicy(initialIntervalMillis: 10, maxIntervalMillis: 100, maxAttempts: 5));

        try {
            $ctx->run('step', static fn (): mixed => throw $error, $options);
        } catch (SuspendException) {
            // suspension expected; verified through the emitted frame below
        }

        // In debug mode the Error carries the full stringified exception, not just the
        // class name.
        $error_frame = self::fields(self::frameOfType($this->frames($vm), MessageType::Error)->payload);
        $stacktrace = $error_frame[3];
        self::assertIsString($stacktrace);
        self::assertStringContainsString('RuntimeException', $stacktrace);
        self::assertStringContainsString('transient', $stacktrace);
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

    public function testServiceCallAsyncEncodesRequest(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        // Issued live (no journalled command), so the CallCommand is emitted.
        $ctx->serviceCallAsync('Greeter', 'greet', 'hi', idempotencyKey: 'idem-c', headers: ['h1' => 'v1']);

        $command = self::fields(self::frameOfType($this->frames($vm), MessageType::CallCommand)->payload);
        self::assertSame('Greeter', $command[1]);
        self::assertSame('greet', $command[2]);
        self::assertSame('"hi"', $command[3]);
        self::assertSame('idem-c', $command[6]);
        // A service call carries no object key.
        self::assertArrayNotHasKey(5, $command);
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

    public function testSelectSuspendsAwaitingTheUnresolvedCompletion(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));
        $future = $ctx->serviceCallAsync('S', 'h', null);

        try {
            $ctx->select($future);
            self::fail('expected suspension');
        } catch (SuspendException) {
            // expected
        }

        // The suspension's await tree must wait on the pending completion id (and treat
        // it as a completion, not a signal).
        $inner = self::innerAwaitTree($this->frames($vm));
        self::assertSame([$future->id()], $inner['completions']);
        self::assertSame([], $inner['signals']);
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

    public function testAwaitAnySkipsUnresolvedFutureToReturnLaterReadySuccess(): void
    {
        [, $ctx] = $this->build($this->journalWithFirstPendingSecondReadyCall('"value"'));
        $first = $ctx->serviceCallAsync('S', 'h', null);
        $second = $ctx->serviceCallAsync('S', 'h', null);

        // The first future is unresolved; awaitAny must keep scanning and return the
        // second, already-ready success rather than stopping at the first gap.
        self::assertSame('value', $ctx->awaitAny($first, $second));
    }

    public function testAwaitAnyThrowsLastFailureWhenAllReadyFutured(): void
    {
        [, $ctx] = $this->build($this->journalWithFailedCall('nope'));
        $future = $ctx->serviceCallAsync('S', 'h', null);

        // The actual failure must be rethrown, not a generic placeholder.
        $this->expectException(TerminalException::class);
        $this->expectExceptionMessage('nope');
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

    public function testAwaitAllSucceededShortCircuitsOnFailureAfterUnresolved(): void
    {
        [, $ctx] = $this->build($this->journalWithFirstPendingSecondFailedCall('bad'));
        $first = $ctx->serviceCallAsync('S', 'h', null);
        $second = $ctx->serviceCallAsync('S', 'h', null);

        // The unresolved first future must not abort the scan: the ready failure that
        // follows it still short-circuits with a TerminalException (not a suspension).
        $this->expectException(TerminalException::class);
        $ctx->awaitAllSucceeded([$first, $second]);
    }

    public function testAwaitAllSucceededShortCircuitsOnFailureBeforeUnresolved(): void
    {
        [, $ctx] = $this->build($this->journalWithFirstFailedSecondPendingCall('bad'));
        $first = $ctx->serviceCallAsync('S', 'h', null);
        $second = $ctx->serviceCallAsync('S', 'h', null);

        // A ready failure must throw immediately, before reaching the unresolved future
        // that would otherwise cause a suspension.
        $this->expectException(TerminalException::class);
        $ctx->awaitAllSucceeded([$first, $second]);
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

    public function testSendVariantsEncodeTargetWithImmediateInvokeTime(): void
    {
        [$vm, $ctx] = $this->build(
            (new JournalBuilder())->input('1'),
            clock: $this->fixedClock(self::NOW_MILLIS),
        );

        $ctx->serviceSend('Svc', 'greet', 'hi', idempotencyKey: 'idem-s');
        $ctx->objectSend('Obj', 'k1', 'add', 5);
        $ctx->workflowSend('Wf', 'k2', 'run', null, headers: ['x-trace' => 'v']);

        $frames = $this->frames($vm);
        self::assertSame(
            [MessageType::OneWayCallCommand, MessageType::OneWayCallCommand, MessageType::OneWayCallCommand],
            $this->typesOf($frames),
        );

        $service = self::fields($frames[0]->payload);
        self::assertSame('Svc', $service[1]);
        self::assertSame('greet', $service[2]);
        self::assertSame('"hi"', $service[3]);
        self::assertSame('idem-s', $service[7]);
        self::assertSame(0, $service[4] ?? 0, 'an undelayed service send fires as soon as possible');

        $object = self::fields($frames[1]->payload);
        self::assertSame('Obj', $object[1]);
        self::assertSame('add', $object[2]);
        self::assertSame('5', $object[3]);
        self::assertSame('k1', $object[6]);
        self::assertSame(0, $object[4] ?? 0, 'an undelayed object send fires as soon as possible');

        $workflow = self::fields($frames[2]->payload);
        self::assertSame('Wf', $workflow[1]);
        self::assertSame('run', $workflow[2]);
        self::assertSame('null', $workflow[3]);
        self::assertSame('k2', $workflow[6]);
        self::assertSame(0, $workflow[4] ?? 0, 'an undelayed workflow send fires as soon as possible');
    }

    public function testDelayedSendEncodesInvokeTimeFromClock(): void
    {
        [$vm, $ctx] = $this->build(
            (new JournalBuilder())->input('1'),
            clock: $this->fixedClock(self::NOW_MILLIS),
        );

        // 1.5s -> exactly 1500ms; the fractional values straddle the rounding boundary
        // so floor/ceil substitutions are observable (1.4ms rounds down, 1.5ms up).
        $ctx->objectSend('O', 'k', 'h', null, delaySeconds: 1.5);
        $ctx->objectSend('O', 'k', 'h', null, delaySeconds: 0.0014);
        $ctx->objectSend('O', 'k', 'h', null, delaySeconds: 0.0015);

        $frames = $this->frames($vm);
        self::assertSame(self::NOW_MILLIS + 1500, self::fields($frames[0]->payload)[4]);
        self::assertSame(self::NOW_MILLIS + 1, self::fields($frames[1]->payload)[4]);
        self::assertSame(self::NOW_MILLIS + 2, self::fields($frames[2]->payload)[4]);
    }

    public function testOutgoingHeadersAreEncodedFaithfully(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        // Two headers exercise the full list: a truncation to one must be observable.
        $ctx->workflowSend('Wf', 'k', 'h', null, headers: ['x-a' => 'one', 'x-b' => 'two']);

        $command = self::frameOfType($this->frames($vm), MessageType::OneWayCallCommand);
        $headers = \array_map(
            static fn (string $bytes): Header => Header::decode($bytes),
            self::repeated($command->payload, 5),
        );

        self::assertCount(2, $headers);
        self::assertSame('x-a', $headers[0]->key);
        self::assertSame('one', $headers[0]->value);
        self::assertSame('x-b', $headers[1]->key);
        self::assertSame('two', $headers[1]->value);
    }

    public function testGenericSendEncodesInvokeTimeAndPayload(): void
    {
        [$vm, $ctx] = $this->build(
            (new JournalBuilder())->input('1'),
            clock: $this->fixedClock(self::NOW_MILLIS),
        );

        try {
            // No journalled completion, so the command is emitted before the await
            // suspends; the raw parameter passes through untouched (no serde).
            $ctx->genericSend('Svc', 'k', 'h', 'param', 1000);
            self::fail('expected suspension');
        } catch (SuspendException) {
            // expected
        }

        $command = self::fields(self::frameOfType($this->frames($vm), MessageType::OneWayCallCommand)->payload);
        self::assertSame('Svc', $command[1]);
        self::assertSame('h', $command[2]);
        self::assertSame('param', $command[3]);
        self::assertSame('k', $command[6]);
        self::assertSame(self::NOW_MILLIS + 1000, $command[4]);
    }

    public function testGenericSendWithZeroDelayFiresImmediately(): void
    {
        [$vm, $ctx] = $this->build(
            (new JournalBuilder())->input('1'),
            clock: $this->fixedClock(self::NOW_MILLIS),
        );

        try {
            $ctx->genericSend('Svc', 'k', 'h', 'param', 0);
            self::fail('expected suspension');
        } catch (SuspendException) {
            // expected
        }

        $command = self::fields(self::frameOfType($this->frames($vm), MessageType::OneWayCallCommand)->payload);
        self::assertSame(0, $command[4] ?? 0, 'a zero delay must not add an invoke time');
    }

    public function testGenericSendWithoutDelayFiresImmediately(): void
    {
        [$vm, $ctx] = $this->build(
            (new JournalBuilder())->input('1'),
            clock: $this->fixedClock(self::NOW_MILLIS),
        );

        try {
            $ctx->genericSend('Svc', 'k', 'h', 'param');
            self::fail('expected suspension');
        } catch (SuspendException) {
            // expected
        }

        $command = self::fields(self::frameOfType($this->frames($vm), MessageType::OneWayCallCommand)->payload);
        self::assertSame(0, $command[4] ?? 0, 'an absent delay must not add an invoke time');
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

        $frames = $this->frames($vm);
        self::assertSame([MessageType::SendSignalCommand], $this->typesOf($frames));

        // The cancel targets the given invocation with the built-in CANCEL signal idx.
        $signal = self::fields($frames[0]->payload);
        self::assertSame('inv-target', $signal[1]);
        self::assertSame(1, $signal[2]);
    }

    // --- Awakeables ---

    public function testAwakeableExposesPublicId(): void
    {
        [, $ctx] = $this->build((new JournalBuilder(invocationId: 'inv-1'))->input('1'));

        $awakeable = $ctx->awakeable();

        self::assertInstanceOf(Awakeable::class, $awakeable);
        self::assertStringStartsWith('sign_1', $awakeable->id());
    }

    public function testAwakeableAwaitSuspendsAsSignal(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        $awakeable = $ctx->awakeable();

        try {
            $awakeable->await();
            self::fail('expected suspension');
        } catch (SuspendException) {
            // expected
        }

        // An awakeable is completed via a signal, so the await tree must wait on a
        // signal id, never a completion id. A single-signal await flattens next to the
        // CANCEL signal, so the signals live on the outer (cancel-guarded) node.
        $outer = self::outerAwaitTree($this->frames($vm));
        self::assertSame([], $outer['completions']);
        self::assertNotSame([], $outer['signals']);
        self::assertSame([], $outer['nested'], 'a single await flattens rather than nesting');
    }

    public function testResolveAndRejectAwakeableEmitCompletionCommands(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        $ctx->resolveAwakeable('prom_1abc', 'value');
        $ctx->rejectAwakeable('prom_1abc', 'denied');

        $frames = $this->frames($vm);
        self::assertSame(
            [MessageType::CompleteAwakeableCommand, MessageType::CompleteAwakeableCommand],
            $this->typesOf($frames),
        );

        // Resolve addresses the awakeable id and carries the serialized value (field 2).
        $resolve = self::fields($frames[0]->payload);
        self::assertSame('prom_1abc', $resolve[1]);
        $resolveValue = $resolve[2];
        self::assertIsString($resolveValue);
        self::assertSame('"value"', Value::decode($resolveValue)->content);
        self::assertArrayNotHasKey(3, $resolve, 'a resolved awakeable carries no failure');

        // Reject carries the failure (field 3) instead of a value.
        $reject = self::fields($frames[1]->payload);
        self::assertSame('prom_1abc', $reject[1]);
        $rejectFailure = $reject[3];
        self::assertIsString($rejectFailure);
        self::assertSame('denied', Failure::decode($rejectFailure)->message);
        self::assertArrayNotHasKey(2, $reject, 'a rejected awakeable carries no value');
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

        $frames = $this->frames($vm);
        self::assertSame(
            [MessageType::SetStateCommand, MessageType::ClearStateCommand, MessageType::ClearAllStateCommand],
            $this->typesOf($frames),
        );

        // set carries the key (field 1) and the serialized value wrapped in a Value (3).
        $set = self::fields($frames[0]->payload);
        self::assertSame('count', $set[1]);
        $setValue = $set[3];
        self::assertIsString($setValue);
        self::assertSame('1', Value::decode($setValue)->content);

        // clear carries the key it removes.
        self::assertSame('count', self::fields($frames[1]->payload)[1]);
    }

    public function testSharedContextRejectsStateMutation(): void
    {
        [, $ctx] = $this->build((new JournalBuilder())->input('1'), writable: false);

        $this->expectException(LogicException::class);
        $ctx->set('count', 1);
    }

    public function testSharedContextRejectsClear(): void
    {
        [, $ctx] = $this->build((new JournalBuilder())->input('1'), writable: false);

        $this->expectException(LogicException::class);
        $ctx->clear('count');
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

        $frames = $this->frames($vm);
        self::assertSame(
            [MessageType::CompletePromiseCommand, MessageType::CompletePromiseCommand],
            $this->typesOf($frames),
        );

        // Resolve carries the promise name (field 1) and the serialized value (field 2).
        $resolve = self::fields($frames[0]->payload);
        self::assertSame('p', $resolve[1]);
        $resolveValue = $resolve[2];
        self::assertIsString($resolveValue);
        self::assertSame('"v"', Value::decode($resolveValue)->content);

        // Reject carries the promise name and the failure (field 3).
        $reject = self::fields($frames[1]->payload);
        self::assertSame('p', $reject[1]);
        $rejectFailure = $reject[3];
        self::assertIsString($rejectFailure);
        self::assertSame('reason', Failure::decode($rejectFailure)->message);
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

    public function testSleepCommandEncodesWakeUpTimeFromClock(): void
    {
        [$vm, $ctx] = $this->build(
            (new JournalBuilder())->input('1'),
            clock: $this->fixedClock(self::NOW_MILLIS),
        );

        try {
            $ctx->sleep(1.5);
        } catch (SuspendException) {
            // expected
        }

        // The wake-up deadline is now + 1500ms, computed from the injected clock.
        $sleep = self::fields(self::frameOfType($this->frames($vm), MessageType::SleepCommand)->payload);
        self::assertSame(self::NOW_MILLIS + 1500, $sleep[1]);
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
    private function build(
        JournalBuilder $builder,
        bool $writable = true,
        ?Clock $clock = null,
        bool $debug = false,
        ?LoggerInterface $logger = null,
    ): array {
        $vm = new StateMachine(ServiceProtocolVersion::V7);
        $vm->notifyInput($builder->build());
        $vm->notifyInputClosed();
        $input = $vm->sysInput();

        $serde = new JsonSerde();
        $rand = ContextRand::fromSeed($input->randomSeed);
        $clock ??= new SystemClock();
        $logger ??= new NullLogger();

        // The non-debug case leans on the constructor's own default so that flipping it
        // is observable; only the debug case passes the flag explicitly.
        $ctx = $debug
            ? new RestateContext($vm, $input, $serde, $clock, $rand, writable: $writable, logger: $logger, debug: true)
            : new RestateContext($vm, $input, $serde, $clock, $rand, writable: $writable, logger: $logger);

        return [$vm, $ctx];
    }

    private function fixedClock(int $millis): Clock
    {
        return new class ($millis) implements Clock {
            public function __construct(private readonly int $millis)
            {
            }

            public function nowMillis(): int
            {
                return $this->millis;
            }
        };
    }

    /**
     * A logger that captures every record so emitted log calls can be asserted.
     *
     * @return AbstractLogger&object{records: list<array{message: string, context: array<array-key, mixed>}>}
     */
    private static function recordingLogger(): AbstractLogger
    {
        return new class () extends AbstractLogger {
            /** @var list<array{message: string, context: array<array-key, mixed>}> */
            public array $records = [];

            /**
             * @param array<array-key, mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
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

    private function journalWithFirstPendingSecondReadyCall(string $secondValue): JournalBuilder
    {
        return (new JournalBuilder())
            ->input('1')
            ->command(MessageType::CallCommand)
            ->command(MessageType::CallCommand)
            ->callCompletion(4, $secondValue);
    }

    private function journalWithFirstPendingSecondFailedCall(string $message): JournalBuilder
    {
        return (new JournalBuilder())
            ->input('1')
            ->command(MessageType::CallCommand)
            ->command(MessageType::CallCommand)
            ->failedCallCompletion(4, $message);
    }

    private function journalWithFirstFailedSecondPendingCall(string $message): JournalBuilder
    {
        return (new JournalBuilder())
            ->input('1')
            ->command(MessageType::CallCommand)
            ->failedCallCompletion(2, $message)
            ->command(MessageType::CallCommand);
    }

    /**
     * @return list<Frame>
     */
    private function frames(StateMachine $vm): array
    {
        return MessageCodec::decodeAll($vm->takeOutput());
    }

    /**
     * @return list<MessageType|null>
     */
    private function frameTypes(StateMachine $vm): array
    {
        return $this->typesOf($this->frames($vm));
    }

    /**
     * @param list<Frame> $frames
     *
     * @return list<MessageType|null>
     */
    private function typesOf(array $frames): array
    {
        return \array_map(static fn (Frame $frame): ?MessageType => $frame->type(), $frames);
    }

    /**
     * @param list<Frame> $frames
     */
    private static function frameOfType(array $frames, MessageType $type): Frame
    {
        foreach ($frames as $frame) {
            if ($frame->type() === $type) {
                return $frame;
            }
        }

        self::fail(\sprintf('no %s frame was emitted', $type->name));
    }

    /**
     * @param list<Frame> $frames
     */
    private static function proposedFailure(array $frames): Failure
    {
        $proposal = self::fields(self::frameOfType($frames, MessageType::ProposeRunCompletion)->payload);
        $failureBytes = $proposal[15];
        self::assertIsString($failureBytes);

        return Failure::decode($failureBytes);
    }

    /**
     * Decodes the single nested await point inside a suspension frame, peeling off the
     * outer cancel-aware wrapper the state machine adds.
     *
     * @param list<Frame> $frames
     *
     * @return array{completions: list<int>, signals: list<int>, named: list<string>, nested: list<string>, combinator: int}
     */
    private static function innerAwaitTree(array $frames): array
    {
        $outer = self::outerAwaitTree($frames);
        self::assertCount(1, $outer['nested']);

        return self::decodeFuture($outer['nested'][0]);
    }

    /**
     * @param list<\Qcodr\Restate\Sdk\Protocol\Frame> $frames
     *
     * @return array{completions: list<int>, signals: list<int>, nested: list<string>}
     */
    private static function outerAwaitTree(array $frames): array
    {
        $suspension = self::frameOfType($frames, MessageType::Suspension);
        $outerBytes = self::fields($suspension->payload)[4];
        self::assertIsString($outerBytes);

        return self::decodeFuture($outerBytes);
    }

    /**
     * Decodes a protobuf message into a field map. Repeated fields collapse to the last
     * occurrence; use {@see repeated} when every occurrence matters.
     *
     * @return array<int, int|string>
     */
    private static function fields(string $payload): array
    {
        $reader = new Reader($payload);
        $fields = [];
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            if ($wire === WireType::VARINT) {
                $fields[$field] = $reader->readVarint();
            } elseif ($wire === WireType::LENGTH_DELIMITED) {
                $fields[$field] = $reader->readLengthDelimited();
            } else {
                $reader->skip($wire);
            }
        }

        return $fields;
    }

    /**
     * @return list<string> the raw bytes of every occurrence of the length-delimited field
     */
    private static function repeated(string $payload, int $field): array
    {
        $reader = new Reader($payload);
        $values = [];
        while (!$reader->atEnd()) {
            [$current, $wire] = $reader->readTag();
            if ($wire === WireType::LENGTH_DELIMITED) {
                $bytes = $reader->readLengthDelimited();
                if ($current === $field) {
                    $values[] = $bytes;
                }
            } else {
                $reader->skip($wire);
            }
        }

        return $values;
    }

    /**
     * @return array{completions: list<int>, signals: list<int>, named: list<string>, nested: list<string>, combinator: int}
     */
    private static function decodeFuture(string $bytes): array
    {
        $reader = new Reader($bytes);
        $completions = [];
        $signals = [];
        $named = [];
        $nested = [];
        $combinator = 0;
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1:
                    $completions = self::unpackVarints($reader->readLengthDelimited());
                    break;
                case 2:
                    $signals = self::unpackVarints($reader->readLengthDelimited());
                    break;
                case 3:
                    $named[] = $reader->readLengthDelimited();
                    break;
                case 4:
                    $nested[] = $reader->readLengthDelimited();
                    break;
                case 5:
                    $combinator = $reader->readVarint();
                    break;
                default:
                    $reader->skip($wire);
            }
        }

        return [
            'completions' => $completions,
            'signals' => $signals,
            'named' => $named,
            'nested' => $nested,
            'combinator' => $combinator,
        ];
    }

    /**
     * @return list<int>
     */
    private static function unpackVarints(string $packed): array
    {
        $reader = new Reader($packed);
        $values = [];
        while (!$reader->atEnd()) {
            $values[] = $reader->readVarint();
        }

        return $values;
    }
}
