<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Vm;

use Fiber;
use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\Message\CombinatorType;
use Qcodr\Restate\Sdk\Protocol\Message\Notification;
use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;
use Qcodr\Restate\Sdk\Tests\Support\RecordingOutputSink;
use Qcodr\Restate\Sdk\Vm\FiberSuspender;
use Qcodr\Restate\Sdk\Vm\ParkSignal;
use Qcodr\Restate\Sdk\Vm\StateMachine;
use Qcodr\Restate\Sdk\Vm\SuspendException;

/**
 * Named-signal receive path: routing a signal_name notification into the named-signal
 * table, and the await/peek/ready accessors mirroring the awakeable (indexed) signal
 * machinery — across both the request/response and streaming transports.
 */
final class NamedSignalStateMachineTest extends TestCase
{
    /** Built-in CANCEL signal index the suspension await tree always guards against. */
    private const CANCEL_SIGNAL_ID = 1;

    private function machine(string $journal): StateMachine
    {
        $vm = new StateMachine(ServiceProtocolVersion::V7);
        $vm->notifyInput($journal);
        $vm->notifyInputClosed();
        self::assertTrue($vm->isReadyToExecute());

        return $vm;
    }

    public function testNamedSignalNotificationInJournalResolvesAwaitWithoutSuspending(): void
    {
        $journal = (new JournalBuilder())
            ->input('1')
            ->namedSignal('ready', '"value"')
            ->build();
        $vm = $this->machine($journal);
        $vm->sysInput();

        self::assertTrue($vm->isNamedSignalReady('ready'));
        self::assertFalse($vm->isNamedSignalReady('absent'));

        $notification = $vm->awaitNamedSignal('ready'); // returns without suspending
        self::assertSame('value', \json_decode($notification->value ?? '', true));
        self::assertSame('ready', $notification->signalName);
        self::assertSame('"value"', $vm->peekNamedSignal('ready')->value);

        // A resolved named signal is keyed by name, never by an index in the signals table.
        self::assertSame([], $this->outputTypes($vm), 'a replayed named signal emits nothing');
    }

    public function testUnresolvedNamedSignalAwaitSuspendsWithNamedSignalAwaitTree(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());
        $vm->sysInput();

        try {
            $vm->awaitNamedSignal('pending');
            self::fail('expected suspension on an unresolved named signal');
        } catch (SuspendException) {
            // expected
        }

        $frames = MessageCodec::decodeAll($vm->takeOutput());
        self::assertSame([MessageType::Suspension], \array_map(static fn ($f) => $f->type(), $frames));

        // The await tree flattens the named signal next to the CANCEL signal under a
        // FirstCompleted node (the canonical single-await shape), exactly like an awakeable.
        $tree = $this->decodeSuspensionFuture($frames[0]->payload);
        self::assertSame(['pending'], $tree['named'], 'the awaited named signal sits on the cancel-guarded node');
        self::assertSame([self::CANCEL_SIGNAL_ID], $tree['signals'], 'the suspension also waits on the CANCEL signal');
        self::assertSame([], $tree['completions']);
        self::assertSame([], $tree['nested'], 'a single await is flattened, not nested');
        self::assertSame(CombinatorType::FirstCompleted->value, $tree['combinator']);
    }

    public function testRejectedNamedSignalRaisesTheCarriedFailureOnAwait(): void
    {
        $journal = (new JournalBuilder())
            ->input('1')
            ->failedNamedSignal('boom', 'denied', 409)
            ->build();
        $vm = $this->machine($journal);
        $vm->sysInput();

        $notification = $vm->awaitNamedSignal('boom');
        self::assertNotNull($notification->failure);
        self::assertSame(409, $notification->failure->code);
        self::assertSame('denied', $notification->failure->message);
    }

    public function testParkedNamedSignalAwaitResumesWhenSignalStreamedIn(): void
    {
        $sink = new RecordingOutputSink();
        $vm = new StateMachine(ServiceProtocolVersion::V7, new FiberSuspender(), $sink);
        $vm->notifyInput((new JournalBuilder())->input('1')->build());
        self::assertTrue($vm->isReadyToExecute());
        $vm->sysInput();

        $result = null;
        $fiber = new Fiber(static function () use ($vm, &$result): void {
            $result = $vm->awaitNamedSignal('streamed');
        });

        $park = $fiber->start();
        self::assertTrue($fiber->isSuspended(), 'an unresolved named-signal await parks the fiber');
        self::assertInstanceOf(ParkSignal::class, $park);
        self::assertSame(['streamed'], $park->awaitTree->waitingNamedSignals);
        self::assertSame([self::CANCEL_SIGNAL_ID], $park->awaitTree->waitingSignals);

        // The driver streams the named signal in (as the runtime would) and resumes.
        $vm->notifyInput((new JournalBuilder())->namedSignal('streamed', '"hi"')->frames());
        $fiber->resume();

        self::assertTrue($fiber->isTerminated(), 'the fiber finished once the named signal arrived');
        self::assertInstanceOf(Notification::class, $result);
        self::assertSame('"hi"', $result->value);
        self::assertSame('streamed', $result->signalName);

        // Parking announced the await tree (AwaitingOn) but wrote no suspension frame.
        self::assertSame([MessageType::AwaitingOn], $sink->frameTypes());
    }

    /** @return list<MessageType|null> */
    private function outputTypes(StateMachine $vm): array
    {
        return \array_map(
            static fn ($frame) => $frame->type(),
            MessageCodec::decodeAll($vm->takeOutput()),
        );
    }

    /**
     * Decodes the await-point {@see \Qcodr\Restate\Sdk\Protocol\Message\Future} carried in
     * a suspension frame (field 4), capturing its named-signal leaves (field 3) too.
     *
     * @return array{completions: list<int>, signals: list<int>, named: list<string>, nested: list<string>, combinator: int}
     */
    private function decodeSuspensionFuture(string $suspensionPayload): array
    {
        $reader = new Reader($suspensionPayload);
        [$field] = $reader->readTag();
        self::assertSame(4, $field, 'the suspension carries the await tree in field 4');

        $tree = new Reader($reader->readLengthDelimited());
        $completions = [];
        $signals = [];
        $named = [];
        $nested = [];
        $combinator = 0;
        while (!$tree->atEnd()) {
            [$treeField, $wire] = $tree->readTag();
            switch ($treeField) {
                case 1:
                    $completions = $this->unpackVarints($tree->readLengthDelimited());
                    break;
                case 2:
                    $signals = $this->unpackVarints($tree->readLengthDelimited());
                    break;
                case 3:
                    $named[] = $tree->readLengthDelimited();
                    break;
                case 4:
                    $nested[] = $tree->readLengthDelimited();
                    break;
                case 5:
                    $combinator = $tree->readVarint();
                    break;
                default:
                    $tree->skip($wire);
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
