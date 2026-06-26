<?php

declare(strict_types=1);

namespace Restate\Sdk\Endpoint;

use Psr\Log\LoggerInterface;
use Restate\Sdk\Context\Clock;
use Restate\Sdk\Discovery\DiscoveryContentType;
use Restate\Sdk\Discovery\ManifestBuilder;
use Restate\Sdk\Protocol\ProtocolException;
use Restate\Sdk\Protocol\ServiceProtocolVersion;
use Restate\Sdk\Serde\Serde;
use Restate\Sdk\Vm\StateMachine;

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
    public const SDK_IDENTIFIER = 'restate-sdk-php/0.1.0';

    /** Hard cap on the request body, enforced for every transport (not just Swoole). */
    public const MAX_BODY_BYTES = 64 * 1024 * 1024;

    private const HEADER_SERVER = 'x-restate-server';
    private const HEADER_CONTENT_TYPE = 'content-type';

    private readonly ManifestBuilder $manifestBuilder;
    private readonly InvocationProcessor $invocationProcessor;

    public function __construct(
        private readonly Endpoint $endpoint,
        ?Serde $serde = null,
        ?Clock $clock = null,
        ?ManifestBuilder $manifestBuilder = null,
        ?LoggerInterface $logger = null,
        bool $debug = false,
    ) {
        $this->manifestBuilder = $manifestBuilder ?? new ManifestBuilder();
        $this->invocationProcessor = new InvocationProcessor($serde, $clock, $logger, $debug);
    }

    public function process(HttpRequest $request): HttpResponse
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

        $manifest = $this->manifestBuilder->build($services, $version, $options);
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

        $service = $this->endpoint->service($serviceName);
        $handler = $service?->handler($handlerName);
        if ($service === null || $handler === null) {
            return HttpResponse::of(404, "Unknown handler {$serviceName}/{$handlerName}");
        }

        try {
            $vm = new StateMachine($version);
            $vm->notifyInput($request->body);
            $vm->notifyInputClosed();
            if (!$vm->isReadyToExecute()) {
                return HttpResponse::of(500, 'Incomplete invocation stream', [self::HEADER_CONTENT_TYPE => 'text/plain']);
            }

            $this->invocationProcessor->process($service, $handler, $vm);
            $output = $vm->takeOutput();
        } catch (ProtocolException) {
            // Don't echo parser internals back to the caller (it is an oracle when the
            // endpoint is unauthenticated); the detail stays in the worker's logs.
            return HttpResponse::of(500, 'Malformed invocation stream', [self::HEADER_CONTENT_TYPE => 'text/plain']);
        }

        return HttpResponse::of(200, $output, [
            self::HEADER_CONTENT_TYPE => $version->contentType(),
            self::HEADER_SERVER => self::SDK_IDENTIFIER,
        ]);
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
