<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Endpoint;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;
use Qcodr\Restate\Sdk\Protocol\Message\Value;
use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Counter;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Greeter;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;

final class RequestProcessorTest extends TestCase
{
    private function processor(): RequestProcessor
    {
        $endpoint = Endpoint::builder()->bind(new Greeter())->bind(new Counter())->build();

        return new RequestProcessor($endpoint);
    }

    private function invoke(string $service, string $handler, string $journal): string
    {
        $request = new HttpRequest(
            'POST',
            "/invoke/{$service}/{$handler}",
            ['content-type' => ServiceProtocolVersion::V7->contentType()],
            $journal,
        );

        $response = $this->processor()->process($request);
        self::assertSame(200, $response->status);
        self::assertSame(ServiceProtocolVersion::V7->contentType(), $response->headers['content-type']);

        return $response->body;
    }

    /** @return list<MessageType|null> */
    private function frameTypes(string $output): array
    {
        return \array_map(static fn ($f) => $f->type(), MessageCodec::decodeAll($output));
    }

    private function outputValue(string $output): string
    {
        foreach (MessageCodec::decodeAll($output) as $frame) {
            if ($frame->type() === MessageType::OutputCommand) {
                $reader = new Reader($frame->payload);
                [$field] = $reader->readTag();
                self::assertSame(14, $field, 'output is a success value');

                return Value::decode($reader->readLengthDelimited())->content;
            }
        }
        self::fail('No OutputCommand in response');
    }

    public function testDiscoveryReturnsManifest(): void
    {
        $request = new HttpRequest('GET', '/discovery', [
            'accept' => 'application/vnd.restate.endpointmanifest.v1+json',
        ], '');

        $response = $this->processor()->process($request);

        self::assertSame(200, $response->status);
        self::assertSame('application/vnd.restate.endpointmanifest.v1+json', $response->headers['content-type']);

        $manifest = \json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame(5, $manifest['minProtocolVersion']);
        self::assertSame(7, $manifest['maxProtocolVersion']);
        self::assertSame('REQUEST_RESPONSE', $manifest['protocolMode']);

        $byName = self::indexByName($manifest['services']);
        self::assertSame('SERVICE', $byName['Greeter']['ty']);
        self::assertSame('VIRTUAL_OBJECT', $byName['Counter']['ty']);

        $counterHandlers = self::indexByName($byName['Counter']['handlers']);
        self::assertSame('EXCLUSIVE', $counterHandlers['add']['ty']);
        self::assertSame('SHARED', $counterHandlers['get']['ty']);
    }

    /**
     * Indexes a list of `{name: ...}` entries by their name.
     *
     * @return array<string, array<mixed>>
     */
    private static function indexByName(mixed $entries): array
    {
        self::assertIsArray($entries);

        $indexed = [];
        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['name']);
            $indexed[$entry['name']] = $entry;
        }

        return $indexed;
    }

    public function testInvokeGreeter(): void
    {
        $journal = (new JournalBuilder())->input('"world"')->build();
        $output = $this->invoke('Greeter', 'greet', $journal);

        self::assertSame([MessageType::OutputCommand, MessageType::End], $this->frameTypes($output));
        self::assertSame('"Greetings world"', $this->outputValue($output));
    }

    public function testInvokeCounterAddOnEmptyState(): void
    {
        $journal = (new JournalBuilder(key: 'orders'))->input('1')->build();
        $output = $this->invoke('Counter', 'add', $journal);

        self::assertSame([
            MessageType::GetEagerStateCommand,
            MessageType::SetStateCommand,
            MessageType::OutputCommand,
            MessageType::End,
        ], $this->frameTypes($output));
        self::assertSame('1', $this->outputValue($output));
    }

    public function testInvokeCounterAddOnExistingState(): void
    {
        $journal = (new JournalBuilder(key: 'orders', stateMap: ['count' => '5']))->input('3')->build();
        $output = $this->invoke('Counter', 'add', $journal);

        self::assertSame('8', $this->outputValue($output));
    }

    public function testSharedHandlerReadsState(): void
    {
        $journal = (new JournalBuilder(key: 'orders', stateMap: ['count' => '42']))->input('')->build();
        $output = $this->invoke('Counter', 'get', $journal);

        self::assertSame('42', $this->outputValue($output));
    }

    public function testUnknownHandlerReturns404(): void
    {
        $request = new HttpRequest(
            'POST',
            '/invoke/Greeter/missing',
            ['content-type' => ServiceProtocolVersion::V7->contentType()],
            (new JournalBuilder())->input('"x"')->build(),
        );

        self::assertSame(404, $this->processor()->process($request)->status);
    }

    public function testUnsupportedContentTypeReturns415(): void
    {
        $request = new HttpRequest('POST', '/invoke/Greeter/greet', ['content-type' => 'application/json'], '');

        self::assertSame(415, $this->processor()->process($request)->status);
    }
}
