<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Restate\Sdk\Protocol\Message\SendSignalCommand;
use Restate\Sdk\Protocol\Protobuf\Reader;
use Restate\Sdk\Protocol\Protobuf\WireType;

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
