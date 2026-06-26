<?php

declare(strict_types=1);

namespace Restate\Conformance;

use Qcodr\Restate\Sdk\Context\ObjectContext;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\VirtualObject;

/**
 * Drives a cancellation scenario: it calls the blocking service, which parks on an
 * awakeable until the harness cancels the in-flight invocation. The propagated
 * cancellation surfaces here as a terminal error with code 409, which is recorded
 * and later verified.
 *
 * Mirrors the Rust test-service `cancel_test.rs` (CancelTestRunner).
 */
#[VirtualObject(name: 'CancelTestRunner')]
final class CancelTestRunner
{
    private const CANCELED = 'canceled';

    #[Handler]
    public function startTest(ObjectContext $ctx, string $operation): void
    {
        try {
            $ctx->objectCall('CancelTestBlockingService', $ctx->key(), 'block', $operation);
        } catch (TerminalException $e) {
            // Cancellation propagates as a terminal failure with HTTP 409.
            if ($e->statusCode() === 409) {
                $ctx->set(self::CANCELED, true);

                return;
            }

            throw $e;
        }

        // The blocking call is expected to be cancelled, never to return normally.
        throw new TerminalException('Block succeeded, this is unexpected');
    }

    #[Handler]
    public function verifyTest(ObjectContext $ctx): bool
    {
        $canceled = $ctx->get(self::CANCELED);

        return \is_bool($canceled) ? $canceled : false;
    }
}

/**
 * The service that blocks. It registers an awakeable with the AwakeableHolder, parks
 * on it, and once released keeps blocking via the requested operation (recursive call,
 * a very long sleep, or a second awakeable that is never completed) so the harness can
 * exercise cancellation at each kind of await point.
 *
 * Mirrors the Rust test-service `cancel_test.rs` (CancelTestBlockingService).
 */
#[VirtualObject(name: 'CancelTestBlockingService')]
final class CancelTestBlockingService
{
    #[Handler]
    public function block(ObjectContext $ctx, string $operation): void
    {
        $awakeable = $ctx->awakeable();
        // Awaited call (the deliberate difference vs. kill_test's fire-and-forget send).
        $ctx->objectCall('AwakeableHolder', $ctx->key(), 'hold', $awakeable->id());
        $awakeable->await();

        switch ($operation) {
            case 'CALL':
                $ctx->objectCall('CancelTestBlockingService', $ctx->key(), 'block', $operation);
                break;
            case 'SLEEP':
                $ctx->sleep(60 * 60 * 24 * 1024);
                break;
            case 'AWAKEABLE':
                $uncompletable = $ctx->awakeable();
                $uncompletable->await();
                break;
        }
    }

    #[Handler]
    public function isUnlocked(ObjectContext $ctx): void
    {
        // no-op
    }
}
