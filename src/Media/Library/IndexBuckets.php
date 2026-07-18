<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

/**
 * Pure bucketing helper: transforms pre-sorted distinct value+count pairs into
 * bucket metadata for the media index rail.
 *
 * No I/O, no state — a pure transformation of its inputs.
 */
final class IndexBuckets
{
    public const FIELD_NAME = 'name';
    public const FIELD_YEAR = 'year';
    public const FIELD_RATING = 'rating';
    public const FIELD_RUNTIME = 'runtime';
    public const FIELD_DATE_ADDED = 'date_added';
    public const FIELD_GENRE = 'genre';
    public const FIELD_ARTIST = 'artist';

    /** Max year labels shown on the rail before years are grouped into ranges. */
    private const MAX_YEAR_BUCKETS = 25;

    /**
     * Rating order mapping — least to most restrictive. Derived from the single
     * canonical rank list {@see ContentRating::RANKS} so the rating facet rail
     * shows the same movie + interleaved TV ratings the filter recognizes.
     *
     * @var array<string, int>
     */
    private const RATING_ORDER = ContentRating::RANKS;

    /**
     * Build bucket metadata for a given field from pre-sorted distinct values.
     *
     * @param string $field One of the FIELD_* constants.
     * @param array<int, array{value: string|int, count: int}> $distincts Pre-sorted by value ASC.
     * @param string $order 'asc' | 'desc'
     * @param int|null $now Reference "now" timestamp for the relative date_added
     *        buckets (defaults to the current time); injectable so tests are
     *        deterministic regardless of the calendar day they run on.
     * @return array<int, array{key: string, label: string, offset: int, count: int}>
     */
    public function build(string $field, array $distincts, string $order, ?int $now = null): array
    {
        $field = $field ?: self::FIELD_NAME;

        $buckets = match ($field) {
            self::FIELD_NAME => $this->bucketsForName($distincts),
            self::FIELD_YEAR => $this->bucketsForYear($distincts),
            self::FIELD_RATING => $this->bucketsForRating($distincts),
            self::FIELD_RUNTIME => $this->bucketsForRuntime($distincts),
            self::FIELD_DATE_ADDED => $this->bucketsForDateAdded($distincts, $now),
            self::FIELD_GENRE => $this->bucketsForGenre($distincts),
            // Artist buckets are A-Z first-letter jumps (same as names), so a
            // library with hundreds of artists gets a compact letter rail.
            self::FIELD_ARTIST => $this->bucketsForName($distincts),
            default => $this->bucketsForName($distincts),
        };

        if ($order === 'desc') {
            $buckets = array_reverse($buckets);
        }

        return $this->withOffsets($buckets);
    }

    /**
     * Compute cumulative offsets from counts (offsets always cumulative from 0).
     *
     * @param array<int, array{key: string, label: string, offset?: int, count: int}> $buckets
     * @return array<int, array{key: string, label: string, offset: int, count: int}>
     */
    public function withOffsets(array $buckets): array
    {
        $offset = 0;
        $result = [];

        foreach ($buckets as $bucket) {
            $result[] = [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'offset' => $offset,
                'count' => $bucket['count'],
            ];
            $offset += $bucket['count'];
        }

        return $result;
    }

    /**
     * Name field: always buckets by first letter (A–Z + #). Never collapses.
     *
     * @param array<int, array{value: string|int, count: int}> $distincts
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function bucketsForName(array $distincts): array
    {
        $buckets = [];

        foreach ($distincts as $item) {
            $value = (string) $item['value'];
            $count = $item['count'];

            $letter = $this->firstLetter($value);
            $letter = $letter !== '' ? $letter : '#';

            if (!isset($buckets[$letter])) {
                $buckets[$letter] = ['key' => $letter, 'label' => $letter, 'count' => 0];
            }
            $buckets[$letter]['count'] += $count;
        }

        // Sort by key ascending (A before B before ... Z before #)
        ksort($buckets);

        return array_values($buckets);
    }

    /**
     * Genre field: one bucket per distinct (primary) genre, keyed + labelled by
     * the genre name and ordered alphabetically (the caller reverses for desc).
     * Blank/absent genres are skipped. Cumulative offsets are added by
     * {@see self::withOffsets()} against the genre-sorted grid.
     *
     * @param array<int, array{value: string|int, count: int}> $distincts
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function bucketsForGenre(array $distincts): array
    {
        $buckets = [];
        foreach ($distincts as $item) {
            $value = trim((string) $item['value']);
            if ($value === '') {
                continue;
            }
            if (!isset($buckets[$value])) {
                $buckets[$value] = ['key' => $value, 'label' => $value, 'count' => 0];
            }
            $buckets[$value]['count'] += $item['count'];
        }
        ksort($buckets);

        return array_values($buckets);
    }

    /**
     * Year field: one bucket per year if ≤30 distinct; decade ranges if >30 distinct.
     *
     * @param array<int, array{value: string|int, count: int}> $distincts
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function bucketsForYear(array $distincts): array
    {
        // Collapse to year → count, dropping unknown/invalid years (0/null) — the
        // grid files those at the end where the rail can't (and needn't) jump.
        $years = [];
        foreach ($distincts as $item) {
            $year = (int) $item['value'];
            if ($year <= 0) {
                continue;
            }
            $years[$year] = ($years[$year] ?? 0) + $item['count'];
        }
        ksort($years);
        $distinctYears = array_keys($years);
        $n = count($distinctYears);

        // Few enough distinct years → one bucket per year.
        if ($n <= self::MAX_YEAR_BUCKETS) {
            $buckets = [];
            foreach ($years as $year => $count) {
                $buckets[] = ['key' => (string) $year, 'label' => (string) $year, 'count' => $count];
            }
            return $buckets;
        }

        // Many years (e.g. 1915–2028) → downsample to ~MAX_YEAR_BUCKETS labelled
        // by REAL years spread across the range, each covering a contiguous slice
        // of years so the rail reads like "…'28 '23 '19 …'15" (newest-first when
        // the caller reverses for desc). Each bucket's label/offset is the FIRST
        // (oldest) year in its slice, so the cumulative offset lands on that
        // slice's first item in the year-sorted grid.
        $perBucket = (int) ceil($n / self::MAX_YEAR_BUCKETS);
        $buckets = [];
        for ($i = 0; $i < $n; $i += $perBucket) {
            $slice = array_slice($distinctYears, $i, $perBucket);
            $count = 0;
            foreach ($slice as $year) {
                $count += $years[$year];
            }
            $boundary = (string) $slice[0];
            $buckets[] = ['key' => $boundary, 'label' => $boundary, 'count' => $count];
        }

        return $buckets;
    }

    /**
     * Rating field: one fixed bucket per {@see ContentRating::RANKS} value (the
     * movie ratings plus the interleaved TV ratings) plus a trailing Unrated
     * bucket for null/empty/unknown ratings.
     *
     * @param array<int, array{value: string|int, count: int}> $distincts
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function bucketsForRating(array $distincts): array
    {
        // Initialize every RATING_ORDER bucket with 0 count
        $buckets = [];
        foreach (self::RATING_ORDER as $rating => $_order) {
            $buckets[$rating] = ['key' => $rating, 'label' => $rating, 'count' => 0];
        }

        // Add Unrated bucket for null/empty/missing/unknown ratings
        $buckets['Unrated'] = ['key' => 'Unrated', 'label' => 'Unrated', 'count' => 0];

        // Distribute counts from distincts
        foreach ($distincts as $item) {
            $value = $item['value'];
            $count = $item['count'];

            if ($value === null || $value === '' || $value === 'Unrated') {
                $buckets['Unrated']['count'] += $count;
            } elseif (isset($buckets[(string) $value])) {
                $buckets[(string) $value]['count'] += $count;
            } else {
                // Rating value not in RATING_ORDER — treat as Unrated
                $buckets['Unrated']['count'] += $count;
            }
        }

        // Return in RATING_ORDER sequence
        $result = [];
        foreach (self::RATING_ORDER as $rating => $_order) {
            $result[] = $buckets[$rating];
        }
        $result[] = $buckets['Unrated'];

        return $result;
    }

    /**
     * Runtime field: always 5 fixed ranges (1-30, 31-60, 61-90, 91-120, 120+).
     *
     * @param array<int, array{value: string|int, count: int}> $distincts
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function bucketsForRuntime(array $distincts): array
    {
        $ranges = [
            '1-30min' => ['key' => '1-30min', 'label' => '1-30min', 'min' => 1, 'max' => 30, 'count' => 0],
            '31-60min' => ['key' => '31-60min', 'label' => '31-60min', 'min' => 31, 'max' => 60, 'count' => 0],
            '61-90min' => ['key' => '61-90min', 'label' => '61-90min', 'min' => 61, 'max' => 90, 'count' => 0],
            '91-120min' => ['key' => '91-120min', 'label' => '91-120min', 'min' => 91, 'max' => 120, 'count' => 0],
            '120min+' => ['key' => '120min+', 'label' => '120min+', 'min' => 121, 'max' => PHP_INT_MAX, 'count' => 0],
        ];

        foreach ($distincts as $item) {
            $runtime = (int) $item['value'];
            $count = $item['count'];

            if ($runtime <= 0) {
                continue;
            }

            foreach ($ranges as $range) {
                if ($runtime >= $range['min'] && $runtime <= $range['max']) {
                    $ranges[$range['key']]['count'] += $count;
                    break;
                }
            }
        }

        return array_values($ranges);
    }

    /**
     * date_added field: always 5 relative buckets (Today, This week, This month, This year, Older).
     *
     * @param array<int, array{value: string|int, count: int}> $distincts
     * @param int|null $now Reference "now" (defaults to the current time).
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function bucketsForDateAdded(array $distincts, ?int $now = null): array
    {
        $now = $now ?? time();
        $todayStart = strtotime('today midnight', $now);
        $weekStart = strtotime('monday this week midnight', $now);
        $monthStart = strtotime('first day of this month midnight', $now);
        $yearStart = strtotime('first day of January this year midnight', $now);

        $buckets = [
            'Today' => ['key' => 'Today', 'label' => 'Today', 'count' => 0],
            'This week' => ['key' => 'This week', 'label' => 'This week', 'count' => 0],
            'This month' => ['key' => 'This month', 'label' => 'This month', 'count' => 0],
            'This year' => ['key' => 'This year', 'label' => 'This year', 'count' => 0],
            'Older' => ['key' => 'Older', 'label' => 'Older', 'count' => 0],
        ];

        foreach ($distincts as $item) {
            $value = $item['value'];
            $count = $item['count'];

            if (!is_string($value) || $value === '') {
                $buckets['Older']['count'] += $count;
                continue;
            }

            $timestamp = strtotime($value);
            if ($timestamp === false) {
                $buckets['Older']['count'] += $count;
                continue;
            }

            if ($timestamp >= $todayStart) {
                $buckets['Today']['count'] += $count;
            } elseif ($timestamp >= $weekStart) {
                $buckets['This week']['count'] += $count;
            } elseif ($timestamp >= $monthStart) {
                $buckets['This month']['count'] += $count;
            } elseif ($timestamp >= $yearStart) {
                $buckets['This year']['count'] += $count;
            } else {
                $buckets['Older']['count'] += $count;
            }
        }

        return array_values($buckets);
    }

    /**
     * Extract the first letter from a string, uppercased. Returns '' for empty input.
     */
    private function firstLetter(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $first = mb_substr($value, 0, 1, 'UTF-8');
        $firstUpper = mb_strtoupper($first, 'UTF-8');
        // Fold non-A-Z to #
        if ($firstUpper >= 'A' && $firstUpper <= 'Z') {
            return $firstUpper;
        }
        return '#';
    }
}
