<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Context\DurableFuture;
use Restate\Sdk\Error\TerminalException;
use Restate\Sdk\Protocol\Message\StateKeys;
use Restate\Sdk\Protocol\MessageHeader;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Writer;
use Restate\Sdk\Protocol\ServiceProtocolVersion;
use Restate\Sdk\Vm\StateMachine;

/**
 * Verifies DurableFuture against a state machine seeded with replayed notifications:
 * the completion vs signal lookups, the side-effect-free isReady()/isFailed()
 * inspectors, and how await()/take() resolve every notification result kind
 * (value with and without a decoder, failure, invocation id, state keys, void, none).
 */
final class DurableFutureTest extends TestCase
{
    public function testIdAndIsSignalForACompletionFuture(): void
    {
        $vm = $this->machine([$this->completionValue(2, '"x"')]);
        $future = new DurableFuture($vm, 2, false);

        self::assertSame(2, $future->id());
        self::assertFalse($future->isSignal());
    }

    public function testIdAndIsSignalForASignalFuture(): void
    {
        $vm = $this->machine([$this->signalValue(17, '"x"')]);
        $future = new DurableFuture($vm, 17, true);

        self::assertSame(17, $future->id());
        self::assertTrue($future->isSignal());
    }

    public function testIsReadyReflectsTheCompletionTable(): void
    {
        $vm = $this->machine([$this->completionValue(2, '"x"')]);

        self::assertTrue((new DurableFuture($vm, 2, false))->isReady());
        self::assertFalse((new DurableFuture($vm, 99, false))->isReady());
    }

    public function testIsReadyReflectsTheSignalTable(): void
    {
        $vm = $this->machine([$this->signalValue(17, '"x"')]);

        self::assertTrue((new DurableFuture($vm, 17, true))->isReady());
        self::assertFalse((new DurableFuture($vm, 99, true))->isReady());
    }

    public function testIsFailedIsFalseWhilePending(): void
    {
        $vm = $this->machine([]);

        self::assertFalse((new DurableFuture($vm, 1, false))->isFailed(), 'pending completion');
        self::assertFalse((new DurableFuture($vm, 1, true))->isFailed(), 'pending signal');
    }

    public function testIsFailedIsTrueForAFailedCompletion(): void
    {
        $vm = $this->machine([$this->completionFailure(2, 409, 'boom')]);

        self::assertTrue((new DurableFuture($vm, 2, false))->isFailed());
    }

    public function testIsFailedIsFalseForASuccessfulCompletion(): void
    {
        $vm = $this->machine([$this->completionValue(2, '"ok"')]);

        self::assertFalse((new DurableFuture($vm, 2, false))->isFailed());
    }

    public function testIsFailedInspectsTheSignalTableWithoutConsuming(): void
    {
        $vm = $this->machine([
            $this->signalFailure(17, 500, 'sig boom'),
            $this->signalValue(18, '"ok"'),
        ]);

        $failed = new DurableFuture($vm, 17, true);
        self::assertTrue($failed->isFailed());
        // Peek does not consume: a second inspection still sees the failure.
        self::assertTrue($failed->isFailed());
        self::assertFalse((new DurableFuture($vm, 18, true))->isFailed());
    }

    public function testAwaitDecodesAValueWithTheDecoder(): void
    {
        $vm = $this->machine([$this->completionValue(2, '{"n":1}')]);
        $decoder = static fn (string $raw): mixed => \json_decode($raw, true);
        $future = new DurableFuture($vm, 2, false, $decoder);

        self::assertSame(['n' => 1], $future->await());
    }

    public function testAwaitReturnsTheRawValueWithoutADecoder(): void
    {
        $vm = $this->machine([$this->completionValue(2, '"raw"')]);

        self::assertSame('"raw"', (new DurableFuture($vm, 2, false))->await());
    }

    public function testAwaitRaisesATerminalExceptionForAFailure(): void
    {
        $vm = $this->machine([$this->completionFailure(2, 409, 'denied')]);

        $this->expectException(TerminalException::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('denied');

        (new DurableFuture($vm, 2, false))->await();
    }

    public function testAwaitReturnsTheInvocationId(): void
    {
        $vm = $this->machine([$this->completionInvocationId(2, 'inv-abc')]);

        self::assertSame('inv-abc', (new DurableFuture($vm, 2, false))->await());
    }

    public function testAwaitReturnsTheStateKeys(): void
    {
        $vm = $this->machine([$this->completionStateKeys(2, ['a', 'b'])]);

        self::assertSame(['a', 'b'], (new DurableFuture($vm, 2, false))->await());
    }

    public function testAwaitReturnsNullForAVoidResult(): void
    {
        $vm = $this->machine([$this->completionVoid(2)]);

        self::assertNull((new DurableFuture($vm, 2, false))->await());
    }

    public function testAwaitReturnsNullForAResultlessNotification(): void
    {
        $vm = $this->machine([$this->completionNone(2)]);

        self::assertNull((new DurableFuture($vm, 2, false))->await());
    }

    public function testAwaitResolvesASignalValue(): void
    {
        $vm = $this->machine([$this->signalValue(17, '"sig"')]);

        self::assertSame('"sig"', (new DurableFuture($vm, 17, true))->await());
    }

    public function testTakePeeksACompletionWithoutConsumingIt(): void
    {
        $vm = $this->machine([$this->completionValue(2, '"peek"')]);
        $future = new DurableFuture($vm, 2, false);

        self::assertSame('"peek"', $future->take());
        self::assertSame('"peek"', $future->take(), 'take() peeks and does not consume the completion');
        self::assertTrue($future->isReady());
    }

    public function testTakePeeksASignal(): void
    {
        $vm = $this->machine([$this->signalVoid(17)]);

        self::assertNull((new DurableFuture($vm, 17, true))->take());
    }

    // --- Journal assembly -----------------------------------------------------

    /**
     * Builds a parsed state machine whose completion/signal tables are seeded from the
     * given replayed notification frames (no commands are issued by these tests).
     *
     * @param list<array{0: MessageType, 1: string}> $frames
     */
    private function machine(array $frames): StateMachine
    {
        $bytes = $this->frame(MessageType::Start, $this->startPayload(\count($frames)));
        foreach ($frames as [$type, $payload]) {
            $bytes .= $this->frame($type, $payload);
        }

        $vm = new StateMachine(ServiceProtocolVersion::V7);
        $vm->notifyInput($bytes);
        $vm->notifyInputClosed();
        self::assertTrue($vm->isReadyToExecute());

        return $vm;
    }

    private function startPayload(int $knownEntries): string
    {
        return (new Writer())
            ->writeBytesPresent(1, 'inv-1')
            ->writeUint32(3, $knownEntries)
            ->toString();
    }

    private function frame(MessageType $type, string $payload): string
    {
        return (new MessageHeader($type->value, \strlen($payload)))->encode() . $payload;
    }

    /** @return array{0: MessageType, 1: string} */
    private function completionValue(int $completionId, string $content): array
    {
        return [MessageType::CallCompletion, $this->withValue($this->completionId($completionId), $content)];
    }

    /** @return array{0: MessageType, 1: string} */
    private function completionFailure(int $completionId, int $code, string $message): array
    {
        return [MessageType::CallCompletion, $this->withFailure($this->completionId($completionId), $code, $message)];
    }

    /** @return array{0: MessageType, 1: string} */
    private function completionVoid(int $completionId): array
    {
        return [MessageType::SleepCompletion, $this->completionId($completionId)->writeMessage(4, '')->toString()];
    }

    /** @return array{0: MessageType, 1: string} */
    private function completionInvocationId(int $completionId, string $invocationId): array
    {
        $payload = $this->completionId($completionId)->writeStringPresent(16, $invocationId)->toString();

        return [MessageType::CallInvocationIdCompletion, $payload];
    }

    /**
     * @param list<string> $keys
     *
     * @return array{0: MessageType, 1: string}
     */
    private function completionStateKeys(int $completionId, array $keys): array
    {
        $payload = $this->completionId($completionId)->writeMessage(17, (new StateKeys($keys))->encode())->toString();

        return [MessageType::GetLazyStateKeysCompletion, $payload];
    }

    /** @return array{0: MessageType, 1: string} */
    private function completionNone(int $completionId): array
    {
        return [MessageType::CallCompletion, $this->completionId($completionId)->toString()];
    }

    /** @return array{0: MessageType, 1: string} */
    private function signalValue(int $signalId, string $content): array
    {
        return [MessageType::SignalNotification, $this->withValue($this->signalId($signalId), $content)];
    }

    /** @return array{0: MessageType, 1: string} */
    private function signalFailure(int $signalId, int $code, string $message): array
    {
        return [MessageType::SignalNotification, $this->withFailure($this->signalId($signalId), $code, $message)];
    }

    /** @return array{0: MessageType, 1: string} */
    private function signalVoid(int $signalId): array
    {
        return [MessageType::SignalNotification, $this->signalId($signalId)->writeMessage(4, '')->toString()];
    }

    private function completionId(int $completionId): Writer
    {
        return (new Writer())->writeUint32Present(1, $completionId);
    }

    private function signalId(int $signalId): Writer
    {
        return (new Writer())->writeUint32Present(2, $signalId);
    }

    private function withValue(Writer $writer, string $content): string
    {
        return $writer->writeMessage(5, (new Writer())->writeBytes(1, $content)->toString())->toString();
    }

    private function withFailure(Writer $writer, int $code, string $message): string
    {
        $failure = (new Writer())->writeUint32(1, $code)->writeString(2, $message)->toString();

        return $writer->writeMessage(6, $failure)->toString();
    }
}
