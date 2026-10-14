<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\UserRepository;
use Phlix\Theming\ThemeSourceInterface;
use Phlix\Theming\ThemeSourceRegistry;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Verifies the SQL built by {@see UserRepository::updateSettings()} is a valid
 * upsert. The previous implementation spliced "col = ?" fragments into the
 * INSERT column list, producing invalid SQL that threw on a first-ever save.
 *
 * Also pins the theme-key contract: a submitted theme must name a LIVE theme
 * ({@see \Phlix\Theming\BuiltInThemes::IDS} ∪ ids registered in the wired
 * ThemeSourceRegistry) or updateSettings() throws BEFORE any write — the
 * pre-S85 fossil whitelist silently dropped every theme while answering 200.
 */
final class UserRepositorySettingsTest extends TestCase
{
    public function test_update_settings_builds_valid_upsert_sql(): void
    {
        $capturedSql = null;
        $capturedBindings = null;

        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $bindings = []) use (&$capturedSql, &$capturedBindings) {
                $capturedSql = $sql;
                $capturedBindings = $bindings;
                return [];
            });

        $repo = new UserRepository($db);
        $repo->updateSettings('user-1', [
            'max_streams' => 5,
            'preferred_audio_language' => 'fr',
        ]);

        $this->assertIsString($capturedSql);

        // Column list must be plain identifiers — never "col = ?" fragments.
        $this->assertStringContainsString(
            'INSERT INTO user_settings (user_id, max_streams, preferred_audio_language)',
            $capturedSql
        );
        $this->assertStringContainsString('VALUES (?, ?, ?)', $capturedSql);
        $this->assertStringNotContainsString('= ?,', $capturedSql, 'INSERT column list must not contain assignment fragments');

        // Upsert clause keeps the existing-row path correct.
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $capturedSql);
        $this->assertStringContainsString('max_streams = VALUES(max_streams)', $capturedSql);
        $this->assertStringContainsString('preferred_audio_language = VALUES(preferred_audio_language)', $capturedSql);

        // Bind order: user_id first, then column values (no duplicated user_id).
        $this->assertSame(['user-1', 5, 'fr'], $capturedBindings);
    }

    public function test_update_settings_encodes_transcoding_preferences(): void
    {
        $capturedSql = null;
        $capturedBindings = null;

        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $bindings = []) use (&$capturedSql, &$capturedBindings) {
                $capturedSql = $sql;
                $capturedBindings = $bindings;
                return [];
            });

        $repo = new UserRepository($db);
        $repo->updateSettings('user-1', [
            'transcoding_preferences' => ['codec' => 'h264'],
        ]);

        $this->assertIsString($capturedSql);
        $this->assertStringContainsString('(user_id, transcoding_preferences)', $capturedSql);
        $this->assertNotNull($capturedBindings);
        $this->assertSame('user-1', $capturedBindings[0]);
        $this->assertSame('{"codec":"h264"}', $capturedBindings[1]);
    }

    public function test_update_settings_with_no_known_fields_is_noop(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $repo = new UserRepository($db);
        $repo->updateSettings('user-1', ['unknown_field' => 'x']);
    }

    /**
     * A real ThemeSourceRegistry with one enabled plugin theme, built the same
     * way tests/Unit/Theming/ThemeSourceRegistryTest.php builds its fixture —
     * the payload token subset passes ThemeTokenValidator, so register()
     * lands the id exactly like PluginLoader::enable() does in production.
     */
    private function registryWithPluginTheme(string $id): ThemeSourceRegistry
    {
        $source = new class ('acme-themes', [[
            'id' => $id,
            'name' => ucfirst($id),
            'dark' => true,
            'extends' => null,
            'tokens' => ['--bg' => '#000'],
        ]]) implements ThemeSourceInterface {
            /**
             * @param list<array<array-key, mixed>> $themes
             */
            public function __construct(private string $name, private array $themes)
            {
            }

            public function themeSourceName(): string
            {
                return $this->name;
            }

            /**
             * @return list<array<array-key, mixed>>
             */
            public function providedThemes(): array
            {
                return $this->themes;
            }
        };

        $registry = new ThemeSourceRegistry();
        $registry->register($source);

        return $registry;
    }

    /**
     * Mock Connection that records every (sql, bindings) pair it is asked to run.
     *
     * @param list<array{0: string, 1: array<int, mixed>}> $captured
     */
    private function recordingDb(array &$captured): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $bindings = []) use (&$captured): array {
                $captured[] = [$sql, $bindings];
                return [];
            });

        return $db;
    }

    public function test_update_settings_persists_builtin_theme_to_user_profiles(): void
    {
        $captured = [];
        $db = $this->recordingDb($captured);

        // No registry wired: built-ins must still persist (fallback law).
        $repo = new UserRepository($db);
        $repo->updateSettings('user-1', ['theme' => 'nocturne']);

        // theme lives on user_profiles, not user_settings — a theme-only
        // payload must produce exactly one statement, the profile UPDATE.
        $this->assertCount(1, $captured);
        $this->assertStringContainsString('UPDATE user_profiles SET active_theme_id = ?', $captured[0][0]);
        $this->assertSame(['nocturne', 'user-1'], $captured[0][1]);
    }

    public function test_update_settings_persists_registered_plugin_theme(): void
    {
        $captured = [];
        $db = $this->recordingDb($captured);

        $repo = new UserRepository($db, $this->registryWithPluginTheme('acme-noir'));
        $repo->updateSettings('user-1', ['theme' => 'acme-noir']);

        $this->assertCount(1, $captured);
        $this->assertStringContainsString('UPDATE user_profiles SET active_theme_id = ?', $captured[0][0]);
        $this->assertSame(['acme-noir', 'user-1'], $captured[0][1]);
    }

    public function test_update_settings_writes_upsert_before_theme_update(): void
    {
        $captured = [];
        $db = $this->recordingDb($captured);

        $repo = new UserRepository($db);
        $repo->updateSettings('user-1', ['max_streams' => 3, 'theme' => 'midnight']);

        // Statement order mirrors the historical code shape: settings upsert
        // first, then the user_profiles theme write — pinned so a refactor
        // that reorders or merges them is caught.
        $this->assertCount(2, $captured);
        $this->assertStringContainsString('INSERT INTO user_settings', $captured[0][0]);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $captured[0][0]);
        $this->assertStringContainsString('UPDATE user_profiles SET active_theme_id = ?', $captured[1][0]);
        $this->assertSame(['midnight', 'user-1'], $captured[1][1]);
    }

    public function test_update_settings_rejects_fossil_theme_id_with_zero_writes(): void
    {
        // 'phlix-dark' was valid ONLY against the deleted pre-S85 fossil
        // whitelist. It names no live theme, so it must now fail loudly —
        // the intentional behavior change that turns silent-drop-200 into 400.
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $repo = new UserRepository($db);

        try {
            $repo->updateSettings('user-1', ['theme' => 'phlix-dark']);
            $this->fail('Expected InvalidArgumentException for a fossil theme id');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown theme "phlix-dark"', $e->getMessage());
        }
    }

    public function test_update_settings_rejects_unknown_theme_before_any_write(): void
    {
        // Mixed payload: the otherwise-valid max_streams must NOT be persisted
        // when the theme fails — validation precedes every query.
        $captured = [];
        $db = $this->recordingDb($captured);

        $repo = new UserRepository($db);

        try {
            $repo->updateSettings('user-1', ['max_streams' => 5, 'theme' => 'nope']);
            $this->fail('Expected InvalidArgumentException before any write');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('nope', $e->getMessage());
        }

        $this->assertSame([], $captured, 'a rejected payload must change zero rows');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function provide_invalid_theme_values(): array
    {
        return [
            'null' => [null],
            'integer' => [42],
            'array' => [['nocturne']],
            'empty string' => [''],
        ];
    }

    /**
     * @param mixed $theme
     * @dataProvider provide_invalid_theme_values
     */
    public function test_update_settings_rejects_non_string_theme(mixed $theme): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $repo = new UserRepository($db);

        $this->expectException(\InvalidArgumentException::class);
        $repo->updateSettings('user-1', ['theme' => $theme]);
    }

    public function test_update_settings_without_registry_wired_rejects_plugin_theme(): void
    {
        // Fallback law: an unwired registry means built-ins only — plugin ids
        // are unknown territory and must throw, never be silently dropped.
        $captured = [];
        $db = $this->recordingDb($captured);

        $repo = new UserRepository($db);

        try {
            $repo->updateSettings('user-1', ['theme' => 'acme-noir']);
            $this->fail('Expected InvalidArgumentException for a plugin theme without registry');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown theme "acme-noir"', $e->getMessage());
            $this->assertStringContainsString('nocturne, daylight, midnight', $e->getMessage());
        }

        $this->assertSame([], $captured);
    }

    public function test_update_settings_error_message_lists_builtin_and_plugin_ids(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db, $this->registryWithPluginTheme('acme-noir'));

        try {
            $repo->updateSettings('user-1', ['theme' => 'ghost']);
            $this->fail('Expected InvalidArgumentException for an unknown theme');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('nocturne', $e->getMessage());
            $this->assertStringContainsString('acme-noir', $e->getMessage());
        }
    }
}
