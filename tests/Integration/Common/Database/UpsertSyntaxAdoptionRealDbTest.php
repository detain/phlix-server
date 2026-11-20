<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Common\Database;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phlix\Tests\Support\Database\UsesScratchDatabase;

/**
 * W2-UPSERT effect parity: every converted statement shape, executed as REAL
 * SQL against MySQL 8.0, must leave the identical row state whether it is
 * written in the legacy `VALUES(col)` form or the adopted `AS new_row`
 * row-alias form — for BOTH the inserting branch (key absent) and the
 * updating branch (key present, including the no-op PK-identity leg and the
 * multi-row same-key last-wins case).
 *
 * The byte law (the MariaDB rendering of an alias statement reproduces the
 * legacy text exactly) is pinned statically by
 * {@see \Phlix\Tests\Unit\Common\Database\UpsertSyntaxAdoptionTest}; this
 * file is the semantic companion: on the modern engine the alias text is
 * what goes over the wire, so the alias/legacy grammar pair must be
 * effect-equivalent on the real server, not just string-similar.
 *
 * Method per shape: DELETE, seed with the legacy statement (binds OLD),
 * then run each arm (binds NEW), snapshot the deterministic columns and the
 * driver-visible return value, and require the two arms to match exactly.
 * Clock-driven legs (`updated_at = NOW()`, `CURRENT_TIMESTAMP`) are excluded
 * from the probe by column list — they are leg-identical text in both forms
 * (the kernel never rewrites a leg that carries no VALUES()/new_row token)
 * and comparing them would only compare wall-clock reads.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */
#[Group('integration')]
final class UpsertSyntaxAdoptionRealDbTest extends TestCase
{
    use UsesScratchDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->openScratchDatabase(
            'Runs against a real MySQL server (the W2-UPSERT effect-parity venue).',
            'upsv'
        );

        $this->db->query('CREATE TABLE server_settings (id CHAR(36) PRIMARY KEY, setting_key VARCHAR(255) NOT NULL UNIQUE, setting_value TEXT, value_type VARCHAR(32) NOT NULL) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE collection_items (collection_id CHAR(36) NOT NULL, media_item_id CHAR(36) NOT NULL, sort_order INT NOT NULL, added_at DATETIME NOT NULL, PRIMARY KEY (collection_id, media_item_id)) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE playback_state (id CHAR(36) PRIMARY KEY, session_id CHAR(36) NOT NULL, media_item_id CHAR(36) NOT NULL, position_ticks BIGINT NOT NULL, duration_ticks BIGINT NOT NULL, playback_status VARCHAR(32) NOT NULL, updated_at DATETIME NULL) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE syncplay_snapshots (group_id VARCHAR(64) PRIMARY KEY, group_name VARCHAR(255) NOT NULL, member_count INT NOT NULL, has_password TINYINT(1) NOT NULL, current_media_id CHAR(36) NULL, is_playing TINYINT(1) NOT NULL, playback_position BIGINT NOT NULL, playback_state VARCHAR(32) NOT NULL, serialized_state TEXT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE client_heartbeats (instance_id CHAR(36) PRIMARY KEY, version VARCHAR(32) NOT NULL, client_type VARCHAR(32) NOT NULL, build_token VARCHAR(64) NOT NULL, first_seen_at DATETIME NULL, last_seen_at DATETIME NULL) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE metrics_connections (connection_id CHAR(36) PRIMARY KEY, worker_id VARCHAR(64) NOT NULL, kind VARCHAR(32) NOT NULL, user_id CHAR(36) NULL, remote_ip VARCHAR(64) NULL, session_id CHAR(36) NULL, media_item_id CHAR(36) NULL, bytes_in BIGINT NOT NULL, bytes_out BIGINT NOT NULL, bytes_in_rate BIGINT NOT NULL, bytes_out_rate BIGINT NOT NULL, opened_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE theme_media (library_id CHAR(36) PRIMARY KEY, audio_path VARCHAR(255) NULL, audio_url VARCHAR(255) NULL, audio_duration INT NULL, audio_format VARCHAR(32) NULL, video_path VARCHAR(255) NULL, video_url VARCHAR(255) NULL, video_duration INT NULL, video_width INT NULL, video_height INT NULL, video_format VARCHAR(32) NULL, scanned_at DATETIME NOT NULL) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE livetv_timeshift_sessions (id CHAR(36) PRIMARY KEY, session_id CHAR(36) NOT NULL, channel_id CHAR(36) NOT NULL, buffer_dir VARCHAR(255) NOT NULL, pid INT NOT NULL, buffer_start_at DATETIME NOT NULL, buffer_end_at DATETIME NOT NULL, window_seconds INT NOT NULL, cursor_position BIGINT NOT NULL, status VARCHAR(32) NOT NULL, updated_at DATETIME NULL) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE media_markers (media_item_id CHAR(36) PRIMARY KEY, marker_type VARCHAR(32) NOT NULL, start_time_ms BIGINT NOT NULL, end_time_ms BIGINT NOT NULL, label VARCHAR(255) NOT NULL, user_id CHAR(36) NOT NULL, thumbnail_path VARCHAR(255) NULL) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE user_settings (user_id CHAR(36) PRIMARY KEY, max_streams INT NULL, preferred_audio_language VARCHAR(16) NULL) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        $this->cleanupScratchDatabase();
        parent::tearDown();
    }

    public function testServerSettingsUpsertArmsAreEffectEquivalent(): void
    {
        $legacy = "INSERT INTO server_settings (id, setting_key, setting_value, value_type) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type)";
        $alias = "INSERT INTO server_settings (id, setting_key, setting_value, value_type) VALUES (?, ?, ?, ?)  AS new_row ON DUPLICATE KEY UPDATE setting_value = new_row.setting_value, value_type = new_row.value_type";

        $this->assertArmsEquivalent(
            'server_settings',
            'id, setting_key, setting_value, value_type',
            $legacy,
            $alias,
            ['row-1', 'theme', 'midnight', 'string'],
            ['row-1', 'theme', 'nocturne', 'string'],
        );
    }

    public function testCollectionItemReAddNoOpLegIsEffectEquivalent(): void
    {
        $legacy = "INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)\n             VALUES (?, ?, ?, ?)\n             ON DUPLICATE KEY UPDATE media_item_id = VALUES(media_item_id)";
        $alias = "INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)\n             VALUES (?, ?, ?, ?)\n              AS new_row\n             ON DUPLICATE KEY UPDATE media_item_id = new_row.media_item_id";

        $this->assertArmsEquivalent(
            'collection_items',
            'collection_id, media_item_id, sort_order, added_at',
            $legacy,
            $alias,
            ['col-1', 'item-1', 5, '2026-01-01 00:00:00'],
            ['col-1', 'item-1', 9, '2026-02-02 00:00:00'],
        );
    }

    public function testCollectionItemBatchUpsertIsEffectEquivalent(): void
    {
        $legacy = "INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)\n             VALUES (?, ?, ?, ?), (?, ?, ?, ?)\n             ON DUPLICATE KEY UPDATE media_item_id = VALUES(media_item_id)";
        $alias = "INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)\n             VALUES (?, ?, ?, ?), (?, ?, ?, ?)\n              AS new_row\n             ON DUPLICATE KEY UPDATE media_item_id = new_row.media_item_id";

        $this->assertArmsEquivalent(
            'collection_items',
            'collection_id, media_item_id, sort_order, added_at',
            $legacy,
            $alias,
            ['col-1', 'item-1', 1, '2026-01-01 00:00:00', 'col-1', 'item-2', 2, '2026-01-01 00:00:00'],
            ['col-1', 'item-1', 10, '2026-02-02 00:00:00', 'col-1', 'item-2', 20, '2026-02-02 00:00:00'],
        );
    }

    public function testCollectionItemBatchSameKeyLastWinsIsEffectEquivalent(): void
    {
        $legacy = "INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)\n             VALUES (?, ?, ?, ?), (?, ?, ?, ?)\n             ON DUPLICATE KEY UPDATE media_item_id = VALUES(media_item_id)";
        $alias = "INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)\n             VALUES (?, ?, ?, ?), (?, ?, ?, ?)\n              AS new_row\n             ON DUPLICATE KEY UPDATE media_item_id = new_row.media_item_id";

        $this->assertArmsEquivalent(
            'collection_items',
            'collection_id, media_item_id, sort_order, added_at',
            $legacy,
            $alias,
            ['col-2', 'item-1', 1, '2026-01-01 00:00:00', 'col-2', 'item-1', 2, '2026-01-01 00:00:00'],
            ['col-2', 'item-1', 30, '2026-02-02 00:00:00', 'col-2', 'item-1', 40, '2026-02-02 00:00:00'],
        );
    }

    public function testPlaybackStateUpsertWithNowLegIsEffectEquivalent(): void
    {
        $legacy = "INSERT INTO playback_state (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)\n             VALUES (?, ?, ?, ?, ?, ?)\n             ON DUPLICATE KEY UPDATE\n                position_ticks = VALUES(position_ticks),\n                duration_ticks = VALUES(duration_ticks),\n                playback_status = VALUES(playback_status),\n                updated_at = NOW()";
        $alias = "INSERT INTO playback_state (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)\n             VALUES (?, ?, ?, ?, ?, ?)\n              AS new_row\n             ON DUPLICATE KEY UPDATE\n                position_ticks = new_row.position_ticks,\n                duration_ticks = new_row.duration_ticks,\n                playback_status = new_row.playback_status,\n                updated_at = NOW()";

        $this->assertArmsEquivalent(
            'playback_state',
            'id, session_id, media_item_id, position_ticks, duration_ticks, playback_status',
            $legacy,
            $alias,
            ['pb-1', 'sess-1', 'item-1', 100, 9000, 'playing'],
            ['pb-1', 'sess-1', 'item-1', 4500, 9000, 'paused'],
        );
    }

    public function testSyncPlaySnapshotUpsertIsEffectEquivalent(): void
    {
        $legacy = "INSERT INTO syncplay_snapshots\n                (group_id, group_name, member_count, has_password, current_media_id,\n                 is_playing, playback_position, playback_state, serialized_state, updated_at)\n             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n             ON DUPLICATE KEY UPDATE\n                group_name = VALUES(group_name),\n                member_count = VALUES(member_count),\n                has_password = VALUES(has_password),\n                current_media_id = VALUES(current_media_id),\n                is_playing = VALUES(is_playing),\n                playback_position = VALUES(playback_position),\n                playback_state = VALUES(playback_state),\n                serialized_state = VALUES(serialized_state),\n                updated_at = VALUES(updated_at)";
        $alias = "INSERT INTO syncplay_snapshots\n                (group_id, group_name, member_count, has_password, current_media_id,\n                 is_playing, playback_position, playback_state, serialized_state, updated_at)\n             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n              AS new_row\n             ON DUPLICATE KEY UPDATE\n                group_name = new_row.group_name,\n                member_count = new_row.member_count,\n                has_password = new_row.has_password,\n                current_media_id = new_row.current_media_id,\n                is_playing = new_row.is_playing,\n                playback_position = new_row.playback_position,\n                playback_state = new_row.playback_state,\n                serialized_state = new_row.serialized_state,\n                updated_at = new_row.updated_at";

        $this->assertArmsEquivalent(
            'syncplay_snapshots',
            'group_id, group_name, member_count, has_password, current_media_id, is_playing, playback_position, playback_state, serialized_state, updated_at',
            $legacy,
            $alias,
            ['grp-1', 'Night', 2, 0, 'item-1', 1, 1000, 'playing', 'a:0:{}', '2026-01-01 00:00:00'],
            ['grp-1', 'Late Night', 3, 1, 'item-2', 0, 5000, 'paused', 'a:1:{}', '2026-02-02 00:00:00'],
        );
    }

    public function testClientHeartbeatUpsertWithNowInsertLegsIsEffectEquivalent(): void
    {
        $legacy = "INSERT INTO client_heartbeats (instance_id, version, client_type, build_token, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE version = VALUES(version), client_type = VALUES(client_type), build_token = VALUES(build_token), last_seen_at = NOW()";
        $alias = "INSERT INTO client_heartbeats (instance_id, version, client_type, build_token, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, NOW(), NOW())  AS new_row ON DUPLICATE KEY UPDATE version = new_row.version, client_type = new_row.client_type, build_token = new_row.build_token, last_seen_at = NOW()";

        $this->assertArmsEquivalent(
            'client_heartbeats',
            'instance_id, version, client_type, build_token',
            $legacy,
            $alias,
            ['hb-1', '1.0.0', 'web', 'tok-a'],
            ['hb-1', '1.2.0', 'desktop', 'tok-b'],
        );
    }

    public function testMetricsConnectionsAlignedUpsertIsEffectEquivalent(): void
    {
        $legacy = "INSERT INTO metrics_connections\n                 (connection_id, worker_id, kind, user_id, remote_ip, session_id,\n                  media_item_id, bytes_in, bytes_out, bytes_in_rate, bytes_out_rate,\n                  opened_at, last_seen_at)\n                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n                 ON DUPLICATE KEY UPDATE\n                     kind           = VALUES(kind),\n                     user_id        = VALUES(user_id),\n                     remote_ip      = VALUES(remote_ip),\n                     session_id     = VALUES(session_id),\n                     media_item_id  = VALUES(media_item_id),\n                     bytes_in       = VALUES(bytes_in),\n                     bytes_out      = VALUES(bytes_out),\n                     bytes_in_rate  = VALUES(bytes_in_rate),\n                     bytes_out_rate = VALUES(bytes_out_rate),\n                     last_seen_at   = VALUES(last_seen_at)";
        $alias = "INSERT INTO metrics_connections\n                 (connection_id, worker_id, kind, user_id, remote_ip, session_id,\n                  media_item_id, bytes_in, bytes_out, bytes_in_rate, bytes_out_rate,\n                  opened_at, last_seen_at)\n                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n                  AS new_row\n                 ON DUPLICATE KEY UPDATE\n                     kind           = new_row.kind,\n                     user_id        = new_row.user_id,\n                     remote_ip      = new_row.remote_ip,\n                     session_id     = new_row.session_id,\n                     media_item_id  = new_row.media_item_id,\n                     bytes_in       = new_row.bytes_in,\n                     bytes_out      = new_row.bytes_out,\n                     bytes_in_rate  = new_row.bytes_in_rate,\n                     bytes_out_rate = new_row.bytes_out_rate,\n                     last_seen_at   = new_row.last_seen_at";

        $this->assertArmsEquivalent(
            'metrics_connections',
            'connection_id, worker_id, kind, user_id, remote_ip, session_id, media_item_id, bytes_in, bytes_out, bytes_in_rate, bytes_out_rate, opened_at, last_seen_at',
            $legacy,
            $alias,
            ['conn-1', 'w1', 'web', null, '10.0.0.1', null, null, 10, 20, 1, 2, '2026-01-01 00:00:00', '2026-01-01 00:00:00'],
            ['conn-1', 'w1', 'ws', 'usr-1', '10.0.0.2', 'sess-1', 'item-1', 300, 400, 30, 40, '2026-01-01 00:00:00', '2026-02-02 00:00:00'],
        );
    }

    public function testThemeMediaUpsertIsEffectEquivalent(): void
    {
        $legacy = "INSERT INTO theme_media (\n                library_id,\n                audio_path,\n                audio_url,\n                audio_duration,\n                audio_format,\n                video_path,\n                video_url,\n                video_duration,\n                video_width,\n                video_height,\n                video_format,\n                scanned_at\n            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n            ON DUPLICATE KEY UPDATE\n                audio_path = VALUES(audio_path),\n                audio_url = VALUES(audio_url),\n                audio_duration = VALUES(audio_duration),\n                audio_format = VALUES(audio_format),\n                video_path = VALUES(video_path),\n                video_url = VALUES(video_url),\n                video_duration = VALUES(video_duration),\n                video_width = VALUES(video_width),\n                video_height = VALUES(video_height),\n                video_format = VALUES(video_format),\n                scanned_at = VALUES(scanned_at)";
        $alias = "INSERT INTO theme_media (\n                library_id,\n                audio_path,\n                audio_url,\n                audio_duration,\n                audio_format,\n                video_path,\n                video_url,\n                video_duration,\n                video_width,\n                video_height,\n                video_format,\n                scanned_at\n            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n             AS new_row\n            ON DUPLICATE KEY UPDATE\n                audio_path = new_row.audio_path,\n                audio_url = new_row.audio_url,\n                audio_duration = new_row.audio_duration,\n                audio_format = new_row.audio_format,\n                video_path = new_row.video_path,\n                video_url = new_row.video_url,\n                video_duration = new_row.video_duration,\n                video_width = new_row.video_width,\n                video_height = new_row.video_height,\n                video_format = new_row.video_format,\n                scanned_at = new_row.scanned_at";

        $this->assertArmsEquivalent(
            'theme_media',
            'library_id, audio_path, audio_url, audio_duration, audio_format, video_path, video_url, video_duration, video_width, video_height, video_format, scanned_at',
            $legacy,
            $alias,
            ['lib-1', '/a1', '/u1', 100, 'mp3', '/v1', '/vu1', 200, 640, 480, 'mp4', '2026-01-01 00:00:00'],
            ['lib-1', '/a2', '/u2', 101, 'ogg', '/v2', '/vu2', 201, 1280, 720, 'webm', '2026-02-02 00:00:00'],
        );
    }

    public function testTimeShiftSessionUpsertWithCurrentTimestampLegIsEffectEquivalent(): void
    {
        $legacy = "INSERT INTO livetv_timeshift_sessions\n                (id, session_id, channel_id, buffer_dir, pid,\n                 buffer_start_at, buffer_end_at, window_seconds,\n                 cursor_position, status)\n             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n             ON DUPLICATE KEY UPDATE\n                channel_id = VALUES(channel_id),\n                buffer_dir = VALUES(buffer_dir),\n                pid = VALUES(pid),\n                buffer_start_at = VALUES(buffer_start_at),\n                buffer_end_at = VALUES(buffer_end_at),\n                window_seconds = VALUES(window_seconds),\n                cursor_position = VALUES(cursor_position),\n                status = VALUES(status),\n                updated_at = CURRENT_TIMESTAMP";
        $alias = "INSERT INTO livetv_timeshift_sessions\n                (id, session_id, channel_id, buffer_dir, pid,\n                 buffer_start_at, buffer_end_at, window_seconds,\n                 cursor_position, status)\n             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n              AS new_row\n             ON DUPLICATE KEY UPDATE\n                channel_id = new_row.channel_id,\n                buffer_dir = new_row.buffer_dir,\n                pid = new_row.pid,\n                buffer_start_at = new_row.buffer_start_at,\n                buffer_end_at = new_row.buffer_end_at,\n                window_seconds = new_row.window_seconds,\n                cursor_position = new_row.cursor_position,\n                status = new_row.status,\n                updated_at = CURRENT_TIMESTAMP";

        $this->assertArmsEquivalent(
            'livetv_timeshift_sessions',
            'id, session_id, channel_id, buffer_dir, pid, buffer_start_at, buffer_end_at, window_seconds, cursor_position, status',
            $legacy,
            $alias,
            ['ts-1', 'sess-1', 'ch-1', '/tmp/b1', 100, '2026-01-01 00:00:00', '2026-01-01 01:00:00', 3600, 10, 'active'],
            ['ts-1', 'sess-2', 'ch-2', '/tmp/b2', 200, '2026-02-01 00:00:00', '2026-02-01 01:00:00', 7200, 999, 'paused'],
        );
    }

    public function testMediaMarkerUpsertIsEffectEquivalent(): void
    {
        $legacy = "INSERT INTO media_markers\n             (media_item_id, marker_type, start_time_ms, end_time_ms, label, user_id, thumbnail_path)\n             VALUES (?, ?, ?, ?, ?, ?, ?)\n             ON DUPLICATE KEY UPDATE\n               marker_type = VALUES(marker_type),\n               start_time_ms = VALUES(start_time_ms),\n               end_time_ms = VALUES(end_time_ms),\n               label = VALUES(label),\n               user_id = VALUES(user_id),\n               thumbnail_path = VALUES(thumbnail_path)";
        $alias = "INSERT INTO media_markers\n             (media_item_id, marker_type, start_time_ms, end_time_ms, label, user_id, thumbnail_path)\n             VALUES (?, ?, ?, ?, ?, ?, ?)\n              AS new_row\n             ON DUPLICATE KEY UPDATE\n               marker_type = new_row.marker_type,\n               start_time_ms = new_row.start_time_ms,\n               end_time_ms = new_row.end_time_ms,\n               label = new_row.label,\n               user_id = new_row.user_id,\n               thumbnail_path = new_row.thumbnail_path";

        $this->assertArmsEquivalent(
            'media_markers',
            'media_item_id, marker_type, start_time_ms, end_time_ms, label, user_id, thumbnail_path',
            $legacy,
            $alias,
            ['mk-1', 'chapter', 0, 1000, 'Intro', 'usr-1', null],
            ['mk-1', 'credit', 5000, 6000, 'Credits', 'usr-2', '/t/1.png'],
        );
    }

    public function testDynamicBuilderSingleLegUpsertIsEffectEquivalent(): void
    {
        $legacy = "INSERT INTO user_settings (user_id, max_streams) VALUES (?, ?) ON DUPLICATE KEY UPDATE max_streams = VALUES(max_streams)";
        $alias = "INSERT INTO user_settings (user_id, max_streams) VALUES (?, ?) AS new_row ON DUPLICATE KEY UPDATE max_streams = new_row.max_streams";

        $this->assertArmsEquivalent(
            'user_settings',
            'user_id, max_streams, preferred_audio_language',
            $legacy,
            $alias,
            ['usr-1', 2],
            ['usr-1', 7],
        );
    }

    public function testDynamicBuilderTwoLegUpsertIsEffectEquivalent(): void
    {
        $legacy = "INSERT INTO user_settings (user_id, max_streams, preferred_audio_language) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE max_streams = VALUES(max_streams), preferred_audio_language = VALUES(preferred_audio_language)";
        $alias = "INSERT INTO user_settings (user_id, max_streams, preferred_audio_language) VALUES (?, ?, ?) AS new_row ON DUPLICATE KEY UPDATE max_streams = new_row.max_streams, preferred_audio_language = new_row.preferred_audio_language";

        $this->assertArmsEquivalent(
            'user_settings',
            'user_id, max_streams, preferred_audio_language',
            $legacy,
            $alias,
            ['usr-2', 1, 'en'],
            ['usr-2', 4, 'de'],
        );
    }

    /**
     * Seed one arm from empty, run the updating statement, snapshot rows and
     * the driver-visible return; repeat for the other arm; require equality.
     *
     * @param string      $table     Scratch table under test.
     * @param string      $probeCols Deterministic columns for the snapshot SELECT.
     * @param string      $legacy    Pre-wave `VALUES(col)` statement text.
     * @param string      $alias     Adopted `AS new_row` statement text.
     * @param list<mixed> $bindsOld  Statement binds for the seeding run.
     * @param list<mixed> $bindsNew  Statement binds for the arm runs.
     */
    private function assertArmsEquivalent(
        string $table,
        string $probeCols,
        string $legacy,
        string $alias,
        array $bindsOld,
        array $bindsNew,
    ): void {
        [$rowsL, $retL] = $this->runArm($legacy, $legacy, $bindsOld, $bindsNew, $table, $probeCols);
        [$rowsA, $retA] = $this->runArm($legacy, $alias, $bindsOld, $bindsNew, $table, $probeCols);
        [$rowsF, $retF] = $this->runArm($alias, $alias, $bindsOld, $bindsNew, $table, $probeCols);

        self::assertSame($rowsL, $rowsA, "update-arm row-state divergence on {$table}");
        self::assertSame($rowsL, $rowsF, "insert+update full-alias row-state divergence on {$table}");

        self::assertSame($this->normalizeReturn($retL), $this->normalizeReturn($retA), "driver-visible return divergence on {$table}");
        self::assertSame($this->normalizeReturn($retL), $this->normalizeReturn($retF), "full-alias return divergence on {$table}");
    }

    /**
     * @param list<mixed> $bindsOld
     * @param list<mixed> $bindsNew
     *
     * @return array{0: list<array<string, mixed>>, 1: mixed}
     */
    private function runArm(string $seedSql, string $sql, array $bindsOld, array $bindsNew, string $table, string $probeCols): array
    {
        $this->db->query('DELETE FROM ' . $table);
        $this->db->query($seedSql, $bindsOld);

        $ret = $this->db->query($sql, $bindsNew);
        $rows = $this->db->query("SELECT {$probeCols} FROM {$table} ORDER BY 1");

        return [$rows, $ret];
    }

    /**
     * lastInsertId()/0-vs-null is the only thing the wrapper exposes for an
     * INSERT-class statement; autoincrement noise must not mask divergence,
     * so returns are compared by kind + null-ness.
     */
    private function normalizeReturn(mixed $ret): string
    {
        if (is_array($ret)) {
            return 'array:' . count($ret);
        }

        if ($ret === null) {
            return 'null';
        }

        return 'scalar:' . gettype($ret);
    }
}
