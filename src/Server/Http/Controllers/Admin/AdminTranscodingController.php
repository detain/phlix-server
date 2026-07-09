<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

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
    public function __construct(
        private readonly FfmpegRunner $ffmpegRunner,
    ) {
    }

    /**
     * Returns auto-detected hardware accelerators and system FFmpeg metadata.
     *
     * `GET /api/v1/admin/transcoding/accelerators`
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON:
     *   - 200: `{ accelerators: array<array{name, encoders, isHardware}>, auto_detected: true, ffmpeg_version: string }`
     *   - 500: `{ error: string, message: string }` on failure
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
            return (new Response())->status(500)->json([
                'error' => 'Failed to detect hardware accelerators',
                'message' => $e->getMessage(),
            ]);
        }
    }
}
