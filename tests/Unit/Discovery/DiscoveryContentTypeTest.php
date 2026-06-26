<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Discovery\DiscoveryContentType;

final class DiscoveryContentTypeTest extends TestCase
{
    private const PREFIX = 'application/vnd.restate.endpointmanifest.v';
    private const SUFFIX = '+json';

    private static function accept(int ...$versions): string
    {
        return \implode(', ', \array_map(
            static fn (int $v): string => self::PREFIX . $v . self::SUFFIX,
            $versions,
        ));
    }

    public function testNegotiatesHighestMutuallySupportedVersion(): void
    {
        self::assertSame(3, DiscoveryContentType::negotiateVersion(self::accept(1, 2, 3)));
        self::assertSame(4, DiscoveryContentType::negotiateVersion(self::accept(2, 4)));
        self::assertSame(4, DiscoveryContentType::negotiateVersion(self::accept(4)));
        self::assertSame(1, DiscoveryContentType::negotiateVersion(self::accept(1)));
    }

    public function testOrderInAcceptHeaderDoesNotMatter(): void
    {
        self::assertSame(3, DiscoveryContentType::negotiateVersion(self::accept(3, 1, 2)));
    }

    public function testIgnoresVersionsThisSdkDoesNotSupport(): void
    {
        // v99 is unknown; the highest mutually supported one is v3.
        self::assertSame(3, DiscoveryContentType::negotiateVersion(self::accept(3, 99)));
    }

    public function testDefaultsToHighestSupportedWhenNoMatch(): void
    {
        // Runtime asks only for a version this SDK cannot emit -> fall back to the newest.
        self::assertSame(4, DiscoveryContentType::negotiateVersion(self::accept(99)));
    }

    public function testDefaultsToHighestSupportedWhenAcceptMissing(): void
    {
        self::assertSame(4, DiscoveryContentType::negotiateVersion(null));
        self::assertSame(4, DiscoveryContentType::negotiateVersion(''));
        self::assertSame(4, DiscoveryContentType::negotiateVersion('*/*'));
    }

    public function testNegotiateReturnsMatchingContentTypeString(): void
    {
        self::assertSame(
            self::PREFIX . '2' . self::SUFFIX,
            DiscoveryContentType::negotiate(self::accept(1, 2)),
        );
        self::assertSame(
            self::PREFIX . '4' . self::SUFFIX,
            DiscoveryContentType::negotiate(null),
        );
    }

    public function testNegotiateAndNegotiateVersionAgree(): void
    {
        $accept = self::accept(1, 2, 3);
        $version = DiscoveryContentType::negotiateVersion($accept);

        self::assertSame(
            self::PREFIX . $version . self::SUFFIX,
            DiscoveryContentType::negotiate($accept),
        );
    }
}
