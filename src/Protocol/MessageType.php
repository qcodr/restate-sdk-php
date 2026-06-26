<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol;

/**
 * The 16-bit message type codes of the Restate service protocol.
 *
 * The type field is partitioned into namespaces:
 *   - control frames     0x0000–0x03FF
 *   - commands           0x0400–0x7FFF   (command | 0x0400)
 *   - notifications      0x8000–0xFBFF   (notification = command | 0x8000)
 *   - custom commands    0xFC00–0xFFFF
 *
 * Authoritative table: restatedev/sdk-shared-core src/service_protocol/header.rs.
 */
enum MessageType: int
{
    // --- Control frames ---
    case Start = 0x0000;
    case Suspension = 0x0001;
    case Error = 0x0002;
    case End = 0x0003;
    case ProposeRunCompletion = 0x0005;
    case AwaitingOn = 0x0006;
    case ProposeRunCompletionAck = 0x0007;

    // --- Commands ---
    case InputCommand = 0x0400;
    case OutputCommand = 0x0401;
    case GetLazyStateCommand = 0x0402;
    case SetStateCommand = 0x0403;
    case ClearStateCommand = 0x0404;
    case ClearAllStateCommand = 0x0405;
    case GetLazyStateKeysCommand = 0x0406;
    case GetEagerStateCommand = 0x0407;
    case GetEagerStateKeysCommand = 0x0408;
    case GetPromiseCommand = 0x0409;
    case PeekPromiseCommand = 0x040A;
    case CompletePromiseCommand = 0x040B;
    case SleepCommand = 0x040C;
    case CallCommand = 0x040D;
    case OneWayCallCommand = 0x040E;
    case SendSignalCommand = 0x0410;
    case RunCommand = 0x0411;
    case AttachInvocationCommand = 0x0412;
    case GetInvocationOutputCommand = 0x0413;
    case CompleteAwakeableCommand = 0x0414;

    // --- Notifications (completions + signals) ---
    case GetLazyStateCompletion = 0x8002;
    case GetLazyStateKeysCompletion = 0x8006;
    case GetPromiseCompletion = 0x8009;
    case PeekPromiseCompletion = 0x800A;
    case CompletePromiseCompletion = 0x800B;
    case SleepCompletion = 0x800C;
    case CallCompletion = 0x800D;
    case CallInvocationIdCompletion = 0x800E;
    case RunCompletion = 0x8011;
    case AttachInvocationCompletion = 0x8012;
    case GetInvocationOutputCompletion = 0x8013;
    case SignalNotification = 0xFBFF;

    private const COMMAND_RANGE_START = 0x0400;
    private const NOTIFICATION_RANGE_START = 0x8000;
    private const CUSTOM_RANGE_START = 0xFC00;

    public function isControl(): bool
    {
        return $this->value < self::COMMAND_RANGE_START;
    }

    public function isCommand(): bool
    {
        return $this->value >= self::COMMAND_RANGE_START
            && $this->value < self::NOTIFICATION_RANGE_START;
    }

    public function isNotification(): bool
    {
        return $this->value >= self::NOTIFICATION_RANGE_START
            && $this->value < self::CUSTOM_RANGE_START;
    }

    /** Whether a raw type code falls in the notification namespace. */
    public static function codeIsNotification(int $code): bool
    {
        return $code >= self::NOTIFICATION_RANGE_START && $code < self::CUSTOM_RANGE_START;
    }
}
