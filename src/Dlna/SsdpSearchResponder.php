<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Dlna;

use Phlix\Common\Version;

/**
 * The SSDP `M-SEARCH` protocol: parsing, target matching and response framing.
 *
 * ## Why this is a separate class from {@see SsdpAdvertiser}
 *
 * `SsdpAdvertiser` is a `Workerman\Worker`; instantiating one registers it in
 * `Worker::$workers`, so anything that lives on it can only be tested by
 * injecting a phantom worker into the runtime. Everything here is pure — string
 * in, string out, no clock other than `DATE:`, no socket — so the protocol rules
 * that decide whether a TV finds this server can be table-tested directly.
 *
 * ## The two halves of "correct" here
 *
 * 1. **Answer the right searches.** A control point sends
 *    `M-SEARCH * HTTP/1.1` with `MAN: "ssdp:discover"` and an `ST` naming what
 *    it is looking for. We answer only when `ST` names something this device
 *    actually is, and we answer **once per matching target** — `ssdp:all` gets
 *    one datagram per entry in {@see self::advertisedTargets()}. Everything
 *    else is ignored in silence; SSDP has no error response, and replying to a
 *    search we do not match is how a device ends up in a source list it cannot
 *    serve.
 * 2. **Frame the reply so it is believed.** The response is an HTTP-shaped
 *    datagram, NOT a NOTIFY — `HTTP/1.1 200 OK` with `CACHE-CONTROL`, `DATE`,
 *    `EXT`, `LOCATION`, `SERVER`, `ST` and `USN`. `EXT` is a header-name-only
 *    field (UPnP DA 1.0 §1.3.3) whose presence confirms `MAN` was understood;
 *    omitting it makes strict control points discard the reply.
 *
 * ## `ST` is echoed, `USN` is composed
 *
 * The reply's `ST` must be the target that MATCHED, not the raw request value —
 * otherwise an `ssdp:all` search would get five datagrams all claiming
 * `ST: ssdp:all`. `USN` pairs with it: `uuid:…::{target}`, except for the
 * device-UUID target itself, which is bare `uuid:…` (a `uuid:X::uuid:X` USN is
 * malformed and is rejected outright by several renderers).
 *
 * @package Phlix\Dlna
 * @since 1.7.0
 */
final class SsdpSearchResponder
{
    /** The only `MAN` value SSDP defines for a search. */
    public const MAN_DISCOVER = 'ssdp:discover';

    /** Wildcard search target: "tell me everything you are". */
    public const ST_ALL = 'ssdp:all';

    /** Root-device search target. */
    public const ST_ROOT_DEVICE = 'upnp:rootdevice';

    /** This device's UPnP device type. Mirrors {@see DlnaDevice::getDeviceTypeUrn()}. */
    public const DEVICE_TYPE = 'urn:schemas-upnp-org:device:MediaServer:1';

    /**
     * Service types this device advertises. These are the two services
     * {@see DlnaDevice::initializeServices()} puts in the device description for
     * `TYPE_SERVER`; a control point that searches for one of them and gets no
     * answer concludes the service is absent, so the two lists must agree.
     */
    public const SERVICE_CONTENT_DIRECTORY = 'urn:schemas-upnp-org:service:ContentDirectory:1';

    /** @see self::SERVICE_CONTENT_DIRECTORY */
    public const SERVICE_CONNECTION_MANAGER = 'urn:schemas-upnp-org:service:ConnectionManager:1';

    /**
     * `max-age` advertised in both the search response and the periodic NOTIFY.
     *
     * 1800 s against a 30 s re-announce interval (see
     * {@see SsdpAdvertiser::BROADCAST_INTERVAL_SECONDS}) means a control point
     * has to miss sixty consecutive announcements before it evicts us.
     */
    public const MAX_AGE_SECONDS = 1800;

    /**
     * Ceiling on the `MX` (maximum wait) a searcher can ask us to spread our
     * reply over. UPnP DA 1.0 §1.3.2 caps `MX` at 5 regardless of what the
     * request says, so a hostile searcher cannot pin a reply — and therefore a
     * pending timer — arbitrarily far into the future.
     */
    public const MAX_MX_SECONDS = 5;

    /**
     * Hard cap on how much of an inbound datagram is parsed.
     *
     * An M-SEARCH is a header block of a few hundred bytes. This bounds the
     * per-packet work on an unauthenticated UDP surface: anything longer is
     * truncated before parsing rather than walked line by line.
     */
    public const MAX_DATAGRAM_BYTES = 2048;

    /** Prevent instantiation — this class is a pure protocol helper. */
    private function __construct()
    {
    }

    /**
     * Every search target this device answers to, in reply order.
     *
     * Order matters only for `ssdp:all`, where control points conventionally
     * expect the root device first.
     *
     * @param string $usn This device's UUID URN (e.g. `uuid:PHLIXSERVER`).
     *
     * @return list<string>
     */
    public static function advertisedTargets(string $usn): array
    {
        return [
            self::ST_ROOT_DEVICE,
            $usn,
            self::DEVICE_TYPE,
            self::SERVICE_CONTENT_DIRECTORY,
            self::SERVICE_CONNECTION_MANAGER,
        ];
    }

    /**
     * Which of our targets this datagram is searching for.
     *
     * Returns an EMPTY list — meaning "ignore, send nothing" — for anything that
     * is not a well-formed `ssdp:discover` M-SEARCH naming a target we are. That
     * covers the NOTIFY datagrams from every other device on the segment, which
     * arrive on this same multicast socket constantly and must never be answered.
     *
     * @param string $datagram Raw inbound UDP payload.
     * @param string $usn      This device's UUID URN.
     *
     * @return list<string> Targets to answer, one response datagram each.
     */
    public static function matchedTargets(string $datagram, string $usn): array
    {
        $datagram = substr($datagram, 0, self::MAX_DATAGRAM_BYTES);

        if (!self::isSearchRequest($datagram)) {
            return [];
        }

        $headers = self::headers($datagram);

        // MAN is mandatory and is spec-quoted. Real clients vary on the quoting,
        // so the quotes are stripped before comparison — but an ABSENT MAN is
        // still a hard reject, because that is what separates a search from the
        // rest of the traffic on this socket.
        $man = trim($headers['MAN'] ?? '', " \t\"");
        if (strcasecmp($man, self::MAN_DISCOVER) !== 0) {
            return [];
        }

        $st = trim($headers['ST'] ?? '');
        if ($st === '') {
            return [];
        }

        $targets = self::advertisedTargets($usn);

        if ($st === self::ST_ALL) {
            return $targets;
        }

        foreach ($targets as $target) {
            if ($target === $st) {
                return [$target];
            }
        }

        return [];
    }

    /**
     * The clamped `MX` this reply may be spread over, in whole seconds.
     *
     * Zero means "answer immediately", which is both the fallback for a missing
     * or malformed `MX` and the correct behaviour for a UNICAST M-SEARCH: UPnP
     * 1.1 §1.3.2 requires `MX` only for multicast searches, and a device that
     * delays a unicast reply just looks slow.
     *
     * @param string $datagram Raw inbound UDP payload.
     *
     * @return int<0, 5>
     */
    public static function delayCapSeconds(string $datagram): int
    {
        $headers = self::headers(substr($datagram, 0, self::MAX_DATAGRAM_BYTES));
        $raw = trim($headers['MX'] ?? '');

        // Deliberately strict: `ctype_digit` rejects '', '-1', '2.5' and 'abc'
        // alike, all of which cast to a plausible-looking int. A negative MX
        // reaching Timer::add() would throw inside the event loop.
        if ($raw === '' || !ctype_digit($raw)) {
            return 0;
        }

        // `max(0, …)` is not redundant belt-and-braces: `ctype_digit` guarantees
        // the string is digits, but a value wider than PHP_INT_MAX casts to a
        // NEGATIVE int, and a negative delay makes `Timer::add()` throw inside
        // the event loop.
        return max(0, min((int) $raw, self::MAX_MX_SECONDS));
    }

    /**
     * The `USN` that pairs with a given search target.
     *
     * @param string $usn    This device's UUID URN.
     * @param string $target The matched search target.
     */
    public static function usnFor(string $usn, string $target): string
    {
        // The device-UUID target is its own USN. `uuid:X::uuid:X` is malformed.
        if ($target === $usn) {
            return $usn;
        }

        return $usn . '::' . $target;
    }

    /**
     * The `SERVER` header, shared by the search response and the NOTIFY.
     *
     * Shape is `OS/version UPnP/1.0 product/version` (UPnP DA 1.0 §1.2.2).
     *
     * The OS *version* token is deliberately a constant rather than
     * `php_uname('r')`. This datagram is handed to any unauthenticated peer that
     * can reach port 1900, and the real value is an exact kernel patch level —
     * a free fingerprint for anyone on the segment. No renderer parses that
     * token; they pattern-match the product token, which is accurate.
     */
    public static function serverHeader(): string
    {
        return sprintf('%s/1.0 UPnP/1.0 Phlix/%s', php_uname('s'), Version::STRING);
    }

    /**
     * Frame a single unicast search response.
     *
     * @param string $target   The matched search target, echoed as `ST`.
     * @param string $usn      This device's UUID URN.
     * @param string $location Device description URL — byte-identical to the
     *                         periodic NOTIFY's, since a control point that
     *                         sees both must not think it found two devices.
     */
    public static function buildResponse(string $target, string $usn, string $location): string
    {
        return sprintf(
            "HTTP/1.1 200 OK\r\n" .
            "CACHE-CONTROL: max-age=%d\r\n" .
            "DATE: %s\r\n" .
            "EXT:\r\n" .
            "LOCATION: %s\r\n" .
            "SERVER: %s\r\n" .
            "ST: %s\r\n" .
            "USN: %s\r\n" .
            "\r\n",
            self::MAX_AGE_SECONDS,
            gmdate('D, d M Y H:i:s') . ' GMT',
            $location,
            self::serverHeader(),
            $target,
            self::usnFor($usn, $target)
        );
    }

    /**
     * Is the request line `M-SEARCH * HTTP/1.1`?
     *
     * The method is compared case-SENSITIVELY (HTTP methods are), but the
     * whitespace between tokens is not pinned, because implementations differ.
     */
    private static function isSearchRequest(string $datagram): bool
    {
        $end = strcspn($datagram, "\r\n");
        $requestLine = trim(substr($datagram, 0, $end));

        return preg_match('#^M-SEARCH\s+\*\s+HTTP/1\.[01]$#', $requestLine) === 1;
    }

    /**
     * Header block as an UPPERCASE-keyed map.
     *
     * SSDP header names are case-insensitive and clients disagree wildly on the
     * casing, so a case-sensitive lookup silently ignores half the real traffic.
     * A repeated header keeps the FIRST occurrence, so a trailing duplicate
     * cannot override an earlier `ST`.
     *
     * @return array<string, string>
     */
    private static function headers(string $datagram): array
    {
        $out = [];

        // Tolerate bare-LF line endings: some embedded clients emit them, and a
        // strict \r\n split turns the whole block into one unparsable line.
        $lines = preg_split('/\r\n|\n|\r/', $datagram);
        if ($lines === false) {
            return $out;
        }

        // Skip the request line.
        array_shift($lines);

        foreach ($lines as $line) {
            if ($line === '') {
                // End of the header block; a body is not our business.
                break;
            }

            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }

            $name = strtoupper(trim(substr($line, 0, $colon)));
            if (isset($out[$name])) {
                continue;
            }

            $out[$name] = trim(substr($line, $colon + 1));
        }

        return $out;
    }
}
