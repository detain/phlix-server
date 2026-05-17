<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlex\Hub\HttpResponse;

class HttpClientTest extends TestCase
{
    public function test_httpResponse_isSuccess_true_for_2xx(): void
    {
        $response = new HttpResponse(200, [], ['ok' => true]);
        $this->assertTrue($response->isSuccess());

        $response = new HttpResponse(201, [], []);
        $this->assertTrue($response->isSuccess());

        $response = new HttpResponse(204, [], []);
        $this->assertTrue($response->isSuccess());
    }

    public function test_httpResponse_isSuccess_false_for_non_2xx(): void
    {
        $response = new HttpResponse(400, [], ['error' => 'BAD_REQUEST']);
        $this->assertFalse($response->isSuccess());

        $response = new HttpResponse(401, [], ['error' => 'UNAUTHORIZED']);
        $this->assertFalse($response->isSuccess());

        $response = new HttpResponse(500, [], ['error' => 'INTERNAL_ERROR']);
        $this->assertFalse($response->isSuccess());
    }

    public function test_httpResponse_getErrorCode_returns_error_field(): void
    {
        $response = new HttpResponse(400, [], ['error' => 'SERVER_KEY_INVALID', 'message' => 'Bad key']);
        $this->assertEquals('SERVER_KEY_INVALID', $response->getErrorCode());
    }

    public function test_httpResponse_getErrorCode_returns_null_when_no_error(): void
    {
        $response = new HttpResponse(200, [], ['ok' => true]);
        $this->assertNull($response->getErrorCode());
    }

    public function test_httpResponse_body_is_array(): void
    {
        $response = new HttpResponse(200, [], ['keys' => [['kty' => 'OKP']]]);
        $this->assertIsArray($response->body);
        $this->assertEquals('OKP', $response->body['keys'][0]['kty']);
    }

    public function test_httpResponse_headers_are_accessible(): void
    {
        $response = new HttpResponse(200, ['content-type' => 'application/json', 'cache-control' => 'public'], []);
        $this->assertEquals('application/json', $response->headers['content-type']);
        $this->assertEquals('public', $response->headers['cache-control']);
    }
}
