<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Server;

use Amp\Http\Server\SocketHttpServer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\ProtocolMode;
use Qcodr\Restate\Sdk\Server\AmpStreamingServer;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Greeter;

/**
 * Construct-time smoke test for {@see AmpStreamingServer}: it builds without throwing
 * when amphp/http-server is present. Its real validation — true h2c bidirectional
 * streaming — is the cross-SDK conformance suite (Phase 5), not a unit test, so no live
 * socket is opened here.
 */
final class AmpStreamingServerTest extends TestCase
{
    public function testConstructsWhenAmphpIsAvailable(): void
    {
        // Precondition: amphp/http-server is a require-dev dependency, so the
        // constructor's class_exists() guard must pass under the test suite.
        self::assertTrue(\class_exists(SocketHttpServer::class), 'amphp/http-server must be installed for tests');

        $endpoint = Endpoint::builder()->bind(new Greeter())->protocolMode(ProtocolMode::BidiStream)->build();

        // The full constructor (mirroring SwooleServer's signature, incl. a custom logger
        // and debug) must wire up without throwing. There is nothing further to assert
        // without opening a live socket, which is conformance territory.
        new AmpStreamingServer($endpoint, null, null, new NullLogger(), true);
    }
}
