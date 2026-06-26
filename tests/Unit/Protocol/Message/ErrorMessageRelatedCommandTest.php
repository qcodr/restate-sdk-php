<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Protocol\Message\ErrorMessage;
use Restate\Sdk\Protocol\Protobuf\Reader;

final class ErrorMessageRelatedCommandTest extends TestCase
{
    public function testEncodesRelatedCommandIndexInFieldFour(): void
    {
        $message = new ErrorMessage(ErrorMessage::JOURNAL_MISMATCH, 'mismatch', 'trace', 3);

        self::assertSame(3, self::relatedCommandIndex($message->encode()));
    }

    public function testRelatedCommandIndexZeroIsStillEmitted(): void
    {
        // The index uses writeUint32Present, so a related command index of 0 must
        // still appear on the wire — it is a real journal position, not the absence
        // of a related command.
        $message = new ErrorMessage(ErrorMessage::PROTOCOL_VIOLATION, 'boom', '', 0);

        self::assertSame(0, self::relatedCommandIndex($message->encode()));
    }

    public function testAbsentRelatedCommandIndexOmitsFieldFour(): void
    {
        $message = new ErrorMessage(500, 'no related command');

        self::assertNull(self::relatedCommandIndex($message->encode()));
    }

    private static function relatedCommandIndex(string $payload): ?int
    {
        $reader = new Reader($payload);
        $related = null;
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            if ($field === 4) {
                $related = $reader->readVarint();
            } else {
                $reader->skip($wire);
            }
        }

        return $related;
    }
}
