<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Auth\SignedUrl;
use Phlix\Server\Http\Middleware\SignedUrlMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;

final class SignedUrlMiddlewareTest extends TestCase
{
    private SignedUrl $signer;

    protected function setUp(): void
    {
        $this->signer = new SignedUrl('middleware-test-secret', 3600);
    }

    /** Builds a request for $path carrying the exp/sig of a freshly-minted URL. */
    private function signedRequest(string $path): Request
    {
        $url = $this->signer->mint($path);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $request = new Request();
        $request->path = $path;
        /** @var array<string, array<mixed>|string> $query */
        $request->query = $query;

        return $request;
    }

    public function testPassesThroughWhenSessionPresent(): void
    {
        $request = new Request();
        $request->userId = 'user-123';

        $middleware = new SignedUrlMiddleware(false, 'Phlix', null, $this->signer);
        $this->assertNull($middleware($request));
    }

    public function testPassesThroughWithValidSignature(): void
    {
        $request = $this->signedRequest('/api/v1/photo/photos/p1/full');

        $middleware = new SignedUrlMiddleware(false, 'Phlix', null, $this->signer);
        $this->assertNull($middleware($request));
    }

    public function testRejectsWithoutSessionOrSignature(): void
    {
        $request = new Request();
        $request->path = '/api/v1/photo/photos/p1/full';

        $response = (new SignedUrlMiddleware(false, 'Phlix', null, $this->signer))($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('auth.required', $body['code']);
        // Non-OPDS routes must NOT emit a Basic challenge (would pop a browser dialog).
        $this->assertArrayNotHasKey('WWW-Authenticate', $response->headers);
    }

    public function testRejectsInvalidSignature(): void
    {
        $request = new Request();
        $request->path = '/api/v1/photo/photos/p1/full';
        $request->query = ['exp' => (string) (time() + 3600), 'sig' => 'forged-signature'];

        $response = (new SignedUrlMiddleware(false, 'Phlix', null, $this->signer))($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->statusCode);
    }

    public function testSignatureForADifferentPathIsRejected(): void
    {
        // Mint for one path, present it on another → must fail.
        $url = $this->signer->mint('/api/v1/photo/photos/p1/full');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $request = new Request();
        $request->path = '/api/v1/photo/photos/SECRET/full';
        /** @var array<string, array<mixed>|string> $query */
        $request->query = $query;

        $response = (new SignedUrlMiddleware(false, 'Phlix', null, $this->signer))($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->statusCode);
    }

    public function testOpdsAcceptsValidBasicCredentials(): void
    {
        $validator = static fn (string $u, string $p): ?string => ($u === 'alice' && $p === 'pw') ? 'user-1' : null;

        $request = new Request();
        $request->path = '/opds/v1.2';
        $request->headers = ['AUTHORIZATION' => 'Basic ' . base64_encode('alice:pw')];

        $middleware = SignedUrlMiddleware::forOpds($validator, $this->signer);
        $this->assertNull($middleware($request));
        // The middleware promotes the Basic identity to a session for downstream code.
        $this->assertSame('user-1', $request->userId);
    }

    public function testOpdsRejectsBadBasicWithChallenge(): void
    {
        $validator = static fn (string $u, string $p): ?string => null;

        $request = new Request();
        $request->path = '/opds/v1.2';
        $request->headers = ['AUTHORIZATION' => 'Basic ' . base64_encode('alice:wrong')];

        $response = SignedUrlMiddleware::forOpds($validator, $this->signer)($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->statusCode);
        $this->assertArrayHasKey('WWW-Authenticate', $response->headers);
        $this->assertStringContainsString('Basic realm="Phlix OPDS"', $response->headers['WWW-Authenticate']);
    }

    public function testOpdsChallengesWhenNoCredentialsSupplied(): void
    {
        $validator = static fn (string $u, string $p): string => 'user-1';

        $request = new Request();
        $request->path = '/opds/v1.2';

        $response = SignedUrlMiddleware::forOpds($validator, $this->signer)($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->statusCode);
        $this->assertArrayHasKey('WWW-Authenticate', $response->headers);
    }

    public function testOpdsRejectsMalformedBasicHeaderWithoutCallingValidator(): void
    {
        $called = false;
        $validator = static function (string $u, string $p) use (&$called): string {
            $called = true;

            return 'user-1';
        };

        $request = new Request();
        $request->path = '/opds/v1.2';
        $request->headers = ['AUTHORIZATION' => 'Basic !!!not-base64!!!'];

        $response = SignedUrlMiddleware::forOpds($validator, $this->signer)($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->statusCode);
        $this->assertFalse($called, 'validator must not run on a malformed header');
    }

    public function testOpdsStillAcceptsAValidSignature(): void
    {
        // An OPDS-configured gate must keep honouring signed URLs and sessions.
        $validator = static fn (string $u, string $p): ?string => null;
        $request = $this->signedRequest('/opds/v1.2/books/b1/cover');

        $middleware = SignedUrlMiddleware::forOpds($validator, $this->signer);
        $this->assertNull($middleware($request));
    }

    public function testSessionTakesPrecedenceOverBasic(): void
    {
        $validator = static fn (string $u, string $p): string => 'basic-user';
        $request = new Request();
        $request->path = '/opds/v1.2';
        $request->userId = 'session-user';
        $request->headers = ['AUTHORIZATION' => 'Basic ' . base64_encode('alice:pw')];

        $middleware = SignedUrlMiddleware::forOpds($validator, $this->signer);
        $this->assertNull($middleware($request));
        // Session identity is preserved (Basic validator never consulted).
        $this->assertSame('session-user', $request->userId);
    }
}
