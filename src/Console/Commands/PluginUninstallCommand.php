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
 * `plugin:uninstall {name}` — uninstall a plugin.
 *
 * Thin console wrapper around {@see PluginLoader::uninstall()}. The backing
 * {@see PluginLoader} is resolved lazily through the injected factory so
 * constructing this command never builds the DI container.
 */
#[AsCommand(name: 'plugin:uninstall', description: 'Uninstall a plugin by name')]
final class PluginUninstallCommand extends Command
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
        $this->addArgument('name', InputArgument::REQUIRED, 'The plugin manifest name to uninstall');
    }

    /**
     * Uninstall the named plugin.
     *
     * @return int {@see Command::SUCCESS} (0) when the plugin is uninstalled,
     *         or {@see Command::FAILURE} (1) when it is not found or fails to
     *         uninstall.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $nameRaw = $input->getArgument('name');
        $name = is_string($nameRaw) ? $nameRaw : '';

        try {
            $loader = ($this->pluginLoaderFactory)();
            $loader->uninstall($name);
        } catch (Throwable $e) {
            $output->writeln('<error>Failed to uninstall plugin "' . $name . '": ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('Plugin "' . $name . '" uninstalled.');

        return Command::SUCCESS;
    }
}
