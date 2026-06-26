<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol;

use Restate\Sdk\Protocol\Message\OutgoingMessage;

/**
 * Frames outgoing messages and parses an incoming byte stream into {@see Frame}s.
 *
 * A frame is a 64-bit {@see MessageHeader} followed by the protobuf payload.
 * {@see decodeAll} assumes the buffer contains only whole frames, which holds for
 * request/response transport where the runtime sends the entire journal at once;
 * {@see consume} supports incremental decoding from a growing buffer.
 */
final class MessageCodec
{
    public static function encode(OutgoingMessage $message): string
    {
        $payload = $message->encode();
        $header = new MessageHeader(
            $message->messageType()->value,
            \strlen($payload),
            $message->requestedAck(),
        );

        return $header->encode() . $payload;
    }

    /**
     * Parses every complete frame in the buffer.
     *
     * @return list<Frame>
     */
    public static function decodeAll(string $buffer): array
    {
        $frames = [];
        $offset = 0;
        while (($frame = self::consume($buffer, $offset)) !== null) {
            $frames[] = $frame;
        }

        if ($offset !== \strlen($buffer)) {
            throw new ProtocolException('Trailing bytes after last complete frame');
        }

        return $frames;
    }

    /**
     * Reads one frame starting at $offset, advancing it past the frame.
     *
     * @param int $offset advanced in place past the consumed frame
     *
     * @return Frame|null the frame, or null if the buffer does not yet hold a complete one
     */
    public static function consume(string $buffer, int &$offset): ?Frame
    {
        $available = \strlen($buffer) - $offset;
        if ($available < MessageHeader::SIZE) {
            return null;
        }

        $header = MessageHeader::decode(\substr($buffer, $offset, MessageHeader::SIZE));
        $total = MessageHeader::SIZE + $header->length;
        if ($available < $total) {
            return null;
        }

        $payload = \substr($buffer, $offset + MessageHeader::SIZE, $header->length);
        $offset += $total;

        return new Frame($header->typeCode, $payload, $header->requestedAck);
    }
}
