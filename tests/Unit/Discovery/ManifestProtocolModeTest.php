<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Discovery\ManifestBuilder;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;
use Qcodr\Restate\Sdk\Endpoint\ProtocolMode;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;
use Qcodr\Restate\Sdk\Service\ServiceDefinition;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Greeter;

/**
 * Covers the discovery `protocolMode` field: `REQUEST_RESPONSE` by default and
 * `BIDI_STREAM` only when an endpoint opts in via {@see ProtocolMode}.
 */
final class ManifestProtocolModeTest extends TestCase
{
    public function testBuildDefaultsToRequestResponse(): void
    {
        $manifest = (new ManifestBuilder())->build([ServiceDefinition::fromObject(new Greeter())]);

        self::assertSame('REQUEST_RESPONSE', $manifest['protocolMode']);
    }

    public function testBuildEmitsBidiStreamWhenRequested(): void
    {
        $manifest = (new ManifestBuilder())->build(
            [ServiceDefinition::fromObject(new Greeter())],
            1,
            [],
            ProtocolMode::BidiStream,
        );

        self::assertSame('BIDI_STREAM', $manifest['protocolMode']);
    }

    public function testDiscoveryDefaultsToRequestResponse(): void
    {
        self::assertSame('REQUEST_RESPONSE', $this->discover(Endpoint::builder()->bind(new Greeter())->build()));
    }

    public function testDiscoveryCapsBidiEndpointToRequestResponseOnDefaultTransport(): void
    {
        // The per-transport cap: a bidi-configured endpoint served by a default-capability
        // host (Swoole/PSR-15/Lambda) advertises REQUEST_RESPONSE — only a bidi-capable
        // transport may advertise BIDI_STREAM.
        $endpoint = Endpoint::builder()
            ->bind(new Greeter())
            ->protocolMode(ProtocolMode::BidiStream)
            ->build();

        self::assertSame('REQUEST_RESPONSE', $this->discover($endpoint));
    }

    public function testDiscoveryEmitsBidiStreamWhenEndpointAndTransportBothOptIn(): void
    {
        $endpoint = Endpoint::builder()
            ->bind(new Greeter())
            ->protocolMode(ProtocolMode::BidiStream)
            ->build();

        self::assertSame('BIDI_STREAM', $this->discover($endpoint, ProtocolMode::BidiStream));
    }

    /**
     * Runs the discovery route end-to-end and returns the emitted `protocolMode`,
     * with the host's transport capability defaulting to request/response.
     */
    private function discover(Endpoint $endpoint, ProtocolMode $capability = ProtocolMode::RequestResponse): string
    {
        $request = new HttpRequest('GET', '/discovery', [], '');
        $response = (new RequestProcessor($endpoint, transportCapability: $capability))->process($request);

        self::assertSame(200, $response->status);

        $manifest = \json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertArrayHasKey('protocolMode', $manifest);
        self::assertIsString($manifest['protocolMode']);

        return $manifest['protocolMode'];
    }
}
