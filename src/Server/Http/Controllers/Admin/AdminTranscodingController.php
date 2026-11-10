<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\HardwareAccelerator;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Throwable;

/**
 * Admin JSON API for transcoding management and hardware accelerator introspection.
 *
 * Exposes:
 *   - `GET /api/v1/admin/transcoding/accelerators` — auto-detected hardware
 *     accelerators with their available encoders, FFmpeg version, and whether
 *     detection succeeded.
 *
 * The route is gated by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * (registered in {@see \Phlix\Server\Http\Routes\AdminRoutes}); non-admin
 * callers receive a JSON 401/403 from the middleware BEFORE this controller
 * runs, so it assumes an already-authenticated admin.
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since 0.36.0
 */
final class AdminTranscodingController
{
    /**
     * SH-003 (M-SERVER-05 idiom): the operator-facing `message` carried by the
     * 500 envelope {@see accelerators()} emits on probe failure. FfmpegRunner
     * failures are system copy (process errors, absolute binary paths), so the
     * class, message and origin are logged server-side ({@see logFailure()})
     * and the wire carries this constant. Envelope SHAPE unchanged, no wire
     * error codes added — contract law.
     */
    // Concatenated to keep the PSR-12 warning-strict src gate happy; the
    // runtime value is one flat string (pinned verbatim by the law test).
    private const GENERIC_FAILURE_MESSAGE = 'An internal error occurred while detecting '
        . 'hardware accelerators. The details were logged server-side.';

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config App config array (injected by container)
     */
    public function __construct(
        private readonly FfmpegRunner $ffmpegRunner,
        array $config,
    ) {
        $this->config = is_array($config['ffmpeg'] ?? null) ? $config['ffmpeg'] : [];
    }

    /**
     * Returns auto-detected hardware accelerators and system FFmpeg metadata.
     *
     * `GET /api/v1/admin/transcoding/accelerators`
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON: 200: `{accelerators: array<array{name,encoders,isHardware}>,
     *   auto_detected: true, ffmpeg_version: string}` | 500: `{error: string, message: string}` on failure
     *
     * @since 0.36.0
     */
    public function accelerators(Request $request, array $params): Response
    {
        try {
            $accelerators = $this->ffmpegRunner->getHardwareAccelerators();
            $ffmpegVersion = $this->ffmpegRunner->getVersion();
            $preferred = $this->ffmpegRunner->getPreferredAccelerator();

            $acceleratorList = array_values(array_map(
                static fn (HardwareAccelerator $accel): array => $accel->toArray(),
                $accelerators
            ));

            return (new Response())->json([
                'accelerators' => $acceleratorList,
                'auto_detected' => true,
                'ffmpeg_version' => $ffmpegVersion ?? 'unknown',
                'preferred_accelerator' => $preferred,
            ]);
        } catch (Throwable $e) {
            self::logFailure('Failed to detect hardware accelerators', $e);
            return (new Response())->status(500)->json([
                'error' => 'Failed to detect hardware accelerators',
                'message' => self::GENERIC_FAILURE_MESSAGE,
            ]);
        }
    }

    /**
     * Returns the current HDR tone-mapping settings.
     *
     * `GET /api/v1/admin/transcoding/tone-mapping`
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON:
     *   - 200: `{ tone_mapping_mode: string, prefer_hdr_output: bool }`
     *
     * @since 0.36.0
     */
    public function toneMapping(Request $request, array $params): Response
    {
        return (new Response())->json([
            'tone_mapping_mode' => $this->config['tone_mapping_mode'] ?? 'zscale',
            'prefer_hdr_output' => $this->config['prefer_hdr_output'] ?? false,
        ]);
    }

    /**
     * Persists HDR tone-mapping settings.
     *
     * `PUT /api/v1/admin/transcoding/tone-mapping`
     *
     * @param Request              $request The HTTP request.
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON:
     *   - 200: `{ tone_mapping_mode: string, prefer_hdr_output: bool }`
     *   - 400: `{ error: string }` on invalid mode
     *
     * @since 0.36.0
     */
    public function setToneMapping(Request $request, array $params): Response
    {
        $body = is_array($request->body) ? $request->body : [];
        $mode = $body['tone_mapping_mode'] ?? 'zscale';
        $preferHdr = (bool) ($body['prefer_hdr_output'] ?? false);

        // Validate mode
        if (!in_array($mode, ['none', 'zscale', 'libplacebo'], true)) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid tone_mapping_mode',
            ]);
        }

        $this->config['tone_mapping_mode'] = $mode;
        $this->config['prefer_hdr_output'] = $preferHdr;

        // Sync config to FfmpegRunner so transcode commands pick up the new settings
        $this->ffmpegRunner->setConfig($this->config);

        return (new Response())->json([
            'tone_mapping_mode' => $mode,
            'prefer_hdr_output' => $preferHdr,
        ]);
    }

    /**
     * SH-003: full exception detail goes to the HTTP log channel, never to the
     * wire. Mirrors AdminSettingsController::logFailure() (M-SERVER-05).
     *
     * @param string    $event Log event label (the per-arm copy).
     * @param Throwable $e     The caught failure.
     */
    private static function logFailure(string $event, Throwable $e): void
    {
        LoggerFactory::get(LogChannels::HTTP)->error($event, [
            'exception' => $e::class,
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);
    }
}
