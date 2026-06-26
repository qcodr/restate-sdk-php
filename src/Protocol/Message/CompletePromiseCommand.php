<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Message;

use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `CompletePromiseCommandMessage` (0x040B): resolve or reject a workflow durable
 * promise by name. Completable — the completion reports whether the promise was
 * still open (void) or had already been completed (failure).
 */
final class CompletePromiseCommand implements OutgoingMessage
{
    private function __construct(
        public readonly string $key,
        public readonly ?Value $value,
        public readonly ?Failure $failure,
        public readonly int $resultCompletionId,
        public readonly string $name,
    ) {
    }

    public static function resolve(string $key, string $value, int $resultCompletionId, string $name = ''): self
    {
        return new self($key, new Value($value), null, $resultCompletionId, $name);
    }

    public static function reject(string $key, Failure $failure, int $resultCompletionId, string $name = ''): self
    {
        return new self($key, null, $failure, $resultCompletionId, $name);
    }

    public function messageType(): MessageType
    {
        return MessageType::CompletePromiseCommand;
    }

    public function encode(): string
    {
        $writer = (new Writer())->writeStringPresent(1, $this->key);
        if ($this->value !== null) {
            $writer->writeMessage(2, $this->value->encode());
        } elseif ($this->failure !== null) {
            $writer->writeMessage(3, $this->failure->encode());
        }

        return $writer
            ->writeUint32(11, $this->resultCompletionId)
            ->writeString(12, $this->name)
            ->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
