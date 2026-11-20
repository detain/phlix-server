<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phlix\Shared\Database\UpsertSyntax;

/**
 * W2-UPSERT adoption differential harness for the shared UpsertSyntax kernel.
 *
 * The server's legacy upsert sites were mechanically converted to the
 * row-alias form (`AS new_row` + `new_row.col`) behind `UpsertSyntax::adapt()`.
 * Adoption law: the wire bytes MUST be flavor-identical — the legacy arm
 * (MariaDB / MySQL < 8.0.19 rendering) reproduces the PRE-WAVE statement
 * byte-for-byte, and the modern arm (MySQL >= 8.0.19) passes the alias form
 * through untouched. This file is the permanent record of that law.
 *
 * Corpus provenance: every (legacy, alias) pair in self::CORPUS was extracted
 * by the wave's differential harness from the real `src/` statement text at
 * tip ccfc3b82 (34 sites token-scanned from their concatenation chains, plus
 * two sampled renderings of the dynamic `UserRepository::updateSettings()`
 * builder). The adoption wave's final commit adds a fidelity tripwire pinning
 * each pair to its site's live fragment bytes, so this table cannot silently
 * drift, alongside the token census that the conversion wave keeps honest.
 *
 * Method ported from the hub precedent (phlix-hub 62efb787-era
 * UpsertSyntaxTest): flavor-detection grammar table, byte-parity corpus,
 * dialect oracle replay, kernel-state operation script, and sole-impl
 * tripwire. The server never carried a local twin, so the goldens here are a
 * verbatim dialect oracle: its rewrite body equals the shared kernel's
 * documented law, and every table case is asserted against BOTH
 * implementations (kernel === oracle), never against a mirror of the kernel.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */
#[Group('unit')]
final class UpsertSyntaxAdoptionTest extends TestCase
{
    /**
     * Every (file, line, n) entry is one converted upsert statement shape;
     * 'legacy' is the exact pre-wave wire text, 'alias' the new wire text
     * the sites build. Both arms are pinned against the kernel below.
     *
     * @var array<int, array{file: string, line: int, n: int, legacy: string, alias: string}>
     */
    private const CORPUS = [
        [
            'file' => 'src/Access/ProfileTagService.php',
            'legacy' => 'INSERT INTO profile_tags (profile_id, tag, tag_type) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE tag = VALUES(tag), tag_type = VALUES(tag_type)',
            'alias' => 'INSERT INTO profile_tags (profile_id, tag, tag_type) VALUES (?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE tag = new_row.tag, tag_type = new_row.tag_type',
            'line' => 164,
            'n' => 1,
        ],
        [
            'file' => 'src/Access/StreamSessionService.php',
            'legacy' => 'INSERT INTO profile_stream_limits (profile_id, max_concurrent_streams, max_total_bandwidth_kbps) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE max_concurrent_streams = VALUES(max_concurrent_streams), max_total_bandwidth_kbps = VALUES(max_total_bandwidth_kbps)',
            'alias' => 'INSERT INTO profile_stream_limits (profile_id, max_concurrent_streams, max_total_bandwidth_kbps) VALUES (?, ?, ?)  AS new_row ON DUPLICATE KEY UPDATE max_concurrent_streams = new_row.max_concurrent_streams, max_total_bandwidth_kbps = new_row.max_total_bandwidth_kbps',
            'line' => 646,
            'n' => 1,
        ],
        [
            'file' => 'src/Admin/SettingsRepository.php',
            'legacy' => 'INSERT INTO server_settings (id, setting_key, setting_value, value_type) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type)',
            'alias' => 'INSERT INTO server_settings (id, setting_key, setting_value, value_type) VALUES (?, ?, ?, ?)  AS new_row ON DUPLICATE KEY UPDATE setting_value = new_row.setting_value, value_type = new_row.value_type',
            'line' => 205,
            'n' => 1,
        ],
        [
            'file' => 'src/Auth/UserRepository.php',
            'legacy' => 'INSERT INTO user_settings (user_id, max_streams) VALUES (?, ?) ON DUPLICATE KEY UPDATE max_streams = VALUES(max_streams)',
            'alias' => 'INSERT INTO user_settings (user_id, max_streams) VALUES (?, ?) AS new_row ON DUPLICATE KEY UPDATE max_streams = new_row.max_streams',
            'line' => 912,
            'n' => 1,
        ],
        [
            'file' => 'src/Auth/UserRepository.php',
            'legacy' => 'INSERT INTO user_settings (user_id, max_streams, preferred_audio_language) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE max_streams = VALUES(max_streams), preferred_audio_language = VALUES(preferred_audio_language)',
            'alias' => 'INSERT INTO user_settings (user_id, max_streams, preferred_audio_language) VALUES (?, ?, ?) AS new_row ON DUPLICATE KEY UPDATE max_streams = new_row.max_streams, preferred_audio_language = new_row.preferred_audio_language',
            'line' => 912,
            'n' => 2,
        ],
        [
            'file' => 'src/Collections/CollectionItemRepository.php',
            'legacy' => 'INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE media_item_id = VALUES(media_item_id)',
            'alias' => 'INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)
             VALUES (?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE media_item_id = new_row.media_item_id',
            'line' => 81,
            'n' => 1,
        ],
        [
            'file' => 'src/Collections/CollectionItemRepository.php',
            'legacy' => 'INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)
             VALUES (?, ?, ?, ?), (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE media_item_id = VALUES(media_item_id)',
            'alias' => 'INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)
             VALUES (?, ?, ?, ?), (?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE media_item_id = new_row.media_item_id',
            'line' => 278,
            'n' => 1,
        ],
        [
            'file' => 'src/Common/Database/MigrationRunner.php',
            'legacy' => 'INSERT INTO schema_migrations (name, applied_at, checksum) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE applied_at = VALUES(applied_at), checksum = VALUES(checksum)',
            'alias' => 'INSERT INTO schema_migrations (name, applied_at, checksum) VALUES (?, ?, ?) AS new_row ON DUPLICATE KEY UPDATE applied_at = new_row.applied_at, checksum = new_row.checksum',
            'line' => 630,
            'n' => 1,
        ],
        [
            'file' => 'src/LiveTv/ChannelManager.php',
            'legacy' => 'INSERT INTO livetv_lineup_channels (lineup_id, channel_id, position)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE position = VALUES(position)',
            'alias' => 'INSERT INTO livetv_lineup_channels (lineup_id, channel_id, position)
             VALUES (?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE position = new_row.position',
            'line' => 571,
            'n' => 1,
        ],
        [
            'file' => 'src/LiveTv/GuideManager.php',
            'legacy' => 'INSERT INTO livetv_programs
             (program_id, channel_id, title, description, start_time, end_time,
              category, series_id, episode_number, episode_title, rating_system,
              rating, year, series_episode, is_repeat, is_film, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                description = VALUES(description),
                start_time = VALUES(start_time),
                end_time = VALUES(end_time),
                category = VALUES(category),
                series_id = VALUES(series_id),
                episode_number = VALUES(episode_number),
                episode_title = VALUES(episode_title),
                rating_system = VALUES(rating_system),
                rating = VALUES(rating),
                year = VALUES(year),
                series_episode = VALUES(series_episode),
                is_repeat = VALUES(is_repeat),
                is_film = VALUES(is_film),
                updated_at = NOW()',
            'alias' => 'INSERT INTO livetv_programs
             (program_id, channel_id, title, description, start_time, end_time,
              category, series_id, episode_number, episode_title, rating_system,
              rating, year, series_episode, is_repeat, is_film, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
              AS new_row
             ON DUPLICATE KEY UPDATE
                title = new_row.title,
                description = new_row.description,
                start_time = new_row.start_time,
                end_time = new_row.end_time,
                category = new_row.category,
                series_id = new_row.series_id,
                episode_number = new_row.episode_number,
                episode_title = new_row.episode_title,
                rating_system = new_row.rating_system,
                rating = new_row.rating,
                year = new_row.year,
                series_episode = new_row.series_episode,
                is_repeat = new_row.is_repeat,
                is_film = new_row.is_film,
                updated_at = NOW()',
            'line' => 462,
            'n' => 1,
        ],
        [
            'file' => 'src/LiveTv/LiveTvManager.php',
            'legacy' => 'INSERT INTO livetv_tuners (tuner_id, name, type, status, capabilities, discovered_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type), status = VALUES(status)',
            'alias' => 'INSERT INTO livetv_tuners (tuner_id, name, type, status, capabilities, discovered_at)
             VALUES (?, ?, ?, ?, ?, NOW())
              AS new_row
             ON DUPLICATE KEY UPDATE name = new_row.name, type = new_row.type, status = new_row.status',
            'line' => 338,
            'n' => 1,
        ],
        [
            'file' => 'src/LiveTv/TimeShift/DbTimeShiftSessionStore.php',
            'legacy' => 'INSERT INTO livetv_timeshift_sessions
                (id, session_id, channel_id, buffer_dir, pid,
                 buffer_start_at, buffer_end_at, window_seconds,
                 cursor_position, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                channel_id = VALUES(channel_id),
                buffer_dir = VALUES(buffer_dir),
                pid = VALUES(pid),
                buffer_start_at = VALUES(buffer_start_at),
                buffer_end_at = VALUES(buffer_end_at),
                window_seconds = VALUES(window_seconds),
                cursor_position = VALUES(cursor_position),
                status = VALUES(status),
                updated_at = CURRENT_TIMESTAMP',
            'alias' => 'INSERT INTO livetv_timeshift_sessions
                (id, session_id, channel_id, buffer_dir, pid,
                 buffer_start_at, buffer_end_at, window_seconds,
                 cursor_position, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
                channel_id = new_row.channel_id,
                buffer_dir = new_row.buffer_dir,
                pid = new_row.pid,
                buffer_start_at = new_row.buffer_start_at,
                buffer_end_at = new_row.buffer_end_at,
                window_seconds = new_row.window_seconds,
                cursor_position = new_row.cursor_position,
                status = new_row.status,
                updated_at = CURRENT_TIMESTAMP',
            'line' => 70,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/CollectionService.php',
            'legacy' => 'INSERT INTO media_collections (tmdb_collection_id, name, overview, poster_url, backdrop_url)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 name = VALUES(name),
                 overview = VALUES(overview),
                 poster_url = VALUES(poster_url),
                 backdrop_url = VALUES(backdrop_url)',
            'alias' => 'INSERT INTO media_collections (tmdb_collection_id, name, overview, poster_url, backdrop_url)
             VALUES (?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
                 name = new_row.name,
                 overview = new_row.overview,
                 poster_url = new_row.poster_url,
                 backdrop_url = new_row.backdrop_url',
            'line' => 149,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/CollectionService.php',
            'legacy' => 'INSERT INTO media_collection_members (collection_id, media_item_id, tmdb_part_order)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE tmdb_part_order = VALUES(tmdb_part_order)',
            'alias' => 'INSERT INTO media_collection_members (collection_id, media_item_id, tmdb_part_order)
             VALUES (?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE tmdb_part_order = new_row.tmdb_part_order',
            'line' => 274,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/Extras/ExtrasRepository.php',
            'legacy' => 'INSERT INTO media_extras (id, media_item_id, title, extra_type, source,
              url, file_path, duration, quality, cached_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                source = VALUES(source),
                url = VALUES(url),
                file_path = VALUES(file_path),
                duration = VALUES(duration),
                quality = VALUES(quality),
                cached_at = VALUES(cached_at)',
            'alias' => 'INSERT INTO media_extras (id, media_item_id, title, extra_type, source,
              url, file_path, duration, quality, cached_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
                title = new_row.title,
                source = new_row.source,
                url = new_row.url,
                file_path = new_row.file_path,
                duration = new_row.duration,
                quality = new_row.quality,
                cached_at = new_row.cached_at',
            'line' => 163,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/Library/AudiobookProgressStore.php',
            'legacy' => 'INSERT INTO audiobook_progress
                (user_id, audiobook_id, position_ms, current_chapter_index,
                 completed_chapters, percent_complete, last_played_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                position_ms = VALUES(position_ms),
                current_chapter_index = VALUES(current_chapter_index),
                completed_chapters = VALUES(completed_chapters),
                percent_complete = VALUES(percent_complete),
                last_played_at = VALUES(last_played_at)',
            'alias' => 'INSERT INTO audiobook_progress
                (user_id, audiobook_id, position_ms, current_chapter_index,
                 completed_chapters, percent_complete, last_played_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
                position_ms = new_row.position_ms,
                current_chapter_index = new_row.current_chapter_index,
                completed_chapters = new_row.completed_chapters,
                percent_complete = new_row.percent_complete,
                last_played_at = new_row.last_played_at',
            'line' => 118,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/Library/BookProgressStore.php',
            'legacy' => 'INSERT INTO book_progress
                (user_id, book_id, position_ms, current_page, total_pages,
                 percent_complete, last_read_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                position_ms = VALUES(position_ms),
                current_page = VALUES(current_page),
                total_pages = VALUES(total_pages),
                percent_complete = VALUES(percent_complete),
                last_read_at = VALUES(last_read_at)',
            'alias' => 'INSERT INTO book_progress
                (user_id, book_id, position_ms, current_page, total_pages,
                 percent_complete, last_read_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
                position_ms = new_row.position_ms,
                current_page = new_row.current_page,
                total_pages = new_row.total_pages,
                percent_complete = new_row.percent_complete,
                last_read_at = new_row.last_read_at',
            'line' => 100,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/MarkerService.php',
            'legacy' => 'INSERT INTO media_markers
             (media_item_id, marker_type, start_time_ms, end_time_ms, label, user_id, thumbnail_path)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               marker_type = VALUES(marker_type),
               start_time_ms = VALUES(start_time_ms),
               end_time_ms = VALUES(end_time_ms),
               label = VALUES(label),
               user_id = VALUES(user_id),
               thumbnail_path = VALUES(thumbnail_path)',
            'alias' => 'INSERT INTO media_markers
             (media_item_id, marker_type, start_time_ms, end_time_ms, label, user_id, thumbnail_path)
             VALUES (?, ?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
               marker_type = new_row.marker_type,
               start_time_ms = new_row.start_time_ms,
               end_time_ms = new_row.end_time_ms,
               label = new_row.label,
               user_id = new_row.user_id,
               thumbnail_path = new_row.thumbnail_path',
            'line' => 66,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/Metadata/FuzzyMatcher.php',
            'legacy' => 'INSERT INTO manual_match_overrides
             (provider, provider_id, media_item_id, confidence, matched_by, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                 media_item_id = VALUES(media_item_id),
                 confidence = VALUES(confidence),
                 matched_by = VALUES(matched_by),
                 created_at = NOW()',
            'alias' => 'INSERT INTO manual_match_overrides
             (provider, provider_id, media_item_id, confidence, matched_by, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())
              AS new_row
             ON DUPLICATE KEY UPDATE
                 media_item_id = new_row.media_item_id,
                 confidence = new_row.confidence,
                 matched_by = new_row.matched_by,
                 created_at = NOW()',
            'line' => 184,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/Metadata/Imdb/ImdbDatasetImporter.php',
            'legacy' => 'INSERT INTO imdb_title_akas (tconst, ordering, title, normalized_title, region, language, is_original_title) VALUES (?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), normalized_title = VALUES(normalized_title), region = VALUES(region), language = VALUES(language), is_original_title = VALUES(is_original_title)',
            'alias' => 'INSERT INTO imdb_title_akas (tconst, ordering, title, normalized_title, region, language, is_original_title) VALUES (?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?)  AS new_row ON DUPLICATE KEY UPDATE title = new_row.title, normalized_title = new_row.normalized_title, region = new_row.region, language = new_row.language, is_original_title = new_row.is_original_title',
            'line' => 566,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/Metadata/Imdb/ImdbDatasetImporter.php',
            'legacy' => 'INSERT INTO imdb_titles (tconst, primary_title, original_title, normalized_title, title_type, start_year, genres, runtime_minutes, average_rating, num_votes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE primary_title = VALUES(primary_title), original_title = VALUES(original_title), normalized_title = VALUES(normalized_title), title_type = VALUES(title_type), start_year = VALUES(start_year), genres = VALUES(genres), runtime_minutes = VALUES(runtime_minutes), average_rating = VALUES(average_rating), num_votes = VALUES(num_votes)',
            'alias' => 'INSERT INTO imdb_titles (tconst, primary_title, original_title, normalized_title, title_type, start_year, genres, runtime_minutes, average_rating, num_votes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)  AS new_row ON DUPLICATE KEY UPDATE primary_title = new_row.primary_title, original_title = new_row.original_title, normalized_title = new_row.normalized_title, title_type = new_row.title_type, start_year = new_row.start_year, genres = new_row.genres, runtime_minutes = new_row.runtime_minutes, average_rating = new_row.average_rating, num_votes = new_row.num_votes',
            'line' => 605,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/Metadata/RatingService.php',
            'legacy' => 'INSERT INTO metadata_ratings (media_item_id, source, rating_type, score, votes)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE score = VALUES(score), votes = VALUES(votes)',
            'alias' => 'INSERT INTO metadata_ratings (media_item_id, source, rating_type, score, votes)
             VALUES (?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE score = new_row.score, votes = new_row.votes',
            'line' => 93,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/Streaming/StreamManager.php',
            'legacy' => 'INSERT INTO playback_state
             (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
             position_ticks = VALUES(position_ticks),
             playback_status = VALUES(playback_status)',
            'alias' => 'INSERT INTO playback_state
             (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
             VALUES (?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
             position_ticks = new_row.position_ticks,
             playback_status = new_row.playback_status',
            'line' => 426,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/Subtitles/Quota/SubtitleProviderQuotaRepository.php',
            'legacy' => 'INSERT INTO subtitle_provider_quota
                (provider, downloads_remaining, reset_time_utc, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                downloads_remaining = VALUES(downloads_remaining),
                reset_time_utc = VALUES(reset_time_utc),
                updated_at = VALUES(updated_at)',
            'alias' => 'INSERT INTO subtitle_provider_quota
                (provider, downloads_remaining, reset_time_utc, updated_at)
             VALUES (?, ?, ?, NOW())
              AS new_row
             ON DUPLICATE KEY UPDATE
                downloads_remaining = new_row.downloads_remaining,
                reset_time_utc = new_row.reset_time_utc,
                updated_at = new_row.updated_at',
            'line' => 171,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/UserItemDataRepository.php',
            'legacy' => 'INSERT INTO user_item_data (user_id, profile_id, item_id, favorite)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE favorite = VALUES(favorite)',
            'alias' => 'INSERT INTO user_item_data (user_id, profile_id, item_id, favorite)
             VALUES (?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE favorite = new_row.favorite',
            'line' => 170,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/UserItemDataRepository.php',
            'legacy' => 'INSERT INTO user_item_data (user_id, profile_id, item_id, rating)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating)',
            'alias' => 'INSERT INTO user_item_data (user_id, profile_id, item_id, rating)
             VALUES (?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE rating = new_row.rating',
            'line' => 207,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/UserItemDataRepository.php',
            'legacy' => 'INSERT INTO user_item_data (user_id, profile_id, item_id, like_level)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE like_level = VALUES(like_level)',
            'alias' => 'INSERT INTO user_item_data (user_id, profile_id, item_id, like_level)
             VALUES (?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE like_level = new_row.like_level',
            'line' => 251,
            'n' => 1,
        ],
        [
            'file' => 'src/Media/UserItemDataRepository.php',
            'legacy' => 'INSERT INTO user_item_data (user_id, profile_id, item_id, watched)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE watched = VALUES(watched)',
            'alias' => 'INSERT INTO user_item_data (user_id, profile_id, item_id, watched)
             VALUES (?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE watched = new_row.watched',
            'line' => 336,
            'n' => 1,
        ],
        [
            'file' => 'src/Plugins/Repository/PluginSettingsRepository.php',
            'legacy' => 'INSERT INTO plugin_settings (plugin_name, settings_json, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE settings_json = VALUES(settings_json), updated_at = VALUES(updated_at)',
            'alias' => 'INSERT INTO plugin_settings (plugin_name, settings_json, updated_at) VALUES (?, ?, ?) AS new_row ON DUPLICATE KEY UPDATE settings_json = new_row.settings_json, updated_at = new_row.updated_at',
            'line' => 146,
            'n' => 1,
        ],
        [
            'file' => 'src/Server/Arr/CustomFormatSyncer.php',
            'legacy' => 'INSERT INTO custom_format_sync (id, sync_type, remote_id, remote_name, trash_version, synced_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE remote_name = VALUES(remote_name), trash_version = VALUES(trash_version), synced_at = NOW()',
            'alias' => 'INSERT INTO custom_format_sync (id, sync_type, remote_id, remote_name, trash_version, synced_at) VALUES (?, ?, ?, ?, ?, NOW()) AS new_row ON DUPLICATE KEY UPDATE remote_name = new_row.remote_name, trash_version = new_row.trash_version, synced_at = NOW()',
            'line' => 363,
            'n' => 1,
        ],
        [
            'file' => 'src/Server/Integrations/Lastfm/LastfmSessionRepository.php',
            'legacy' => 'INSERT INTO lastfm_sessions (user_id, session_key, connected_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE session_key = VALUES(session_key),
                                     connected_at = VALUES(connected_at)',
            'alias' => 'INSERT INTO lastfm_sessions (user_id, session_key, connected_at)
             VALUES (?, ?, NOW())
              AS new_row
             ON DUPLICATE KEY UPDATE session_key = new_row.session_key,
                                     connected_at = new_row.connected_at',
            'line' => 84,
            'n' => 1,
        ],
        [
            'file' => 'src/Session/PlaybackController.php',
            'legacy' => 'INSERT INTO playback_state (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                position_ticks = VALUES(position_ticks),
                duration_ticks = VALUES(duration_ticks),
                playback_status = VALUES(playback_status),
                updated_at = NOW()',
            'alias' => 'INSERT INTO playback_state (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
             VALUES (?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
                position_ticks = new_row.position_ticks,
                duration_ticks = new_row.duration_ticks,
                playback_status = new_row.playback_status,
                updated_at = NOW()',
            'line' => 164,
            'n' => 1,
        ],
        [
            'file' => 'src/Session/SyncPlay/SyncPlaySnapshotService.php',
            'legacy' => 'INSERT INTO syncplay_snapshots
                (group_id, group_name, member_count, has_password, current_media_id,
                 is_playing, playback_position, playback_state, serialized_state, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                group_name = VALUES(group_name),
                member_count = VALUES(member_count),
                has_password = VALUES(has_password),
                current_media_id = VALUES(current_media_id),
                is_playing = VALUES(is_playing),
                playback_position = VALUES(playback_position),
                playback_state = VALUES(playback_state),
                serialized_state = VALUES(serialized_state),
                updated_at = VALUES(updated_at)',
            'alias' => 'INSERT INTO syncplay_snapshots
                (group_id, group_name, member_count, has_password, current_media_id,
                 is_playing, playback_position, playback_state, serialized_state, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
                group_name = new_row.group_name,
                member_count = new_row.member_count,
                has_password = new_row.has_password,
                current_media_id = new_row.current_media_id,
                is_playing = new_row.is_playing,
                playback_position = new_row.playback_position,
                playback_state = new_row.playback_state,
                serialized_state = new_row.serialized_state,
                updated_at = new_row.updated_at',
            'line' => 89,
            'n' => 1,
        ],
        [
            'file' => 'src/Stats/ClientHeartbeatStore.php',
            'legacy' => 'INSERT INTO client_heartbeats (instance_id, version, client_type, build_token, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE version = VALUES(version), client_type = VALUES(client_type), build_token = VALUES(build_token), last_seen_at = NOW()',
            'alias' => 'INSERT INTO client_heartbeats (instance_id, version, client_type, build_token, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, NOW(), NOW())  AS new_row ON DUPLICATE KEY UPDATE version = new_row.version, client_type = new_row.client_type, build_token = new_row.build_token, last_seen_at = NOW()',
            'line' => 102,
            'n' => 1,
        ],
        [
            'file' => 'src/Stats/Metrics/MetricsFlushService.php',
            'legacy' => 'INSERT INTO metrics_connections
                 (connection_id, worker_id, kind, user_id, remote_ip, session_id,
                  media_item_id, bytes_in, bytes_out, bytes_in_rate, bytes_out_rate,
                  opened_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     kind           = VALUES(kind),
                     user_id        = VALUES(user_id),
                     remote_ip      = VALUES(remote_ip),
                     session_id     = VALUES(session_id),
                     media_item_id  = VALUES(media_item_id),
                     bytes_in       = VALUES(bytes_in),
                     bytes_out      = VALUES(bytes_out),
                     bytes_in_rate  = VALUES(bytes_in_rate),
                     bytes_out_rate = VALUES(bytes_out_rate),
                     last_seen_at   = VALUES(last_seen_at)',
            'alias' => 'INSERT INTO metrics_connections
                 (connection_id, worker_id, kind, user_id, remote_ip, session_id,
                  media_item_id, bytes_in, bytes_out, bytes_in_rate, bytes_out_rate,
                  opened_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                  AS new_row
                 ON DUPLICATE KEY UPDATE
                     kind           = new_row.kind,
                     user_id        = new_row.user_id,
                     remote_ip      = new_row.remote_ip,
                     session_id     = new_row.session_id,
                     media_item_id  = new_row.media_item_id,
                     bytes_in       = new_row.bytes_in,
                     bytes_out      = new_row.bytes_out,
                     bytes_in_rate  = new_row.bytes_in_rate,
                     bytes_out_rate = new_row.bytes_out_rate,
                     last_seen_at   = new_row.last_seen_at',
            'line' => 302,
            'n' => 1,
        ],
        [
            'file' => 'src/Theming/ThemeMediaRepository.php',
            'legacy' => 'INSERT INTO theme_media (
                library_id,
                audio_path,
                audio_url,
                audio_duration,
                audio_format,
                video_path,
                video_url,
                video_duration,
                video_width,
                video_height,
                video_format,
                scanned_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                audio_path = VALUES(audio_path),
                audio_url = VALUES(audio_url),
                audio_duration = VALUES(audio_duration),
                audio_format = VALUES(audio_format),
                video_path = VALUES(video_path),
                video_url = VALUES(video_url),
                video_duration = VALUES(video_duration),
                video_width = VALUES(video_width),
                video_height = VALUES(video_height),
                video_format = VALUES(video_format),
                scanned_at = VALUES(scanned_at)',
            'alias' => 'INSERT INTO theme_media (
                library_id,
                audio_path,
                audio_url,
                audio_duration,
                audio_format,
                video_path,
                video_url,
                video_duration,
                video_width,
                video_height,
                video_format,
                scanned_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             AS new_row
            ON DUPLICATE KEY UPDATE
                audio_path = new_row.audio_path,
                audio_url = new_row.audio_url,
                audio_duration = new_row.audio_duration,
                audio_format = new_row.audio_format,
                video_path = new_row.video_path,
                video_url = new_row.video_url,
                video_duration = new_row.video_duration,
                video_width = new_row.video_width,
                video_height = new_row.video_height,
                video_format = new_row.video_format,
                scanned_at = new_row.scanned_at',
            'line' => 74,
            'n' => 1,
        ],
    ];

    /**
     * Canonical alias-form statement reused by the grammar/flavor tests
     * (ported verbatim from the hub precedent's const SQL).
     */
    private const SQL = "INSERT INTO t (a, b) VALUES (:a, :b)\n   AS new_row\n   ON DUPLICATE KEY UPDATE "
        . 'a = new_row.a, '
        . 'b = t.b + new_row.b, c = GREATEST(t.c, new_row.c)';

    /** Mirror state for the verbatim dialect oracle below. */
    private static bool $oracleAliasSupported = true;

    protected function tearDown(): void
    {
        UpsertSyntax::reset();
        parent::tearDown();
    }

    public function testTheAliasConstantIsPinned(): void
    {
        self::assertSame('new_row', UpsertSyntax::ALIAS);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function versions(): array
    {
        return [
            'mysql 8.0.46' => ['8.0.46', true],
            'mysql 8.0.19' => ['8.0.19', true],
            'mysql 8.4.1' => ['8.4.1-0ubuntu0.24.04.1', true],
            'mysql 8.0.18' => ['8.0.18', false],
            'mysql 5.7' => ['5.7.44', false],
            'mariadb 10.6' => ['10.6.18-MariaDB-0ubuntu0.22.04.1', false],
            'mariadb rpl prefix' => ['5.5.5-10.11.6-MariaDB', false],
            'mariadb 11' => ['11.4.2-MariaDB', false],
            'unparseable' => ['weird', true],
        ];
    }

    /**
     * @param string $version   The server's SELECT VERSION() payload.
     * @param bool   $wantAlias Whether the row-alias grammar is supported.
     */
    #[DataProvider('versions')]
    public function testFlavorDetectionDrivesTheRowAliasDecision(string $version, bool $wantAlias): void
    {
        self::assertSame($wantAlias, UpsertSyntax::supportsAlias($version));

        UpsertSyntax::detect($version);
        self::assertSame($wantAlias, UpsertSyntax::usesAlias());
    }

    public function testModernMysqlKeepsTheRowAliasFormUntouched(): void
    {
        UpsertSyntax::detect('8.0.46');

        self::assertSame(self::SQL, UpsertSyntax::adapt(self::SQL));
    }

    public function testMariaDbGetsTheValuesFunctionFormWithoutTheAlias(): void
    {
        UpsertSyntax::detect('10.6.18-MariaDB');
        $adapted = UpsertSyntax::adapt(self::SQL);

        self::assertStringNotContainsString('new_row', $adapted);
        self::assertStringNotContainsString(' AS ', $adapted);
        self::assertStringContainsString('a = VALUES(a)', $adapted);
        self::assertStringContainsString('b = t.b + VALUES(b)', $adapted);
        self::assertStringContainsString('GREATEST(t.c, VALUES(c))', $adapted);
        self::assertStringContainsString(":b)\n   ON DUPLICATE KEY UPDATE", $adapted);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function corpusProvider(): array
    {
        $cases = [];
        foreach (self::CORPUS as $row) {
            $cases[sprintf('%s:%d#%d', $row['file'], $row['line'], $row['n'])] = [$row['legacy'], $row['alias']];
        }

        return $cases;
    }

    /**
     * The core adoption law, per converted site: on a MariaDB-class flavor
     * the kernel renders the new statement text back to the exact pre-wave
     * wire bytes, and on a modern flavor the alias text passes through
     * byte-for-byte. Legacy is asserted to actually carry the VALUES() form
     * so the differential can never be vacuous.
     */
    #[DataProvider('corpusProvider')]
    public function testCorpusPairRendersBothArmsByteIdentically(string $legacy, string $alias): void
    {
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $legacy);
        self::assertStringContainsString('VALUES(', $legacy);
        self::assertStringContainsString('new_row', $alias);

        UpsertSyntax::reset();
        self::assertSame($alias, UpsertSyntax::adapt($alias), 'modern arm must pass the alias text through untouched');

        UpsertSyntax::detect('10.11.8-MariaDB-1:10.11.8');
        self::assertSame($legacy, UpsertSyntax::adapt($alias), 'legacy arm must reproduce the pre-wave wire bytes exactly');
    }

    /**
     * Grammar table ported from the hub precedent: every alias spelling and
     * every reference shape (including the decoy forms the kernel's
     * word-boundary law deliberately leaves alone) is replayed through both
     * the kernel and the verbatim dialect oracle on both flavors.
     */
    public function testTheKernelAnswersByteIdenticallyToTheDialectOracle(): void
    {
        $versions = [
            '8.0.46',
            '8.0.19',
            '8.0.18',
            '8.4.1-0ubuntu0.24.04.1',
            '5.7.44',
            '9999.9.9',
            'weird',
            '',
            '10.6.18-MariaDB-0ubuntu0.22.04.1',
            '5.5.5-10.11.6-MariaDB',
            '11.4.2-MariaDB',
            '10.6-mariadb-Trial',
            'mariadb 11.8.1',
            '8.0.191',
        ];
        foreach ($versions as $version) {
            self::assertSame(
                self::oracleSupportsAlias($version),
                UpsertSyntax::supportsAlias($version),
                'flavor verdict drifted for version payload ' . var_export($version, true)
            );
        }

        $aliasForms = ['AS new_row', 'as new_row', 'AS  new_row', "AS\tnew_row", 'AS NEW_ROW', 'AS `new_row`'];
        $refSets = [
            'a = new_row.a',
            'a = NEW_ROW.a',
            'a = new_rowx.a',
            'a = `new_row`.`a`',
            'a = new_row_nothere.a',
            'b = t.b + new_row.b, c = GREATEST(t.c, new_row.c)',
            "x = 'AS new_row inside a literal', a = new_row.a",
        ];

        $fired = 0;
        foreach ($aliasForms as $aliasForm) {
            foreach ($refSets as $refSet) {
                $sql = "INSERT INTO t (a, b) VALUES (:a, :b)\n   {$aliasForm}\n   ON DUPLICATE KEY UPDATE {$refSet}";
                foreach ([true, false] as $supported) {
                    self::assertSame(
                        self::oracleRewrite($sql, $supported),
                        UpsertSyntax::rewrite($sql, $supported),
                        'kernel rewrite drifted from the dialect oracle'
                    );
                    $fired++;
                }
            }
        }

        $noAlias = "INSERT INTO t (a, b) VALUES (:a, :b)\n   ON DUPLICATE KEY UPDATE a = VALUES(a), b = t.b + VALUES(b)";
        foreach ([true, false] as $supported) {
            self::assertSame(self::oracleRewrite($noAlias, $supported), UpsertSyntax::rewrite($noAlias, $supported));
            self::assertSame($noAlias, UpsertSyntax::rewrite($noAlias, $supported), 'alias-less statements pass through on both arms');
            $fired++;
        }

        $multiRow = "INSERT INTO t (a, b) VALUES (1, (SELECT MAX(x) FROM u)), (2, 3)\n   AS new_row\n   ON DUPLICATE KEY UPDATE a = new_row.a, b = new_row.b";
        foreach ([true, false] as $supported) {
            self::assertSame(self::oracleRewrite($multiRow, $supported), UpsertSyntax::rewrite($multiRow, $supported), 'nested parentheses must survive the rewrite');
            $fired++;
        }

        self::assertGreaterThan(0, $fired);
    }

    /**
     * Kernel-state operation script ported from the hub precedent: a whole
     * detect/adapt/reset sequence is replayed against a mirrored oracle
     * static, pinning that adapt() consults exactly the documented flavor.
     */
    public function testKernelStateTracksTheDialectOracleThroughOperations(): void
    {
        self::$oracleAliasSupported = true;
        $script = [
            ['detect', '8.0.46'],
            ['adapt', self::SQL],
            ['detect', '10.11.6-MariaDB'],
            ['adapt', self::SQL],
            ['reset', ''],
            ['adapt', self::SQL],
            ['detect', 'weird'],
            ['adapt', self::SQL],
        ];

        foreach ($script as [$op, $arg]) {
            if ($op === 'detect') {
                UpsertSyntax::detect($arg);
                self::$oracleAliasSupported = self::oracleSupportsAlias($arg);
                self::assertSame(self::$oracleAliasSupported, UpsertSyntax::usesAlias(), "state drifted after detect({$arg})");
                continue;
            }

            if ($op === 'reset') {
                UpsertSyntax::reset();
                self::$oracleAliasSupported = true;
                self::assertTrue(UpsertSyntax::usesAlias(), 'reset must restore the default (row-alias) flavor');
                continue;
            }

            self::assertSame(
                self::oracleRewrite($arg, self::$oracleAliasSupported),
                UpsertSyntax::adapt($arg),
                'adapt() diverged from the dialect oracle in flavor ' . var_export(self::$oracleAliasSupported, true)
            );
        }
    }

    /**
     * The twin must never be revived: no server-local class may redefine the
     * name, and the shared kernel must be THE implementation the import
     * resolves to.
     */
    public function testTheSharedKernelIsTheSoleImplementationForTheServer(): void
    {
        self::assertFalse(
            class_exists('Phlix\\Common\\Database\\UpsertSyntax'),
            'A server-local UpsertSyntax was revived; the shared kernel at Phlix\\Shared\\Database\\UpsertSyntax is the sole implementation.'
        );
        self::assertSame(
            \Phlix\Shared\Database\UpsertSyntax::class,
            UpsertSyntax::class,
            'the import must resolve to the shared kernel'
        );

        $definition = '/\b(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+UpsertSyntax\b/';
        $found = [];
        $walker = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::srcDir(), \FilesystemIterator::SKIP_DOTS));
        $visited = 0;
        foreach ($walker as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $visited++;
            if (preg_match($definition, (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = str_replace(self::srcDir() . '/', '', $file->getPathname());
            }
        }

        self::assertSame([], $found, 'UpsertSyntax must never be re-declared inside src/ — delegate to the shared kernel.');
        self::assertGreaterThanOrEqual(700, $visited, 'the src/ walk itself broke (measured 806 files at adoption)');
    }

    /**
     * @return string Absolute path to the repo src/ tree.
     */
    private static function srcDir(): string
    {
        $dir = realpath(__DIR__ . '/../../../../src');
        if (!is_string($dir)) {
            throw new \RuntimeException('cannot reach src/ for the sole-impl scan');
        }

        return $dir;
    }

    /**
     * Verbatim dialect oracle (byte-for-byte the shared kernel's documented
     * law; carried as an independent implementation for the differential,
     * exactly as the hub precedent carried its retired twin).
     */
    private static function oracleSupportsAlias(string $version): bool
    {
        if (stripos($version, 'mariadb') !== false) {
            return false;
        }

        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
            return true;
        }

        return ((int) $m[1] * 10000 + ((int) $m[2]) * 100 + (int) $m[3]) >= 80019;
    }

    private static function oracleRewrite(string $sql, bool $aliasSupported): string
    {
        if ($aliasSupported) {
            return $sql;
        }

        $stripped = (string) preg_replace('/\s+AS\s+new_row\b/i', '', $sql);

        return (string) preg_replace('/\bnew_row\.(\w+)/', 'VALUES($1)', $stripped);
    }

    /**
     * Construction fidelity: the exact slice bytes the corpus generator
     * emitted from the PRE-wave source (legacy chain + mechanical row-alias
     * insertion) must sit verbatim in the live source. The corpus provider
     * proves what adapt() does to these strings; this table proves the
     * converted source actually carries the strings it was built against —
     * a hand-edited fragment anywhere in the adopted set reddens here.
     * The three UserRepository rows are the dynamic builder's marker
     * fragments (no static slice existed to splice).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const FIDELITY_SLICES = [
        'src/Access/ProfileTagService.php:164#1' => ['src/Access/ProfileTagService.php', '\'INSERT INTO profile_tags (profile_id, tag, tag_type) VALUES (?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE tag = new_row.tag, tag_type = new_row.tag_type\''],
        'src/Access/StreamSessionService.php:646#1' => ['src/Access/StreamSessionService.php', '\'INSERT INTO profile_stream_limits (profile_id, max_concurrent_streams, max_total_bandwidth_kbps)\'
            . \' VALUES (?, ?, ?)\'
            . \'  AS new_row ON DUPLICATE KEY UPDATE max_concurrent_streams = new_row.max_concurrent_streams,\'
            . \' max_total_bandwidth_kbps = new_row.max_total_bandwidth_kbps\''],
        'src/Admin/SettingsRepository.php:205#1' => ['src/Admin/SettingsRepository.php', '\'INSERT INTO server_settings (id, setting_key, setting_value, value_type)\'
            . \' VALUES (?, ?, ?, ?)\'
            . \'  AS new_row ON DUPLICATE KEY UPDATE\'
            . \' setting_value = new_row.setting_value,\'
            . \' value_type = new_row.value_type\''],
        'src/Collections/CollectionItemRepository.php:278#1' => ['src/Collections/CollectionItemRepository.php', '"INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)
             VALUES " . implode(\', \', $placeholders) . "
              AS new_row
             ON DUPLICATE KEY UPDATE media_item_id = new_row.media_item_id"'],
        'src/Collections/CollectionItemRepository.php:81#1' => ['src/Collections/CollectionItemRepository.php', '"INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)
             VALUES (?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE media_item_id = new_row.media_item_id"'],
        'src/Common/Database/MigrationRunner.php:630#1' => ['src/Common/Database/MigrationRunner.php', '\'INSERT INTO \' . self::LEDGER_TABLE . \' (name, applied_at, checksum) \'
                . \'VALUES (?, ?, ?) AS new_row \'
                . \'ON DUPLICATE KEY UPDATE applied_at = new_row.applied_at, checksum = new_row.checksum\''],
        'src/LiveTv/ChannelManager.php:571#1' => ['src/LiveTv/ChannelManager.php', '"INSERT INTO livetv_lineup_channels (lineup_id, channel_id, position)
             VALUES (?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE position = new_row.position"'],
        'src/LiveTv/GuideManager.php:462#1' => ['src/LiveTv/GuideManager.php', '"INSERT INTO livetv_programs
             (program_id, channel_id, title, description, start_time, end_time,
              category, series_id, episode_number, episode_title, rating_system,
              rating, year, series_episode, is_repeat, is_film, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
              AS new_row
             ON DUPLICATE KEY UPDATE
                title = new_row.title,
                description = new_row.description,
                start_time = new_row.start_time,
                end_time = new_row.end_time,
                category = new_row.category,
                series_id = new_row.series_id,
                episode_number = new_row.episode_number,
                episode_title = new_row.episode_title,
                rating_system = new_row.rating_system,
                rating = new_row.rating,
                year = new_row.year,
                series_episode = new_row.series_episode,
                is_repeat = new_row.is_repeat,
                is_film = new_row.is_film,
                updated_at = NOW()"'],
        'src/LiveTv/LiveTvManager.php:338#1' => ['src/LiveTv/LiveTvManager.php', '"INSERT INTO livetv_tuners (tuner_id, name, type, status, capabilities, discovered_at)
             VALUES (?, ?, ?, ?, ?, NOW())
              AS new_row
             ON DUPLICATE KEY UPDATE name = new_row.name, type = new_row.type, status = new_row.status"'],
        'src/LiveTv/TimeShift/DbTimeShiftSessionStore.php:70#1' => ['src/LiveTv/TimeShift/DbTimeShiftSessionStore.php', '"INSERT INTO livetv_timeshift_sessions
                (id, session_id, channel_id, buffer_dir, pid,
                 buffer_start_at, buffer_end_at, window_seconds,
                 cursor_position, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
                channel_id = new_row.channel_id,
                buffer_dir = new_row.buffer_dir,
                pid = new_row.pid,
                buffer_start_at = new_row.buffer_start_at,
                buffer_end_at = new_row.buffer_end_at,
                window_seconds = new_row.window_seconds,
                cursor_position = new_row.cursor_position,
                status = new_row.status,
                updated_at = CURRENT_TIMESTAMP"'],
        'src/Media/CollectionService.php:149#1' => ['src/Media/CollectionService.php', '\'INSERT INTO media_collections (tmdb_collection_id, name, overview, poster_url, backdrop_url)
             VALUES (?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
                 name = new_row.name,
                 overview = new_row.overview,
                 poster_url = new_row.poster_url,
                 backdrop_url = new_row.backdrop_url\''],
        'src/Media/CollectionService.php:274#1' => ['src/Media/CollectionService.php', '\'INSERT INTO media_collection_members (collection_id, media_item_id, tmdb_part_order)
             VALUES (?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE tmdb_part_order = new_row.tmdb_part_order\''],
        'src/Media/Extras/ExtrasRepository.php:163#1' => ['src/Media/Extras/ExtrasRepository.php', '"INSERT INTO media_extras (id, media_item_id, title, extra_type, source,
              url, file_path, duration, quality, cached_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
                title = new_row.title,
                source = new_row.source,
                url = new_row.url,
                file_path = new_row.file_path,
                duration = new_row.duration,
                quality = new_row.quality,
                cached_at = new_row.cached_at"'],
        'src/Media/Library/AudiobookProgressStore.php:118#1' => ['src/Media/Library/AudiobookProgressStore.php', '"INSERT INTO audiobook_progress
                (user_id, audiobook_id, position_ms, current_chapter_index,
                 completed_chapters, percent_complete, last_played_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
                position_ms = new_row.position_ms,
                current_chapter_index = new_row.current_chapter_index,
                completed_chapters = new_row.completed_chapters,
                percent_complete = new_row.percent_complete,
                last_played_at = new_row.last_played_at"'],
        'src/Media/Library/BookProgressStore.php:100#1' => ['src/Media/Library/BookProgressStore.php', '"INSERT INTO book_progress
                (user_id, book_id, position_ms, current_page, total_pages,
                 percent_complete, last_read_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
                position_ms = new_row.position_ms,
                current_page = new_row.current_page,
                total_pages = new_row.total_pages,
                percent_complete = new_row.percent_complete,
                last_read_at = new_row.last_read_at"'],
        'src/Media/MarkerService.php:66#1' => ['src/Media/MarkerService.php', '\'INSERT INTO media_markers
             (media_item_id, marker_type, start_time_ms, end_time_ms, label, user_id, thumbnail_path)
             VALUES (?, ?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
               marker_type = new_row.marker_type,
               start_time_ms = new_row.start_time_ms,
               end_time_ms = new_row.end_time_ms,
               label = new_row.label,
               user_id = new_row.user_id,
               thumbnail_path = new_row.thumbnail_path\''],
        'src/Media/Metadata/FuzzyMatcher.php:184#1' => ['src/Media/Metadata/FuzzyMatcher.php', '"INSERT INTO manual_match_overrides
             (provider, provider_id, media_item_id, confidence, matched_by, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())
              AS new_row
             ON DUPLICATE KEY UPDATE
                 media_item_id = new_row.media_item_id,
                 confidence = new_row.confidence,
                 matched_by = new_row.matched_by,
                 created_at = NOW()"'],
        'src/Media/Metadata/Imdb/ImdbDatasetImporter.php:566#1' => ['src/Media/Metadata/Imdb/ImdbDatasetImporter.php', '\'INSERT INTO imdb_title_akas \'
            . \'(tconst, ordering, title, normalized_title, region, language, is_original_title) VALUES \'
            . $placeholders
            . \'  AS new_row ON DUPLICATE KEY UPDATE \'
            . \'title = new_row.title, \'
            . \'normalized_title = new_row.normalized_title, \'
            . \'region = new_row.region, \'
            . \'language = new_row.language, \'
            . \'is_original_title = new_row.is_original_title\''],
        'src/Media/Metadata/Imdb/ImdbDatasetImporter.php:605#1' => ['src/Media/Metadata/Imdb/ImdbDatasetImporter.php', '\'INSERT INTO imdb_titles \'
            . \'(tconst, primary_title, original_title, normalized_title, title_type, \'
            . \'start_year, genres, runtime_minutes, average_rating, num_votes) VALUES \'
            . $placeholders
            . \'  AS new_row ON DUPLICATE KEY UPDATE \'
            . \'primary_title = new_row.primary_title, \'
            . \'original_title = new_row.original_title, \'
            . \'normalized_title = new_row.normalized_title, \'
            . \'title_type = new_row.title_type, \'
            . \'start_year = new_row.start_year, \'
            . \'genres = new_row.genres, \'
            . \'runtime_minutes = new_row.runtime_minutes, \'
            . \'average_rating = new_row.average_rating, \'
            . \'num_votes = new_row.num_votes\''],
        'src/Media/Metadata/RatingService.php:93#1' => ['src/Media/Metadata/RatingService.php', '\'INSERT INTO metadata_ratings (media_item_id, source, rating_type, score, votes)
             VALUES (?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE score = new_row.score, votes = new_row.votes\''],
        'src/Media/Streaming/StreamManager.php:426#1' => ['src/Media/Streaming/StreamManager.php', '"INSERT INTO playback_state
             (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
             VALUES (?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
             position_ticks = new_row.position_ticks,
             playback_status = new_row.playback_status"'],
        'src/Media/Subtitles/Quota/SubtitleProviderQuotaRepository.php:171#1' => ['src/Media/Subtitles/Quota/SubtitleProviderQuotaRepository.php', '\'INSERT INTO subtitle_provider_quota
                (provider, downloads_remaining, reset_time_utc, updated_at)
             VALUES (?, ?, ?, NOW())
              AS new_row
             ON DUPLICATE KEY UPDATE
                downloads_remaining = new_row.downloads_remaining,
                reset_time_utc = new_row.reset_time_utc,
                updated_at = new_row.updated_at\''],
        'src/Media/UserItemDataRepository.php:170#1' => ['src/Media/UserItemDataRepository.php', '"INSERT INTO user_item_data (user_id, profile_id, item_id, favorite)
             VALUES (?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE favorite = new_row.favorite"'],
        'src/Media/UserItemDataRepository.php:207#1' => ['src/Media/UserItemDataRepository.php', '"INSERT INTO user_item_data (user_id, profile_id, item_id, rating)
             VALUES (?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE rating = new_row.rating"'],
        'src/Media/UserItemDataRepository.php:251#1' => ['src/Media/UserItemDataRepository.php', '"INSERT INTO user_item_data (user_id, profile_id, item_id, like_level)
             VALUES (?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE like_level = new_row.like_level"'],
        'src/Media/UserItemDataRepository.php:336#1' => ['src/Media/UserItemDataRepository.php', '"INSERT INTO user_item_data (user_id, profile_id, item_id, watched)
             VALUES (?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE watched = new_row.watched"'],
        'src/Plugins/Repository/PluginSettingsRepository.php:146#1' => ['src/Plugins/Repository/PluginSettingsRepository.php', '\'INSERT INTO plugin_settings (plugin_name, settings_json, updated_at) \'
            . \'VALUES (?, ?, ?) AS new_row \'
            . \'ON DUPLICATE KEY UPDATE settings_json = new_row.settings_json, updated_at = new_row.updated_at\''],
        'src/Server/Arr/CustomFormatSyncer.php:363#1' => ['src/Server/Arr/CustomFormatSyncer.php', '\'INSERT INTO custom_format_sync \'
                . \'(id, sync_type, remote_id, remote_name, trash_version, synced_at) \'
                . \'VALUES (?, ?, ?, ?, ?, NOW()) AS new_row \'
                . \'ON DUPLICATE KEY UPDATE remote_name = new_row.remote_name, \'
                . \'trash_version = new_row.trash_version, synced_at = NOW()\''],
        'src/Server/Integrations/Lastfm/LastfmSessionRepository.php:84#1' => ['src/Server/Integrations/Lastfm/LastfmSessionRepository.php', '\'INSERT INTO lastfm_sessions (user_id, session_key, connected_at)
             VALUES (?, ?, NOW())
              AS new_row
             ON DUPLICATE KEY UPDATE session_key = new_row.session_key,
                                     connected_at = new_row.connected_at\''],
        'src/Session/PlaybackController.php:164#1' => ['src/Session/PlaybackController.php', '"INSERT INTO playback_state (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
             VALUES (?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
                position_ticks = new_row.position_ticks,
                duration_ticks = new_row.duration_ticks,
                playback_status = new_row.playback_status,
                updated_at = NOW()"'],
        'src/Session/SyncPlay/SyncPlaySnapshotService.php:89#1' => ['src/Session/SyncPlay/SyncPlaySnapshotService.php', '"INSERT INTO syncplay_snapshots
                (group_id, group_name, member_count, has_password, current_media_id,
                 is_playing, playback_position, playback_state, serialized_state, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
              AS new_row
             ON DUPLICATE KEY UPDATE
                group_name = new_row.group_name,
                member_count = new_row.member_count,
                has_password = new_row.has_password,
                current_media_id = new_row.current_media_id,
                is_playing = new_row.is_playing,
                playback_position = new_row.playback_position,
                playback_state = new_row.playback_state,
                serialized_state = new_row.serialized_state,
                updated_at = new_row.updated_at"'],
        'src/Stats/ClientHeartbeatStore.php:102#1' => ['src/Stats/ClientHeartbeatStore.php', '\'INSERT INTO client_heartbeats\'
                . \' (instance_id, version, client_type, build_token, first_seen_at, last_seen_at)\'
                . " VALUES (?, ?, ?, ?, NOW(), NOW())"
                . \'  AS new_row ON DUPLICATE KEY UPDATE\'
                . \' version = new_row.version,\'
                . \' client_type = new_row.client_type,\'
                . \' build_token = new_row.build_token,\'
                . \' last_seen_at = NOW()\''],
        'src/Stats/Metrics/MetricsFlushService.php:302#1' => ['src/Stats/Metrics/MetricsFlushService.php', '"INSERT INTO metrics_connections
                 (connection_id, worker_id, kind, user_id, remote_ip, session_id,
                  media_item_id, bytes_in, bytes_out, bytes_in_rate, bytes_out_rate,
                  opened_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                  AS new_row
                 ON DUPLICATE KEY UPDATE
                     kind           = new_row.kind,
                     user_id        = new_row.user_id,
                     remote_ip      = new_row.remote_ip,
                     session_id     = new_row.session_id,
                     media_item_id  = new_row.media_item_id,
                     bytes_in       = new_row.bytes_in,
                     bytes_out      = new_row.bytes_out,
                     bytes_in_rate  = new_row.bytes_in_rate,
                     bytes_out_rate = new_row.bytes_out_rate,
                     last_seen_at   = new_row.last_seen_at"'],
        'src/Theming/ThemeMediaRepository.php:74#1' => ['src/Theming/ThemeMediaRepository.php', '"INSERT INTO theme_media (
                library_id,
                audio_path,
                audio_url,
                audio_duration,
                audio_format,
                video_path,
                video_url,
                video_duration,
                video_width,
                video_height,
                video_format,
                scanned_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             AS new_row
            ON DUPLICATE KEY UPDATE
                audio_path = new_row.audio_path,
                audio_url = new_row.audio_url,
                audio_duration = new_row.audio_duration,
                audio_format = new_row.audio_format,
                video_path = new_row.video_path,
                video_url = new_row.video_url,
                video_duration = new_row.video_duration,
                video_width = new_row.video_width,
                video_height = new_row.video_height,
                video_format = new_row.video_format,
                scanned_at = new_row.scanned_at"'],
        'src/Auth/UserRepository.php:912#legs' => ['src/Auth/UserRepository.php', 'static fn (string $col): string => "{$col} = new_row.{$col}", $columns'],
        'src/Auth/UserRepository.php:912#alias-frag' => ['src/Auth/UserRepository.php', '. \' AS new_row\''],
        'src/Auth/UserRepository.php:912#odku-frag' => ['src/Auth/UserRepository.php', '. \' ON DUPLICATE KEY UPDATE \' . $updateClause'],
    ];

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fidelitySliceProvider(): array
    {
        return self::FIDELITY_SLICES;
    }

    #[DataProvider('fidelitySliceProvider')]
    public function testTheLiveSourceCarriesTheCorpusSliceVerbatim(string $file, string $slice): void
    {
        $path = dirname(__DIR__, 4) . '/' . $file;
        $this->assertFileExists($path);
        $this->assertStringContainsString(
            $slice,
            (string) file_get_contents($path),
            "corpus slice {$file} drifted from the verified bytes"
        );
    }

    /**
     * Verbatim legacy pins for the seven adjudicated KEEP sites (r1 belt):
     * each raw statement bytes must survive untouched. Flipping a KEEP to
     * the row-alias form (adapted or raw) without a designed accept-set
     * change and both-arm re-proof reddens here.
     */
    private const KEEP_STATEMENT_SLICES = [
        'src/Common/RateLimit/DbRateLimiter.php:137#1' => ['src/Common/RateLimit/DbRateLimiter.php', 'ON DUPLICATE KEY UPDATE \'
            . \'attempts = IF(reset_at <= ?, 1, attempts + 1), \'
            . \'reset_at = IF(reset_at <= ?, ?, reset_at)\''],
        'src/Auth/DbLoginRateLimitStore.php:189#1' => ['src/Auth/DbLoginRateLimitStore.php', 'ON DUPLICATE KEY UPDATE attempts = attempts + 1, reset_at = IF(reset_at <= ?, ?, reset_at)\''],
        'src/LiveTv/ChannelManager.php:414#1' => ['src/LiveTv/ChannelManager.php', 'ON DUPLICATE KEY UPDATE added_at = NOW()"'],
        'src/Stats/Metrics/MetricsFlushService.php:185#1' => ['src/Stats/Metrics/MetricsFlushService.php', 'ON DUPLICATE KEY UPDATE
                     request_count   = request_count + VALUES(request_count),
                     error_count     = error_count + VALUES(error_count),
                     duration_ms_sum = duration_ms_sum + VALUES(duration_ms_sum),
                     duration_ms_max = GREATEST(duration_ms_max, VALUES(duration_ms_max)),
                     bytes_in        = bytes_in + VALUES(bytes_in),
                     bytes_out       = bytes_out + VALUES(bytes_out),
                     h_le_10         = h_le_10 + VALUES(h_le_10),
                     h_le_50         = h_le_50 + VALUES(h_le_50),
                     h_le_100        = h_le_100 + VALUES(h_le_100),
                     h_le_250        = h_le_250 + VALUES(h_le_250),
                     h_le_500        = h_le_500 + VALUES(h_le_500),
                     h_le_1000       = h_le_1000 + VALUES(h_le_1000),
                     h_le_2500       = h_le_2500 + VALUES(h_le_2500),
                     h_le_5000       = h_le_5000 + VALUES(h_le_5000),
                     h_gt_5000       = h_gt_5000 + VALUES(h_gt_5000)"'],
        'src/Stats/Metrics/MetricsFlushService.php:250#1' => ['src/Stats/Metrics/MetricsFlushService.php', 'ON DUPLICATE KEY UPDATE
                     request_count   = request_count + VALUES(request_count),
                     error_count     = error_count + VALUES(error_count),
                     duration_ms_sum = duration_ms_sum + VALUES(duration_ms_sum),
                     duration_ms_max = GREATEST(duration_ms_max, VALUES(duration_ms_max))"'],
        'src/Stats/StatsCollector.php:804#1' => ['src/Stats/StatsCollector.php', 'ON DUPLICATE KEY UPDATE
                     item_count = COALESCE(item_count, 0) + VALUES(item_count),
                     total_bytes = COALESCE(total_bytes, 0) + VALUES(total_bytes),
                     transcode_cache_bytes = COALESCE(transcode_cache_bytes, 0) + VALUES(transcode_cache_bytes)"'],
        'src/Auth/WatchHistory.php:728#1' => ['src/Auth/WatchHistory.php', 'ON DUPLICATE KEY UPDATE
                 position_ticks = VALUES(position_ticks),
                 duration_ticks = COALESCE(VALUES(duration_ticks), duration_ticks),
                 playback_status = VALUES(playback_status),
                 progress_percent = VALUES(progress_percent),
                 last_watched_at = NOW(),
                 completed_at = COALESCE({$completedAtExpr}, completed_at)"'],
    ];

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function keepStatementSliceProvider(): array
    {
        return self::KEEP_STATEMENT_SLICES;
    }

    #[DataProvider('keepStatementSliceProvider')]
    public function testTheKeepSitesStillCarryTheirLegacyStatementVerbatim(string $file, string $slice): void
    {
        $path = dirname(__DIR__, 4) . '/' . $file;
        $this->assertFileExists($path);
        $this->assertStringContainsString(
            $slice,
            (string) file_get_contents($path),
            "KEEP site {$file} moved off its pinned legacy statement — the wave adjudication (UPSERT-SRV.md KEEP table) demands a designed accept-set change with both-arm re-proof, never a silent edit."
        );
    }

    /**
     * Source-intrinsic statement count (r1 belt): the walk counts every
     * 'ON DUPLICATE' occurrence inside string tokens ONLY (comments and
     * prose excluded) across src/. It catches the blind spot the
     * delegation census has for UNADAPTED additions: a smuggled raw
     * upsert statement raises this number and reddens here.
     */
    public function testTheCountedUpsertStatementsArePinned(): void
    {
        $count = 0;
        $walker = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::srcDir(), \FilesystemIterator::SKIP_DOTS));
        foreach ($walker as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                if ($token[0] !== T_CONSTANT_ENCAPSED_STRING && $token[0] !== T_ENCAPSED_AND_WHITESPACE) {
                    continue;
                }
                $count += substr_count($token[1], 'ON DUPLICATE');
            }
        }

        self::assertSame(
            42,
            $count,
            'The src/ ON DUPLICATE statement census moved. 42 sites are verified in this wave: 35 kernel-adopted behind adapt(), seven adjudicated KEEPs (UPSERT-SRV.md). '
            . 'A new upsert statement must join the corpus with differential evidence or land as a designed KEEP with a verbatim pin — never a silent addition.'
        );
    }

    /**
     * Table-size pins (r1 belt): the fidelity table and the KEEP table
     * must not thin or grow silently; each rotation rides its measured
     * wave evidence.
     */
    public function testTheVerificationTablesAreCounted(): void
    {
        self::assertCount(
            37,
            self::FIDELITY_SLICES,
            'the corpus fidelity table moved — rotate only with the wave that changes a converted site'
        );
        self::assertCount(
            7,
            self::KEEP_STATEMENT_SLICES,
            'the KEEP pin table moved — a KEEP may change only through a designed accept-set change with both-arm re-proof'
        );
    }
}
