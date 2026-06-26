<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Endpoint;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Endpoint\Endpoint;
use Restate\Sdk\Service\ServiceDefinition;
use Restate\Sdk\Service\ServiceDefinitionException;
use Restate\Sdk\Service\ServiceOptions;
use Restate\Sdk\Tests\Support\Fixtures\Counter;
use Restate\Sdk\Tests\Support\Fixtures\Greeter;

/**
 * Branch coverage for {@see \Restate\Sdk\Endpoint\EndpointBuilder}: duplicate
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
}
