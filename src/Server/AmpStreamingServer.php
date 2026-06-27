<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Server;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Pipeline\Queue;
use Amp\Socket\InternetAddress;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qcodr\Restate\Sdk\Context\Clock;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;
use Qcodr\Restate\Sdk\Endpoint\HttpResponse;
use Qcodr\Restate\Sdk\Endpoint\ProtocolMode;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;
use Qcodr\Restate\Sdk\Endpoint\StreamingInvocation;
use Qcodr\Restate\Sdk\Serde\Serde;
use RuntimeException;
use Throwable;

use function Amp\async;
use function Amp\trapSignal;

/**
 * Serves a Restate {@see Endpoint} over true bidirectional HTTP/2 (h2c) streaming
 * using amphp/http-server v3.
 *
 * Unlike {@see SwooleServer} (request/response), this transport keeps the invocation
 * channel open in both directions for the lifetime of the call: the request body is
 * read incrementally as the runtime streams the journal and late completions, and
 * command frames are written back the moment the handler produces them. A parked await
 * therefore does not write a `SuspensionMessage` — the driver waits for the next result
 * on the open channel and resumes the handler fiber. Because it can serve bidi, it is
 * the one host that constructs the {@see RequestProcessor} with a
 * {@see ProtocolMode::BidiStream} capability, so a bidi-configured endpoint advertises
 * BIDI_STREAM in discovery here (and only here).
 *
 * Discovery and health are naturally request/response: their small body is buffered and
 * run through the shared {@see RequestProcessor}, so behaviour matches the other hosts.
 *
 * Requires amphp/http-server (a composer `suggest`, not a hard dependency, mirroring
 * ext-swoole for {@see SwooleServer}); the constructor fails fast with a clear message
 * when it is absent.
 */
final class AmpStreamingServer
{
    /**
     * Per-stream and whole-connection idle ceilings handed to amphp's HTTP/2 driver, in
     * seconds. amphp defaults these to 15s / 60s, which is wrong for this transport: a
     * Restate invocation legitimately keeps its bidi stream open and idle while the handler
     * is parked awaiting a completion, a signal, or a cancel the runtime may deliver much
     * later, and the runtime never half-closes the request (so amphp never `suspend()`s the
     * stream timer) while its HTTP/2 keep-alive PINGs only refresh the connection timer.
     * At 15s amphp would otherwise `releaseStream(..., "Closing stream due to inactivity")`,
     * making the body read throw mid-invocation and silently dropping a pending cancel.
     * Raised well above the runtime's own inactivity/abort windows so Restate governs
     * suspension; a dead peer is still detected immediately by the socket closing.
     */
    private const STREAM_IDLE_TIMEOUT_SECONDS = 3600;
    private const CONNECTION_IDLE_TIMEOUT_SECONDS = 3600;

    /**
     * Connection / concurrency ceilings handed to amphp. The Restate runtime is a single
     * trusted peer that opens one long-lived bidi connection per in-flight invocation, all
     * from the same IP, so amphp's defaults (1000 total, 10 per IP, 1000 concurrent) are
     * far too low: at ~10 the runtime is denied new connections ("too many existing
     * connections"), which surfaces as broken-pipe / unexpected-frame errors and dropped
     * invocations under load. Raised so the runtime — not amphp — governs how many
     * invocations run at once.
     */
    private const CONNECTION_LIMIT = 100_000;
    private const CONNECTION_LIMIT_PER_IP = 100_000;
    private const CONCURRENCY_LIMIT = 100_000;

    private readonly RequestProcessor $processor;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Endpoint $endpoint,
        ?Serde $serde = null,
        ?Clock $clock = null,
        ?LoggerInterface $logger = null,
        bool $debug = false,
    ) {
        if (!\class_exists(SocketHttpServer::class)) {
            throw new RuntimeException(
                'AmpStreamingServer requires amphp/http-server; run composer require amphp/http-server',
            );
        }

        $this->logger = $logger ?? new NullLogger();
        $this->processor = new RequestProcessor(
            $endpoint,
            $serde,
            $clock,
            logger: $logger,
            debug: $debug,
            transportCapability: ProtocolMode::BidiStream,
        );
    }

    public function listen(string $host = '0.0.0.0', int $port = 9080): void
    {
        if ($port < 0 || $port > 65535) {
            throw new RuntimeException("Port {$port} is out of range (0-65535)");
        }

        // The Restate runtime opens the invocation stream with HTTP/2 cleartext (h2c)
        // PRIOR KNOWLEDGE — it writes the HTTP/2 connection preface straight onto the
        // socket, with no TLS (so no ALPN) and no `Upgrade: h2c` handshake. amphp only
        // honours that preface when its HTTP/1 driver is built with HTTP/2 upgrade allowed;
        // the default createForDirectAccess factory leaves it off and answers the preface
        // with `505 Unsupported version 2.0`. Enable it explicitly so the bidi stream is
        // accepted (verified against a real runtime in the conformance suite).
        $server = SocketHttpServer::createForDirectAccess(
            $this->logger,
            connectionLimit: self::CONNECTION_LIMIT,
            connectionLimitPerIp: self::CONNECTION_LIMIT_PER_IP,
            concurrencyLimit: self::CONCURRENCY_LIMIT,
            httpDriverFactory: new DefaultHttpDriverFactory(
                $this->logger,
                streamTimeout: self::STREAM_IDLE_TIMEOUT_SECONDS,
                connectionTimeout: self::CONNECTION_IDLE_TIMEOUT_SECONDS,
                allowHttp2Upgrade: true,
            ),
        );
        $server->expose(new InternetAddress($host, $port));
        $server->start(new ClosureRequestHandler($this->handleRequest(...)), new DefaultErrorHandler());

        \fwrite(\STDOUT, "Restate PHP endpoint (amphp bidi streaming) listening on http://{$host}:{$port}\n");
        if ($this->endpoint->identityVerifier() === null) {
            \fwrite(\STDERR, 'WARNING: request identity verification is disabled; '
                . "configure EndpointBuilder::identityKey() for production.\n");
        }

        // Serve until the container/runtime asks us to stop.
        trapSignal([\SIGINT, \SIGTERM]);
        $server->stop();
    }

    public function handleRequest(Request $request): Response
    {
        $method = \strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        $headers = self::flattenHeaders($request->getHeaders());

        // Invoke is the only POST route. Resolve it from the headers alone (no body
        // read), then drive it bidirectionally; an error case comes back as a buffered
        // response, identical to the request/response hosts.
        if ($method === 'POST') {
            $resolved = $this->processor->resolveStreamingInvoke(new HttpRequest($method, $path, $headers, ''));
            if ($resolved instanceof StreamingInvocation) {
                return $this->stream($request, $resolved);
            }

            return self::toResponse($resolved);
        }

        // Discovery and health: buffer the small body and run request/response.
        try {
            $body = $request->getBody()->buffer();
        } catch (Throwable $e) {
            $this->logger->error('Failed to read request body: ' . $e->getMessage(), ['exception' => $e]);

            return self::toResponse(HttpResponse::of(500, 'Internal Server Error'));
        }

        return self::toResponse($this->processor->process(new HttpRequest($method, $path, $headers, $body)));
    }

    private function stream(Request $request, StreamingInvocation $target): Response
    {
        /** @var Queue<string> $queue */
        $queue = new Queue();
        $transport = new AmpStreamTransport($request->getBody(), $queue);

        // Drive the invocation on its own task so the streamed Response can be returned
        // immediately; frames the handler produces are pushed onto $queue as they happen.
        async(function () use ($target, $transport): void {
            try {
                $this->processor->driveStreaming($target, $transport);
            } catch (Throwable $e) {
                // A malformed stream (e.g. a non-Start first frame) escapes the driver;
                // isolate it to this invocation and let the response terminate below.
                $this->logger->error('Unhandled error while streaming invocation: ' . $e->getMessage(), ['exception' => $e]);
            } finally {
                // Ensure the response body ends even if the driver threw before its own
                // close(); AmpStreamTransport::close() is idempotent.
                $transport->close();
            }
        })->ignore();

        return new Response(
            HttpStatus::OK,
            [
                'content-type' => $target->version->contentType(),
                'x-restate-server' => RequestProcessor::SDK_IDENTIFIER,
            ],
            new ReadableIterableStream($queue->iterate()),
        );
    }

    private static function toResponse(HttpResponse $response): Response
    {
        return new Response($response->status, self::nonEmptyHeaders($response->headers), $response->body);
    }

    /**
     * Narrows a header map to the `array<non-empty-string, string>` shape amphp's
     * {@see Response} requires: every Restate response header has a literal non-empty
     * name, so this only restates that for the type checker.
     *
     * @param array<string, string> $headers
     *
     * @return array<non-empty-string, string>
     */
    private static function nonEmptyHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            if ($name !== '') {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Collapses amphp's multi-valued header map into the lower-cased
     * `array<string, string>` shape {@see HttpRequest} expects, joining repeated values
     * with ", " per RFC 7230 §3.2.2 (mirrors {@see Psr15Handler}).
     *
     * @param array<string, array<int, string>> $headers
     *
     * @return array<string, string>
     */
    private static function flattenHeaders(array $headers): array
    {
        $flattened = [];
        foreach ($headers as $name => $values) {
            $flattened[\strtolower($name)] = \implode(', ', $values);
        }

        return $flattened;
    }
}
