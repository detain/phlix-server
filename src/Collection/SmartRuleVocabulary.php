<?php

/**
 * Phlix media server component: Collection.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 *
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Collection;

use Phlix\Media\Library\ItemRepository;

/**
 * Vocabulary of smart-collection rule builders.
 *
 * Each rule type maps to a SQL clause builder that returns WHERE fragments
 * and parameter bindings. The builders are composed by {@see apply()} to
 * produce a filtered result set from {@see ItemRepository}.
 *
 * Rule format: array with 'type' (string) and 'value' (mixed).
 * Example: [{type: 'actor', value: 'John'}, {type: 'decade', value: '1990s'}, ...]
 *
 * @since 1.3.0
 */
final class SmartRuleVocabulary
{
    /**
     * Map of rule type name → SQL clause builder callback.
     *
     * Each builder receives array $params (containing 'value' key) and returns
     * array{wheres: list<string>, bindings: list<mixed>, needsRatingJoin?: bool}.
     *
     * @var array<string, array{0: class-string, 1: non-empty-string}>
     */
    public const RULES = [
        'actor' => [self::class, 'buildActorClause'],
        'director' => [self::class, 'buildDirectorClause'],
        'decade' => [self::class, 'buildDecadeClause'],
        'rating_range' => [self::class, 'buildRatingRangeClause'],
        'tmdb_score' => [self::class, 'buildTmdbScoreClause'],
        'studio' => [self::class, 'buildStudioClause'],
        'network' => [self::class, 'buildNetworkClause'],
    ];

    /**
     * Apply a set of smart rules to the ItemRepository and return matching items.
     *
     * This is a convenience wrapper around ItemRepository::queryWithSmartRules().
     *
     * @param array<array{type: string, value: mixed}> $rules     List of rule descriptors
     * @param ItemRepository                          $repo      Repository to query
     * @param string|null                             $libraryId Optional library scope
     *
     * @return array<array<string, mixed>> Hydrated media items matching all rules
     */
    public static function apply(array $rules, ItemRepository $repo, ?string $libraryId = null): array
    {
        $result = $repo->queryWithSmartRules($rules, [], $libraryId);

        return $result['items'];
    }

    /**
     * Build SQL clause for actor filter.
     *
     * Matches actor name using JSON_SEARCH over both flat array format
     * ($.actors[*]) and object format ($.actors[*].name).
     *
     * @param array{value: mixed} $params Rule parameters
     *
     * @return array{wheres: list<string>, bindings: list<mixed>}
     */
    public static function buildActorClause(array $params): array
    {
        $value = isset($params['value']) && is_string($params['value']) ? $params['value'] : '';
        if ($value === '') {
            return ['wheres' => [], 'bindings' => []];
        }

        $escaped = addcslashes($value, '%_');

        // Match both flat ["Name", ...] and rich [{name: "Name"}, ...] actor shapes.
        $where = "(JSON_SEARCH(metadata_json, 'one', ?, NULL, '\$.actors[*]') IS NOT NULL"
            . " OR JSON_SEARCH(metadata_json, 'one', ?, NULL, '\$.actors[*].name') IS NOT NULL)";

        return [
            'wheres' => [$where],
            'bindings' => ['%' . $escaped . '%', '%' . $escaped . '%'],
        ];
    }

    /**
     * Build SQL clause for director filter.
     *
     * Matches director name using JSON_SEARCH over both flat and object array formats,
     * and also falls back to exact string match on $.director.
     *
     * @param array{value: mixed} $params Rule parameters
     *
     * @return array{wheres: list<string>, bindings: list<mixed>}
     */
    public static function buildDirectorClause(array $params): array
    {
        $value = isset($params['value']) && is_string($params['value']) ? $params['value'] : '';
        if ($value === '') {
            return ['wheres' => [], 'bindings' => []];
        }

        $escaped = addcslashes($value, '%_');

        // Director can be a flat string, array of strings, or array of objects.
        $where = "(JSON_SEARCH(metadata_json, 'one', ?, NULL, '\$.directors[*]') IS NOT NULL"
            . " OR JSON_SEARCH(metadata_json, 'one', ?, NULL, '\$.directors[*].name') IS NOT NULL"
            . " OR JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.director')) LIKE ?)";

        return [
            'wheres' => [$where],
            'bindings' => ['%' . $escaped . '%', '%' . $escaped . '%', '%' . $escaped . '%'],
        ];
    }

    /**
     * Build SQL clause for decade filter.
     *
     * Accepts decade strings like "2020", "1990s", "1990" and converts to
     * year BETWEEN start AND end.
     *
     * Examples:
     *   "2020"  → year BETWEEN 2020 AND 2029
     *   "1990s" → year BETWEEN 1990 AND 1999
     *   "1990"  → year BETWEEN 1990 AND 1999
     *
     * @param array{value: mixed} $params Rule parameters
     *
     * @return array{wheres: list<string>, bindings: list<mixed>}
     */
    public static function buildDecadeClause(array $params): array
    {
        $value = isset($params['value']) && is_string($params['value']) ? $params['value'] : '';
        if ($value === '') {
            return ['wheres' => [], 'bindings' => []];
        }

        // Normalize: strip 's' suffix so "1990s" → "1990"
        $normalized = rtrim($value, 's');
        if (!is_numeric($normalized)) {
            return ['wheres' => [], 'bindings' => []];
        }

        $decadeStart = (int) $normalized;
        $decadeEnd = $decadeStart + 9;

        $yearExpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.year')) AS SIGNED)";

        return [
            'wheres' => ["({$yearExpr} >= ? AND {$yearExpr} <= ?)"],
            'bindings' => [$decadeStart, $decadeEnd],
        ];
    }

    /**
     * Build SQL clause for rating range filter.
     *
     * Expects $params['value'] to be an array with 'min' and/or 'max' keys,
     * or a string like "7-10" or "7".
     *
     * @param array{value: mixed} $params Rule parameters
     *
     * @return array{wheres: list<string>, bindings: list<mixed>, needsRatingJoin: bool}
     */
    public static function buildRatingRangeClause(array $params): array
    {
        $value = $params['value'] ?? null;
        $min = null;
        $max = null;

        if (is_array($value)) {
            $min = isset($value['min']) && is_numeric($value['min']) ? (float) $value['min'] : null;
            $max = isset($value['max']) && is_numeric($value['max']) ? (float) $value['max'] : null;
        } elseif (is_string($value) && $value !== '') {
            // Parse "7-10" or "7" format
            if (str_contains($value, '-')) {
                $parts = explode('-', $value, 2);
                $min = is_numeric($parts[0]) ? (float) $parts[0] : null;
                $max = is_numeric($parts[1] ?? '') ? (float) $parts[1] : null;
            } else {
                $min = is_numeric($value) ? (float) $value : null;
            }
        }

        if ($min === null && $max === null) {
            return ['wheres' => [], 'bindings' => [], 'needsRatingJoin' => false];
        }

        $wheres = [];
        $bindings = [];

        if ($min !== null) {
            $wheres[] = 'COALESCE(AVG(r.score), 0) >= ?';
            $bindings[] = $min;
        }

        if ($max !== null) {
            $wheres[] = 'COALESCE(AVG(r.score), 0) <= ?';
            $bindings[] = $max;
        }

        return [
            'wheres' => $wheres,
            'bindings' => $bindings,
            'needsRatingJoin' => true,
        ];
    }

    /**
     * Build SQL clause for TMDB score range filter.
     *
     * Expects $params['value'] to be an array with 'min' and/or 'max' keys,
     * or a string like "7-10" or "7".
     *
     * @param array{value: mixed} $params Rule parameters
     *
     * @return array{wheres: list<string>, bindings: list<mixed>}
     */
    public static function buildTmdbScoreClause(array $params): array
    {
        $value = $params['value'] ?? null;
        $min = null;
        $max = null;

        if (is_array($value)) {
            $min = isset($value['min']) && is_numeric($value['min']) ? (float) $value['min'] : null;
            $max = isset($value['max']) && is_numeric($value['max']) ? (float) $value['max'] : null;
        } elseif (is_string($value) && $value !== '') {
            if (str_contains($value, '-')) {
                $parts = explode('-', $value, 2);
                $min = is_numeric($parts[0]) ? (float) $parts[0] : null;
                $max = is_numeric($parts[1] ?? '') ? (float) $parts[1] : null;
            } else {
                $min = is_numeric($value) ? (float) $value : null;
            }
        }

        if ($min === null && $max === null) {
            return ['wheres' => [], 'bindings' => []];
        }

        $tmdbExpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.tmdb_score')) AS DECIMAL(3,1))";
        $wheres = [];
        $bindings = [];

        if ($min !== null) {
            $wheres[] = "({$tmdbExpr} >= ? AND {$tmdbExpr} IS NOT NULL)";
            $bindings[] = $min;
        }

        if ($max !== null) {
            $wheres[] = "({$tmdbExpr} <= ? AND {$tmdbExpr} IS NOT NULL)";
            $bindings[] = $max;
        }

        return [
            'wheres' => $wheres,
            'bindings' => $bindings,
        ];
    }

    /**
     * Build SQL clause for studio filter.
     *
     * Matches studio name using JSON_SEARCH over production_companies array
     * and falls back to exact match on $.studio string.
     *
     * @param array{value: mixed} $params Rule parameters
     *
     * @return array{wheres: list<string>, bindings: list<mixed>}
     */
    public static function buildStudioClause(array $params): array
    {
        $value = isset($params['value']) && is_string($params['value']) ? $params['value'] : '';
        if ($value === '') {
            return ['wheres' => [], 'bindings' => []];
        }

        $escaped = addcslashes($value, '%_');

        // Match rich [{name: "Warner Bros"}, ...] and legacy single "studio" string.
        $where = "(JSON_SEARCH(metadata_json, 'one', ?, NULL,"
            . " '\$.production_companies[*].name') IS NOT NULL"
            . " OR JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.studio')) = ?)";

        return [
            'wheres' => [$where],
            'bindings' => ['%' . $escaped . '%', $value],
        ];
    }

    /**
     * Build SQL clause for TV network filter.
     *
     * Matches network name using JSON_SEARCH over networks array
     * ($.networks[*].name).
     *
     * @param array{value: mixed} $params Rule parameters
     *
     * @return array{wheres: list<string>, bindings: list<mixed>}
     */
    public static function buildNetworkClause(array $params): array
    {
        $value = isset($params['value']) && is_string($params['value']) ? $params['value'] : '';
        if ($value === '') {
            return ['wheres' => [], 'bindings' => []];
        }

        $escaped = addcslashes($value, '%_');

        $where = "JSON_SEARCH(metadata_json, 'one', ?, NULL, '\$.networks[*].name') IS NOT NULL";

        return [
            'wheres' => [$where],
            'bindings' => ['%' . $escaped . '%'],
        ];
    }
}
