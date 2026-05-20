<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\RelayFrame;
use Phlix\Hub\RelayMessageFramer;

class RelayMessageFramerTest extends TestCase
{
    private RelayMessageFramer $framer;

    protected function setUp(): void
    {
        $this->framer = new RelayMessageFramer();
    }

    public function test_frame_request_round_trips(): void
    {
        $seq = 42;
        $method = 'GET';
        $path = '/api/v1/media/123';
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer token123',
        ];
        $body = '';

        $frame = $this->framer->frameRequest($seq, $method, $path, $headers, $body);
        $parsed = $this->framer->parse($frame);

        $this->assertInstanceOf(RelayFrame::class, $parsed);
        $this->assertSame(RelayMessageFramer::TYPE_HTTP_REQUEST, $parsed->type);
        $this->assertSame($seq, $parsed->seq);
        $this->assertSame($method, $parsed->payload['method']);
        $this->assertSame($path, $parsed->payload['path']);
        $this->assertSame($headers, $parsed->payload['headers']);
        $this->assertSame($body, $parsed->payload['body']);
    }

    public function test_frame_request_with_body_round_trips(): void
    {
        $seq = 1;
        $method = 'POST';
        $path = '/api/v1/sessions';
        $headers = ['Content-Type' => 'application/json'];
        $body = '{"profile_id":"abc-123"}';

        $frame = $this->framer->frameRequest($seq, $method, $path, $headers, $body);
        $parsed = $this->framer->parse($frame);

        $this->assertInstanceOf(RelayFrame::class, $parsed);
        $this->assertSame(RelayMessageFramer::TYPE_HTTP_REQUEST, $parsed->type);
        $this->assertSame($body, $parsed->payload['body']);
    }

    public function test_frame_response_round_trips(): void
    {
        $seq = 99;
        $statusCode = 200;
        $headers = [
            'Content-Type' => 'application/json',
            'Content-Length' => '27',
        ];
        $body = '{"media_items":[]}';

        $frame = $this->framer->frameResponse($seq, $statusCode, $headers, $body);
        $parsed = $this->framer->parse($frame);

        $this->assertInstanceOf(RelayFrame::class, $parsed);
        $this->assertSame(RelayMessageFramer::TYPE_HTTP_RESPONSE, $parsed->type);
        $this->assertSame($seq, $parsed->seq);
        $this->assertSame($statusCode, $parsed->payload['status']);
        $this->assertSame($headers, $parsed->payload['headers']);
        $this->assertSame($body, $parsed->payload['body']);
    }

    public function test_parse_ping_frame(): void
    {
        $seq = 7;
        $frame = $this->framer->framePing($seq);
        $parsed = $this->framer->parse($frame);

        $this->assertInstanceOf(RelayFrame::class, $parsed);
        $this->assertSame(RelayMessageFramer::TYPE_PING, $parsed->type);
        $this->assertSame($seq, $parsed->seq);
        $this->assertTrue($parsed->isPing());
    }

    public function test_parse_pong_frame(): void
    {
        $seq = 8;
        $frame = $this->framer->framePong($seq);
        $parsed = $this->framer->parse($frame);

        $this->assertInstanceOf(RelayFrame::class, $parsed);
        $this->assertSame(RelayMessageFramer::TYPE_PONG, $parsed->type);
        $this->assertSame($seq, $parsed->seq);
        $this->assertTrue($parsed->isPong());
    }

    public function test_parse_invalid_frame_returns_null(): void
    {
        $invalidData = "\xFF\x00\x00\x00\x00";
        $parsed = $this->framer->parse($invalidData);

        $this->assertNull($parsed);
    }

    public function test_parse_incomplete_frame_returns_null(): void
    {
        $seq = 1;
        $frame = $this->framer->framePing($seq);
        $truncated = substr($frame, 0, 5);

        $this->assertNull($this->framer->parse($truncated));
    }

    public function test_parse_response_frame_with_error_status(): void
    {
        $seq = 55;
        $frame = $this->framer->frameResponse($seq, 404, ['Content-Type' => 'application/json'], '{"error":"Not found"}');
        $parsed = $this->framer->parse($frame);

        $this->assertInstanceOf(RelayFrame::class, $parsed);
        $this->assertSame(404, $parsed->payload['status']);
    }

    public function test_frame_type_constants(): void
    {
        $this->assertSame(1, RelayMessageFramer::TYPE_HTTP_REQUEST);
        $this->assertSame(2, RelayMessageFramer::TYPE_HTTP_RESPONSE);
        $this->assertSame(3, RelayMessageFramer::TYPE_PING);
        $this->assertSame(4, RelayMessageFramer::TYPE_PONG);
    }

    public function test_isRequest_returns_true_for_request_frame(): void
    {
        $frame = $this->framer->frameRequest(1, 'GET', '/', [], '');
        $parsed = $this->framer->parse($frame);

        $this->assertTrue($parsed->isRequest());
        $this->assertFalse($parsed->isResponse());
        $this->assertFalse($parsed->isPing());
        $this->assertFalse($parsed->isPong());
    }

    public function test_isResponse_returns_true_for_response_frame(): void
    {
        $frame = $this->framer->frameResponse(1, 200, [], '');
        $parsed = $this->framer->parse($frame);

        $this->assertTrue($parsed->isResponse());
        $this->assertFalse($parsed->isRequest());
    }

    public function test_seq_is_32bit_unsigned(): void
    {
        $seq = 0xFFFFFFFF;
        $frame = $this->framer->framePing($seq);
        $parsed = $this->framer->parse($frame);

        $this->assertSame($seq, $parsed->seq);
    }

    public function test_empty_headers_in_request(): void
    {
        $frame = $this->framer->frameRequest(1, 'DELETE', '/api/v1/items/5', [], '');
        $parsed = $this->framer->parse($frame);

        $this->assertIsArray($parsed->payload['headers']);
        $this->assertEmpty($parsed->payload['headers']);
    }
}
