<?php

declare(strict_types=1);

namespace Restate\Conformance;

use Restate\Sdk\Context\ObjectContext;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\VirtualObject;

/**
 * Kicks off a recursive call tree into KillTestSingleton so the harness can kill the
 * invocation mid-flight and observe the whole tree being torn down.
 *
 * Mirrors the Rust test-service `kill_test.rs` (KillTestRunner).
 */
#[VirtualObject(name: 'KillTestRunner')]
final class KillTestRunner
{
    #[Handler]
    public function startCallTree(ObjectContext $ctx): void
    {
        $ctx->objectCall('KillTestSingleton', $ctx->key(), 'recursiveCall');
    }
}

/**
 * Builds the recursive call tree: each invocation registers an awakeable, parks on it,
 * and then recurses. Unlike cancel_test, the awakeable id is handed to the holder with
 * a fire-and-forget send (not an awaited call) — the deliberate difference between the
 * two scenarios.
 *
 * Mirrors the Rust test-service `kill_test.rs` (KillTestSingleton).
 */
#[VirtualObject(name: 'KillTestSingleton')]
final class KillTestSingleton
{
    #[Handler]
    public function recursiveCall(ObjectContext $ctx): void
    {
        $awakeable = $ctx->awakeable();
        // Fire-and-forget send (NOT awaited) — the deliberate difference vs. cancel_test.
        $ctx->objectSend('AwakeableHolder', $ctx->key(), 'hold', $awakeable->id());
        $awakeable->await();

        $ctx->objectCall('KillTestSingleton', $ctx->key(), 'recursiveCall');
    }

    #[Handler]
    public function isUnlocked(ObjectContext $ctx): void
    {
        // no-op
    }
}
