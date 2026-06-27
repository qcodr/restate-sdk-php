<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Support\Fixtures;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

/**
 * Fixture exercising call ergonomics: idempotency keys, custom headers, the call
 * handle (callee invocation id) and the request-metadata accessors.
 */
#[Service]
final class CallOptionsService
{
    /** Issues a call carrying an idempotency key and a custom header, then returns. */
    #[Handler]
    public function callWithOptions(Context $ctx): string
    {
        $ctx->serviceCallAsync(
            'Target',
            'receive',
            'payload',
            'idem-key-123',
            ['x-trace-id' => 'trace-abc'],
        );

        return 'issued';
    }

    /** Issues a call and returns the callee's invocation id from the call handle. */
    #[Handler]
    public function callAndReturnInvocationId(Context $ctx): string
    {
        $handle = $ctx->serviceCallHandle('Target', 'receive', 'payload');
        $invocationId = $handle->invocationId()->await();

        return \is_string($invocationId) ? $invocationId : '';
    }

    /**
     * Awaits the callee's invocation id (completion 1) and then its result (completion 2)
     * in sequence, returning the result. Used to prove the streaming driver drains both
     * awaits when the runtime batches both completions into a single inbound chunk.
     */
    #[Handler]
    public function callAwaitIdThenResult(Context $ctx): string
    {
        $handle = $ctx->serviceCallHandle('Target', 'receive', 'payload');
        $handle->invocationId()->await();
        $result = $handle->result()->await();

        return \is_string($result) ? $result : '';
    }

    /**
     * Echoes the request metadata captured from the StartMessage / InputCommand.
     *
     * @return array{headers: array<string, string>, idempotencyKey: ?string}
     */
    #[Handler]
    public function readMetadata(Context $ctx): array
    {
        return [
            'headers' => $ctx->requestHeaders(),
            'idempotencyKey' => $ctx->requestIdempotencyKey(),
        ];
    }
}
