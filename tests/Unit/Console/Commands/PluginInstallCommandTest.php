<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Console\Commands\PluginInstallCommand;
use Phlix\Plugins\Exception\PluginInstallException;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Phlix\Console\Commands\PluginInstallCommand
 */
class PluginInstallCommandTest extends TestCase
{
    private function tester(PluginLoader $loader): CommandTester
    {
        $application = new Application();
        $application->add(new PluginInstallCommand(fn(): PluginLoader => $loader));

        return new CommandTester($application->find('plugin:install'));
    }

    public function testInstallsAndPrintsManifest(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-x',
            'version' => '2.0.1',
            'type' => 'metadata',
            'entry' => 'Acme\\Entry',
        ]);

        $loader = $this->createMock(PluginLoader::class);
        $loader->expects($this->once())
            ->method('install')
            ->with('file:///tmp/plugin.json')
            ->willReturn($manifest);

        $tester = $this->tester($loader);
        $exitCode = $tester->execute(['source' => 'file:///tmp/plugin.json']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Installed plugin "phlix-plugin-x" version 2.0.1.', $display);
    }

    public function testInstallFailureExitsOne(): void
    {
        $loader = $this->createMock(PluginLoader::class);
        $loader->method('install')
            ->willThrowException(new PluginInstallException('signature did not verify'));

        $tester = $this->tester($loader);
        $exitCode = $tester->execute(['source' => 'https://example.com/bad']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Plugin install failed: signature did not verify', $tester->getDisplay());
    }
}
