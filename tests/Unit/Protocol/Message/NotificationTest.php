<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\Message\Failure;
use Qcodr\Restate\Sdk\Protocol\Message\Notification;
use Qcodr\Restate\Sdk\Protocol\Message\NotificationResult;
use Qcodr\Restate\Sdk\Protocol\Message\StateKeys;
use Qcodr\Restate\Sdk\Protocol\Message\Value;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

final class NotificationTest extends TestCase
{
    public function testDecodesNamedSignalCarryingAValue(): void
    {
        $payload = (new Writer())
            ->writeStringPresent(3, 'sig-name')
            ->writeMessage(5, (new Value('hello'))->encode())
            ->toString();

        $notification = Notification::decode($payload);

        self::assertSame('sig-name', $notification->signalName);
        self::assertSame('hello', $notification->value);
        self::assertSame(NotificationResult::Value, $notification->resultKind);
        self::assertNull($notification->completionId);
        self::assertNull($notification->signalId);
    }

    public function testDecodesSignalIdWithVoidResult(): void
    {
        $payload = (new Writer())
            ->writeUint32Present(2, 4)
            ->writeMessage(4, '')
            ->toString();

        $notification = Notification::decode($payload);

        self::assertSame(4, $notification->signalId);
        self::assertSame(NotificationResult::Void, $notification->resultKind);
    }

    public function testDecodesFailureResult(): void
    {
        $payload = (new Writer())
            ->writeUint32Present(1, 2)
            ->writeMessage(6, (new Failure(409, 'conflict'))->encode())
            ->toString();

        $notification = Notification::decode($payload);

        self::assertSame(2, $notification->completionId);
        self::assertInstanceOf(Failure::class, $notification->failure);
        self::assertSame(409, $notification->failure->code);
        self::assertSame('conflict', $notification->failure->message);
        self::assertSame(NotificationResult::Failure, $notification->resultKind);
    }

    public function testDecodesInvocationIdResult(): void
    {
        $payload = (new Writer())
            ->writeUint32Present(1, 6)
            ->writeStringPresent(16, 'inv-abc')
            ->toString();

        $notification = Notification::decode($payload);

        self::assertSame('inv-abc', $notification->invocationId);
        self::assertSame(NotificationResult::InvocationId, $notification->resultKind);
    }

    public function testDecodesStateKeysResult(): void
    {
        $payload = (new Writer())
            ->writeUint32Present(1, 9)
            ->writeMessage(17, (new StateKeys(['k1', 'k2']))->encode())
            ->toString();

        $notification = Notification::decode($payload);

        self::assertSame(9, $notification->completionId);
        self::assertInstanceOf(StateKeys::class, $notification->stateKeys);
        self::assertSame(['k1', 'k2'], $notification->stateKeys->keys);
        self::assertSame(NotificationResult::StateKeys, $notification->resultKind);
    }

    public function testSkipsUnknownFieldsAndLeavesResultKindNone(): void
    {
        $payload = (new Writer())
            ->writeUint32Present(1, 3)
            ->writeUint32Present(99, 123)
            ->toString();

        $notification = Notification::decode($payload);

        self::assertSame(3, $notification->completionId);
        self::assertSame(NotificationResult::None, $notification->resultKind);
        self::assertNull($notification->value);
        self::assertNull($notification->failure);
    }
}
