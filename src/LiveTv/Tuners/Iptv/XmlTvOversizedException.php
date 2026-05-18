<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Tuners\Iptv;

use RuntimeException;

/**
 * Thrown when a remote XMLTV download exceeds the configured maximum size.
 *
 * Protects the worker against malicious EPG endpoints that serve
 * arbitrarily large payloads which would otherwise exhaust memory.
 *
 * @since 0.16.0
 */
final class XmlTvOversizedException extends RuntimeException
{
}
