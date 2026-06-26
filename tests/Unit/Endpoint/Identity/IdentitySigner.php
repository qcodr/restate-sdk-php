<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Endpoint\Identity;

/**
 * Test-only helper that plays the role of the Restate server: it base58-encodes
 * Ed25519 public keys into `publickeyv1_...` strings and mints the `v1` JWTs the
 * runtime attaches to signed requests.
 */
final class IdentitySigner
{
    private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public string $publicKeyString;

    private readonly string $secretKey;

    public function __construct()
    {
        $keyPair = \sodium_crypto_sign_keypair();
        $this->publicKeyString = 'publickeyv1_' . self::base58Encode(\sodium_crypto_sign_publickey($keyPair));
        $this->secretKey = \sodium_crypto_sign_secretkey($keyPair);
    }

    /**
     * Builds a compact `EdDSA` JWT signed for the given audience (request path).
     *
     * @param array<string, mixed>|null $claimsOverride replaces the default
     *                                                   `{aud, exp, iat, nbf}` claims
     */
    public function jwt(string $audience, ?array $claimsOverride = null, ?string $alg = null): string
    {
        $now = \time();
        $header = ['typ' => 'JWT', 'alg' => $alg ?? 'EdDSA', 'kid' => $this->publicKeyString];
        $claims = $claimsOverride ?? [
            'aud' => $audience,
            'exp' => $now + 3600,
            'iat' => $now,
            'nbf' => $now - 5,
        ];

        $message = self::base64UrlEncode(self::json($header))
            . '.' . self::base64UrlEncode(self::json($claims));
        $signature = \sodium_crypto_sign_detached($message, $this->secretKey);

        return $message . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Mints a JWT whose payload is the given raw JSON string, signed with this
     * signer's key. Unlike {@see jwt()}, the payload need not be a JSON object
     * (e.g. a bare scalar like `5`), which lets tests exercise the
     * "payload did not decode to an object" rejection path with a genuine
     * signature attached.
     *
     * @param array<string, mixed>|null $header replaces the default EdDSA header
     */
    public function jwtRaw(string $rawPayloadJson, ?array $header = null): string
    {
        $header ??= ['typ' => 'JWT', 'alg' => 'EdDSA', 'kid' => $this->publicKeyString];

        $message = self::base64UrlEncode(self::json($header))
            . '.' . self::base64UrlEncode($rawPayloadJson);
        $signature = \sodium_crypto_sign_detached($message, $this->secretKey);

        return $message . '.' . self::base64UrlEncode($signature);
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    private static function base64UrlEncode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }

    public static function base58Encode(string $bytes): string
    {
        $length = \strlen($bytes);

        $zeroes = 0;
        while ($zeroes < $length && $bytes[$zeroes] === "\x00") {
            ++$zeroes;
        }

        $digits = [0];
        for ($i = $zeroes; $i < $length; ++$i) {
            $carry = \ord($bytes[$i]);
            for ($j = 0, $size = \count($digits); $j < $size; ++$j) {
                $carry += $digits[$j] << 8;
                $digits[$j] = $carry % 58;
                $carry = \intdiv($carry, 58);
            }
            while ($carry > 0) {
                $digits[] = $carry % 58;
                $carry = \intdiv($carry, 58);
            }
        }

        $out = \str_repeat('1', $zeroes);
        for ($i = \count($digits) - 1; $i >= 0; --$i) {
            $out .= self::BASE58_ALPHABET[$digits[$i]];
        }

        return $out;
    }
}
