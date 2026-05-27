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
 * `plugin:install {source}` — install a plugin from a source URL.
 *
 * Thin console wrapper around {@see PluginLoader::install()}, which returns
 * the parsed {@see \Phlix\Plugins\Manifest} of the installed plugin. The
 * command prints the resulting plugin name and version. The backing
 * {@see PluginLoader} is resolved lazily through the injected factory so
 * constructing this command never builds the DI container.
 */
#[AsCommand(name: 'plugin:install', description: 'Install a plugin from a source URL')]
final class PluginInstallCommand extends Command
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
     * Declare the required `source` argument.
     */
    protected function configure(): void
    {
        $this->addArgument(
            'source',
            InputArgument::REQUIRED,
            'The plugin source URL (HTTPS, or file:// for local sources)'
        );
    }

    /**
     * Install the plugin from the given source.
     *
     * @return int {@see Command::SUCCESS} (0) once the plugin is installed, or
     *         {@see Command::FAILURE} (1) when installation fails.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sourceRaw = $input->getArgument('source');
        $source = is_string($sourceRaw) ? $sourceRaw : '';

        try {
            $loader = ($this->pluginLoaderFactory)();
            $manifest = $loader->install($source);
        } catch (Throwable $e) {
            $output->writeln('<error>Plugin install failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Installed plugin "%s" version %s.',
            $manifest->name,
            $manifest->version
        ));

        return Command::SUCCESS;
    }
}
