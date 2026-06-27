<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Vm;

/**
 * Builds the public awakeable identifier handed to other invocations.
 *
 * Per the protocol (service protocol V7), the id is the literal `sign_1` followed by
 * the Base64 URL-safe (unpadded) encoding of the invocation id concatenated with the
 * awakeable's signal index as a 32-bit big-endian unsigned integer. The `sign_` prefix
 * marks it as a *signal*-backed awakeable; the older `prom_` prefix denoted the
 * completion-backed form, which makes the runtime route a resolution to a non-existent
 * completion id — a journal "storage corruption" error that crashes the partition.
 */
final class AwakeableId
{
    private const PREFIX = 'sign_1';

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
