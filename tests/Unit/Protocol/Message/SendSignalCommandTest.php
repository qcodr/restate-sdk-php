<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\Message\SendSignalCommand;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;

final class SendSignalCommandTest extends TestCase
{
    public function testCancelEncodesTargetIdxAndVoid(): void
    {
        $command = SendSignalCommand::cancel('inv-target-99');

        self::assertSame(MessageType::SendSignalCommand, $command->messageType());
        self::assertFalse($command->requestedAck());

        $reader = new Reader($command->encode());
        $targetInvocationId = null;
        $idx = null;
        $voidPresent = false;

        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1:
                    $targetInvocationId = $reader->readLengthDelimited();
                    break;
                case 2:
                    $idx = $reader->readVarint();
                    break;
                case 4:
                    $reader->readLengthDelimited(); // Void
                    $voidPresent = true;
                    break;
                default:
                    $reader->skip($wire);
            }
        }

        self::assertSame('inv-target-99', $targetInvocationId, 'target invocation id in field 1');
        self::assertSame(1, $idx, 'built-in CANCEL idx (1) in field 2');
        self::assertTrue($voidPresent, 'void result present in field 4');
    }

    public function testCancelDoesNotEncodeSignalName(): void
    {
        $reader = new Reader(SendSignalCommand::cancel('inv-1')->encode());

        $fields = [];
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            $fields[] = $field;
            $reader->skip($wire);
        }

        self::assertContains(2, $fields, 'signal idx (field 2) is set');
        self::assertNotContains(3, $fields, 'signal name (field 3) must not be set for a cancel');
    }
}
