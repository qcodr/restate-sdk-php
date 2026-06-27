<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\Message\Failure;
use Qcodr\Restate\Sdk\Protocol\Message\SendSignalCommand;
use Qcodr\Restate\Sdk\Protocol\Message\Value;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\Protobuf\WireType;
use ReflectionClass;

final class SendSignalCommandNamedTest extends TestCase
{
    /**
     * The constructor is private and the only public factory is {@see SendSignalCommand::cancel}
     * (which addresses the built-in CANCEL idx), so the custom-named-signal encode
     * branch is reached here through the constructor directly. A named signal must
     * encode its name into field 3 and must NOT also emit the built-in idx (field 2).
     */
    public function testNamedSignalEncodesNameInFieldThreeAndOmitsTheBuiltInIdx(): void
    {
        $command = self::namedSignal('inv-target', 'my-signal');

        $fields = self::fields($command->encode());

        self::assertSame('inv-target', $fields[1]);
        self::assertSame('my-signal', $fields[3]);
        self::assertArrayNotHasKey(2, $fields, 'a named signal must not also emit the built-in idx');
    }

    public function testNamedSignalWithVoidResultEmitsFieldFour(): void
    {
        $command = self::namedSignal('inv-7', 'done', void: true);

        $fields = self::fields($command->encode());

        self::assertSame('done', $fields[3]);
        self::assertArrayHasKey(4, $fields, 'a void result is present as field 4');
        self::assertSame('', $fields[4]);
    }

    public function testResolveNamedEncodesNameAndValueResult(): void
    {
        $command = SendSignalCommand::resolveNamed('inv-target', 'my-signal', new Value('"v"'));

        self::assertSame(MessageType::SendSignalCommand, $command->messageType());
        self::assertFalse($command->requestedAck());

        $fields = self::fields($command->encode());
        self::assertSame('inv-target', $fields[1]);
        self::assertSame('my-signal', $fields[3]);
        self::assertArrayNotHasKey(2, $fields, 'a named signal must not emit the built-in idx');
        self::assertArrayNotHasKey(4, $fields, 'a value result must not also emit a void');
        self::assertArrayNotHasKey(6, $fields, 'a resolve carries no failure');

        $valueBytes = $fields[5];
        self::assertIsString($valueBytes);
        self::assertSame('"v"', Value::decode($valueBytes)->content);
    }

    public function testRejectNamedEncodesNameAndFailureResult(): void
    {
        $command = SendSignalCommand::rejectNamed('inv-target', 'my-signal', new Failure(409, 'denied'));

        $fields = self::fields($command->encode());
        self::assertSame('inv-target', $fields[1]);
        self::assertSame('my-signal', $fields[3]);
        self::assertArrayNotHasKey(2, $fields);
        self::assertArrayNotHasKey(4, $fields);
        self::assertArrayNotHasKey(5, $fields, 'a reject carries no value');

        $failureBytes = $fields[6];
        self::assertIsString($failureBytes);
        $failure = Failure::decode($failureBytes);
        self::assertSame(409, $failure->code);
        self::assertSame('denied', $failure->message);
    }

    private static function namedSignal(string $target, string $name, bool $void = false): SendSignalCommand
    {
        $class = new ReflectionClass(SendSignalCommand::class);
        $constructor = $class->getConstructor();
        self::assertNotNull($constructor);
        $constructor->setAccessible(true);

        $command = $class->newInstanceWithoutConstructor();
        $constructor->invoke($command, $target, null, $name, $void, '');

        return $command;
    }

    /** @return array<int, int|string> */
    private static function fields(string $bytes): array
    {
        $reader = new Reader($bytes);
        $fields = [];
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            if ($wire === WireType::VARINT) {
                $fields[$field] = $reader->readVarint();
            } elseif ($wire === WireType::LENGTH_DELIMITED) {
                $fields[$field] = $reader->readLengthDelimited();
            } else {
                $reader->skip($wire);
            }
        }

        return $fields;
    }
}
