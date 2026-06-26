<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Vm;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Error\CancelledException;
use Restate\Sdk\Protocol\MessageHeader;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Writer;
use Restate\Sdk\Protocol\ProtocolException;
use Restate\Sdk\Protocol\ServiceProtocolVersion;
use Restate\Sdk\Tests\Support\JournalBuilder;
use Restate\Sdk\Vm\StateMachine;
use Restate\Sdk\Vm\SuspendException;
use Restate\Sdk\Vm\VmState;

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
