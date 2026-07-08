<?php

/**
 * Phlix media server component: Commands.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Console\Commands;

use Phlix\Plugins\Catalog\PluginCatalogService;
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
 *
 * When a {@see PluginCatalogService} factory is supplied, the command resolves
 * the catalog pin (`artifactSha256` + `ref`) for the given source and threads
 * it into the install so a pinned official plugin clears the SV-S2b
 * default-deny (SV-B2). A source not present in any catalog yields a null pin
 * and stays on the default-deny path unchanged.
 */
#[AsCommand(name: 'plugin:install', description: 'Install a plugin from a source URL')]
final class PluginInstallCommand extends Command
{
    /** @var callable(): PluginLoader Lazy factory for the backing loader. */
    private $pluginLoaderFactory;

    /** @var (callable(): PluginCatalogService)|null Lazy factory for the catalog (pin resolution). */
    private $catalogFactory;

    /**
     * @param callable(): PluginLoader $pluginLoaderFactory Lazy factory
     *        returning the backing {@see PluginLoader}. Invoked only inside
     *        {@see execute()}, never at registration time.
     * @param (callable(): PluginCatalogService)|null $catalogFactory Optional
     *        lazy factory returning the {@see PluginCatalogService} used to
     *        resolve the catalog pin (SV-B2). When omitted, the install runs
     *        un-pinned (subject to the SV-S2b default-deny).
     */
    public function __construct(callable $pluginLoaderFactory, ?callable $catalogFactory = null)
    {
        $this->pluginLoaderFactory = $pluginLoaderFactory;
        $this->catalogFactory = $catalogFactory;
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
            // SV-B2: resolve the catalog pin for the source (matched by `repo`
            // URL or `name`) so a pinned official plugin clears the SV-S2b
            // default-deny. A source not in any catalog yields [null, null]
            // and stays un-pinned (default-deny applies).
            [$sha, $ref] = $this->catalogFactory !== null
                ? ($this->catalogFactory)()->pinFor($source)
                : [null, null];
            $manifest = $loader->install($source, $sha, $ref);
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
