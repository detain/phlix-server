<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DateTimeImmutable;
use Phlix\Access\AccessScheduleService;
use Phlix\Auth\SignedUrl;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserProfileManager;
use Phlix\Hub\RelayIdentityResolver;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\TranscodeController;
use Phlix\Server\Http\Middleware\AccessScheduleMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * The global access-schedule gate (Application::scheduleGateFor()).
 *
 * The gate used to read RequestContext, which only route-level AuthMiddleware publishes,
 * and it runs before routing. So for routed requests it checked nothing. These cases pin
 * that it now reads the request's own identity, refuses an authenticated routed request
 * in its window (including HLS/DASH segments), fails closed when the profile cannot be
 * resolved, and leaves unauthenticated and public requests exactly as they were.
 */
final class ApplicationScheduleGateTest extends TestCase
{
    private const SIGNED_SECRET = 'application-schedule-gate-test-secret';

    protected function setUp(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SIGNED_SECRET);
        SignedUrl::resetSharedForTesting();
    }

    protected function tearDown(): void
    {
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        putenv('PHLIX_SIGNED_URL_SECRET');
        SignedUrl::resetSharedForTesting();
    }

    private static function dayAbbrev(DateTimeImmutable $when): string
    {
        return strtolower(substr($when->format('D'), 0, 3));
    }

    /**
     * A schedule covering the whole of today for profile p1, so p1 is locked out now.
     *
     * @return list<array<string, mixed>>
     */
    private function lockedOutP1(): array
    {
        return [[
            'id' => 1,
            'profile_id' => 'p1',
            'name' => 'Bedtime',
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => self::dayAbbrev(new DateTimeImmutable()),
            'is_active' => 1,
        ]];
    }

    /**
     * The schedule middleware over a scripted connection. The rows are filtered by the
     * profile the statement is bound to, as the real query is. With $expectNoQuery the
     * connection must never be touched at all.
     *
     * @param list<array<string, mixed>>  $rows          access_schedules rows the DB holds.
     * @param array<string, mixed>|null   $activeProfile The account's active profile.
     */
    private function schedule(array $rows, ?array $activeProfile, bool $expectNoQuery = false): AccessScheduleMiddleware
    {
        $db = $this->createMock(Connection::class);
        if ($expectNoQuery) {
            $db->expects(self::never())->method('query');
        } else {
            $db->method('query')->willReturnCallback(
                static function (string $sql, mixed $params = null) use ($rows): mixed {
                    if (str_starts_with($sql, 'SELECT * FROM access_schedules')) {
                        $profileId = is_array($params) ? ($params[0] ?? null) : null;

                        return array_values(array_filter(
                            $rows,
                            static fn (array $row): bool => $row['profile_id'] === $profileId,
                        ));
                    }

                    return 1;
                }
            );
        }

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('getActiveProfile')->willReturn($activeProfile);

        return new AccessScheduleMiddleware(new AccessScheduleService($db), $profiles);
    }

    private function request(string $method, string $path, ?string $userId = null, ?string $profileId = null): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;
        $request->userId = $userId;
        $request->profileId = $profileId;

        return $request;
    }

    /**
     * A request with no session, carrying a token minted by the real signer for `$path`.
     * `$profileId` null mints a legacy token with no `pid`.
     */
    private function signedRequest(string $path, ?string $profileId): Request
    {
        $minted = SignedUrl::fromEnv()->mint($path, null, null, $profileId);
        parse_str((string) parse_url($minted, PHP_URL_QUERY), $query);

        $request = $this->request('GET', $path);
        $request->query = $query;

        return $request;
    }

    /** @return array<string, mixed> */
    private function decodedBody(\Phlix\Server\Http\Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function test_an_authenticated_routed_request_outside_its_window_is_refused(): void
    {
        $schedule = $this->schedule($this->lockedOutP1(), null);

        // Covers the ordinary API and the routed HLS/DASH segments the reviewer named.
        foreach (['/api/v1/media/m1', '/hls/job-1/seg-1.m4s', '/dash/job-1/manifest.mpd'] as $path) {
            $response = Application::scheduleGateFor(
                $this->request('GET', $path, 'u1', 'p1'),
                $schedule,
            );

            self::assertNotNull($response, $path . ' must be refused in the window');
            self::assertSame(403, $response->statusCode, $path);
            self::assertSame('access.scheduled', $this->decodedBody($response)['code'], $path);
        }
    }

    public function test_an_authenticated_routed_request_in_a_free_window_passes(): void
    {
        $schedule = $this->schedule($this->lockedOutP1(), null);

        // p2 has no schedule, so the same route is open to it.
        self::assertNull(Application::scheduleGateFor(
            $this->request('GET', '/api/v1/media/m1', 'u1', 'p2'),
            $schedule,
        ));
    }

    public function test_a_session_whose_profile_cannot_be_resolved_fails_closed(): void
    {
        // No session profile and no account profile: the middleware refuses, rather than
        // letting a known user through unprofiled.
        $schedule = $this->schedule([], null);

        $response = Application::scheduleGateFor($this->request('GET', '/api/v1/media/m1', 'u1', null), $schedule);

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertSame('access.scheduled', $this->decodedBody($response)['code']);
    }

    public function test_an_unmapped_relayed_principal_with_no_profile_passes_the_gate(): void
    {
        // A hub principal with no server linkage has no account, so no profile and no schedule
        // exist for it. It passes as master did, rather than failing closed on the missing profile.
        $schedule = $this->schedule([], null);

        $request = $this->request('GET', '/api/v1/media/m1', 'hub-relay', null);
        $request->relayUnmapped = true;

        self::assertNull(Application::scheduleGateFor($request, $schedule));
    }

    public function test_a_mapped_relayed_principal_with_no_profile_still_fails_closed(): void
    {
        // The same relayed request for a principal that IS mapped to an account with no profile
        // is refused: the account exists, so the no-profile rule applies to it as to a local session.
        $schedule = $this->schedule([], null);

        $response = Application::scheduleGateFor($this->request('GET', '/api/v1/media/m1', 'u1', null), $schedule);

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertSame('access.scheduled', $this->decodedBody($response)['code']);
    }

    public function test_a_session_with_no_profile_claim_falls_back_to_the_account_profile(): void
    {
        // A token with no profile claim is checked against the account's active profile,
        // which is the pre-S80 behaviour the middleware keeps.
        $schedule = $this->schedule($this->lockedOutP1(), ['id' => 'p1']);

        $response = Application::scheduleGateFor($this->request('GET', '/api/v1/media/m1', 'u1', null), $schedule);

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
    }

    public function test_a_profile_less_session_can_create_a_profile(): void
    {
        // No profile anywhere on the account: creating one is the only remedy the 403
        // names, so POST /api/v1/profiles passes the gate for exactly this case.
        $schedule = $this->schedule([], null);

        self::assertNull(Application::scheduleGateFor(
            $this->request('POST', '/api/v1/profiles', 'u1', null),
            $schedule,
        ));
    }

    public function test_a_profile_less_session_stays_refused_on_every_other_route(): void
    {
        $schedule = $this->schedule([], null);

        // Same method, neighbouring paths, and the exact path under another method: none
        // of these is the profile-create exemption, so each keeps the no-profile 403.
        foreach (
            [
                ['GET', '/api/v1/media/m1'],
                ['GET', '/api/v1/profiles'],
                ['POST', '/api/v1/profiles/'],
                ['POST', '/api/v1/profiles/p1/switch'],
                ['POST', '/api/v1/media/m1'],
                ['PUT', '/api/v1/profiles'],
                ['DELETE', '/api/v1/profiles'],
            ] as [$method, $path]
        ) {
            $response = Application::scheduleGateFor($this->request($method, $path, 'u1', null), $schedule);

            self::assertNotNull($response, $method . ' ' . $path . ' must stay refused');
            self::assertSame(403, $response->statusCode, $method . ' ' . $path);
            self::assertSame('access.scheduled', $this->decodedBody($response)['code'], $method . ' ' . $path);
        }
    }

    public function test_a_profile_bound_locked_session_is_refused_on_profile_create(): void
    {
        // The exemption is for the no-profile case only. A session bound to a locked profile
        // is gated on POST /api/v1/profiles like any other route.
        $schedule = $this->schedule($this->lockedOutP1(), null);

        $response = Application::scheduleGateFor($this->request('POST', '/api/v1/profiles', 'u1', 'p1'), $schedule);

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertSame('access.scheduled', $this->decodedBody($response)['code']);
    }

    public function test_a_profile_less_token_on_an_account_with_a_locked_profile_is_refused_on_profile_create(): void
    {
        // A legacy token with no pid on an account whose active profile is locked: the
        // session does have a resolvable profile, so the create exemption does not apply.
        $schedule = $this->schedule($this->lockedOutP1(), ['id' => 'p1']);

        $response = Application::scheduleGateFor($this->request('POST', '/api/v1/profiles', 'u1', null), $schedule);

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertSame('access.scheduled', $this->decodedBody($response)['code']);
    }

    public function test_an_unauthenticated_request_passes_through_untouched(): void
    {
        // No identity: the gate neither refuses nor reads the schedule, even though a
        // profile is locked. The connection must not be touched at all.
        $schedule = $this->schedule($this->lockedOutP1(), ['id' => 'p1'], true);

        foreach (['/api/v1/media/m1', '/hls/job-1/seg-1.m4s', '/api/v1/auth/login', '/health', '/', '/app/library'] as $path) {
            self::assertNull(
                Application::scheduleGateFor($this->request('GET', $path), $schedule),
                $path . ' with no identity must pass unchanged',
            );
        }
    }

    public function test_the_session_lifecycle_health_and_shell_are_exempt_even_for_a_locked_session(): void
    {
        // A schedule-locked session must still be able to end its session, sign in again,
        // probe liveness and load the app to see the lock message.
        $schedule = $this->schedule($this->lockedOutP1(), null);

        $exempt = [
            ['GET', '/auth/logout'],
            ['POST', '/auth/logout'],
            ['POST', '/api/v1/auth/login'],
            ['POST', '/api/v1/auth/refresh'],
            ['POST', '/api/v1/auth/logout'],
            ['GET', '/health'],
            ['GET', '/'],
            ['GET', '/app'],
            ['GET', '/app/library'],
            ['HEAD', '/app/library'],
        ];
        foreach ($exempt as [$method, $path]) {
            self::assertNull(
                Application::scheduleGateFor($this->request($method, $path, 'u1', 'p1'), $schedule),
                $method . ' ' . $path . ' must stay open to a locked session',
            );
        }

        // A write that is not the lifecycle is still gated, even under the app prefix.
        $response = Application::scheduleGateFor($this->request('POST', '/app', 'u1', 'p1'), $schedule);
        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
    }

    public function test_the_exact_lifecycle_routes_stay_open_to_a_locked_session(): void
    {
        // The remaining allowlisted pairs: browser-form sign-in, the current identity, the
        // session list and single-segment revoke, and the HEAD probes.
        $schedule = $this->schedule($this->lockedOutP1(), null);

        $exempt = [
            ['POST', '/auth/login'],
            ['POST', '/auth/refresh'],
            ['GET', '/api/v1/auth/me'],
            ['GET', '/api/v1/auth/sessions'],
            ['DELETE', '/api/v1/auth/sessions/s1'],
            ['HEAD', '/health'],
            ['HEAD', '/'],
            ['HEAD', '/app'],
        ];
        foreach ($exempt as [$method, $path]) {
            self::assertNull(
                Application::scheduleGateFor($this->request($method, $path, 'u1', 'p1'), $schedule),
                $method . ' ' . $path . ' must stay open to a locked session',
            );
        }
    }

    public function test_routes_outside_the_exact_allowlist_are_gated_for_a_locked_session(): void
    {
        // The allowlist is exact. These are the near-miss and prefix cases a prefix match
        // would have let through, plus the session-minting and credential-mutating routes
        // the old `/auth/` and `/api/v1/auth/` prefixes exempted (identity link and unlink,
        // passkey registration, quick-connect approve).
        $schedule = $this->schedule($this->lockedOutP1(), null);

        $gated = [
            ['GET', '/apps'],
            ['GET', '/authorize'],
            ['GET', '/auth'],
            ['GET', '/health/'],
            ['POST', '/app'],
            ['POST', '/health'],
            ['PUT', '/app'],
            ['DELETE', '/app/library'],
            ['GET', '/auth/identities'],
            ['DELETE', '/auth/identities/abc'],
            ['POST', '/auth/identities/link/ldap'],
            ['POST', '/auth/identities/link/hub'],
            ['POST', '/api/v1/auth/webauthn/register/options'],
            ['POST', '/api/v1/auth/webauthn/register/verify'],
            ['POST', '/api/v1/auth/quick-connect/ABCD2345/approve'],
            ['POST', '/api/v1/auth/register'],
            ['POST', '/api/v1/auth/hub-token'],
            ['DELETE', '/api/v1/auth/sessions'],
            ['DELETE', '/api/v1/auth/sessions/a/b'],
            ['GET', '/api/v1/auth/login'],
            ['POST', '/api/v1/auth/me'],
        ];
        foreach ($gated as [$method, $path]) {
            $response = Application::scheduleGateFor($this->request($method, $path, 'u1', 'p1'), $schedule);

            self::assertNotNull($response, $method . ' ' . $path . ' must be gated for a locked session');
            self::assertSame(403, $response->statusCode, $method . ' ' . $path);
            self::assertSame('access.scheduled', $this->decodedBody($response)['code'], $method . ' ' . $path);
        }
    }

    public function test_a_schedule_locked_approver_cannot_approve_a_quick_connect_pairing(): void
    {
        // The bypass: a child on a locked profile approves a pairing code shown on a TV,
        // and the TV mints an unrestricted default-profile session. Approve is no longer
        // exempt, so the approver's own request is refused before the controller runs.
        $schedule = $this->schedule($this->lockedOutP1(), null);
        $path = '/api/v1/auth/quick-connect/ABCD2345/approve';

        $response = Application::scheduleGateFor($this->request('POST', $path, 'u1', 'p1'), $schedule);

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertSame('access.scheduled', $this->decodedBody($response)['code']);

        // The same approver on a free profile is not refused by the gate.
        self::assertNull(Application::scheduleGateFor($this->request('POST', $path, 'u1', 'p2'), $schedule));
    }

    public function test_the_request_context_is_restored_after_the_gate(): void
    {
        RequestContext::setUserId('u-prev');
        RequestContext::setProfileId(null);
        $schedule = $this->schedule($this->lockedOutP1(), null);

        $response = Application::scheduleGateFor($this->request('GET', '/api/v1/media/m1', 'u1', 'p1'), $schedule);

        self::assertNotNull($response);
        self::assertSame('u-prev', RequestContext::getUserId());
        self::assertNull(RequestContext::getProfileId());
    }

    public function test_a_signed_request_bound_to_a_locked_profile_is_refused_in_its_window(): void
    {
        $schedule = $this->schedule($this->lockedOutP1(), null);

        $response = Application::scheduleGateFor(
            $this->signedRequest('/hls/job-1/index.m3u8', 'p1'),
            $schedule,
        );

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertSame('access.scheduled', $this->decodedBody($response)['code']);
    }

    public function test_a_signed_request_bound_to_a_free_profile_passes(): void
    {
        $schedule = $this->schedule($this->lockedOutP1(), null);

        self::assertNull(Application::scheduleGateFor(
            $this->signedRequest('/hls/job-1/index.m3u8', 'p2'),
            $schedule,
        ));
    }

    public function test_a_legacy_signed_request_is_not_schedule_checked(): void
    {
        // Documented bound: a token minted before `pid` existed has no profile, so the
        // gate cannot apply a window to it. It is bounded only by its expiry.
        $schedule = $this->schedule($this->lockedOutP1(), null);

        self::assertNull(Application::scheduleGateFor(
            $this->signedRequest('/hls/job-1/index.m3u8', null),
            $schedule,
        ));
    }

    public function test_a_forged_profile_binding_is_not_trusted_by_the_gate(): void
    {
        // A legacy token with `pid` added does not verify, so the gate does not apply
        // p1's window on its say-so. The route's own signed-URL check refuses it.
        $schedule = $this->schedule($this->lockedOutP1(), null);
        $request = $this->signedRequest('/hls/job-1/index.m3u8', null);
        $request->query[SignedUrl::PROFILE_PARAM] = 'p1';

        self::assertNull(Application::scheduleGateFor($request, $schedule));
    }

    public function test_the_gate_publishes_the_profile_it_tested_on_the_request(): void
    {
        // A profile-less session is tested on the account's active profile. The gate passes it,
        // publishes that profile on the request, and restores RequestContext afterwards.
        $schedule = $this->schedule([], ['id' => 'p1']);
        $request = $this->request('GET', '/api/v1/media/m1', 'u1', null);

        self::assertNull(Application::scheduleGateFor($request, $schedule));
        self::assertSame('p1', $request->gatedProfileId);
        self::assertNull(RequestContext::getProfileId(), 'RequestContext is restored once the gate returns');
    }

    public function test_an_unmapped_relayed_principal_publishes_no_gated_profile(): void
    {
        $schedule = $this->schedule([], null);
        $request = $this->request('GET', '/api/v1/media/m1', 'hub-relay', null);
        $request->relayUnmapped = true;

        self::assertNull(Application::scheduleGateFor($request, $schedule));
        self::assertNull($request->gatedProfileId);
    }

    /**
     * The relayed chain end to end: the hub UUID resolves through RelayIdentityResolver to a
     * server user, the gate tests that account's active profile, and the route mints its stream
     * token. The token must carry the profile the gate tested, and that profile's schedule must be
     * checked again when the token is used.
     */
    public function test_a_relayed_mapped_principal_mints_its_stream_token_bound_to_its_resolved_profile(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')
            ->with(RelayIdentityResolver::PROVIDER_FAMILY, '', 'hub-user-42')
            ->willReturn(['user_id' => 'u1']);
        $serverUserId = (new RelayIdentityResolver($identities))->resolve('hub-user-42');
        self::assertSame('u1', $serverUserId);

        // A mapped principal as RelayConsumer::buildRequest() leaves it: the server user id, no
        // profile on the request, and not marked relayUnmapped.
        $request = $this->request('GET', '/api/v1/transcode/media-1', $serverUserId, null);

        // p1's window covers tomorrow only, so it is not in effect now. The gate passes the request
        // and tests it against p1.
        $tomorrow = [[
            'id' => 2,
            'profile_id' => 'p1',
            'name' => 'Tomorrow',
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => self::dayAbbrev(new DateTimeImmutable('+1 day')),
            'is_active' => 1,
        ]];
        self::assertNull(Application::scheduleGateFor($request, $this->schedule($tomorrow, ['id' => 'p1'])));
        self::assertSame('p1', $request->gatedProfileId);

        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('ensureHlsJob')->willReturn([
            'job_id' => 'job-7',
            'status' => 'running',
            'master_url' => '/hls/job-7/master.m3u8',
            'hls_url' => '/hls/job-7/master.m3u8',
            'dash_url' => null,
            'reused' => false,
            'subtitles' => [],
        ]);
        $manager->method('getJobVariants')->willReturn(null);

        $response = (new TranscodeController($manager))->start($request, ['id' => 'media-1']);
        self::assertSame(200, $response->statusCode);
        $body = $this->decodedBody($response);
        self::assertIsString($body['master_url']);
        parse_str((string) parse_url($body['master_url'], PHP_URL_QUERY), $query);
        self::assertSame('p1', $query[SignedUrl::PROFILE_PARAM] ?? null, 'the token is bound to the tested profile');

        // On use, the URL arrives with no session and its pid is checked against p1's schedule.
        // p1 in its window (tomorrow's is not in effect now) is allowed; once it is locked, the same
        // URL is refused.
        $use = $this->request('GET', '/hls/job-7/master.m3u8');
        $use->query = $query;
        self::assertNull($this->schedule($tomorrow, null)->denyForSignedRequest($use));
        $refused = $this->schedule($this->lockedOutP1(), null)->denyForSignedRequest($use);
        self::assertNotNull($refused);
        self::assertSame(403, $refused->statusCode);
    }
}
