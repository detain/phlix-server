<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use Phlix\Admin\SettingsRepository;
use Phlix\Media\Metadata\TmdbApiKeyResolver;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Regression cover for the TMDB API key precedence.
 *
 * The defect these guard: three call sites resolved the key from
 * `config/tmdb.php` / `TMDB_API_KEY` only and never consulted the
 * admin-managed `server_settings` row, so on a deployment where the key is
 * set from the admin UI (and `TMDB_API_KEY` is not exported) every one of
 * them resolved to an empty string permanently.
 */
final class TmdbApiKeyResolverTest extends TestCase
{
    private const CONFIG_DIR  = __DIR__ . '/../../../Fixtures/config-tmdb';
    private const CONFIG_FILE = self::CONFIG_DIR . '/tmdb.php';

    /** @var string|false Original TMDB_API_KEY, restored after each test. */
    private string|false $originalEnv = false;

    protected function setUp(): void
    {
        $this->originalEnv = getenv('TMDB_API_KEY');
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
     * Build a settings store whose `server_settings` lookup returns $storedKey,
     * or no row at all when $storedKey is null.
     */
    private function settingsWithOverride(?string $storedKey): SettingsRepository
    {
        $db = $this->createMock(Connection::class);
        // Variadic: Workerman's Connection::query() is untyped
        // (query($query = '', $params = null, $fetchmode = ...)), so match
        // loosely rather than pinning a signature that could drift.
        $db->method('query')->willReturnCallback(
            static function (mixed ...$args) use ($storedKey): array {
                $sql    = is_string($args[0] ?? null) ? $args[0] : '';
                $params = is_array($args[1] ?? null) ? $args[1] : [];

                if (!str_contains($sql, 'server_settings') || ($params[0] ?? null) !== 'tmdb.api_key') {
                    return [];
                }
                if ($storedKey === null) {
                    return [];
                }

                return [['setting_value' => $storedKey, 'value_type' => 'string']];
            }
        );

        return new SettingsRepository($db, self::CONFIG_DIR);
    }

    /**
     * THE regression: a stored override must outrank the config file.
     *
     * Against the pre-fix readers this fails — they returned 'config-file-key'
     * (and on production, where config/tmdb.php resolves to the unset
     * TMDB_API_KEY, an empty string) no matter what the admin had saved.
     */
    public function testStoredOverrideOutranksConfigFile(): void
    {
        $key = TmdbApiKeyResolver::resolve(
            $this->settingsWithOverride('db-override-key'),
            self::CONFIG_FILE
        );

        $this->assertSame('db-override-key', $key);
    }

    /**
     * The production shape of the defect: config/tmdb.php yields an empty
     * string (because TMDB_API_KEY is not exported) while a key IS stored.
     * A consumer must still get the stored key, not ''.
     */
    public function testStoredOverrideWinsWhenConfigAndEnvAreEmpty(): void
    {
        $key = TmdbApiKeyResolver::resolve(
            $this->settingsWithOverride('db-override-key'),
            '/nonexistent/config/tmdb.php'
        );

        $this->assertSame('db-override-key', $key);
        $this->assertNotSame('', $key, 'A stored key must never resolve to the empty production value.');
    }

    public function testFallsBackToConfigFileWhenNoOverrideStored(): void
    {
        $key = TmdbApiKeyResolver::resolve(
            $this->settingsWithOverride(null),
            self::CONFIG_FILE
        );

        $this->assertSame('config-file-key', $key);
    }

    public function testFallsBackToConfigFileWhenNoSettingsStoreAvailable(): void
    {
        $this->assertSame(
            'config-file-key',
            TmdbApiKeyResolver::resolve(null, self::CONFIG_FILE)
        );
    }

    /**
     * An empty override row is not a configured key — it must not shadow the
     * config/env default.
     */
    public function testEmptyOverrideDoesNotShadowConfigDefault(): void
    {
        $key = TmdbApiKeyResolver::resolve(
            $this->settingsWithOverride(''),
            self::CONFIG_FILE
        );

        $this->assertSame('config-file-key', $key);
    }

    /**
     * A broken settings store must degrade to config/env, not blow up the
     * caller's construction.
     */
    public function testSettingsFailureFallsBackToConfigFile(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new \RuntimeException('db down'));
        $settings = new SettingsRepository($db, self::CONFIG_DIR);

        $this->assertSame(
            'config-file-key',
            TmdbApiKeyResolver::resolve($settings, self::CONFIG_FILE)
        );
    }

    public function testFallsBackToEnvWhenConfigFileMissing(): void
    {
        putenv('TMDB_API_KEY=env-key');

        $this->assertSame(
            'env-key',
            TmdbApiKeyResolver::resolve(null, '/nonexistent/config/tmdb.php')
        );
    }

    public function testReturnsEmptyStringWhenNothingIsConfigured(): void
    {
        $this->assertSame(
            '',
            TmdbApiKeyResolver::resolve(null, '/nonexistent/config/tmdb.php')
        );
    }
}
