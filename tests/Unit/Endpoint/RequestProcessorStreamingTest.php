<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Endpoint;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;
use Qcodr\Restate\Sdk\Endpoint\HttpResponse;
use Qcodr\Restate\Sdk\Endpoint\ProtocolMode;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;
use Qcodr\Restate\Sdk\Endpoint\StreamingInvocation;
use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Tests\Support\BufferedStreamTransport;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Greeter;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;

/**
 * The bidi-streaming surface of {@see RequestProcessor}: the per-transport protocol-mode
 * cap advertised in discovery, the streaming-invoke resolution (with its 401/404/413/415
 * rejections), and driving a resolved invocation over a network-free transport.
 */
final class RequestProcessorStreamingTest extends TestCase
{
    private const VALID_KEY = 'publickeyv1_w7YHemBctH5Ck2nQRQ47iBBqhNHy4FV7t2Usbye2A6f';

    private function discoveryMode(Endpoint $endpoint, ProtocolMode $capability): string
    {
        $processor = new RequestProcessor($endpoint, transportCapability: $capability);
        $request = new HttpRequest('GET', '/discovery', [
            'accept' => 'application/vnd.restate.endpointmanifest.v1+json',
        ], '');

        $manifest = \json_decode($processor->process($request)->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsString($manifest['protocolMode']);

        return $manifest['protocolMode'];
    }

    public function testBidiEndpointOnRequestResponseTransportAdvertisesRequestResponse(): void
    {
        // The endpoint opted into bidi, but the default-capability host (Swoole/PSR-15/
        // Lambda) cannot serve it, so discovery is capped back to REQUEST_RESPONSE.
        $endpoint = Endpoint::builder()->bind(new Greeter())->protocolMode(ProtocolMode::BidiStream)->build();

        self::assertSame('REQUEST_RESPONSE', $this->discoveryMode($endpoint, ProtocolMode::RequestResponse));
    }

    public function testBidiEndpointOnBidiTransportAdvertisesBidiStream(): void
    {
        // Both the endpoint and the host opt in: only then is BIDI_STREAM advertised.
        $endpoint = Endpoint::builder()->bind(new Greeter())->protocolMode(ProtocolMode::BidiStream)->build();

        self::assertSame('BIDI_STREAM', $this->discoveryMode($endpoint, ProtocolMode::BidiStream));
    }

    public function testRequestResponseEndpointOnBidiTransportStaysRequestResponse(): void
    {
        // A bidi-capable host must not upgrade an endpoint that never opted in.
        $endpoint = Endpoint::builder()->bind(new Greeter())->build();

        self::assertSame('REQUEST_RESPONSE', $this->discoveryMode($endpoint, ProtocolMode::BidiStream));
    }

    private function processor(): RequestProcessor
    {
        return new RequestProcessor(
            Endpoint::builder()->bind(new Greeter())->build(),
            transportCapability: ProtocolMode::BidiStream,
        );
    }

    private function invokeRequest(string $path, ?string $contentType = null): HttpRequest
    {
        $headers = $contentType === null ? [] : ['content-type' => $contentType];

        return new HttpRequest('POST', $path, $headers, '');
    }

    public function testResolveStreamingInvokeReturnsResolvedTarget(): void
    {
        $resolved = $this->processor()->resolveStreamingInvoke(
            $this->invokeRequest('/invoke/Greeter/greet', ServiceProtocolVersion::V7->contentType()),
        );

        self::assertInstanceOf(StreamingInvocation::class, $resolved);
        self::assertSame('Greeter', $resolved->service->name);
        self::assertSame('greet', $resolved->handler->name);
        self::assertSame(ServiceProtocolVersion::V7, $resolved->version);
    }

    public function testResolveStreamingInvokeRejectsUnsignedRequestWhenIdentityRequired(): void
    {
        $endpoint = Endpoint::builder()->bind(new Greeter())->identityKey(self::VALID_KEY)->build();
        $processor = new RequestProcessor($endpoint, transportCapability: ProtocolMode::BidiStream);

        $resolved = $processor->resolveStreamingInvoke(
            $this->invokeRequest('/invoke/Greeter/greet', ServiceProtocolVersion::V7->contentType()),
        );

        self::assertInstanceOf(HttpResponse::class, $resolved);
        self::assertSame(401, $resolved->status);
    }

    public function testResolveStreamingInvokeRejectsOversizedBody(): void
    {
        $oversized = new HttpRequest(
            'POST',
            '/invoke/Greeter/greet',
            ['content-type' => ServiceProtocolVersion::V7->contentType()],
            \str_repeat('a', RequestProcessor::MAX_BODY_BYTES + 1),
        );

        $resolved = $this->processor()->resolveStreamingInvoke($oversized);

        self::assertInstanceOf(HttpResponse::class, $resolved);
        self::assertSame(413, $resolved->status);
    }

    public function testResolveStreamingInvokeReturns404ForNonInvokePath(): void
    {
        $resolved = $this->processor()->resolveStreamingInvoke($this->invokeRequest('/not/a/route'));

        self::assertInstanceOf(HttpResponse::class, $resolved);
        self::assertSame(404, $resolved->status);
    }

    public function testResolveStreamingInvokeReturns404ForGetMethod(): void
    {
        // A GET to an invoke path is not a streaming invoke; the server routes GET to
        // request/response, so resolution rejects it here.
        $request = new HttpRequest('GET', '/invoke/Greeter/greet', [
            'content-type' => ServiceProtocolVersion::V7->contentType(),
        ], '');

        $resolved = $this->processor()->resolveStreamingInvoke($request);

        self::assertInstanceOf(HttpResponse::class, $resolved);
        self::assertSame(404, $resolved->status);
    }

    public function testResolveStreamingInvokeRejectsUnsupportedContentType(): void
    {
        $resolved = $this->processor()->resolveStreamingInvoke(
            $this->invokeRequest('/invoke/Greeter/greet', 'application/json'),
        );

        self::assertInstanceOf(HttpResponse::class, $resolved);
        self::assertSame(415, $resolved->status);
    }

    public function testResolveStreamingInvokeReturns404ForUnknownHandler(): void
    {
        $resolved = $this->processor()->resolveStreamingInvoke(
            $this->invokeRequest('/invoke/Greeter/missing', ServiceProtocolVersion::V7->contentType()),
        );

        self::assertInstanceOf(HttpResponse::class, $resolved);
        self::assertSame(404, $resolved->status);
    }

    public function testResolveStreamingInvokeReturns404ForUnknownService(): void
    {
        // An unknown service exercises the `$service === null` branch (and the nullsafe
        // handler lookup): there is no service to resolve a handler on.
        $resolved = $this->processor()->resolveStreamingInvoke(
            $this->invokeRequest('/invoke/Nope/greet', ServiceProtocolVersion::V7->contentType()),
        );

        self::assertInstanceOf(HttpResponse::class, $resolved);
        self::assertSame(404, $resolved->status);
    }

    public function testDriveStreamingRunsResolvedInvocationToTerminalFrames(): void
    {
        $processor = $this->processor();
        $resolved = $processor->resolveStreamingInvoke(
            $this->invokeRequest('/invoke/Greeter/greet', ServiceProtocolVersion::V7->contentType()),
        );
        self::assertInstanceOf(StreamingInvocation::class, $resolved);

        $transport = new BufferedStreamTransport([(new JournalBuilder())->input('"world"')->build()]);
        $processor->driveStreaming($resolved, $transport);

        $types = \array_map(static fn ($frame) => $frame->type(), MessageCodec::decodeAll($transport->written()));
        self::assertSame([MessageType::OutputCommand, MessageType::End], $types);
        self::assertTrue($transport->isClosed(), 'the driver closes the channel after the terminal frame');
    }
}
