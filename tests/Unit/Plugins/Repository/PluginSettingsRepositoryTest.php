<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Repository;

use Phlix\Plugins\Repository\PluginSettingsRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlix\Plugins\Repository\PluginSettingsRepository
 */
final class PluginSettingsRepositoryTest extends TestCase
{
    public function test_get_returns_decoded_settings_when_row_present(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['settings_json' => '{"client_id":"abc","scopes":"read:user user:email"}'],
        ]);

        $repo = new PluginSettingsRepository($db);
        $settings = $repo->get('github');

        $this->assertSame('abc', $settings['client_id'] ?? null);
        $this->assertSame('read:user user:email', $settings['scopes'] ?? null);
    }

    public function test_get_returns_null_when_no_row(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new PluginSettingsRepository($db);

        $this->assertNull($repo->get('github'));
    }

    public function test_get_returns_empty_array_when_settings_json_blank(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['settings_json' => null]]);

        $repo = new PluginSettingsRepository($db);

        $this->assertSame([], $repo->get('github'));
    }

    public function test_save_issues_an_upsert(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('ON DUPLICATE KEY UPDATE'),
                $this->callback(function (array $params): bool {
                    return $params[0] === 'github'
                        && is_string($params[1])
                        && str_contains($params[1], 'client_id');
                }),
            )
            ->willReturn(true);

        $repo = new PluginSettingsRepository($db);
        $repo->save('github', ['client_id' => 'abc']);
    }

    public function test_exists_reflects_row_presence(): void
    {
        $dbYes = $this->createMock(Connection::class);
        $dbYes->method('query')->willReturn([['1' => 1]]);
        $this->assertTrue((new PluginSettingsRepository($dbYes))->exists('github'));

        $dbNo = $this->createMock(Connection::class);
        $dbNo->method('query')->willReturn([]);
        $this->assertFalse((new PluginSettingsRepository($dbNo))->exists('github'));
    }
}
