<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItemShaper;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Storage\ArtworkDownloadPolicy;
use Phlix\Media\Storage\ArtworkStorage;

/**
 * S72 — person/content-hash-keyed shared cache.
 *
 * Proves {@see LibraryMetadataMatcher::cachePeopleLocally()} satisfies the
 * step's headline acceptance criterion: the SAME cast member appearing in N
 * media items is downloaded/cached exactly ONCE — asserted by counting the
 * {@see ArtworkStorage::downloadAndStore()} calls across N item-enrichment
 * (match) calls referencing the same person, keyed by the FLAT
 * `people-{tmdbPersonId}` directory (never by the media-item UUID), and by
 * the rewrite of each people entry's `profile_url` to the signed local
 * `/api/v1/artwork/people-{id}?size=w185` URL.
 *
 * Companion guards: the no-op contract with storage unwired, the operator
 * `artwork.download_enabled` gate, fail-soft on download errors (with the
 * log line), non-TMDB URLs never fetched, and the missing-id content-hash
 * fallback key. The final test pins the client-boundary contract: the added
 * `id`/`profile_path` keys are metadata-internal — the media-item shaper
 * whitelists `{name, role|job, profile_url}` on the response.
 */
final class LibraryMetadataMatcherPeopleCacheTest extends TestCase
{
    /** @var list<string> variant names a successful downloadAndStore() returns. */
    private const FIVE_VARIANTS = ['w185', 'w342', 'w500', 'w780', 'original'];

    /**
     * Lane survival token (this step's premerge `--token` assertion strips
     * comments before matching, so the string must live in CODE, not prose).
     */
    private const LANE_TOKEN = 'CS72PEOPLECACHEX9Q';

    private const SIGNED_287 = '/api/v1/artwork/people-287?size=w185&exp=2000000000&sig=deadbeef';

    private const SIGNED_288 = '/api/v1/artwork/people-288?size=w185&exp=2000000000&sig=deadbeef';

    /**
     * One people entry in the canonical provider shape (S72 adds `id`).
     *
     * @return array{name: string, role: string, profile_url: string, id: string|null}
     */
    private static function castMember(string $name, string $role, string $file, ?string $id): array
    {
        return [
            'name' => $name,
            'role' => $role,
            'profile_url' => 'https://image.tmdb.org/t/p/w185/' . $file,
            'id' => $id,
        ];
    }

    /**
     * @return array{name: string, job: string, profile_url: string, id: string|null}
     */
    private static function crewMember(string $name, string $job, string $file, ?string $id): array
    {
        return [
            'name' => $name,
            'job' => $job,
            'profile_url' => 'https://image.tmdb.org/t/p/w185/' . $file,
            'id' => $id,
        ];
    }

    /**
     * HEADLINE AC: the same cast member appearing in N media items is
     * downloaded/cached exactly once.
     *
     * Person 287 appears in THREE items (plus once more as 287's crew entry in
     * the third); person 288 appears once. Across three enrichment calls the
     * download seam must fire TWICE — once per PERSON — under the flat shared
     * keys `people-287` / `people-288`, never under an item UUID. Every item's
     * profile_url must end up the SAME signed local URL per person.
     */
    public function testSameCastMemberAcrossItemsDownloadsExactlyOnce(): void
    {
        $calls = [];
        $artwork = $this->createMock(ArtworkStorage::class);
        $artwork->method('downloadAndStore')
            ->willReturnCallback(
                /** @return list<string> */
                static function ($key, $path) use (&$calls): array {
                    $calls[] = [(string) $key, (string) $path];

                    return self::FIVE_VARIANTS;
                }
            );
        $artwork->method('relativePath')
            ->willReturnCallback(
                static fn($key, $size): string => '/api/v1/artwork/' . $key . '?size=' . $size
            );
        $artwork->method('url')
            ->willReturnCallback(
                /** @return string|null */
                static function ($key, $size, $path = null) {
                    return $path === null ? null : $path . '&exp=2000000000&sig=deadbeef';
                }
            );

        $keanu = static fn(): array => self::castMember('Keanu Reeves', 'Neo', 'keanu.jpg', '287');
        $updates = $this->runMatchWithCalls(
            [
                'm1' => ['cast' => [$keanu()]],
                'm2' => ['cast' => [$keanu(), self::castMember('Carrie-Anne Moss', 'Trinity', 'carrie.jpg', '288')]],
                'm3' => [
                    'cast' => [$keanu()],
                    'crew' => [self::crewMember('Keanu Reeves', 'Producer', 'keanu.jpg', '287')],
                ],
            ],
            $artwork
        );

        // EXACTLY ONE FETCH PER PERSON across 4 references in 3 items:
        $this->assertSame(
            [['people-287', '/keanu.jpg'], ['people-288', '/carrie.jpg']],
            $calls,
            self::LANE_TOKEN . ': S72 AC — the same cast member in N items must be '
                . 'downloaded exactly once, keyed by the flat person id, not the media item UUID.'
        );

        // Every reference now points at the SAME shared local URL — m3's crew
        // (person 287) reuses m1's download without any second call.
        $this->assertSame(self::SIGNED_287, $updates['m1']['cast'][0]['profile_url']);
        $this->assertSame(self::SIGNED_287, $updates['m3']['crew'][0]['profile_url']);
        $this->assertSame(self::SIGNED_288, $updates['m2']['cast'][1]['profile_url']);

        // The repair handle rides along (poster_path precedent) and nothing on
        // any persisted people entry still names the TMDB CDN.
        $this->assertSame('/keanu.jpg', $updates['m1']['cast'][0]['profile_path']);
        $this->assertSame('/keanu.jpg', $updates['m3']['crew'][0]['profile_path']);
        foreach (['m1', 'm2', 'm3'] as $id) {
            foreach (['cast', 'crew'] as $group) {
                foreach ($updates[$id][$group] ?? [] as $entry) {
                    $this->assertStringNotContainsString('image.tmdb.org', (string) $entry['profile_url']);
                }
            }
        }
    }

    /**
     * Same run harness as {@see testSameCastMemberAcrossItemsDownloadsExactlyOnce()}
     * but fed a pre-built mock whose callback the test itself owns (so the
     * call list is visible without dynamic mock properties).
     *
     * @param array<string, array{cast?: list<array<string, mixed>>, crew?: list<array<string, mixed>>}> $peopleById
     * @return array<string, array<string, mixed>>
     */
    private function runMatchWithCalls(
        array $peopleById,
        ArtworkStorage $artwork,
        ?ArtworkDownloadPolicy $policy = null
    ): array {
        $rows = [];
        foreach (array_keys($peopleById) as $id) {
            $rows[] = ['id' => $id, 'type' => 'movie', 'name' => $id, 'metadata' => []];
        }

        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls($rows, []);

        $updates = [];
        $items->method('update')->willReturnCallback(
            static function (string $id, array $data) use (&$updates): void {
                $updates[$id] = is_array($data['metadata_json'] ?? null) ? $data['metadata_json'] : [];
            }
        );

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->method('resolve')->willReturnCallback(
            /** @return array<string, mixed> */
            static function (string $title) use ($peopleById): array {
                return array_merge(
                    ['external_ids' => ['tmdb' => '603'], 'sources' => ['tmdb']],
                    $peopleById[$title] ?? []
                );
            }
        );

        $matcher = new LibraryMetadataMatcher(
            $items,
            $resolver,
            null,
            $this->createMock(StructuredLogger::class),
            null,
            null,
            null,
            null,
            null,
            null,
            $artwork,
            false,
            $policy
        );
        $matcher->matchLibrary('lib-1');

        return $updates;
    }

    /**
     * No-op contract (sibling of the poster/logo unwired guards): with storage
     * unwired, people entries keep the remote TMDB URL and gain no fields.
     */
    public function testUnwiredStorageKeepsRemoteProfileUrls(): void
    {
        $updates = $this->runMatchWithCalls(
            ['m1' => ['cast' => [self::castMember('Keanu Reeves', 'Neo', 'keanu.jpg', '287')]]],
            $this->createMock(ArtworkStorage::class),
            null
        );

        $this->assertRemoteProfileUnchanged($updates['m1']['cast'][0]);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function assertRemoteProfileUnchanged(array $entry): void
    {
        $this->assertSame('https://image.tmdb.org/t/p/w185/keanu.jpg', $entry['profile_url']);
        $this->assertArrayNotHasKey('profile_path', $entry);
    }

    /**
     * Operator gate: `artwork.download_enabled = false` must skip every person
     * fetch and leave the remote URLs in place (identical contract to posters/
     * logos, enforced at the same choke point class).
     */
    public function testPolicyDisabledSkipsEveryPersonFetch(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->with(ArtworkDownloadPolicy::SETTING_KEY)
            ->willReturn(false);
        $policy = new ArtworkDownloadPolicy($settings);

        $artwork = $this->createMock(ArtworkStorage::class);
        $artwork->expects($this->never())->method('downloadAndStore');

        $updates = $this->runMatchWithCalls(
            ['m1' => ['cast' => [self::castMember('Keanu Reeves', 'Neo', 'keanu.jpg', '287')]]],
            $artwork,
            $policy
        );

        $this->assertRemoteProfileUnchanged($updates['m1']['cast'][0]);
    }

    /**
     * Fail-soft parity with the poster path: a throwing download leaves THAT
     * person remote, logs one warning, and does not stop the next person (or
     * the persist) from completing.
     */
    public function testFailedDownloadKeepsRemoteUrlAndLogsOnce(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('person'),
                $this->callback(static fn(array $c): bool => ($c['person_key'] ?? null) === 'people-287')
            );

        $artwork = $this->createMock(ArtworkStorage::class);
        $artwork->method('downloadAndStore')
            ->willReturnCallback(
                /** @return list<string> */
                static function ($key, $path): array {
                    if ((string) $key === 'people-287') {
                        throw new \RuntimeException('download exploded');
                    }

                    return self::FIVE_VARIANTS;
                }
            );
        $artwork->method('relativePath')
            ->willReturnCallback(
                static fn($key, $size): string => '/api/v1/artwork/' . $key . '?size=' . $size
            );
        $artwork->method('url')
            ->willReturnCallback(
                /** @return string|null */
                static function ($key, $size, $path = null) {
                    return $path === null ? null : $path . '&exp=2000000000&sig=deadbeef';
                }
            );

        $rows = [
            ['id' => 'm1', 'type' => 'movie', 'name' => 'm1', 'metadata' => []],
        ];
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls($rows, []);
        $updates = [];
        $items->method('update')->willReturnCallback(
            static function (string $id, array $data) use (&$updates): void {
                $updates[$id] = is_array($data['metadata_json'] ?? null) ? $data['metadata_json'] : [];
            }
        );
        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->method('resolve')->willReturn([
            'external_ids' => ['tmdb' => '603'],
            'sources' => ['tmdb'],
            'cast' => [
                self::castMember('Keanu Reeves', 'Neo', 'keanu.jpg', '287'),
                self::castMember('Carrie-Anne Moss', 'Trinity', 'carrie.jpg', '288'),
            ],
        ]);

        $matcher = new LibraryMetadataMatcher(
            $items,
            $resolver,
            null,
            $logger,
            null,
            null,
            null,
            null,
            null,
            null,
            $artwork,
            false,
            null
        );
        $matcher->matchLibrary('lib-1');

        // The failed person stays remote; the healthy one in the SAME item is
        // still localized — one bad download never aborts the group.
        $this->assertSame('https://image.tmdb.org/t/p/w185/keanu.jpg', $updates['m1']['cast'][0]['profile_url']);
        $this->assertArrayNotHasKey('profile_path', $updates['m1']['cast'][0]);
        $this->assertSame(self::SIGNED_288, $updates['m1']['cast'][1]['profile_url']);
    }

    /**
     * An empty variant list (download returned nothing usable) leaves the entry
     * remote — no half-localized metadata, no log spam.
     */
    public function testEmptyVariantListKeepsRemoteUrl(): void
    {
        $artwork = $this->createMock(ArtworkStorage::class);
        $artwork->method('downloadAndStore')->willReturn([]);

        $updates = $this->runMatchWithCalls(
            ['m1' => ['cast' => [self::castMember('Keanu Reeves', 'Neo', 'keanu.jpg', '287')]]],
            $artwork
        );

        $this->assertSame('https://image.tmdb.org/t/p/w185/keanu.jpg', $updates['m1']['cast'][0]['profile_url']);
        $this->assertArrayNotHasKey('profile_path', $updates['m1']['cast'][0]);
    }

    /**
     * Only TMDB-anchored profile URLs localize; a provider URL from anywhere
     * else passes through untouched and no fetch is attempted.
     */
    public function testNonTmdbProfileUrlIsNeverFetched(): void
    {
        $artwork = $this->createMock(ArtworkStorage::class);
        $artwork->expects($this->never())->method('downloadAndStore');

        $updates = $this->runMatchWithCalls(
            ['m1' => ['cast' => [[
                'name' => 'Someone',
                'role' => 'Guest',
                'profile_url' => 'https://thetvdb.com/banners/actors/123.jpg',
                'id' => '999',
            ]]]],
            $artwork
        );

        $this->assertSame(
            'https://thetvdb.com/banners/actors/123.jpg',
            $updates['m1']['cast'][0]['profile_url']
        );
        $this->assertArrayNotHasKey('profile_path', $updates['m1']['cast'][0]);
    }

    /**
     * Missing person id ⇒ deterministic FLAT content-hash key (the plan's
     * sanctioned alternative), never the media-item id and never skipped: the
     * same anonymous path across two items still downloads exactly once.
     */
    public function testMissingPersonIdFallsBackToSharedContentHashKey(): void
    {
        $calls = [];
        $artwork = $this->createMock(ArtworkStorage::class);
        $artwork->method('downloadAndStore')
            ->willReturnCallback(
                /** @return list<string> */
                static function ($key, $path) use (&$calls): array {
                    $calls[] = [(string) $key, (string) $path];

                    return self::FIVE_VARIANTS;
                }
            );
        $artwork->method('relativePath')
            ->willReturnCallback(
                static fn($key, $size): string => '/api/v1/artwork/' . $key . '?size=' . $size
            );
        $artwork->method('url')
            ->willReturnCallback(
                /** @return string|null */
                static function ($key, $size, $path = null) {
                    return $path === null ? null : $path . '&exp=2000000000&sig=deadbeef';
                }
            );

        $anon = self::castMember('Extras Actor', 'Townsperson', 'anon.jpg', null);
        $updates = $this->runMatchWithCalls(
            ['m1' => ['cast' => [$anon]], 'm2' => ['cast' => [$anon]]],
            $artwork
        );

        $expectedKey = 'people-' . sha1('/anon.jpg');
        $this->assertSame([[$expectedKey, '/anon.jpg']], $calls);
        $this->assertMatchesRegularExpression('/^people-[a-f0-9]{40}$/', $expectedKey);
        $this->assertSame(
            '/api/v1/artwork/' . $expectedKey . '?size=w185&exp=2000000000&sig=deadbeef',
            $updates['m2']['cast'][0]['profile_url']
        );
    }

    /**
     * Client-boundary contract: `id` and `profile_path` are metadata-internal.
     * The detail shape whitelists {name, role|job, profile_url} for cast AND
     * crew, so the S72 plumbing can never change any client payload.
     */
    public function testPersonIdAndProfilePathNeverReachTheClientShape(): void
    {
        $castEntry = self::castMember('Keanu Reeves', 'Neo', 'keanu.jpg', '287');
        $castEntry['profile_path'] = '/keanu.jpg';
        $crewEntry = self::crewMember('Keanu Reeves', 'Producer', 'keanu.jpg', '287');
        $crewEntry['profile_path'] = '/keanu.jpg';

        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'M',
            'type' => 'movie',
            'metadata' => ['cast' => [$castEntry], 'crew' => [$crewEntry]],
        ], []);

        $this->assertSame(['name', 'role', 'profile_url'], array_keys($shaped['cast'][0]));
        $this->assertSame(['name', 'job', 'profile_url'], array_keys($shaped['crew'][0]));
    }
}
