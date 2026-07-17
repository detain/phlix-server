<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Imdb;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Metadata\Imdb\ImdbDatasetImporter;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see ImdbDatasetImporter}: parse + filter + ratings-join +
 * batched UPSERT, exercised with tiny in-repo fixture `.tsv.gz` files written
 * in setUp via gzencode. No network is touched (importFromFiles + a captured
 * mock Connection).
 *
 * @since 0.21.0
 */
class ImdbDatasetImporterTest extends TestCase
{
    private string $tmpDir;
    private string $basicsPath;
    private string $ratingsPath;
    private string $akasPath;

    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../../config/logger.php');

        $this->tmpDir = sys_get_temp_dir() . '/phlix-imdb-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0775, true);

        $basics = implode("\t", [
            'tconst', 'titleType', 'primaryTitle', 'originalTitle',
            'isAdult', 'startYear', 'endYear', 'runtimeMinutes', 'genres',
        ]) . "\n";
        // A movie (kept).
        $basics .= "tt0133093\tmovie\tThe Matrix\tThe Matrix\t0\t1999\t\\N\t136\tAction,Sci-Fi\n";
        // A tvMovie (kept).
        $basics .= "tt0000005\ttvMovie\tA Quiet Place\tA Quiet Place\t0\t2018\t\\N\t90\tHorror\n";
        // A tvSeries (filtered out).
        $basics .= "tt0903747\ttvSeries\tBreaking Bad\tBreaking Bad\t0\t2008\t2013\t49\tCrime,Drama\n";
        // A short (filtered out).
        $basics .= "tt0000001\tshort\tCarmencita\tCarmencita\t0\t1894\t\\N\t1\tDocumentary\n";
        // A movie with no rating in the ratings file (rating fields => null).
        $basics .= "tt9999999\tmovie\tObscure Film\t\\N\t0\t2021\t\\N\t\\N\tDrama\n";

        $ratings = implode("\t", ['tconst', 'averageRating', 'numVotes']) . "\n";
        $ratings .= "tt0133093\t8.7\t1900000\n";
        $ratings .= "tt0000005\t7.5\t500000\n";
        $ratings .= "tt0903747\t9.5\t2000000\n";

        $akas = implode("\t", [
            'titleId', 'ordering', 'title', 'region', 'language', 'types', 'attributes', 'isOriginalTitle',
        ]) . "\n";
        // Alternate title for The Matrix (kept: normalized differs from primary).
        $akas .= "tt0133093\t1\tMatrix - Die Vollendung\tDE\tde\timdbDisplay\t\\N\t0\n";
        // Duplicate of the primary title (normalized == 'matrix') — skipped.
        $akas .= "tt0133093\t2\tThe Matrix\tUS\ten\t\\N\t\\N\t1\n";
        // Localized title for A Quiet Place (kept).
        $akas .= "tt0000005\t1\tUn Lugar Tranquilo\tES\tes\t\\N\t\\N\t0\n";
        // aka for a tvSeries never imported into imdb_titles — skipped (bounding).
        $akas .= "tt0903747\t1\tBreaking Bad ES\tES\tes\t\\N\t\\N\t0\n";

        $this->basicsPath = $this->tmpDir . '/title.basics.tsv.gz';
        $this->ratingsPath = $this->tmpDir . '/title.ratings.tsv.gz';
        $this->akasPath = $this->tmpDir . '/title.akas.tsv.gz';

        file_put_contents($this->basicsPath, (string) gzencode($basics));
        file_put_contents($this->ratingsPath, (string) gzencode($ratings));
        file_put_contents($this->akasPath, (string) gzencode($akas));
    }

    protected function tearDown(): void
    {
        foreach ([$this->basicsPath, $this->ratingsPath, $this->akasPath] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    /**
     * Build an importer wired to a mock Connection that records every UPSERT.
     *
     * The captured-rows buffer is passed in by reference (rather than returned)
     * so the closure's `use (&$captured)` and the caller's assertions share the
     * exact same array — returning it inside an array would copy the (then still
     * empty) buffer by value and the recorded rows would never be visible.
     *
     * @param list<array{sql: string, params: list<mixed>}> $captured Recorder
     *                                                                 (filled by ref).
     */
    private function makeImporter(array &$captured): ImdbDatasetImporter
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$captured) {
                $captured[] = ['sql' => $sql, 'params' => $params];
                return [];
            }
        );

        return new ImdbDatasetImporter($db, [
            'basics_url' => 'https://example.test/basics',
            'ratings_url' => 'https://example.test/ratings',
            'akas_url' => 'https://example.test/akas',
            'cache_dir' => $this->tmpDir,
            'title_types' => ['movie', 'tvMovie'],
        ]);
    }

    public function testImportFromFilesFiltersNonMoviesJoinsRatingsAndUpserts(): void
    {
        $captured = [];
        $importer = $this->makeImporter($captured);

        $result = $importer->importFromFiles($this->basicsPath, $this->ratingsPath);

        // 3 movie-type rows kept (Matrix, A Quiet Place tvMovie, Obscure Film);
        // tvSeries + short filtered out. All 3 ratings loaded.
        $this->assertSame(3, $result['titles']);
        $this->assertSame(3, $result['ratings']);

        // One batched upsert statement.
        $this->assertCount(1, $captured);
        $this->assertStringContainsString('INSERT INTO imdb_titles', $captured[0]['sql']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $captured[0]['sql']);

        // 3 rows * 10 columns of params.
        $this->assertCount(30, $captured[0]['params']);

        // First row = The Matrix, with joined rating + normalized title.
        $params = $captured[0]['params'];
        $this->assertSame('tt0133093', $params[0]);
        $this->assertSame('The Matrix', $params[1]);
        $this->assertSame('The Matrix', $params[2]);   // original_title
        $this->assertSame('matrix', $params[3]);        // normalized_title
        $this->assertSame('movie', $params[4]);         // title_type
        $this->assertSame(1999, $params[5]);            // start_year
        $this->assertSame('Action,Sci-Fi', $params[6]); // genres
        $this->assertSame(136, $params[7]);             // runtime_minutes
        $this->assertSame(8.7, $params[8]);             // average_rating
        $this->assertSame(1900000, $params[9]);         // num_votes
    }

    public function testRowWithoutRatingHasNullRatingFields(): void
    {
        $captured = [];
        $importer = $this->makeImporter($captured);
        $importer->importFromFiles($this->basicsPath, $this->ratingsPath);

        $params = $captured[0]['params'];

        // Obscure Film is the 3rd kept row (index offset 20). It has \N original
        // title + \N runtime + no rating in the ratings file.
        $base = 20;
        $this->assertSame('tt9999999', $params[$base + 0]);
        $this->assertSame('Obscure Film', $params[$base + 1]);
        $this->assertNull($params[$base + 2]);   // original_title (\N)
        $this->assertSame('obscure film', $params[$base + 3]);
        $this->assertSame(2021, $params[$base + 5]);
        $this->assertNull($params[$base + 7]);   // runtime (\N)
        $this->assertNull($params[$base + 8]);   // average_rating (no rating)
        $this->assertNull($params[$base + 9]);   // num_votes (no rating)
    }

    public function testImportUsesInjectedDownloaderNoNetwork(): void
    {
        // Use a fresh, empty cache dir so import() triggers the downloader
        // (rather than reusing the fixture files already in $this->tmpDir).
        $cacheDir = sys_get_temp_dir() . '/phlix-imdb-cache-' . uniqid('', true);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $importer = new ImdbDatasetImporter($db, [
            'basics_url' => 'https://example.test/basics',
            'ratings_url' => 'https://example.test/ratings',
            'akas_url' => 'https://example.test/akas',
            'cache_dir' => $cacheDir,
            'title_types' => ['movie', 'tvMovie'],
        ]);

        $downloadCalls = [];
        $importer->setDownloader(function (string $url, string $dest) use (&$downloadCalls): bool {
            $downloadCalls[] = $url;
            // Copy the matching fixture into the (different) cache path.
            if (str_contains($url, 'basics')) {
                $src = $this->basicsPath;
            } elseif (str_contains($url, 'akas')) {
                $src = $this->akasPath;
            } else {
                $src = $this->ratingsPath;
            }
            copy($src, $dest);
            return true;
        });

        $result = $importer->import(true);

        $this->assertSame(3, $result['titles']);
        $this->assertSame(2, $result['akas']);
        $this->assertCount(3, $downloadCalls);

        // Cleanup.
        foreach (glob($cacheDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($cacheDir)) {
            rmdir($cacheDir);
        }
    }

    public function testImportFromFilesLoadsAkasBoundedAndDedupedAgainstPrimary(): void
    {
        $captured = [];
        $importer = $this->makeImporter($captured);

        $result = $importer->importFromFiles($this->basicsPath, $this->ratingsPath, $this->akasPath);

        // 3 movie titles (as before) + 2 kept akas rows.
        // Kept: tt0133093 'Matrix - Die Vollendung', tt0000005 'Un Lugar Tranquilo'.
        // Skipped: tt0133093 'The Matrix' (dup of primary), tt0903747 (not imported).
        $this->assertSame(3, $result['titles']);
        $this->assertSame(3, $result['ratings']);
        $this->assertSame(2, $result['akas']);

        // Two batched statements: one for imdb_titles, one for imdb_title_akas.
        $this->assertCount(2, $captured);
        $this->assertStringContainsString('INSERT INTO imdb_titles', $captured[0]['sql']);
        $this->assertStringContainsString('INSERT INTO imdb_title_akas', $captured[1]['sql']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $captured[1]['sql']);

        // 2 kept akas rows * 7 columns of params.
        $akasParams = $captured[1]['params'];
        $this->assertCount(14, $akasParams);

        // First kept aka = tt0133093 / 'Matrix - Die Vollendung' (region DE).
        $this->assertSame('tt0133093', $akasParams[0]);          // tconst
        $this->assertSame(1, $akasParams[1]);                    // ordering
        $this->assertSame('Matrix - Die Vollendung', $akasParams[2]); // title
        $this->assertSame('matrix die vollendung', $akasParams[3]);   // normalized_title
        $this->assertSame('DE', $akasParams[4]);                 // region
        $this->assertSame('de', $akasParams[5]);                 // language
        $this->assertSame(0, $akasParams[6]);                    // is_original_title

        // Second kept aka = tt0000005 / 'Un Lugar Tranquilo'.
        $this->assertSame('tt0000005', $akasParams[7]);
        $this->assertSame('un lugar tranquilo', $akasParams[10]);
    }

    public function testImportFromFilesWithoutAkasPathSkipsAkas(): void
    {
        $captured = [];
        $importer = $this->makeImporter($captured);

        $result = $importer->importFromFiles($this->basicsPath, $this->ratingsPath);

        $this->assertSame(3, $result['titles']);
        $this->assertSame(0, $result['akas']);
        // Only the imdb_titles upsert — no akas statement.
        $this->assertCount(1, $captured);
    }
}
