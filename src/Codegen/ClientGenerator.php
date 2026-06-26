<?php

declare(strict_types=1);

namespace Restate\Sdk\Codegen;

use InvalidArgumentException;
use ReflectionClass;
use Restate\Sdk\Service\HandlerDefinition;
use Restate\Sdk\Service\ServiceDefinition;
use Restate\Sdk\Service\ServiceType;

/**
 * Generates typed, IDE-autocompletable PHP client classes for Restate services.
 *
 * Given a service class, {@see generate} reflects its {@see ServiceDefinition}
 * (Restate name, service type, handlers) and emits the source of a
 * `{ServiceName}Client` that delegates each handler to the matching
 * {@see \Restate\Sdk\Context\Context} call method. Callers then write
 * `GreeterClient::fromContext($ctx)->greet('world')` instead of the
 * stringly-typed `$ctx->serviceCall('Greeter', 'greet', 'world')`.
 *
 * The emitted source is strict-typed, formatted PHP and never instantiates the
 * service under inspection (it reflects attributes only), so services with
 * constructor dependencies generate cleanly.
 */
final class ClientGenerator
{
    /**
     * Parameter names reserved by the generated call signatures; a handler input
     * sharing one of these is renamed to avoid a collision.
     *
     * @var list<string>
     */
    private const RESERVED_PARAMS = [
        'ctx',
        'key',
        'this',
        'result',
        'headers',
        'idempotencyKey',
        'delaySeconds',
    ];

    /** Scalar and pseudo types emitted verbatim; everything else is a class FQCN. */
    private const BUILTIN_TYPES = [
        'string',
        'int',
        'float',
        'bool',
        'array',
        'mixed',
        'object',
        'callable',
        'iterable',
        'void',
        'never',
        'null',
        'true',
        'false',
        'self',
        'static',
        'parent',
    ];

    private readonly string $namespace;

    public function __construct(string $namespace = 'Restate\\Generated')
    {
        $this->namespace = \trim($namespace, '\\');
    }

    /**
     * Returns the PHP source of the generated client for the given service class.
     *
     * @param string $serviceClass fully-qualified, autoloadable service class name
     *
     * @throws InvalidArgumentException when the class does not exist or is not a service
     */
    public function generate(string $serviceClass): string
    {
        $reflection = $this->reflect($serviceClass);
        $definition = ServiceDefinition::fromObject($reflection->newInstanceWithoutConstructor());

        return $this->renderClass($serviceClass, $definition, $reflection);
    }

    /**
     * The short class name of the client that {@see generate} would emit, e.g.
     * `GreeterClient` — used to name the output file.
     */
    public function clientClassName(string $serviceClass): string
    {
        $definition = ServiceDefinition::fromObject(
            $this->reflect($serviceClass)->newInstanceWithoutConstructor(),
        );

        return $this->classNameFor($definition->name);
    }

    /**
     * @return ReflectionClass<object>
     *
     * @throws InvalidArgumentException
     */
    private function reflect(string $serviceClass): ReflectionClass
    {
        if (!\class_exists($serviceClass)) {
            throw new InvalidArgumentException(\sprintf(
                'Service class "%s" does not exist or is not autoloadable.',
                $serviceClass,
            ));
        }

        return new ReflectionClass($serviceClass);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function renderClass(string $serviceClass, ServiceDefinition $definition, ReflectionClass $reflection): string
    {
        $className = $this->classNameFor($definition->name);

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            \sprintf('namespace %s;', $this->namespace),
            '',
            'use Restate\\Sdk\\Context\\Context;',
            'use Restate\\Sdk\\Context\\DurableFuture;',
            '',
            '/**',
            \sprintf(' * Typed Restate client for the "%s" %s.', $definition->name, $this->kindLabel($definition->type)),
            ' *',
            \sprintf(' * Generated from %s by Restate\\Sdk\\Codegen\\ClientGenerator;', $serviceClass),
            ' * do not edit by hand — re-run restate-codegen to regenerate.',
            ' */',
            \sprintf('final class %s', $className),
            '{',
        ];

        $lines = [...$lines, ...$this->renderConstructor($definition->type)];

        foreach ($definition->handlers as $handler) {
            $lines[] = '';
            $lines[] = $this->renderHandlerMethods($definition, $handler, $this->inputParamName($reflection, $handler));
        }

        $lines[] = '}';
        $lines[] = '';

        return \implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function renderConstructor(ServiceType $type): array
    {
        if (!$type->hasKey()) {
            return [
                '    private function __construct(',
                '        private readonly Context $ctx,',
                '    ) {',
                '    }',
                '',
                '    public static function fromContext(Context $ctx): self',
                '    {',
                '        return new self($ctx);',
                '    }',
            ];
        }

        return [
            '    private function __construct(',
            '        private readonly Context $ctx,',
            '        private readonly string $key,',
            '    ) {',
            '    }',
            '',
            '    public static function fromContext(Context $ctx, string $key): self',
            '    {',
            '        return new self($ctx, $key);',
            '    }',
        ];
    }

    private function renderHandlerMethods(ServiceDefinition $definition, HandlerDefinition $handler, ?string $inputName): string
    {
        $targetArgs = \sprintf(
            "'%s'%s, '%s'",
            $definition->name,
            $definition->type->hasKey() ? ', $this->key' : '',
            $handler->name,
        );
        $inputType = $handler->hasInput ? $this->phpType($handler->inputType) : '';
        $inputArg = $inputName !== null ? '$' . $inputName : 'null';

        $callBase = $this->callMethod($definition->type);
        $sendMethod = $this->sendMethod($definition->type);

        return \implode("\n\n", [
            $this->renderSyncMethod($handler, $callBase, $targetArgs, $inputName, $inputType, $inputArg),
            $this->renderAsyncMethod($handler, $callBase, $targetArgs, $inputName, $inputType, $inputArg),
            $this->renderSendMethod($handler, $sendMethod, $targetArgs, $inputName, $inputType, $inputArg),
        ]);
    }

    private function renderSyncMethod(
        HandlerDefinition $handler,
        string $callBase,
        string $targetArgs,
        ?string $inputName,
        string $inputType,
        string $inputArg,
    ): string {
        $signature = $this->signature($inputName, $inputType, false);
        $callExpr = \sprintf('$this->ctx->%s(%s, %s, $idempotencyKey, $headers)', $callBase, $targetArgs, $inputArg);

        $lines = $this->docComment(\sprintf('Calls the "%s" handler and awaits its result.', $handler->name));

        if (!$handler->hasOutput) {
            $lines[] = \sprintf('    public function %s(%s): void', $handler->name, $signature);
            $lines[] = '    {';
            $lines[] = '        ' . $callExpr . ';';
            $lines[] = '    }';

            return \implode("\n", $lines);
        }

        $outputType = $this->phpType($handler->outputType);
        $lines[] = \sprintf('    public function %s(%s): %s', $handler->name, $signature, $outputType);
        $lines[] = '    {';

        if ($outputType === 'mixed') {
            $lines[] = '        return ' . $callExpr . ';';
        } else {
            $lines[] = \sprintf('        /** @var %s $result */', $outputType);
            $lines[] = '        $result = ' . $callExpr . ';';
            $lines[] = '';
            $lines[] = '        return $result;';
        }

        $lines[] = '    }';

        return \implode("\n", $lines);
    }

    private function renderAsyncMethod(
        HandlerDefinition $handler,
        string $callBase,
        string $targetArgs,
        ?string $inputName,
        string $inputType,
        string $inputArg,
    ): string {
        $signature = $this->signature($inputName, $inputType, false);
        $callExpr = \sprintf('$this->ctx->%sAsync(%s, %s, $idempotencyKey, $headers)', $callBase, $targetArgs, $inputArg);

        $lines = $this->docComment(\sprintf(
            'Calls the "%s" handler without awaiting it, for concurrent composition.',
            $handler->name,
        ));
        $lines[] = \sprintf('    public function %sAsync(%s): DurableFuture', $handler->name, $signature);
        $lines[] = '    {';
        $lines[] = '        return ' . $callExpr . ';';
        $lines[] = '    }';

        return \implode("\n", $lines);
    }

    private function renderSendMethod(
        HandlerDefinition $handler,
        string $sendMethod,
        string $targetArgs,
        ?string $inputName,
        string $inputType,
        string $inputArg,
    ): string {
        $signature = $this->signature($inputName, $inputType, true);
        $callExpr = \sprintf(
            '$this->ctx->%s(%s, %s, $delaySeconds, $idempotencyKey, $headers)',
            $sendMethod,
            $targetArgs,
            $inputArg,
        );

        $lines = $this->docComment(\sprintf(
            'Sends a one-way request to the "%s" handler (fire-and-forget).',
            $handler->name,
        ));
        $lines[] = \sprintf('    public function %sSend(%s): void', $handler->name, $signature);
        $lines[] = '    {';
        $lines[] = '        ' . $callExpr . ';';
        $lines[] = '    }';

        return \implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function docComment(string $summary): array
    {
        return [
            '    /**',
            '     * ' . $summary,
            '     *',
            '     * @param array<string, string> $headers extra request headers forwarded to the callee',
            '     */',
        ];
    }

    private function signature(?string $inputName, string $inputType, bool $isSend): string
    {
        $parts = [];
        if ($inputName !== null) {
            $parts[] = \sprintf('%s $%s', $inputType, $inputName);
        }
        if ($isSend) {
            $parts[] = 'float $delaySeconds = 0.0';
        }
        $parts[] = '?string $idempotencyKey = null';
        $parts[] = 'array $headers = []';

        return \implode(', ', $parts);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function inputParamName(ReflectionClass $reflection, HandlerDefinition $handler): ?string
    {
        if (!$handler->hasInput) {
            return null;
        }

        $parameters = $reflection->getMethod($handler->method)->getParameters();
        $name = isset($parameters[1]) ? $parameters[1]->getName() : 'input';

        return \in_array($name, self::RESERVED_PARAMS, true) || $name === '' ? 'input' : $name;
    }

    private function callMethod(ServiceType $type): string
    {
        return match ($type) {
            ServiceType::Service => 'serviceCall',
            ServiceType::VirtualObject => 'objectCall',
            ServiceType::Workflow => 'workflowCall',
        };
    }

    private function sendMethod(ServiceType $type): string
    {
        return match ($type) {
            ServiceType::Service => 'serviceSend',
            ServiceType::VirtualObject => 'objectSend',
            ServiceType::Workflow => 'workflowSend',
        };
    }

    private function kindLabel(ServiceType $type): string
    {
        return match ($type) {
            ServiceType::Service => 'service',
            ServiceType::VirtualObject => 'virtual object',
            ServiceType::Workflow => 'workflow',
        };
    }

    private function phpType(?string $name): string
    {
        if ($name === null) {
            return 'mixed';
        }

        return \in_array($name, self::BUILTIN_TYPES, true) ? $name : '\\' . $name;
    }

    private function classNameFor(string $serviceName): string
    {
        $base = \preg_replace('/[^A-Za-z0-9_]/', '', $serviceName) ?? '';
        if ($base === '') {
            $base = 'Service';
        }
        if (\preg_match('/^\d/', $base) === 1) {
            $base = '_' . $base;
        }

        return $base . 'Client';
    }
}
