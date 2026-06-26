<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Endpoint\Identity;

use JsonException;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;

/**
 * Verifies that an inbound request was signed by a trusted Restate instance.
 *
 * This is a pure-PHP port of `restate-sdk-shared-core`
 * (`src/request_identity.rs`), the same logic the Rust/TS/Java/Go/Python SDKs
 * delegate to. The scheme ("request identity v1") works as follows:
 *
 *  - Restate sends `x-restate-signature-scheme: v1` and a compact JWT in
 *    `x-restate-jwt-v1`. (A value of `unsigned`, or a missing scheme, is
 *    rejected once any key is configured.)
 *  - The JWT is signed with `EdDSA` (Ed25519); its header carries
 *    `alg: EdDSA` and `kid: publickeyv1_...`.
 *  - The claims are `{ aud, exp, iat, nbf }` where `aud` is the *normalised*
 *    request path (see {@see normalisePath()}): `/invoke/{service}/{handler}`
 *    for invocations, `/discover` for discovery, otherwise the literal path.
 *  - Verification: the signature must validate against one configured key, the
 *    `aud` must equal the normalised path, `exp` must be in the future and
 *    `nbf` (if present) must not be in the future — all with zero leeway.
 *
 * The verifier fails closed: any malformed header, key, or claim yields false.
 *
 * @see https://docs.restate.dev/services/security
 * @see https://github.com/restatedev/sdk-shared-core/blob/main/src/request_identity.rs
 */
final class RequestIdentityVerifier
{
    private const SIGNATURE_SCHEME_HEADER = 'x-restate-signature-scheme';
    private const SIGNATURE_JWT_V1_HEADER = 'x-restate-jwt-v1';
    private const SIGNATURE_SCHEME_V1 = 'v1';

    private const JWT_ALG_EDDSA = 'EdDSA';

    /** @var list<IdentityKey> */
    private readonly array $keys;

    public function __construct(IdentityKey ...$keys)
    {
        $this->keys = \array_values($keys);
    }

    /**
     * Builds a verifier from raw `publickeyv1_...` strings.
     *
     * @param list<string> $publicKeys
     *
     * @throws IdentityKeyException when any key string is malformed
     */
    public static function fromKeys(array $publicKeys): self
    {
        return new self(...\array_map(
            static fn (string $key): IdentityKey => IdentityKey::fromString($key),
            $publicKeys,
        ));
    }

    /**
     * Whether verification is active (at least one key configured).
     */
    public function isEnabled(): bool
    {
        return $this->keys !== [];
    }

    /**
     * Verifies the request's identity signature.
     *
     * Returns true when no key is configured (verification opt-in) or when the
     * request carries a valid `v1` signature; false otherwise.
     */
    public function verify(HttpRequest $request): bool
    {
        if ($this->keys === []) {
            return true;
        }

        $scheme = $request->header(self::SIGNATURE_SCHEME_HEADER);
        if ($scheme !== self::SIGNATURE_SCHEME_V1) {
            // Missing scheme, `unsigned`, or an unknown scheme: reject.
            return false;
        }

        $jwt = $request->header(self::SIGNATURE_JWT_V1_HEADER);
        if ($jwt === null || $jwt === '') {
            return false;
        }

        return $this->checkV1($jwt, self::normalisePath($request->path));
    }

    private function checkV1(string $jwt, string $audience): bool
    {
        $parts = \explode('.', $jwt);
        if (\count($parts) !== 3) {
            return false;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $headerJson = self::base64UrlDecode($encodedHeader);
        $payloadJson = self::base64UrlDecode($encodedPayload);
        $signature = self::base64UrlDecode($encodedSignature);
        if ($headerJson === null || $payloadJson === null || $signature === null) {
            return false;
        }

        $header = self::decodeJsonObject($headerJson);
        $payload = self::decodeJsonObject($payloadJson);
        if ($header === null || $payload === null) {
            return false;
        }

        // Pin the algorithm to EdDSA: never honour `alg: none` or an attacker
        // downgrading to a symmetric algorithm.
        if (($header['alg'] ?? null) !== self::JWT_ALG_EDDSA) {
            return false;
        }

        $message = $encodedHeader . '.' . $encodedPayload;
        if (!$this->signatureValid($message, $signature)) {
            return false;
        }

        return self::claimsValid($payload, $audience);
    }

    private function signatureValid(string $message, string $signature): bool
    {
        foreach ($this->keys as $key) {
            if ($key->verifySignature($message, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private static function claimsValid(array $payload, string $audience): bool
    {
        if (!self::audienceMatches($payload['aud'] ?? null, $audience)) {
            return false;
        }

        $now = \time();

        $exp = $payload['exp'] ?? null;
        if (!\is_int($exp) && !\is_float($exp)) {
            return false;
        }
        if ($exp < $now) {
            return false;
        }

        $nbf = $payload['nbf'] ?? null;
        if ($nbf !== null) {
            if (!\is_int($nbf) && !\is_float($nbf)) {
                return false;
            }
            if ($nbf > $now) {
                return false;
            }
        }

        return true;
    }

    private static function audienceMatches(mixed $aud, string $expected): bool
    {
        if (\is_string($aud)) {
            return \hash_equals($expected, $aud);
        }

        if (\is_array($aud)) {
            foreach ($aud as $candidate) {
                if (\is_string($candidate) && \hash_equals($expected, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Normalises a request path to the audience Restate signs, mirroring
     * `normalise_path` in `restate-sdk-shared-core`.
     */
    private static function normalisePath(string $path): string
    {
        $slashes = [];
        $offset = 0;
        while (($position = \strpos($path, '/', $offset)) !== false) {
            $slashes[] = $position;
            $offset = $position + 1;
        }

        $count = \count($slashes);

        if ($count >= 3) {
            $thirdFromLast = $slashes[$count - 3];
            $secondFromLast = $slashes[$count - 2];
            if (\substr($path, $thirdFromLast, $secondFromLast - $thirdFromLast) === '/invoke') {
                return \substr($path, $thirdFromLast);
            }
        }

        if ($count >= 1) {
            $last = $slashes[$count - 1];
            if (\substr($path, $last) === '/discover') {
                return \substr($path, $last);
            }
        }

        return $path;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decodeJsonObject(string $json): ?array
    {
        try {
            $decoded = \json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $remainder = \strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= \str_repeat('=', 4 - $remainder);
        }

        $decoded = \base64_decode(\strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
