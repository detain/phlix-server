<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Metadata\OpdsFeedBuilder;
use Phlix\Server\Http\Controllers\BookController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Parental-control ACCESS gate coverage for {@see BookController} (Finding 3).
 * A book is a `media_items` row with a `content_rating`, so a capped profile must
 * not obtain its signed read/download URLs (getBook mint gate) nor its bytes
 * (download/read serve gate). The owner / un-capped profile is never gated.
 */
class BookControllerParentalTest extends TestCase
{
    /**
     * @return array{allowedRatings: list<string>, allowUnrated: bool}
     */
    private function pg13Filter(): array
    {
        return ['allowedRatings' => ['G', 'PG', 'PG-13'], 'allowUnrated' => true];
    }

    /**
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter
     */
    private function gate(?array $filter, bool $isAdmin = false): RatingGate
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('effectiveContentRatingsForIds')->willReturn([]);
        $pm = $this->createMock(UserProfileManager::class);
        $pm->method('getActiveRatingFilter')->willReturn($filter);
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => $isAdmin ? 1 : 0]);

        return new RatingGate($items, $pm, $users);
    }

    /**
     * @param array<string, mixed> $book
     */
    private function controller(array $book, ?RatingGate $gate): BookController
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($book);
        $libraryManager = $this->createMock(LibraryManager::class);
        $opds = new OpdsFeedBuilder($repo, 'http://localhost:8080');

        return new BookController($repo, $libraryManager, $opds, $gate);
    }

    private function cappedRequest(): Request
    {
        $req = new Request();
        $req->userId = 'u1';
        return $req;
    }

    /**
     * @return array<string, mixed>
     */
    private function book(string $rating): array
    {
        return [
            'id' => 'b1', 'name' => 'A Book', 'type' => 'book',
            'content_rating' => $rating, 'path' => '/lib/a.epub', 'metadata' => [],
        ];
    }

    public function testGetBookBlocksOverCapWithNoSignedUrls(): void
    {
        $controller = $this->controller($this->book('R'), $this->gate($this->pg13Filter()));

        $resp = $controller->getBook($this->cappedRequest(), ['id' => 'b1']);

        $this->assertSame(404, $resp->statusCode);
        // No signed read/download/cover URL is disclosed.
        $this->assertStringNotContainsString('download_url', $resp->body);
        $this->assertStringNotContainsString('sig=', $resp->body);
    }

    public function testGetBookAllowsWithinCap(): void
    {
        $controller = $this->controller($this->book('PG'), $this->gate($this->pg13Filter()));

        $resp = $controller->getBook($this->cappedRequest(), ['id' => 'b1']);
        $this->assertSame(200, $resp->statusCode);
        $this->assertStringContainsString('download_url', $resp->body);
    }

    public function testGetBookAllowsOverCapForOwner(): void
    {
        $controller = $this->controller($this->book('NC-17'), $this->gate($this->pg13Filter(), true));

        $resp = $controller->getBook($this->cappedRequest(), ['id' => 'b1']);
        $this->assertSame(200, $resp->statusCode);
    }

    public function testDownloadBlocksOverCapBook(): void
    {
        $controller = $this->controller($this->book('R'), $this->gate($this->pg13Filter()));

        $resp = $controller->downloadBook($this->cappedRequest(), ['id' => 'b1']);
        $this->assertSame(404, $resp->statusCode);
    }

    public function testReadBlocksOverCapBook(): void
    {
        $controller = $this->controller($this->book('R'), $this->gate($this->pg13Filter()));

        $resp = $controller->readBook($this->cappedRequest(), ['id' => 'b1']);
        $this->assertSame(404, $resp->statusCode);
    }

    public function testGetBookUnfilteredWhenGateUnwired(): void
    {
        $controller = $this->controller($this->book('NC-17'), null);

        $resp = $controller->getBook($this->cappedRequest(), ['id' => 'b1']);
        $this->assertSame(200, $resp->statusCode);
    }
}
