<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Server;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Qcodr\Restate\Sdk\Context\Clock;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;
use Qcodr\Restate\Sdk\Serde\Serde;

/**
 * Hosts a Restate {@see Endpoint} inside any PSR-15 middleware stack (Slim, Mezzio,
 * Laminas, …).
 *
 * The handler adapts an incoming PSR-7 {@see ServerRequestInterface} onto the
 * framework-agnostic {@see RequestProcessor} and renders the resulting
 * {@see \Qcodr\Restate\Sdk\Endpoint\HttpResponse} back into a PSR-7 response built from the
 * supplied factories. All protocol behavior lives in the processor; this class only
 * bridges the message types, so the same endpoint runs identically here and under
 * {@see SwooleServer}.
 */
final class Psr15Handler implements RequestHandlerInterface
{
    private readonly RequestProcessor $processor;

    public function __construct(
        Endpoint $endpoint,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        ?Serde $serde = null,
        ?Clock $clock = null,
        ?LoggerInterface $logger = null,
        bool $debug = false,
    ) {
        $this->processor = new RequestProcessor($endpoint, $serde, $clock, logger: $logger, debug: $debug);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $httpRequest = new HttpRequest(
            $request->getMethod(),
            $request->getUri()->getPath(),
            self::flattenHeaders($request->getHeaders()),
            (string) $request->getBody(),
        );

        $httpResponse = $this->processor->process($httpRequest);

        $response = $this->responseFactory
            ->createResponse($httpResponse->status)
            ->withBody($this->streamFactory->createStream($httpResponse->body));
        foreach ($httpResponse->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Collapses PSR-7's multi-valued header map into the lower-cased
     * `array<string, string>` shape the {@see HttpRequest} expects, joining repeated
     * values with ", " per RFC 7230 §3.2.2.
     *
     * @param array<array<string>> $headers
     *
     * @return array<string, string>
     */
    private static function flattenHeaders(array $headers): array
    {
        $flattened = [];
        foreach ($headers as $name => $values) {
            // PHP coerces purely numeric array keys to int; header names are strings.
            $flattened[\strtolower((string) $name)] = \implode(', ', $values);
        }

        return $flattened;
    }
}
