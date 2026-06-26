<?php

declare(strict_types=1);

namespace Restate\Sdk\Discovery;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use stdClass;

/**
 * Derives a JSON Schema (draft 2020-12) descriptor from a handler's PHP input or
 * output type name, for inclusion as `input.jsonSchema` / `output.jsonSchema` in the
 * discovery manifest.
 *
 * Scalars map to their JSON counterparts (`int` → integer, `float` → number,
 * `string` → string, `bool` → boolean). A bare `array` becomes a generic object,
 * since PHP arrays carry no structural hints. A class name is reflected into an
 * object schema built from its public typed properties and promoted constructor
 * parameters, recursing into nested classes up to {@see MAX_DEPTH} levels to bound
 * cyclic graphs.
 *
 * Types that yield no useful structure (`mixed`, untyped, `null`, unions, interfaces)
 * produce `null` so the caller can omit the key entirely rather than emitting a
 * meaningless schema.
 */
final class JsonSchemaGenerator
{
    private const SCHEMA_DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    /**
     * Maximum class-nesting depth expanded before falling back to a generic object.
     */
    private const MAX_DEPTH = 3;

    /**
     * Builds a self-contained JSON Schema for the given PHP type name, or returns
     * `null` when the type cannot be described meaningfully.
     *
     * @return array<string, mixed>|null
     */
    public function forType(?string $phpType): ?array
    {
        $schema = $this->schemaFor($phpType, 0);
        if ($schema === null) {
            return null;
        }

        return ['$schema' => self::SCHEMA_DIALECT] + $schema;
    }

    /**
     * Resolves a schema fragment (without the top-level `$schema` dialect key) for a
     * PHP type at the given recursion depth.
     *
     * @return array<string, mixed>|null
     */
    private function schemaFor(?string $phpType, int $depth): ?array
    {
        if ($phpType === null) {
            return null;
        }

        $scalar = self::scalarSchema($phpType);
        if ($scalar !== null) {
            return $scalar;
        }

        if ($phpType === 'array') {
            return ['type' => 'object'];
        }

        if (\class_exists($phpType)) {
            return $this->classSchema($phpType, $depth);
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private static function scalarSchema(string $phpType): ?array
    {
        return match ($phpType) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'string' => ['type' => 'string'],
            'bool' => ['type' => 'boolean'],
            default => null,
        };
    }

    /**
     * Reflects a class into an object schema over its public typed members.
     *
     * @param class-string $phpType
     *
     * @return array<string, mixed>
     */
    private function classSchema(string $phpType, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['type' => 'object'];
        }

        $reflection = new ReflectionClass($phpType);
        $promoted = self::promotedParameters($reflection);

        $properties = [];
        $required = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || !$property->hasType()) {
                continue;
            }

            $name = $property->getName();
            $type = $property->getType();

            $properties[$name] = $this->schemaFor(self::typeName($type), $depth + 1) ?? new stdClass();

            if (!self::isOptional($type, $property, $promoted[$name] ?? null)) {
                $required[] = $name;
            }
        }

        if ($properties === []) {
            return ['type' => 'object'];
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Maps the promoted constructor parameters of a class by parameter name, so that
     * default values declared on the constructor (which do not surface through
     * {@see ReflectionProperty::hasDefaultValue()}) can drive optionality.
     *
     * @param ReflectionClass<object> $reflection
     *
     * @return array<string, ReflectionParameter>
     */
    private static function promotedParameters(ReflectionClass $reflection): array
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $promoted = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isPromoted()) {
                $promoted[$parameter->getName()] = $parameter;
            }
        }

        return $promoted;
    }

    /**
     * A member is optional when its type is nullable or it carries a default value,
     * either on the property itself or on its promoted constructor parameter.
     */
    private static function isOptional(
        ?ReflectionType $type,
        ReflectionProperty $property,
        ?ReflectionParameter $parameter,
    ): bool {
        if ($type !== null && $type->allowsNull()) {
            return true;
        }

        if ($property->hasDefaultValue()) {
            return true;
        }

        return $parameter !== null && $parameter->isDefaultValueAvailable();
    }

    private static function typeName(?ReflectionType $type): ?string
    {
        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }
}
