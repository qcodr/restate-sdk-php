<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Context\Context;
use Restate\Sdk\Endpoint\Endpoint;
use Restate\Sdk\Endpoint\HttpRequest;
use Restate\Sdk\Endpoint\RequestProcessor;
use Restate\Sdk\Protocol\Message\Value;
use Restate\Sdk\Protocol\MessageCodec;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Reader;
use Restate\Sdk\Protocol\ServiceProtocolVersion;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Service;
use Restate\Sdk\Tests\Support\JournalBuilder;

/**
 * Covers the raw (serde-bypassing) call primitives: {@see Context::genericCall} returns
 * the callee's response bytes verbatim, and {@see Context::genericSend} fires a one-way
 * call and resolves the callee's invocation id.
 */
final class GenericCallTest extends TestCase
{
    public function testGenericCallReturnsRawResponseBytes(): void
    {
        // sysCall allocates completion id 1 (invocation id) and 2 (result); the journal
        // resolves the result completion with a raw value so await() does not suspend.
        $journal = (new JournalBuilder())
            ->input('')
            ->callCompletion(2, 'raw-bytes-payload')
            ->build();

        $output = $this->invoke('callGeneric', $journal);

        // The handler returns the raw call result; the harness's output serde JSON-encodes
        // it. Stripping that single output-layer encoding recovers the bytes genericCall
        // produced — verbatim, with no JSON quoting applied to the call result itself.
        $returned = \json_decode($this->outputValue($output), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('raw-bytes-payload', $returned);
    }

    public function testGenericSendReturnsCalleeInvocationId(): void
    {
        // sysOneWayCall allocates completion id 1 (the invocation id); the journal resolves
        // it so the invocation-id future does not suspend.
        $journal = (new JournalBuilder())
            ->input('')
            ->invocationIdCompletion(1, 'inv-xyz')
            ->build();

        $output = $this->invoke('sendGeneric', $journal);

        $returned = \json_decode($this->outputValue($output), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('inv-xyz', $returned);
    }

    private function invoke(string $handler, string $journal): string
    {
        $endpoint = Endpoint::builder()->bind(new GenericCallService())->build();
        $request = new HttpRequest(
            'POST',
            "/invoke/GenericCallService/{$handler}",
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

/**
 * Inline fixture exercising the raw call primitives. Kept in the test file so the
 * surface stays self-contained under tests/Unit/.
 */
#[Service]
final class GenericCallService
{
    /** Forwards raw bytes via {@see Context::genericCall} and returns the raw response. */
    #[Handler]
    public function callGeneric(Context $ctx): string
    {
        return $ctx->genericCall('Svc', '', 'h', 'rawbytes');
    }

    /** Fires a raw one-way call via {@see Context::genericSend} and returns the callee id. */
    #[Handler]
    public function sendGeneric(Context $ctx): string
    {
        return $ctx->genericSend('Svc', '', 'h', 'x');
    }
}
