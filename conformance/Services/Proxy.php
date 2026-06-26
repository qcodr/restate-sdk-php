<?php

declare(strict_types=1);

namespace Restate\Conformance;

use Restate\Sdk\Context\Context;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Service;

/**
 * Conformance proxy service: forwards opaque request bytes to an arbitrary target
 * handler, bypassing serde entirely so the exact wire bytes are preserved.
 *
 * Mirrors the Rust `test-services/src/proxy.rs`. A `ProxyRequest` carries the target
 * coordinates plus the request body as a `message` array of byte values (`Vec<u8>`):
 *
 *   - serviceName      (string)  the target service/object name
 *   - virtualObjectKey (?string) when non-null, target a Virtual Object with this key;
 *                                when null, target a (keyless) Service
 *   - handlerName      (string)  the target handler
 *   - idempotencyKey   (?string) optional idempotency key forwarded to the callee
 *   - message          (int[])   the raw request body, as an array of byte values
 *   - delayMillis      (?int)    optional send delay (one-way calls only)
 */
#[Service(name: 'Proxy')]
final class Proxy
{
    /**
     * Forwards a request/response call and returns the callee's raw response bytes
     * back as an array of byte values (the Rust `Json<Vec<u8>>` shape).
     *
     * @param array<string, mixed> $request a ProxyRequest assoc array
     *
     * @return list<int> the raw response bytes, as an array of byte values
     */
    #[Handler]
    public function call(Context $ctx, array $request): array
    {
        [$service, $key, $handler, $bytes, $idempotencyKey] = $this->parseRequest($request);

        $response = $ctx->genericCall($service, $key, $handler, $bytes, $idempotencyKey);

        // `unpack('C*', '')` returns false, so guard the empty-response case explicitly.
        return $response === '' ? [] : \array_values(\unpack('C*', $response));
    }

    /**
     * Fires a one-way (fire-and-forget) call, optionally delayed, and returns the
     * callee's invocation id.
     *
     * @param array<string, mixed> $request a ProxyRequest assoc array
     */
    #[Handler]
    public function oneWayCall(Context $ctx, array $request): string
    {
        [$service, $key, $handler, $bytes, $idempotencyKey, $delayMillis] = $this->parseRequest($request);

        return $ctx->genericSend($service, $key, $handler, $bytes, $delayMillis, $idempotencyKey);
    }

    /**
     * Issues a batch of calls. Each entry is `{proxyRequest, oneWayCall, awaitAtTheEnd}`:
     *
     *   - oneWayCall=true                 -> fire-and-forget send (honouring delayMillis)
     *   - oneWayCall=false, awaitAtEnd    -> request/response call awaited before returning
     *   - oneWayCall=false, !awaitAtEnd   -> dropped (see note below)
     *
     * Note on the dropped case: the Rust implementation builds the call future but
     * never pushes/awaits it, so the future is dropped and the call never actually
     * executes. We match that by simply skipping those entries.
     *
     * Note on the awaited case: the PHP SDK exposes raw (serde-bypassing) calls only as
     * the blocking `genericCall`, with no non-blocking raw-future variant. So instead of
     * issuing all awaited calls concurrently and awaiting at the very end, we collect the
     * awaitAtTheEnd entries and perform them as blocking awaited calls before returning.
     * The observable contract — every awaited call completes before the handler returns —
     * is preserved.
     *
     * @param list<array<string, mixed>> $requests
     */
    #[Handler]
    public function manyCalls(Context $ctx, array $requests): void
    {
        /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: ?string}> $awaited */
        $awaited = [];

        foreach ($requests as $entry) {
            /** @var array<string, mixed> $request */
            $request = $entry['proxyRequest'] ?? [];
            $oneWayCall = (bool) ($entry['oneWayCall'] ?? false);
            $awaitAtTheEnd = (bool) ($entry['awaitAtTheEnd'] ?? false);

            [$service, $key, $handler, $bytes, $idempotencyKey, $delayMillis] = $this->parseRequest($request);

            if ($oneWayCall) {
                $ctx->genericSend($service, $key, $handler, $bytes, $delayMillis, $idempotencyKey);

                continue;
            }

            if ($awaitAtTheEnd) {
                $awaited[] = [$service, $key, $handler, $bytes, $idempotencyKey];
            }
        }

        foreach ($awaited as [$service, $key, $handler, $bytes, $idempotencyKey]) {
            $ctx->genericCall($service, $key, $handler, $bytes, $idempotencyKey);
        }
    }

    /**
     * Resolves a ProxyRequest assoc array into raw-call arguments.
     *
     * @param array<string, mixed> $request
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: ?int}
     *         [serviceName, targetKey, handlerName, rawBytes, idempotencyKey, delayMillis]
     */
    private function parseRequest(array $request): array
    {
        // A non-null virtualObjectKey selects a Virtual Object target; null -> Service.
        // For the raw call/send APIs, an empty key string ('') denotes a Service target.
        $virtualObjectKey = $request['virtualObjectKey'] ?? null;
        $key = $virtualObjectKey !== null ? (string) $virtualObjectKey : '';

        // Rebuild the raw request body from its byte values (the Rust `Vec<u8>`).
        /** @var list<int> $message */
        $message = $request['message'] ?? [];
        $bytes = $message === [] ? '' : \pack('C*', ...\array_map('intval', $message));

        $idempotencyKey = $request['idempotencyKey'] ?? null;
        $delayMillis = $request['delayMillis'] ?? null;

        return [
            (string) ($request['serviceName'] ?? ''),
            $key,
            (string) ($request['handlerName'] ?? ''),
            $bytes,
            $idempotencyKey !== null ? (string) $idempotencyKey : null,
            $delayMillis !== null ? (int) $delayMillis : null,
        ];
    }
}
