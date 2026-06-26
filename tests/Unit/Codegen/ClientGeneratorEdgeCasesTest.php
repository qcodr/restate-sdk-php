<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Codegen;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Codegen\ClientGenerator;
use Restate\Sdk\Context\Context;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Service;
use Restate\Sdk\Service\Attribute\Shared;
use Restate\Sdk\Service\Attribute\Workflow;

/**
 * Generation for handler/return-type and service-name shapes that the happy-path
 * Greeter/Counter fixtures do not exercise: void output, mixed/untyped payloads,
 * workflow call variants, and service names that need sanitizing into class names.
 */
final class ClientGeneratorEdgeCasesTest extends TestCase
{
    public function testVoidHandlerEmitsVoidSyncMethodWithoutReturn(): void
    {
        $source = (new ClientGenerator())->generate(MixedShapesService::class);

        self::assertStringContainsString('public function notify(?string $idempotencyKey = null, array $headers = []): void', $source);
        // A void handler calls through without capturing or returning a result.
        self::assertStringContainsString("        \$this->ctx->serviceCall('Mixed', 'notify', null, \$idempotencyKey, \$headers);", $source);
        self::assertStringNotContainsString('$result = $this->ctx->serviceCall(\'Mixed\', \'notify\'', $source);
    }

    public function testMixedReturnIsReturnedDirectlyWithoutTypedLocal(): void
    {
        $source = (new ClientGenerator())->generate(MixedShapesService::class);

        self::assertStringContainsString('public function compute(?string $idempotencyKey = null, array $headers = []): mixed', $source);
        self::assertStringContainsString("        return \$this->ctx->serviceCall('Mixed', 'compute', null, \$idempotencyKey, \$headers);", $source);
        // No `@var`/typed local round-trip for a mixed result.
        self::assertStringNotContainsString('/** @var mixed $result */', $source);
    }

    public function testUntypedInputFallsBackToMixedParameterType(): void
    {
        $source = (new ClientGenerator())->generate(MixedShapesService::class);

        self::assertStringContainsString('public function raw(mixed $payload, ?string $idempotencyKey = null, array $headers = []): void', $source);
        self::assertStringContainsString("\$this->ctx->serviceCall('Mixed', 'raw', \$payload", $source);
    }

    public function testWorkflowGeneratesKeyedClientWithWorkflowCallVariants(): void
    {
        $source = (new ClientGenerator())->generate(OrderWorkflowFixture::class);

        self::assertStringContainsString('final class OrderClient', $source);
        self::assertStringContainsString('Typed Restate client for the "Order" workflow.', $source);
        self::assertStringContainsString('public static function fromContext(Context $ctx, string $key): self', $source);
        self::assertStringContainsString("\$this->ctx->workflowCall('Order', \$this->key, 'run', \$orderId", $source);
        self::assertStringContainsString("\$this->ctx->workflowCallAsync('Order', \$this->key, 'run', \$orderId", $source);
        self::assertStringContainsString("\$this->ctx->workflowSend('Order', \$this->key, 'run', \$orderId", $source);
        self::assertStringContainsString("\$this->ctx->workflowCall('Order', \$this->key, 'status', null", $source);
    }

    public function testServiceNameWithoutAlphanumericsFallsBackToServiceClient(): void
    {
        $generator = new ClientGenerator();

        self::assertSame('ServiceClient', $generator->clientClassName(SymbolNamedService::class));
        self::assertStringContainsString('final class ServiceClient', $generator->generate(SymbolNamedService::class));
    }

    public function testServiceNameStartingWithDigitIsPrefixedWithUnderscore(): void
    {
        $generator = new ClientGenerator();

        self::assertSame('_123startClient', $generator->clientClassName(DigitNamedService::class));
        self::assertStringContainsString('final class _123startClient', $generator->generate(DigitNamedService::class));
    }
}

#[Service(name: 'Mixed')]
final class MixedShapesService
{
    #[Handler]
    public function notify(Context $ctx): void
    {
    }

    #[Handler]
    public function compute(Context $ctx): mixed
    {
        return null;
    }

    /**
     * @param int|string $payload a deliberately union-typed input so reflection yields
     *                            no single named type and the generator falls back to mixed
     */
    #[Handler]
    public function raw(Context $ctx, int|string $payload): void
    {
    }
}

#[Workflow(name: 'Order')]
final class OrderWorkflowFixture
{
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
}

#[Service(name: '***')]
final class SymbolNamedService
{
    #[Handler]
    public function go(Context $ctx): void
    {
    }
}

#[Service(name: '123-start')]
final class DigitNamedService
{
    #[Handler]
    public function go(Context $ctx): void
    {
    }
}
