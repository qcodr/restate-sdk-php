<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `CompleteAwakeableCommandMessage` (0x0414): resolve or reject an awakeable
 * created by another invocation, addressed by its public awakeable id.
 */
final class CompleteAwakeableCommand implements OutgoingMessage
{
    private function __construct(
        public readonly string $awakeableId,
        public readonly ?Value $value,
        public readonly ?Failure $failure,
        public readonly string $name,
    ) {
    }

    public static function resolve(string $awakeableId, string $value, string $name = ''): self
    {
        return new self($awakeableId, new Value($value), null, $name);
    }

    public static function reject(string $awakeableId, Failure $failure, string $name = ''): self
    {
        return new self($awakeableId, null, $failure, $name);
    }

    public function messageType(): MessageType
    {
        return MessageType::CompleteAwakeableCommand;
    }

    public function encode(): string
    {
        $writer = (new Writer())->writeStringPresent(1, $this->awakeableId);
        if ($this->value !== null) {
            $writer->writeMessage(2, $this->value->encode());
        } elseif ($this->failure !== null) {
            $writer->writeMessage(3, $this->failure->encode());
        }

        return $writer->writeString(12, $this->name)->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
