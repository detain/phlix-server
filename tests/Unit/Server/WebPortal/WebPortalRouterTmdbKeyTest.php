<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthManager;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Consumer-level regression cover for the TMDB API key.
 *
 * WebPortalRouter builds the admin poster endpoints' TmdbProvider from
 * tmdbApiKey() while registering routes. That method used to read
 * `config/tmdb.php` / `TMDB_API_KEY` only, so an admin-saved key in
 * `server_settings` was ignored and — on a deployment with TMDB_API_KEY
 * unexported — the provider was constructed with an empty key forever, while
 * the admin UI showed the key as configured.
 *
 * These tests fail against that implementation.
 *
 * @covers \Phlix\Server\WebPortal\WebPortalRouter
 */
final class WebPortalRouterTmdbKeyTest extends TestCase
{
    private const CONFIG_DIR = __DIR__ . '/../../../Fixtures/config-tmdb';

    /** @var string|false Original TMDB_API_KEY, restored after each test. */
    private string|false $originalEnv = false;

    protected function setUp(): void
    {
        $this->originalEnv = getenv('TMDB_API_KEY');
        // Reproduce production: the env var is NOT exported there.
        putenv('TMDB_API_KEY');
    }

    protected function tearDown(): void
    {
        if ($this->originalEnv === false) {
            putenv('TMDB_API_KEY');
        } else {
            putenv('TMDB_API_KEY=' . $this->originalEnv);
        }
    }

    /**
     * Settings store backed by a `server_settings` row for tmdb.api_key.
     */
    private function settingsWithStoredKey(string $storedKey): SettingsRepository
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (mixed ...$args) use ($storedKey): array {
                $sql    = is_string($args[0] ?? null) ? $args[0] : '';
                $params = is_array($args[1] ?? null) ? $args[1] : [];

                if (str_contains($sql, 'server_settings') && ($params[0] ?? null) === 'tmdb.api_key') {
                    return [['setting_value' => $storedKey, 'value_type' => 'string']];
                }

                return [];
            }
        );

        return new SettingsRepository($db, self::CONFIG_DIR);
    }

    private function makeRouter(?SettingsRepository $settings): WebPortalRouter
    {
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $this->createMock(ItemRepository::class),
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            // userRepository + auditLogger are what gate the admin poster
            // route block, so pass them to exercise the real registration path.
            userRepository: $this->createMock(UserRepository::class),
            auditLogger: $this->createMock(AuditLogger::class),
            settings: $settings
        );
    }

    private function resolvedKey(WebPortalRouter $router): string
    {
        $method = new \ReflectionMethod($router, 'tmdbApiKey');
        $method->setAccessible(true);

        /** @var string $key */
        $key = $method->invoke($router);

        return $key;
    }

    /**
     * THE regression: an admin-saved key must reach the poster endpoints'
     * TMDB consumer even though config/env supply nothing.
     */
    public function testAdminSavedKeyIsUsedWhenConfigAndEnvAreEmpty(): void
    {
        $router = $this->makeRouter($this->settingsWithStoredKey('admin-saved-key'));

        $this->assertSame('admin-saved-key', $this->resolvedKey($router));
    }

    /**
     * The consumer that is actually constructed must report a usable key —
     * this is the symptom an operator sees (poster lookups behaving as if no
     * key were configured).
     */
    public function testConstructedTmdbConsumerHasAnApiKey(): void
    {
        $router = $this->makeRouter($this->settingsWithStoredKey('admin-saved-key'));

        $provider = new TmdbProvider($this->resolvedKey($router));

        $this->assertTrue(
            $provider->hasApiKey(),
            'A TMDB consumer built by the router must not ignore the stored server_settings key.'
        );
    }

    /**
     * A stored key must OUTRANK a configured env key, not merely fill in when
     * one is absent.
     *
     * Asserted against a populated TMDB_API_KEY on purpose: an assertion that
     * the result merely differs from the config value would also hold for the
     * pre-fix code (which returned ''), so it would not discriminate. This
     * comparison does — the old readers return 'env-key' here.
     */
    public function testStoredKeyOutranksConfiguredEnvKey(): void
    {
        putenv('TMDB_API_KEY=env-key');

        $router = $this->makeRouter($this->settingsWithStoredKey('admin-saved-key'));

        $this->assertSame('admin-saved-key', $this->resolvedKey($router));
    }

    /**
     * Without a settings store (legacy construction) the config/env default
     * must still be honoured — the fix must not make the key settings-only.
     */
    public function testFallsBackToConfigWhenNoSettingsStoreInjected(): void
    {
        putenv('TMDB_API_KEY=env-fallback-key');

        // No settings store, and the router reads the repository's real
        // config/tmdb.php, which resolves to TMDB_API_KEY.
        $router = $this->makeRouter(null);

        $this->assertSame('env-fallback-key', $this->resolvedKey($router));
    }
}
