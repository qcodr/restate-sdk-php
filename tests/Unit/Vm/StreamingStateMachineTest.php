<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Vm;

use Fiber;
use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\Message\CombinatorType;
use Qcodr\Restate\Sdk\Protocol\Message\Notification;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;
use Qcodr\Restate\Sdk\Tests\Support\RecordingOutputSink;
use Qcodr\Restate\Sdk\Vm\FiberSuspender;
use Qcodr\Restate\Sdk\Vm\ParkSignal;
use Qcodr\Restate\Sdk\Vm\StateMachine;

/**
 * Proves the bidirectional-streaming behavior of the state machine using a purely
 * programmatic fiber driver (no network): with a {@see FiberSuspender} an unresolved
 * await PARKS the running fiber instead of writing a `SuspensionMessage`, and once a
 * completion is streamed in and the fiber is resumed, the awaited value is returned.
 *
 * The request/response parity of the same machine lives in {@see StateMachineTest}
 * (the default {@see \Qcodr\Restate\Sdk\Vm\ThrowingSuspender} + buffering sink).
 */
final class StreamingStateMachineTest extends TestCase
{
    /** Built-in CANCEL signal index the suspension await tree always guards against. */
    private const CANCEL_SIGNAL_ID = 1;

    private function streamingMachine(RecordingOutputSink $sink): StateMachine
    {
        $vm = new StateMachine(ServiceProtocolVersion::V7, new FiberSuspender(), $sink);
        $vm->notifyInput((new JournalBuilder())->input('1')->build());
        self::assertTrue($vm->isReadyToExecute());
        $vm->sysInput();

        return $vm;
    }

    public function testUnresolvedAwaitParksWithoutEmittingSuspension(): void
    {
        $sink = new RecordingOutputSink();
        $vm = $this->streamingMachine($sink);

        // Call allocates ids 1 (invocation id) and 2 (result); the result is unresolved.
        [, $resultId] = $vm->sysCall('Svc', 'h', '', '"x"');

        $result = null;
        $fiber = new Fiber(static function () use ($vm, $resultId, &$result): void {
            $result = $vm->awaitCompletion($resultId);
        });

        $park = $fiber->start();

        self::assertTrue($fiber->isSuspended(), 'an unresolved await parks the fiber');
        self::assertNull($result, 'the await has not returned a value while parked');

        // The fiber yields a ParkSignal carrying the cancel-guarded await tree (not a
        // SuspensionMessage) plus the predicate the driver evaluates before resuming.
        self::assertInstanceOf(ParkSignal::class, $park);
        self::assertFalse(($park->isResolved)(), 'the await is unresolved while the completion is absent');
        $awaitTree = $park->awaitTree;
        self::assertSame([self::CANCEL_SIGNAL_ID], $awaitTree->waitingSignals);
        self::assertSame(CombinatorType::FirstCompleted, $awaitTree->combinatorType);
        self::assertCount(1, $awaitTree->nestedFutures);
        self::assertSame([$resultId], $awaitTree->nestedFutures[0]->waitingCompletions);

        // Only the CallCommand was emitted: no suspension frame is written while parked.
        self::assertSame([MessageType::CallCommand], $sink->frameTypes());
        self::assertNotContains(MessageType::Suspension, $sink->frameTypes());

        // A non-buffering sink has nothing to drain via the legacy takeOutput() path.
        self::assertSame('', $vm->takeOutput());
    }

    public function testParkedAwaitResumesWithValueWhenCompletionStreamedIn(): void
    {
        $sink = new RecordingOutputSink();
        $vm = $this->streamingMachine($sink);

        [, $resultId] = $vm->sysCall('Svc', 'h', '', '"x"');

        $result = null;
        $fiber = new Fiber(static function () use ($vm, $resultId, &$result): void {
            $result = $vm->awaitCompletion($resultId);
        });
        $fiber->start();
        self::assertTrue($fiber->isSuspended());

        // The driver streams the result completion in (as the runtime would) and resumes.
        $vm->notifyInput((new JournalBuilder())->callCompletion($resultId, '"hello"')->frames());
        $fiber->resume();

        self::assertTrue($fiber->isTerminated(), 'the fiber finished once the value was available');
        self::assertInstanceOf(Notification::class, $result);
        self::assertSame('"hello"', $result->value);
        self::assertSame($resultId, $result->completionId);

        // The park/resume round trip never wrote a suspension frame.
        self::assertSame([MessageType::CallCommand], $sink->frameTypes());
    }

    public function testCombinatorParksOnFirstCompletedTreeInStreaming(): void
    {
        $sink = new RecordingOutputSink();
        $vm = $this->streamingMachine($sink);

        // Two unresolved results raced via the lowest-level combinator entry point. The
        // predicate is supplied by the caller; here it is never invoked because the fiber
        // is resumed directly (no driver), so a trivial always-true closure suffices.
        $finished = false;
        $fiber = new Fiber(static function () use ($vm, &$finished): void {
            $vm->suspendAny([2, 4], [], static fn (): bool => true);
            $finished = true;
        });

        $park = $fiber->start();

        self::assertTrue($fiber->isSuspended(), 'the combinator parked rather than returning');
        self::assertInstanceOf(ParkSignal::class, $park);
        $awaitTree = $park->awaitTree;
        self::assertSame([self::CANCEL_SIGNAL_ID], $awaitTree->waitingSignals);
        self::assertCount(1, $awaitTree->nestedFutures);
        self::assertSame([2, 4], $awaitTree->nestedFutures[0]->waitingCompletions);
        self::assertSame(CombinatorType::FirstCompleted, $awaitTree->nestedFutures[0]->combinatorType);

        // Resuming returns control from the void combinator (streaming park returns).
        $fiber->resume();
        self::assertTrue($fiber->isTerminated());
        self::assertTrue($finished, 'the combinator returned after the fiber was resumed');
        self::assertNotContains(MessageType::Suspension, $sink->frameTypes());
    }
}
