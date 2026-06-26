<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\MessageType;

final class MessageTypeTest extends TestCase
{
    public function testIsControlMatchesOnlyTheControlNamespace(): void
    {
        self::assertTrue(MessageType::Start->isControl());
        self::assertTrue(MessageType::End->isControl());
        self::assertTrue(MessageType::Suspension->isControl());

        self::assertFalse(MessageType::InputCommand->isControl());
        self::assertFalse(MessageType::SignalNotification->isControl());
    }

    public function testIsCommandMatchesOnlyTheCommandNamespace(): void
    {
        self::assertTrue(MessageType::InputCommand->isCommand());
        self::assertTrue(MessageType::CompleteAwakeableCommand->isCommand());

        self::assertFalse(MessageType::Start->isCommand());
        self::assertFalse(MessageType::GetLazyStateCompletion->isCommand());
    }

    public function testIsNotificationMatchesOnlyTheNotificationNamespace(): void
    {
        self::assertTrue(MessageType::GetLazyStateCompletion->isNotification());
        self::assertTrue(MessageType::SignalNotification->isNotification());

        self::assertFalse(MessageType::InputCommand->isNotification());
        self::assertFalse(MessageType::Start->isNotification());
    }

    public function testCodeIsNotificationChecksTheRawNotificationRange(): void
    {
        // Lower bound (0x8000) inclusive, custom range (0xFC00) exclusive.
        self::assertTrue(MessageType::codeIsNotification(0x8002));
        self::assertTrue(MessageType::codeIsNotification(0xFBFF));

        self::assertFalse(MessageType::codeIsNotification(0x0400), 'a command code is not a notification');
        self::assertFalse(MessageType::codeIsNotification(0xFC00), 'the custom range starts above notifications');
    }
}
