<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Context\ContextRand;

final class ContextRandTest extends TestCase
{
    public function testSameSeedProducesIdenticalFloatStream(): void
    {
        $a = ContextRand::fromSeed(12345);
        $b = ContextRand::fromSeed(12345);

        for ($i = 0; $i < 5; $i++) {
            self::assertSame($a->randomFloat(), $b->randomFloat(), "draw {$i} diverged across replay");
        }
    }

    public function testFloatsLieInUnitInterval(): void
    {
        $rand = ContextRand::fromSeed(1);

        for ($i = 0; $i < 100; $i++) {
            $value = $rand->randomFloat();
            self::assertGreaterThanOrEqual(0.0, $value);
            self::assertLessThan(1.0, $value);
        }
    }

    public function testRandomIntStaysWithinBounds(): void
    {
        $rand = ContextRand::fromSeed(7);

        for ($i = 0; $i < 200; $i++) {
            $value = $rand->randomInt(10, 20);
            self::assertGreaterThanOrEqual(10, $value);
            self::assertLessThanOrEqual(20, $value);
        }
    }

    public function testRandomIntSwapsInvertedBounds(): void
    {
        $rand = ContextRand::fromSeed(7);

        for ($i = 0; $i < 50; $i++) {
            $value = $rand->randomInt(20, 10);
            self::assertGreaterThanOrEqual(10, $value);
            self::assertLessThanOrEqual(20, $value);
        }
    }

    public function testRandomIntSingletonRangeIsConstant(): void
    {
        self::assertSame(5, ContextRand::fromSeed(7)->randomInt(5, 5));
    }

    public function testUuidV4HasCorrectShapeVersionAndVariant(): void
    {
        $uuid = ContextRand::fromSeed(99)->uuidV4();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    public function testUuidV4IsDeterministicPerSeed(): void
    {
        self::assertSame(
            ContextRand::fromSeed(99)->uuidV4(),
            ContextRand::fromSeed(99)->uuidV4(),
        );
    }

    public function testDistinctSeedsDiverge(): void
    {
        self::assertNotSame(
            ContextRand::fromSeed(1)->uuidV4(),
            ContextRand::fromSeed(2)->uuidV4(),
        );
    }

    public function testNegativeSeedIsTreatedAsUnsignedAndStaysStable(): void
    {
        // A seed with bit 63 set arrives as a negative PHP int; fromSeed formats it
        // as unsigned so both replicas reproduce the same stream.
        $a = ContextRand::fromSeed(-1);
        $b = ContextRand::fromSeed(-1);

        self::assertSame($a->randomFloat(), $b->randomFloat());
    }
}
