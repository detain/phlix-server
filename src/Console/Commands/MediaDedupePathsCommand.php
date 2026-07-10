<?php

/**
 * Phlix media server component: Commands.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Commands;

use Phlix\Media\Library\PathDeduper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `media:dedupe-paths` — Find and merge duplicate media items sharing the same path.
 *
 * When the same media file is scanned multiple times (e.g., different library scans
 * or race conditions), multiple rows can exist with the same path. This command
 * identifies those duplicates and merges them using a scoring algorithm to pick
 * the best row to keep.
 *
 * Scoring:
 *   - watch_history_count * 1
 *   - playback_state exists ? 5 : 0
 *   - user_item_data exists ? 5 : 0
 *   - media_markers count > 0 ? 3 : 0
 *   - rating_votes > 0 ? 2 : 0
 *   - rating_score (0-10)
 *
 * Tiebreak: lowest ID wins.
 *
 * Use --dry-run (default) to preview what would happen, or --apply to execute.
 */
#[AsCommand(name: 'media:dedupe-paths', description: 'Find and merge duplicate media items that share the same filesystem path')]
final class MediaDedupePathsCommand extends Command
{
    /** @var callable(): PathDeduper Lazy factory for the PathDeduper service */
    private $pathDeduperFactory;

    /**
     * @param callable(): PathDeduper $pathDeduperFactory Lazy factory returning
     *        the backing {@see PathDeduper}. Invoked only inside {@see execute()},
     *        never at registration time.
     */
    public function __construct(callable $pathDeduperFactory)
    {
        $this->pathDeduperFactory = $pathDeduperFactory;
        parent::__construct();
    }

    /**
     * Declare options.
     */
    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Preview what would be merged without making changes (default behavior)'
        );
        $this->addOption(
            'apply',
            null,
            InputOption::VALUE_NONE,
            'Actually perform the merges (without this, only a preview is shown)'
        );
        $this->addOption(
            'batch-size',
            null,
            InputOption::VALUE_REQUIRED,
            'Number of duplicate groups to process per batch (default: 500)',
            '500'
        );
    }

    /**
     * Run the dedupe operation.
     *
     * @return int {@see Command::SUCCESS} (0) on completion, or
     *         {@see Command::FAILURE} (1) on error.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Dry-run is the default and wins over --apply if both are given, so an
        // explicit --dry-run can never accidentally mutate data.
        $isDryRun = $input->getOption('dry-run') || !$input->getOption('apply');
        $batchSizeOpt = $input->getOption('batch-size');
        $batchSize = is_numeric($batchSizeOpt) ? (int) $batchSizeOpt : 500;

        // Register the semantic styles used below so their tags render as colour
        // rather than as literal "<keep>…</keep>" text.
        $formatter = $output->getFormatter();
        $formatter->setStyle('keep', new OutputFormatterStyle('green', null, ['bold']));
        $formatter->setStyle('delete', new OutputFormatterStyle('red'));
        $formatter->setStyle('header', new OutputFormatterStyle('cyan', null, ['bold']));
        $formatter->setStyle('summary', new OutputFormatterStyle('yellow', null, ['bold']));

        if ($isDryRun) {
            $output->writeln('<info>DRY-RUN mode: No changes will be made.</info>');
            $output->writeln('Use --apply to actually merge duplicates.');
            $output->writeln('');
        } else {
            $output->writeln('<comment>APPLY mode: Changes WILL be made.</comment>');
            $output->writeln('');
        }

        try {
            $deduper = ($this->pathDeduperFactory)();
        } catch (Throwable $e) {
            $output->writeln('<error>Failed to create PathDeduper: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // Find duplicate groups
        $output->write('Scanning for duplicate paths... ');
        $groups = $deduper->findDuplicateGroups();
        $output->writeln('done.');
        $output->writeln('');

        if ($groups === []) {
            $output->writeln('<info>No duplicate paths found.</info>');
            return Command::SUCCESS;
        }

        $totalGroups = count($groups);
        $totalDelete = 0;
        $totalKeep = 0;

        $output->writeln(sprintf('Found %d duplicate path group(s).', $totalGroups));
        $output->writeln(str_repeat('-', 80));
        $output->writeln('');

        $processed = 0;
        foreach ($groups as $group) {
            $processed++;
            if ($processed > $batchSize) {
                $output->writeln(sprintf('Batch limit (%d) reached. Stopping.', $batchSize));
                break;
            }

            $this->displayDuplicateGroup($output, $group, $deduper, $isDryRun, $totalDelete, $totalKeep);
        }

        $output->writeln('');
        $output->writeln(str_repeat('-', 80));
        $output->writeln('');
        $output->writeln('<summary>Summary:</summary>');
        $output->writeln(sprintf('  Total duplicate groups: %d', $processed));
        $output->writeln(sprintf('  Total rows to DELETE: %d', $totalDelete));
        $output->writeln(sprintf('  Total rows to KEEP:  %d', $totalKeep));

        if ($isDryRun) {
            $output->writeln('');
            $output->writeln('<info>DRY-RUN complete. Run with --apply to execute.</info>');
        }

        return Command::SUCCESS;
    }

    /**
     * Display details of a single duplicate group and process it.
     *
     * @param OutputInterface $output    Console output
     * @param array{path: string, library_id: string, library_name: string, items: list<array{id: string, name: string, type: string, created_at: string}>} $group Duplicate group data
     * @param PathDeduper     $deduper   PathDeduper instance
     * @param bool            $isDryRun  Whether this is a dry run
     * @param int             &$totalDelete Cumulative delete count (passed by reference)
     * @param int             &$totalKeep   Cumulative keep count (passed by reference)
     */
    private function displayDuplicateGroup(
        OutputInterface $output,
        array $group,
        PathDeduper $deduper,
        bool $isDryRun,
        int &$totalDelete,
        int &$totalKeep
    ): void {
        $path = $group['path'];
        $libraryName = $group['library_name'];
        $libraryId = $group['library_id'];
        $items = $group['items'];

        $output->writeln(sprintf(
            '<header>[%s] %s</header>',
            $libraryName,
            $libraryId
        ));
        $output->writeln(sprintf('  Path: %s', $path));
        $output->writeln(sprintf('  %d duplicate(s):', count($items)));

        // Score each item and pick the keeper
        $scored = [];
        foreach ($items as $item) {
            $score = $deduper->scoreItem($item['id']);
            $scored[] = [
                'item' => $item,
                'score' => $score,
            ];
        }

        // Sort by score desc, then id asc (tiebreak)
        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return strcmp($a['item']['id'], $b['item']['id']);
        });

        $keeper = $scored[0];
        $losers = array_slice($scored, 1);

        // Display KEEP row
        $output->writeln(sprintf(
            '  <keep>KEEP  </keep> id=%s score=%d type=%s created=%s',
            $keeper['item']['id'],
            $keeper['score'],
            $keeper['item']['type'],
            $keeper['item']['created_at']
        ));

        // Display DELETE rows
        foreach ($losers as $s) {
            $output->writeln(sprintf(
                '  <delete>DELETE</delete> id=%s score=%d type=%s',
                $s['item']['id'],
                $s['score'],
                $s['item']['type']
            ));
        }

        $totalKeep++;
        $totalDelete += count($losers);

        // If applying, perform the merge
        if (!$isDryRun) {
            $output->write('    Applying... ');

            try {
                $deduper->beginTrans();

                foreach ($losers as $s) {
                    $loserId = $s['item']['id'];
                    $keeperId = $keeper['item']['id'];

                    // Repoint all referencing tables
                    $deduper->repointReferencingTables($loserId, $keeperId);

                    // Delete the loser row
                    $deduper->deleteItem($loserId);
                }

                $deduper->commit();
                $output->writeln('<info>done</info>');
            } catch (Throwable $e) {
                $deduper->rollback();
                $output->writeln('<error>FAILED: ' . $e->getMessage() . '</error>');
            }
        }

        $output->writeln('');
    }
}
