<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Endpoint;

use Qcodr\Restate\Sdk\Endpoint\Identity\IdentityKey;
use Qcodr\Restate\Sdk\Endpoint\Identity\IdentityKeyException;
use Qcodr\Restate\Sdk\Endpoint\Identity\RequestIdentityVerifier;
use Qcodr\Restate\Sdk\Service\ServiceDefinition;
use Qcodr\Restate\Sdk\Service\ServiceDefinitionException;
use Qcodr\Restate\Sdk\Service\ServiceOptions;

/**
 * Fluent builder that registers service instances and produces an {@see Endpoint}.
 */
final class EndpointBuilder
{
    /** @var array<string, ServiceDefinition> */
    private array $services = [];

    /** @var array<string, ServiceOptions> */
    private array $options = [];

    /** @var list<IdentityKey> */
    private array $identityKeys = [];

    public function bind(object $service): self
    {
        $definition = $service instanceof ServiceDefinition
            ? $service
            : ServiceDefinition::fromObject($service);

        if (isset($this->services[$definition->name])) {
            throw new ServiceDefinitionException("Service '{$definition->name}' is already registered");
        }

        $this->services[$definition->name] = $definition;

        return $this;
    }

    /**
     * Registers a service together with its discovery {@see ServiceOptions}
     * (timeouts, retention, metadata, retry policy, per-handler options, ...).
     */
    public function bindWithOptions(object $service, ServiceOptions $options): self
    {
        $definition = $service instanceof ServiceDefinition
            ? $service
            : ServiceDefinition::fromObject($service);

        $this->bind($definition);
        $this->options[$definition->name] = $options;

        return $this;
    }

    /**
     * Registers a Restate request-identity public key (`publickeyv1_...`).
     *
     * Once at least one key is registered the endpoint enforces request signing:
     * every request must carry a valid signature from one of these keys or it is
     * rejected with HTTP 401. Call it multiple times to trust several keys (e.g.
     * during key rotation).
     *
     * @throws IdentityKeyException when the key string is malformed
     */
    public function identityKey(string $publicKey): self
    {
        $this->identityKeys[] = IdentityKey::fromString($publicKey);

        return $this;
    }

    public function build(): Endpoint
    {
        if ($this->services === []) {
            throw new ServiceDefinitionException('Cannot build an endpoint with no services');
        }

        $verifier = $this->identityKeys === []
            ? null
            : new RequestIdentityVerifier(...$this->identityKeys);

        return new Endpoint($this->services, $this->options, $verifier);
    }
}
