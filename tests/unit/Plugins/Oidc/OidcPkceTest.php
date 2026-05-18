<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins\Oidc;

use Phlex\Auth\AuthProviderRegistry;
use Phlex\Auth\JwtHandler;
use Phlex\Auth\UserRepository;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Plugins\Oidc\Controller\OidcCallbackController;
use Phlex\Plugins\Oidc\DiscoveryDocument;
use Phlex\Plugins\Oidc\OidcProvider;
use Phlex\Plugins\Oidc\OidcStateStore;
use Phlex\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Covers the OIDC Authorization Code + PKCE (S256) flow:
 * - code_verifier generation produces a 43+ character string from the
 *   RFC 7636 unreserved set.
 * - code_challenge is the base64url(sha256(verifier)) of the verifier.
 * - authorize() embeds `code_challenge` and `code_challenge_method=S256`
 *   in the redirect URL and stores the verifier server-side keyed by
 *   an opaque session id.
 * - callback() rejects requests whose state is missing, malformed, or
 *   does not match a previously-issued sid (CSRF / replay).
 *
 * See post-O.7 wave 1 security audit, finding D.2.
 */
final class OidcPkceTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/phlex_oidc_pkce_test_' . uniqid();
        mkdir($this->cacheDir, 0755, true);
        DiscoveryDocument::clearMemoryCache();
        LoggerFactory::reset();
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->cacheDir)) {
            foreach ((array) glob($this->cacheDir . '/*') as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->cacheDir);
        }
        DiscoveryDocument::clearMemoryCache();
    }

    public function test_code_verifier_is_at_least_43_chars_from_unreserved_set(): void
    {
        $verifier = OidcProvider::generateCodeVerifier();

        // RFC 7636 §4.1: code_verifier = 43*128unreserved
        self::assertGreaterThanOrEqual(43, strlen($verifier));
        self::assertLessThanOrEqual(128, strlen($verifier));
        // bin2hex output uses [0-9a-f], a strict subset of the unreserved
        // ABCDEFGHIJKLMNOPQRSTUVWXYZ-abcdefghijklmnopqrstuvwxyz-0123456789-._~ set.
        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]+$/', $verifier);
    }

    public function test_code_verifiers_are_unique_across_calls(): void
    {
        // 32 random bytes → collision is astronomically unlikely.
        self::assertNotSame(
            OidcProvider::generateCodeVerifier(),
            OidcProvider::generateCodeVerifier()
        );
    }

    public function test_code_challenge_is_s256_of_verifier(): void
    {
        // Test vector from RFC 7636 Appendix B.
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $expected = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        self::assertSame($expected, OidcProvider::computeCodeChallenge($verifier));
    }

    public function test_authorize_url_carries_code_challenge_and_s256_method(): void
    {
        $registry = $this->makeRegistryWithProvider();
        $userRepository = $this->createMock(UserRepository::class);
        $jwtHandler = $this->createMock(JwtHandler::class);
        $store = new RecordingOidcStateStore();

        $controller = new OidcCallbackController(
            $registry,
            $userRepository,
            $jwtHandler,
            $store,
        );

        $request = new Request();
        $request->query = ['redirect_uri' => 'http://localhost/cb'];

        $response = $controller->authorize($request, []);

        self::assertSame(302, $response->statusCode);
        $location = $response->headers['Location'] ?? '';
        self::assertStringContainsString('code_challenge=', $location);
        self::assertStringContainsString('code_challenge_method=S256', $location);

        // The store should have recorded a single entry whose verifier
        // hashes to the code_challenge present in the URL.
        self::assertCount(1, $store->entries);
        $entry = array_values($store->entries)[0];
        $params = [];
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $challengeInUrl = is_string($params['code_challenge'] ?? null) ? $params['code_challenge'] : '';
        self::assertSame(
            OidcProvider::computeCodeChallenge($entry['code_verifier']),
            $challengeInUrl
        );
    }

    public function test_callback_with_state_for_unknown_sid_returns_403(): void
    {
        $registry = $this->makeRegistryWithProvider();
        $userRepository = $this->createMock(UserRepository::class);
        $jwtHandler = $this->createMock(JwtHandler::class);
        $store = new RecordingOidcStateStore();

        $controller = new OidcCallbackController(
            $registry,
            $userRepository,
            $jwtHandler,
            $store,
        );

        // Forge a state that references a sid the store has never seen.
        $stateValue = base64_encode((string) json_encode([
            'sid' => 'never-issued',
            'redirect_uri' => 'http://localhost/cb',
        ]));

        $request = new Request();
        $request->query = [
            'code' => 'attacker-code',
            'state' => $stateValue,
        ];

        $response = $controller->callback($request, []);

        self::assertSame(403, $response->statusCode);
    }

    public function test_callback_with_state_missing_sid_returns_403(): void
    {
        $registry = $this->makeRegistryWithProvider();
        $userRepository = $this->createMock(UserRepository::class);
        $jwtHandler = $this->createMock(JwtHandler::class);
        $store = new RecordingOidcStateStore();

        $controller = new OidcCallbackController(
            $registry,
            $userRepository,
            $jwtHandler,
            $store,
        );

        // No `sid` field — legacy state envelope shape.
        $stateValue = base64_encode((string) json_encode([
            'redirect_uri' => 'http://localhost/cb',
            'nonce' => 'something',
        ]));

        $request = new Request();
        $request->query = [
            'code' => 'attacker-code',
            'state' => $stateValue,
        ];

        $response = $controller->callback($request, []);

        self::assertSame(403, $response->statusCode);
    }

    public function test_callback_after_state_already_consumed_returns_403(): void
    {
        $registry = $this->makeRegistryWithProvider();
        $userRepository = $this->createMock(UserRepository::class);
        $jwtHandler = $this->createMock(JwtHandler::class);
        $store = new RecordingOidcStateStore();
        $store->put('sid-one', 'verifier-one', 'nonce-one');

        $controller = new OidcCallbackController(
            $registry,
            $userRepository,
            $jwtHandler,
            $store,
        );

        $stateValue = base64_encode((string) json_encode([
            'sid' => 'sid-one',
            'redirect_uri' => 'http://localhost/cb',
        ]));

        $request1 = new Request();
        $request1->query = ['code' => 'some-code', 'state' => $stateValue];
        // First call consumes the entry (and will likely fail downstream
        // when it tries to actually hit the token endpoint — we don't
        // care, we just need the consume to have happened).
        $controller->callback($request1, []);

        $request2 = new Request();
        $request2->query = ['code' => 'some-code', 'state' => $stateValue];
        $response2 = $controller->callback($request2, []);

        self::assertSame(403, $response2->statusCode);
    }

    private function makeRegistryWithProvider(): AuthProviderRegistry
    {
        $registry = new AuthProviderRegistry();

        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $cachedData = [
            'issuer' => 'https://example.com',
            'authorization_endpoint' => 'https://example.com/authorize',
            'token_endpoint' => 'https://example.com/token',
            'jwks_uri' => 'https://example.com/jwks',
            '_cached_at' => time(),
        ];
        $cacheFile = $this->cacheDir . '/discovery_' . md5('https://example.com') . '.json';
        file_put_contents($cacheFile, (string) json_encode($cachedData));

        $provider = new OidcProvider($discovery, 'client-id', 'client-secret');
        $registry->registerProvider($provider);

        return $registry;
    }
}

/**
 * In-memory state store that exposes its contents for assertions.
 *
 * @internal Test fixture only.
 */
final class RecordingOidcStateStore implements OidcStateStore
{
    /** @var array<string, array{code_verifier: string, nonce: string}> */
    public array $entries = [];

    public function put(string $state, string $codeVerifier, string $nonce): void
    {
        $this->entries[$state] = [
            'code_verifier' => $codeVerifier,
            'nonce' => $nonce,
        ];
    }

    public function consume(string $state): ?array
    {
        if (!isset($this->entries[$state])) {
            return null;
        }
        $entry = $this->entries[$state];
        unset($this->entries[$state]);
        return $entry;
    }
}
