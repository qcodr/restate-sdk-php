<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Server;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Server\Psr15Handler;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Greeter;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;

final class Psr15HandlerTest extends TestCase
{
    private function handler(): Psr15Handler
    {
        $factory = new Psr17Factory();
        $endpoint = Endpoint::builder()->bind(new Greeter())->build();

        return new Psr15Handler($endpoint, $factory, $factory);
    }

    public function testDiscoveryReturnsManifest(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/discovery')
            ->withHeader('Accept', 'application/vnd.restate.endpointmanifest.v1+json');

        $response = $this->handler()->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'application/vnd.restate.endpointmanifest.v1+json',
            $response->getHeaderLine('content-type'),
        );
        self::assertStringContainsString('"REQUEST_RESPONSE"', (string) $response->getBody());
    }

    public function testInvokeGreeterReturnsOk(): void
    {
        $factory = new Psr17Factory();
        $journal = (new JournalBuilder())->input('"world"')->build();
        $request = $factory->createServerRequest('POST', '/invoke/Greeter/greet')
            ->withHeader('Content-Type', ServiceProtocolVersion::V7->contentType())
            ->withBody($factory->createStream($journal));

        $response = $this->handler()->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ServiceProtocolVersion::V7->contentType(),
            $response->getHeaderLine('content-type'),
        );
        self::assertStringContainsString('Greetings world', (string) $response->getBody());
    }
}
