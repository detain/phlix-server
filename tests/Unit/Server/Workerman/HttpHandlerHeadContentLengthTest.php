<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Auth\SignedUrl;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Workerman\BodylessResponse;
use Phlix\Server\Workerman\HttpHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * `HEAD /media/{id}/stream` must declare the file's size ONCE on the wire.
 *
 * This is the pre-existing twin of the DLNA defect found in S52 review: the arm at
 * `HttpHandler::serveMediaStream()` correctly set the real `Content-Length` on an
 * empty body, and Workerman's encoder then appended its own
 * `Content-Length: strlen($body)` — i.e. `0` — after it
 * (`vendor/workerman/workerman/src/Protocols/Http/Response.php:580-583`). RFC 9110
 * §8.6 makes a message with conflicting `Content-Length` values invalid: recipients
 * must reject it, HAProxy drops it as a request-smuggling defence, and clients
 * disagree about which value wins — and the wrong value was LAST.
 *
 * Every client that probes availability with `HEAD` before opening a stream reads
 * that reply, so the assertion is made on `(string) $response`. A `getHeader()`
 * assertion cannot see the defect: the response object holds only the correct value.
 */
final class HttpHandlerHeadContentLengthTest extends TestCase
{
    /** Fixture body: a size that cannot be confused with 0. */
    private const BODY = 'ABCDEFGHIJKLMNOP';

    private string $mediaPath = '';

    protected function setUp(): void
    {
        SignedUrl::resetSharedForTesting();
        $this->mediaPath = sys_get_temp_dir() . '/phlix-headlen-' . bin2hex(random_bytes(6)) . '.mp4';
        file_put_contents($this->mediaPath, self::BODY);
    }

    protected function tearDown(): void
    {
        if ($this->mediaPath !== '' && is_file($this->mediaPath)) {
            @unlink($this->mediaPath);
        }
        SignedUrl::resetSharedForTesting();
    }

    private function handler(): HttpHandler
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn([
            'id'   => 'm1',
            'type' => 'movie',
            'path' => $this->mediaPath,
        ]);
        $repo->method('effectiveContentRatingsForIds')->willReturn([]);

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('getActiveProfile')->willReturn(['id' => 'p1']);
        // No cap, so the rating gate is a no-op and cannot mask the assertion.
        $profiles->method('getActiveRatingFilter')->willReturn(null);

        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => 0]);

        $gate = new RatingGate($repo, $profiles, $users);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $class): object => match ($class) {
                RatingGate::class          => $gate,
                UserProfileManager::class  => $profiles,
                default                    => $repo,
            },
        );

        return new HttpHandler(
            $container,
            new RequestAuthenticator($this->createMock(AuthManager::class)),
            sys_get_temp_dir(),
            $this->createMock(Application::class),
            null,
        );
    }

    private function head(): ?WorkermanResponse
    {
        $request = new WorkermanRequest("HEAD /media/m1/stream HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $method = new ReflectionMethod(HttpHandler::class, 'serveMediaStream');
        /** @var WorkermanResponse|null $result */
        $result = $method->invoke($this->handler(), $request, 'u1');

        return $result;
    }

    /**
     * THE DEFECT: exactly one `Content-Length`, and it is the real file size.
     *
     * DISCRIMINATING: reverting the arm to a plain `WorkermanResponse` makes
     * `Content-Length:` appear twice, with `0` last, and this fails.
     */
    public function test_head_puts_exactly_one_real_content_length_on_the_wire(): void
    {
        $response = $this->head();

        self::assertInstanceOf(WorkermanResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());

        $wire = (string) $response;

        self::assertSame(
            1,
            substr_count($wire, 'Content-Length:'),
            "A HEAD reply must carry exactly ONE Content-Length. Encoded bytes were:\n" . $wire,
        );
        self::assertStringContainsString('Content-Length: ' . strlen(self::BODY) . "\r\n", $wire);
        self::assertStringNotContainsString('Content-Length: 0', $wire);
        self::assertStringNotContainsString(self::BODY, $wire, 'A HEAD reply must not carry the bytes.');
    }

    /**
     * LOCK-IN on the mechanism, so a future edit cannot quietly swap the class back
     * and re-break the wire while the status code and headers still look right.
     */
    public function test_the_head_arm_uses_the_bodyless_encoder(): void
    {
        self::assertInstanceOf(BodylessResponse::class, $this->head());
    }
}
