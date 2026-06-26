<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Service\Attribute;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Service\Attribute\Workflow;

final class WorkflowTest extends TestCase
{
    public function testDefaultsToNoExplicitName(): void
    {
        self::assertNull((new Workflow())->name);
    }

    public function testRetainsConfiguredName(): void
    {
        self::assertSame('OrderFlow', (new Workflow('OrderFlow'))->name);
        self::assertSame('OrderFlow', (new Workflow(name: 'OrderFlow'))->name);
    }
}
