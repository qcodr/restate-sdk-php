<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Vm;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Vm\EagerStateStore;

/**
 * Verifies the SDK's local state view: which reads are served locally (a present
 * key, or any key once the map is exhaustive) versus which fall back to a lazy read
 * (an unknown key while the map is partial), and how set/clear/clearAll mutate it.
 */
final class EagerStateStoreTest extends TestCase
{
    public function testGetReturnsKnownValueForPresentKeyOnPartialMap(): void
    {
        $store = new EagerStateStore(['count' => '5'], true);

        self::assertSame([true, true, '5'], $store->get('count'));
    }

    public function testGetReturnsKnownValueForPresentKeyOnCompleteMap(): void
    {
        $store = new EagerStateStore(['count' => '5'], false);

        self::assertSame([true, true, '5'], $store->get('count'));
    }

    public function testGetReturnsKnownAbsentForMissingKeyOnCompleteMap(): void
    {
        $store = new EagerStateStore([], false);

        // The map is exhaustive, so a missing key is definitively absent (known).
        self::assertSame([true, false, null], $store->get('missing'));
    }

    public function testGetReturnsUnknownForMissingKeyOnPartialMap(): void
    {
        $store = new EagerStateStore([], true);

        // Partial map + unknown key: the caller must perform a lazy read.
        self::assertSame([false, false, null], $store->get('missing'));
    }

    public function testSetMakesAPreviouslyUnknownKeyLocallyReadable(): void
    {
        $store = new EagerStateStore([], true);
        self::assertSame([false, false, null], $store->get('k'), 'unknown before the write');

        $store->set('k', 'v');

        self::assertSame([true, true, 'v'], $store->get('k'));
    }

    public function testSetOverridesAnEarlierClearMarker(): void
    {
        $store = new EagerStateStore(['k' => 'v'], true);
        $store->clear('k');
        self::assertSame([true, false, null], $store->get('k'), 'cleared key reads as known-absent');

        $store->set('k', 'v2');

        self::assertSame([true, true, 'v2'], $store->get('k'), 'a later set clears the clear marker');
    }

    public function testClearMarksKeyKnownAbsentEvenOnPartialMap(): void
    {
        $store = new EagerStateStore(['k' => 'v'], true);

        $store->clear('k');

        // Known-absent (not unknown): the clear is recorded for this invocation, so no
        // lazy read is needed to answer the read.
        self::assertSame([true, false, null], $store->get('k'));
    }

    public function testClearAllDropsValuesAndMakesTheMapExhaustive(): void
    {
        $store = new EagerStateStore(['a' => '1', 'b' => '2'], true);

        $store->clearAll();

        // Every key now reads known-absent because the state is fully known: empty.
        self::assertSame([true, false, null], $store->get('a'));
        self::assertSame([true, false, null], $store->get('never-existed'));
        self::assertSame([true, []], $store->keys());
    }

    public function testKeysAreUnknownWhileTheMapIsPartial(): void
    {
        $store = new EagerStateStore(['a' => '1'], true);

        self::assertSame([false, []], $store->keys());
    }

    public function testKeysAreEnumeratedWhenTheMapIsComplete(): void
    {
        $store = new EagerStateStore(['a' => '1', 'b' => '2'], false);

        self::assertSame([true, ['a', 'b']], $store->keys());
    }

    public function testSetThenClearRoundTripUpdatesTheLocalView(): void
    {
        $store = new EagerStateStore([], false);
        $store->set('k', 'v');
        self::assertSame([true, true, 'v'], $store->get('k'));

        $store->clear('k');

        self::assertSame([true, false, null], $store->get('k'));
        // The key is gone from an otherwise-complete map.
        self::assertSame([true, []], $store->keys());
    }
}
