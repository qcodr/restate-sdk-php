<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Context;

/**
 * A per-run retry policy applied to a {@see Context::run} closure that fails with a
 * non-terminal error.
 *
 * The effective backoff for attempt `n` (zero-based) is
 * `initialIntervalMillis * exponentiationFactor^n`, capped at {@see $maxIntervalMillis}.
 * Once {@see $maxAttempts} is reached the run gives up with a terminal failure.
 */
final class RetryPolicy
{
    public function __construct(
        public readonly int $initialIntervalMillis,
        public readonly int $maxIntervalMillis,
        public readonly ?int $maxAttempts = null,
        public readonly float $exponentiationFactor = 2.0,
        public readonly ?int $maxDurationMillis = null,
    ) {
    }

    /**
     * Builds an exponential-backoff policy.
     */
    public static function exponential(
        int $initialIntervalMillis,
        int $maxIntervalMillis,
        ?int $maxAttempts = null,
        float $exponentiationFactor = 2.0,
        ?int $maxDurationMillis = null,
    ): self {
        return new self(
            $initialIntervalMillis,
            $maxIntervalMillis,
            $maxAttempts,
            $exponentiationFactor,
            $maxDurationMillis,
        );
    }
}
