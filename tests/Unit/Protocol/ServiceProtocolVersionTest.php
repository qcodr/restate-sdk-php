<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;

final class ServiceProtocolVersionTest extends TestCase
{
    public function testParsesEverySupportedVersion(): void
    {
        self::assertSame(
            ServiceProtocolVersion::V5,
            ServiceProtocolVersion::fromContentType('application/vnd.restate.invocation.v5'),
        );
        self::assertSame(
            ServiceProtocolVersion::V7,
            ServiceProtocolVersion::fromContentType('application/vnd.restate.invocation.v7'),
        );
    }

    public function testIgnoresContentTypeParameters(): void
    {
        self::assertSame(
            ServiceProtocolVersion::V6,
            ServiceProtocolVersion::fromContentType('application/vnd.restate.invocation.v6; charset=utf-8'),
        );
    }

    public function testRejectsAForeignContentType(): void
    {
        self::assertNull(ServiceProtocolVersion::fromContentType('application/json'));
    }

    public function testRejectsANonNumericVersionSuffix(): void
    {
        // The prefix matches but the remainder is not all digits, so parsing fails.
        self::assertNull(ServiceProtocolVersion::fromContentType('application/vnd.restate.invocation.vabc'));
        self::assertNull(ServiceProtocolVersion::fromContentType('application/vnd.restate.invocation.v'));
    }

    public function testRejectsAWellFormedButUnsupportedVersion(): void
    {
        self::assertNull(ServiceProtocolVersion::fromContentType('application/vnd.restate.invocation.v4'));
        self::assertNull(ServiceProtocolVersion::fromContentType('application/vnd.restate.invocation.v99'));
    }

    public function testContentTypeRoundTripsThroughFromContentType(): void
    {
        foreach ([ServiceProtocolVersion::V5, ServiceProtocolVersion::V6, ServiceProtocolVersion::V7] as $version) {
            self::assertSame('application/vnd.restate.invocation.v' . $version->value, $version->contentType());
            self::assertSame($version, ServiceProtocolVersion::fromContentType($version->contentType()));
        }
    }

    public function testMinAndMaxBoundTheSupportedRange(): void
    {
        self::assertSame(ServiceProtocolVersion::V5, ServiceProtocolVersion::min());
        self::assertSame(ServiceProtocolVersion::V7, ServiceProtocolVersion::max());
    }
}
