<?php

declare(strict_types=1);

namespace Restate\Sdk\Service;

/**
 * Per-service discovery configuration advertised in the endpoint manifest, plus an
 * optional map of per-handler {@see HandlerOptions} keyed by Restate handler name.
 *
 * Every field is optional: a `null` value means "leave unset", so the runtime keeps
 * its own default. Durations are expressed in milliseconds. Which keys are actually
 * emitted depends on the negotiated manifest version — see {@see \Restate\Sdk\Discovery\ManifestBuilder}:
 *   - `documentation` / `metadata` require manifest v2+;
 *   - timeouts, retention windows, `enableLazyState` and `ingressPrivate` require v3+;
 *   - the retry-policy fields require v4+.
 *
 * Instances are immutable value objects; attach handler options with the fluent,
 * copy-on-write {@see withHandler} helper.
 */
final class ServiceOptions
{
    /**
     * @param array<string, string>|null $metadata custom metadata shown on the Admin API
     * @param array<string, HandlerOptions> $handlers per-handler options keyed by handler name
     */
    public function __construct(
        public readonly ?int $inactivityTimeoutMillis = null,
        public readonly ?int $abortTimeoutMillis = null,
        public readonly ?int $journalRetentionMillis = null,
        public readonly ?int $idempotencyRetentionMillis = null,
        public readonly ?bool $enableLazyState = null,
        public readonly ?bool $ingressPrivate = null,
        public readonly ?string $documentation = null,
        public readonly ?array $metadata = null,
        public readonly ?int $retryPolicyInitialIntervalMillis = null,
        public readonly ?int $retryPolicyMaxIntervalMillis = null,
        public readonly ?int $retryPolicyMaxAttempts = null,
        public readonly ?float $retryPolicyExponentiationFactor = null,
        public readonly ?RetryPolicyOnMaxAttempts $retryPolicyOnMaxAttempts = null,
        public readonly array $handlers = [],
    ) {
    }

    /**
     * Returns a copy of these options with the given handler's options registered
     * (replacing any previous entry for the same handler name).
     */
    public function withHandler(string $name, HandlerOptions $options): self
    {
        $handlers = $this->handlers;
        $handlers[$name] = $options;

        return new self(
            inactivityTimeoutMillis: $this->inactivityTimeoutMillis,
            abortTimeoutMillis: $this->abortTimeoutMillis,
            journalRetentionMillis: $this->journalRetentionMillis,
            idempotencyRetentionMillis: $this->idempotencyRetentionMillis,
            enableLazyState: $this->enableLazyState,
            ingressPrivate: $this->ingressPrivate,
            documentation: $this->documentation,
            metadata: $this->metadata,
            retryPolicyInitialIntervalMillis: $this->retryPolicyInitialIntervalMillis,
            retryPolicyMaxIntervalMillis: $this->retryPolicyMaxIntervalMillis,
            retryPolicyMaxAttempts: $this->retryPolicyMaxAttempts,
            retryPolicyExponentiationFactor: $this->retryPolicyExponentiationFactor,
            retryPolicyOnMaxAttempts: $this->retryPolicyOnMaxAttempts,
            handlers: $handlers,
        );
    }

    public function handlerOptions(string $name): ?HandlerOptions
    {
        return $this->handlers[$name] ?? null;
    }
}
