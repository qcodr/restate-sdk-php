<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Endpoint;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Endpoint\Endpoint;
use Restate\Sdk\Endpoint\HttpRequest;
use Restate\Sdk\Endpoint\RequestProcessor;
use Restate\Sdk\Protocol\MessageCodec;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Reader;
use Restate\Sdk\Protocol\ServiceProtocolVersion;
use Restate\Sdk\Tests\Support\Fixtures\ThrowingService;
use Restate\Sdk\Tests\Support\JournalBuilder;
use RuntimeException;

/**
 * Item #1: the stacktrace sent to the runtime (ErrorMessage field 3) is reduced to
 * the exception class name in production (debug=false) so absolute file paths and
 * frame detail are not disclosed over the wire, and forwarded in full under debug.
 */
final class StacktraceSanitizationTest extends TestCase
{
    public function testProductionStacktraceIsClassNameOnly(): void
    {
        $stacktrace = $this->stacktraceFrom(debug: false);

        self::assertSame(RuntimeException::class, $stacktrace, 'only the class name is forwarded');
        self::assertStringNotContainsString('/', $stacktrace, 'no absolute file path leaks');
        self::assertStringNotContainsString('.php', $stacktrace, 'no source file name leaks');
        self::assertStringNotContainsString('Stack trace', $stacktrace, 'no frame detail leaks');
    }

    public function testDebugStacktraceCarriesTheFullTrace(): void
    {
        $stacktrace = $this->stacktraceFrom(debug: true);

        self::assertStringContainsString('RuntimeException', $stacktrace);
        self::assertStringContainsString(ThrowingService::MESSAGE, $stacktrace, 'message is part of the full trace');
        self::assertStringContainsString('ThrowingService.php', $stacktrace, 'the full trace includes file paths');
        self::assertStringContainsString('Stack trace', $stacktrace);
    }

    private function stacktraceFrom(bool $debug): string
    {
        $endpoint = Endpoint::builder()->bind(new ThrowingService())->build();
        $request = new HttpRequest(
            'POST',
            '/invoke/ThrowingService/boom',
            ['content-type' => ServiceProtocolVersion::V7->contentType()],
            (new JournalBuilder())->input('')->build(),
        );

        $output = (new RequestProcessor($endpoint, debug: $debug))->process($request)->body;

        return self::stacktraceField($output);
    }

    /** Decodes field 3 (stacktrace) from the ErrorMessage frame in the response. */
    private static function stacktraceField(string $output): string
    {
        $reader = new Reader(self::errorPayload($output));
        $stacktrace = '';
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            if ($field === 3) {
                $stacktrace = $reader->readLengthDelimited();
            } else {
                $reader->skip($wire);
            }
        }

        return $stacktrace;
    }

    private static function errorPayload(string $output): string
    {
        foreach (MessageCodec::decodeAll($output) as $frame) {
            if ($frame->type() === MessageType::Error) {
                return $frame->payload;
            }
        }
        self::fail('No Error frame in response');
    }
}
