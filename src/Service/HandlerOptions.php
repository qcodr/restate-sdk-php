<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Service;

/**
 * Per-handler discovery configuration advertised in the endpoint manifest.
 *
 * Every field is optional: a `null` value means "leave unset", so the runtime keeps
 * its own default. Durations are expressed in milliseconds. Which keys are actually
 * emitted depends on the negotiated manifest version — see {@see \Qcodr\Restate\Sdk\Discovery\ManifestBuilder}:
 *   - `documentation` / `metadata` require manifest v2+;
 *   - timeouts, retention windows, `enableLazyState` and `ingressPrivate` require v3+;
 *   - the retry-policy fields require v4+.
 *
 * Instances are immutable value objects; construct them with named arguments.
 */
final class HandlerOptions
{
    /**
     * @param array<string, string>|null $metadata custom metadata shown on the Admin API
     */
    public function __construct(
        public readonly ?int $inactivityTimeoutMillis = null,
        public readonly ?int $abortTimeoutMillis = null,
        public readonly ?int $journalRetentionMillis = null,
        public readonly ?int $idempotencyRetentionMillis = null,
        public readonly ?int $workflowCompletionRetentionMillis = null,
        public readonly ?bool $enableLazyState = null,
        public readonly ?bool $ingressPrivate = null,
        public readonly ?string $documentation = null,
        public readonly ?array $metadata = null,
        public readonly ?int $retryPolicyInitialIntervalMillis = null,
        public readonly ?int $retryPolicyMaxIntervalMillis = null,
        public readonly ?int $retryPolicyMaxAttempts = null,
        public readonly ?float $retryPolicyExponentiationFactor = null,
        public readonly ?RetryPolicyOnMaxAttempts $retryPolicyOnMaxAttempts = null,
    ) {
    }
}
