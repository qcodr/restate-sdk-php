<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Server;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Endpoint\Endpoint;
use Restate\Sdk\Protocol\ServiceProtocolVersion;
use Restate\Sdk\Server\LambdaHandler;
use Restate\Sdk\Tests\Support\Fixtures\Greeter;
use Restate\Sdk\Tests\Support\JournalBuilder;

/**
 * Branch coverage for {@see LambdaHandler}'s event-extraction fallbacks: missing
 * method/path/headers default safely, and a non-base64 body is read verbatim.
 */
final class LambdaHandlerBranchesTest extends TestCase
{
    private function handler(): LambdaHandler
    {
        return new LambdaHandler(Endpoint::builder()->bind(new Greeter())->build());
    }

    public function testMissingMethodPathAndHeadersFallBackToDefaults(): void
    {
        // An event with none of the recognised keys: method -> POST, path -> '/',
        // headers -> [], body -> ''. POST '/' matches no route, so we get a 404 —
        // proving the defaults were applied without crashing.
        $response = $this->handler()->handle([]);

        self::assertSame(404, $response['statusCode']);
        self::assertTrue($response['isBase64Encoded']);
        self::assertSame('Not Found', \base64_decode($response['body'], true));
    }

    public function testNonArrayHeadersFallBackToEmpty(): void
    {
        // headers present but not a map: extractHeaders must ignore it, not error.
        $event = [
            'httpMethod' => 'GET',
            'path' => '/health',
            'headers' => 'not-a-map',
            'body' => null,
        ];

        $response = $this->handler()->handle($event);

        self::assertSame(200, $response['statusCode']);
        self::assertSame('OK', \base64_decode($response['body'], true));
    }

    public function testRawBodyIsReadWhenNotBase64Encoded(): void
    {
        // isBase64Encoded omitted: the body string must be passed through verbatim
        // to the processor rather than base64-decoded.
        $journal = (new JournalBuilder())->input('"world"')->build();
        $event = [
            'requestContext' => ['http' => ['method' => 'POST']],
            'rawPath' => '/invoke/Greeter/greet',
            'headers' => ['content-type' => ServiceProtocolVersion::V7->contentType()],
            'body' => $journal,
        ];

        $response = $this->handler()->handle($event);

        self::assertSame(200, $response['statusCode']);
        $decoded = \base64_decode($response['body'], true);
        self::assertIsString($decoded);
        self::assertStringContainsString('Greetings world', $decoded);
    }
}
