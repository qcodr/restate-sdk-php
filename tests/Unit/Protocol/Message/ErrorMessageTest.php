<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Protocol\ErrorBehavior;
use Restate\Sdk\Protocol\Message\ErrorMessage;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Reader;

final class ErrorMessageTest extends TestCase
{
    /**
     * @return array{code: ?int, message: ?string, nextRetryDelay: ?int, behavior: ?int}
     */
    private function decode(string $payload): array
    {
        $reader = new Reader($payload);
        $decoded = ['code' => null, 'message' => null, 'nextRetryDelay' => null, 'behavior' => null];

        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1:
                    $decoded['code'] = $reader->readVarint();
                    break;
                case 2:
                    $decoded['message'] = $reader->readLengthDelimited();
                    break;
                case 8:
                    $decoded['nextRetryDelay'] = $reader->readVarint();
                    break;
                case 9:
                    $decoded['behavior'] = $reader->readVarint();
                    break;
                default:
                    $reader->skip($wire);
            }
        }

        return $decoded;
    }

    public function testEncodesNextRetryDelayAndBehavior(): void
    {
        $message = new ErrorMessage(
            500,
            'boom',
            '',
            null,
            2500,
            ErrorBehavior::Pause,
        );

        self::assertSame(MessageType::Error, $message->messageType());

        $decoded = $this->decode($message->encode());
        self::assertSame(500, $decoded['code']);
        self::assertSame('boom', $decoded['message']);
        self::assertSame(2500, $decoded['nextRetryDelay'], 'next_retry_delay in field 8');
        self::assertSame(ErrorBehavior::Pause->value, $decoded['behavior'], 'behavior in field 9 is Pause');
    }

    public function testDefaultRetryBehaviorOmitsFields8And9(): void
    {
        $decoded = $this->decode((new ErrorMessage(571, 'protocol violation'))->encode());

        self::assertSame(571, $decoded['code']);
        self::assertNull($decoded['nextRetryDelay'], 'field 8 omitted when no delay is set');
        self::assertNull($decoded['behavior'], 'field 9 omitted for the default RETRY behavior (0)');
    }

    public function testRetryWithDelayEncodesField8ButNotField9(): void
    {
        $decoded = $this->decode((new ErrorMessage(500, 'retry me', '', null, 1000))->encode());

        self::assertSame(1000, $decoded['nextRetryDelay']);
        self::assertNull($decoded['behavior'], 'RETRY (0) stays omitted even with a delay');
    }
}
