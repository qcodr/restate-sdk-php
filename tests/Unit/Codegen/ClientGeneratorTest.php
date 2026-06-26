<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Codegen;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Codegen\ClientGenerator;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Counter;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Greeter;
use ReflectionClass;

final class ClientGeneratorTest extends TestCase
{
    public function testGeneratesFinalClientClassForService(): void
    {
        $source = (new ClientGenerator())->generate(Greeter::class);

        self::assertStringContainsString('namespace Restate\\Generated;', $source);
        self::assertStringContainsString('final class GreeterClient', $source);
        self::assertStringContainsString('public function greet(', $source);
    }

    public function testServiceFactoryTakesContextOnly(): void
    {
        $source = (new ClientGenerator())->generate(Greeter::class);

        self::assertStringContainsString('public static function fromContext(Context $ctx): self', $source);
        self::assertStringNotContainsString('fromContext(Context $ctx, string $key)', $source);
    }

    public function testServiceMethodsDelegateToServiceCallVariants(): void
    {
        $source = (new ClientGenerator())->generate(Greeter::class);

        self::assertStringContainsString("\$this->ctx->serviceCall('Greeter', 'greet', \$name", $source);
        self::assertStringContainsString('public function greetAsync(', $source);
        self::assertStringContainsString("\$this->ctx->serviceCallAsync('Greeter', 'greet', \$name", $source);
        self::assertStringContainsString('public function greetSend(', $source);
        self::assertStringContainsString("\$this->ctx->serviceSend('Greeter', 'greet', \$name", $source);
    }

    public function testVirtualObjectFactoryTakesKey(): void
    {
        $source = (new ClientGenerator())->generate(Counter::class);

        self::assertStringContainsString('final class CounterClient', $source);
        self::assertStringContainsString('public static function fromContext(Context $ctx, string $key): self', $source);
        self::assertStringContainsString("\$this->ctx->objectCall('Counter', \$this->key, 'add', \$delta", $source);
    }

    public function testNoInputHandlerPassesNullPayload(): void
    {
        $source = (new ClientGenerator())->generate(Counter::class);

        self::assertStringContainsString('public function get(?string $idempotencyKey = null, array $headers = []): int', $source);
        self::assertStringContainsString("\$this->ctx->objectCall('Counter', \$this->key, 'get', null", $source);
    }

    public function testGeneratedSourceCarriesStrictTypes(): void
    {
        $source = (new ClientGenerator())->generate(Greeter::class);

        self::assertStringStartsWith('<?php', $source);
        self::assertStringContainsString('declare(strict_types=1);', $source);
    }

    public function testCustomNamespaceIsHonored(): void
    {
        $source = (new ClientGenerator('App\\Generated\\Clients'))->generate(Greeter::class);

        self::assertStringContainsString('namespace App\\Generated\\Clients;', $source);
    }

    public function testClientClassNameMatchesServiceName(): void
    {
        $generator = new ClientGenerator();

        self::assertSame('GreeterClient', $generator->clientClassName(Greeter::class));
        self::assertSame('CounterClient', $generator->clientClassName(Counter::class));
    }

    public function testUnknownClassIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ClientGenerator())->generate('Restate\\Nope\\Missing');
    }

    public function testGeneratedSourceEvaluatesIntoAUsableClass(): void
    {
        $namespace = 'Qcodr\\Restate\\Sdk\\Tests\\Tmp\\Gen' . \bin2hex(\random_bytes(6));
        $source = (new ClientGenerator($namespace))->generate(Counter::class);

        eval($this->stripPreambleForEval($source));

        $fqcn = $namespace . '\\CounterClient';
        self::assertTrue(\class_exists($fqcn));

        $reflection = new ReflectionClass($fqcn);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->getConstructor()?->isPrivate());
        self::assertTrue($reflection->hasMethod('fromContext'));
        self::assertTrue($reflection->getMethod('fromContext')->isStatic());

        foreach (['add', 'addAsync', 'addSend', 'get', 'getAsync', 'getSend'] as $method) {
            self::assertTrue($reflection->hasMethod($method), "missing method {$method}");
        }

        self::assertSame('int', (string) $reflection->getMethod('add')->getReturnType());
        self::assertSame('void', (string) $reflection->getMethod('addSend')->getReturnType());
    }

    /**
     * Strips the `<?php` tag and the file-level `declare(strict_types=1);` so the
     * remaining namespace + class can be {@see eval}'d (both are illegal mid-script).
     */
    private function stripPreambleForEval(string $source): string
    {
        $withoutTag = \preg_replace('/^<\?php\s*/', '', $source) ?? $source;

        return \preg_replace('/declare\(strict_types=1\);\s*/', '', $withoutTag, 1) ?? $withoutTag;
    }
}
