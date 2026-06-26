<?php

declare(strict_types=1);

namespace Restate\Sdk\Server;

use Psr\Log\LoggerInterface;
use Restate\Sdk\Context\Clock;
use Restate\Sdk\Endpoint\Endpoint;
use Restate\Sdk\Endpoint\HttpRequest;
use Restate\Sdk\Endpoint\RequestProcessor;
use Restate\Sdk\Serde\Serde;

/**
 * Hosts a Restate {@see Endpoint} behind an AWS Lambda Function URL / API Gateway
 * HTTP-API proxy integration.
 *
 * The Restate runtime invokes a Lambda deployment in request/response mode: each
 * incoming discovery or invocation call arrives as an API Gateway proxy event whose
 * body is base64-encoded (the deployment protocol body is raw bytes), and the
 * response must echo a base64-encoded body with `isBase64Encoded: true`. This handler
 * adapts that event/response envelope onto the framework-agnostic
 * {@see RequestProcessor}; all protocol behavior lives in the processor, so the same
 * endpoint runs identically here and under {@see SwooleServer} or {@see Psr15Handler}.
 *
 * Both API Gateway v2 (HTTP API / Function URL) and the older v1 (REST API) event
 * shapes are accepted: v2 keys (`requestContext.http.method`, `rawPath`) are preferred,
 * falling back to v1 keys (`httpMethod`, `path`).
 *
 * Wiring under a custom Lambda runtime (e.g. bref) — the function bootstrap turns the
 * runtime-supplied event into a response array:
 *
 * ```php
 * // bootstrap.php (bref "function" runtime)
 * use Restate\Sdk\Endpoint\Endpoint;
 * use Restate\Sdk\Server\LambdaHandler;
 *
 * $endpoint = Endpoint::builder()->bind(new Greeter())->build();
 * $handler  = new LambdaHandler($endpoint);
 *
 * return function (array $event) use ($handler): array {
 *     return $handler->handle($event);
 * };
 * ```
 *
 * Runtimes that hand the bootstrap the raw event string instead of a decoded array can
 * use {@see handleJson} to decode, dispatch and re-encode in one step.
 */
final class LambdaHandler
{
    private const DEFAULT_METHOD = 'POST';
    private const DEFAULT_PATH = '/';

    private readonly RequestProcessor $processor;

    public function __construct(
        Endpoint $endpoint,
        ?Serde $serde = null,
        ?Clock $clock = null,
        ?LoggerInterface $logger = null,
        bool $debug = false,
    ) {
        $this->processor = new RequestProcessor($endpoint, $serde, $clock, logger: $logger, debug: $debug);
    }

    /**
     * Dispatches a single API Gateway proxy event and returns the proxy response.
     *
     * @param array<array-key, mixed> $event the decoded API Gateway v2/v1 proxy event
     *
     * @return array{statusCode: int, headers: array<string, string>, body: string, isBase64Encoded: bool}
     */
    public function handle(array $event): array
    {
        $request = new HttpRequest(
            self::extractMethod($event),
            self::extractPath($event),
            self::extractHeaders($event),
            self::extractBody($event),
        );

        $response = $this->processor->process($request);

        return [
            'statusCode' => $response->status,
            'headers' => $response->headers,
            // The protocol body is raw bytes; Lambda requires base64 for binary output.
            'body' => \base64_encode($response->body),
            'isBase64Encoded' => true,
        ];
    }

    /**
     * Convenience wrapper for runtimes that pass the raw event JSON string rather than
     * a decoded array: decode → {@see handle} → encode.
     */
    public function handleJson(string $eventJson): string
    {
        /** @var mixed $decoded */
        $decoded = \json_decode($eventJson, true, 512, JSON_THROW_ON_ERROR);
        $event = \is_array($decoded) ? $decoded : [];

        return \json_encode($this->handle($event), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<array-key, mixed> $event
     */
    private static function extractMethod(array $event): string
    {
        $requestContext = $event['requestContext'] ?? null;
        if (\is_array($requestContext)) {
            $http = $requestContext['http'] ?? null;
            if (\is_array($http)) {
                $method = $http['method'] ?? null;
                if (\is_string($method) && $method !== '') {
                    return \strtoupper($method);
                }
            }
        }

        // API Gateway v1 (REST API) fallback.
        $httpMethod = $event['httpMethod'] ?? null;
        if (\is_string($httpMethod) && $httpMethod !== '') {
            return \strtoupper($httpMethod);
        }

        return self::DEFAULT_METHOD;
    }

    /**
     * @param array<array-key, mixed> $event
     */
    private static function extractPath(array $event): string
    {
        // API Gateway v2 (HTTP API / Function URL).
        $rawPath = $event['rawPath'] ?? null;
        if (\is_string($rawPath) && $rawPath !== '') {
            return $rawPath;
        }

        // API Gateway v1 (REST API) fallback.
        $path = $event['path'] ?? null;
        if (\is_string($path) && $path !== '') {
            return $path;
        }

        return self::DEFAULT_PATH;
    }

    /**
     * @param array<array-key, mixed> $event
     *
     * @return array<string, string> lower-cased header names, as {@see HttpRequest} expects
     */
    private static function extractHeaders(array $event): array
    {
        $headers = $event['headers'] ?? null;
        if (!\is_array($headers)) {
            return [];
        }

        $flattened = [];
        foreach ($headers as $name => $value) {
            if (\is_string($value)) {
                $flattened[\strtolower((string) $name)] = $value;
            }
        }

        return $flattened;
    }

    /**
     * @param array<array-key, mixed> $event
     */
    private static function extractBody(array $event): string
    {
        $body = $event['body'] ?? null;
        if (!\is_string($body)) {
            return '';
        }

        if (($event['isBase64Encoded'] ?? null) === true) {
            $decoded = \base64_decode($body, true);

            return $decoded === false ? '' : $decoded;
        }

        return $body;
    }
}
