<?php

/**
 * Phlix media server component: tests for the Network local-address probe.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Network\LocalAddressProbe;
use ReflectionMethod;

/**
 * SH-007 — one-home pinning for {@see LocalAddressProbe}.
 *
 * ## WHAT THIS FILE OWNS
 *
 * The behavior table of the extracted helpers, and the STRUCTURAL law that the
 * discovery logic exists exactly once in `src/`. The coroutine-branch pins
 * (S146/S197) stay where they always were —
 * `tests/Unit/Network/CoroutineSocketFailureContainmentTest.php` drives each
 * class's own private methods through reflection, so it pins the delegates as
 * faithfully as it pinned the copies, and ext-swoole is what gates it (absent
 * on a plain CLI box, present in CI — the load-time `Class "Swoole\Coroutine\Socket"
 * not found` here is pre-existing behavior of that file, not lane damage).
 *
 * ## MEASURED filter_var() TRUTH (PHP 8.3.6, this host)
 *
 * The accept/reject table below was produced by running
 * `filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)`
 * over every candidate on 2026-11-10 and pinning what came out — including the
 * non-obvious rows: PHP's reserved ranges do NOT cover the TEST-NET
 * documentation blocks (203.0.113.9/198.51.100.7/192.0.2.1 all ACCEPT), CGNAT
 * 100.64.0.0/10 ACCEPTS. An IPv6 zone id (`fe80::1%lo`) and a bracketed host
 * (`[2001:db8::1]`) are not valid address literals and reject; `0.0.0.0` and
 * both loopback classes reject. Re-measure, never re-reason, if this table
 * ever goes red.
 *
 * One row is deliberately NOT in the fixed table: the IPv6 documentation
 * space 2001:db8::/32. PHP 8.3.6 (measured here) REJECTS it under
 * FILTER_FLAG_NO_RES_RANGE while the CI runner's PHP line ACCEPTS it — the
 * reserved-range list differs across patch levels, so pinning either verdict
 * would be an environment flake (CI run 42795623411, 2026-11-10, is the
 * measurement). The dedicated test below pins the scan to relay whatever the
 * runtime's own filter decides for that literal, which still convicts a scan
 * that swallows or swaps the flags.
 */
class LocalAddressProbeTest extends TestCase
{
    /**
     * The edge shapes named in the SH-007 brief, as a truth table.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function addressShapes(): array
    {
        return [
            // Accepted (measured 2026-11-10, PHP 8.3.6)
            'public dns' => ['8.8.8.8', true],
            'test-net-3' => ['203.0.113.9', true],
            'test-net-2' => ['198.51.100.7', true],
            'test-net-1' => ['192.0.2.1', true],
            'cgnat' => ['100.64.0.1', true],
            'adjacent-outside-priv' => ['172.15.0.1', true],
            'v6-global' => ['2606:4700:4700::1111', true],
            // Rejected (measured 2026-11-10, PHP 8.3.6)
            'rfc1918-10' => ['10.1.2.3', false],
            'rfc1918-172-16' => ['172.16.0.1', false],
            'rfc1918-192-168' => ['192.168.1.1', false],
            'v4-loopback' => ['127.0.0.1', false],
            'v6-loopback' => ['::1', false],
            'this-host-zero' => ['0.0.0.0', false],
            'link-local-v4' => ['169.254.1.1', false],
            'link-local-v6' => ['fe80::1', false],
            'v6-zone-id' => ['fe80::1%lo', false],
            'bracketed-host' => ['[2001:db8::1]', false],
            'not-an-ip' => ['not-an-ip', false],
            'empty-string' => ['', false],
        ];
    }

    /**
     * The scan leg decides each candidate in isolation: accept = returned,
     * reject = skipped (null out of a one-address table).
     */
    #[DataProvider('addressShapes')]
    public function test_the_interface_scan_decides_each_edge_shape(string $address, bool $accepted): void
    {
        $table = [['unicast' => [['address' => $address]]]];

        $result = LocalAddressProbe::localInterfaceAddress($table);

        if ($accepted) {
            $this->assertSame($address, $result);
            return;
        }

        $this->assertNull($result, $address . ' must not be treated as a usable local address');
    }

    /**
     * The 2001:db8::/32 documentation space: accepted or rejected depending
     * on the runtime's PHP patch level (see the class header). What is fixed
     * is that the scan RELAYS the filter's own verdict — dropping or swapping
     * either FILTER_FLAG_* arm makes this disagree on at least one line, and
     * the version-stable rejections above carry the flag-strip convictions.
     */
    public function test_the_v6_documentation_space_relays_the_runtime_filter_verdict(): void
    {
        $address = '2001:db8::1';
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        $usable = filter_var($address, FILTER_VALIDATE_IP, $flags) !== false;

        $result = LocalAddressProbe::localInterfaceAddress([['unicast' => [['address' => $address]]]]);

        if ($usable) {
            $this->assertSame($address, $result, $address . ' is usable on this PHP line; the scan must relay that');
            return;
        }

        $this->assertNull($result, $address . ' is filtered out on this PHP line; the scan must relay that');
    }

    public function test_the_scan_returns_the_first_acceptable_address_in_table_order(): void
    {
        $table = [
            ['unicast' => [['address' => '192.168.1.42'], ['address' => '203.0.113.9']]],
            ['unicast' => [['address' => '198.51.100.7']]],
        ];

        $this->assertSame('203.0.113.9', LocalAddressProbe::localInterfaceAddress($table));
    }

    /**
     * Malformed interface rows are skipped, never fatal — the table shape is
     * ambient data, so the loop must degrade like the original copies did.
     */
    public function test_the_scan_survives_malformed_interface_rows(): void
    {
        $table = [
            'not-an-array',
            ['no-unicast-key' => true],
            ['unicast' => 'not-an-array'],
            ['unicast' => [['address' => 42], ['address' => null], 'scalar-entry', []]],
            ['unicast' => [['address' => '10.0.0.5']]],
            ['unicast' => [['address' => '198.51.100.7']]],
        ];

        $this->assertSame('198.51.100.7', LocalAddressProbe::localInterfaceAddress($table));
    }

    public function test_an_empty_injected_table_finds_nothing(): void
    {
        $this->assertNull(LocalAddressProbe::localInterfaceAddress([]));
    }

    public function test_in_coroutine_is_false_on_the_main_stack(): void
    {
        // True whether or not ext-swoole is loaded: PHPUnit never runs inside a
        // coroutine, and getCid() on the main stack is -1 (measured, S170). A
        // flipped condition (< 0) reddens this on BOTH venues, swoole present or
        // not — which is why this leg lives in the locally-runnable file.
        $this->assertFalse(LocalAddressProbe::inCoroutine());
    }

    public function test_the_udp_probe_never_builds_a_coroutine_socket_outside_a_coroutine(): void
    {
        $factoryCalls = 0;
        $factory = static function (int $type) use (&$factoryCalls) {
            $factoryCalls++;
            throw new \LogicException('unreachable on the main stack');
        };

        $result = LocalAddressProbe::udpProbeAddress($factory);

        $this->assertSame(0, $factoryCalls, 'the guarded branch is behind inCoroutine()');
        $this->assert_not_a_blank_string($result);
    }

    public function test_the_probe_fallback_answer_is_a_valid_ipv4_literal(): void
    {
        // The probe dials 8.8.8.8 (AF_INET), so stream_socket_get_name() answers
        // with 'a.b.c.d:port'. This kills a strrpos()->strpos() mutation on any
        // box with outbound DNS; on a network-isolated box the leg below is the
        // null answer instead, exactly like the pre-existing env-gated family.
        $result = LocalAddressProbe::udpProbeAddress(
            static fn (int $type) => throw new \LogicException('unreachable on the main stack')
        );

        if ($result === null) {
            $this->addToAssertionCount(1);
            return;
        }

        // $result is narrowed to string by the null early-exit above; what this
        // leg actually pins is non-blankness and the clean IPv4 literal shape.
        $this->assertNotSame('', $result);
        $this->assertTrue(
            filter_var($result, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'host part after the port strip must be a clean IPv4 literal, got ' . $result
        );
    }

    /**
     * The scan-then-probe order lives in the four `getLocalIpAddress()` bodies
     * (exactly where the originals kept it — SH-007 moved the step-1 TABLE and
     * the step-2 PROBE, not the step). On a single-homed box the two steps
     * answer the same address, so value equality cannot distinguish an order
     * flip; the structural pin convicts one on any venue, all four files.
     */
    public function test_the_delegates_scan_before_they_probe(): void
    {
        $files = [
            'src/Network/NatPmpClient.php',
            'src/Network/StunClient.php',
            'src/Network/UpnpIgdClient.php',
            'src/Network/PortForwardService.php',
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $start = strpos($source, 'function getLocalIpAddress');
            $this->assertIsInt($start, $file . ' must keep getLocalIpAddress()');
            $body = substr($source, $start, strpos($source, 'function', $start + 8) - $start);

            $scanAt = strpos($body, 'LocalAddressProbe::localInterfaceAddress()');
            $probeAt = strpos($body, '$this->getLocalIpViaUdpSocket()');
            $this->assertIsInt($scanAt, $file . ' must consult the shared interface scan');
            $this->assertIsInt($probeAt, $file . ' must fall back to its own probe');
            $this->assertLessThan($probeAt, $scanAt, $file . ' must scan before it probes');
        }
    }

    /**
     * The fallback parse must split the socket name at the LAST colon: a v6
     * answer like `[2001:db8::1]:40126` truncates to a blank host under the
     * first-colon variant. Measured blind spot: on this venue the live leg can
     * only answer a single-colon IPv4 name, where strrpos and strpos coincide —
     * so the shape is pinned on the source instead (same genre as the allow-list
     * pins in this file), keeping the byte-ident port of the four copies exact.
     */
    public function test_the_fallback_parse_splits_the_socket_name_at_the_last_colon(): void
    {
        $source = (string) file_get_contents('src/Network/LocalAddressProbe.php');

        $this->assertSame(
            1,
            substr_count($source, '$colonPos = strrpos($localAddr'),
            'the blocking fallback must parse via strrpos — the shape copied verbatim from the four family sources'
        );
        $this->assertSame(
            0,
            substr_count($source, '$colonPos = strpos($localAddr'),
            'a first-colon parse would blank IPv6 socket names'
        );
    }

    /**
     * The dedup law: the interface-scan filter family — the exact
     * `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` gate the four
     * copies shared — now rides on exactly one DISCOVERY implementation.
     * Red before the extraction (the four byte-identical copies carried it
     * themselves), green after; the two allow-listed neighbours use the same
     * filter constant pair for unrelated contracts — SsrfGuard validates
     * caller-supplied URLs and DeviceRegistry filters DLNA announce targets,
     * neither scans interfaces for the local address — so the pin is a
     * measured allow-list: any NEW carrier reddens it, which is what makes
     * this the regression pin the card asked for.
     */
    public function test_the_discovery_filter_family_lives_on_the_measured_allow_list(): void
    {
        $carriers = $this->src_files_calling('FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE');

        $this->assertSame(
            [
                'src/Common/Net/SsrfGuard.php',
                'src/Dlna/DeviceRegistry.php',
                'src/Network/LocalAddressProbe.php',
            ],
            $carriers,
            'SH-007 consolidated the byte-identical discovery scan; a new file carrying the same '
            . 'filter gate is a re-copy of the bug this row closed'
        );
    }

    /**
     * The raw table read is allow-listed, not single-file: `PortForwardService::
     * discoverGateway()` legitimately walks `net_get_interfaces()` too, but with
     * a DIFFERENT contract (LAN-prefix `str_starts_with` matching to seed the
     * gateway TCP probe, `'192.168.1.1'` default) — it was never part of the
     * byte-identical family and consolidating it would change behavior.
     */
    public function test_the_interface_table_read_stays_on_the_allow_list(): void
    {
        $carriers = $this->src_files_calling('net_get_interfaces');

        sort($carriers);
        $this->assertSame(
            ['src/Network/LocalAddressProbe.php', 'src/Network/PortForwardService.php'],
            $carriers
        );
    }

    /**
     * The DNS-probe literal is allow-listed: the consolidated helper plus
     * SsdpAdvertiser's distinct detectLocalIp() (public static, '127.0.0.1'
     * default, explode-based parse — a different contract that the SH-007
     * card did NOT scope in; see the lane register).
     */
    public function test_the_udp_dns_probe_literal_stays_on_the_allow_list(): void
    {
        $carriers = $this->src_files_calling("'8.8.8.8'");

        sort($carriers);
        $this->assertSame(
            ['src/Dlna/SsdpAdvertiser.php', 'src/Network/LocalAddressProbe.php'],
            $carriers
        );
    }

    /**
     * The former carriers still expose the exact private surface the
     * containment family reflects into — the extraction moved logic, not API.
     * `NatPmpClient::inCoroutine()` is the documented exception: after the pair
     * delegates it had ZERO call sites left in its own class (the other three
     * keep theirs — they still fork for probePort/asyncHttpGet/gateway-probe
     * legs outside this family), so the duplicate was deleted rather than kept
     * as a dead delegate.
     */
    public function test_the_four_delegates_keep_their_private_method_surface(): void
    {
        $methods = [
            \Phlix\Network\NatPmpClient::class => ['getLocalIpAddress', 'getLocalIpViaUdpSocket'],
            \Phlix\Network\StunClient::class => ['getLocalIpAddress', 'getLocalIpViaUdpSocket', 'inCoroutine'],
            \Phlix\Network\UpnpIgdClient::class => ['getLocalIpAddress', 'getLocalIpViaUdpSocket', 'inCoroutine'],
            \Phlix\Network\PortForwardService::class => ['getLocalIpAddress', 'getLocalIpViaUdpSocket', 'inCoroutine'],
        ];

        foreach ($methods as $class => $names) {
            foreach ($names as $method) {
                $this->assertTrue(
                    method_exists($class, $method),
                    $class . '::' . $method . ' must survive as the delegate the S197 family reflects into'
                );
            }
        }

        $this->assertFalse(
            method_exists(\Phlix\Network\NatPmpClient::class, 'inCoroutine'),
            'the zero-call-site copy must NOT come back as a dead delegate'
        );
    }

    /**
     * On the main stack every delegate collapses to the same shared non-coroutine
     * answer: a null-or-IPv4 string, and the socket seam is never touched. This
     * is the class-level behavioral-identity leg; the coroutine-level one is the
     * unchanged swoole-gated containment family.
     */
    public function test_each_delegate_answers_exactly_like_the_shared_helper(): void
    {
        $classes = [
            \Phlix\Network\NatPmpClient::class,
            \Phlix\Network\StunClient::class,
            \Phlix\Network\UpnpIgdClient::class,
            \Phlix\Network\PortForwardService::class,
        ];

        $scan = LocalAddressProbe::localInterfaceAddress();
        foreach ($classes as $class) {
            $client = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

            $probe = (new ReflectionMethod($class, 'getLocalIpViaUdpSocket'))->invoke($client);
            $this->assert_not_a_blank_string($probe, $class . ' probe leg');

            $local = (new ReflectionMethod($class, 'getLocalIpAddress'))->invoke($client);
            $this->assert_not_a_blank_string($local, $class . ' scan leg');
            if ($scan !== null) {
                $this->assertSame($scan, $local, $class . ' must return the shared scan answer');
            }
        }
    }

    /**
     * Blank-string answers would silently poison callers that treat a non-null
     * address as usable (PortForwardService::autoConfigure() and friends).
     */
    private function assert_not_a_blank_string(mixed $value, string $context = ''): void
    {
        if ($value === null) {
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertIsString($value, $context);
        $this->assertNotSame('', $value, 'the probe must never answer with the empty string — ' . $context);
    }

    /**
     * Files under src/ whose STRIPPED (tokenized) code contains $needle —
     * comments mentioning it can never be counted, per the fork-guard idiom.
     *
     * @return list<string> Paths relative to the repo root.
     */
    private function src_files_calling(string $needle): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator('src', \FilesystemIterator::SKIP_DOTS)
        );
        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        foreach ($files as $file) {
            $tmp = tempnam(sys_get_temp_dir(), 'w22l2_probe_');
            file_put_contents($tmp, (string) file_get_contents($file));
            $code = php_strip_whitespace($tmp);
            unlink($tmp);

            if ($code !== '' && str_contains($code, $needle)) {
                $found[] = $file;
            }
        }

        return $found;
    }
}
