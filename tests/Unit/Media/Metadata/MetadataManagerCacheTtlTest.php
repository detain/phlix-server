<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\MetadataCachePolicy;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Media\Metadata\MetadataProviderInterface;

/**
 * W3 — the `metadata.cache_ttl_hours` effective read AT THE MANAGER: the
 * per-provider skip window in {@see MetadataManager::hasRecentMetadata()} was
 * a hardcoded `< 86400`; it now delegates the timestamp comparison to
 * {@see MetadataCachePolicy}. These tests pin the CONSEQUENCE (does
 * `search()` run or not) across the default, shortened and lengthened windows
 * — not just the policy plumbing.
 *
 * Boundary MARGIN note: the exact ttl±1s edges are pinned at the policy level
 * with an INJECTED clock (MetadataCachePolicyTest — no wall-clock race). Here
 * the manager reads real `time()` internally, so ages use ±100s margins —
 * still squarely inside/outside every window under test.
 */
final class MetadataManagerCacheTtlTest extends TestCase
{
    /**
     * Format an age in seconds as an EXPLICIT-UTC `metadata_refreshed_at`
     * string (the '+0000' suffix keeps strtotime() from re-reading the wall
     * time through the runtime TZ, same reason as in MetadataCachePolicyTest).
     */
    private function ageStamp(int $ageSeconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - $ageSeconds) . ' +0000';
    }

    /**
     * @return array{MetadataManager, MetadataProviderInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function managerWithStoredTmdb(array $metadataPayload, ?MetadataCachePolicy $policy = null): array
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'The Matrix',
            'metadata_json' => json_encode($metadataPayload),
        ]);

        $provider = $this->createMock(MetadataProviderInterface::class);

        $manager = new MetadataManager($items, null, null, null, $policy);
        $manager->registerProvider('tmdb', $provider, ['movie']);

        return [$manager, $provider];
    }

    /** A policy fixed to one TTL, built over a mocked store as the DI does. */
    private function ttlPolicy(int $hours): MetadataCachePolicy
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->with(MetadataCachePolicy::SETTING_KEY)
            ->willReturn($hours);

        return new MetadataCachePolicy($settings);
    }

    // ------------------------------------------------------------------
    // Behaviour preservation at the shipped default
    // ------------------------------------------------------------------

    public function test_legacy_construction_skips_search_inside_the_historical_24h_window(): void
    {
        // THE behavior-preservation pin: with NO policy injected (legacy call
        // sites, store-less DI), an item refreshed 23h58m ago still SKIPS the
        // re-fetch exactly as the old hardcoded 86400 did.
        [$manager, $provider] = $this->managerWithStoredTmdb([
            'details' => ['tmdb' => ['id' => '603']],
            'metadata_refreshed_at' => $this->ageStamp(85_000),
        ]);

        $provider->expects($this->never())->method('search');
        $this->assertTrue($manager->refreshItemMetadata('m1'));
    }

    public function test_legacy_construction_refetches_outside_the_historical_24h_window(): void
    {
        [$manager, $provider] = $this->managerWithStoredTmdb([
            'details' => ['tmdb' => ['id' => '603']],
            'metadata_refreshed_at' => $this->ageStamp(87_800),
        ]);

        $provider->expects($this->once())->method('search')->willReturn([]);
        $this->assertFalse($manager->refreshItemMetadata('m1'));
    }

    public function test_no_stored_details_for_the_provider_always_refetches(): void
    {
        // Pre-existing semantics pinned: the freshness window only matters to
        // items that HAVE per-provider details stored.
        [$manager, $provider] = $this->managerWithStoredTmdb([
            'details' => ['fanart' => []],
            'metadata_refreshed_at' => $this->ageStamp(10),
        ]);

        $provider->expects($this->once())->method('search')->willReturn([]);
        $this->assertFalse($manager->refreshItemMetadata('m1'));
    }

    public function test_missing_or_unparseable_refresh_stamp_never_counts_fresh(): void
    {
        [$manager, $provider] = $this->managerWithStoredTmdb([
            'details' => ['tmdb' => ['id' => '603']],
            // NO metadata_refreshed_at key at all.
        ]);

        $provider->expects($this->once())->method('search')->willReturn([]);
        $this->assertFalse($manager->refreshItemMetadata('m1'));
    }

    public function test_force_bypasses_the_window_regardless_of_ttl(): void
    {
        [$manager, $provider] = $this->managerWithStoredTmdb(
            [
                'details' => ['tmdb' => ['id' => '603']],
                'metadata_refreshed_at' => $this->ageStamp(5),
            ],
            $this->ttlPolicy(8760),
        );

        $provider->expects($this->once())->method('search')->willReturn([]);
        $this->assertFalse($manager->refreshItemMetadata('m1', true));
    }

    // ------------------------------------------------------------------
    // Live overrides — shorter and longer than the historical window
    // ------------------------------------------------------------------

    public function test_a_shortened_ttl_takes_effect_on_the_very_next_check(): void
    {
        // 2h TTL: 24h01m-old data (which the OLD code would still have skipped)
        // must now be REFRESHED — the consequence-side proof of the live read.
        [$manager, $provider] = $this->managerWithStoredTmdb(
            [
                'details' => ['tmdb' => ['id' => '603']],
                'metadata_refreshed_at' => $this->ageStamp(87_800),
            ],
            $this->ttlPolicy(2),
        );

        $provider->expects($this->once())->method('search')->willReturn([]);
        $this->assertFalse($manager->refreshItemMetadata('m1'));
    }

    public function test_a_shortened_ttl_still_skips_inside_its_own_window(): void
    {
        [$manager, $provider] = $this->managerWithStoredTmdb(
            [
                'details' => ['tmdb' => ['id' => '603']],
                'metadata_refreshed_at' => $this->ageStamp(7_100),
            ],
            $this->ttlPolicy(2),
        );

        $provider->expects($this->never())->method('search');
        $this->assertTrue($manager->refreshItemMetadata('m1'));
    }

    public function test_a_lengthened_ttl_suppresses_the_refetch_the_old_code_would_have_done(): void
    {
        // 48h TTL: 24h01m-old data (STALE under the old constant) is now fresh.
        [$manager, $provider] = $this->managerWithStoredTmdb(
            [
                'details' => ['tmdb' => ['id' => '603']],
                'metadata_refreshed_at' => $this->ageStamp(87_800),
            ],
            $this->ttlPolicy(48),
        );

        $provider->expects($this->never())->method('search');
        $this->assertTrue($manager->refreshItemMetadata('m1'));
    }

    public function test_the_ttl_is_consulted_at_every_refresh_never_frozen(): void
    {
        // Resident-process law at the CONSUMER: two refreshes must read the
        // store twice, and a flip between them must change the outcome.
        $reads = 0;
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->willReturnCallback(function (string $key) use (&$reads): int {
                $reads++;

                return $reads === 1 ? 48 : 1;
            });
        $policy = new MetadataCachePolicy($settings);

        [$manager, $provider] = $this->managerWithStoredTmdb(
            [
                'details' => ['tmdb' => ['id' => '603']],
                'metadata_refreshed_at' => $this->ageStamp(87_800),
            ],
            $policy,
        );
        $provider->method('search')->willReturn([]);

        $this->assertTrue($manager->refreshItemMetadata('m1'), 'first check: 48h window → fresh');
        $this->assertFalse($manager->refreshItemMetadata('m1'), 'second check: flipped to 1h → stale');
        $this->assertSame(2, $reads);
    }
}
