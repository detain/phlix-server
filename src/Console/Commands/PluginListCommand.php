<?php

declare(strict_types=1);

namespace Phlix\Console\Commands;

use Phlix\Plugins\PluginLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `plugin:list` — list every installed plugin and its enabled state.
 *
 * Thin console wrapper around {@see PluginLoader::listInstalled()}: it prints
 * each plugin's name, version, and enabled flag as a table. The backing
 * {@see PluginLoader} is resolved lazily through the injected factory so
 * constructing this command never builds the DI container.
 */
#[AsCommand(name: 'plugin:list', description: 'List installed plugins and their enabled state')]
final class PluginListCommand extends Command
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
     * Render the installed-plugin list.
     *
     * @return int {@see Command::SUCCESS} (0) once the list is rendered, or
     *         {@see Command::FAILURE} (1) when the loader throws.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $loader = ($this->pluginLoaderFactory)();
            $plugins = $loader->listInstalled();
        } catch (Throwable $e) {
            $output->writeln('<error>Failed to list plugins: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($plugins === []) {
            $output->writeln('No plugins installed.');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Name', 'Version', 'Enabled']);

        foreach ($plugins as $plugin) {
            $table->addRow([
                $plugin->name(),
                $plugin->manifest->version,
                $plugin->enabled ? 'yes' : 'no',
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
