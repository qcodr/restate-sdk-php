<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Endpoint\Identity;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Endpoint\Identity\IdentityKey;
use Restate\Sdk\Endpoint\Identity\IdentityKeyException;

final class IdentityKeyTest extends TestCase
{
    /** A real key string from the Restate docs; decodes to 32 bytes. */
    private const SAMPLE_KEY = 'publickeyv1_w7YHemBctH5Ck2nQRQ47iBBqhNHy4FV7t2Usbye2A6f';

    public function testParsesValidPublicKey(): void
    {
        $key = IdentityKey::fromString(self::SAMPLE_KEY);

        self::assertSame(self::SAMPLE_KEY, $key->kid);
    }

    public function testRejectsKeyWithoutPrefix(): void
    {
        $this->expectException(IdentityKeyException::class);
        // Pin the exact message so mutations of the `throw` and of every operand
        // in the "must start with 'publickeyv1_'" concatenation are detected:
        // dropping the throw lets parsing continue and surface a *different*
        // exception/message, and any reordered/removed operand changes this one.
        $this->expectExceptionMessage("Request identity key must start with 'publickeyv1_'");

        IdentityKey::fromString('w7YHemBctH5Ck2nQRQ47iBBqhNHy4FV7t2Usbye2A6f');
    }

    public function testRejectsWrongPrefixOfCorrectLengthWithValidBody(): void
    {
        // A 12-character prefix that is not `publickeyv1_` followed by a body that
        // *would* decode to a valid 32-byte key. If the prefix `throw` were
        // removed, parsing would strip these 12 bytes and accept the key with no
        // exception at all -- so this asserts the guard genuinely rejects.
        $this->expectException(IdentityKeyException::class);
        $this->expectExceptionMessage("Request identity key must start with 'publickeyv1_'");

        IdentityKey::fromString('xxxxxxxxxxxx' . \substr(self::SAMPLE_KEY, 12));
    }

    public function testRejectsKeyWithInvalidBase58(): void
    {
        $this->expectException(IdentityKeyException::class);
        // Pin the message: if the invalid-character guard stopped firing, the
        // garbage would silently decode to the wrong length and surface the
        // "must decode to 32 bytes" message instead of this one.
        $this->expectExceptionMessage('Request identity key is not valid base58');

        // '0', 'O', 'I' and 'l' are not in the base58 alphabet.
        IdentityKey::fromString('publickeyv1_0OIl0OIl0OIl0OIl0OIl0OIl0OIl0OIl0OIl');
    }

    public function testRejectsKeyOfWrongLength(): void
    {
        $this->expectException(IdentityKeyException::class);

        // Valid base58 but decodes to far fewer than 32 bytes.
        IdentityKey::fromString('publickeyv1_abc');
    }

    public function testVerifiesAGenuineEd25519Signature(): void
    {
        $keyPair = \sodium_crypto_sign_keypair();
        $publicKey = \sodium_crypto_sign_publickey($keyPair);
        $secretKey = \sodium_crypto_sign_secretkey($keyPair);

        $key = IdentityKey::fromString('publickeyv1_' . IdentitySigner::base58Encode($publicKey));

        $message = 'hello.world';
        $signature = \sodium_crypto_sign_detached($message, $secretKey);

        self::assertTrue($key->verifySignature($message, $signature));
        self::assertFalse($key->verifySignature($message . 'tampered', $signature));
        self::assertFalse($key->verifySignature($message, 'too-short'));
    }
}
