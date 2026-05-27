<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use DateTimeImmutable;
use Phlix\Console\Commands\PluginListCommand;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Phlix\Console\Commands\PluginListCommand
 */
class PluginListCommandTest extends TestCase
{
    private function tester(PluginLoader $loader): CommandTester
    {
        $application = new Application();
        $application->add(new PluginListCommand(fn(): PluginLoader => $loader));

        return new CommandTester($application->find('plugin:list'));
    }

    private function installedPlugin(string $name, string $version, bool $enabled): InstalledPlugin
    {
        $manifest = Manifest::fromArray([
            'name' => $name,
            'version' => $version,
            'type' => 'metadata',
            'entry' => 'Acme\\Entry',
        ]);

        return new InstalledPlugin(
            id: 'id-' . $name,
            manifest: $manifest,
            enabled: $enabled,
            installedAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
            settings: [],
            directory: '/plugins/' . $name,
        );
    }

    public function testListsInstalledPluginsAsTable(): void
    {
        $loader = $this->createMock(PluginLoader::class);
        $loader->expects($this->once())
            ->method('listInstalled')
            ->willReturn([
                $this->installedPlugin('phlix-plugin-a', '1.2.3', true),
                $this->installedPlugin('phlix-plugin-b', '0.9.0', false),
            ]);

        $tester = $this->tester($loader);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('phlix-plugin-a', $display);
        $this->assertStringContainsString('1.2.3', $display);
        $this->assertStringContainsString('yes', $display);
        $this->assertStringContainsString('phlix-plugin-b', $display);
        $this->assertStringContainsString('no', $display);
    }

    public function testEmptyListPrintsMessage(): void
    {
        $loader = $this->createMock(PluginLoader::class);
        $loader->method('listInstalled')->willReturn([]);

        $tester = $this->tester($loader);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No plugins installed.', $tester->getDisplay());
    }

    public function testLoaderThrowsExitsOne(): void
    {
        $loader = $this->createMock(PluginLoader::class);
        $loader->method('listInstalled')->willThrowException(new RuntimeException('repo error'));

        $tester = $this->tester($loader);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Failed to list plugins: repo error', $tester->getDisplay());
    }
}
