<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Endpoint\Identity;

use SodiumException;

/**
 * A single Restate request-identity public key.
 *
 * Restate identifies itself with an Ed25519 key pair. The public half is shared
 * with the SDK as a compact, base58-encoded string prefixed with
 * `publickeyv1_` (the same `kid` the Restate server logs on startup, e.g.
 * `publickeyv1_w7YHemBctH5Ck2nQRQ47iBBqhNHy4FV7t2Usbye2A6f`).
 *
 * Decoding mirrors `restate-sdk-shared-core` (`src/request_identity.rs`,
 * `parse_key`): strip the `publickeyv1_` prefix, base58-decode (Bitcoin
 * alphabet) the remainder, and require exactly 32 bytes — the raw Ed25519
 * public key consumed by {@see \sodium_crypto_sign_verify_detached()}.
 */
final class IdentityKey
{
    private const PREFIX = 'publickeyv1_';

    /** Raw Ed25519 public key size, in bytes. */
    private const PUBLIC_KEY_BYTES = 32;

    /** Bitcoin / IPFS base58 alphabet used by the `bs58` Rust crate default. */
    private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /** ceil(log(58) / log(256) * 1000) headroom factor for the decode buffer. */
    private const BASE256_FACTOR = 733;

    private function __construct(
        private readonly string $rawPublicKey,
        public readonly string $kid,
    ) {
    }

    /**
     * Parses a `publickeyv1_...` key string.
     *
     * @throws IdentityKeyException when the prefix is missing, the base58 body is
     *                              malformed, or the decoded key is not 32 bytes.
     */
    public static function fromString(string $publicKey): self
    {
        if (!\str_starts_with($publicKey, self::PREFIX)) {
            throw new IdentityKeyException(
                "Request identity key must start with '" . self::PREFIX . "'",
            );
        }

        $encoded = \substr($publicKey, \strlen(self::PREFIX));
        $decoded = self::base58Decode($encoded);
        if ($decoded === null) {
            throw new IdentityKeyException('Request identity key is not valid base58');
        }

        if (\strlen($decoded) !== self::PUBLIC_KEY_BYTES) {
            throw new IdentityKeyException(
                'Request identity key must decode to ' . self::PUBLIC_KEY_BYTES
                . ' bytes, got ' . \strlen($decoded),
            );
        }

        return new self($decoded, $publicKey);
    }

    /**
     * Verifies a detached Ed25519 signature over $message with this key.
     *
     * Any malformed input (wrong signature length, sodium failure) fails closed
     * by returning false rather than throwing.
     */
    public function verifySignature(string $message, string $signature): bool
    {
        if (\strlen($signature) !== \SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        // The raw key is always 32 bytes (enforced in fromString); guard
        // defensively so verification fails closed rather than erroring.
        if ($this->rawPublicKey === '') {
            return false;
        }

        try {
            return \sodium_crypto_sign_verify_detached($signature, $message, $this->rawPublicKey);
        } catch (SodiumException) {
            return false;
        }
    }

    /**
     * Decodes a base58 (Bitcoin alphabet) string into its raw bytes.
     *
     * Pure-PHP port of the canonical base-x algorithm; returns null on any
     * character outside the alphabet.
     */
    private static function base58Decode(string $input): ?string
    {
        $length = \strlen($input);
        if ($length === 0) {
            return '';
        }

        $zeroes = 0;
        $position = 0;
        while ($position < $length && $input[$position] === '1') {
            ++$zeroes;
            ++$position;
        }

        $size = (int) (($length - $position) * self::BASE256_FACTOR / 1000 + 1);
        $bytes = \array_fill(0, $size, 0);

        $outputLength = 0;
        for (; $position < $length; ++$position) {
            $carry = \strpos(self::BASE58_ALPHABET, $input[$position]);
            if ($carry === false) {
                return null;
            }

            $index = 0;
            for ($it = $size - 1; ($carry !== 0 || $index < $outputLength) && $it >= 0; --$it, ++$index) {
                $carry += 58 * $bytes[$it];
                $bytes[$it] = $carry % 256;
                $carry = \intdiv($carry, 256);
            }

            if ($carry !== 0) {
                // Buffer too small for the value: malformed input.
                return null;
            }

            $outputLength = $index;
        }

        $it = $size - $outputLength;
        while ($it < $size && $bytes[$it] === 0) {
            ++$it;
        }

        $result = \str_repeat("\x00", $zeroes);
        for (; $it < $size; ++$it) {
            $result .= \chr($bytes[$it]);
        }

        return $result;
    }
}
