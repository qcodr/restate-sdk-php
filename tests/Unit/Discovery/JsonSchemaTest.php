<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Discovery\JsonSchemaGenerator;
use Restate\Sdk\Discovery\ManifestBuilder;
use Restate\Sdk\Service\ServiceDefinition;
use Restate\Sdk\Tests\Support\Fixtures\DtoService;
use Restate\Sdk\Tests\Support\Fixtures\Greeter;
use Restate\Sdk\Tests\Support\Fixtures\SampleDto;

final class JsonSchemaTest extends TestCase
{
    private const DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    public function testScalarTypesMapToJsonSchemaTypes(): void
    {
        $generator = new JsonSchemaGenerator();

        self::assertSame(['$schema' => self::DIALECT, 'type' => 'integer'], $generator->forType('int'));
        self::assertSame(['$schema' => self::DIALECT, 'type' => 'number'], $generator->forType('float'));
        self::assertSame(['$schema' => self::DIALECT, 'type' => 'string'], $generator->forType('string'));
        self::assertSame(['$schema' => self::DIALECT, 'type' => 'boolean'], $generator->forType('bool'));
    }

    public function testGenericArrayMapsToObject(): void
    {
        $schema = (new JsonSchemaGenerator())->forType('array');

        self::assertSame(['$schema' => self::DIALECT, 'type' => 'object'], $schema);
    }

    public function testUntypedAndMixedYieldNull(): void
    {
        $generator = new JsonSchemaGenerator();

        self::assertNull($generator->forType('mixed'));
        self::assertNull($generator->forType(null));
        self::assertNull($generator->forType('null'));
        self::assertNull($generator->forType('void'));
    }

    public function testClassYieldsObjectSchemaWithPropertiesAndRequired(): void
    {
        $schema = (new JsonSchemaGenerator())->forType(SampleDto::class);

        self::assertIsArray($schema);
        self::assertSame(self::DIALECT, $schema['$schema']);
        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);

        self::assertIsArray($schema['properties']);
        self::assertArrayHasKey('name', $schema['properties']);
        self::assertSame(['type' => 'string'], $schema['properties']['name']);
        self::assertSame(['type' => 'integer'], $schema['properties']['age']);
        self::assertSame(['type' => 'string'], $schema['properties']['nickname']);
        self::assertSame(['type' => 'boolean'], $schema['properties']['active']);

        // Only the non-nullable, non-defaulted scalars are required.
        self::assertIsArray($schema['required']);
        self::assertContains('name', $schema['required']);
        self::assertContains('age', $schema['required']);
        self::assertNotContains('nickname', $schema['required']);
        self::assertNotContains('active', $schema['required']);
        self::assertNotContains('address', $schema['required']);
    }

    public function testNestedClassRecursesOneLevel(): void
    {
        $schema = (new JsonSchemaGenerator())->forType(SampleDto::class);

        self::assertIsArray($schema);
        self::assertIsArray($schema['properties']);

        $address = $schema['properties']['address'];
        self::assertIsArray($address);
        // Nested schema must NOT repeat the top-level dialect key.
        self::assertArrayNotHasKey('$schema', $address);
        self::assertSame('object', $address['type']);
        self::assertIsArray($address['properties']);
        self::assertSame(['type' => 'string'], $address['properties']['city']);
        self::assertSame(['type' => 'integer'], $address['properties']['zip']);
    }

    public function testGreeterHandlerCarriesStringSchemaInManifest(): void
    {
        $manifest = (new ManifestBuilder())->build([ServiceDefinition::fromObject(new Greeter())]);

        $greet = self::firstHandler($manifest);

        self::assertArrayHasKey('input', $greet);
        self::assertIsArray($greet['input']);
        self::assertSame(
            ['$schema' => self::DIALECT, 'type' => 'string'],
            $greet['input']['jsonSchema'],
        );

        self::assertArrayHasKey('output', $greet);
        self::assertIsArray($greet['output']);
        self::assertSame(
            ['$schema' => self::DIALECT, 'type' => 'string'],
            $greet['output']['jsonSchema'],
        );
    }

    public function testDtoHandlerCarriesObjectSchemaInManifest(): void
    {
        $manifest = (new ManifestBuilder())->build([ServiceDefinition::fromObject(new DtoService())]);

        $process = self::firstHandler($manifest);

        self::assertIsArray($process['input']);
        self::assertIsArray($process['input']['jsonSchema']);
        self::assertSame('object', $process['input']['jsonSchema']['type']);
        self::assertSame(self::DIALECT, $process['input']['jsonSchema']['$schema']);
        self::assertArrayHasKey('properties', $process['input']['jsonSchema']);

        self::assertIsArray($process['output']);
        self::assertIsArray($process['output']['jsonSchema']);
        self::assertSame('object', $process['output']['jsonSchema']['type']);
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array<array-key, mixed>
     */
    private static function firstHandler(array $manifest): array
    {
        self::assertArrayHasKey('services', $manifest);
        self::assertIsArray($manifest['services']);
        $service = $manifest['services'][0];
        self::assertIsArray($service);
        self::assertArrayHasKey('handlers', $service);
        self::assertIsArray($service['handlers']);
        $handler = $service['handlers'][0];
        self::assertIsArray($handler);

        return $handler;
    }
}
