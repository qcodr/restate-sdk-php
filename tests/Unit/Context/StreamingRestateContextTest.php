<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Context;

use Fiber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qcodr\Restate\Sdk\Context\ContextRand;
use Qcodr\Restate\Sdk\Context\RestateContext;
use Qcodr\Restate\Sdk\Context\SystemClock;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Serde\JsonSerde;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;
use Qcodr\Restate\Sdk\Tests\Support\RecordingOutputSink;
use Qcodr\Restate\Sdk\Vm\FiberSuspender;
use Qcodr\Restate\Sdk\Vm\StateMachine;

/**
 * Covers the streaming behavior of the context-level combinators: under a
 * {@see FiberSuspender} an unresolved {@see RestateContext::awaitAny()} /
 * {@see RestateContext::awaitAllSucceeded()} parks the fiber and, once the awaited
 * completions are streamed in and the fiber resumes, re-evaluates readiness and
 * returns. The request/response parity of the same combinators lives in
 * {@see RestateContextTest}.
 */
final class StreamingRestateContextTest extends TestCase
{
    /**
     * @return array{0: StateMachine, 1: RestateContext}
     */
    private function streamingContext(): array
    {
        $vm = new StateMachine(ServiceProtocolVersion::V7, new FiberSuspender(), new RecordingOutputSink());
        $vm->notifyInput((new JournalBuilder())->input('1')->build());
        self::assertTrue($vm->isReadyToExecute());
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

    public function testAwaitAnyParksThenReturnsTheStreamedSuccess(): void
    {
        [$vm, $ctx] = $this->streamingContext();

        // Two unresolved calls (result completion ids 2 and 4).
        $first = $ctx->serviceCallAsync('S', 'h', null);
        $second = $ctx->serviceCallAsync('S', 'h', null);

        $value = null;
        $fiber = new Fiber(static function () use ($ctx, $first, $second, &$value): void {
            $value = $ctx->awaitAny($first, $second);
        });
        $fiber->start();
        self::assertTrue($fiber->isSuspended(), 'awaitAny parks while every future is unresolved');

        // Stream in the first call's success and resume: the combinator loop re-scans.
        $vm->notifyInput((new JournalBuilder())->callCompletion(2, '"win"')->frames());
        $fiber->resume();

        self::assertTrue($fiber->isTerminated());
        self::assertSame('win', $value);
    }

    public function testAwaitAllSucceededParksThenReturnsEveryStreamedValue(): void
    {
        [$vm, $ctx] = $this->streamingContext();

        $first = $ctx->serviceCallAsync('S', 'h', null);
        $second = $ctx->serviceCallAsync('S', 'h', null);

        $values = null;
        $fiber = new Fiber(static function () use ($ctx, $first, $second, &$values): void {
            $values = $ctx->awaitAllSucceeded([$first, $second]);
        });
        $fiber->start();
        self::assertTrue($fiber->isSuspended(), 'awaitAllSucceeded parks until all futures are ready');

        // Stream both successes in one chunk; the resumed loop returns the full set.
        $vm->notifyInput(
            (new JournalBuilder())->callCompletion(2, '"a"')->callCompletion(4, '"b"')->frames(),
        );
        $fiber->resume();

        self::assertTrue($fiber->isTerminated());
        self::assertSame(['a', 'b'], $values);
    }
}
