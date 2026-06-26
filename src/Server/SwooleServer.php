<?php

declare(strict_types=1);

namespace Restate\Sdk\Server;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Restate\Sdk\Context\Clock;
use Restate\Sdk\Endpoint\Endpoint;
use Restate\Sdk\Endpoint\HttpRequest;
use Restate\Sdk\Endpoint\HttpResponse;
use Restate\Sdk\Endpoint\RequestProcessor;
use Restate\Sdk\Serde\Serde;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Throwable;

/**
 * Serves a Restate {@see Endpoint} over HTTP using Swoole.
 *
 * Swoole's persistent workers and coroutine scheduler let the deployment handle
 * concurrent invocations (distinct object keys, a workflow run plus its shared
 * handlers) without per-request bootstrap cost. The transport only adapts Swoole's
 * native request/response onto the framework-agnostic {@see RequestProcessor};
 * all protocol behavior lives there.
 *
 * Requires ext-swoole (provided by the deployment's Docker image).
 */
final class SwooleServer
{
    private const MAX_BODY_BYTES = 64 * 1024 * 1024;

    private readonly RequestProcessor $processor;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Endpoint $endpoint,
        ?Serde $serde = null,
        ?Clock $clock = null,
        ?LoggerInterface $logger = null,
        bool $debug = false,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->processor = new RequestProcessor($endpoint, $serde, $clock, logger: $logger, debug: $debug);
    }

    public function listen(string $host = '0.0.0.0', int $port = 9080, int $workerNum = 0): void
    {
        $server = new Server($host, $port);
        $server->set([
            // An explicit positive worker count wins; otherwise scale to the host CPUs.
            // A single worker keeps process-global in-memory state shared (conformance).
            'worker_num' => $workerNum > 0 ? $workerNum : \max(2, \swoole_cpu_num()),
            // The protocol body is raw bytes; never let Swoole parse it as a form.
            'http_parse_post' => false,
            'http_parse_cookie' => false,
            // Accept HTTP/2 cleartext (h2c). The Restate runtime discovers/invokes over
            // h2c prior-knowledge by default; HTTP/1.1 still works alongside it.
            'open_http2_protocol' => true,
            'package_max_length' => self::MAX_BODY_BYTES,
        ]);

        $server->on('request', $this->onRequest(...));

        \fwrite(\STDOUT, "Restate PHP endpoint listening on http://{$host}:{$port}\n");
        if ($this->endpoint->identityVerifier() === null) {
            \fwrite(\STDERR, 'WARNING: request identity verification is disabled; '
                . "configure EndpointBuilder::identityKey() for production.\n");
        }
        $server->start();
    }

    private function onRequest(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse): void
    {
        $method = $swooleRequest->server['request_method'] ?? 'GET';
        $uri = $swooleRequest->server['request_uri'] ?? '/';

        try {
            $request = new HttpRequest(
                \strtoupper(\is_string($method) ? $method : 'GET'),
                \is_string($uri) ? $uri : '/',
                $swooleRequest->header, // Swoole lower-cases header names
                $swooleRequest->rawContent() ?: '',
            );

            $response = $this->processor->process($request);
        } catch (Throwable $e) {
            // Never let an exception escape the request callback: that would crash the
            // Swoole worker. Isolate it to this request as a 500.
            $this->logger->error('Unhandled error while processing request: ' . $e->getMessage(), ['exception' => $e]);
            $response = HttpResponse::of(500, 'Internal Server Error');
        }

        $swooleResponse->status($response->status);
        foreach ($response->headers as $name => $value) {
            $swooleResponse->header($name, $value);
        }
        $swooleResponse->end($response->body);
    }
}
