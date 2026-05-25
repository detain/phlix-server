<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\UserRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Verifies the SQL built by {@see UserRepository::updateSettings()} is a valid
 * upsert. The previous implementation spliced "col = ?" fragments into the
 * INSERT column list, producing invalid SQL that threw on a first-ever save.
 *
 * @covers \Phlix\Auth\UserRepository::updateSettings
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
}
