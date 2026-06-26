<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Endpoint\Identity;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Endpoint\Endpoint;
use Restate\Sdk\Endpoint\HttpRequest;
use Restate\Sdk\Endpoint\Identity\RequestIdentityVerifier;
use Restate\Sdk\Endpoint\RequestProcessor;
use Restate\Sdk\Tests\Support\Fixtures\Counter;
use Restate\Sdk\Tests\Support\Fixtures\Greeter;

final class RequestIdentityVerifierTest extends TestCase
{
    private const SCHEME_HEADER = 'x-restate-signature-scheme';
    private const JWT_HEADER = 'x-restate-jwt-v1';

    // --- Backward compatibility: no key configured -> processor untouched. ---

    public function testNoKeyConfiguredLeavesRequestsUnverified(): void
    {
        $endpoint = Endpoint::builder()->bind(new Greeter())->build();

        self::assertNull($endpoint->identityVerifier());

        $processor = new RequestProcessor($endpoint);
        $response = $processor->process(new HttpRequest('GET', '/health', [], ''));

        self::assertSame(200, $response->status);
        self::assertSame('OK', $response->body);
    }

    public function testEmptyVerifierIsDisabledAndPassesEverything(): void
    {
        $verifier = new RequestIdentityVerifier();

        self::assertFalse($verifier->isEnabled());
        self::assertTrue($verifier->verify(new HttpRequest('GET', '/health', [], '')));
    }

    // --- Key configured + missing/invalid signature -> rejected (401). ---

    public function testMissingSchemeHeaderIsRejected(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        self::assertTrue($verifier->isEnabled());
        self::assertFalse($verifier->verify(new HttpRequest('GET', '/discover', [], '')));
    }

    public function testUnsignedSchemeIsRejected(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $request = new HttpRequest('GET', '/discover', [self::SCHEME_HEADER => 'unsigned'], '');

        self::assertFalse($verifier->verify($request));
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $jwt = $signer->jwt('/discover');
        // Corrupt the signature by flipping a fully-significant character. The
        // final base64url char of a 64-byte Ed25519 signature carries only 2
        // significant bits (the other 4 are dropped as padding on decode), so
        // flipping it can be a no-op; mutate the first signature char instead,
        // which always changes the decoded bytes.
        [$header, $payload, $signature] = \explode('.', $jwt);
        $signature[0] = $signature[0] === 'A' ? 'B' : 'A';
        $tampered = $header . '.' . $payload . '.' . $signature;

        $request = $this->signedRequest('GET', '/discover', $tampered);

        self::assertFalse($verifier->verify($request));
    }

    public function testWrongAudienceIsRejected(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        // Signed for a different path than the one requested.
        $jwt = $signer->jwt('/invoke/Other/handler');
        $request = $this->signedRequest('POST', '/invoke/Greeter/greet', $jwt);

        self::assertFalse($verifier->verify($request));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $now = \time();
        $jwt = $signer->jwt('/discover', [
            'aud' => '/discover',
            'exp' => $now - 60,
            'iat' => $now - 120,
            'nbf' => $now - 120,
        ]);

        self::assertFalse($verifier->verify($this->signedRequest('GET', '/discover', $jwt)));
    }

    public function testAlgNoneIsRejected(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        // Even with a real signature, an unexpected `alg` header must be refused.
        $jwt = $signer->jwt('/discover', null, 'none');

        self::assertFalse($verifier->verify($this->signedRequest('GET', '/discover', $jwt)));
    }

    public function testSignatureFromAnUntrustedKeyIsRejected(): void
    {
        $trusted = new IdentitySigner();
        $attacker = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$trusted->publicKeyString]);

        $jwt = $attacker->jwt('/discover');

        self::assertFalse($verifier->verify($this->signedRequest('GET', '/discover', $jwt)));
    }

    // --- Valid signature -> accepted. ---

    public function testValidSignatureOnDiscoverIsAccepted(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        $jwt = $signer->jwt('/discover');

        self::assertTrue($verifier->verify($this->signedRequest('GET', '/discover', $jwt)));
    }

    public function testValidSignatureOnInvokeUsesNormalisedPath(): void
    {
        $signer = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([$signer->publicKeyString]);

        // A longer prefix in front of /invoke/{svc}/{handler} is normalised away.
        $jwt = $signer->jwt('/invoke/Greeter/greet');
        $request = $this->signedRequest('POST', '/restate/v1/invoke/Greeter/greet', $jwt);

        self::assertTrue($verifier->verify($request));
    }

    public function testRotationAcceptsAnyConfiguredKey(): void
    {
        $oldKey = new IdentitySigner();
        $newKey = new IdentitySigner();
        $verifier = RequestIdentityVerifier::fromKeys([
            $oldKey->publicKeyString,
            $newKey->publicKeyString,
        ]);

        self::assertTrue($verifier->verify($this->signedRequest('GET', '/discover', $newKey->jwt('/discover'))));
        self::assertTrue($verifier->verify($this->signedRequest('GET', '/discover', $oldKey->jwt('/discover'))));
    }

    // --- End-to-end through RequestProcessor. ---

    public function testProcessorRejectsUnsignedRequestWith401(): void
    {
        $signer = new IdentitySigner();
        $endpoint = Endpoint::builder()
            ->bind(new Greeter())
            ->bind(new Counter())
            ->identityKey($signer->publicKeyString)
            ->build();

        self::assertNotNull($endpoint->identityVerifier());

        $processor = new RequestProcessor($endpoint);
        $response = $processor->process(new HttpRequest('GET', '/health', [], ''));

        self::assertSame(401, $response->status);
        self::assertSame('Unauthorized', $response->body);
    }

    public function testProcessorAcceptsAValidlySignedRequest(): void
    {
        $signer = new IdentitySigner();
        $endpoint = Endpoint::builder()
            ->bind(new Greeter())
            ->identityKey($signer->publicKeyString)
            ->build();

        $processor = new RequestProcessor($endpoint);
        $request = $this->signedRequest('GET', '/health', $signer->jwt('/health'));

        $response = $processor->process($request);

        self::assertSame(200, $response->status);
        self::assertSame('OK', $response->body);
    }

    private function signedRequest(string $method, string $path, string $jwt): HttpRequest
    {
        return new HttpRequest($method, $path, [
            self::SCHEME_HEADER => 'v1',
            self::JWT_HEADER => $jwt,
        ], '');
    }
}
