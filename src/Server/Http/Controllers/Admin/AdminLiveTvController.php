<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\LiveTv\ChannelManager;
use Phlix\LiveTv\GuideManager;
use Phlix\LiveTv\LiveTvManager;
use Phlix\LiveTv\Recorder;
use Phlix\LiveTv\Recording\SeriesRuleManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Admin JSON API for Live TV / DVR management (Step 2.4).
 *
 * Provides 20 endpoints for managing tuners, channels, EPG/guide data,
 * DVR recordings, and series recording rules.
 *
 * All endpoints are gated by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * (registered in {@see \Phlix\Server\Core\Application}). The middleware
 * already returns 401/403 for unauthenticated or non-admin callers, so this
 * controller assumes it only runs for authenticated admins.
 *
 * Resident-memory rules: no `exit`/`die`, no blocking `sleep()`, no request
 * data parked in `static`/`global`. Dependencies are resolved from the
 * PSR-11 container so each request gets fresh instances.
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since   2.4 (Live TV / DVR API)
 */
final class AdminLiveTvController
{
    /** @var ContainerInterface PSR-11 container for resolving manager instances */
    private ContainerInterface $container;

    /**
     * Creates a new AdminLiveTvController instance.
     *
     * @param ContainerInterface $container PSR-11 container used to resolve
     *        LiveTV manager instances on each request.
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    // -------------------------------------------------------------------------
    // Tuners
    // -------------------------------------------------------------------------

    /**
     * List all known tuners.
     *
     * GET /api/v1/admin/livetv/tuners
     *
     * @param Request              $request Unused.
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return Response JSON { success: true, tuners: Tuner[] }
     */
    public function listTuners(Request $request, array $params): Response
    {
        try {
            /** @var LiveTvManager $manager */
            $manager = $this->container->get(LiveTvManager::class);
            $tuners = $manager->getTuners();

            return (new Response())->json([
                'success' => true,
                'tuners' => array_values($tuners),
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to list tuners', $e->getMessage());
        }
    }

    /**
     * Get a single tuner by ID.
     *
     * GET /api/v1/admin/livetv/tuners/{id}
     *
     * @param Request              $request Unused.
     * @param array<string, string> $params Must contain 'id'.
     *
     * @return Response JSON { success: true, tuner: Tuner } or 404.
     */
    public function getTuner(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? '';
            if ($id === '') {
                return $this->error(400, 'Missing tuner ID');
            }

            /** @var LiveTvManager $manager */
            $manager = $this->container->get(LiveTvManager::class);
            $tuner = $manager->getTuner($id);

            if ($tuner === null) {
                return $this->error(404, 'Tuner not found');
            }

            return (new Response())->json([
                'success' => true,
                'tuner' => $tuner,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to get tuner', $e->getMessage());
        }
    }

    /**
     * Scan the network for HDHomeRun tuners via SSDP discovery.
     *
     * POST /api/v1/admin/livetv/tuners/scan
     *
     * @param Request              $request Unused.
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return Response JSON { success: true, tuners: Tuner[] }
     */
    public function scanTuners(Request $request, array $params): Response
    {
        try {
            /** @var LiveTvManager $manager */
            $manager = $this->container->get(LiveTvManager::class);
            $tuners = $manager->discoverTuners();

            return (new Response())->json([
                'success' => true,
                'tuners' => array_values($tuners),
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Tuner scan failed', $e->getMessage());
        }
    }

    /**
     * Update tuner name or enabled state.
     *
     * PUT /api/v1/admin/livetv/tuners/{id}
     *
     * @param Request              $request Body: { name?: string, enabled?: bool }
     * @param array<string, string> $params  Must contain 'id'.
     *
     * @return Response JSON { success: true, tuner: Tuner } or 404.
     */
    public function updateTuner(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? '';
            if ($id === '') {
                return $this->error(400, 'Missing tuner ID');
            }

            /** @var LiveTvManager $manager */
            $manager = $this->container->get(LiveTvManager::class);
            $tuner = $manager->getTuner($id);

            if ($tuner === null) {
                return $this->error(404, 'Tuner not found');
            }

            $body = is_array($request->body) ? $request->body : [];
            $updated = [];

            if (isset($body['name']) && is_string($body['name'])) {
                $updated['name'] = $body['name'];
            }

            if (isset($body['enabled'])) {
                $updated['enabled'] = $body['enabled'] ? 1 : 0;
            }

            // Persist update to database via a direct query since
            // LiveTvManager does not have a dedicated updateTuner method.
            // Re-discover to surface the updated tuner state.
            if ($updated !== []) {
                $db = \Phlix\Common\Database\ConnectionPool::getConnection('mysql');
                $setParts = [];
                $values = [];
                if (isset($updated['name'])) {
                    $setParts[] = 'name = ?';
                    $values[] = $updated['name'];
                }
                if (isset($updated['enabled'])) {
                    $setParts[] = 'enabled = ?';
                    $values[] = $updated['enabled'];
                }
                if ($setParts !== []) {
                    $values[] = $id;
                    $db->query(
                        'UPDATE livetv_tuners SET '
                        . implode(', ', $setParts)
                        . ' WHERE tuner_id = ?',
                        $values
                    );
                }
            }

            // Return updated tuner
            $refreshed = $manager->getTuner($id);

            return (new Response())->json([
                'success' => true,
                'tuner' => $refreshed ?? $tuner,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to update tuner', $e->getMessage());
        }
    }

    /**
     * Delete a tuner registration.
     *
     * DELETE /api/v1/admin/livetv/tuners/{id}
     *
     * @param Request              $request Unused.
     * @param array<string, string> $params  Must contain 'id'.
     *
     * @return Response JSON { success: true } or 404.
     */
    public function deleteTuner(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? '';
            if ($id === '') {
                return $this->error(400, 'Missing tuner ID');
            }

            /** @var LiveTvManager $manager */
            $manager = $this->container->get(LiveTvManager::class);
            $tuner = $manager->getTuner($id);

            if ($tuner === null) {
                return $this->error(404, 'Tuner not found');
            }

            $db = \Phlix\Common\Database\ConnectionPool::getConnection('mysql');
            $db->query('DELETE FROM livetv_tuners WHERE tuner_id = ?', [$id]);

            return (new Response())->json(['success' => true]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to delete tuner', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Channels
    // -------------------------------------------------------------------------

    /**
     * List all visible channels.
     *
     * GET /api/v1/admin/livetv/channels
     *
     * @param Request              $request Unused.
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true, channels: Channel[] }
     */
    public function listChannels(Request $request, array $params): Response
    {
        try {
            /** @var ChannelManager $manager */
            $manager = $this->container->get(ChannelManager::class);
            $channels = $manager->getAllChannels();

            return (new Response())->json([
                'success' => true,
                'channels' => $channels,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to list channels', $e->getMessage());
        }
    }

    /**
     * Get a single channel by ID.
     *
     * GET /api/v1/admin/livetv/channels/{id}
     *
     * @param Request              $request Unused.
     * @param array<string, string> $params  Must contain 'id'.
     *
     * @return Response JSON { success: true, channel: Channel } or 404.
     */
    public function getChannel(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? '';
            if ($id === '') {
                return $this->error(400, 'Missing channel ID');
            }

            /** @var ChannelManager $manager */
            $manager = $this->container->get(ChannelManager::class);
            $channel = $manager->getChannel($id);

            if ($channel === null) {
                return $this->error(404, 'Channel not found');
            }

            return (new Response())->json([
                'success' => true,
                'channel' => $channel,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to get channel', $e->getMessage());
        }
    }

    /**
     * Update channel name or enabled state.
     *
     * PUT /api/v1/admin/livetv/channels/{id}
     *
     * @param Request              $request Body: { name?: string, enabled?: bool }
     * @param array<string, string> $params  Must contain 'id'.
     *
     * @return Response JSON { success: true, channel: Channel } or 404.
     */
    public function updateChannel(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? '';
            if ($id === '') {
                return $this->error(400, 'Missing channel ID');
            }

            /** @var ChannelManager $manager */
            $manager = $this->container->get(ChannelManager::class);

            $body = is_array($request->body) ? $request->body : [];
            $updates = [];

            if (isset($body['name']) && is_string($body['name'])) {
                $updates['name'] = $body['name'];
            }

            // ChannelManager doesn't expose enabled directly, map via visibility
            if (isset($body['enabled'])) {
                $updates['visibility'] = $body['enabled']
                    ? ChannelManager::VISIBILITY_VISIBLE
                    : ChannelManager::VISIBILITY_HIDDEN;
            }

            $channel = $manager->updateChannel($id, $updates);

            if ($channel === null) {
                return $this->error(404, 'Channel not found');
            }

            return (new Response())->json([
                'success' => true,
                'channel' => $channel,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to update channel', $e->getMessage());
        }
    }

    /**
     * Redirect to the HLS stream URL for a channel.
     *
     * GET /api/v1/admin/livetv/channels/{id}/stream
     *
     * @param Request              $request Unused.
     * @param array<string, string> $params  Must contain 'id'.
     *
     * @return Response 302 redirect to stream URL or 404/error.
     */
    public function streamChannel(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? '';
            if ($id === '') {
                return $this->error(400, 'Missing channel ID');
            }

            /** @var LiveTvManager $manager */
            $manager = $this->container->get(LiveTvManager::class);

            $tuneResult = $manager->tuneToChannel($id, null);
            $streamUrl = $tuneResult['stream_url'] ?? null;

            if ($streamUrl === null || $streamUrl === '') {
                return $this->error(500, 'Failed to obtain stream URL');
            }

            return (new Response())->redirect($streamUrl);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to start channel stream', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // EPG / Guide
    // -------------------------------------------------------------------------

    /**
     * List guide programs with optional channel_id and time-range filters.
     *
     * GET /api/v1/admin/livetv/guide
     * Query params: channel_id, from (Unix timestamp), to (Unix timestamp)
     *
     * @param Request              $request Contains query string params.
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true, programs: Program[] }
     */
    public function listGuide(Request $request, array $params): Response
    {
        try {
            /** @var GuideManager $manager */
            $manager = $this->container->get(GuideManager::class);

            $channelId = is_string($request->query['channel_id'] ?? null)
                ? (string) $request->query['channel_id']
                : null;
            $from = is_string($request->query['from'] ?? null) && is_numeric($request->query['from'])
                ? (int) $request->query['from']
                : time();
            $to = is_string($request->query['to'] ?? null) && is_numeric($request->query['to'])
                ? (int) $request->query['to']
                : time() + 86400 * 7; // default 7 days ahead

            if ($channelId !== null && $channelId !== '') {
                $programs = $manager->getProgramsForChannel($channelId, $from, $to);
            } else {
                // No channel filter: return upcoming programs across all channels.
                // Fetch via search for the next 7 days as a reasonable default.
                $programs = $manager->searchPrograms('', 500);
                // Filter to time range
                $programs = array_values(array_filter(
                    $programs,
                    static fn(array $p): bool => ($p['start_time'] ?? 0) >= $from && ($p['start_time'] ?? 0) <= $to
                ));
            }

            return (new Response())->json([
                'success' => true,
                'programs' => $programs,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to list guide', $e->getMessage());
        }
    }

    /**
     * Get a single program by ID.
     *
     * GET /api/v1/admin/livetv/guide/programs/{id}
     *
     * @param Request              $request Unused.
     * @param array<string, string> $params  Must contain 'id'.
     *
     * @return Response JSON { success: true, program: Program } or 404.
     */
    public function getProgram(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? '';
            if ($id === '') {
                return $this->error(400, 'Missing program ID');
            }

            /** @var GuideManager $manager */
            $manager = $this->container->get(GuideManager::class);
            $program = $manager->getProgram($id);

            if ($program === null) {
                return $this->error(404, 'Program not found');
            }

            return (new Response())->json([
                'success' => true,
                'program' => $program,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to get program', $e->getMessage());
        }
    }

    /**
     * Refresh EPG data from Schedules Direct or HDHomeRun.
     *
     * POST /api/v1/admin/livetv/guide/refresh
     *
     * @param Request              $request Body (optional): { days_ahead?: int }
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true, programs: number } (count imported).
     */
    public function refreshGuide(Request $request, array $params): Response
    {
        try {
            /** @var LiveTvManager $manager */
            $manager = $this->container->get(LiveTvManager::class);

            $body = is_array($request->body) ? $request->body : [];
            $daysAhead = is_int($body['days_ahead'] ?? null)
                ? (int) $body['days_ahead']
                : 14;

            $result = $manager->syncSdEpG($daysAhead);
            $imported = $result['imported'] ?? 0;

            return (new Response())->json([
                'success' => true,
                'programs' => $imported,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Guide refresh failed', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Recordings
    // -------------------------------------------------------------------------

    /**
     * List all recordings.
     *
     * GET /api/v1/admin/livetv/recordings
     *
     * @param Request              $request Optional query: status (filter by status).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true, recordings: Recording[] }
     */
    public function listRecordings(Request $request, array $params): Response
    {
        try {
            /** @var Recorder $recorder */
            $recorder = $this->container->get(Recorder::class);

            $status = is_string($request->query['status'] ?? null) && $request->query['status'] !== ''
                ? (string) $request->query['status']
                : null;

            $recordings = $recorder->getAllRecordings($status);

            return (new Response())->json([
                'success' => true,
                'recordings' => $recordings,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to list recordings', $e->getMessage());
        }
    }

    /**
     * Get a single recording by ID.
     *
     * GET /api/v1/admin/livetv/recordings/{id}
     *
     * @param Request              $request Unused.
     * @param array<string, string> $params  Must contain 'id'.
     *
     * @return Response JSON { success: true, recording: Recording } or 404.
     */
    public function getRecording(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? '';
            if ($id === '') {
                return $this->error(400, 'Missing recording ID');
            }

            /** @var Recorder $recorder */
            $recorder = $this->container->get(Recorder::class);
            $recording = $recorder->getRecording($id);

            if ($recording === null) {
                return $this->error(404, 'Recording not found');
            }

            return (new Response())->json([
                'success' => true,
                'recording' => $recording,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to get recording', $e->getMessage());
        }
    }

    /**
     * Create a manual recording.
     *
     * POST /api/v1/admin/livetv/recordings
     * Body: { channel_id, start_time, end_time, title, program_id?, priority? }
     *
     * @param Request              $request Body parameters.
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true, recording: Recording } or 400.
     */
    public function createRecording(Request $request, array $params): Response
    {
        try {
            $body = is_array($request->body) ? $request->body : [];

            $channelId = $body['channel_id'] ?? null;
            $startTime = $body['start_time'] ?? null;
            $endTime = $body['end_time'] ?? null;
            $title = $body['title'] ?? 'Untitled Recording';

            if (
                !is_string($channelId) || $channelId === ''
                || !is_numeric($startTime) || !is_numeric($endTime)
            ) {
                return $this->error(400, 'channel_id, start_time, and end_time are required');
            }

            /** @var Recorder $recorder */
            $recorder = $this->container->get(Recorder::class);

            $recording = $recorder->scheduleRecording([
                'channel_id' => $channelId,
                'start_time' => (int) $startTime,
                'end_time' => (int) $endTime,
                'title' => is_string($title) ? $title : 'Untitled Recording',
                'program_id' => is_string($body['program_id'] ?? null) ? $body['program_id'] : null,
                'priority' => is_int($body['priority'] ?? null) ? $body['priority'] : Recorder::PRIORITY_NORMAL,
            ]);

            return (new Response())->json([
                'success' => true,
                'recording' => $recording,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to create recording', $e->getMessage());
        }
    }

    /**
     * Delete a recording and its associated file.
     *
     * DELETE /api/v1/admin/livetv/recordings/{id}
     *
     * @param Request              $request Unused.
     * @param array<string, string> $params  Must contain 'id'.
     *
     * @return Response JSON { success: true } or 404.
     */
    public function deleteRecording(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? '';
            if ($id === '') {
                return $this->error(400, 'Missing recording ID');
            }

            /** @var Recorder $recorder */
            $recorder = $this->container->get(Recorder::class);
            $deleted = $recorder->deleteRecording($id);

            if (!$deleted) {
                return $this->error(404, 'Recording not found');
            }

            return (new Response())->json(['success' => true]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to delete recording', $e->getMessage());
        }
    }

    /**
     * List upcoming scheduled recordings.
     *
     * GET /api/v1/admin/livetv/recordings/upcoming
     *
     * @param Request              $request Optional query: limit (default 10).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true, recordings: Recording[] }
     */
    public function listUpcomingRecordings(Request $request, array $params): Response
    {
        try {
            /** @var Recorder $recorder */
            $recorder = $this->container->get(Recorder::class);

            $limit = is_string($request->query['limit'] ?? null) && is_numeric($request->query['limit'])
                ? (int) $request->query['limit']
                : 10;

            $recordings = $recorder->getUpcomingRecordings($limit);

            return (new Response())->json([
                'success' => true,
                'recordings' => $recordings,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to list upcoming recordings', $e->getMessage());
        }
    }

    /**
     * List recordings for a series by series ID.
     *
     * GET /api/v1/admin/livetv/recordings/series/{seriesId}
     *
     * @param Request              $request Unused.
     * @param array<string, string> $params  Must contain 'seriesId'.
     *
     * @return Response JSON { success: true, recordings: Recording[] }
     */
    public function listBySeries(Request $request, array $params): Response
    {
        try {
            $seriesId = $params['seriesId'] ?? '';
            if ($seriesId === '') {
                return $this->error(400, 'Missing series ID');
            }

            /** @var Recorder $recorder */
            $recorder = $this->container->get(Recorder::class);

            // Recorder does not expose getBySeriesId; filter recordings manually.
            $all = $recorder->getAllRecordings();
            $filtered = array_values(array_filter(
                $all,
                static fn(array $r): bool => ($r['series_rule_id'] ?? null) === $seriesId
            ));

            return (new Response())->json([
                'success' => true,
                'recordings' => $filtered,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to list series recordings', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Series Rules (auto-DVR)
    // -------------------------------------------------------------------------

    /**
     * List all active series rules.
     *
     * GET /api/v1/admin/livetv/series-rules
     *
     * @param Request              $request Unused.
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true, rules: SeriesRule[] }
     */
    public function listSeriesRules(Request $request, array $params): Response
    {
        try {
            /** @var SeriesRuleManager $manager */
            $manager = $this->container->get(SeriesRuleManager::class);
            $rules = $manager->getRules();

            return (new Response())->json([
                'success' => true,
                'rules' => $rules,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to list series rules', $e->getMessage());
        }
    }

    /**
     * Get a single series rule by ID.
     *
     * GET /api/v1/admin/livetv/series-rules/{id}
     *
     * @param Request              $request Unused.
     * @param array<string, string> $params  Must contain 'id'.
     *
     * @return Response JSON { success: true, rule: SeriesRule } or 404.
     */
    public function getSeriesRule(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? '';
            if ($id === '') {
                return $this->error(400, 'Missing rule ID');
            }

            /** @var SeriesRuleManager $manager */
            $manager = $this->container->get(SeriesRuleManager::class);
            $rule = $manager->getRule($id);

            if ($rule === null) {
                return $this->error(404, 'Series rule not found');
            }

            return (new Response())->json([
                'success' => true,
                'rule' => $rule,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to get series rule', $e->getMessage());
        }
    }

    /**
     * Create a new series rule for automatic DVR recording.
     *
     * POST /api/v1/admin/livetv/series-rules
     * Body: { series_id, channel_id, title, priority?, pre_padding_seconds?,
     *         post_padding_seconds?, max_recordings?, days_ahead? }
     *
     * @param Request              $request Body parameters.
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true, rule: SeriesRule } or 400.
     */
    public function createSeriesRule(Request $request, array $params): Response
    {
        try {
            $body = is_array($request->body) ? $request->body : [];

            $seriesId = $body['series_id'] ?? null;
            $channelId = $body['channel_id'] ?? null;
            $title = $body['title'] ?? 'Series Recording';

            if (
                !is_string($seriesId) || $seriesId === ''
                || !is_string($channelId) || $channelId === ''
            ) {
                return $this->error(400, 'series_id and channel_id are required');
            }

            /** @var SeriesRuleManager $manager */
            $manager = $this->container->get(SeriesRuleManager::class);

            $options = [];
            if (is_string($title)) {
                $options['title'] = $title;
            }
            if (is_int($body['priority'] ?? null)) {
                $options['priority'] = $body['priority'];
            }
            if (is_int($body['pre_padding_seconds'] ?? null)) {
                $options['pre_padding_seconds'] = $body['pre_padding_seconds'];
            }
            if (is_int($body['post_padding_seconds'] ?? null)) {
                $options['post_padding_seconds'] = $body['post_padding_seconds'];
            }
            if (is_int($body['max_recordings'] ?? null)) {
                $options['max_recordings'] = $body['max_recordings'];
            }
            if (is_int($body['days_ahead'] ?? null)) {
                $options['days_ahead'] = $body['days_ahead'];
            }

            $rule = $manager->createRule($seriesId, $channelId, $options);

            return (new Response())->json([
                'success' => true,
                'rule' => $rule,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to create series rule', $e->getMessage());
        }
    }

    /**
     * Update an existing series rule.
     *
     * PUT /api/v1/admin/livetv/series-rules/{id}
     * Body: partial SeriesRule fields to update.
     *
     * @param Request              $request Body parameters.
     * @param array<string, string> $params  Must contain 'id'.
     *
     * @return Response JSON { success: true, rule: SeriesRule } or 404.
     */
    public function updateSeriesRule(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? '';
            if ($id === '') {
                return $this->error(400, 'Missing rule ID');
            }

            /** @var SeriesRuleManager $manager */
            $manager = $this->container->get(SeriesRuleManager::class);

            $body = is_array($request->body) ? $request->body : [];
            $rule = $manager->updateRule($id, $body);

            if ($rule === null) {
                return $this->error(404, 'Series rule not found');
            }

            return (new Response())->json([
                'success' => true,
                'rule' => $rule,
            ]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to update series rule', $e->getMessage());
        }
    }

    /**
     * Delete a series rule.
     *
     * DELETE /api/v1/admin/livetv/series-rules/{id}
     *
     * @param Request              $request Unused.
     * @param array<string, string> $params  Must contain 'id'.
     *
     * @return Response JSON { success: true } or 404.
     */
    public function deleteSeriesRule(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? '';
            if ($id === '') {
                return $this->error(400, 'Missing rule ID');
            }

            /** @var SeriesRuleManager $manager */
            $manager = $this->container->get(SeriesRuleManager::class);
            $deleted = $manager->deleteRule($id);

            if (!$deleted) {
                return $this->error(404, 'Series rule not found');
            }

            return (new Response())->json(['success' => true]);
        } catch (Throwable $e) {
            return $this->error(500, 'Failed to delete series rule', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a standardised error JSON response.
     *
     * @param int    $statusCode HTTP status code (400, 404, 500, etc.).
     * @param string $message   Human-readable error summary.
     * @param string $detail     Optional technical detail (exception message).
     *
     * @return Response JSON error payload.
     */
    private function error(int $statusCode, string $message, string $detail = ''): Response
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($detail !== '') {
            $payload['detail'] = $detail;
        }

        return (new Response())->status($statusCode)->json($payload);
    }
}
