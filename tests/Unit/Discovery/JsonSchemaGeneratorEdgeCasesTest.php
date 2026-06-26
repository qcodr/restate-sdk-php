<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Discovery\JsonSchemaGenerator;

/**
 * Structural edge cases for class-to-schema reflection: recursion-depth fallback,
 * properties skipped because they are static or untyped, classes that expose no
 * usable members, constructor-less classes, and inline default values driving
 * optionality.
 */
final class JsonSchemaGeneratorEdgeCasesTest extends TestCase
{
    private const DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    public function testNestingBeyondMaxDepthCollapsesToGenericObject(): void
    {
        $schema = (new JsonSchemaGenerator())->forType(DepthRoot::class);

        self::assertIsArray($schema);
        self::assertIsArray($schema['properties']);
        $a = $schema['properties']['a'];
        self::assertIsArray($a);
        self::assertIsArray($a['properties']);
        $b = $a['properties']['b'];
        self::assertIsArray($b);
        self::assertIsArray($b['properties']);

        // The fourth level exceeds MAX_DEPTH (3) and is emitted as a bare object,
        // without recursing into DepthLeaf's own properties.
        self::assertSame(['type' => 'object'], $b['properties']['c']);
    }

    public function testStaticPropertiesAreSkippedAndAnEmptyClassIsAGenericObject(): void
    {
        $schema = (new JsonSchemaGenerator())->forType(OnlyStaticMember::class);

        // The single public member is static, so no properties survive and the class
        // degrades to a structureless object.
        self::assertSame(['$schema' => self::DIALECT, 'type' => 'object'], $schema);
    }

    public function testConstructorlessClassWithInlineDefaultMakesPropertyOptional(): void
    {
        $schema = (new JsonSchemaGenerator())->forType(InlineDefaults::class);

        self::assertIsArray($schema);
        self::assertSame('object', $schema['type']);
        self::assertIsArray($schema['properties']);
        self::assertSame(['type' => 'integer'], $schema['properties']['count']);
        self::assertSame(['type' => 'string'], $schema['properties']['label']);

        // `count` carries an inline default so it is optional; `label` does not.
        self::assertArrayHasKey('required', $schema);
        self::assertIsArray($schema['required']);
        self::assertContains('label', $schema['required']);
        self::assertNotContains('count', $schema['required']);
    }

    public function testAllOptionalMembersOmitTheRequiredKey(): void
    {
        $schema = (new JsonSchemaGenerator())->forType(AllOptional::class);

        self::assertIsArray($schema);
        self::assertArrayHasKey('properties', $schema);
        self::assertArrayNotHasKey('required', $schema);
    }
}

final class DepthLeaf
{
    public function __construct(public readonly string $v)
    {
    }
}

final class DepthThird
{
    public function __construct(public readonly DepthLeaf $c)
    {
    }
}

final class DepthSecond
{
    public function __construct(public readonly DepthThird $b)
    {
    }
}

final class DepthRoot
{
    public function __construct(public readonly DepthSecond $a)
    {
    }
}

final class OnlyStaticMember
{
    public static int $instances = 0;
}

final class InlineDefaults
{
    public int $count = 5;

    public function __construct(public readonly string $label)
    {
    }
}

final class AllOptional
{
    public int $count = 1;
}
