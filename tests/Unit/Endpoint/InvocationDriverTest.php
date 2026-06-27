<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Endpoint;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\InvocationDriver;
use Qcodr\Restate\Sdk\Endpoint\StreamingOutputSink;
use Qcodr\Restate\Sdk\Error\CancelledException;
use Qcodr\Restate\Sdk\Protocol\Frame;
use Qcodr\Restate\Sdk\Protocol\Message\Failure;
use Qcodr\Restate\Sdk\Protocol\Message\Value;
use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Service\HandlerDefinition;
use Qcodr\Restate\Sdk\Service\ServiceDefinition;
use Qcodr\Restate\Sdk\Tests\Support\BufferedStreamTransport;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\AwakeableService;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\CallOptionsService;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\CancellationService;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Greeter;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\RunService;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;
use Qcodr\Restate\Sdk\Vm\FiberSuspender;
use Qcodr\Restate\Sdk\Vm\StateMachine;

/**
 * Proves the streaming invocation driver over a network-free
 * {@see BufferedStreamTransport}: a parked await does NOT emit a `SuspensionMessage`
 * but waits for the runtime to stream a result; commands stream out as the handler
 * produces them; cancellation and ack-requiring runs are handled inline; and an EOF
 * before resolution suspends gracefully.
 *
 * The request/response parity of the same processor lives in {@see RequestProcessorTest}
 * and friends (the default throwing suspender + buffering sink); this suite only
 * exercises {@see InvocationDriver::driveStreaming}.
 */
final class InvocationDriverTest extends TestCase
{
    /**
     * Drives one streaming invocation of $handlerName on the bound $serviceInstance,
     * feeding $inbound chunks (first the journal, then late notifications).
     *
     * @param list<string> $inbound
     */
    private function drive(object $serviceInstance, string $serviceName, string $handlerName, array $inbound): BufferedStreamTransport
    {
        $endpoint = Endpoint::builder()->bind($serviceInstance)->build();
        $service = $endpoint->service($serviceName);
        self::assertInstanceOf(ServiceDefinition::class, $service);
        $handler = $service->handler($handlerName);
        self::assertInstanceOf(HandlerDefinition::class, $handler);

        $transport = new BufferedStreamTransport($inbound);
        $vm = new StateMachine(ServiceProtocolVersion::V7, new FiberSuspender(), new StreamingOutputSink($transport));

        (new InvocationDriver())->driveStreaming($vm, $service, $handler, $transport);

        return $transport;
    }

    /** @return list<MessageType|null> */
    private function frameTypes(string $output): array
    {
        return \array_map(static fn ($frame) => $frame->type(), MessageCodec::decodeAll($output));
    }

    private function successValue(string $output): string
    {
        foreach (MessageCodec::decodeAll($output) as $frame) {
            if ($frame->type() === MessageType::OutputCommand) {
                $reader = new Reader($frame->payload);
                [$field] = $reader->readTag();
                self::assertSame(14, $field, 'output carries a success value');

                return Value::decode($reader->readLengthDelimited())->content;
            }
        }
        self::fail('No OutputCommand in streamed output');
    }

    private function failure(string $output): Failure
    {
        foreach (MessageCodec::decodeAll($output) as $frame) {
            if ($frame->type() === MessageType::OutputCommand) {
                $reader = new Reader($frame->payload);
                [$field] = $reader->readTag();
                self::assertSame(15, $field, 'output carries a failure result');

                return Failure::decode($reader->readLengthDelimited());
            }
        }
        self::fail('No OutputCommand in streamed output');
    }

    public function testLateCompletionResolvesParkedCallWithoutSuspending(): void
    {
        // The call's invocation-id lands on completion id 1; it is streamed in only
        // after the handler has parked, so the CallCommand must already be on the wire.
        $transport = $this->drive(
            new CallOptionsService(),
            'CallOptionsService',
            'callAndReturnInvocationId',
            [
                (new JournalBuilder())->input('')->build(),
                (new JournalBuilder())->invocationIdCompletion(1, 'inv-xyz')->frames(),
            ],
        );

        $output = $transport->written();
        self::assertSame([MessageType::CallCommand, MessageType::AwaitingOn, MessageType::OutputCommand, MessageType::End], $this->frameTypes($output));
        self::assertNotContains(MessageType::Suspension, $this->frameTypes($output), 'a parked await announces an AwaitingOn, not a suspension');
        self::assertSame('"inv-xyz"', $this->successValue($output));
        self::assertTrue($transport->isClosed(), 'the driver closes the channel after the terminal frame');

        // Read #1 is the completion read; the CallCommand and the park's AwaitingOn were
        // already written by then.
        self::assertSame([MessageType::CallCommand, MessageType::AwaitingOn], $this->frameTypes($transport->outputAtRead(1)));
    }

    public function testSpuriousFrameDoesNotResumeParkedHandlerPrematurely(): void
    {
        // The handler parks awaiting the call's invocation id (completion id 1). A frame
        // that resolves only an unrelated id — here the never-awaited call result, id 2 —
        // must NOT wake the handler: the predicate stays false, so the driver keeps
        // reading until the awaited completion arrives.
        $transport = $this->drive(
            new CallOptionsService(),
            'CallOptionsService',
            'callAndReturnInvocationId',
            [
                (new JournalBuilder())->input('')->build(),
                // Spurious: completes id 2 (the unawaited result), not the awaited id 1.
                (new JournalBuilder())->callCompletion(2, '"unawaited"')->frames(),
                // The awaited invocation id finally arrives and resolves the parked await.
                (new JournalBuilder())->invocationIdCompletion(1, 'inv-xyz')->frames(),
            ],
        );

        $output = $transport->written();
        self::assertSame([MessageType::CallCommand, MessageType::AwaitingOn, MessageType::OutputCommand, MessageType::End], $this->frameTypes($output));
        self::assertNotContains(MessageType::Suspension, $this->frameTypes($output));
        // The awaited id (not the spurious frame's value) is returned: had the spurious
        // frame resumed the now straight-line await, it would have observed an absent
        // completion and failed instead of returning this value.
        self::assertSame('"inv-xyz"', $this->successValue($output));

        // The spurious frame (read #1) and the resolving frame (read #2) were both consumed
        // while only the CallCommand and the park's AwaitingOn had been written: the handler
        // stayed parked across the spurious frame and emitted its output only after read #2.
        self::assertSame([MessageType::CallCommand, MessageType::AwaitingOn], $this->frameTypes($transport->outputAtRead(1)));
        self::assertSame([MessageType::CallCommand, MessageType::AwaitingOn], $this->frameTypes($transport->outputAtRead(2)));
    }

    public function testPromptCancelTurnsParkedAwaitIntoTerminal409(): void
    {
        $transport = $this->drive(
            new CancellationService(),
            'CancellationService',
            'awaitThenSleep',
            [
                (new JournalBuilder())->input('')->build(),
                (new JournalBuilder())->cancelSignal()->frames(),
            ],
        );

        $output = $transport->written();
        self::assertSame([MessageType::SleepCommand, MessageType::AwaitingOn, MessageType::OutputCommand, MessageType::End], $this->frameTypes($output));
        self::assertNotContains(MessageType::Suspension, $this->frameTypes($output));

        $failure = $this->failure($output);
        self::assertSame(CancelledException::CODE, $failure->code, 'a cancelled await fails with HTTP 409');
        self::assertSame('cancelled', $failure->message);
    }

    public function testRunWithAckResolvesOnRunCompletion(): void
    {
        $service = new RunService();
        $endpoint = Endpoint::builder()->bind($service)->build();
        $serviceDefinition = $endpoint->service('RunService');
        self::assertInstanceOf(ServiceDefinition::class, $serviceDefinition);
        $handler = $serviceDefinition->handler('process');
        self::assertInstanceOf(HandlerDefinition::class, $handler);

        $transport = new BufferedStreamTransport([
            (new JournalBuilder())->input('')->build(),
            // The ack control frame is read and ignored; the value arrives separately.
            (new JournalBuilder())->proposeRunCompletionAck(1)->frames(),
            (new JournalBuilder())->runCompletion(1, '"effect-result"')->frames(),
        ]);
        $vm = new StateMachine(ServiceProtocolVersion::V7, new FiberSuspender(), new StreamingOutputSink($transport));

        (new InvocationDriver())->driveStreaming($vm, $serviceDefinition, $handler, $transport);

        $output = $transport->written();
        self::assertSame(1, $service->runs(), 'the side effect executed exactly once');
        self::assertSame([
            MessageType::RunCommand,
            MessageType::ProposeRunCompletion,
            MessageType::AwaitingOn,
            MessageType::OutputCommand,
            MessageType::End,
        ], $this->frameTypes($output));
        self::assertNotContains(MessageType::Suspension, $this->frameTypes($output));
        self::assertSame('"effect-result"', $this->successValue($output));

        // The propose frame requested an ack (the only frame that sets the flag).
        $proposeFrame = $this->frameOfType($output, MessageType::ProposeRunCompletion);
        self::assertTrue($proposeFrame->requestedAck, 'ProposeRunCompletion sets the REQUESTED_ACK flag');
    }

    public function testHandlerCompletingWithoutAwaitingEmitsOneShotOutputAndEnd(): void
    {
        $transport = $this->drive(
            new Greeter(),
            'Greeter',
            'greet',
            [(new JournalBuilder())->input('"world"')->build()],
        );

        $output = $transport->written();
        self::assertSame([MessageType::OutputCommand, MessageType::End], $this->frameTypes($output));
        self::assertSame('"Greetings world"', $this->successValue($output));
        self::assertTrue($transport->isClosed());

        // The handler never parked: only the single journal read happened (read #0),
        // so the resume loop body never ran.
        self::assertSame('', $transport->outputAtRead(1), 'no second read occurred — the loop body never ran');
    }

    public function testEofWhileParkedSuspendsGracefully(): void
    {
        // No notification chunk after the journal: the runtime hangs up while the
        // handler is parked on the never-arriving sleep timer.
        $transport = $this->drive(
            new CancellationService(),
            'CancellationService',
            'awaitThenSleep',
            [(new JournalBuilder())->input('')->build()],
        );

        $output = $transport->written();
        self::assertSame([MessageType::SleepCommand, MessageType::AwaitingOn, MessageType::Suspension], $this->frameTypes($output));
        self::assertNotContains(MessageType::OutputCommand, $this->frameTypes($output), 'no terminal output on a graceful suspend');
        self::assertTrue($transport->isClosed());
    }

    /**
     * Each of the four combinators reached while a CANCEL signal is already pending must
     * surface a terminal 409 — not re-suspend (the old bug streamed a SuspensionMessage)
     * and not throw a misleading LogicException/TerminalException. The cancel rides in the
     * same chunk as the journal, so it is in the signal table before the handler runs.
     *
     * @return array<string, array{0: string}>
     */
    public static function combinatorHandlers(): array
    {
        return [
            'awaitAny' => ['raceWhileCancelled'],
            'select' => ['selectWhileCancelled'],
            'awaitAll' => ['awaitAllWhileCancelled'],
            'awaitAllSucceeded' => ['awaitAllSucceededWhileCancelled'],
        ];
    }

    #[DataProvider('combinatorHandlers')]
    public function testCombinatorWhileCancelledTerminatesWith409(string $handlerName): void
    {
        $transport = $this->drive(
            new CancellationService(),
            'CancellationService',
            $handlerName,
            [
                // The CANCEL signal arrives in the same chunk as the journal, so it is
                // pending before the combinator is reached; no further chunk follows.
                (new JournalBuilder())->input('')->build() . (new JournalBuilder())->cancelSignal()->frames(),
            ],
        );

        $output = $transport->written();
        self::assertNotContains(MessageType::Suspension, $this->frameTypes($output), 'a pending cancel must not re-suspend a combinator');
        self::assertSame(CancelledException::CODE, $this->failure($output)->code, 'a cancelled combinator fails with HTTP 409');
        self::assertTrue($transport->isClosed());
    }

    public function testCancelObservedMidStreamThenCombinatorDrains(): void
    {
        // The sleep await observes the cancel (CancelledException, caught by the handler);
        // the handler then reaches a combinator that is still cancelled. The driver must
        // drain that re-park (its predicate already holds) into the terminal 409 rather
        // than block for a chunk that never comes and suspend.
        $transport = $this->drive(
            new CancellationService(),
            'CancellationService',
            'raceAfterObservedCancel',
            [
                (new JournalBuilder())->input('')->build(),
                (new JournalBuilder())->cancelSignal()->frames(),
            ],
        );

        $output = $transport->written();
        // SleepCommand + the sleep park's AwaitingOn, then the call, then 409 — the
        // combinator re-park drains synchronously (its predicate already holds) so it
        // never parks again and emits no second AwaitingOn.
        self::assertSame(
            [MessageType::SleepCommand, MessageType::AwaitingOn, MessageType::CallCommand, MessageType::OutputCommand, MessageType::End],
            $this->frameTypes($output),
        );
        self::assertNotContains(MessageType::Suspension, $this->frameTypes($output));
        self::assertSame(CancelledException::CODE, $this->failure($output)->code);
    }

    public function testCombinatorParkedThenCancelledMidStreamTerminatesWith409(): void
    {
        // No cancel at entry: the combinator parks, then a CANCEL arrives on the open
        // stream. The post-suspend rescan must surface a 409 (not the misleading
        // TerminalException the rescan used to throw when no future was ready).
        $transport = $this->drive(
            new CancellationService(),
            'CancellationService',
            'raceWhileCancelled',
            [
                (new JournalBuilder())->input('')->build(),
                (new JournalBuilder())->cancelSignal()->frames(),
            ],
        );

        $output = $transport->written();
        self::assertSame([MessageType::CallCommand, MessageType::AwaitingOn, MessageType::OutputCommand, MessageType::End], $this->frameTypes($output));
        self::assertNotContains(MessageType::Suspension, $this->frameTypes($output));
        self::assertSame(CancelledException::CODE, $this->failure($output)->code);
    }

    public function testBatchedCompletionsResolveTwoSequentialAwaitsInOneChunk(): void
    {
        // The runtime batches both the invocation-id (completion 1) and the call result
        // (completion 2) into a single chunk. The handler awaits them in sequence; the
        // driver must run both awaits off that one chunk, never suspending.
        $transport = $this->drive(
            new CallOptionsService(),
            'CallOptionsService',
            'callAwaitIdThenResult',
            [
                (new JournalBuilder())->input('')->build(),
                (new JournalBuilder())->invocationIdCompletion(1, 'inv-xyz')->frames()
                    . (new JournalBuilder())->callCompletion(2, '"call-result"')->frames(),
            ],
        );

        $output = $transport->written();
        // One AwaitingOn for the first (parked) await; the second await finds its
        // completion already buffered and returns on the fast path without parking.
        self::assertSame([MessageType::CallCommand, MessageType::AwaitingOn, MessageType::OutputCommand, MessageType::End], $this->frameTypes($output));
        self::assertNotContains(MessageType::Suspension, $this->frameTypes($output));
        self::assertSame('"call-result"', $this->successValue($output));
        // Both completions rode in read #1; no read past that batch occurred.
        self::assertSame('', $transport->outputAtRead(2), 'the batched chunk resolved both awaits — no further read');
    }

    public function testAwakeableSignalOnOpenStreamResolvesParkedAwait(): void
    {
        // An awakeable emits no command; the handler parks on signal idx 17, which the
        // runtime resolves by streaming a SignalNotification on the open channel.
        $transport = $this->drive(
            new AwakeableService(),
            'AwakeableService',
            'awaitOne',
            [
                (new JournalBuilder())->input('')->build(),
                (new JournalBuilder())->awakeableSignal(17, '"resolved"')->frames(),
            ],
        );

        $output = $transport->written();
        // The awakeable park announces an AwaitingOn (it carries no command of its own),
        // which is exactly what lets the runtime push the resolving signal back.
        self::assertSame([MessageType::AwaitingOn, MessageType::OutputCommand, MessageType::End], $this->frameTypes($output));
        self::assertNotContains(MessageType::Suspension, $this->frameTypes($output));
        self::assertSame('"resolved"', $this->successValue($output));
    }

    public function testEofWhileParkedOnAwakeableSuspendsWaitingOnCancelAndSignal(): void
    {
        // No resolving signal arrives: parked on the awakeable (signal idx 17), an EOF
        // suspends. The await tree must declare the CANCEL signal (idx 1) at the outer
        // node and nest the awakeable signal (idx 17) — so the runtime re-invokes on
        // either the awakeable resolution or a cancel.
        $transport = $this->drive(
            new AwakeableService(),
            'AwakeableService',
            'awaitOne',
            [(new JournalBuilder())->input('')->build()],
        );

        // Parking first announced an AwaitingOn (await tree in field 1); the EOF then
        // wrote the Suspension (await tree in field 4). Both carry the same tree.
        $frames = MessageCodec::decodeAll($transport->written());
        self::assertSame([MessageType::AwaitingOn, MessageType::Suspension], \array_map(static fn ($f) => $f->type(), $frames));

        $awaitingOnReader = new Reader($frames[0]->payload);
        [$awaitingOnField] = $awaitingOnReader->readTag();
        self::assertSame(1, $awaitingOnField, 'the AwaitingOn carries its await tree in field 1');

        $reader = new Reader($frames[1]->payload);
        [$field] = $reader->readTag();
        self::assertSame(4, $field, 'the suspension carries its await tree in field 4');
        $outer = $this->decodeFuture($reader->readLengthDelimited());

        self::assertSame([1], $outer['signals'], 'the outer node waits on the CANCEL signal');
        self::assertCount(1, $outer['nested'], 'the awakeable await nests under the cancel guard');
        $inner = $this->decodeFuture($outer['nested'][0]);
        self::assertSame([17], $inner['signals'], 'the nested node waits on the awakeable signal idx 17');
        self::assertSame([], $inner['completions']);
    }

    private function frameOfType(string $output, MessageType $type): Frame
    {
        foreach (MessageCodec::decodeAll($output) as $frame) {
            if ($frame->type() === $type) {
                return $frame;
            }
        }
        self::fail("No {$type->name} frame in streamed output");
    }

    /**
     * Decodes a {@see \Qcodr\Restate\Sdk\Protocol\Message\Future} payload, returning its
     * leaf ids and the raw bytes of each nested future (mirrors the helper in
     * {@see \Qcodr\Restate\Sdk\Tests\Unit\Vm\StateMachineTest}).
     *
     * @return array{completions: list<int>, signals: list<int>, nested: list<string>}
     */
    private function decodeFuture(string $payload): array
    {
        $reader = new Reader($payload);
        $completions = [];
        $signals = [];
        $nested = [];
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1:
                    $completions = $this->unpackVarints($reader->readLengthDelimited());
                    break;
                case 2:
                    $signals = $this->unpackVarints($reader->readLengthDelimited());
                    break;
                case 4:
                    $nested[] = $reader->readLengthDelimited();
                    break;
                default:
                    $reader->skip($wire);
            }
        }

        return ['completions' => $completions, 'signals' => $signals, 'nested' => $nested];
    }

    /**
     * @return list<int>
     */
    private function unpackVarints(string $packed): array
    {
        $reader = new Reader($packed);
        $values = [];
        while (!$reader->atEnd()) {
            $values[] = $reader->readVarint();
        }

        return $values;
    }
}
