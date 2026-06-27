<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Endpoint;

use Closure;
use Fiber;
use Psr\Log\LoggerInterface;
use Qcodr\Restate\Sdk\Context\Clock;
use Qcodr\Restate\Sdk\Discovery\DiscoveryContentType;
use Qcodr\Restate\Sdk\Discovery\ManifestBuilder;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Serde\Serde;
use Qcodr\Restate\Sdk\Vm\FiberSuspender;
use Qcodr\Restate\Sdk\Vm\StateMachine;
use Qcodr\Restate\Sdk\Vm\SwitchableOutputSink;

/**
 * The framework-agnostic core of the deployment endpoint.
 *
 * It turns a transport-neutral {@see HttpRequest} into an {@see HttpResponse},
 * implementing the three routes of the deployment HTTP contract:
 *   - `GET  /discovery`            → the endpoint manifest;
 *   - `POST {prefix}/invoke/{s}/{h}` → one invocation slice;
 *   - `GET  /health`               → liveness.
 *
 * Transports (Swoole, PSR-15, the dev server) adapt their native request/response
 * onto this class; all protocol behavior lives here so it is uniformly testable.
 */
final class RequestProcessor
{
    public const SDK_IDENTIFIER = InvocationDriver::SDK_IDENTIFIER;

    /** Hard cap on the request body, enforced for every transport (not just Swoole). */
    public const MAX_BODY_BYTES = 64 * 1024 * 1024;

    private const HEADER_SERVER = 'x-restate-server';
    private const HEADER_CONTENT_TYPE = 'content-type';

    private readonly ManifestBuilder $manifestBuilder;
    private readonly InvocationDriver $invocationDriver;

    /**
     * @param ProtocolMode $transportCapability the richest transport mode the host can
     *                                           actually serve; discovery advertises the
     *                                           lesser of this and the endpoint's own
     *                                           mode, so a bidi-configured endpoint never
     *                                           advertises BIDI_STREAM on a request/response
     *                                           host. Defaults to RequestResponse, the only
     *                                           mode the Swoole/PSR-15/Lambda hosts serve.
     */
    public function __construct(
        private readonly Endpoint $endpoint,
        ?Serde $serde = null,
        ?Clock $clock = null,
        ?ManifestBuilder $manifestBuilder = null,
        ?LoggerInterface $logger = null,
        bool $debug = false,
        private readonly ProtocolMode $transportCapability = ProtocolMode::RequestResponse,
    ) {
        $this->manifestBuilder = $manifestBuilder ?? new ManifestBuilder();
        $this->invocationDriver = new InvocationDriver($serde, $clock, $logger, $debug);
    }

    public function process(HttpRequest $request): HttpResponse
    {
        $rejection = $this->guard($request);
        if ($rejection !== null) {
            return $rejection;
        }

        $path = \rtrim($request->path, '/');

        if ($request->method === 'GET' && (\str_ends_with($path, '/discover') || \str_ends_with($path, '/discovery'))) {
            return $this->discover($request);
        }
        if ($request->method === 'GET' && \str_ends_with($path, '/health')) {
            return HttpResponse::of(200, 'OK', [self::HEADER_CONTENT_TYPE => 'text/plain']);
        }
        if ($request->method === 'POST' && ($target = self::parseInvokePath($path)) !== null) {
            return $this->invoke($request, $target[0], $target[1]);
        }

        return HttpResponse::of(404, 'Not Found');
    }

    private function discover(HttpRequest $request): HttpResponse
    {
        $accept = $request->header('accept');
        $contentType = DiscoveryContentType::negotiate($accept);
        $version = DiscoveryContentType::negotiateVersion($accept);

        $services = $this->endpoint->services();
        $options = [];
        foreach ($services as $service) {
            $serviceOptions = $this->endpoint->optionsFor($service->name);
            if ($serviceOptions !== null) {
                $options[$service->name] = $serviceOptions;
            }
        }

        $manifest = $this->manifestBuilder->build($services, $version, $options, $this->effectiveProtocolMode());
        $body = \json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return HttpResponse::of(200, $body, [
            self::HEADER_CONTENT_TYPE => $contentType,
            self::HEADER_SERVER => self::SDK_IDENTIFIER,
        ]);
    }

    private function invoke(HttpRequest $request, string $serviceName, string $handlerName): HttpResponse
    {
        $version = ServiceProtocolVersion::fromContentType($request->header(self::HEADER_CONTENT_TYPE) ?? '');
        if ($version === null) {
            return self::unsupportedVersionResponse();
        }

        $service = $this->endpoint->service($serviceName);
        $handler = $service?->handler($handlerName);
        if ($service === null || $handler === null) {
            return HttpResponse::of(404, "Unknown handler {$serviceName}/{$handlerName}");
        }

        // Request/response transport: hand the driver a state machine with the r/r
        // defaults (throwing suspender + buffering sink). The bidirectional streaming
        // wiring lives in {@see driveStreaming} for hosts that keep the channel open.
        return $this->invocationDriver->runRequestResponse(
            new StateMachine($version),
            $service,
            $handler,
            $request->body,
        );
    }

    /**
     * Resolves an invoke request for a bidirectional streaming host. Verifies the
     * request identity, the body cap, the protocol version and the service/handler —
     * reusing the exact checks {@see process} applies — and returns either a
     * {@see StreamingInvocation} for the caller to drive over an open channel, or the
     * error {@see HttpResponse} (401/404/413/415) to send as-is. The request body is
     * not consumed here: it streams in once {@see driveStreaming} runs.
     */
    public function resolveStreamingInvoke(HttpRequest $request): StreamingInvocation|HttpResponse
    {
        $rejection = $this->guard($request);
        if ($rejection !== null) {
            return $rejection;
        }

        $target = self::parseInvokePath(\rtrim($request->path, '/'));
        if ($request->method !== 'POST' || $target === null) {
            return HttpResponse::of(404, 'Not Found');
        }

        $version = ServiceProtocolVersion::fromContentType($request->header(self::HEADER_CONTENT_TYPE) ?? '');
        if ($version === null) {
            return self::unsupportedVersionResponse();
        }

        $service = $this->endpoint->service($target[0]);
        $handler = $service?->handler($target[1]);
        if ($service === null || $handler === null) {
            return HttpResponse::of(404, "Unknown handler {$target[0]}/{$target[1]}");
        }

        return new StreamingInvocation($service, $handler, $version);
    }

    /**
     * Drives a resolved invocation bidirectionally over an open {@see StreamTransport}.
     * Builds the streaming state machine (a {@see FiberSuspender} so an await parks the
     * handler fiber, plus a {@see StreamingOutputSink} so frames flow straight to $io)
     * and hands it to the driver; frames stream out as the handler produces them and the
     * channel is closed once the terminal frame is on the wire.
     */
    public function driveStreaming(StreamingInvocation $target, StreamTransport $io): void
    {
        $this->invocationDriver->driveStreaming(
            new StateMachine($target->version, new FiberSuspender(), new StreamingOutputSink($io)),
            $target->service,
            $target->handler,
            $io,
        );
    }

    /**
     * Attempts to run the journal-replay phase and the handler's first execution slice
     * inline (in the calling fiber), eliminating the `async()` task and outbound
     * {@see \Amp\Pipeline\Queue} for handlers that complete without parking.
     *
     * The caller provides `$readChunk` — a closure that reads one raw byte-string from
     * the inbound transport and returns null at EOF — so this method stays transport-
     * agnostic. Typically `fn() => $requestBody->read()`.
     *
     * Returns a {@see StreamingInlineResult}:
     *
     *  - `completed === true`: handler finished in this slice; `$output` is the full
     *    encoded body.  Return a `ReadableBuffer($output)` response, no continuation.
     *
     *  - `completed === false`: handler is parked; `$output` is the preamble to flush,
     *    and `$vm`/`$handlerFiber`/`$park`/`$switchSink` are set.  The caller must call
     *    {@see SwitchableOutputSink::switchToDownstream} then {@see continueStreamingFromPark}.
     *
     * @param Closure(): ?string $readChunk
     */
    public function tryDriveStreamingInline(
        StreamingInvocation $target,
        Closure $readChunk,
    ): StreamingInlineResult {
        $switchSink = new SwitchableOutputSink();
        $vm = new StateMachine($target->version, new FiberSuspender(), $switchSink);

        $inlineState = $this->invocationDriver->tryStartInline(
            $vm,
            $target->service,
            $target->handler,
            $readChunk,
        );

        if ($inlineState === null) {
            // Handler completed or EOF during journal; all output is already in the sink.
            return new StreamingInlineResult(
                completed: true,
                output: $switchSink->takeBuffer(),
                vm: null,
                handlerFiber: null,
                park: null,
                switchSink: null,
            );
        }

        /** @var Fiber<mixed, mixed, mixed, mixed> $fiber */
        [$fiber, $park] = $inlineState;

        return new StreamingInlineResult(
            completed: false,
            output: $switchSink->takeBuffer(),
            vm: $vm,
            handlerFiber: $fiber,
            park: $park,
            switchSink: $switchSink,
        );
    }

    /**
     * Continues an invocation that was left parked by {@see tryDriveStreamingInline}.
     * Routes late completions from `$io` to the VM, resumes the handler fiber on each
     * satisfiable park, and closes `$io` when the fiber terminates or EOF arrives.
     *
     * Must only be called when `$result->completed === false`.
     */
    public function continueStreamingFromPark(StreamingInlineResult $result, StreamTransport $io): void
    {
        $vm = $result->vm;
        $fiber = $result->handlerFiber;

        if ($vm === null || $fiber === null) {
            $io->close();

            return;
        }

        $this->invocationDriver->continueFromPark($vm, $fiber, $result->park, $io);
    }

    /**
     * Shared request gate: opt-in identity verification then the transport-agnostic
     * body cap. Returns the rejection response, or null when the request may proceed.
     */
    private function guard(HttpRequest $request): ?HttpResponse
    {
        // Request-identity verification (opt-in): when the endpoint is configured
        // with one or more `publickeyv1_...` keys, every request — discovery,
        // invoke and health alike, matching the Rust/shared-core behaviour — must
        // carry a valid Restate signature. With no key configured this is a no-op.
        $verifier = $this->endpoint->identityVerifier();
        if ($verifier !== null && !$verifier->verify($request)) {
            return HttpResponse::of(401, 'Unauthorized', [self::HEADER_CONTENT_TYPE => 'text/plain']);
        }

        // Transport-agnostic body cap so every host (Swoole, PSR-15, Lambda) is
        // protected from unbounded parsing, not just the Swoole server.
        if (\strlen($request->body) > self::MAX_BODY_BYTES) {
            return HttpResponse::of(413, 'Request body too large', [self::HEADER_CONTENT_TYPE => 'text/plain']);
        }

        return null;
    }

    /**
     * The transport mode advertised in discovery: the lesser of the endpoint's own mode
     * and what the host can serve. BIDI_STREAM only when both opt in; otherwise
     * REQUEST_RESPONSE, so a bidi-configured endpoint stays request/response on a
     * request/response host.
     */
    private function effectiveProtocolMode(): ProtocolMode
    {
        if (
            $this->endpoint->protocolMode() === ProtocolMode::BidiStream
            && $this->transportCapability === ProtocolMode::BidiStream
        ) {
            return ProtocolMode::BidiStream;
        }

        return ProtocolMode::RequestResponse;
    }

    private static function unsupportedVersionResponse(): HttpResponse
    {
        return HttpResponse::of(
            415,
            \sprintf(
                'Unsupported service protocol version (supported: v%d-v%d)',
                ServiceProtocolVersion::min()->value,
                ServiceProtocolVersion::max()->value,
            ),
            [self::HEADER_CONTENT_TYPE => 'text/plain'],
        );
    }

    /**
     * @return array{0: string, 1: string}|null [serviceName, handlerName]
     */
    private static function parseInvokePath(string $path): ?array
    {
        $marker = '/invoke/';
        $position = \strrpos($path, $marker);
        if ($position === false) {
            return null;
        }

        $remainder = \substr($path, $position + \strlen($marker));
        $segments = \explode('/', $remainder);
        if (\count($segments) !== 2 || $segments[0] === '' || $segments[1] === '') {
            return null;
        }

        return [\rawurldecode($segments[0]), \rawurldecode($segments[1])];
    }
}
