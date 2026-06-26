<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Endpoint;

use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Service\HandlerDefinition;
use Qcodr\Restate\Sdk\Service\ServiceDefinition;

/**
 * A resolved invoke target ready to be driven over a bidirectional
 * {@see StreamTransport}.
 *
 * {@see RequestProcessor::resolveStreamingInvoke()} returns one of these once it has
 * verified the request identity, negotiated the protocol version and matched the
 * service/handler — so a streaming transport (e.g. the amphp server) can open the
 * channel and hand it straight to {@see RequestProcessor::driveStreaming()} without
 * re-implementing any of that routing.
 */
final class StreamingInvocation
{
    public function __construct(
        public readonly ServiceDefinition $service,
        public readonly HandlerDefinition $handler,
        public readonly ServiceProtocolVersion $version,
    ) {
    }
}
