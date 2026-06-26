<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Protocol\Message\Header;
use Restate\Sdk\Protocol\Message\OneWayCallCommand;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Reader;

final class OneWayCallCommandTest extends TestCase
{
    public function testEncodesHeadersAndIdempotencyKeyAlongsideTheCallTarget(): void
    {
        $command = new OneWayCallCommand(
            serviceName: 'svc',
            handlerName: 'handler',
            parameter: 'param-bytes',
            invocationIdNotificationIdx: 7,
            invokeTimeMillis: 1234,
            key: 'obj-key',
            idempotencyKey: 'idem-42',
            headers: [new Header('h1', 'v1'), new Header('h2', 'v2')],
            name: 'one-way',
        );

        self::assertSame(MessageType::OneWayCallCommand, $command->messageType());
        self::assertFalse($command->requestedAck());

        $reader = new Reader($command->encode());
        $serviceName = null;
        $handlerName = null;
        $parameter = null;
        $invokeTime = null;
        $headers = [];
        $key = null;
        $idempotencyKey = null;
        $idx = null;
        $name = null;

        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1:
                    $serviceName = $reader->readLengthDelimited();
                    break;
                case 2:
                    $handlerName = $reader->readLengthDelimited();
                    break;
                case 3:
                    $parameter = $reader->readLengthDelimited();
                    break;
                case 4:
                    $invokeTime = $reader->readVarint();
                    break;
                case 5:
                    $headers[] = Header::decode($reader->readLengthDelimited());
                    break;
                case 6:
                    $key = $reader->readLengthDelimited();
                    break;
                case 7:
                    $idempotencyKey = $reader->readLengthDelimited();
                    break;
                case 10:
                    $idx = $reader->readVarint();
                    break;
                case 12:
                    $name = $reader->readLengthDelimited();
                    break;
                default:
                    $reader->skip($wire);
            }
        }

        self::assertSame('svc', $serviceName);
        self::assertSame('handler', $handlerName);
        self::assertSame('param-bytes', $parameter);
        self::assertSame(1234, $invokeTime);
        self::assertCount(2, $headers);
        self::assertSame('h1', $headers[0]->key);
        self::assertSame('v1', $headers[0]->value);
        self::assertSame('h2', $headers[1]->key);
        self::assertSame('v2', $headers[1]->value);
        self::assertSame('obj-key', $key);
        self::assertSame('idem-42', $idempotencyKey);
        self::assertSame(7, $idx);
        self::assertSame('one-way', $name);
    }

    public function testEmptyIdempotencyKeyOmitsFieldSeven(): void
    {
        // The idempotency key is only emitted when non-null and non-empty, so an
        // empty key must not produce field 7.
        $command = new OneWayCallCommand('s', 'h', 'p', 1, idempotencyKey: '');

        $reader = new Reader($command->encode());
        $hasField7 = false;
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            if ($field === 7) {
                $hasField7 = true;
            }
            $reader->skip($wire);
        }

        self::assertFalse($hasField7);
    }
}
