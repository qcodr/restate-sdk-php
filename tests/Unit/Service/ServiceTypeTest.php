<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Service\ServiceType;

/**
 * The service kind drives whether the manifest advertises per-key state and a key:
 * plain Services have neither, Virtual Objects and Workflows have both.
 */
final class ServiceTypeTest extends TestCase
{
    public function testWireValuesMatchTheManifestEnum(): void
    {
        self::assertSame('SERVICE', ServiceType::Service->value);
        self::assertSame('VIRTUAL_OBJECT', ServiceType::VirtualObject->value);
        self::assertSame('WORKFLOW', ServiceType::Workflow->value);
    }

    public function testOnlyKeyedKindsHaveState(): void
    {
        self::assertFalse(ServiceType::Service->hasState());
        self::assertTrue(ServiceType::VirtualObject->hasState());
        self::assertTrue(ServiceType::Workflow->hasState());
    }

    public function testOnlyKeyedKindsHaveKey(): void
    {
        self::assertFalse(ServiceType::Service->hasKey());
        self::assertTrue(ServiceType::VirtualObject->hasKey());
        self::assertTrue(ServiceType::Workflow->hasKey());
    }
}
