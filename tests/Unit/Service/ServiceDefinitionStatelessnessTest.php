<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Context\Context;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Service;
use Restate\Sdk\Service\ServiceDefinition;
use Restate\Sdk\Service\ServiceDefinitionException;
use Restate\Sdk\Tests\Support\Fixtures\Greeter;
use Restate\Sdk\Tests\Support\Fixtures\RunService;
use Restate\Sdk\Tests\Support\Fixtures\StatefulService;

/**
 * Item #3: a service instance is shared across concurrent invocations, so mutable
 * public/protected instance state is a data race. Registration rejects it; readonly,
 * static and private state stay allowed.
 */
final class ServiceDefinitionStatelessnessTest extends TestCase
{
    public function testRejectsMutablePublicProperty(): void
    {
        $this->expectException(ServiceDefinitionException::class);
        $this->expectExceptionMessage('mutable public property $counter');

        ServiceDefinition::fromObject(new StatefulService());
    }

    public function testRejectsMutableProtectedProperty(): void
    {
        $service = new #[Service] class () {
            protected int $hits = 0;

            #[Handler]
            public function run(Context $ctx): string
            {
                return (string) $this->hits;
            }
        };

        $this->expectException(ServiceDefinitionException::class);
        $this->expectExceptionMessage('mutable protected property $hits');

        ServiceDefinition::fromObject($service);
    }

    public function testAcceptsPrivateMutableState(): void
    {
        // A deliberate single-worker counter is the author's call; the check is scoped
        // to the public/protected surface and must not reject private state.
        self::assertSame('RunService', ServiceDefinition::fromObject(new RunService())->name);
    }

    public function testAcceptsReadonlyPublicProperty(): void
    {
        $service = new #[Service] class ('cfg') {
            public function __construct(public readonly string $config)
            {
            }

            #[Handler]
            public function run(Context $ctx): string
            {
                return $this->config;
            }
        };

        self::assertNotNull(ServiceDefinition::fromObject($service)->handler('run'));
    }

    public function testAcceptsStatelessService(): void
    {
        self::assertNotNull(ServiceDefinition::fromObject(new Greeter())->handler('greet'));
    }
}
