<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Version;
use Phlix\Dlna\DlnaDevice;
use Phlix\Dlna\SsdpAdvertiser;
use Phlix\Dlna\SsdpSearchResponder;

/**
 * The SSDP `M-SEARCH` protocol rules, table-tested.
 *
 * This file covers the FORMATTER and the MATCHER. It deliberately does not
 * claim to cover the listener: a class that formats a perfect response is
 * worthless if the socket never receives anything, and a multicast join can
 * fail while returning TRUE. That half is proved on a real socket in
 * {@see SsdpMSearchListenerTest}.
 *
 * (No coverage metadata: this repo removed it wholesale, and
 * `CoverageMetadataPolicyTest` reds any file that reintroduces it — including,
 * as this comment originally proved, one that merely names the annotation.)
 */
final class SsdpSearchResponderTest extends TestCase
{
    private const USN = 'uuid:PHLIXSERVER';

    /**
     * A well-formed multicast M-SEARCH, as a real control point sends it.
     */
    private function search(string $st, ?string $mx = '3', string $man = '"ssdp:discover"'): string
    {
        $lines = [
            'M-SEARCH * HTTP/1.1',
            'HOST: 239.255.255.250:1900',
            'MAN: ' . $man,
            'ST: ' . $st,
        ];
        if ($mx !== null) {
            $lines[] = 'MX: ' . $mx;
        }

        return implode("\r\n", $lines) . "\r\n\r\n";
    }

    // ------------------------------------------------------------------
    // Which searches get answered
    // ------------------------------------------------------------------

    /**
     * CONSEQUENCE: each target this device IS gets exactly one answer.
     *
     * A control point looking for `urn:…:service:ContentDirectory:1` and getting
     * silence concludes this server has no browse service and never shows it.
     */
    public function test_every_advertised_target_is_answered_exactly_once(): void
    {
        foreach (SsdpSearchResponder::advertisedTargets(self::USN) as $target) {
            self::assertSame(
                [$target],
                SsdpSearchResponder::matchedTargets($this->search($target), self::USN),
                "A search for '{$target}' must be answered with exactly that target."
            );
        }
    }

    /**
     * CONSEQUENCE: `ssdp:all` gets one datagram PER target, not one saying
     * `ST: ssdp:all`.
     *
     * The reply's ST is what the control point records as "what this device is".
     * Echoing `ssdp:all` back would tell it we are a device of type `ssdp:all`.
     */
    public function test_ssdp_all_is_answered_once_per_advertised_target(): void
    {
        $targets = SsdpSearchResponder::matchedTargets(
            $this->search(SsdpSearchResponder::ST_ALL),
            self::USN
        );

        self::assertSame(SsdpSearchResponder::advertisedTargets(self::USN), $targets);
        self::assertNotContains(SsdpSearchResponder::ST_ALL, $targets, 'ssdp:all must never be echoed as an ST.');
        self::assertCount(5, $targets);
    }

    /**
     * CONSEQUENCE: a search for something we are not is met with silence.
     *
     * SSDP has no error response. Answering a MediaRenderer search puts Phlix in
     * a control point's renderer list as a device that will not play anything.
     *
     * The inputs DISCRIMINATE: `MediaRenderer:1` differs from our real device
     * type by one word, and `…MediaServer:2` by one character, so a `str_contains`
     * or prefix match passes the exact-equality implementation but fails here.
     */
    public function test_a_target_this_device_is_not_gets_no_reply(): void
    {
        $notUs = [
            'urn:schemas-upnp-org:device:MediaRenderer:1',
            'urn:schemas-upnp-org:device:MediaServer:2',
            'urn:schemas-upnp-org:service:AVTransport:1',
            'uuid:SOMEONEELSE',
            'upnp:rootdevic',
        ];

        foreach ($notUs as $st) {
            self::assertSame(
                [],
                SsdpSearchResponder::matchedTargets($this->search($st), self::USN),
                "'{$st}' is not something this device is; it must not be answered."
            );
        }
    }

    /**
     * CONSEQUENCE: the NOTIFY traffic of every other device on the segment is
     * ignored.
     *
     * This is the single most common thing that arrives on the socket. A
     * responder that answered a NOTIFY would answer its own announcements
     * reflected back by the multicast loop and produce a packet storm.
     */
    public function test_non_search_datagrams_are_ignored(): void
    {
        $cases = [
            'a NOTIFY from another device' =>
                "NOTIFY * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\nNT: upnp:rootdevice\r\n"
                . "NTS: ssdp:alive\r\nLOCATION: http://192.168.1.9:80/d.xml\r\nUSN: uuid:OTHER\r\n\r\n",
            'a search response' =>
                "HTTP/1.1 200 OK\r\nST: upnp:rootdevice\r\nUSN: uuid:OTHER\r\n\r\n",
            'an HTTP request that happens to arrive here' =>
                "GET /dlna/description.xml HTTP/1.1\r\nHost: x\r\nMAN: \"ssdp:discover\"\r\nST: ssdp:all\r\n\r\n",
            'empty' => '',
            'binary junk' => "\x00\x01\x02\xff\xfe",
        ];

        foreach ($cases as $label => $datagram) {
            self::assertSame(
                [],
                SsdpSearchResponder::matchedTargets($datagram, self::USN),
                "Must stay silent for: {$label}."
            );
        }
    }

    /**
     * CONSEQUENCE: `MAN: "ssdp:discover"` is mandatory.
     *
     * It is the field that separates a discovery search from any other M-SEARCH,
     * and dropping the requirement is the easy way to make this responder answer
     * traffic it should not.
     */
    public function test_the_man_header_is_required_and_must_say_ssdp_discover(): void
    {
        self::assertSame(
            [],
            SsdpSearchResponder::matchedTargets(
                "M-SEARCH * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\nST: ssdp:all\r\nMX: 3\r\n\r\n",
                self::USN
            ),
            'An M-SEARCH with no MAN header must not be answered.'
        );

        self::assertSame(
            [],
            SsdpSearchResponder::matchedTargets($this->search('ssdp:all', '3', '"ssdp:somethingelse"'), self::USN),
            'A MAN naming a different extension must not be answered.'
        );

        // Real clients send it quoted, unquoted, and in mixed case.
        foreach (['"ssdp:discover"', 'ssdp:discover', '"SSDP:Discover"'] as $man) {
            self::assertNotSame(
                [],
                SsdpSearchResponder::matchedTargets($this->search('ssdp:all', '3', $man), self::USN),
                "MAN spelled as {$man} is a real client's spelling and must be accepted."
            );
        }
    }

    /**
     * CONSEQUENCE: header names are matched case-insensitively.
     *
     * SSDP header names are case-insensitive and clients genuinely disagree.
     * A case-sensitive lookup makes this server invisible to half of them while
     * every other test still passes.
     */
    public function test_header_names_are_case_insensitive(): void
    {
        $datagram = "M-SEARCH * HTTP/1.1\r\nHost: 239.255.255.250:1900\r\n"
            . "Man: \"ssdp:discover\"\r\nSt: upnp:rootdevice\r\nMx: 2\r\n\r\n";

        self::assertSame(
            [SsdpSearchResponder::ST_ROOT_DEVICE],
            SsdpSearchResponder::matchedTargets($datagram, self::USN)
        );
        self::assertSame(2, SsdpSearchResponder::delayCapSeconds($datagram));
    }

    /**
     * CONSEQUENCE: a bare-LF datagram is still parsed.
     *
     * Embedded clients emit them. A strict `\r\n` split collapses the whole
     * block into one unparsable line and the device silently never answers.
     */
    public function test_bare_lf_line_endings_are_tolerated(): void
    {
        $datagram = "M-SEARCH * HTTP/1.1\nMAN: \"ssdp:discover\"\nST: upnp:rootdevice\nMX: 1\n\n";

        self::assertSame(
            [SsdpSearchResponder::ST_ROOT_DEVICE],
            SsdpSearchResponder::matchedTargets($datagram, self::USN)
        );
    }

    /**
     * CONSEQUENCE: a duplicated header cannot override the first occurrence.
     *
     * Otherwise a trailing `ST: ssdp:all` appended to a narrow search silently
     * widens what we disclose.
     */
    public function test_a_repeated_header_keeps_the_first_value(): void
    {
        $datagram = "M-SEARCH * HTTP/1.1\r\nMAN: \"ssdp:discover\"\r\n"
            . "ST: upnp:rootdevice\r\nST: ssdp:all\r\n\r\n";

        self::assertSame(
            [SsdpSearchResponder::ST_ROOT_DEVICE],
            SsdpSearchResponder::matchedTargets($datagram, self::USN)
        );
    }

    /**
     * CONSEQUENCE: an oversized datagram is truncated before it is walked.
     *
     * Unauthenticated UDP: the per-packet work must be bounded. The padding here
     * pushes the real `ST` past the cap, so an implementation that parsed the
     * whole 64 KB would ANSWER and fail this test.
     */
    public function test_an_oversized_datagram_is_bounded(): void
    {
        $padding = str_repeat("X-Pad: " . str_repeat('a', 100) . "\r\n", 700);
        $datagram = "M-SEARCH * HTTP/1.1\r\nMAN: \"ssdp:discover\"\r\n" . $padding . "ST: ssdp:all\r\n\r\n";

        self::assertGreaterThan(SsdpSearchResponder::MAX_DATAGRAM_BYTES, strlen($datagram));
        self::assertSame([], SsdpSearchResponder::matchedTargets($datagram, self::USN));
    }

    // ------------------------------------------------------------------
    // MX clamping
    // ------------------------------------------------------------------

    /**
     * CONSEQUENCE: MX is clamped to 5 and never goes negative.
     *
     * The clamp is what stops a hostile searcher pinning a pending timer far
     * into the future; the non-negativity is what stops `Timer::add()` throwing
     * `$timeInterval can not less than 0` inside the event loop.
     *
     * @dataProvider mxCases
     */
    public function test_mx_is_clamped(?string $mx, int $expected, string $why): void
    {
        self::assertSame($expected, SsdpSearchResponder::delayCapSeconds($this->search('ssdp:all', $mx)), $why);
    }

    /**
     * @return array<string, array{0: ?string, 1: int, 2: string}>
     */
    public static function mxCases(): array
    {
        return [
            'absent means answer now (unicast search)' => [null, 0, 'A missing MX must not delay the reply.'],
            'MX: 0'      => ['0', 0, 'Zero means answer immediately.'],
            'MX: 1'      => ['1', 1, 'A usable MX is honoured verbatim.'],
            'MX: 5'      => ['5', 5, 'Five is the ceiling and must survive it.'],
            'MX: 6'      => ['6', 5, 'Above the ceiling must be clamped DOWN, not honoured.'],
            'MX: 9999'   => ['9999', 5, 'A hostile MX must be clamped.'],
            'MX: -3'     => ['-3', 0, 'A negative MX would make Timer::add() throw.'],
            'MX: 2.5'    => ['2.5', 0, 'A non-integer MX is malformed, not "2".'],
            'MX: abc'    => ['abc', 0, 'Junk must not cast to a delay.'],
            'MX empty'   => ['', 0, 'An empty MX is malformed.'],
        ];
    }

    // ------------------------------------------------------------------
    // Response framing
    // ------------------------------------------------------------------

    /**
     * SPEC: the response is well-formed, header by header.
     *
     * Asserting "non-empty" or "contains LOCATION" would pass a response missing
     * `EXT` — which strict control points discard — so every REQUIRED field of
     * UPnP DA 1.0 §1.3.3 is checked for presence AND for a plausible value.
     */
    public function test_the_response_is_a_spec_conformant_search_response(): void
    {
        $location = 'http://10.0.0.1:8096/dlna/description.xml';
        $response = SsdpSearchResponder::buildResponse(
            SsdpSearchResponder::DEVICE_TYPE,
            self::USN,
            $location
        );

        // Status line: a search response is an HTTP RESPONSE, not a NOTIFY.
        self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $response);
        self::assertStringEndsWith("\r\n\r\n", $response, 'The header block must be terminated by a blank line.');
        self::assertStringNotContainsString('NOTIFY', $response, 'A search response must never be framed as a NOTIFY.');

        $headers = $this->parseHeaders($response);

        self::assertSame('max-age=1800', $headers['CACHE-CONTROL'] ?? null);
        self::assertSame('', $headers['EXT'] ?? null, 'EXT is REQUIRED and is a header-name-only field.');
        self::assertSame($location, $headers['LOCATION'] ?? null);
        self::assertSame(SsdpSearchResponder::DEVICE_TYPE, $headers['ST'] ?? null);
        self::assertSame(self::USN . '::' . SsdpSearchResponder::DEVICE_TYPE, $headers['USN'] ?? null);

        // SERVER: OS/version UPnP/1.0 product/version.
        self::assertMatchesRegularExpression(
            '#^\S+/\S+ UPnP/1\.0 Phlix/' . preg_quote(Version::STRING, '#') . '$#',
            $headers['SERVER'] ?? ''
        );

        // DATE: an RFC 1123 GMT stamp, and actually this century.
        $date = $headers['DATE'] ?? '';
        self::assertMatchesRegularExpression(
            '#^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$#',
            $date
        );
        self::assertEqualsWithDelta(time(), (int) strtotime($date), 120.0, 'DATE must be the current time.');
    }

    /**
     * CONSEQUENCE: `USN` is composed correctly for every target.
     *
     * `uuid:X::uuid:X` is malformed and is rejected outright by several
     * renderers, so the device-UUID target is the one case that must NOT be
     * suffixed. A naive `$usn . '::' . $target` produces exactly that.
     */
    public function test_the_usn_pairs_with_the_target_and_is_bare_for_the_device_uuid(): void
    {
        self::assertSame(self::USN, SsdpSearchResponder::usnFor(self::USN, self::USN));

        foreach (SsdpSearchResponder::advertisedTargets(self::USN) as $target) {
            $usn = SsdpSearchResponder::usnFor(self::USN, $target);

            if ($target === self::USN) {
                self::assertSame(self::USN, $usn);
                continue;
            }

            self::assertSame(self::USN . '::' . $target, $usn);
            self::assertStringNotContainsString('::uuid:', $usn);
        }
    }

    /**
     * CONSEQUENCE: what we ANSWER matches what the device description DECLARES.
     *
     * These are two hand-maintained lists in different files. If the responder
     * claims a `MediaRenderer` device type, or omits `ConnectionManager`, a
     * control point's search result and the document it then fetches disagree,
     * and the failure surfaces as "found it, cannot open it".
     */
    public function test_the_advertised_targets_agree_with_the_device_description(): void
    {
        $device = new DlnaDevice(
            'uuid:PHLIXSERVER',
            DlnaDevice::TYPE_SERVER,
            'Phlix',
            'http://10.0.0.1:8096',
            8096
        );

        self::assertSame(
            $device->getDeviceTypeUrn(),
            SsdpSearchResponder::DEVICE_TYPE,
            'The device type we answer to must be the device type we describe ourselves as.'
        );

        $declared = [];
        foreach ($device->getServices() as $service) {
            if (isset($service['serviceType'])) {
                $declared[] = $service['serviceType'];
            }
        }
        sort($declared);

        $answered = array_values(array_filter(
            SsdpSearchResponder::advertisedTargets(self::USN),
            static fn (string $t): bool => str_contains($t, ':service:')
        ));
        sort($answered);

        self::assertSame($declared, $answered, 'Every declared service must be searchable, and no others.');
    }

    /**
     * The advertiser's USN constant is the one this responder is fed.
     *
     * Cheap, but it is the join between the two classes: if `SsdpAdvertiser::USN`
     * changed, every USN in this file would still pass while production
     * advertised a different identity in its NOTIFY than in its search reply.
     */
    public function test_the_fixture_usn_is_the_advertisers_real_usn(): void
    {
        self::assertSame(self::USN, SsdpAdvertiser::USN);
    }

    /**
     * @return array<string, string> UPPERCASE header name => value
     */
    private function parseHeaders(string $response): array
    {
        $out = [];
        $lines = explode("\r\n", trim($response));
        array_shift($lines);

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            self::assertNotFalse($colon, "Malformed header line: {$line}");
            $out[strtoupper(substr($line, 0, (int) $colon))] = trim(substr($line, (int) $colon + 1));
        }

        return $out;
    }
}
