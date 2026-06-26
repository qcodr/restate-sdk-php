<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Context\Awakeable;
use Qcodr\Restate\Sdk\Context\DurableFuture;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;
use Qcodr\Restate\Sdk\Vm\StateMachine;

/**
 * Verifies the awakeable handle: it exposes the public id verbatim and delegates
 * await() to its backing durable future, returning the resolved value or raising the
 * terminal failure the future would.
 */
final class AwakeableTest extends TestCase
{
    public function testIdReturnsTheConfiguredHandle(): void
    {
        $awakeable = new Awakeable('prom_1xyz', $this->valueFuture('"v"'));

        self::assertSame('prom_1xyz', $awakeable->id());
    }

    public function testAwaitDelegatesToTheFutureAndReturnsTheResolvedValue(): void
    {
        $awakeable = new Awakeable('prom_1xyz', $this->valueFuture('"hello"'));

        self::assertSame('"hello"', $awakeable->await());
    }

    public function testAwaitRaisesTheTerminalFailureWhenRejected(): void
    {
        $journal = (new JournalBuilder())->input('')->failedCallCompletion(2, 'rejected', 409)->build();
        $awakeable = new Awakeable('prom_1xyz', new DurableFuture($this->machine($journal), 2, false));

        $this->expectException(TerminalException::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('rejected');

        $awakeable->await();
    }

    private function valueFuture(string $content): DurableFuture
    {
        $journal = (new JournalBuilder())->input('')->callCompletion(2, $content)->build();

        return new DurableFuture($this->machine($journal), 2, false);
    }

    private function machine(string $journal): StateMachine
    {
        $vm = new StateMachine(ServiceProtocolVersion::V7);
        $vm->notifyInput($journal);
        $vm->notifyInputClosed();
        self::assertTrue($vm->isReadyToExecute());

        return $vm;
    }
}
