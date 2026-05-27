<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Console\Commands\PluginDisableCommand;
use Phlix\Plugins\Exception\PluginNotFoundException;
use Phlix\Plugins\PluginLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Phlix\Console\Commands\PluginDisableCommand
 */
class PluginDisableCommandTest extends TestCase
{
    private function tester(PluginLoader $loader): CommandTester
    {
        $application = new Application();
        $application->add(new PluginDisableCommand(fn(): PluginLoader => $loader));

        return new CommandTester($application->find('plugin:disable'));
    }

    public function testDisablesNamedPlugin(): void
    {
        $loader = $this->createMock(PluginLoader::class);
        $loader->expects($this->once())->method('disable')->with('phlix-plugin-a');

        $tester = $this->tester($loader);
        $exitCode = $tester->execute(['name' => 'phlix-plugin-a']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Plugin "phlix-plugin-a" disabled.', $tester->getDisplay());
    }

    public function testNotFoundExitsOne(): void
    {
        $loader = $this->createMock(PluginLoader::class);
        $loader->method('disable')
            ->willThrowException(new PluginNotFoundException('no such plugin: missing'));

        $tester = $this->tester($loader);
        $exitCode = $tester->execute(['name' => 'missing']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Failed to disable plugin "missing"', $tester->getDisplay());
    }
}
