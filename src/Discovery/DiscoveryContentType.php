<?php

declare(strict_types=1);

namespace Restate\Sdk\Discovery;

/**
 * Negotiates the discovery manifest version from the runtime's `Accept` header.
 *
 * This SDK can emit manifest schema versions v1 through v4. The negotiation picks the
 * highest version that both this SDK and the runtime support; when the runtime sends
 * no usable `Accept` header (absent, empty, or listing only unknown versions) it
 * defaults to the highest version this SDK supports.
 *
 * {@see negotiate} yields the matching content-type string to echo on the response;
 * {@see negotiateVersion} yields the integer the {@see ManifestBuilder} uses to gate
 * which option fields it emits.
 */
final class DiscoveryContentType
{
    /** @var list<int> */
    private const SUPPORTED_VERSIONS = [1, 2, 3, 4];
    private const PREFIX = 'application/vnd.restate.endpointmanifest.v';
    private const SUFFIX = '+json';

    public static function negotiate(?string $accept): string
    {
        return self::PREFIX . self::negotiateVersion($accept) . self::SUFFIX;
    }

    public static function negotiateVersion(?string $accept): int
    {
        $highest = \max(self::SUPPORTED_VERSIONS);

        $requested = self::parseAcceptedVersions($accept);
        if ($requested === []) {
            return $highest;
        }

        $candidates = \array_values(\array_intersect(self::SUPPORTED_VERSIONS, $requested));

        return $candidates === [] ? $highest : \max($candidates);
    }

    /**
     * @return list<int>
     */
    private static function parseAcceptedVersions(?string $accept): array
    {
        if ($accept === null || $accept === '') {
            return [];
        }

        $versions = [];
        if (\preg_match_all('/endpointmanifest\.v(\d+)\+json/', $accept, $matches) !== false) {
            foreach ($matches[1] as $version) {
                $versions[] = (int) $version;
            }
        }

        return $versions;
    }
}
