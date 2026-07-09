<?php

/**
 * Phlix media server component: ThemeMusic.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\ThemeMusic;

/**
 * Fetches a remote theme-audio file (the Plex theme archive) as raw bytes.
 *
 * Abstracted behind an interface so {@see ThemeMusicResolver} can be unit-tested
 * without a live network request: tests inject a fake that returns canned bytes
 * (200) or null (404 / timeout / error). Implementations MUST NOT throw — any
 * network/HTTP failure is reported as a null return so a theme lookup can never
 * abort a scan.
 *
 * @since 0.66.0
 */
interface ThemeMusicFetcherInterface
{
    /**
     * GET an audio URL, returning its raw body on a `200 OK`, else null.
     *
     * A non-200 status, a timeout, a transport error, or an empty body all yield
     * null. The caller is responsible for validating the URL (this method trusts
     * the URL it is handed).
     *
     * @param string $url            Absolute HTTP(S) URL of the theme file.
     * @param int    $timeoutSeconds Connect/read timeout in seconds (> 0).
     *
     * @return string|null Raw response body on success, null on any failure.
     */
    public function fetch(string $url, int $timeoutSeconds): ?string;
}
