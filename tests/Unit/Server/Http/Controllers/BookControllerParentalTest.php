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

    /**
     * 🔓 S235 regression pin for the DELIBERATE opt-out.
     *
     * `/api/v1/books/{id}/download` and the whole `/opds/v1.2/…` group sit behind
     * {@see SignedUrlMiddleware}, so a request arriving here with no `userId` has
     * already presented a valid signature (or is an e-reader that authenticated
     * with Basic — in which case the middleware DOES set a userId, and the cap
     * still applies). S235 made an unidentified request fail CLOSED by default;
     * `bookOverCap()` opts out via `resolveFilterForSignedRequest()`, and this
     * test reddens if that opt-out is removed.
     *
     * The assertion is on the RESPONSE BODY, not just the status, because both
     * outcomes are 404: the gate refuses with `"Book not found"`, whereas passing
     * the gate reaches the library-root jail and yields `"File not found"` for
     * this fixture's non-existent path. Only the latter proves the gate let it
     * through.
     */
    public function testAnAnonymousSignedBookDownloadIsNotDeniedByTheGate(): void
    {
        $controller = $this->controller($this->book('NC-17'), $this->gate($this->pg13Filter()));

        $anonymous = new Request();
        $this->assertNull($anonymous->userId, 'fixture must really be anonymous');

        $resp = $controller->downloadBook($anonymous, ['id' => 'b1']);

        $this->assertSame(404, $resp->statusCode);
        $this->assertStringContainsString(
            'File not found',
            $resp->body,
            'the gate must NOT be what refused a signature-authorised fetch'
        );
    }

    public function testGetBookUnfilteredWhenGateUnwired(): void
    {
        $controller = $this->controller($this->book('NC-17'), null);

        $resp = $controller->getBook($this->cappedRequest(), ['id' => 'b1']);
        $this->assertSame(200, $resp->statusCode);
    }

    /**
     * @param list<array<string, mixed>> $books
     */
    private function listController(array $books, ?RatingGate $gate): BookController
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('searchFuzzy')->willReturn($books);
        $libraryManager = $this->createMock(LibraryManager::class);
        $opds = new OpdsFeedBuilder($repo, 'http://localhost:8080');

        return new BookController($repo, $libraryManager, $opds, $gate);
    }

    public function testListBooksDropsOverCapTitlesForCappedProfile(): void
    {
        $kid = ['id' => 'kidbook', 'name' => 'Kid Book', 'type' => 'book', 'content_rating' => 'PG'];
        $adult = ['id' => 'adultbook', 'name' => 'Adult Book', 'type' => 'book', 'content_rating' => 'R'];
        $controller = $this->listController([$kid, $adult], $this->gate($this->pg13Filter()));

        $resp = $controller->listBooks($this->cappedRequest());

        $this->assertSame(200, $resp->statusCode);
        $this->assertStringContainsString('kidbook', $resp->body);
        $this->assertStringNotContainsString('adultbook', $resp->body);
    }

    public function testListBooksUnfilteredForOwner(): void
    {
        $adult = ['id' => 'adultbook', 'name' => 'Adult Book', 'type' => 'book', 'content_rating' => 'R'];
        $controller = $this->listController([$adult], $this->gate($this->pg13Filter(), true));

        $resp = $controller->listBooks($this->cappedRequest());

        $this->assertSame(200, $resp->statusCode);
        $this->assertStringContainsString('adultbook', $resp->body);
    }
}
