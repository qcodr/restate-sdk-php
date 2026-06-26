<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Context;

/**
 * Options for a {@see Context::run} call. Currently carries an optional
 * {@see RetryPolicy} that governs how a failing run closure is retried.
 */
final class RunOptions
{
    public function __construct(
        public readonly ?RetryPolicy $retryPolicy = null,
    ) {
    }
}
