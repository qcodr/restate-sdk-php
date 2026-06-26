<?php

declare(strict_types=1);

namespace Restate\Conformance;

use Restate\Sdk\Context\ObjectContext;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\VirtualObject;

/**
 * Deliberately non-deterministic virtual object used to exercise the runtime's
 * journal/replay divergence detection.
 *
 * Each handler alternates between two structurally different journal shapes ("left"
 * vs "right") on successive invocations of the same object key, driven by an
 * in-memory per-key counter. The counter lives as an instance property: the
 * conformance endpoint binds a single instance on a single worker, so it persists
 * across invocations within the process (it MUST NOT use durable Restate state).
 *
 * @var array<string, int> $invocationCounts per-key invocation counter
 */
#[VirtualObject(name: 'NonDeterministic')]
final class NonDeterministic
{
    private const STATE_A = 'a';
    private const STATE_B = 'b';

    /** @var array<string, int> */
    private array $invocationCounts = [];

    #[Handler]
    public function eitherSleepOrCall(ObjectContext $ctx): void
    {
        if ($this->doLeftAction($ctx->key())) {
            $ctx->sleep(0.1);
        } else {
            $ctx->objectCall('Counter', 'abc', 'get');
        }

        $this->sleepThenIncrementCounter($ctx);
    }

    #[Handler]
    public function callDifferentMethod(ObjectContext $ctx): void
    {
        if ($this->doLeftAction($ctx->key())) {
            $ctx->objectCall('Counter', 'abc', 'get');
        } else {
            $ctx->objectCall('Counter', 'abc', 'reset');
        }

        $this->sleepThenIncrementCounter($ctx);
    }

    #[Handler]
    public function backgroundInvokeWithDifferentTargets(ObjectContext $ctx): void
    {
        if ($this->doLeftAction($ctx->key())) {
            $ctx->objectSend('Counter', 'abc', 'get');
        } else {
            $ctx->objectSend('Counter', 'abc', 'reset');
        }

        $this->sleepThenIncrementCounter($ctx);
    }

    #[Handler]
    public function setDifferentKey(ObjectContext $ctx): void
    {
        if ($this->doLeftAction($ctx->key())) {
            $ctx->set(self::STATE_A, 'my-state');
        } else {
            $ctx->set(self::STATE_B, 'my-state');
        }

        $this->sleepThenIncrementCounter($ctx);
    }

    private function doLeftAction(string $key): bool
    {
        $count = $this->invocationCounts[$key] ?? 0;
        $this->invocationCounts[$key] = $count + 1;

        return $count % 2 === 1;
    }

    private function sleepThenIncrementCounter(ObjectContext $ctx): void
    {
        $ctx->sleep(0.1);
        $ctx->objectSend('Counter', $ctx->key(), 'add', 1);
    }
}
