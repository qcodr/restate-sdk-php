<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Endpoint;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Endpoint\Endpoint;
use Restate\Sdk\Endpoint\HttpRequest;
use Restate\Sdk\Endpoint\RequestProcessor;
use Restate\Sdk\Protocol\MessageHeader;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\ServiceProtocolVersion;
use Restate\Sdk\Tests\Support\Fixtures\Greeter;

/**
 * Branch coverage for {@see RequestProcessor}: body cap, unroutable requests, the
 * invoke-path parser, and the incomplete/malformed invocation-stream responses.
 */
final class RequestProcessorBranchesTest extends TestCase
{
    private function processor(): RequestProcessor
    {
        return new RequestProcessor(Endpoint::builder()->bind(new Greeter())->build());
    }

    public function testBodyExceedingMaxReturns413(): void
    {
        $oversized = \str_repeat('a', RequestProcessor::MAX_BODY_BYTES + 1);
        $request = new HttpRequest('POST', '/invoke/Greeter/greet', [
            'content-type' => ServiceProtocolVersion::V7->contentType(),
        ], $oversized);

        $response = $this->processor()->process($request);

        self::assertSame(413, $response->status);
        self::assertSame('Request body too large', $response->body);
    }

    public function testPostToNonInvokePathReturns404(): void
    {
        // No '/invoke/' marker: parseInvokePath returns null and we fall through.
        $request = new HttpRequest('POST', '/not/a/route', [], '');

        $response = $this->processor()->process($request);

        self::assertSame(404, $response->status);
        self::assertSame('Not Found', $response->body);
    }

    public function testInvokePathWithSingleSegmentReturns404(): void
    {
        // '/invoke/Greeter' has one trailing segment, not service + handler.
        $request = new HttpRequest('POST', '/invoke/Greeter', [
            'content-type' => ServiceProtocolVersion::V7->contentType(),
        ], '');

        self::assertSame(404, $this->processor()->process($request)->status);
    }

    public function testInvokePathWithEmptySegmentReturns404(): void
    {
        // Trailing empty handler segment is rejected by the parser.
        $request = new HttpRequest('POST', '/invoke/Greeter/', [
            'content-type' => ServiceProtocolVersion::V7->contentType(),
        ], '');

        self::assertSame(404, $this->processor()->process($request)->status);
    }

    public function testEmptyInvocationBodyReturns500Incomplete(): void
    {
        // A valid route + protocol version, but the journal never arrives, so the
        // state machine cannot reach a ready-to-execute state.
        $request = new HttpRequest('POST', '/invoke/Greeter/greet', [
            'content-type' => ServiceProtocolVersion::V7->contentType(),
        ], '');

        $response = $this->processor()->process($request);

        self::assertSame(500, $response->status);
        self::assertSame('Incomplete invocation stream', $response->body);
    }

    public function testMalformedInvocationStreamReturns500(): void
    {
        // First frame is an OutputCommand, not the required StartMessage: the state
        // machine raises a ProtocolException, which must be masked as a generic 500.
        $nonStartFrame = (new MessageHeader(MessageType::OutputCommand->value, 0))->encode();
        $request = new HttpRequest('POST', '/invoke/Greeter/greet', [
            'content-type' => ServiceProtocolVersion::V7->contentType(),
        ], $nonStartFrame);

        $response = $this->processor()->process($request);

        self::assertSame(500, $response->status);
        self::assertSame('Malformed invocation stream', $response->body);
    }
}
