<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Admin\SettingsRepository;
use Phlix\Config\EffectiveConfig;
use Phlix\Server\Http\Middleware\DlnaAllowlistMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Consequence tests for the DLNA inbound IP allowlist.
 *
 * DLNA/UPnP has no authentication, so this middleware is the ONLY thing between
 * a caller and the whole library once the CDS routes are enabled. The single
 * most important property under test is that an EMPTY allowlist is never
 * "allow everyone" — the security defect this step exists to prevent — so that
 * case is asserted explicitly and from multiple angles.
 *
 * Middleware semantics are the behaviour under test: returning `null` lets the
 * request through, returning a {@see Response} (403) short-circuits it. Where a
 * pure policy decision is asserted, {@see DlnaAllowlistMiddleware::isAllowed()}
 * is called directly to keep the input (the exact client IP) unambiguous.
 *
 */
final class DlnaAllowlistMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EffectiveConfig::reset();
    }

    protected function tearDown(): void
    {
        EffectiveConfig::reset();
        parent::tearDown();
    }

    /**
     * A settings repository whose getEffective() answers from the given map.
     *
     * @param array<string, mixed> $values Dotted key => effective value.
     */
    private function settingsReturning(array $values): SettingsRepository
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturnCallback(
            static fn (string $key): mixed => $values[$key] ?? null
        );

        return $settings;
    }

    /**
     * Middleware in the shipped default posture: empty allowlist, LAN-only.
     */
    private function withDefaults(): DlnaAllowlistMiddleware
    {
        return new DlnaAllowlistMiddleware($this->settingsReturning([
            'dlna.allowed_cidrs'   => [],
            'dlna.restrict_to_lan' => true,
        ]));
    }

    /**
     * CONSEQUENCE: with the shipped defaults, loopback and every private/local
     * range are admitted.
     *
     * @dataProvider lanAddresses
     */
    public function test_default_posture_admits_lan_addresses(string $ip): void
    {
        self::assertTrue(
            $this->withDefaults()->isAllowed($ip),
            sprintf('%s is a LAN address and must be admitted by the default posture.', $ip),
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function lanAddresses(): iterable
    {
        yield 'IPv4 loopback'        => ['127.0.0.1'];
        yield 'RFC1918 10/8'         => ['10.4.5.6'];
        yield 'RFC1918 172.16/12'    => ['172.20.1.1'];
        yield 'RFC1918 192.168/16'   => ['192.168.1.50'];
        yield 'IPv4 link-local'      => ['169.254.10.10'];
        yield 'IPv6 loopback'        => ['::1'];
        yield 'IPv6 ULA fc00::/7'    => ['fd12:3456:789a::1'];
        yield 'IPv6 link-local'      => ['fe80::1'];
    }

    /**
     * CONSEQUENCE: with the shipped defaults, a PUBLIC address is rejected.
     *
     * This is the whole point — an off-LAN caller cannot browse the library.
     *
     * @dataProvider publicAddresses
     */
    public function test_default_posture_rejects_public_addresses(string $ip): void
    {
        self::assertFalse(
            $this->withDefaults()->isAllowed($ip),
            sprintf('%s is a public address and must be rejected by the default posture.', $ip),
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function publicAddresses(): iterable
    {
        yield 'public IPv4'            => ['8.8.8.8'];
        yield 'public IPv4 (2)'        => ['203.0.113.7'];
        yield 'CGNAT 100.64/10'        => ['100.64.0.1'];
        yield 'public IPv6'            => ['2001:4860:4860::8888'];
    }

    /**
     * THE CORE SECURITY PROPERTY: an empty allowlist is NEVER "allow all".
     *
     * With no CIDRs configured and the default LAN restriction, a public caller
     * is denied. A middleware that treated "empty" as "open" — the exact bug
     * this step prevents — would admit 8.8.8.8 here.
     *
     * Mutation-verified in intent: making isAllowed() return true on an empty
     * allowlist fails this test.
     */
    public function test_empty_allowlist_is_never_allow_all(): void
    {
        $middleware = $this->withDefaults();

        self::assertFalse($middleware->isAllowed('8.8.8.8'));
        self::assertTrue($middleware->isAllowed('192.168.0.9'));
    }

    /**
     * CONSEQUENCE: an explicit allowlist entry admits an otherwise-rejected
     * (public) address.
     */
    public function test_explicit_allowlist_entry_admits_a_public_address(): void
    {
        $middleware = new DlnaAllowlistMiddleware($this->settingsReturning([
            'dlna.allowed_cidrs'   => ['203.0.113.0/24'],
            'dlna.restrict_to_lan' => true,
        ]));

        self::assertTrue($middleware->isAllowed('203.0.113.42'), 'A listed public range must be admitted.');
        self::assertFalse($middleware->isAllowed('198.51.100.1'), 'An unlisted public address stays rejected.');
        // The LAN default is additive, not replaced, when restrict_to_lan is on.
        self::assertTrue($middleware->isAllowed('192.168.1.1'), 'LAN addresses remain admitted alongside the allowlist.');
    }

    /**
     * CONSEQUENCE: with restrict_to_lan OFF, the allowlist is the ONLY gate —
     * LAN addresses that are not explicitly listed are rejected.
     */
    public function test_restrict_to_lan_off_makes_the_allowlist_the_only_gate(): void
    {
        $middleware = new DlnaAllowlistMiddleware($this->settingsReturning([
            'dlna.allowed_cidrs'   => ['203.0.113.0/24'],
            'dlna.restrict_to_lan' => false,
        ]));

        self::assertTrue($middleware->isAllowed('203.0.113.42'), 'A listed address is admitted.');
        self::assertFalse($middleware->isAllowed('192.168.1.1'), 'With LAN restriction off, unlisted LAN is rejected.');
        self::assertFalse($middleware->isAllowed('127.0.0.1'), 'With LAN restriction off, loopback is not special-cased.');
    }

    /**
     * THE OTHER HALF OF THE SECURITY PROPERTY: restrict_to_lan OFF + empty
     * allowlist denies EVERYONE — a deliberate locked-down state, still not
     * "allow all".
     */
    public function test_lan_off_and_empty_allowlist_denies_everyone(): void
    {
        $middleware = new DlnaAllowlistMiddleware($this->settingsReturning([
            'dlna.allowed_cidrs'   => [],
            'dlna.restrict_to_lan' => false,
        ]));

        foreach (['127.0.0.1', '192.168.1.1', '8.8.8.8', '::1'] as $ip) {
            self::assertFalse($middleware->isAllowed($ip), sprintf('%s must be denied in the locked-down state.', $ip));
        }
    }

    /**
     * CONSEQUENCE: an IPv4-mapped IPv6 peer is collapsed to its IPv4 form before
     * matching (reusing the SsrfGuard helper), so a dual-stack listener that
     * reports ::ffff:127.0.0.1 for a loopback client still admits it.
     */
    public function test_ipv4_mapped_ipv6_peer_is_collapsed_before_matching(): void
    {
        $middleware = $this->withDefaults();

        self::assertTrue($middleware->isAllowed('::ffff:127.0.0.1'), 'Mapped loopback must be admitted.');
        self::assertTrue($middleware->isAllowed('::ffff:192.168.1.5'), 'Mapped LAN must be admitted.');
        self::assertFalse($middleware->isAllowed('::ffff:8.8.8.8'), 'Mapped public must be rejected.');
    }

    /**
     * CONSEQUENCE: an unparseable address fails CLOSED (denied), never open.
     */
    public function test_malformed_client_ip_is_rejected(): void
    {
        $middleware = $this->withDefaults();

        foreach (['', 'not-an-ip', '999.999.999.999', '0.0.0.0/0'] as $junk) {
            self::assertFalse($middleware->isAllowed($junk), sprintf('%s must not be admitted.', var_export($junk, true)));
        }
    }

    /**
     * CONSEQUENCE: a malformed restrict_to_lan override does NOT disable the LAN
     * restriction. Only an explicit boolean false turns it off; junk keeps the
     * safe default in force.
     *
     * The inputs DISCRIMINATE: '' and 0 are values a (bool) cast or a bare `!`
     * would read as OFF, which would then reject LAN traffic. Only real `false`
     * disables the restriction.
     */
    public function test_malformed_restrict_to_lan_override_keeps_the_restriction(): void
    {
        foreach (['', 0, 'no', null] as $junk) {
            $middleware = new DlnaAllowlistMiddleware($this->settingsReturning([
                'dlna.allowed_cidrs'   => [],
                'dlna.restrict_to_lan' => $junk,
            ]));

            self::assertTrue(
                $middleware->isAllowed('192.168.1.1'),
                sprintf('A %s override must not disable the LAN restriction.', var_export($junk, true)),
            );
        }
    }

    /**
     * CONSEQUENCE: __invoke returns null (continue) for an admitted peer and a
     * 403 Response (short-circuit) for a rejected one — reading the peer IP off
     * the Request via getTrustedClientIp().
     */
    public function test_invoke_passes_lan_peer_and_blocks_public_peer(): void
    {
        $middleware = $this->withDefaults();

        $lan = new Request();
        $lan->remoteIp = '192.168.1.50';
        self::assertNull($middleware($lan), 'A LAN peer must be routed through.');

        $public = new Request();
        $public->remoteIp = '8.8.8.8';
        $response = $middleware($public);
        self::assertInstanceOf(Response::class, $response, 'A public peer must be short-circuited.');
        self::assertSame(403, $response->statusCode);
        /** @var array{code?: string} $body */
        $body = json_decode($response->body, true);
        self::assertSame('dlna.forbidden', $body['code'] ?? null);
    }

    /**
     * CONSEQUENCE: a spoofed leftmost X-Forwarded-For from a direct (untrusted)
     * peer cannot fake a LAN address — getTrustedClientIp ignores forwarding
     * headers from an untrusted peer, so the real (public) peer decides.
     */
    public function test_spoofed_forwarded_for_from_untrusted_peer_is_ignored(): void
    {
        $middleware = $this->withDefaults();

        $req = new Request();
        $req->remoteIp = '8.8.8.8';
        $req->headers = ['X-FORWARDED-FOR' => '192.168.1.1'];

        self::assertInstanceOf(
            Response::class,
            $middleware($req),
            'A forged XFF from a public peer must not smuggle in a LAN identity.',
        );
    }

    /**
     * SECURITY (positive control for the spoof test): a request arriving through
     * a TRUSTED loopback reverse proxy (peer = 127.0.0.1, the shipped nginx /
     * HAProxy topology) has its X-Forwarded-For honoured, so the REAL upstream
     * client decides — a PUBLIC real client is DENIED even though the direct peer
     * is loopback (which would otherwise be a LAN address).
     *
     * This is the load-bearing reason the middleware reads getTrustedClientIp()
     * and not the raw peer: a naive `remoteIp` check would treat every request
     * behind the loopback proxy as loopback/LAN and wave the whole internet in.
     * This test FAILS if the middleware ever regresses to the raw peer address.
     */
    public function test_public_client_behind_trusted_loopback_proxy_is_denied(): void
    {
        $middleware = $this->withDefaults();

        $req = new Request();
        $req->remoteIp = '127.0.0.1';                 // trusted loopback proxy hop
        $req->headers = ['X-Forwarded-For' => '8.8.8.8']; // real upstream client

        $response = $middleware($req);
        self::assertInstanceOf(
            Response::class,
            $response,
            'A public real client behind the loopback proxy must be denied, not admitted as loopback.',
        );
        self::assertSame(403, $response->statusCode);
    }

    /**
     * SECURITY (positive control): the same trusted-loopback-proxy path admits a
     * LAN real client — X-Forwarded-For IS honoured when (and only when) the
     * direct peer is a trusted proxy, so a genuine LAN device fronted by the
     * reverse proxy still reaches the CDS endpoints.
     */
    public function test_lan_client_behind_trusted_loopback_proxy_is_admitted(): void
    {
        $middleware = $this->withDefaults();

        $req = new Request();
        $req->remoteIp = '127.0.0.1';                        // trusted loopback proxy hop
        $req->headers = ['X-Forwarded-For' => '192.168.1.5']; // real upstream client (LAN)

        self::assertNull(
            $middleware($req),
            'A LAN real client behind the trusted loopback proxy must be routed through.',
        );
    }

    /**
     * INTEGRATION: wired onto a real {@see Router} group EXACTLY as
     * {@see \Phlix\Server\Core\Application::loadCdsRoutes()} attaches it — an
     * empty-prefix group with `[$middleware]` — a peer OUTSIDE the allowlist gets
     * a 403 and the CDS route handler NEVER runs; a LAN peer reaches the handler.
     *
     * This pins acceptance behaviour (1) at the route level, not just at
     * __invoke: it proves the group middleware short-circuits dispatch before the
     * ContentDirectory handler is ever invoked.
     */
    public function test_wired_on_a_router_group_blocks_off_lan_peer_before_the_handler(): void
    {
        $middleware = $this->withDefaults();

        $handlerRuns = 0;
        $router = new Router();
        $router->group('', static function (Router $r) use (&$handlerRuns): void {
            // Stand-in for a CDS route (e.g. /description.xml).
            $r->get('/description.xml', static function () use (&$handlerRuns): Response {
                $handlerRuns++;
                return (new Response())->text('<root/>');
            });
        }, [$middleware]);

        // Off-LAN peer → 403, handler must not run.
        $public = new Request();
        $public->method = 'GET';
        $public->path = '/description.xml';
        $public->remoteIp = '8.8.8.8';
        $blocked = $router->dispatch($public);
        self::assertSame(403, $blocked->statusCode, 'Off-LAN peer must be blocked on the CDS route.');
        self::assertSame(0, $handlerRuns, 'The CDS handler must NOT run for a blocked peer.');

        // LAN peer → route handler runs.
        $lan = new Request();
        $lan->method = 'GET';
        $lan->path = '/description.xml';
        $lan->remoteIp = '192.168.1.50';
        $ok = $router->dispatch($lan);
        self::assertSame(200, $ok->statusCode, 'LAN peer must reach the CDS route.');
        self::assertSame(1, $handlerRuns, 'The CDS handler must run exactly once for a LAN peer.');
    }

    /**
     * CONSEQUENCE: with no settings repository wired, the overlaid config file
     * decides — and it must enforce the same policy.
     */
    public function test_config_file_decides_when_no_settings_repository(): void
    {
        $this->bootstrapDlnaConfig(['allowed_cidrs' => ['203.0.113.0/24'], 'restrict_to_lan' => false]);

        $middleware = new DlnaAllowlistMiddleware(null);

        self::assertTrue($middleware->isAllowed('203.0.113.9'), 'Config allowlist must admit a listed range.');
        self::assertFalse($middleware->isAllowed('192.168.1.1'), 'Config restrict_to_lan=false rejects unlisted LAN.');
    }

    /**
     * CONSEQUENCE: a throwing settings store falls back to the config file
     * rather than 500ing — and the file default (LAN-only) still gates.
     */
    public function test_failing_settings_store_falls_back_to_the_config_file(): void
    {
        $this->bootstrapDlnaConfig(['allowed_cidrs' => [], 'restrict_to_lan' => true]);

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new \RuntimeException('db down'));

        $middleware = new DlnaAllowlistMiddleware($settings);

        self::assertTrue($middleware->isAllowed('192.168.1.1'), 'Fallback must still admit LAN.');
        self::assertFalse($middleware->isAllowed('8.8.8.8'), 'Fallback must still reject public.');
    }

    /**
     * CONSEQUENCE: an absent dlna config (fresh install, no keys) still fails
     * closed for public addresses and admits LAN — the shipped default posture.
     */
    public function test_absent_config_keys_default_to_lan_only(): void
    {
        $this->bootstrapDlnaConfig([]); // no allowed_cidrs, no restrict_to_lan

        $middleware = new DlnaAllowlistMiddleware(null);

        self::assertTrue($middleware->isAllowed('10.0.0.1'), 'Missing keys must default to LAN-allowed.');
        self::assertFalse($middleware->isAllowed('8.8.8.8'), 'Missing keys must NOT default to allow-all.');
    }

    /**
     * LOCK-IN: the shipped config/dlna.php ships the LAN-only default posture
     * (empty allowlist + restrict_to_lan true). A regression here would silently
     * change the out-of-the-box security posture.
     */
    public function test_shipped_config_ships_lan_only_defaults(): void
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 5) . '/config/dlna.php';

        self::assertArrayHasKey('allowed_cidrs', $config);
        self::assertSame([], $config['allowed_cidrs'], 'allowed_cidrs must ship empty.');
        self::assertArrayHasKey('restrict_to_lan', $config);
        self::assertTrue($config['restrict_to_lan'], 'restrict_to_lan must ship true (LAN-only default).');
    }

    /**
     * Bootstrap the overlay against a throwaway `config/dlna.php`.
     *
     * @param array<string, mixed> $dlna
     */
    private function bootstrapDlnaConfig(array $dlna): void
    {
        $dir = sys_get_temp_dir() . '/phlix_dlna_' . uniqid('', true) . '/config';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/dlna.php', '<?php return ' . var_export($dlna, true) . ";\n");

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        EffectiveConfig::bootstrap($db, null, $dir);
    }
}
