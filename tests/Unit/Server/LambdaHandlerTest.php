<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Server;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Server\LambdaHandler;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Greeter;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;

final class LambdaHandlerTest extends TestCase
{
    private function handler(): LambdaHandler
    {
        $endpoint = Endpoint::builder()->bind(new Greeter())->build();

        return new LambdaHandler($endpoint);
    }

    /**
     * Builds an API Gateway v2 (HTTP API / Function URL) proxy event.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private static function eventV2(string $method, string $path, array $headers = [], string $body = ''): array
    {
        return [
            'version' => '2.0',
            'rawPath' => $path,
            'headers' => $headers,
            'requestContext' => [
                'http' => [
                    'method' => $method,
                    'path' => $path,
                ],
            ],
            'body' => \base64_encode($body),
            'isBase64Encoded' => true,
        ];
    }

    public function testDiscoveryReturnsBase64Manifest(): void
    {
        $event = self::eventV2('GET', '/discovery', [
            'accept' => 'application/vnd.restate.endpointmanifest.v1+json',
        ]);

        $response = $this->handler()->handle($event);

        self::assertSame(200, $response['statusCode']);
        self::assertTrue($response['isBase64Encoded']);

        $decoded = \base64_decode($response['body'], true);
        self::assertIsString($decoded);
        self::assertStringContainsString('"REQUEST_RESPONSE"', $decoded);
    }

    public function testInvokeGreeterReturnsBase64ProtocolFrames(): void
    {
        $journal = (new JournalBuilder())->input('"world"')->build();
        $event = self::eventV2(
            'POST',
            '/invoke/Greeter/greet',
            ['content-type' => ServiceProtocolVersion::V7->contentType()],
            $journal,
        );

        $response = $this->handler()->handle($event);

        self::assertSame(200, $response['statusCode']);
        self::assertTrue($response['isBase64Encoded']);

        $decoded = \base64_decode($response['body'], true);
        self::assertIsString($decoded);
        self::assertNotSame('', $decoded, 'expected non-empty protocol frames');
        self::assertStringContainsString('Greetings world', $decoded);
    }

    public function testHandleJsonRoundTripsThroughDiscovery(): void
    {
        $eventJson = \json_encode(
            self::eventV2('GET', '/discovery', [
                'accept' => 'application/vnd.restate.endpointmanifest.v1+json',
            ]),
            JSON_THROW_ON_ERROR,
        );

        $responseJson = $this->handler()->handleJson($eventJson);

        /** @var array<string, mixed> $response */
        $response = \json_decode($responseJson, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response['statusCode']);
        self::assertTrue($response['isBase64Encoded']);
        self::assertIsString($response['body']);

        $decoded = \base64_decode($response['body'], true);
        self::assertIsString($decoded);
        self::assertStringContainsString('"REQUEST_RESPONSE"', $decoded);
    }

    public function testFallsBackToV1KeysAndDefaults(): void
    {
        // API Gateway v1 shape: httpMethod + path, no requestContext.http.
        $event = [
            'httpMethod' => 'GET',
            'path' => '/health',
            'headers' => [],
            'body' => null,
        ];

        $response = $this->handler()->handle($event);

        self::assertSame(200, $response['statusCode']);
        self::assertTrue($response['isBase64Encoded']);
        self::assertSame('OK', \base64_decode($response['body'], true));
    }
}
