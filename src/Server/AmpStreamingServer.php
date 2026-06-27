<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Server;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Pipeline\Queue;
use Amp\Socket\BindContext;
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
use Qcodr\Restate\Sdk\Endpoint\StreamingOutputSink;
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

    /**
     * Serves the endpoint until SIGINT/SIGTERM.
     *
     * @param int $workers number of worker processes. 1 (default) serves in a single
     *                     event loop. > 1 pre-forks that many processes that each bind the
     *                     same port via SO_REUSEPORT, so the kernel load-balances
     *                     connections across N event loops — the amphp equivalent of
     *                     Swoole's worker pool (a single amphp loop is otherwise a
     *                     single-core throughput ceiling). <= 0 auto-detects the CPU count.
     *                     Needs ext-pcntl; without it the call falls back to one worker.
     */
    public function listen(string $host = '0.0.0.0', int $port = 9080, int $workers = 1): void
    {
        if ($port < 0 || $port > 65535) {
            throw new RuntimeException("Port {$port} is out of range (0-65535)");
        }

        $workers = $workers > 0 ? $workers : self::detectWorkers();

        // Single worker: serve inline, behaviour unchanged (no SO_REUSEPORT needed).
        if ($workers < 2 || !\function_exists('pcntl_fork')) {
            if ($workers >= 2) {
                \fwrite(\STDERR, "WARNING: ext-pcntl is unavailable; running a single worker.\n");
            }
            $this->runServer($host, $port, reusePort: false, announce: true);

            return;
        }

        // Multi-worker: pre-fork (workers - 1) children that, with the parent, each bind the
        // same port with SO_REUSEPORT so the kernel spreads connections across N event loops.
        // Fork before any event-loop use so every process starts a clean Revolt loop. A
        // crashed worker is not respawned (use a process supervisor / amphp-cluster for that).
        $childPids = [];
        for ($i = 1; $i < $workers; $i++) {
            $pid = \pcntl_fork();
            if ($pid === 0) {
                $this->runServer($host, $port, reusePort: true, announce: false);

                return;
            }
            if ($pid > 0) {
                $childPids[] = $pid;
            } else {
                \fwrite(\STDERR, "WARNING: fork failed; continuing with fewer workers.\n");
            }
        }

        \fwrite(\STDOUT, \sprintf(
            "Restate PHP endpoint (amphp bidi streaming) listening on http://%s:%d (%d workers)\n",
            $host,
            $port,
            \count($childPids) + 1,
        ));
        if ($this->endpoint->identityVerifier() === null) {
            \fwrite(\STDERR, 'WARNING: request identity verification is disabled; '
                . "configure EndpointBuilder::identityKey() for production.\n");
        }

        // The parent serves too; when it is asked to stop it returns, then we stop the
        // workers and reap them so none is left as a zombie.
        $this->runServer($host, $port, reusePort: true, announce: false);

        foreach ($childPids as $pid) {
            if (\function_exists('posix_kill')) {
                \posix_kill($pid, \SIGTERM);
            }
            \pcntl_waitpid($pid, $status);
        }
    }

    /**
     * Builds and runs one amphp HTTP server in the current process, blocking until a stop
     * signal. Factored out of {@see listen} so it runs identically in the parent and each
     * forked worker; only the announcer (single/parent) prints the startup banner.
     *
     * @param int<0, 65535> $port already range-checked by {@see listen}
     */
    private function runServer(string $host, int $port, bool $reusePort, bool $announce): void
    {
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

        // Disable Nagle's algorithm (amphp's BindContext defaults TCP_NODELAY off). Each
        // invocation writes a few small command frames (e.g. Output then End) onto the h2
        // stream and then waits to read; with Nagle on, the second small write is held back
        // until the first is ACKed, colliding with the peer's delayed-ACK timer for a ~40 ms
        // per-invocation stall that dominates end-to-end latency. Flushing immediately keeps
        // the bidi transport's latency close to the request/response host's. SO_REUSEPORT
        // lets the workers share the port for kernel-level load balancing.
        $bindContext = (new BindContext())->withTcpNoDelay();
        if ($reusePort) {
            $bindContext = $bindContext->withReusePort();
        }
        $server->expose(new InternetAddress($host, $port), $bindContext);
        $server->start(new ClosureRequestHandler($this->handleRequest(...)), new DefaultErrorHandler());

        if ($announce) {
            \fwrite(\STDOUT, "Restate PHP endpoint (amphp bidi streaming) listening on http://{$host}:{$port}\n");
            if ($this->endpoint->identityVerifier() === null) {
                \fwrite(\STDERR, 'WARNING: request identity verification is disabled; '
                    . "configure EndpointBuilder::identityKey() for production.\n");
            }
        }

        // Serve until the container/runtime asks us to stop.
        trapSignal([\SIGINT, \SIGTERM]);
        $server->stop();
    }

    /**
     * Best-effort online CPU count (Linux) used when {@see listen} is called with
     * `workers <= 0`; falls back to 1. Parses /proc/cpuinfo (a constant path) rather than
     * shelling out, so it adds no command-execution surface for the SAST to flag.
     */
    private static function detectWorkers(): int
    {
        $cpuinfo = @\file_get_contents('/proc/cpuinfo');
        if (\is_string($cpuinfo)) {
            $count = \preg_match_all('/^processor\s*:/m', $cpuinfo);
            if (\is_int($count) && $count > 0) {
                return $count;
            }
        }

        return 1;
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
        $headers = [
            'content-type'     => $target->version->contentType(),
            'x-restate-server' => RequestProcessor::SDK_IDENTIFIER,
        ];

        // Fast path: run the journal replay and first handler slice inline (in the current
        // fiber) so we know whether the handler completes without parking before we
        // decide how to build the response body.
        //
        // Invariant: the HTTP/2 driver pushed the incoming DATA frames into the request
        // body before scheduling this fiber (same event-loop tick), so read() returns
        // immediately without suspending — zero extra event-loop hops for the whole
        // journal phase and first slice for non-parking handlers.
        $inbound = $request->getBody();
        $inlineResult = $this->processor->tryDriveStreamingInline(
            $target,
            static fn (): ?string => $inbound->read(),
        );

        if ($inlineResult->completed) {
            // Handler ran to completion without any unresolved park: return the buffered
            // output as a ReadableBuffer so the HTTP/2 send() loop never suspends waiting
            // for a Queue — the bytes are already there on the first read().
            // ReadableBuffer('') self-closes immediately, matching a zero-byte body for
            // the edge case where the handler produced no frames (e.g. EOF during journal).
            return new Response(
                HttpStatus::OK,
                $headers,
                new ReadableBuffer($inlineResult->output !== '' ? $inlineResult->output : null),
            );
        }

        // Slow / parked path: the handler is waiting for a late completion or signal.
        // Set up the streaming queue, wire the switchable sink to route future output
        // through the transport, push the pre-park preamble synchronously so the HTTP/2
        // send() loop sees it on the very first read() without suspending, then hand off
        // to an async continuation for the remaining completion loop.

        // Buffer size 1: the prelude push (producer-first, no consumer yet) can be
        // absorbed without creating a DeferredFuture for backpressure. Subsequent pushes
        // happen only when the continuation flushes after a transport->read(), at which
        // point the consumer is already waiting, so they resume it directly.
        /** @var Queue<string> $queue */
        $queue = new Queue(1);
        $transport = new AmpStreamTransport($inbound, $queue);

        // Any VM writes that happen after this point go via transport → queue.
        $inlineResult->switchSink?->switchToDownstream(new StreamingOutputSink($transport));

        // Push the pre-park preamble (AwaitingOn + earlier commands) synchronously into
        // the queue BEFORE returning the ReadableIterableStream so the first body read()
        // by the HTTP/2 send() loop finds data already buffered and returns immediately.
        if ($inlineResult->output !== '') {
            $queue->pushAsync($inlineResult->output)->ignore();
        }

        async(function () use ($inlineResult, $transport): void {
            try {
                $this->processor->continueStreamingFromPark($inlineResult, $transport);
            } catch (Throwable $e) {
                $this->logger->error(
                    'Unhandled error while streaming invocation: ' . $e->getMessage(),
                    ['exception' => $e],
                );
            } finally {
                // Guard: continueStreamingFromPark always closes, but protect against an
                // exception thrown before it reaches its own close.
                $transport->close();
            }
        })->ignore();

        return new Response(
            HttpStatus::OK,
            $headers,
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
