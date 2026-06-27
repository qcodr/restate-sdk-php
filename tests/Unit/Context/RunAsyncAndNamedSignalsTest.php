<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qcodr\Restate\Sdk\Context\ContextRand;
use Qcodr\Restate\Sdk\Context\DurableFuture;
use Qcodr\Restate\Sdk\Context\RestateContext;
use Qcodr\Restate\Sdk\Context\SystemClock;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Protocol\Frame;
use Qcodr\Restate\Sdk\Protocol\Message\Failure;
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

/**
 * Covers the async run future ({@see RestateContext::runAsync}) and the named-signal
 * send/receive surface ({@see RestateContext::createSignal} / {@see RestateContext::resolveSignal}
 * / {@see RestateContext::rejectSignal}), including that a named-signal future is carried
 * into a combinator's `waitingNamedSignals` bucket.
 */
final class RunAsyncAndNamedSignalsTest extends TestCase
{
    private const CANCEL_SIGNAL_ID = 1;

    // --- runAsync() ---

    public function testRunAsyncReplayReturnsStoredResultWithoutInvokingAction(): void
    {
        [, $ctx] = $this->build(
            (new JournalBuilder())
                ->input('1')
                ->command(MessageType::RunCommand)
                ->runCompletion(1, '"stored"'),
        );

        $invoked = false;
        $future = $ctx->runAsync('step', static function () use (&$invoked): string {
            $invoked = true;

            return 'fresh';
        });

        self::assertInstanceOf(DurableFuture::class, $future);
        self::assertSame('stored', $future->await());
        self::assertFalse($invoked, 'a replayed run must not re-execute its action');
    }

    public function testRunAsyncLiveProposesSuccessAndReturnsAFutureWithoutSuspending(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        $future = $ctx->runAsync('step', static fn (): string => 'value');

        self::assertInstanceOf(DurableFuture::class, $future);

        // runAsync proposes but does NOT await: no suspension is emitted by it.
        $frames = $this->frames($vm);
        self::assertSame(
            [MessageType::RunCommand, MessageType::ProposeRunCompletion],
            $this->typesOf($frames),
        );

        $proposal = self::fields(self::frameOfType($frames, MessageType::ProposeRunCompletion)->payload);
        self::assertSame('"value"', $proposal[14]);
        self::assertArrayNotHasKey(15, $proposal, 'a success proposal carries no failure');
    }

    public function testRunAsyncWithTerminalExceptionProposesFailureWithoutSuspending(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        $ctx->runAsync('step', static fn (): mixed => throw new TerminalException('boom', 418));

        $frames = $this->frames($vm);
        self::assertSame(
            [MessageType::RunCommand, MessageType::ProposeRunCompletion],
            $this->typesOf($frames),
        );

        $proposal = self::fields(self::frameOfType($frames, MessageType::ProposeRunCompletion)->payload);
        $failureBytes = $proposal[15];
        self::assertIsString($failureBytes);
        $failure = Failure::decode($failureBytes);
        self::assertSame(418, $failure->code);
        self::assertSame('boom', $failure->message);
    }

    // --- createSignal() (receive) ---

    public function testCreateSignalReturnsANamedSignalFuture(): void
    {
        [, $ctx] = $this->build((new JournalBuilder())->input('1'));

        $future = $ctx->createSignal('my-signal');

        self::assertTrue($future->isNamedSignal());
        self::assertSame('my-signal', $future->signalName());
    }

    public function testCreateSignalReplayResolvesToDeserializedValue(): void
    {
        [, $ctx] = $this->build(
            (new JournalBuilder())->input('1')->namedSignal('my-signal', '"hello"'),
        );

        self::assertSame('hello', $ctx->createSignal('my-signal')->await());
    }

    public function testCreateSignalSuspendsWhenTheNamedSignalIsAbsent(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        try {
            $ctx->createSignal('pending')->await();
            self::fail('expected suspension on an unresolved named signal');
        } catch (SuspendException) {
            // expected
        }

        // The await tree waits on the named signal (flattened next to the CANCEL signal),
        // never on a completion or signal index.
        $tree = self::awaitTree($this->frames($vm));
        self::assertSame(['pending'], $tree['named']);
        self::assertSame([self::CANCEL_SIGNAL_ID], $tree['signals']);
        self::assertSame([], $tree['completions']);
    }

    // --- resolveSignal() / rejectSignal() (send) ---

    public function testResolveSignalEmitsSendSignalCommandWithNameAndValue(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        $ctx->resolveSignal('inv-target', 'my-signal', 'val');

        $frames = $this->frames($vm);
        self::assertSame([MessageType::SendSignalCommand], $this->typesOf($frames));

        $signal = self::fields($frames[0]->payload);
        self::assertSame('inv-target', $signal[1], 'target invocation id in field 1');
        self::assertSame('my-signal', $signal[3], 'signal name in field 3');
        self::assertArrayNotHasKey(2, $signal, 'a named signal must not emit the built-in idx');

        $valueBytes = $signal[5];
        self::assertIsString($valueBytes);
        self::assertSame('"val"', Value::decode($valueBytes)->content);
    }

    public function testRejectSignalEmitsSendSignalCommandWithNameAndFailure(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));

        $ctx->rejectSignal('inv-target', 'my-signal', 'because');

        $frames = $this->frames($vm);
        self::assertSame([MessageType::SendSignalCommand], $this->typesOf($frames));

        $signal = self::fields($frames[0]->payload);
        self::assertSame('inv-target', $signal[1]);
        self::assertSame('my-signal', $signal[3]);
        self::assertArrayNotHasKey(5, $signal, 'a reject carries no value');

        $failureBytes = $signal[6];
        self::assertIsString($failureBytes);
        $failure = Failure::decode($failureBytes);
        self::assertSame(TerminalException::DEFAULT_CODE, $failure->code);
        self::assertSame('because', $failure->message);
    }

    // --- combinator partitioning ---

    public function testSelectCarriesANamedSignalFutureIntoTheNamedSignalBucket(): void
    {
        [$vm, $ctx] = $this->build((new JournalBuilder())->input('1'));
        $signal = $ctx->createSignal('combined');

        try {
            $ctx->select($signal);
            self::fail('expected suspension');
        } catch (SuspendException) {
            // expected
        }

        // partitionFutures must route the named signal into waitingNamedSignals (field 3
        // of the inner combinator node), not into completions or signals.
        $inner = self::innerAwaitTree($this->frames($vm));
        self::assertSame(['combined'], $inner['named']);
        self::assertSame([], $inner['completions']);
        self::assertSame([], $inner['signals']);
    }

    // --- Helpers ---

    /**
     * @return array{0: StateMachine, 1: RestateContext}
     */
    private function build(JournalBuilder $builder): array
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
            writable: true,
            logger: new NullLogger(),
        );

        return [$vm, $ctx];
    }

    /**
     * @return list<Frame>
     */
    private function frames(StateMachine $vm): array
    {
        return MessageCodec::decodeAll($vm->takeOutput());
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
     * The outer (cancel-guarded) await tree carried in the suspension frame.
     *
     * @param list<Frame> $frames
     *
     * @return array{completions: list<int>, signals: list<int>, named: list<string>, nested: list<string>, combinator: int}
     */
    private static function awaitTree(array $frames): array
    {
        $suspension = self::frameOfType($frames, MessageType::Suspension);
        $treeBytes = self::fields($suspension->payload)[4];
        self::assertIsString($treeBytes);

        return self::decodeFuture($treeBytes);
    }

    /**
     * The single nested await point, peeling off the outer cancel-aware wrapper.
     *
     * @param list<Frame> $frames
     *
     * @return array{completions: list<int>, signals: list<int>, named: list<string>, nested: list<string>, combinator: int}
     */
    private static function innerAwaitTree(array $frames): array
    {
        $outer = self::awaitTree($frames);
        self::assertCount(1, $outer['nested']);

        return self::decodeFuture($outer['nested'][0]);
    }

    /**
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
