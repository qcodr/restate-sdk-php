<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Endpoint;

use Qcodr\Restate\Sdk\Endpoint\Identity\RequestIdentityVerifier;
use Qcodr\Restate\Sdk\Service\ServiceDefinition;
use Qcodr\Restate\Sdk\Service\ServiceOptions;

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
     * @param ProtocolMode $protocolMode the transport mode advertised in discovery
     */
    public function __construct(
        private readonly array $services,
        private readonly array $options = [],
        private readonly ?RequestIdentityVerifier $identityVerifier = null,
        private readonly ProtocolMode $protocolMode = ProtocolMode::RequestResponse,
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
     * The transport mode advertised in the discovery manifest.
     */
    public function protocolMode(): ProtocolMode
    {
        return $this->protocolMode;
    }

    /**
     * @return list<ServiceDefinition>
     */
    public function services(): array
    {
        return \array_values($this->services);
    }
}
