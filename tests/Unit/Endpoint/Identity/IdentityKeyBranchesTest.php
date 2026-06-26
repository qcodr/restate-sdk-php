<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Endpoint\Identity;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Restate\Sdk\Endpoint\Identity\IdentityKey;
use Restate\Sdk\Endpoint\Identity\IdentityKeyException;

/**
 * Branch coverage for {@see IdentityKey}: base58 edge cases (empty body, leading
 * zero bytes) and the documented fail-closed guards on {@see IdentityKey::verifySignature()}.
 */
final class IdentityKeyBranchesTest extends TestCase
{
    public function testEmptyBodyAfterPrefixIsRejected(): void
    {
        $this->expectException(IdentityKeyException::class);
        $this->expectExceptionMessage('must decode to 32 bytes, got 0');

        // Prefix only: base58-decoding the empty remainder yields zero bytes.
        IdentityKey::fromString('publickeyv1_');
    }

    public function testAllOnesBodyDecodesToThirtyTwoZeroBytes(): void
    {
        // In base58 a leading '1' encodes a zero byte; 32 ones decode to 32 zero
        // bytes, which is a structurally valid (if cryptographically useless) key.
        $kid = 'publickeyv1_' . \str_repeat('1', 32);

        $key = IdentityKey::fromString($kid);

        self::assertSame($kid, $key->kid);
        // An all-zero public key still fails closed for any real signature.
        self::assertFalse($key->verifySignature('message', \str_repeat("\x00", \SODIUM_CRYPTO_SIGN_BYTES)));
    }

    public function testVerifySignatureRejectsWrongLengthSignature(): void
    {
        $key = IdentityKey::fromString('publickeyv1_' . \str_repeat('1', 32));

        // One byte short of SODIUM_CRYPTO_SIGN_BYTES: rejected before touching sodium.
        $shortSignature = \str_repeat("\x00", \SODIUM_CRYPTO_SIGN_BYTES - 1);

        self::assertFalse($key->verifySignature('message', $shortSignature));
    }

    public function testVerifySignatureFailsClosedOnEmptyRawKey(): void
    {
        // The constructor is private and fromString enforces a 32-byte key, so the
        // empty-key guard is only reachable defensively; inject it to prove it
        // honours the documented "fail closed" contract rather than erroring.
        $key = self::keyWithRawBytes('');

        self::assertFalse($key->verifySignature('message', \str_repeat("\x00", \SODIUM_CRYPTO_SIGN_BYTES)));
    }

    public function testVerifySignatureFailsClosedOnSodiumException(): void
    {
        // A non-empty but wrong-length raw key makes sodium throw; the guard must
        // swallow it and return false instead of propagating SodiumException.
        $key = self::keyWithRawBytes(\str_repeat("\x01", 31));

        self::assertFalse($key->verifySignature('message', \str_repeat("\x00", \SODIUM_CRYPTO_SIGN_BYTES)));
    }

    private static function keyWithRawBytes(string $rawPublicKey): IdentityKey
    {
        $reflection = new ReflectionClass(IdentityKey::class);
        $key = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('rawPublicKey')->setValue($key, $rawPublicKey);
        $reflection->getProperty('kid')->setValue($key, 'publickeyv1_injected');

        return $key;
    }
}
