<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Vm;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Vm\OutputSink;
use Qcodr\Restate\Sdk\Vm\SwitchableOutputSink;

/**
 * The two-phase sink behind the bidi streaming fast-path: it buffers frames to a string
 * until {@see SwitchableOutputSink::switchToDownstream} is called, then forwards every
 * subsequent frame to the downstream sink. {@see SwitchableOutputSink::takeBuffer} drains
 * the pre-switch buffer for the caller to flush as the response preamble.
 */
final class SwitchableOutputSinkTest extends TestCase
{
    public function testBuffersFramesBeforeSwitch(): void
    {
        $sink = new SwitchableOutputSink();
        $sink->write('a');
        $sink->write('b');

        self::assertSame('ab', $sink->takeBuffer());
    }

    public function testTakeBufferClearsTheBuffer(): void
    {
        $sink = new SwitchableOutputSink();
        $sink->write('a');

        self::assertSame('a', $sink->takeBuffer());
        self::assertSame('', $sink->takeBuffer(), 'the buffer is emptied once taken');
    }

    public function testForwardsToDownstreamAfterSwitch(): void
    {
        $downstream = new class () implements OutputSink {
            public string $received = '';

            public function write(string $frame): void
            {
                $this->received .= $frame;
            }
        };

        $sink = new SwitchableOutputSink();
        $sink->write('preamble');          // buffered
        $sink->switchToDownstream($downstream);
        $sink->write('x');                 // forwarded
        $sink->write('y');                 // forwarded

        // Pre-switch frames stay in the buffer (the caller flushes them); only post-switch
        // frames reach the downstream sink.
        self::assertSame('preamble', $sink->takeBuffer());
        self::assertSame('xy', $downstream->received);
    }
}
