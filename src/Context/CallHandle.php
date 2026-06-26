<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Context;

/**
 * A handle to an in-flight request-response call.
 *
 * A call yields two durable results: the callee's invocation id (available before
 * the call finishes, so it can be observed or used to cancel/attach) and the call
 * result. This immutable handle exposes both as {@see DurableFuture}s, letting the
 * caller await whichever it needs — or compose them with {@see Context::select} /
 * {@see Context::awaitAll}.
 */
final class CallHandle
{
    public function __construct(
        private readonly DurableFuture $result,
        private readonly DurableFuture $invocationId,
    ) {
    }

    /** The future resolving to the call result (decoded via the context serde). */
    public function result(): DurableFuture
    {
        return $this->result;
    }

    /** The future resolving to the callee's invocation id (a string). */
    public function invocationId(): DurableFuture
    {
        return $this->invocationId;
    }
}
