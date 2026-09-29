<?php

/**
 * Phlix media server component: Iptv.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv\Tuners\Iptv;

/**
 * Thrown when a fetched M3U playlist exceeds the configured byte cap.
 *
 * Mirrors {@see XmlTvOversizedException}: the cap exists so a hostile or
 * misconfigured playlist endpoint cannot make a Workerman worker buffer an
 * unbounded response body during a synchronous fetch.
 *
 * @since 2.3.0
 */
class M3UPlaylistOversizedException extends \RuntimeException
{
}
