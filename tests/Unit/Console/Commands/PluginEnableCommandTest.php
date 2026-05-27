<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Console\Commands\PluginEnableCommand;
use Phlix\Plugins\Exception\PluginNotFoundException;
use Phlix\Plugins\PluginLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Phlix\Console\Commands\PluginEnableCommand
 */
class PluginEnableCommandTest extends TestCase
{
    private function tester(PluginLoader $loader): CommandTester
    {
        $application = new Application();
        $application->add(new PluginEnableCommand(fn(): PluginLoader => $loader));

        return new CommandTester($application->find('plugin:enable'));
    }

    public function testEnablesNamedPlugin(): void
    {
        $loader = $this->createMock(PluginLoader::class);
        $loader->expects($this->once())->method('enable')->with('phlix-plugin-a');

        $tester = $this->tester($loader);
        $exitCode = $tester->execute(['name' => 'phlix-plugin-a']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Plugin "phlix-plugin-a" enabled.', $tester->getDisplay());
    }

    public function testNotFoundExitsOne(): void
    {
        $loader = $this->createMock(PluginLoader::class);
        $loader->method('enable')
            ->willThrowException(new PluginNotFoundException('no such plugin: missing'));

        $tester = $this->tester($loader);
        $exitCode = $tester->execute(['name' => 'missing']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Failed to enable plugin "missing"', $tester->getDisplay());
    }
}
