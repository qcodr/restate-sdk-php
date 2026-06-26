<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Message;

use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `GetEagerStateCommandMessage` (0x0407): read a state key with the result inlined
 * from the eager state map. Non-completable — the SDK already knows the answer.
 *
 * `void` (field 13) means the key is absent; `value` (field 14) carries its bytes.
 */
final class GetEagerStateCommand implements OutgoingMessage
{
    private function __construct(
        public readonly string $key,
        public readonly bool $found,
        public readonly string $value,
        public readonly string $name,
    ) {
    }

    public static function found(string $key, string $value, string $name = ''): self
    {
        return new self($key, true, $value, $name);
    }

    public static function empty(string $key, string $name = ''): self
    {
        return new self($key, false, '', $name);
    }

    public function messageType(): MessageType
    {
        return MessageType::GetEagerStateCommand;
    }

    public function encode(): string
    {
        $writer = (new Writer())->writeBytesPresent(1, $this->key);

        if ($this->found) {
            $writer->writeMessage(14, (new Value($this->value))->encode());
        } else {
            $writer->writeMessage(13, ''); // Void
        }

        return $writer->writeString(12, $this->name)->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
