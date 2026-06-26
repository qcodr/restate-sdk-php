<?php

declare(strict_types=1);

namespace Restate\Sdk\Endpoint;

use Restate\Sdk\Endpoint\Identity\RequestIdentityVerifier;
use Restate\Sdk\Service\ServiceDefinition;
use Restate\Sdk\Service\ServiceOptions;

/**
 * The immutable registry of services exposed by a deployment.
 *
 * Built once via {@see builder} and consumed by transports (the Swoole server, the
 * PSR-15 adapter) through a {@see RequestProcessor}.
 */
final class Endpoint
{
    /**
     * @param array<string, ServiceDefinition> $services keyed by service name
     * @param array<string, ServiceOptions> $options discovery options keyed by service name
     * @param RequestIdentityVerifier|null $identityVerifier request-signing verifier;
     *                                                        null disables verification
     */
    public function __construct(
        private readonly array $services,
        private readonly array $options = [],
        private readonly ?RequestIdentityVerifier $identityVerifier = null,
    ) {
    }

    public static function builder(): EndpointBuilder
    {
        return new EndpointBuilder();
    }

    public function service(string $name): ?ServiceDefinition
    {
        return $this->services[$name] ?? null;
    }

    public function optionsFor(string $name): ?ServiceOptions
    {
        return $this->options[$name] ?? null;
    }

    /**
     * The configured request-identity verifier, or null when request signing is
     * not enforced for this endpoint.
     */
    public function identityVerifier(): ?RequestIdentityVerifier
    {
        return $this->identityVerifier;
    }

    /**
     * @return list<ServiceDefinition>
     */
    public function services(): array
    {
        return \array_values($this->services);
    }
}
