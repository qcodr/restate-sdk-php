<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Vm;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Vm\AwakeableId;

/**
 * Verifies the public awakeable id encoding: the literal `prom_1` prefix followed by
 * the unpadded url-safe Base64 of the invocation id concatenated with the signal
 * index as a 32-bit big-endian unsigned integer.
 */
final class AwakeableIdTest extends TestCase
{
    public function testEncodesAKnownVector(): void
    {
        self::assertSame('prom_1aW52LTEAAAAR', AwakeableId::encode('inv-1', 17));
    }

    public function testEncodesAKnownVectorForALongerInvocationId(): void
    {
        self::assertSame(
            'prom_1bXktaW52b2NhdGlvbi1pZAAAABE',
            AwakeableId::encode('my-invocation-id', 17),
        );
    }

    public function testStartsWithTheProtocolPrefix(): void
    {
        self::assertStringStartsWith('prom_1', AwakeableId::encode('inv-1', 1));
    }

    public function testSuffixDecodesBackToInvocationIdAndBigEndianSignalIndex(): void
    {
        $id = AwakeableId::encode('inv-7', 259);
        $suffix = \substr($id, \strlen('prom_1'));

        // Restore the standard alphabet and padding, then decode and check the bytes:
        // invocation id followed by the signal index as uint32 big-endian.
        $padded = \str_pad(\strtr($suffix, '-_', '+/'), (int) (\ceil(\strlen($suffix) / 4) * 4), '=');
        $decoded = \base64_decode($padded, true);

        self::assertIsString($decoded);
        self::assertSame('inv-7' . \pack('N', 259), $decoded);
    }

    public function testEncodingIsUrlSafeAndUnpadded(): void
    {
        // A signal index of 0xFFFFFFFF produces bytes that the standard alphabet would
        // render with '+' / '/' and trailing '=' padding; all must be absent here.
        $id = AwakeableId::encode('A', 4_294_967_295);

        self::assertStringNotContainsString('+', $id);
        self::assertStringNotContainsString('/', $id);
        self::assertStringNotContainsString('=', $id);
    }

    public function testDistinctSignalIndexesProduceDistinctIds(): void
    {
        self::assertNotSame(
            AwakeableId::encode('inv-1', 17),
            AwakeableId::encode('inv-1', 18),
        );
    }

    public function testEncodingIsDeterministic(): void
    {
        self::assertSame(
            AwakeableId::encode('inv-1', 42),
            AwakeableId::encode('inv-1', 42),
        );
    }
}
