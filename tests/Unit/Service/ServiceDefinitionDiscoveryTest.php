<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;
use Qcodr\Restate\Sdk\Service\Attribute\Shared;
use Qcodr\Restate\Sdk\Service\Attribute\VirtualObject;
use Qcodr\Restate\Sdk\Service\Attribute\Workflow;
use Qcodr\Restate\Sdk\Service\HandlerType;
use Qcodr\Restate\Sdk\Service\ServiceDefinition;
use Qcodr\Restate\Sdk\Service\ServiceDefinitionException;
use Qcodr\Restate\Sdk\Service\ServiceType;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Counter;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Greeter;

/**
 * Reflection-driven discovery: every service kind, handler-type mapping, and the
 * registration-time guards that reject malformed services.
 */
final class ServiceDefinitionDiscoveryTest extends TestCase
{
    public function testServiceAttributeNameOverridesShortClassName(): void
    {
        $service = new #[Service(name: 'CustomName')] class () {
            #[Handler]
            public function run(Context $ctx): string
            {
                return 'ok';
            }
        };

        $definition = ServiceDefinition::fromObject($service);

        self::assertSame('CustomName', $definition->name);
        self::assertSame(ServiceType::Service, $definition->type);

        $run = $definition->handler('run');
        self::assertNotNull($run);
        // Plain services leave the handler type unset.
        self::assertNull($run->type);
    }

    public function testFallsBackToShortClassNameWhenAttributeOmitsName(): void
    {
        self::assertSame('Greeter', ServiceDefinition::fromObject(new Greeter())->name);
    }

    public function testVirtualObjectMapsExclusiveAndSharedHandlerTypes(): void
    {
        $definition = ServiceDefinition::fromObject(new Counter());

        self::assertSame(ServiceType::VirtualObject, $definition->type);

        $add = $definition->handler('add');
        $get = $definition->handler('get');
        self::assertNotNull($add);
        self::assertNotNull($get);

        self::assertSame(HandlerType::Exclusive, $add->type);
        self::assertFalse($add->isShared());
        self::assertSame(HandlerType::Shared, $get->type);
        self::assertTrue($get->isShared());
    }

    public function testWorkflowMapsRunAndSharedHandlerTypes(): void
    {
        $workflow = new #[Workflow(name: 'OrderFlow')] class () {
            #[Handler]
            public function run(Context $ctx, string $orderId): string
            {
                return $orderId;
            }

            #[Shared]
            public function status(Context $ctx): string
            {
                return 'pending';
            }
        };

        $definition = ServiceDefinition::fromObject($workflow);

        self::assertSame('OrderFlow', $definition->name);
        self::assertSame(ServiceType::Workflow, $definition->type);

        $run = $definition->handler('run');
        $status = $definition->handler('status');
        self::assertNotNull($run);
        self::assertNotNull($status);

        self::assertSame(HandlerType::Workflow, $run->type);
        self::assertSame(HandlerType::Shared, $status->type);
        self::assertTrue($status->isShared());
    }

    public function testSharedHandlerNameOverrideIsHonored(): void
    {
        $object = new #[VirtualObject] class () {
            #[Shared(name: 'peek')]
            public function read(Context $ctx): int
            {
                return 0;
            }
        };

        $definition = ServiceDefinition::fromObject($object);

        self::assertNotNull($definition->handler('peek'));
        self::assertNull($definition->handler('read'));
    }

    public function testHandlerLookupReturnsNullForUnknownName(): void
    {
        self::assertNull(ServiceDefinition::fromObject(new Greeter())->handler('does-not-exist'));
    }

    public function testInputAndOutputTypingIsReflectedFromTheSignature(): void
    {
        $greet = ServiceDefinition::fromObject(new Greeter())->handler('greet');
        self::assertNotNull($greet);

        self::assertTrue($greet->hasInput);
        self::assertSame('string', $greet->inputType);
        self::assertTrue($greet->hasOutput);
        self::assertSame('string', $greet->outputType);

        $get = ServiceDefinition::fromObject(new Counter())->handler('get');
        self::assertNotNull($get);

        self::assertFalse($get->hasInput);
        self::assertNull($get->inputType);
        self::assertTrue($get->hasOutput);
    }

    public function testVoidReturnIsTreatedAsNoOutput(): void
    {
        $service = new #[Service] class () {
            #[Handler]
            public function fire(Context $ctx): void
            {
            }
        };

        $fire = ServiceDefinition::fromObject($service)->handler('fire');
        self::assertNotNull($fire);

        self::assertFalse($fire->hasOutput);
        self::assertNull($fire->outputType);
    }

    public function testClassWithoutServiceAttributeIsRejected(): void
    {
        $plain = new class () {
            public function run(Context $ctx): void
            {
            }
        };

        $this->expectException(ServiceDefinitionException::class);
        $this->expectExceptionMessage('is not a Restate service');

        ServiceDefinition::fromObject($plain);
    }

    public function testServiceWithoutHandlersIsRejected(): void
    {
        $service = new #[Service(name: 'Empty')] class () {
            public function notAHandler(Context $ctx): void
            {
            }
        };

        $this->expectException(ServiceDefinitionException::class);
        $this->expectExceptionMessage("Service 'Empty' declares no handlers");

        ServiceDefinition::fromObject($service);
    }

    public function testMethodMarkedBothHandlerAndSharedIsRejected(): void
    {
        $service = new #[VirtualObject] class () {
            #[Handler]
            #[Shared]
            public function dup(Context $ctx): void
            {
            }
        };

        $this->expectException(ServiceDefinitionException::class);
        $this->expectExceptionMessage('cannot be both #[Handler] and #[Shared]');

        ServiceDefinition::fromObject($service);
    }

    public function testHandlerWithoutContextParameterIsRejected(): void
    {
        $service = new #[Service] class () {
            #[Handler]
            public function noContext(): void
            {
            }
        };

        $this->expectException(ServiceDefinitionException::class);
        $this->expectExceptionMessage('must accept a context as its first parameter');

        ServiceDefinition::fromObject($service);
    }
}
