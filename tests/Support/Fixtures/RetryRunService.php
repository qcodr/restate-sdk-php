<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Support\Fixtures;

use Restate\Sdk\Context\Context;
use Restate\Sdk\Context\RetryPolicy;
use Restate\Sdk\Context\RunOptions;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Service;
use RuntimeException;

/**
 * Fixture exercising the per-run retry policy: a run closure that always fails with a
 * non-terminal error, under policies that either still have retry budget (so the
 * invocation retries with a computed backoff) or are exhausted (so the run gives up
 * with a terminal failure).
 */
#[Service]
final class RetryRunService
{
    /** Counts closure executions across the test's single slice. */
    private int $attempts = 0;

    /** Policy with budget remaining (maxAttempts 3, retryCount 0): expect a retry. */
    #[Handler]
    public function retries(Context $ctx): string
    {
        return $ctx->run('flaky', function (): string {
            $this->attempts++;

            throw new RuntimeException('flaky failure');
        }, new RunOptions(RetryPolicy::exponential(
            initialIntervalMillis: 100,
            maxIntervalMillis: 10000,
            maxAttempts: 3,
        )));
    }

    /** Policy already at its last attempt (maxAttempts 1): expect a terminal give-up. */
    #[Handler]
    public function givesUp(Context $ctx): string
    {
        return $ctx->run('flaky', function (): string {
            $this->attempts++;

            throw new RuntimeException('flaky failure');
        }, new RunOptions(RetryPolicy::exponential(
            initialIntervalMillis: 100,
            maxIntervalMillis: 10000,
            maxAttempts: 1,
        )));
    }

    public function attempts(): int
    {
        return $this->attempts;
    }
}
