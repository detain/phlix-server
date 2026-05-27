<?php

declare(strict_types=1);

namespace Phlix\Console\Commands;

use Phlix\Plugins\PluginLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `plugin:enable {name}` — enable a previously-installed plugin.
 *
 * Thin console wrapper around {@see PluginLoader::enable()}. The backing
 * {@see PluginLoader} is resolved lazily through the injected factory so
 * constructing this command never builds the DI container.
 */
#[AsCommand(name: 'plugin:enable', description: 'Enable an installed plugin by name')]
final class PluginEnableCommand extends Command
{
    /** @var callable(): PluginLoader Lazy factory for the backing loader. */
    private $pluginLoaderFactory;

    /**
     * @param callable(): PluginLoader $pluginLoaderFactory Lazy factory
     *        returning the backing {@see PluginLoader}. Invoked only inside
     *        {@see execute()}, never at registration time.
     */
    public function __construct(callable $pluginLoaderFactory)
    {
        $this->pluginLoaderFactory = $pluginLoaderFactory;
        parent::__construct();
    }

    /**
     * Declare the required `name` argument.
     */
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The plugin manifest name to enable');
    }

    /**
     * Enable the named plugin.
     *
     * @return int {@see Command::SUCCESS} (0) when the plugin is enabled, or
     *         {@see Command::FAILURE} (1) when it is not found or fails to
     *         enable.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $nameRaw = $input->getArgument('name');
        $name = is_string($nameRaw) ? $nameRaw : '';

        try {
            $loader = ($this->pluginLoaderFactory)();
            $loader->enable($name);
        } catch (Throwable $e) {
            $output->writeln('<error>Failed to enable plugin "' . $name . '": ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('Plugin "' . $name . '" enabled.');

        return Command::SUCCESS;
    }
}
