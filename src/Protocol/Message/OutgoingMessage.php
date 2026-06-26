<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Message;

use Restate\Sdk\Protocol\MessageType;

/**
 * A protocol message the SDK writes to the runtime.
 *
 * Implementations expose their frame type and their protobuf payload; the
 * {@see \Restate\Sdk\Protocol\MessageCodec} prefixes the 64-bit header.
 */
interface OutgoingMessage
{
    public function messageType(): MessageType;

    public function encode(): string;

    /** Whether the frame header must set the REQUESTED_ACK flag. */
    public function requestedAck(): bool;
}
