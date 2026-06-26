<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Endpoint;

/**
 * The transport mode an endpoint advertises in its discovery manifest.
 *
 * `RequestResponse` is the default: every invocation is a single
 * request/response exchange. `BidiStream` opts the endpoint into the
 * bidirectional streaming transport for hosts that support it.
 */
enum ProtocolMode: string
{
    case RequestResponse = 'REQUEST_RESPONSE';
    case BidiStream = 'BIDI_STREAM';
}
