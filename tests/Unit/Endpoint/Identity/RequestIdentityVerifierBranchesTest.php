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

    // --- Header and payload must BOTH decode to JSON objects. ---

    public function testValidHeaderWithNonObjectPayloadIsRejected(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        // A genuine EdDSA header and a real signature, but the payload is a bare
        // JSON scalar (`5`) rather than an object. Both the header *and* the
        // payload must decode to objects; relaxing that conjunction would let a
        // signed-but-objectless token slip past the structural check.
        $jwt = $signer->jwtRaw('5');

        self::assertFalse($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    // --- exp claim: type must be int/float, with a strict-future boundary. ---

    public function testStringExpIsRejected(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $now = \time();
        // A numeric *string* far in the future: not an int/float type, so it must
        // be rejected by the type guard rather than slipping through to the
        // expiry comparison (where its value would read as still-valid).
        $jwt = $signer->jwt('/discover', [
            'aud' => '/discover',
            'exp' => '99999999999',
            'iat' => $now,
            'nbf' => $now - 5,
        ]);

        self::assertFalse($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    public function testFloatExpInTheFutureIsAccepted(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $now = \time();
        // A fractional NumericDate is a valid exp type and must be honoured.
        $jwt = $signer->jwt('/discover', [
            'aud' => '/discover',
            'exp' => $now + 3600.5,
            'iat' => $now,
            'nbf' => $now - 5,
        ]);

        self::assertTrue($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    public function testExpExactlyAtNowIsAccepted(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        // exp == now is NOT expired (the check is `exp < now`, zero leeway).
        // Align to the start of a fresh second so the verifier's internal
        // `time()` reads the same value we stamped, making the boundary exact.
        $now = $this->freshSecond();
        $jwt = $signer->jwt('/discover', [
            'aud' => '/discover',
            'exp' => $now,
            'iat' => $now,
            'nbf' => $now - 5,
        ]);

        self::assertTrue($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    // --- nbf claim: optional, but when present must be int/float and not future. ---

    public function testStringNbfIsRejected(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $now = \time();
        // A numeric *string* whose value is comfortably in the past: it must be
        // rejected on type alone, not accepted because its value passes the
        // not-yet-valid comparison.
        $jwt = $signer->jwt('/discover', [
            'aud' => '/discover',
            'exp' => $now + 3600,
            'iat' => $now,
            'nbf' => '0',
        ]);

        self::assertFalse($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    public function testFloatNbfInThePastIsAccepted(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $now = \time();
        // A fractional past nbf is a valid type and must be honoured.
        $jwt = $signer->jwt('/discover', [
            'aud' => '/discover',
            'exp' => $now + 3600,
            'iat' => $now,
            'nbf' => $now - 5.5,
        ]);

        self::assertTrue($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    public function testNbfExactlyAtNowIsAccepted(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        // nbf == now is already valid (the check is `nbf > now`, zero leeway).
        $now = $this->freshSecond();
        $jwt = $signer->jwt('/discover', [
            'aud' => '/discover',
            'exp' => $now + 3600,
            'iat' => $now,
            'nbf' => $now,
        ]);

        self::assertTrue($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    // --- Path normalisation: slash counting and the substring extraction. ---

    public function testDoubleSlashDiscoverNormalisesToDiscover(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        // `//discover` has two slashes: normalisation must strip down to the
        // `/discover` tail. This pins the slash-walk step, the `count >= 1`
        // guard, and that the audience is the extracted *tail* substring, not
        // the whole path or a whole-string equality.
        $jwt = $signer->jwt('/discover');

        self::assertTrue($verifier->verify($this->signed('GET', '//discover', $jwt)));
    }

    public function testPrefixedInvokeWithExactlyThreeSlashesNormalises(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        // `x/invoke/S/h` carries exactly three slashes with a non-empty prefix,
        // so the `count >= 3` invoke branch must fire and strip the `x` prefix
        // down to `/invoke/S/h`. A `count > 3` boundary would miss this.
        $jwt = $signer->jwt('/invoke/S/h');

        self::assertTrue($verifier->verify($this->signed('POST', 'x/invoke/S/h', $jwt)));
    }

    public function testPrefixedDiscoverWithExactlyOneSlashNormalises(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        // `x/discover` carries exactly one slash with a non-empty prefix, so the
        // `count >= 1` discover branch must fire and strip to `/discover`. A
        // `count > 1` boundary would miss this.
        $jwt = $signer->jwt('/discover');

        self::assertTrue($verifier->verify($this->signed('GET', 'x/discover', $jwt)));
    }

    // --- JSON nesting depth limit (depth 8). ---

    public function testClaimsNestedToTheDepthLimitAreAccepted(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $now = \time();
        // Claims carrying a value nested to exactly the decoder's depth budget
        // (8): a lower budget would reject this otherwise-valid token.
        $jwt = $signer->jwt('/discover', [
            'aud' => '/discover',
            'exp' => $now + 3600,
            'iat' => $now,
            'nbf' => $now - 5,
            'z' => self::nest(6),
        ]);

        self::assertTrue($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    public function testClaimsNestedBeyondTheDepthLimitAreRejected(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $now = \time();
        // One level deeper than the budget: the decoder must throw (and the token
        // be rejected). A higher budget would wrongly accept it.
        $jwt = $signer->jwt('/discover', [
            'aud' => '/discover',
            'exp' => $now + 3600,
            'iat' => $now,
            'nbf' => $now - 5,
            'z' => self::nest(7),
        ]);

        self::assertFalse($verifier->verify($this->signed('GET', '/discover', $jwt)));
    }

    /**
     * Builds an array nested `$arrayLevels` deep around a scalar, so the
     * surrounding claims object reaches a known total JSON nesting depth.
     *
     * @return array<int, mixed>
     */
    private static function nest(int $arrayLevels): array
    {
        $value = [1];
        for ($i = 1; $i < $arrayLevels; ++$i) {
            $value = [$value];
        }

        return $value;
    }

    /**
     * Spins until the start of a fresh wall-clock second and returns it, leaving
     * almost a full second of headroom before `time()` ticks again -- enough for
     * the verifier's internal `time()` call to observe the same value.
     */
    private function freshSecond(): int
    {
        $start = \time();
        while (\time() === $start) {
            \usleep(1000);
        }

        return \time();
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
