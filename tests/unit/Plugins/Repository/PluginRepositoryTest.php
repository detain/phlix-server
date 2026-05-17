<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins\Repository;

use Phlex\Plugins\Exception\PluginNotFoundException;
use Phlex\Plugins\Manifest;
use Phlex\Plugins\Repository\PluginRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlex\Plugins\Repository\PluginRepository
 * @covers \Phlex\Plugins\InstalledPlugin
 */
final class PluginRepositoryTest extends TestCase
{
    private Connection&MockObject $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->createMock(Connection::class);
    }

    public function test_insert_returns_a_uuid_and_persists_row(): void
    {
        $manifest = $this->buildManifest('phlex-plugin-foo');

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO plugins'),
                $this->callback(function (array $params) {
                    return is_string($params[0]) && strlen($params[0]) === 36
                        && $params[1] === 'phlex-plugin-foo'
                        && $params[5] === 0;
                }),
            );

        $repo = new PluginRepository($this->db, '/tmp/plugins');
        $id = $repo->insert($manifest, false, []);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function test_findByName_hydrates_installed_plugin(): void
    {
        $manifest = $this->buildManifest('phlex-plugin-bar');

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('FROM plugins WHERE name = ?'),
                ['phlex-plugin-bar'],
            )
            ->willReturn([[
                'id' => 'aaaa1111-bbbb-2222-cccc-333344445555',
                'name' => 'phlex-plugin-bar',
                'version' => '1.0.0',
                'type' => 'notifier',
                'entry' => 'Phlex\\Bar\\Plugin',
                'enabled' => 1,
                'installed_at' => '2026-05-01 10:00:00',
                'settings_json' => '{"webhook":"https://example.test"}',
                'manifest_json' => json_encode($manifest->toArray()),
            ]]);

        $repo = new PluginRepository($this->db, '/tmp/plugins');
        $installed = $repo->findByName('phlex-plugin-bar');

        $this->assertSame('phlex-plugin-bar', $installed->manifest->name);
        $this->assertTrue($installed->enabled);
        $this->assertSame(['webhook' => 'https://example.test'], $installed->settings);
        $this->assertSame('/tmp/plugins/phlex-plugin-bar', $installed->directory);
        $this->assertSame('aaaa1111-bbbb-2222-cccc-333344445555', $installed->id);
    }

    public function test_findByName_throws_when_no_row(): void
    {
        $this->db->method('query')->willReturn([]);
        $repo = new PluginRepository($this->db, '/tmp/plugins');

        $this->expectException(PluginNotFoundException::class);
        $repo->findByName('phlex-plugin-missing');
    }

    public function test_existsByName_true_when_row_present(): void
    {
        $this->db->method('query')->willReturn([[1]]);
        $repo = new PluginRepository($this->db, '/tmp/plugins');
        $this->assertTrue($repo->existsByName('phlex-plugin-foo'));
    }

    public function test_existsByName_false_when_no_row(): void
    {
        $this->db->method('query')->willReturn([]);
        $repo = new PluginRepository($this->db, '/tmp/plugins');
        $this->assertFalse($repo->existsByName('phlex-plugin-foo'));
    }

    public function test_setEnabled_persists_flag(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE plugins SET enabled = ?'),
                [1, 'phlex-plugin-foo'],
            );

        $repo = new PluginRepository($this->db, '/tmp/plugins');
        $repo->setEnabled('phlex-plugin-foo', true);
    }

    public function test_updateSettings_writes_json_blob(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE plugins SET settings_json = ?'),
                $this->callback(function (array $params) {
                    return $params[0] === '{"api_key":"abc"}'
                        && $params[1] === 'phlex-plugin-foo';
                }),
            );

        $repo = new PluginRepository($this->db, '/tmp/plugins');
        $repo->updateSettings('phlex-plugin-foo', ['api_key' => 'abc']);
    }

    public function test_delete_removes_row(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                'DELETE FROM plugins WHERE name = ?',
                ['phlex-plugin-foo'],
            );

        $repo = new PluginRepository($this->db, '/tmp/plugins');
        $repo->delete('phlex-plugin-foo');
    }

    public function test_listAll_returns_hydrated_dtos(): void
    {
        $manifest = $this->buildManifest('phlex-plugin-foo');
        $this->db->method('query')->willReturn([
            [
                'id' => 'id-1',
                'name' => 'phlex-plugin-foo',
                'version' => '1.0.0',
                'type' => 'notifier',
                'entry' => 'Phlex\\Foo\\Plugin',
                'enabled' => 0,
                'installed_at' => '2026-01-01 00:00:00',
                'settings_json' => null,
                'manifest_json' => json_encode($manifest->toArray()),
            ],
            [
                'id' => 'id-2',
                'name' => 'phlex-plugin-bar',
                'version' => '2.0.0',
                'type' => 'scrobbler',
                'entry' => 'Phlex\\Bar\\Plugin',
                'enabled' => 1,
                'installed_at' => '2026-01-02 00:00:00',
                'settings_json' => '{}',
                'manifest_json' => json_encode($this->buildManifest('phlex-plugin-bar')->toArray()),
            ],
        ]);

        $repo = new PluginRepository($this->db, '/tmp/plugins');
        $all = $repo->listAll();
        $this->assertCount(2, $all);
        $this->assertSame('phlex-plugin-foo', $all[0]->manifest->name);
        $this->assertFalse($all[0]->enabled);
        $this->assertTrue($all[1]->enabled);
    }

    public function test_listEnabled_filters_to_enabled_rows(): void
    {
        $manifest = $this->buildManifest('phlex-plugin-on');
        $this->db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('WHERE enabled = 1'))
            ->willReturn([
                [
                    'id' => 'enabled-id',
                    'name' => 'phlex-plugin-on',
                    'version' => '1.0.0',
                    'type' => 'notifier',
                    'entry' => 'Phlex\\On\\Plugin',
                    'enabled' => 1,
                    'installed_at' => '2026-01-01 00:00:00',
                    'settings_json' => null,
                    'manifest_json' => json_encode($manifest->toArray()),
                ],
            ]);

        $repo = new PluginRepository($this->db, '/tmp/plugins');
        $this->assertCount(1, $repo->listEnabled());
    }

    public function test_directoryFor_joins_base_and_name(): void
    {
        $repo = new PluginRepository($this->db, '/tmp/plugins');
        $this->assertSame('/tmp/plugins/phlex-plugin-x', $repo->directoryFor('phlex-plugin-x'));
    }

    public function test_generateUuid_produces_rfc4122_v4_format(): void
    {
        $uuid = PluginRepository::generateUuid();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    private function buildManifest(string $name): Manifest
    {
        return Manifest::fromArray([
            'name' => $name,
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Phlex\\Foo\\Plugin',
        ]);
    }
}
