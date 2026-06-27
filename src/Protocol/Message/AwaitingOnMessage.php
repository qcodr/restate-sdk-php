<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `AwaitingOnMessage` (0x0006, service protocol V7): over bidirectional streaming, tells
 * the runtime which notifications the handler is currently blocked awaiting, so it pushes
 * the matching completions/signals onto the open stream — including *external* ones the
 * SDK cannot pull itself, such as the built-in CANCEL signal or an awakeable resolved by
 * another invocation. Without it the runtime never learns a parked invocation is waiting
 * on a cancel, so a cancel of a suspended invocation is stored but never delivered.
 *
 * The runtime treats it as a hint, superseded as soon as it sends any notification in the
 * await tree. The request/response transport never sends it — it suspends with a
 * {@see SuspensionMessage} instead.
 */
final class AwaitingOnMessage implements OutgoingMessage
{
    public function __construct(public readonly Future $awaitingOn)
    {
    }

    public function messageType(): MessageType
    {
        return MessageType::AwaitingOn;
    }

    public function encode(): string
    {
        // Field 1: the await-point Future. Field 2 (executing_side_effects) defaults to
        // false and is omitted — this SDK proposes run completions eagerly rather than
        // parking the stream on an in-flight side effect.
        return (new Writer())->writeMessage(1, $this->awaitingOn->encode())->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
