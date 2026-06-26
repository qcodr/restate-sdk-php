<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Vm;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Error\CancelledException;
use Qcodr\Restate\Sdk\Protocol\Message\CompleteAwakeableCommand;
use Qcodr\Restate\Sdk\Protocol\Message\Failure;
use Qcodr\Restate\Sdk\Protocol\MessageHeader;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;
use Qcodr\Restate\Sdk\Protocol\ProtocolException;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;
use Qcodr\Restate\Sdk\Vm\StateMachine;
use Qcodr\Restate\Sdk\Vm\SuspendException;
use Qcodr\Restate\Sdk\Vm\VmState;

/**
 * Covers the state machine's error, lazy-state and lifecycle branches that the
 * happy-path tests in {@see StateMachineTest} and the context tests do not reach.
 */
final class StateMachineBranchesTest extends TestCase
{
    public function testProtocolVersionIsExposed(): void
    {
        self::assertSame(ServiceProtocolVersion::V7, (new StateMachine(ServiceProtocolVersion::V7))->protocolVersion());
    }

    // --- Parsing guards ---

    public function testRejectsTooManyKnownEntries(): void
    {
        $vm = new StateMachine(ServiceProtocolVersion::V7);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('known_entries exceeds the maximum');
        $vm->notifyInput($this->startFrame(100_001));
    }

    public function testRejectsJournalShorterThanKnownEntriesOnceClosed(): void
    {
        $vm = new StateMachine(ServiceProtocolVersion::V7);
        $vm->notifyInput($this->startFrame(1)); // promises one journal frame that never arrives

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Journal is shorter than known_entries');
        $vm->notifyInputClosed();
    }

    public function testNotReadyWhileJournalIsIncompleteAndStreamStillOpen(): void
    {
        $vm = new StateMachine(ServiceProtocolVersion::V7);
        $vm->notifyInput($this->startFrame(1)); // awaiting one more frame, stream not closed

        self::assertFalse($vm->isReadyToExecute());
    }

    public function testSysInputWithoutInputCommandFails(): void
    {
        // A StartMessage with zero known entries parses, but has no InputCommand.
        $vm = $this->machine((new JournalBuilder())->build());

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('no input command');
        $vm->sysInput();
    }

    public function testSyscallBeforeParsingFails(): void
    {
        $vm = new StateMachine(ServiceProtocolVersion::V7);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('not ready to execute');
        $vm->sysSleep(0);
    }

    // --- Lazy state (partial eager map) ---

    public function testLazyStateReadResolvesFromCompletion(): void
    {
        $journal = (new JournalBuilder(partialState: true))
            ->input('1')
            ->command(MessageType::GetLazyStateCommand)
            ->lazyStateCompletion(1, 'lazy-value')
            ->build();
        $vm = $this->machine($journal);
        $vm->sysInput();

        self::assertSame([true, 'lazy-value'], $vm->sysGetState('missing'));
    }

    public function testLazyStateKeysResolveFromCompletion(): void
    {
        $stateKeys = (new Writer())
            ->writeBytesPresent(1, 'k1')
            ->writeBytesPresent(1, 'k2')
            ->toString();
        $completion = (new Writer())
            ->writeUint32Present(1, 1)
            ->writeMessage(17, $stateKeys)
            ->toString();

        $journal = (new JournalBuilder(partialState: true))
            ->input('1')
            ->command(MessageType::GetLazyStateKeysCommand)
            ->command(MessageType::GetLazyStateKeysCompletion, $completion)
            ->build();
        $vm = $this->machine($journal);
        $vm->sysInput();

        self::assertSame(['k1', 'k2'], $vm->sysGetStateKeys());
    }

    // --- Completion / signal table guards ---

    public function testPeekCompletionThrowsWhenAbsent(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());

        $this->expectException(ProtocolException::class);
        $vm->peekCompletion(999);
    }

    public function testPeekSignalThrowsWhenAbsent(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());

        $this->expectException(ProtocolException::class);
        $vm->peekSignal(999);
    }

    public function testAwaitSignalRaisesCancelledWhenCancelSignalPresent(): void
    {
        $journal = (new JournalBuilder())->input('1')->cancelSignal()->build();
        $vm = $this->machine($journal);
        $vm->sysInput();

        $this->expectException(CancelledException::class);
        $vm->awaitSignal(99); // not ready, but the invocation was cancelled
    }

    public function testAwaitSignalSuspendsWhenNotReadyAndNotCancelled(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());
        $vm->sysInput();

        $this->expectException(SuspendException::class);
        $vm->awaitSignal(17);
    }

    // --- Lifecycle state() ---

    public function testStateIsWaitingPreFlightBeforeParsing(): void
    {
        self::assertSame(VmState::WaitingPreFlight, (new StateMachine(ServiceProtocolVersion::V7))->state());
    }

    public function testStateIsProcessingAfterConsumingTheJournal(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());
        $vm->sysInput();

        self::assertSame(VmState::Processing, $vm->state());
    }

    public function testStateIsClosedAfterEnd(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());
        $vm->sysInput();
        $vm->sysWriteOutputSuccess('"x"');
        $vm->sysEnd();

        self::assertSame(VmState::Closed, $vm->state());
    }

    // --- Parsing accumulation and framing ---

    public function testInputArrivingInChunksIsAccumulated(): void
    {
        $journal = (new JournalBuilder())->input('"hi"')->build();
        $head = \substr($journal, 0, -1);
        $tail = \substr($journal, -1);

        $vm = new StateMachine(ServiceProtocolVersion::V7);
        $vm->notifyInput($head);
        self::assertFalse($vm->isReadyToExecute(), 'not ready until the final byte of the journal arrives');

        $vm->notifyInput($tail);
        $vm->notifyInputClosed();
        self::assertTrue($vm->isReadyToExecute(), 'the two chunks are concatenated into a complete journal');
        self::assertSame('"hi"', $vm->sysInput()->body);
    }

    public function testRejectsNonStartFirstFrame(): void
    {
        $payload = (new Writer())->writeBytesPresent(1, 'x')->toString();
        $frame = (new MessageHeader(MessageType::InputCommand->value, \strlen($payload)))->encode() . $payload;
        $vm = new StateMachine(ServiceProtocolVersion::V7);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Expected StartMessage as the first frame');
        $vm->notifyInput($frame);
    }

    public function testKnownEntriesExactlyAtMaximumIsAccepted(): void
    {
        // The boundary value (MAX_KNOWN_ENTRIES) must pass the guard; only strictly
        // larger counts are rejected. The journal frames have not arrived, so the
        // machine is simply not-ready — never "exceeds the maximum".
        $vm = new StateMachine(ServiceProtocolVersion::V7);
        $vm->notifyInput($this->startFrame(100_000));

        self::assertFalse($vm->isReadyToExecute());
    }

    public function testControlFramesInTheJournalAreNotRoutedAsCompletions(): void
    {
        // A control frame (ProposeRunCompletionAck) inside the journal is ignored —
        // never decoded and routed as a notification — even though its bytes happen
        // to parse as a Notification carrying completion id 42.
        $controlPayload = (new Writer())->writeUint32Present(1, 42)->toString();
        $journal = (new JournalBuilder())
            ->input('1')
            ->command(MessageType::ProposeRunCompletionAck, $controlPayload)
            ->build();
        $vm = $this->machine($journal);

        self::assertTrue($vm->isReadyToExecute());
        self::assertFalse($vm->isCompletionReady(42), 'control frames are ignored, not routed into the completion table');
    }

    // --- Guard: sys* calls require a parsed journal ---

    public function testSysCallsBeforeParsingThrowNotReady(): void
    {
        $calls = [
            'sysInput' => static fn (StateMachine $vm) => $vm->sysInput(),
            'sysCall' => static fn (StateMachine $vm) => $vm->sysCall('Svc', 'h', '', 'x'),
            'sysOneWayCall' => static fn (StateMachine $vm) => $vm->sysOneWayCall('Svc', 'h', '', 'x'),
            'sysRun' => static fn (StateMachine $vm) => $vm->sysRun('step'),
            'sysGetPromise' => static fn (StateMachine $vm) => $vm->sysGetPromise('p'),
            'sysPeekPromise' => static fn (StateMachine $vm) => $vm->sysPeekPromise('p'),
            'sysResolvePromise' => static fn (StateMachine $vm) => $vm->sysResolvePromise('p', 'v'),
            'sysRejectPromise' => static fn (StateMachine $vm) => $vm->sysRejectPromise('p', new Failure(500, 'boom')),
            'sysCompleteAwakeable' => static fn (StateMachine $vm) => $vm->sysCompleteAwakeable(CompleteAwakeableCommand::resolve('prom_1abc', 'v')),
            'sysCancel' => static fn (StateMachine $vm) => $vm->sysCancel('inv-2'),
        ];

        foreach ($calls as $label => $call) {
            $vm = new StateMachine(ServiceProtocolVersion::V7);
            try {
                $call($vm);
                self::fail("{$label} must fail before the journal is parsed");
            } catch (ProtocolException $exception) {
                self::assertStringContainsString(
                    'not ready to execute',
                    $exception->getMessage(),
                    "{$label} should report that the state machine is not ready",
                );
            }
        }
    }

    // --- Publicly observable predicates ---

    public function testIsCancelledIsPubliclyAccessible(): void
    {
        $cancelled = $this->machine((new JournalBuilder())->input('1')->cancelSignal()->build());
        self::assertTrue($cancelled->isCancelled(), 'a CANCEL signal in the journal marks the invocation cancelled');

        $live = $this->machine((new JournalBuilder())->input('1')->build());
        self::assertFalse($live->isCancelled());
    }

    public function testIsProcessingIsPubliclyAccessible(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());

        self::assertFalse($vm->isProcessing(), 'still replaying the input command');
        $vm->sysInput();
        self::assertTrue($vm->isProcessing(), 'past the journal: now processing fresh commands');
    }

    // --- Helpers ---

    private function machine(string $journal): StateMachine
    {
        $vm = new StateMachine(ServiceProtocolVersion::V7);
        $vm->notifyInput($journal);
        $vm->notifyInputClosed();

        return $vm;
    }

    /** A bare StartMessage frame promising $knownEntries journal frames. */
    private function startFrame(int $knownEntries): string
    {
        $payload = (new Writer())
            ->writeBytesPresent(1, 'inv-1')
            ->writeUint32(3, $knownEntries)
            ->toString();

        return (new MessageHeader(MessageType::Start->value, \strlen($payload)))->encode() . $payload;
    }
}
