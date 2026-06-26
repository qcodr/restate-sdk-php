<?php

declare(strict_types=1);

namespace Restate\Sdk\Service;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Service;
use Restate\Sdk\Service\Attribute\Shared;
use Restate\Sdk\Service\Attribute\VirtualObject;
use Restate\Sdk\Service\Attribute\Workflow;

/**
 * A discovered service: its Restate name, type, the user instance handling
 * invocations, and the handlers reflected from its attributes.
 *
 * {@see fromObject} reads the class-level service attribute and the method-level
 * {@see Handler} / {@see Shared} attributes once, so the reflection cost is paid at
 * registration time rather than per invocation.
 */
final class ServiceDefinition
{
    /**
     * @param array<string, HandlerDefinition> $handlers keyed by Restate handler name
     */
    private function __construct(
        public readonly string $name,
        public readonly ServiceType $type,
        public readonly object $instance,
        public readonly array $handlers,
    ) {
    }

    public static function fromObject(object $instance): self
    {
        $reflection = new ReflectionClass($instance);
        [$type, $configuredName] = self::resolveServiceType($reflection);
        $name = $configuredName ?? $reflection->getShortName();

        self::assertStateless($reflection, $name);

        $handlers = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $handler = self::resolveHandler($method, $type);
            if ($handler !== null) {
                $handlers[$handler->name] = $handler;
            }
        }

        if ($handlers === []) {
            throw new ServiceDefinitionException(
                "Service '{$name}' declares no handlers; mark methods with #[Handler] or #[Shared]",
            );
        }

        return new self($name, $type, $instance, $handlers);
    }

    public function handler(string $name): ?HandlerDefinition
    {
        return $this->handlers[$name] ?? null;
    }

    /**
     * Rejects mutable public/protected instance properties at registration.
     *
     * A single service instance is shared across the concurrent coroutines a worker
     * runs, so mutable instance state is a data race between invocations. Public and
     * protected mutable properties are almost always an accidental footgun, so fail
     * fast with a clear message rather than corrupting silently at runtime.
     *
     * `readonly` and static properties are always allowed. Private mutable state is
     * left to the author's discretion: a deliberate single-worker counter is a valid
     * (if advanced) pattern, so the check is scoped to the public/protected surface.
     *
     * @param ReflectionClass<object> $reflection
     */
    private static function assertStateless(ReflectionClass $reflection, string $name): void
    {
        $visible = ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED;
        foreach ($reflection->getProperties($visible) as $property) {
            if ($property->isStatic() || $property->isReadOnly()) {
                continue;
            }

            throw new ServiceDefinitionException(\sprintf(
                "Service '%s' has a mutable %s property \$%s; service instances are shared across "
                . 'concurrent invocations, so mutable instance state is a data race. Make it readonly, '
                . 'or keep per-invocation state in a handler local or in Restate state.',
                $name,
                $property->isPublic() ? 'public' : 'protected',
                $property->getName(),
            ));
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return array{0: ServiceType, 1: ?string}
     */
    private static function resolveServiceType(ReflectionClass $reflection): array
    {
        foreach ($reflection->getAttributes(Service::class) as $attribute) {
            return [ServiceType::Service, $attribute->newInstance()->name];
        }
        foreach ($reflection->getAttributes(VirtualObject::class) as $attribute) {
            return [ServiceType::VirtualObject, $attribute->newInstance()->name];
        }
        foreach ($reflection->getAttributes(Workflow::class) as $attribute) {
            return [ServiceType::Workflow, $attribute->newInstance()->name];
        }

        throw new ServiceDefinitionException(\sprintf(
            'Class %s is not a Restate service; add #[Service], #[VirtualObject] or #[Workflow]',
            $reflection->getName(),
        ));
    }

    private static function resolveHandler(ReflectionMethod $method, ServiceType $serviceType): ?HandlerDefinition
    {
        $handlerAttr = $method->getAttributes(Handler::class)[0] ?? null;
        $sharedAttr = $method->getAttributes(Shared::class)[0] ?? null;
        if ($handlerAttr === null && $sharedAttr === null) {
            return null;
        }
        if ($handlerAttr !== null && $sharedAttr !== null) {
            throw new ServiceDefinitionException(
                "Method {$method->getName()} cannot be both #[Handler] and #[Shared]",
            );
        }

        $isShared = $sharedAttr !== null;
        $name = ($isShared ? $sharedAttr->newInstance()->name : $handlerAttr->newInstance()->name)
            ?? $method->getName();

        if ($method->getNumberOfParameters() < 1) {
            throw new ServiceDefinitionException(
                "Handler {$method->getName()} must accept a context as its first parameter",
            );
        }

        $parameters = $method->getParameters();
        $hasInput = \count($parameters) >= 2;
        $inputType = $hasInput ? self::typeName($parameters[1]->getType()) : null;

        $returnType = $method->getReturnType();
        $returnTypeName = self::typeName($returnType);
        $hasOutput = $returnTypeName !== null && $returnTypeName !== 'void' && $returnTypeName !== 'never';

        return new HandlerDefinition(
            $name,
            self::handlerType($serviceType, $isShared),
            $method->getName(),
            $hasInput,
            $inputType,
            $hasOutput,
            $hasOutput ? $returnTypeName : null,
        );
    }

    private static function handlerType(ServiceType $serviceType, bool $isShared): ?HandlerType
    {
        return match ($serviceType) {
            ServiceType::Service => null,
            ServiceType::VirtualObject => $isShared ? HandlerType::Shared : HandlerType::Exclusive,
            ServiceType::Workflow => $isShared ? HandlerType::Shared : HandlerType::Workflow,
        };
    }

    private static function typeName(?ReflectionType $type): ?string
    {
        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }
}
