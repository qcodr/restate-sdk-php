<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Vm;

use PHPUnit\Framework\TestCase;
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
}
