<?php

declare(strict_types=1);

namespace Restate\Sdk\Context;

/**
 * Deterministic randomness seeded by `StartMessage.random_seed`.
 *
 * The stream is reproduced identically across replays (same seed, same call order),
 * so values such as generated UUIDs remain stable. The generator is a per-context
 * SHA-256 counter stream — self-contained (no global PHP RNG state), which keeps it
 * safe under concurrent invocations in a Swoole worker.
 */
final class ContextRand
{
    private int $counter = 0;
    private string $residue = '';

    public function __construct(private readonly string $seed)
    {
    }

    public static function fromSeed(int $seed): self
    {
        // The runtime sends an unsigned 64-bit seed; format it as unsigned so a seed
        // with bit 63 set (a negative PHP int) matches the runtime's interpretation.
        return new self(\sprintf('%u', $seed));
    }

    /** Uniform float in [0, 1). */
    public function randomFloat(): float
    {
        // 53 bits of entropy mapped into the double mantissa.
        $unpacked = \unpack('J', $this->nextBytes(8));
        $value = $unpacked === false ? 0 : $unpacked[1];
        $value = \is_int($value) ? $value : 0;
        $mantissa = ($value >> 11) & ((1 << 53) - 1);

        return $mantissa / (1 << 53);
    }

    public function randomInt(int $min, int $max): int
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        $range = $max - $min + 1;

        return $min + (int) \floor($this->randomFloat() * $range);
    }

    public function uuidV4(): string
    {
        $bytes = $this->nextBytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80); // variant 10

        $hex = \bin2hex($bytes);

        return \sprintf(
            '%s-%s-%s-%s-%s',
            \substr($hex, 0, 8),
            \substr($hex, 8, 4),
            \substr($hex, 12, 4),
            \substr($hex, 16, 4),
            \substr($hex, 20, 12),
        );
    }

    private function nextBytes(int $length): string
    {
        while (\strlen($this->residue) < $length) {
            $this->residue .= \hash('sha256', $this->seed . ':' . $this->counter, true);
            $this->counter++;
        }

        $bytes = \substr($this->residue, 0, $length);
        $this->residue = \substr($this->residue, $length);

        return $bytes;
    }
}
