<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;

/**
 * A decoded notification message (completion or signal).
 *
 * Every concrete notification message in the protocol shares the field numbers of
 * `NotificationTemplate`, so a single generic decoder handles all of them: pick
 * out the id (completion id, signal id, or signal name) and the result, then route
 * it to the awaiting future via a lookup table.
 */
final class Notification
{
    public function __construct(
        public readonly ?int $completionId,
        public readonly ?int $signalId,
        public readonly ?string $signalName,
        public readonly NotificationResult $resultKind,
        public readonly ?string $value,
        public readonly ?Failure $failure,
        public readonly ?string $invocationId,
        public readonly ?StateKeys $stateKeys,
    ) {
    }

    public static function decode(string $bytes): self
    {
        $reader = new Reader($bytes);
        $completionId = null;
        $signalId = null;
        $signalName = null;
        $resultKind = NotificationResult::None;
        $value = null;
        $failure = null;
        $invocationId = null;
        $stateKeys = null;

        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1:
                    $completionId = $reader->readVarint();
                    break;
                case 2:
                    $signalId = $reader->readVarint();
                    break;
                case 3:
                    $signalName = $reader->readLengthDelimited();
                    break;
                case 4:
                    $reader->readLengthDelimited(); // Void
                    $resultKind = NotificationResult::Void;
                    break;
                case 5:
                    $value = Value::decode($reader->readLengthDelimited())->content;
                    $resultKind = NotificationResult::Value;
                    break;
                case 6:
                    $failure = Failure::decode($reader->readLengthDelimited());
                    $resultKind = NotificationResult::Failure;
                    break;
                case 16:
                    $invocationId = $reader->readLengthDelimited();
                    $resultKind = NotificationResult::InvocationId;
                    break;
                case 17:
                    $stateKeys = StateKeys::decode($reader->readLengthDelimited());
                    $resultKind = NotificationResult::StateKeys;
                    break;
                default:
                    $reader->skip($wire);
            }
        }

        return new self(
            $completionId,
            $signalId,
            $signalName,
            $resultKind,
            $value,
            $failure,
            $invocationId,
            $stateKeys,
        );
    }
}
