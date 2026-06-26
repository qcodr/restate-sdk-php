<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol;

/**
 * Restate service protocol versions and their negotiation rules.
 *
 * The runtime selects a version through the request `content-type`
 * (`application/vnd.restate.invocation.vX`); the SDK echoes the same content-type
 * on a 200 response, or replies 415 if it does not support the requested version.
 *
 * This SDK implements the "commands + notifications" model introduced in V5, which
 * is the minimum supported by current Restate runtimes (>= 1.6 reject V1–V4).
 */
enum ServiceProtocolVersion: int
{
    case V5 = 5;
    case V6 = 6;
    case V7 = 7;

    public const MIN = self::V5;
    public const MAX = self::V7;

    private const CONTENT_TYPE_PREFIX = 'application/vnd.restate.invocation.v';

    public function contentType(): string
    {
        return self::CONTENT_TYPE_PREFIX . $this->value;
    }

    /**
     * Parses the protocol version out of an invocation content-type header.
     *
     * @return self|null the negotiated version, or null when the content-type is
     *                   not a (supported) Restate invocation content-type
     */
    public static function fromContentType(string $contentType): ?self
    {
        $contentType = \trim(\explode(';', $contentType, 2)[0]);
        if (!\str_starts_with($contentType, self::CONTENT_TYPE_PREFIX)) {
            return null;
        }

        $version = \substr($contentType, \strlen(self::CONTENT_TYPE_PREFIX));
        if (!\ctype_digit($version)) {
            return null;
        }

        return self::tryFrom((int) $version);
    }

    public static function min(): self
    {
        return self::MIN;
    }

    public static function max(): self
    {
        return self::MAX;
    }
}
