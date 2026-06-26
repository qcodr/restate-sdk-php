<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Vm;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Protocol\Message\StateKeys;
use Restate\Sdk\Protocol\Message\Value;
use Restate\Sdk\Protocol\MessageCodec;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Reader;
use Restate\Sdk\Protocol\ServiceProtocolVersion;
use Restate\Sdk\Tests\Support\JournalBuilder;
use Restate\Sdk\Vm\StateMachine;
use Restate\Sdk\Vm\SuspendException;

final class StateMachineTest extends TestCase
{
    private function machine(string $journal): StateMachine
    {
        $vm = new StateMachine(ServiceProtocolVersion::V7);
        $vm->notifyInput($journal);
        $vm->notifyInputClosed();
        self::assertTrue($vm->isReadyToExecute());

        return $vm;
    }

    /** @return list<MessageType|null> */
    private function outputTypes(StateMachine $vm): array
    {
        return \array_map(
            static fn ($frame) => $frame->type(),
            MessageCodec::decodeAll($vm->takeOutput()),
        );
    }

    private function firstValueContent(string $output): string
    {
        $frames = MessageCodec::decodeAll($output);
        $reader = new Reader($frames[0]->payload);
        [$field] = $reader->readTag();
        self::assertSame(14, $field);

        return Value::decode($reader->readLengthDelimited())->content;
    }

    public function testServiceInputOutputRoundTrip(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('"world"')->build());

        $input = $vm->sysInput();
        self::assertSame('"world"', $input->body);
        self::assertSame('', $input->key);

        $vm->sysWriteOutputSuccess('"Greetings world"');
        $vm->sysEnd();

        $output = $vm->takeOutput();
        self::assertSame('"Greetings world"', $this->firstValueContent($output));
        self::assertSame(
            [MessageType::OutputCommand, MessageType::End],
            \array_map(static fn ($f) => $f->type(), MessageCodec::decodeAll($output)),
        );
    }

    public function testEagerStateReadAndWrite(): void
    {
        $journal = (new JournalBuilder(stateMap: ['count' => '5']))->input('1')->build();
        $vm = $this->machine($journal);
        $vm->sysInput();

        self::assertSame([true, '5'], $vm->sysGetState('count'));
        $vm->sysSetState('count', '6');
        self::assertSame([true, '6'], $vm->sysGetState('count'), 'local view reflects the write');

        $vm->sysWriteOutputSuccess('"6"');
        $vm->sysEnd();

        self::assertSame([
            MessageType::GetEagerStateCommand,
            MessageType::SetStateCommand,
            MessageType::GetEagerStateCommand,
            MessageType::OutputCommand,
            MessageType::End,
        ], $this->outputTypes($vm));
    }

    public function testAbsentStateOnFullMapReturnsNull(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());
        $vm->sysInput();

        self::assertSame([false, null], $vm->sysGetState('missing'));
        self::assertSame([MessageType::GetEagerStateCommand], $this->outputTypes($vm));
    }

    public function testSleepSuspendsWhenNotYetCompleted(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());
        $vm->sysInput();

        $completionId = $vm->sysSleep(1_700_000_000_000);

        try {
            $vm->awaitCompletion($completionId);
            self::fail('Expected suspension');
        } catch (SuspendException) {
            // expected
        }

        self::assertSame(
            [MessageType::SleepCommand, MessageType::Suspension],
            $this->outputTypes($vm),
        );
    }

    public function testSleepReplayDoesNotResendCommand(): void
    {
        $journal = (new JournalBuilder())
            ->input('1')
            ->command(MessageType::SleepCommand)
            ->sleepCompletion(1)
            ->build();
        $vm = $this->machine($journal);
        $vm->sysInput();

        $completionId = $vm->sysSleep(1_700_000_000_000);
        self::assertTrue($vm->isCompletionReady($completionId));
        $vm->awaitCompletion($completionId); // returns without suspending

        $vm->sysWriteOutputSuccess('"done"');
        $vm->sysEnd();

        self::assertSame(
            [MessageType::OutputCommand, MessageType::End],
            $this->outputTypes($vm),
            'replayed SleepCommand must not be re-sent',
        );
    }

    public function testCallReplayReturnsCompletionValue(): void
    {
        // Call allocates two completion ids (1 = invocation id, 2 = result).
        $journal = (new JournalBuilder())
            ->input('1')
            ->command(MessageType::CallCommand)
            ->callCompletion(2, '"hello"')
            ->build();
        $vm = $this->machine($journal);
        $vm->sysInput();

        [$invocationIdCompletionId, $resultCompletionId] = $vm->sysCall('Svc', 'h', '', '"x"');
        self::assertSame([1, 2], [$invocationIdCompletionId, $resultCompletionId]);

        $notification = $vm->awaitCompletion($resultCompletionId);
        self::assertSame('"hello"', $notification->value);
    }

    public function testRunExecutesProposesAndSuspendsWhenProcessing(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());
        $vm->sysInput();

        $completionId = $vm->sysRun('step');
        self::assertFalse($vm->isCompletionReady($completionId));
        $vm->proposeRunCompletionSuccess($completionId, '"v"');

        try {
            $vm->awaitCompletion($completionId);
            self::fail('Expected suspension after proposing run completion');
        } catch (SuspendException) {
            // expected
        }

        $frames = MessageCodec::decodeAll($vm->takeOutput());
        self::assertSame([
            MessageType::RunCommand,
            MessageType::ProposeRunCompletion,
            MessageType::Suspension,
        ], \array_map(static fn ($f) => $f->type(), $frames));
        self::assertTrue($frames[1]->requestedAck, 'ProposeRunCompletion sets the ACK flag');
    }

    public function testRunReplayReturnsStoredResultWithoutResending(): void
    {
        $journal = (new JournalBuilder())
            ->input('1')
            ->command(MessageType::RunCommand)
            ->runCompletion(1, '"stored"')
            ->build();
        $vm = $this->machine($journal);
        $vm->sysInput();

        $completionId = $vm->sysRun('step');
        self::assertTrue($vm->isCompletionReady($completionId));
        self::assertSame('"stored"', $vm->awaitCompletion($completionId)->value);

        self::assertSame([], $this->outputTypes($vm), 'replayed run emits nothing');
    }

    public function testJournalMismatchOnNonDeterministicReplayEmitsError(): void
    {
        // The journal records a Sleep at command index 1; the handler instead issues
        // a Call there (non-deterministic code) -> JOURNAL_MISMATCH (570), not silent
        // corruption.
        $journal = (new JournalBuilder())
            ->input('1')
            ->command(MessageType::SleepCommand)
            ->sleepCompletion(1)
            ->build();
        $vm = $this->machine($journal);
        $vm->sysInput();

        try {
            $vm->sysCall('Svc', 'h', '', 'x');
            self::fail('Expected a journal-mismatch suspension');
        } catch (SuspendException) {
            // expected
        }

        $frames = MessageCodec::decodeAll($vm->takeOutput());
        self::assertSame(MessageType::Error, $frames[0]->type());

        $reader = new Reader($frames[0]->payload);
        [$field] = $reader->readTag();
        self::assertSame(1, $field);
        self::assertSame(570, $reader->readVarint(), 'ErrorMessage code is JOURNAL_MISMATCH');
    }

    public function testEagerStateFoundCommandCarriesTheValue(): void
    {
        $journal = (new JournalBuilder(stateMap: ['count' => '5']))->input('1')->build();
        $vm = $this->machine($journal);
        $vm->sysInput();

        self::assertSame([true, '5'], $vm->sysGetState('count'));

        $frames = MessageCodec::decodeAll($vm->takeOutput());
        self::assertSame(
            [MessageType::GetEagerStateCommand],
            \array_map(static fn ($f) => $f->type(), $frames),
        );

        [$key, $value, $void] = $this->decodeEagerStateCommand($frames[0]->payload);
        self::assertSame('count', $key);
        self::assertFalse($void, 'a found key is encoded with a value, not a void marker');
        self::assertSame('5', $value);
    }

    public function testEagerStateAbsentCommandIsEncodedAsVoid(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());
        $vm->sysInput();

        self::assertSame([false, null], $vm->sysGetState('missing'));

        $frames = MessageCodec::decodeAll($vm->takeOutput());
        [$key, $value, $void] = $this->decodeEagerStateCommand($frames[0]->payload);
        self::assertSame('missing', $key);
        self::assertTrue($void, 'an absent key is encoded as void, never as an empty value');
        self::assertSame('', $value);
    }

    public function testEagerStateKeysCommandIsEmittedWithTheKeys(): void
    {
        $journal = (new JournalBuilder(stateMap: ['a' => '1', 'b' => '2']))->input('1')->build();
        $vm = $this->machine($journal);
        $vm->sysInput();

        self::assertSame(['a', 'b'], $vm->sysGetStateKeys());

        $frames = MessageCodec::decodeAll($vm->takeOutput());
        self::assertSame(
            [MessageType::GetEagerStateKeysCommand],
            \array_map(static fn ($f) => $f->type(), $frames),
        );

        $reader = new Reader($frames[0]->payload);
        [$field] = $reader->readTag();
        self::assertSame(14, $field, 'state keys are carried in field 14');
        self::assertSame(['a', 'b'], StateKeys::decode($reader->readLengthDelimited())->keys);
    }

    public function testLazyStateReadEmitsCommandWithKeyAndCompletionIdThenSuspends(): void
    {
        $vm = $this->machine((new JournalBuilder(partialState: true))->input('1')->build());
        $vm->sysInput();

        try {
            $vm->sysGetState('missing');
            self::fail('Expected suspension on an unresolved lazy read');
        } catch (SuspendException) {
            // expected
        }

        $frames = MessageCodec::decodeAll($vm->takeOutput());
        self::assertSame(
            [MessageType::GetLazyStateCommand, MessageType::Suspension],
            \array_map(static fn ($f) => $f->type(), $frames),
        );

        $reader = new Reader($frames[0]->payload);
        [$keyField] = $reader->readTag();
        self::assertSame(1, $keyField);
        self::assertSame('missing', $reader->readLengthDelimited());
        [$idField] = $reader->readTag();
        self::assertSame(11, $idField, 'completion id is carried in field 11');
        self::assertSame(1, $reader->readVarint(), 'first allocated completion id');
    }

    public function testLazyStateKeysEmitsCommandWithCompletionIdThenSuspends(): void
    {
        $vm = $this->machine((new JournalBuilder(partialState: true))->input('1')->build());
        $vm->sysInput();

        try {
            $vm->sysGetStateKeys();
            self::fail('Expected suspension on an unresolved lazy state-keys read');
        } catch (SuspendException) {
            // expected
        }

        $frames = MessageCodec::decodeAll($vm->takeOutput());
        self::assertSame(
            [MessageType::GetLazyStateKeysCommand, MessageType::Suspension],
            \array_map(static fn ($f) => $f->type(), $frames),
        );

        $reader = new Reader($frames[0]->payload);
        [$idField] = $reader->readTag();
        self::assertSame(11, $idField);
        self::assertSame(1, $reader->readVarint());
    }

    public function testClearStateRemovesKeyFromTheLocalView(): void
    {
        $journal = (new JournalBuilder(stateMap: ['count' => '5']))->input('1')->build();
        $vm = $this->machine($journal);
        $vm->sysInput();

        self::assertSame([true, '5'], $vm->sysGetState('count'));
        $vm->sysClearState('count');
        self::assertSame([false, null], $vm->sysGetState('count'), 'a cleared key reads as absent locally');
    }

    public function testClearAllStateEmptiesTheLocalView(): void
    {
        $journal = (new JournalBuilder(stateMap: ['a' => '1', 'b' => '2']))->input('1')->build();
        $vm = $this->machine($journal);
        $vm->sysInput();

        $vm->sysClearAllState();
        self::assertSame([], $vm->sysGetStateKeys(), 'every key is gone after clearAll');
        self::assertSame([false, null], $vm->sysGetState('a'));
    }

    public function testOneWayCallDefaultsToImmediateInvokeTime(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());
        $vm->sysInput();

        $invocationIdCompletionId = $vm->sysOneWayCall('Svc', 'handler', 'k', '"p"');
        self::assertSame(1, $invocationIdCompletionId);

        $frames = MessageCodec::decodeAll($vm->takeOutput());
        self::assertSame(
            [MessageType::OneWayCallCommand],
            \array_map(static fn ($f) => $f->type(), $frames),
        );
        self::assertSame(0, $this->oneWayCallInvokeTime($frames[0]->payload), 'default invokeTimeMillis is 0 (as soon as possible)');
    }

    public function testGetPromiseEmitsCommandWithKeyAndReturnsCompletionId(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());
        $vm->sysInput();

        $completionId = $vm->sysGetPromise('my-promise');
        self::assertSame(1, $completionId);

        $frames = MessageCodec::decodeAll($vm->takeOutput());
        self::assertSame(
            [MessageType::GetPromiseCommand],
            \array_map(static fn ($f) => $f->type(), $frames),
        );

        $reader = new Reader($frames[0]->payload);
        [$keyField] = $reader->readTag();
        self::assertSame(1, $keyField);
        self::assertSame('my-promise', $reader->readLengthDelimited());
        [$idField] = $reader->readTag();
        self::assertSame(11, $idField);
        self::assertSame(1, $reader->readVarint());
    }

    public function testPeekPromiseEmitsCommandWithKeyAndReturnsCompletionId(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());
        $vm->sysInput();

        $completionId = $vm->sysPeekPromise('peek-me');
        self::assertSame(1, $completionId);

        $frames = MessageCodec::decodeAll($vm->takeOutput());
        self::assertSame(
            [MessageType::PeekPromiseCommand],
            \array_map(static fn ($f) => $f->type(), $frames),
        );

        $reader = new Reader($frames[0]->payload);
        [$keyField] = $reader->readTag();
        self::assertSame(1, $keyField);
        self::assertSame('peek-me', $reader->readLengthDelimited());
        [$idField] = $reader->readTag();
        self::assertSame(11, $idField);
        self::assertSame(1, $reader->readVarint());
    }

    public function testCreateAwakeableAllocatesSequentialSignalIds(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());
        $vm->sysInput();

        [$firstId, $firstSignal] = $vm->createAwakeable();
        [$secondId, $secondSignal] = $vm->createAwakeable();

        self::assertSame(17, $firstSignal, 'user signal ids start at 17');
        self::assertSame(18, $secondSignal, 'signal ids increase, they do not decrease');
        self::assertNotSame($firstId, $secondId, 'each awakeable gets a distinct public id');
    }

    public function testSuspensionAwaitsBothTheResultAndTheCancelSignal(): void
    {
        $vm = $this->machine((new JournalBuilder())->input('1')->build());
        $vm->sysInput();

        $completionId = $vm->sysSleep(1_000);

        try {
            $vm->awaitCompletion($completionId);
            self::fail('Expected suspension');
        } catch (SuspendException) {
            // expected
        }

        $frames = MessageCodec::decodeAll($vm->takeOutput());
        self::assertSame(
            [MessageType::SleepCommand, MessageType::Suspension],
            \array_map(static fn ($f) => $f->type(), $frames),
        );

        // SuspensionMessage carries the await-point Future tree in field 4.
        $reader = new Reader($frames[1]->payload);
        [$field] = $reader->readTag();
        self::assertSame(4, $field);
        $outer = $this->decodeFuture($reader->readLengthDelimited());

        // The outer node waits on the built-in CANCEL signal (idx 1) so a cancel wakes it...
        self::assertSame([1], $outer['signals'], 'the suspension also waits on the CANCEL signal');
        // ...and nests the actual awaited completion.
        self::assertCount(1, $outer['nested'], 'the real await point is nested under the cancel guard');
        $inner = $this->decodeFuture($outer['nested'][0]);
        self::assertSame([$completionId], $inner['completions']);
    }

    /**
     * Decodes a {@see \Restate\Sdk\Protocol\Message\GetEagerStateCommand} payload.
     *
     * @return array{0: string, 1: string, 2: bool} [key, value, void]
     */
    private function decodeEagerStateCommand(string $payload): array
    {
        $reader = new Reader($payload);
        $key = '';
        $value = '';
        $void = false;
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            if ($field === 1) {
                $key = $reader->readLengthDelimited();
            } elseif ($field === 14) {
                $value = Value::decode($reader->readLengthDelimited())->content;
            } elseif ($field === 13) {
                $reader->readLengthDelimited();
                $void = true;
            } else {
                $reader->skip($wire);
            }
        }

        return [$key, $value, $void];
    }

    private function oneWayCallInvokeTime(string $payload): int
    {
        $reader = new Reader($payload);
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            if ($field === 4) {
                return $reader->readVarint();
            }
            $reader->skip($wire);
        }

        return 0;
    }

    /**
     * Decodes a {@see \Restate\Sdk\Protocol\Message\Future} payload, returning its
     * leaf ids and the raw bytes of each nested future.
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
