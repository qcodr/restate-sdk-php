<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Discovery\ManifestBuilder;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;
use Qcodr\Restate\Sdk\Service\HandlerOptions;
use Qcodr\Restate\Sdk\Service\RetryPolicyOnMaxAttempts;
use Qcodr\Restate\Sdk\Service\ServiceDefinition;
use Qcodr\Restate\Sdk\Service\ServiceOptions;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Counter;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Greeter;

final class ManifestOptionsTest extends TestCase
{
    /**
     * Service-level option keys that must never appear in a v1 manifest.
     *
     * @var list<string>
     */
    private const SERVICE_OPTION_KEYS = [
        'documentation',
        'metadata',
        'inactivityTimeout',
        'abortTimeout',
        'journalRetention',
        'idempotencyRetention',
        'enableLazyState',
        'ingressPrivate',
        'retryPolicyInitialInterval',
        'retryPolicyMaxInterval',
        'retryPolicyMaxAttempts',
        'retryPolicyExponentiationFactor',
        'retryPolicyOnMaxAttempts',
    ];

    private function greeterOptions(): ServiceOptions
    {
        $options = new ServiceOptions(
            inactivityTimeoutMillis: 60000,
            abortTimeoutMillis: 120000,
            journalRetentionMillis: 3600000,
            idempotencyRetentionMillis: 86400000,
            enableLazyState: true,
            ingressPrivate: false,
            documentation: 'Greets people.',
            metadata: ['team' => 'core'],
            retryPolicyInitialIntervalMillis: 100,
            retryPolicyMaxIntervalMillis: 10000,
            retryPolicyMaxAttempts: 5,
            retryPolicyExponentiationFactor: 2.5,
            retryPolicyOnMaxAttempts: RetryPolicyOnMaxAttempts::Pause,
        );

        return $options->withHandler('greet', new HandlerOptions(
            abortTimeoutMillis: 999,
            workflowCompletionRetentionMillis: 5000,
            documentation: 'Greet handler.',
            metadata: ['kind' => 'greet'],
            retryPolicyMaxAttempts: 3,
            retryPolicyOnMaxAttempts: RetryPolicyOnMaxAttempts::Kill,
        ));
    }

    /**
     * Runs the discovery route end-to-end and returns the manifest services indexed by name.
     *
     * @return array<string, array<mixed>>
     */
    private function discover(int $manifestVersion): array
    {
        $endpoint = Endpoint::builder()
            ->bindWithOptions(new Greeter(), $this->greeterOptions())
            ->bind(new Counter())
            ->build();

        $accept = "application/vnd.restate.endpointmanifest.v{$manifestVersion}+json";
        $request = new HttpRequest('GET', '/discovery', ['accept' => $accept], '');
        $response = (new RequestProcessor($endpoint))->process($request);

        self::assertSame(200, $response->status);
        self::assertSame($accept, $response->headers['content-type']);

        $manifest = \json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertArrayHasKey('services', $manifest);

        return self::indexByName($manifest['services']);
    }

    public function testV4ManifestEmitsAllServiceOptionKeys(): void
    {
        $greeter = $this->discover(4);
        $service = $greeter['Greeter'];

        self::assertSame('Greets people.', $service['documentation']);
        self::assertSame(['team' => 'core'], $service['metadata']);
        self::assertSame(60000, $service['inactivityTimeout']);
        self::assertSame(120000, $service['abortTimeout']);
        self::assertSame(3600000, $service['journalRetention']);
        self::assertSame(86400000, $service['idempotencyRetention']);
        self::assertTrue($service['enableLazyState']);
        self::assertFalse($service['ingressPrivate']);
        self::assertSame(100, $service['retryPolicyInitialInterval']);
        self::assertSame(10000, $service['retryPolicyMaxInterval']);
        self::assertSame(5, $service['retryPolicyMaxAttempts']);
        self::assertSame(2.5, $service['retryPolicyExponentiationFactor']);
        self::assertSame('PAUSE', $service['retryPolicyOnMaxAttempts']);
    }

    public function testV4ManifestEmitsHandlerOptionKeys(): void
    {
        $greeter = $this->discover(4);
        $greet = self::handlerByName($greeter['Greeter'], 'greet');

        self::assertSame('Greet handler.', $greet['documentation']);
        self::assertSame(['kind' => 'greet'], $greet['metadata']);
        self::assertSame(999, $greet['abortTimeout']);
        self::assertSame(5000, $greet['workflowCompletionRetention']);
        self::assertSame(3, $greet['retryPolicyMaxAttempts']);
        self::assertSame('KILL', $greet['retryPolicyOnMaxAttempts']);
    }

    public function testV1ManifestOmitsAllOptionKeys(): void
    {
        $greeter = $this->discover(1);
        $service = $greeter['Greeter'];

        self::assertSame(['name', 'ty', 'handlers'], \array_keys($service));
        foreach (self::SERVICE_OPTION_KEYS as $key) {
            self::assertArrayNotHasKey($key, $service);
        }

        $greet = self::handlerByName($service, 'greet');
        self::assertArrayNotHasKey('documentation', $greet);
        self::assertArrayNotHasKey('metadata', $greet);
        self::assertArrayNotHasKey('abortTimeout', $greet);
        self::assertArrayNotHasKey('workflowCompletionRetention', $greet);
        self::assertArrayNotHasKey('retryPolicyMaxAttempts', $greet);
    }

    public function testV2ManifestEmitsOnlyMetadataAndDocumentation(): void
    {
        $service = $this->buildDirect(2)['Greeter'];

        self::assertSame('Greets people.', $service['documentation']);
        self::assertSame(['team' => 'core'], $service['metadata']);
        self::assertArrayNotHasKey('inactivityTimeout', $service);
        self::assertArrayNotHasKey('retryPolicyInitialInterval', $service);
    }

    public function testV3ManifestEmitsTimeoutsButNotRetryPolicy(): void
    {
        $service = $this->buildDirect(3)['Greeter'];

        self::assertSame('Greets people.', $service['documentation']);
        self::assertSame(60000, $service['inactivityTimeout']);
        self::assertSame(86400000, $service['idempotencyRetention']);
        self::assertTrue($service['enableLazyState']);
        self::assertArrayNotHasKey('retryPolicyInitialInterval', $service);
        self::assertArrayNotHasKey('retryPolicyOnMaxAttempts', $service);

        $greet = self::handlerByName($service, 'greet');
        self::assertSame(5000, $greet['workflowCompletionRetention']);
        self::assertArrayNotHasKey('retryPolicyMaxAttempts', $greet);
    }

    public function testServiceWithoutOptionsHasNoOptionKeys(): void
    {
        $counter = $this->discover(4)['Counter'];

        self::assertSame(['name', 'ty', 'handlers'], \array_keys($counter));
    }

    /**
     * Builds the manifest directly through {@see ManifestBuilder} at the given version.
     *
     * @return array<string, array<mixed>>
     */
    private function buildDirect(int $version): array
    {
        $builder = new ManifestBuilder();
        $manifest = $builder->build(
            [ServiceDefinition::fromObject(new Greeter())],
            $version,
            ['Greeter' => $this->greeterOptions()],
        );

        return self::indexByName($manifest['services']);
    }

    /**
     * @param array<mixed> $service
     *
     * @return array<mixed>
     */
    private static function handlerByName(array $service, string $name): array
    {
        self::assertArrayHasKey('handlers', $service);

        return self::indexByName($service['handlers'])[$name];
    }

    /**
     * Indexes a list of `{name: ...}` entries by their name.
     *
     * @return array<string, array<mixed>>
     */
    private static function indexByName(mixed $entries): array
    {
        self::assertIsArray($entries);

        $indexed = [];
        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            self::assertArrayHasKey('name', $entry);
            self::assertIsString($entry['name']);
            $indexed[$entry['name']] = $entry;
        }

        return $indexed;
    }
}
