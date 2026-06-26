<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `OutputCommandMessage` (0x0401): the last journal entry the SDK writes, carrying
 * the invocation result — either a success value or a terminal failure.
 */
final class OutputCommand implements OutgoingMessage
{
    private function __construct(
        public readonly ?Value $value,
        public readonly ?Failure $failure,
    ) {
    }

    public static function success(string $value): self
    {
        return new self(new Value($value), null);
    }

    public static function failure(Failure $failure): self
    {
        return new self(null, $failure);
    }

    public function messageType(): MessageType
    {
        return MessageType::OutputCommand;
    }

    public function encode(): string
    {
        $writer = new Writer();
        if ($this->value !== null) {
            $writer->writeMessage(14, $this->value->encode());
        } elseif ($this->failure !== null) {
            $writer->writeMessage(15, $this->failure->encode());
        }

        return $writer->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
