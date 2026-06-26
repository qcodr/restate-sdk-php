<?php

declare(strict_types=1);

namespace Restate\Sdk\Vm;

/**
 * Builds the public awakeable identifier handed to other invocations.
 *
 * Per the protocol, the id is the literal `prom_1` followed by the Base64 URL-safe
 * (unpadded) encoding of the invocation id concatenated with the awakeable's signal
 * index as a 32-bit big-endian unsigned integer.
 */
final class AwakeableId
{
    private const PREFIX = 'prom_1';

    public static function encode(string $invocationId, int $signalId): string
    {
        $payload = $invocationId . \pack('N', $signalId);

        return self::PREFIX . self::base64UrlEncode($payload);
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return \rtrim(\strtr(\base64_encode($bytes), '+/', '-_'), '=');
    }
}
