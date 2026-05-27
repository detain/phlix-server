<?php

declare(strict_types=1);

namespace Phlix\Console\Commands;

use Phlix\Media\Library\LibraryManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `library:list` — list every configured media library.
 *
 * Thin console wrapper around {@see LibraryManager::getAllLibraries()}: it
 * prints the id, name, and configured path(s) of each library as a table.
 * The backing {@see LibraryManager} is resolved lazily through the injected
 * factory so constructing this command — e.g. for `bin/phlix list` — never
 * builds the DI container or opens a database connection.
 */
#[AsCommand(name: 'library:list', description: 'List all configured media libraries')]
final class LibraryListCommand extends Command
{
    /** @var callable(): LibraryManager Lazy factory for the backing manager. */
    private $libraryManagerFactory;

    /**
     * @param callable(): LibraryManager $libraryManagerFactory Lazy factory
     *        returning the backing {@see LibraryManager}. Invoked only inside
     *        {@see execute()}, never at registration time.
     */
    public function __construct(callable $libraryManagerFactory)
    {
        $this->libraryManagerFactory = $libraryManagerFactory;
        parent::__construct();
    }

    /**
     * Render the library list.
     *
     * @return int {@see Command::SUCCESS} (0) once the list is rendered, or
     *         {@see Command::FAILURE} (1) when the manager throws.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $manager = ($this->libraryManagerFactory)();
            $libraries = $manager->getAllLibraries();
        } catch (Throwable $e) {
            $output->writeln('<error>Failed to list libraries: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($libraries === []) {
            $output->writeln('No libraries configured.');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Name', 'Type', 'Paths']);

        foreach ($libraries as $library) {
            $id = isset($library['id']) && is_scalar($library['id']) ? (string) $library['id'] : '';
            $name = isset($library['name']) && is_scalar($library['name']) ? (string) $library['name'] : '';
            $type = isset($library['type']) && is_scalar($library['type']) ? (string) $library['type'] : '';
            $paths = $this->formatPaths($library['paths'] ?? null);

            $table->addRow([$id, $name, $type, $paths]);
        }

        $table->render();

        return Command::SUCCESS;
    }

    /**
     * Render the library `paths` value (a decoded array) as a newline-joined
     * string. Non-array / non-string entries are skipped.
     *
     * @param mixed $paths The decoded `paths` value from a library row.
     *
     * @return string Newline-separated list of path strings (empty when none).
     */
    private function formatPaths(mixed $paths): string
    {
        if (!is_array($paths)) {
            return '';
        }

        $out = [];
        foreach ($paths as $path) {
            if (is_string($path)) {
                $out[] = $path;
            }
        }

        return implode("\n", $out);
    }
}
