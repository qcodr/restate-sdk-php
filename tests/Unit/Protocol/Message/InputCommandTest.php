<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\Message\Header;
use Qcodr\Restate\Sdk\Protocol\Message\InputCommand;
use Qcodr\Restate\Sdk\Protocol\Message\Value;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

final class InputCommandTest extends TestCase
{
    public function testDecodesBodyAndHeadersAndSkipsUnknownFields(): void
    {
        $bytes = (new Writer())
            ->writeMessage(1, (new Header('h-key', 'h-val'))->encode())
            ->writeUint32Present(99, 7)
            ->writeMessage(14, (new Value('input-body'))->encode())
            ->toString();

        $command = InputCommand::decode($bytes);

        self::assertSame('input-body', $command->body);
        self::assertCount(1, $command->headers);
        self::assertSame('h-key', $command->headers[0]->key);
        self::assertSame('h-val', $command->headers[0]->value);
    }

    public function testDecodesMultipleHeadersInOrder(): void
    {
        $bytes = (new Writer())
            ->writeMessage(1, (new Header('a', '1'))->encode())
            ->writeMessage(1, (new Header('b', '2'))->encode())
            ->toString();

        $command = InputCommand::decode($bytes);

        self::assertSame('', $command->body);
        self::assertCount(2, $command->headers);
        self::assertSame('a', $command->headers[0]->key);
        self::assertSame('b', $command->headers[1]->key);
    }
}
