<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Endpoint\Identity;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Endpoint\HttpRequest;
use Restate\Sdk\Endpoint\Identity\RequestIdentityVerifier;

/**
 * Branch coverage for {@see RequestIdentityVerifier}: malformed JWT structure, bad
 * base64/JSON segments, and every claim-rejection path (audience shapes, exp, nbf).
 */
final class RequestIdentityVerifierBranchesTest extends TestCase
{
    private const SCHEME_HEADER = 'x-restate-signature-scheme';
    private const JWT_HEADER = 'x-restate-jwt-v1';

    // --- Scheme present but JWT header missing/empty. ---

    public function testSchemeV1WithMissingJwtHeaderIsRejected(): void
    {
        $verifier = RequestIdentityVerifier::fromKeys([(new IdentitySigner())->publicKeyString]);

        $request = new HttpRequest('GET', '/discover', [self::SCHEME_HEADER => 'v1'], '');

        self::assertFalse($verifier->verify($request));
    }

    public function testSchemeV1WithEmptyJwtHeaderIsRejected(): void
    {
        $verifier = RequestIdentityVerifier::fromKeys([(new IdentitySigner())->publicKeyString]);

        $request = new HttpRequest('GET', '/discover', [
            self::SCHEME_HEADER => 'v1',
            self::JWT_HEADER => '',
        ], '');

        self::assertFalse($verifier->verify($request));
    }

    // --- Malformed compact JWT structure. ---

    public function testJwtWithWrongSegmentCountIsRejected(): void
    {
        $verifier = RequestIdentityVerifier::fromKeys([(new IdentitySigner())->publicKeyString]);

        // Two segments instead of header.payload.signature.
        self::assertFalse($verifier->verify($this->signed('GET', '/discover', 'aaaa.bbbb')));
    }

    public function testJwtWithInvalidBase64SegmentIsRejected(): void
    {
        $verifier = RequestIdentityVerifier::fromKeys([(new IdentitySigner())->publicKeyString]);

        // '@' is outside the base64url alphabet, so strict decoding fails.
        $jwt = '@@@@.' . self::b64url('{}') . '.' . self::b64url('signature');

        self::assertFalse($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    public function testJwtWithNonJsonHeaderIsRejected(): void
    {
        $verifier = RequestIdentityVerifier::fromKeys([(new IdentitySigner())->publicKeyString]);

        // Decodes cleanly from base64url but is not valid JSON (JsonException path).
        $jwt = self::b64url('not json') . '.' . self::b64url('{}') . '.' . self::b64url('signature');

        self::assertFalse($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    public function testJwtWithScalarJsonHeaderIsRejected(): void
    {
        $verifier = RequestIdentityVerifier::fromKeys([(new IdentitySigner())->publicKeyString]);

        // Valid JSON but a scalar, not the expected object.
        $jwt = self::b64url('5') . '.' . self::b64url('{}') . '.' . self::b64url('signature');

        self::assertFalse($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    // --- Claim rejection (signature is genuine; only the claims are wrong). ---

    public function testMissingExpClaimIsRejected(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $now = \time();
        $jwt = $signer->jwt('/discover', [
            'aud' => '/discover',
            'iat' => $now,
            'nbf' => $now - 5,
        ]);

        self::assertFalse($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    public function testNonNumericNbfClaimIsRejected(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $now = \time();
        $jwt = $signer->jwt('/discover', [
            'aud' => '/discover',
            'exp' => $now + 3600,
            'iat' => $now,
            'nbf' => 'soon',
        ]);

        self::assertFalse($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    public function testFutureNbfClaimIsRejected(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $now = \time();
        $jwt = $signer->jwt('/discover', [
            'aud' => '/discover',
            'exp' => $now + 3600,
            'iat' => $now,
            'nbf' => $now + 3600,
        ]);

        self::assertFalse($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    public function testAbsentNbfClaimIsAccepted(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $now = \time();
        // nbf is optional: a token without it must still validate.
        $jwt = $signer->jwt('/discover', [
            'aud' => '/discover',
            'exp' => $now + 3600,
            'iat' => $now,
        ]);

        self::assertTrue($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    // --- Audience as an array (multi-audience tokens). ---

    public function testAudienceArrayContainingThePathIsAccepted(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $now = \time();
        $jwt = $signer->jwt('/discover', [
            'aud' => ['/other', '/discover'],
            'exp' => $now + 3600,
            'iat' => $now,
            'nbf' => $now - 5,
        ]);

        self::assertTrue($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    public function testAudienceArrayWithoutThePathIsRejected(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $now = \time();
        // Includes a non-string member to exercise the is_string guard as well.
        $jwt = $signer->jwt('/discover', [
            'aud' => [123, '/other'],
            'exp' => $now + 3600,
            'iat' => $now,
            'nbf' => $now - 5,
        ]);

        self::assertFalse($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    public function testNonStringNonArrayAudienceIsRejected(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $now = \time();
        $jwt = $signer->jwt('/discover', [
            'aud' => 123,
            'exp' => $now + 3600,
            'iat' => $now,
            'nbf' => $now - 5,
        ]);

        self::assertFalse($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    private function signed(string $method, string $path, string $jwt): HttpRequest
    {
        return new HttpRequest($method, $path, [
            self::SCHEME_HEADER => 'v1',
            self::JWT_HEADER => $jwt,
        ], '');
    }

    private static function b64url(string $raw): string
    {
        return \rtrim(\strtr(\base64_encode($raw), '+/', '-_'), '=');
    }
}
