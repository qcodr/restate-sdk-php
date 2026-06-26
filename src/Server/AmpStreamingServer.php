<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Server;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
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

        // createForDirectAccess wires up the HTTP/2 driver (incl. h2c prior-knowledge),
        // which the Restate runtime uses to open the bidirectional invocation stream.
        $server = SocketHttpServer::createForDirectAccess($this->logger);
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
