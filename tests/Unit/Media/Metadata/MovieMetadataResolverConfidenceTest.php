<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Metadata\Imdb\ImdbLookup;
use Phlix\Media\Metadata\MatchConfidencePolicy;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\TmdbProvider;

/**
 * W3/F3 — the match-confidence gate on
 * {@see MovieMetadataResolver::resolveTmdbId()}'s formerly-blind first-result
 * path, plus the pure {@see MovieMetadataResolver::matchConfidence()} score
 * table it gates on.
 *
 * Two load-bearing invariants:
 *
 *  1. BEHAVIOUR PRESERVATION: at the shipped default (threshold 0.0) even a
 *     garbage first result is accepted — byte-identical to the pre-setting
 *     `$results[0]` path (the scorer is not even invoked).
 *  2. NO RE-RANKING: the gate only accepts/rejects the candidate the old code
 *     would have taken unconditionally; a rejected first result routes to the
 *     SAME no-match path an empty result set takes.
 */
final class MovieMetadataResolverConfidenceTest extends TestCase
{
    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    /**
     * A policy fixed to one effective threshold, built over a mocked
     * SettingsRepository exactly as the DI factory builds the real one.
     */
    private function thresholdPolicy(float $threshold): MatchConfidencePolicy
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->with(MatchConfidencePolicy::SETTING_KEY)
            ->willReturn($threshold);

        return new MatchConfidencePolicy($settings);
    }

    /**
     * Resolver over fakes: TMDB search returns $searchResults, the offline
     * IMDb lookup misses (so the TMDB path alone decides the outcome), and
     * getDetails echoes a minimal matrix row when it is reached at all.
     */
    private function resolverWithSearchResults(array $searchResults, ?MatchConfidencePolicy $policy): MovieMetadataResolver
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        $imdb->method('lookup')->willReturn(null);
        $imdb->method('lookupByAka')->willReturn(null);
        $tmdb->method('search')->willReturn($searchResults);
        $tmdb->method('getDetails')->willReturn(['name' => 'Details', 'overview' => '', 'year' => 1999]);

        return new MovieMetadataResolver($tmdb, $imdb, null, null, null, null, $policy);
    }

    // ------------------------------------------------------------------
    // matchConfidence() — the pure score table
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>, string, int|null, float}>
     */
    public static function scoreTable(): array
    {
        return [
            'exact title + exact year = 1.0' => [
                ['id' => '603', 'title' => 'The Matrix', 'release_date' => '1999-03-31'],
                'The Matrix', 1999, 1.0,
            ],
            'case/whitespace variants still 1.0' => [
                ['id' => '603', 'title' => 'the   MATRIX', 'release_date' => '1999-03-31'],
                '  The Matrix ', 1999, 1.0,
            ],
            'original_title fallback when title empty' => [
                ['id' => '603', 'title' => '', 'original_title' => 'The Matrix', 'release_date' => '1999'],
                'The Matrix', 1999, 1.0,
            ],
            'same title different year = 0.7 (year credit lost)' => [
                ['id' => '98', 'title' => 'The Matrix', 'release_date' => '2003-05-15'],
                'The Matrix', 1999, 0.7,
            ],
            'same title NO candidate year = 0.7 (absence is not corroboration)' => [
                ['id' => '98', 'title' => 'The Matrix', 'release_date' => ''],
                'The Matrix', 1999, 0.7,
            ],
            'same title malformed partial date = 0.7' => [
                ['id' => '98', 'title' => 'The Matrix', 'release_date' => '1999-08'],
                'The Matrix', 1999, 0.7,
            ],
            'exact title, wanted year unknown = 1.0 (year not comparable)' => [
                ['id' => '98', 'title' => 'The Matrix', 'release_date' => '2003-05-15'],
                'The Matrix', null, 1.0,
            ],
            'empty candidate title + exact year = 0.3 (bare year credit)' => [
                ['id' => '603', 'title' => '', 'release_date' => '1999-03-31'],
                'The Matrix', 1999, 0.3,
            ],
            'empty candidate title + no year = 0.0 floor' => [
                ['id' => '603', 'title' => '', 'release_date' => ''],
                'The Matrix', 1999, 0.0,
            ],
            'fuzzy edge "Matrix" vs "The Matrix" + year = 0.825' => [
                ['id' => '603', 'title' => 'Matrix', 'release_date' => '1999-03-31'],
                'The Matrix', 1999, 0.825,
            ],
            'sequel-ish "The Matrix 2" vs "The Matrix", year mismatch ≈ 0.636' => [
                ['id' => '604', 'title' => 'The Matrix 2', 'release_date' => '2003-05-15'],
                'The Matrix', 1999, 0.7 * (2 * 10 / 22),
            ],
            'unrelated title with matching year = 0.7*0.2105+0.3 ≈ 0.447' => [
                // similar_text('the matrix','inception') shares a 2-char
                // pattern → 2*2/19 = 0.2105…; measured, not guessed.
                ['id' => '27205', 'title' => 'inception', 'release_date' => '1999-07-16'],
                'The Matrix', 1999, 0.7 * (2 * 2 / 19) + 0.3,
            ],
        ];
    }

    /**
     * @dataProvider scoreTable
     *
     * @param array<string, mixed> $candidate
     */
    public function test_match_confidence_score_table(array $candidate, string $wanted, ?int $year, float $expected): void
    {
        $this->assertEqualsWithDelta(
            $expected,
            MovieMetadataResolver::matchConfidence($candidate, $wanted, $year),
            1e-9
        );
    }

    public function test_score_never_leaves_the_zero_to_one_domain(): void
    {
        // Domain guard for every composition: a NaN/over-1 product would break
        // the "0..1 heuristic score" contract the policy docblock advertises.
        foreach ([['title' => 'x', 'release_date' => '1999'], []] as $candidate) {
            $score = MovieMetadataResolver::matchConfidence($candidate, '', 1999);
            $this->assertGreaterThanOrEqual(0.0, $score);
            $this->assertLessThanOrEqual(1.0, $score);
        }
    }

    // ------------------------------------------------------------------
    // The gate — threshold matrix against fake result sets
    // ------------------------------------------------------------------

    public function test_default_zero_threshold_accepts_a_garbage_first_result_behavior_preserved(): void
    {
        // THE behaviour-preservation pin: at the shipped default even a first
        // result with an EMPTY title and no year is taken — exactly the old
        // blind `$results[0]` path. The scorer must not run at all.
        $resolver = $this->resolverWithSearchResults(
            [['id' => '603', 'title' => '', 'release_date' => '']],
            null, // legacy construction: store-less policy → 0.0
        );
        $result = $resolver->resolve('The Matrix', 1999);

        $this->assertNotNull($result, 'threshold 0.0 must reproduce the pre-setting blind acceptance');
        $this->assertSame('603', $result['external_ids']['tmdb']);
    }

    public function test_explicit_zero_threshold_from_a_store_also_accepts_garbage(): void
    {
        $resolver = $this->resolverWithSearchResults(
            [['id' => '603', 'title' => '', 'release_date' => '']],
            $this->thresholdPolicy(0.0),
        );
        $this->assertNotNull($resolver->resolve('The Matrix', 1999));
    }

    public function test_threshold_rejects_a_weak_first_result_to_the_no_match_path(): void
    {
        // Same-title-different-year scores 0.7 < 0.9 → rejected. The IMDb
        // fakes miss too, so a correct rejection surfaces as a FULL no-match
        // (null) — the same path an empty result set takes.
        $resolver = $this->resolverWithSearchResults(
            [['id' => '603', 'title' => 'The Matrix', 'release_date' => '2003-05-15']],
            $this->thresholdPolicy(0.9),
        );
        $this->assertNull($resolver->resolve('The Matrix', 1999));
    }

    public function test_threshold_05_accepts_the_same_title_wrong_year_candidate(): void
    {
        // 0.7 >= 0.5 → accepted → getDetails runs → non-null merge.
        $resolver = $this->resolverWithSearchResults(
            [['id' => '603', 'title' => 'The Matrix', 'release_date' => '2003-05-15']],
            $this->thresholdPolicy(0.5),
        );
        $result = $resolver->resolve('The Matrix', 1999);

        $this->assertNotNull($result);
        $this->assertSame('603', $result['external_ids']['tmdb']);
    }

    public function test_exact_title_and_year_clears_the_strictest_10_threshold(): void
    {
        $resolver = $this->resolverWithSearchResults(
            [['id' => '603', 'title' => 'The Matrix', 'release_date' => '1999-03-31']],
            $this->thresholdPolicy(1.0),
        );
        $this->assertNotNull($resolver->resolve('The Matrix', 1999));
    }

    public function test_boundary_is_inclusive_confidence_equal_to_threshold_is_accepted(): void
    {
        // 0.7 score against a 0.7 threshold must PASS (gate is `< threshold`
        // rejects). Exact-equal doubles: 0.7*1.0+0.3*0.0 and (float)'0.7' are
        // the same IEEE value.
        $resolver = $this->resolverWithSearchResults(
            [['id' => '603', 'title' => 'The Matrix', 'release_date' => '2003-05-15']],
            $this->thresholdPolicy(0.7),
        );
        $this->assertNotNull($resolver->resolve('The Matrix', 1999));
    }

    public function test_fuzzy_title_edge_falls_below_the_09_threshold(): void
    {
        // 'Matrix' vs 'The Matrix' + exact year = 0.825 < 0.9 → rejected.
        $resolver = $this->resolverWithSearchResults(
            [['id' => '603', 'title' => 'Matrix', 'release_date' => '1999-03-31']],
            $this->thresholdPolicy(0.9),
        );
        $this->assertNull($resolver->resolve('The Matrix', 1999));
    }

    public function test_unrelated_title_with_matching_year_falls_below_the_05_threshold(): void
    {
        // 0.447… < 0.5 → rejected (the year alone never carries a match over
        // the bar — title evidence must contribute too).
        $resolver = $this->resolverWithSearchResults(
            [['id' => '27205', 'title' => 'inception', 'release_date' => '1999-07-16']],
            $this->thresholdPolicy(0.5),
        );
        $this->assertNull($resolver->resolve('The Matrix', 1999));
    }

    public function test_the_gate_never_reranks_a_rejected_first_result_never_promotes_the_second(): void
    {
        // Even though result #2 is a perfect match, the ratified scope gates
        // the CANDIDATE the old code would have taken — it does not re-rank.
        // If this ever changes, the follow-up ticket should say so out loud.
        $resolver = $this->resolverWithSearchResults(
            [
                ['id' => '999', 'title' => 'inception', 'release_date' => '2010-07-16'],
                ['id' => '603', 'title' => 'The Matrix', 'release_date' => '1999-03-31'],
            ],
            $this->thresholdPolicy(0.9),
        );
        $this->assertNull($resolver->resolve('The Matrix', 1999));
    }

    public function test_the_imdb_id_path_stays_ungated_exact_identity_lookup(): void
    {
        // An tt… lookup is external identity, not a fuzzy guess — no title on
        // the found row must not trip any threshold.
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);
        $tmdb->method('findByImdbId')->willReturn(['id' => '603']);
        $tmdb->method('getDetails')->willReturn(['name' => 'The Matrix', 'year' => 1999]);
        $tmdb->expects($this->never())->method('search');
        $imdb->method('getByImdbId')->willReturn(null);

        $resolver = new MovieMetadataResolver($tmdb, $imdb, null, null, null, null, $this->thresholdPolicy(1.0));
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093']);

        $this->assertNotNull($result);
        $this->assertSame('603', $result['external_ids']['tmdb']);
    }

    public function test_a_weak_candidate_rejection_still_lets_imdb_data_carry_the_result(): void
    {
        // The no-match routing is TMDB-side ONLY: an offline IMDb row that
        // carries no discoverable imdb_id leaves the resolver on the search
        // path, and when the gate rejects there the merge still proceeds on
        // the IMDb payload — the exact shape the empty-result-set path yields
        // (cf. testImdbOnlyWhenTmdbThrows in the existing suite). The
        // load-bearing assertions: imdb-only provenance and getDetails never
        // fired for the rejected candidate.
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);
        $tmdb->expects($this->never())->method('getDetails');
        $tmdb->method('search')->willReturn([['id' => '999', 'title' => 'inception', 'release_date' => '2010-07-16']]);
        $imdb->method('lookup')->willReturn([
            // No imdb_id key on purpose — see the comment above: this is the
            // one shape where IMDb data exists yet the search path still runs.
            'title' => 'The Matrix',
            'year' => 1999,
            'genres' => ['Action'],
            'average_rating' => 8.7,
            'num_votes' => 1900000,
            'runtime_minutes' => 136,
        ]);
        $imdb->method('lookupByAka')->willReturn(null);

        $resolver = new MovieMetadataResolver($tmdb, $imdb, null, null, null, null, $this->thresholdPolicy(0.9));
        $result = $resolver->resolve('The Matrix', 1999);

        $this->assertNotNull($result);
        $this->assertSame(['imdb'], $result['sources']);
        $this->assertArrayNotHasKey('tmdb', $result['external_ids']);
    }

    public function test_the_threshold_is_read_live_per_resolve_call(): void
    {
        // Integration-shape proof of the resident-process law at the CONSUMER:
        // two resolves must hit the store twice — a flipped first result with
        // the override raised between calls proves nothing is cached in the
        // resolver or policy beyond the store handle.
        $reads = 0;
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->willReturnCallback(function (string $key) use (&$reads): float {
                $reads++;

                return $reads === 1 ? 0.0 : 0.9;
            });
        $policy = new MatchConfidencePolicy($settings);

        $resolver = $this->resolverWithSearchResults(
            [['id' => '999', 'title' => 'inception', 'release_date' => '2010-07-16']],
            $policy,
        );

        $this->assertNotNull($resolver->resolve('The Matrix', 1999), 'first call: gate off (0.0) → blind accept');
        $this->assertNull($resolver->resolve('The Matrix', 1999), 'second call: raised to 0.9 → rejected');
        $this->assertSame(2, $reads);
    }
}
