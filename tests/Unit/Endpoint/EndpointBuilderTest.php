<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Endpoint;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\ProtocolMode;
use Qcodr\Restate\Sdk\Service\ServiceDefinition;
use Qcodr\Restate\Sdk\Service\ServiceDefinitionException;
use Qcodr\Restate\Sdk\Service\ServiceOptions;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Counter;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Greeter;

/**
 * Branch coverage for {@see \Qcodr\Restate\Sdk\Endpoint\EndpointBuilder}: duplicate
 * binding, binding with options, and the empty-endpoint guard.
 */
final class EndpointBuilderTest extends TestCase
{
    public function testBindingTheSameServiceTwiceThrows(): void
    {
        $builder = Endpoint::builder()->bind(new Greeter());

        $this->expectException(ServiceDefinitionException::class);
        $this->expectExceptionMessage("Service 'Greeter' is already registered");

        $builder->bind(new Greeter());
    }

    public function testBindWithOptionsRegistersServiceAndOptions(): void
    {
        $options = new ServiceOptions(documentation: 'The counter service');

        $endpoint = Endpoint::builder()
            ->bindWithOptions(new Counter(), $options)
            ->build();

        self::assertNotNull($endpoint->service('Counter'));
        self::assertSame($options, $endpoint->optionsFor('Counter'));
    }

    public function testBindWithOptionsAcceptsAPreBuiltDefinition(): void
    {
        // Passing a ServiceDefinition directly bypasses fromObject reflection.
        $definition = ServiceDefinition::fromObject(new Greeter());
        $options = new ServiceOptions(documentation: 'The greeter service');

        $endpoint = Endpoint::builder()
            ->bindWithOptions($definition, $options)
            ->build();

        self::assertSame($definition, $endpoint->service('Greeter'));
        self::assertSame($options, $endpoint->optionsFor('Greeter'));
    }

    public function testBuildWithNoServicesThrows(): void
    {
        $this->expectException(ServiceDefinitionException::class);
        $this->expectExceptionMessage('Cannot build an endpoint with no services');

        Endpoint::builder()->build();
    }

    public function testProtocolModeDefaultsToRequestResponse(): void
    {
        $endpoint = Endpoint::builder()->bind(new Greeter())->build();

        self::assertSame(ProtocolMode::RequestResponse, $endpoint->protocolMode());
    }

    public function testProtocolModeOptsIntoBidiStream(): void
    {
        $endpoint = Endpoint::builder()
            ->bind(new Greeter())
            ->protocolMode(ProtocolMode::BidiStream)
            ->build();

        self::assertSame(ProtocolMode::BidiStream, $endpoint->protocolMode());
    }

    public function testProtocolModeEnumValues(): void
    {
        self::assertSame('REQUEST_RESPONSE', ProtocolMode::RequestResponse->value);
        self::assertSame('BIDI_STREAM', ProtocolMode::BidiStream->value);
    }
}
