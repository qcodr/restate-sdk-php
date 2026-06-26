<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\ErrorBehavior;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `ErrorMessage` (0x0002): closes the stream on a retryable (non-terminal)
 * failure. The runtime treats this as an attempt failure and applies its retry
 * policy. Terminal failures take the output path instead (see {@see OutputCommand}).
 *
 * Since protocol V7 the SDK may also tune that retry: {@see $nextRetryDelayMillis}
 * overrides the delay before the next attempt (relevant only when retrying), and
 * {@see $behavior} can ask the runtime to pause or fail instead of retrying.
 */
final class ErrorMessage implements OutgoingMessage
{
    public const JOURNAL_MISMATCH = 570;
    public const PROTOCOL_VIOLATION = 571;

    public function __construct(
        public readonly int $code,
        public readonly string $message,
        public readonly string $stacktrace = '',
        public readonly ?int $relatedCommandIndex = null,
        public readonly ?int $nextRetryDelayMillis = null,
        public readonly ErrorBehavior $behavior = ErrorBehavior::Retry,
    ) {
    }

    public function messageType(): MessageType
    {
        return MessageType::Error;
    }

    public function encode(): string
    {
        $writer = (new Writer())
            ->writeUint32(1, $this->code)
            ->writeString(2, $this->message)
            ->writeString(3, $this->stacktrace);

        if ($this->relatedCommandIndex !== null) {
            $writer->writeUint32Present(4, $this->relatedCommandIndex);
        }

        // Field 8 is an optional uint64; presence matters, so emit it whenever set
        // (varint encoding is identical to uint32 for the value range we use).
        if ($this->nextRetryDelayMillis !== null) {
            $writer->writeUint32Present(8, $this->nextRetryDelayMillis);
        }

        // Field 9 defaults to RETRY (0); proto3 omits the default, so only non-Retry
        // behaviors are written.
        $writer->writeUint32(9, $this->behavior->value);

        return $writer->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
