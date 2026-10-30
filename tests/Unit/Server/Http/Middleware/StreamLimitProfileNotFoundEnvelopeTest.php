<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Access\StreamSessionService;
use Phlix\Auth\UserProfileManager;
use Phlix\Server\Http\Middleware\StreamLimitMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Workerman\MySQL\Connection;

/**
 * Review server__copywriter M-SERVER-04: the profile-not-found 403 (both the
 * router middleware AND the inlined direct-play analogue in
 * PreRouterFastPaths) set the client-rendered `error` field to
 * 'StreamLimitExceeded' while its own `code` said 'profile.not_found'. Per
 * the estate {error,code} contract clients render the error field verbatim,
 * so a member with no active profile was told the STREAM LIMIT was to blame —
 * a different cause than the code asserts.
 *
 * Cure pinned here: the denial now names its own cause ('Profile not found',
 * the same label the profile controllers already use), the dotted code is
 * untouched (wire contract), and the message tells the member what to DO.
 *
 * @package Phlix\Tests\Unit\Server\Http\Middleware
 */
final class StreamLimitProfileNotFoundEnvelopeTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;

    private StreamSessionService $service;

    /** @var UserProfileManager&MockObject */
    private UserProfileManager $profiles;

    protected function setUp(): void
    {
        parent::setUp();

        // StreamSessionService is final — built for real over a mock
        // Connection (house idiom); this path never reaches it.
        $this->db = $this->createMock(Connection::class);
        $this->db->method('query')->willReturn([]);
        $this->service = new StreamSessionService($this->db);
        $this->profiles = $this->createMock(UserProfileManager::class);

        RequestContext::setUserId('u1');
        RequestContext::setProfileId(null);
    }

    protected function tearDown(): void
    {
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        parent::tearDown();
    }

    private function middleware(): StreamLimitMiddleware
    {
        return new StreamLimitMiddleware($this->service, $this->profiles);
    }

    private function request(): Request
    {
        // The profile-not-found refusal fires before device/session handling,
        // so a bare request object is all the middleware can reach on this path.
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/media/m1/stream';

        return $request;
    }

    public function test_unresolvable_profile_denies_with_its_own_honest_label(): void
    {
        $this->profiles->method('getActiveProfile')->willReturn(null);

        $response = $this->middleware()->__invoke($this->request());

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->body, true, 8, JSON_THROW_ON_ERROR);

        self::assertSame('profile.not_found', $body['code'], 'the dotted wire code is contract — frozen.');
        self::assertSame(
            'Profile not found',
            $body['error'],
            'the client-rendered error field must name the actual cause, never StreamLimitExceeded.'
        );
        self::assertSame(
            'No active profile for this session. Pick or create a profile, then retry.',
            $body['message'],
            'the message must tell the member the actionable step.'
        );
        self::assertSame('profile_not_found', $body['denial_type']);
    }

    public function test_profile_row_without_resolvable_id_carries_the_same_envelope(): void
    {
        $this->profiles->method('getActiveProfile')->willReturn(['name' => 'ghost']);

        $response = $this->middleware()->__invoke($this->request());

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->body, true, 8, JSON_THROW_ON_ERROR);

        self::assertSame('Profile not found', $body['error']);
        self::assertSame('profile.not_found', $body['code']);
    }
}
