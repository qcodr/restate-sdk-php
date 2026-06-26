<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;
use Qcodr\Restate\Sdk\Protocol\Message\Header;
use Qcodr\Restate\Sdk\Protocol\Message\Value;
use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\CallOptionsService;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;

/**
 * Covers the call ergonomics surface: idempotency keys and custom headers forwarded
 * onto the wire CallCommand, the callee invocation-id handle, and the request
 * metadata accessors (headers / idempotency key) read from the invocation input.
 */
final class CallOptionsTest extends TestCase
{
    public function testCallForwardsIdempotencyKeyAndHeaders(): void
    {
        $journal = (new JournalBuilder())->input('')->build();
        $output = $this->invoke('callWithOptions', $journal);

        $call = $this->frameOfType($output, MessageType::CallCommand);

        $reader = new Reader($call);
        $idempotencyKey = null;
        $header = null;
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 4:
                    $header = Header::decode($reader->readLengthDelimited());
                    break;
                case 6:
                    $idempotencyKey = $reader->readLengthDelimited();
                    break;
                default:
                    $reader->skip($wire);
            }
        }

        self::assertSame('idem-key-123', $idempotencyKey, 'idempotency key encoded in field 6');
        self::assertNotNull($header, 'a Header is encoded in field 4');
        self::assertSame('x-trace-id', $header->key);
        self::assertSame('trace-abc', $header->value);
    }

    public function testCallHandleExposesCalleeInvocationId(): void
    {
        // sysCall allocates completion ids 1 (invocation id) and 2 (result); the
        // journal resolves the invocation-id completion so await() does not suspend.
        $journal = (new JournalBuilder())
            ->input('')
            ->invocationIdCompletion(1, 'inv-callee-42')
            ->build();

        $output = $this->invoke('callAndReturnInvocationId', $journal);

        self::assertSame('"inv-callee-42"', $this->outputValue($output));
    }

    public function testRequestHeadersAndIdempotencyKeyAccessors(): void
    {
        $journal = (new JournalBuilder(idempotencyKey: 'idem-789'))
            ->input('', ['x-user' => 'alice', 'x-region' => 'eu'])
            ->build();

        $output = $this->invoke('readMetadata', $journal);

        $decoded = \json_decode($this->outputValue($output), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(['x-user' => 'alice', 'x-region' => 'eu'], $decoded['headers']);
        self::assertSame('idem-789', $decoded['idempotencyKey']);
    }

    public function testMissingIdempotencyKeyAccessorReturnsNull(): void
    {
        $journal = (new JournalBuilder())->input('')->build();

        $output = $this->invoke('readMetadata', $journal);

        $decoded = \json_decode($this->outputValue($output), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame([], $decoded['headers']);
        self::assertNull($decoded['idempotencyKey']);
    }

    private function invoke(string $handler, string $journal): string
    {
        $endpoint = Endpoint::builder()->bind(new CallOptionsService())->build();
        $request = new HttpRequest(
            'POST',
            "/invoke/CallOptionsService/{$handler}",
            ['content-type' => ServiceProtocolVersion::V7->contentType()],
            $journal,
        );

        return (new RequestProcessor($endpoint))->process($request)->body;
    }

    /** Returns the payload of the first frame of the given type. */
    private function frameOfType(string $output, MessageType $type): string
    {
        foreach (MessageCodec::decodeAll($output) as $frame) {
            if ($frame->type() === $type) {
                return $frame->payload;
            }
        }
        self::fail("No {$type->name} frame in response");
    }

    private function outputValue(string $output): string
    {
        $reader = new Reader($this->frameOfType($output, MessageType::OutputCommand));
        [$field] = $reader->readTag();
        self::assertSame(14, $field, 'output is a success value');

        return Value::decode($reader->readLengthDelimited())->content;
    }
}
