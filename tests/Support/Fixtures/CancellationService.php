<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Support\Fixtures;

use Restate\Sdk\Context\Context;
use Restate\Sdk\Error\RetryableException;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Service;

/**
 * Fixture exercising durable-error tuning and cancellation: a handler that throws a
 * pausing {@see RetryableException}, one that cancels another invocation, and one
 * that awaits a never-arriving timer (so a delivered CANCEL signal surfaces as a
 * terminal 409).
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
}
