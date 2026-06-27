<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Support\Fixtures;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Error\CancelledException;
use Qcodr\Restate\Sdk\Error\RetryableException;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

/**
 * Fixture exercising durable-error tuning and cancellation: a handler that throws a
 * pausing {@see RetryableException}, one that cancels another invocation, one that
 * awaits a never-arriving timer (so a delivered CANCEL signal surfaces as a terminal
 * 409), and the four combinators reached while a CANCEL is pending (each must surface a
 * 409 rather than re-suspend or throw a misleading error).
 */
#[Service]
final class CancellationService
{
    /** Throws a retryable error asking the runtime to pause the invocation. */
    #[Handler]
    public function failPaused(Context $ctx): string
    {
        throw new RetryableException('transient outage', pause: true);
    }

    /** Cancels another invocation, then returns. */
    #[Handler]
    public function cancelOther(Context $ctx): string
    {
        $ctx->cancel('inv-target-99');

        return 'cancelled';
    }

    /** Awaits a long timer; a delivered CANCEL signal turns this into a 409 failure. */
    #[Handler]
    public function awaitThenSleep(Context $ctx): string
    {
        $ctx->sleep(60.0);

        return 'slept';
    }

    /** Races a never-completing call; a pending CANCEL must surface as a 409, not a suspension. */
    #[Handler]
    public function raceWhileCancelled(Context $ctx): string
    {
        $ctx->awaitAny($ctx->serviceCallAsync('Backend', 'never'));

        return 'done';
    }

    /** select() over a never-completing call; a pending CANCEL must surface as a 409. */
    #[Handler]
    public function selectWhileCancelled(Context $ctx): string
    {
        $ctx->select($ctx->serviceCallAsync('Backend', 'never'));

        return 'done';
    }

    /** awaitAll() over a never-completing call; a pending CANCEL must surface as a 409. */
    #[Handler]
    public function awaitAllWhileCancelled(Context $ctx): string
    {
        $ctx->awaitAll([$ctx->serviceCallAsync('Backend', 'never')]);

        return 'done';
    }

    /** awaitAllSucceeded() over a never-completing call; a pending CANCEL must surface as a 409. */
    #[Handler]
    public function awaitAllSucceededWhileCancelled(Context $ctx): string
    {
        $ctx->awaitAllSucceeded([$ctx->serviceCallAsync('Backend', 'never')]);

        return 'done';
    }

    /**
     * Observes a cancel at the sleep await (CancelledException), then reaches a combinator.
     * The combinator must also surface the still-pending cancel as a 409 rather than
     * re-parking — proves the driver drains a re-park whose predicate already holds.
     */
    #[Handler]
    public function raceAfterObservedCancel(Context $ctx): string
    {
        try {
            $ctx->sleep(60.0);
        } catch (CancelledException) {
            // observed; fall through to a combinator that is still cancelled
        }

        $ctx->awaitAny($ctx->serviceCallAsync('Backend', 'never'));

        return 'done';
    }
}
