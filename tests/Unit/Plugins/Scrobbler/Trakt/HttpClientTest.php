<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\HttpClient;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the non-network surface of the Trakt {@see HttpClient}.
 *
 * The real transport (requestCurl / requestAsync cooperative-wait + header
 * capture) performs live network I/O and, per this repo's established idiom for
 * its sibling de-blocked HTTP clients (Hub\HttpClient, MetadataHttpClient — see
 * their tests), is NOT exercised against a live server in the unit suite; the
 * Trakt API callers are tested against the MockHttpClient double in
 * TraktApiTest instead. This file locks down the input-guard branch that is
 * reachable without a socket: all three verbs reject an empty URL before any
 * transport is selected.
 */
final class HttpClientTest extends TestCase
{
    public function testGetRejectsEmptyUrl(): void
    {
        $client = new HttpClient();

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessage('URL cannot be empty');

        $client->get('');
    }

    public function testGetWithHeadersRejectsEmptyUrl(): void
    {
        $client = new HttpClient();

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessage('URL cannot be empty');

        $client->getWithHeaders('');
    }

    public function testPostRejectsEmptyUrl(): void
    {
        $client = new HttpClient();

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessage('URL cannot be empty');

        $client->post('');
    }
}
