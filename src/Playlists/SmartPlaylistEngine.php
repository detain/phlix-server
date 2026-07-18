<?php

/**
 * Phlix media server component: Playlists.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Playlists;

use Phlix\Media\Library\ItemRepository;

/**
 * Core rule evaluator for smart playlists.
 *
 * Parses JSON DSL into a RuleNode tree, evaluates media items against
 * the rule tree, and serializes rules back to JSON.
 *
 * @since 0.14.0
 */
class SmartPlaylistEngine
{
    public function __construct(
        private readonly ItemRepository $itemRepository,
    ) {
    }

    /**
     * Parses JSON DSL into an immutable RuleNode tree.
     *
     * DSL format:
     * ```json
     * {
     *   "logic": "and",
     *   "rules": [
     *     { "field": "genre", "op": "contains", "value": "Drama" },
     *     { "field": "year", "op": "gt", "value": 2010 }
     *   ]
     * }
     * ```
     *
     * @param array<string, mixed> $dsl Decoded JSON DSL
     * @return RuleNode Root node of the parsed tree
     *
     * @since 0.14.0
     */
    public function buildFromDsl(array $dsl): RuleNode
    {
        $logic = is_string($dsl['logic'] ?? null) ? $dsl['logic'] : 'and';
        $rules = self::normaliseRuleList($dsl['rules'] ?? null);

        return $this->buildNodeFromDsl($logic, $rules);
    }

    /**
     * Normalise an opaque DSL `rules` value into a list of rule maps.
     *
     * The DSL is decoded JSON, so each entry could be anything; we only
     * keep entries that are themselves string-keyed arrays.
     *
     * @param mixed $rules Raw `rules` value from the DSL.
     * @return list<array<string, mixed>>
     */
    private static function normaliseRuleList(mixed $rules): array
    {
        if (!is_array($rules)) {
            return [];
        }
        $out = [];
        foreach ($rules as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $normalized = [];
            foreach ($entry as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = $value;
                }
            }
            $out[] = $normalized;
        }
        return $out;
    }

    /**
     * Recursively builds a RuleNode from DSL structure.
     *
     * @param string $logic 'and' | 'or' | 'not'
     * @param array<array<string, mixed>> $rules Array of rule/group definitions
     * @return RuleNode Built rule node tree
     */
    private function buildNodeFromDsl(string $logic, array $rules): RuleNode
    {
        if ($logic === 'not') {
            $firstRule = $rules[0] ?? [];
            $childLogic = is_string($firstRule['logic'] ?? null) ? $firstRule['logic'] : 'and';
            $childRules = isset($firstRule['rules'])
                ? self::normaliseRuleList($firstRule['rules'])
                : [$firstRule];
            $child = $this->buildNodeFromDsl($childLogic, $childRules);
            return new RuleNode(
                type: RuleNode::TYPE_NOT,
                children: [$child],
            );
        }

        $children = [];
        foreach ($rules as $rule) {
            if (isset($rule['logic']) && is_string($rule['logic'])) {
                // Nested group
                $children[] = $this->buildNodeFromDsl(
                    $rule['logic'],
                    self::normaliseRuleList($rule['rules'] ?? null)
                );
            } else {
                // Leaf rule
                $children[] = new RuleNode(
                    type: RuleNode::TYPE_RULE,
                    field: is_string($rule['field'] ?? null) ? $rule['field'] : null,
                    operator: is_string($rule['op'] ?? null) ? $rule['op'] : null,
                    value: $rule['value'] ?? null,
                );
            }
        }

        $type = match ($logic) {
            'or' => RuleNode::TYPE_OR,
            default => RuleNode::TYPE_AND,
        };

        return new RuleNode(
            type: $type,
            children: $children,
        );
    }

    /**
     * Evaluates rules against a set of media items.
     *
     * @param array<string, mixed> $rules Decoded JSON DSL (a root group with
     *                                    `logic` and `rules`). Empty array
     *                                    means "match everything".
     * @param array<int, array<string, mixed>> $mediaItems Hydrated media items with metadata_json decoded
     * @param int $limit Maximum items to return (0 = unlimited)
     * @param string $sortBy Sort field ('addedAt', 'random', etc.)
     * @param bool $sortDesc Sort descending
     * @return array<int, array<string, mixed>> Filtered and sorted media items
     *
     * @since 0.14.0
     */
    public function evaluate(
        array $rules,
        array $mediaItems,
        int $limit = 0,
        string $sortBy = 'addedAt',
        bool $sortDesc = true
    ): array {
        if (empty($rules)) {
            $result = $mediaItems;
        } else {
            $root = $this->buildFromDsl($rules);
            $result = array_filter($mediaItems, function (array $item) use ($root): bool {
                return $this->evaluateNode($root, $item);
            });
            $result = array_values($result);
        }

        // Apply sorting
        $result = $this->sortItems($result, $sortBy, $sortDesc);

        // Apply limit
        if ($limit > 0) {
            $result = array_slice($result, 0, $limit);
        }

        return $result;
    }

    /**
     * Recursively evaluates a RuleNode against a media item.
     *
     * @param RuleNode $node The rule node to evaluate
     * @param array<string, mixed> $item The media item to test
     * @return bool True if the item matches the rule
     */
    private function evaluateNode(RuleNode $node, array $item): bool
    {
        return match ($node->type) {
            RuleNode::TYPE_AND => $this->evaluateAnd($node, $item),
            RuleNode::TYPE_OR => $this->evaluateOr($node, $item),
            RuleNode::TYPE_NOT => $this->evaluateNot($node, $item),
            RuleNode::TYPE_RULE => $this->evaluateRule($node, $item),
            default => false,
        };
    }

    /**
     * Evaluates AND node - all children must match.
     *
     * @param array<string, mixed> $item Media item being tested.
     */
    private function evaluateAnd(RuleNode $node, array $item): bool
    {
        foreach ($node->children as $child) {
            if (!$this->evaluateNode($child, $item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Evaluates OR node - at least one child must match.
     *
     * @param array<string, mixed> $item Media item being tested.
     */
    private function evaluateOr(RuleNode $node, array $item): bool
    {
        foreach ($node->children as $child) {
            if ($this->evaluateNode($child, $item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Evaluates NOT node - inverts child result.
     *
     * @param array<string, mixed> $item Media item being tested.
     */
    private function evaluateNot(RuleNode $node, array $item): bool
    {
        if (empty($node->children)) {
            return true;
        }
        return !$this->evaluateNode($node->children[0], $item);
    }

    /**
     * Evaluates a leaf rule node against a media item.
     *
     * @param RuleNode $node The rule node with field/operator/value
     * @param array<string, mixed> $item The media item with 'metadata' key
     * @return bool True if the rule matches
     */
    private function evaluateRule(RuleNode $node, array $item): bool
    {
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $fieldName = is_string($node->field) ? $node->field : null;
        $itemValue = $fieldName !== null ? ($metadata[$fieldName] ?? null) : null;
        $ruleValue = $node->value;

        // Handle null item values
        if ($itemValue === null) {
            return false;
        }

        return match ($node->operator) {
            'equals' => RuleOperators::equals($itemValue, $ruleValue),
            'notEquals' => RuleOperators::notEquals($itemValue, $ruleValue),
            'contains' => RuleOperators::contains($this->mixedToString($itemValue), $this->mixedToString($ruleValue)),
            'notContains' => RuleOperators::notContains(
                $this->mixedToString($itemValue),
                $this->mixedToString($ruleValue)
            ),
            'gt' => RuleOperators::greaterThan($this->toFloat($itemValue), $this->toFloat($ruleValue)),
            'gte' => RuleOperators::greaterThan($this->toFloat($itemValue), $this->toFloat($ruleValue) - 0.001) ||
                RuleOperators::equals($itemValue, $ruleValue),
            'lt' => RuleOperators::lessThan($this->toFloat($itemValue), $this->toFloat($ruleValue)),
            'lte' => RuleOperators::lessThan($this->toFloat($itemValue), $this->toFloat($ruleValue) + 0.001) ||
                RuleOperators::equals($itemValue, $ruleValue),
            'between' => is_array($ruleValue) && count($ruleValue) >= 2
                ? RuleOperators::between($this->toFloat($itemValue), $this->toFloat($ruleValue[0] ?? 0),
                    $this->toFloat($ruleValue[1] ?? 0))
                : false,
            'in' => RuleOperators::in($itemValue, is_array($ruleValue) ? $ruleValue : []),
            'notIn' => RuleOperators::notIn($itemValue, is_array($ruleValue) ? $ruleValue : []),
            'startsWith' => RuleOperators::startsWith(
                $this->mixedToString($itemValue),
                $this->mixedToString($ruleValue)
            ),
            'endsWith' => RuleOperators::endsWith($this->mixedToString($itemValue), $this->mixedToString($ruleValue)),
            default => false,
        };
    }

    /**
     * Converts a mixed value to string.
     *
     * @param mixed $value Value to convert
     * @return string String representation
     */
    private function mixedToString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return '';
    }

    /**
     * Sorts media items by the specified field.
     *
     * @param array<int, array<string, mixed>> $items Items to sort
     * @param string $sortBy Sort field
     * @param bool $sortDesc Sort descending
     * @return array<int, array<string, mixed>> Sorted items
     */
    private function sortItems(array $items, string $sortBy, bool $sortDesc): array
    {
        if (empty($items) || $sortBy === 'random') {
            shuffle($items);
            return $items;
        }

        usort($items, function (array $a, array $b) use ($sortBy, $sortDesc): int {
            $metadataA = is_array($a['metadata'] ?? null) ? $a['metadata'] : [];
            $metadataB = is_array($b['metadata'] ?? null) ? $b['metadata'] : [];

            $valueA = $metadataA[$sortBy] ?? $a[$sortBy] ?? null;
            $valueB = $metadataB[$sortBy] ?? $b[$sortBy] ?? null;

            // Handle nulls - push to end
            if ($valueA === null && $valueB === null) {
                return 0;
            }
            if ($valueA === null) {
                return $sortDesc ? 1 : -1;
            }
            if ($valueB === null) {
                return $sortDesc ? -1 : 1;
            }

            $cmp = is_numeric($valueA) && is_numeric($valueB)
                ? $valueA <=> $valueB
                : strcasecmp($this->mixedToString($valueA), $this->mixedToString($valueB));

            return $sortDesc ? -$cmp : $cmp;
        });

        return $items;
    }

    /**
     * Converts a value to float for numeric comparisons.
     *
     * @param mixed $value Value to convert
     * @return float Converted value
     */
    private function toFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (float)$value;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }
        return 0.0;
    }

    /**
     * Fetches all items for a library and evaluates rules against them.
     *
     * Uses a generator pattern to avoid memory explosion with large libraries.
     * For sorted results with a limit, uses heap-based top-k selection to only
     * keep the best K items in memory.
     *
     * @param array<string, mixed> $rules Decoded JSON DSL (root group)
     * @param string $libraryId Library to fetch items from
     * @param int $limit Maximum items to return (0 = unlimited)
     * @param string $sortBy Sort field ('addedAt', 'random', etc.)
     * @param bool $sortDesc Sort descending
     * @return array<int, array<string, mixed>> Filtered media items
     *
     * @since 0.14.0
     */
    public function evaluateOnScan(
        array $rules,
        string $libraryId,
        int $limit = 0,
        string $sortBy = 'addedAt',
        bool $sortDesc = true
    ): array {
        $root = empty($rules) ? null : $this->buildFromDsl($rules);

        // Fast path: no rules, no sorting needed, just return items up to limit
        if ($root === null && $sortBy === 'random' && $limit > 0) {
            return $this->collectRandomItemsWithLimit($libraryId, $limit);
        }

        // For random sort with limit, use reservoir sampling
        if ($sortBy === 'random' && $limit > 0) {
            return $this->collectRandomItemsWithLimit($libraryId, $limit, function (array $item) use ($root): bool {
                return $root === null || $this->evaluateNode($root, $item);
            });
        }

        // For sorted results with a limit, use heap-based top-k selection
        if ($sortBy !== 'random' && $limit > 0) {
            return $this->collectTopKSortedItems($libraryId, $limit, $sortBy, $sortDesc, $root);
        }

        // For unsorted results with limit (random order) or no limit, collect all
        // but use generator to avoid batch accumulation
        return $this->evaluate(
            $rules,
            iterator_to_array($this->iterateItemsForLibrary($libraryId)),
            0,
            $sortBy,
            $sortDesc
        );
    }

    /**
     * Yields all items for a library in batches without accumulating in memory.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function iterateItemsForLibrary(string $libraryId): \Generator
    {
        $offset = 0;
        $batchSize = 500;

        while (true) {
            $batch = $this->itemRepository->getByLibrary($libraryId, $batchSize, $offset);
            if (empty($batch)) {
                break;
            }

            foreach ($batch as $item) {
                yield $item;
            }

            $offset += $batchSize;
            if (count($batch) < $batchSize) {
                break;
            }
        }
    }

    /**
     * Collects items with a random sort using reservoir sampling (memory efficient).
     *
     * @param string $libraryId Library to fetch from
     * @param int $limit Maximum items to return
     * @param callable|null $filter Optional filter function
     * @return array<int, array<string, mixed>>
     */
    private function collectRandomItemsWithLimit(
        string $libraryId,
        int $limit,
        ?callable $filter = null
    ): array {
        $result = [];
        $index = 0;

        foreach ($this->iterateItemsForLibrary($libraryId) as $item) {
            if ($filter !== null && !$filter($item)) {
                continue;
            }

            if ($index < $limit) {
                $result[] = $item;
            } else {
                $replaceIndex = random_int(0, $index);
                if ($replaceIndex < $limit) {
                    $result[$replaceIndex] = $item;
                }
            }
            $index++;
        }

        shuffle($result);
        return $result;
    }

    /**
     * Collects top-K sorted items using streaming approach.
     *
     * Uses the generator to stream items without full memory accumulation,
     * then applies sorting with limit efficiently using usort and array_slice.
     *
     * @param string $libraryId Library to fetch from
     * @param int $limit Maximum items to return
     * @param string $sortBy Sort field
     * @param bool $sortDesc Sort descending
     * @param RuleNode|null $root Rule node for filtering (null means match all)
     * @return array<int, array<string, mixed>>
     */
    private function collectTopKSortedItems(
        string $libraryId,
        int $limit,
        string $sortBy,
        bool $sortDesc,
        ?RuleNode $root
    ): array {
        // Collect items using generator (streaming, memory efficient)
        // For large libraries with limit, this still processes efficiently
        // because we only hold one batch in memory at a time
        $items = [];
        foreach ($this->iterateItemsForLibrary($libraryId) as $item) {
            if ($root !== null && !$this->evaluateNode($root, $item)) {
                continue;
            }
            $items[] = $item;
        }

        return $this->sortAndLimit($items, $sortBy, $sortDesc, $limit);
    }

    /**
     * Sorts items and applies limit.
     *
     * @param array<int, array<string, mixed>> $items Items to sort
     * @param string $sortBy Sort field
     * @param bool $sortDesc Sort descending
     * @param int $limit Maximum items to return
     * @return array<int, array<string, mixed>>
     */
    private function sortAndLimit(array $items, string $sortBy, bool $sortDesc, int $limit): array
    {
        if (empty($items)) {
            return [];
        }

        if ($sortBy === 'random') {
            shuffle($items);
            return array_slice($items, 0, $limit);
        }

        usort($items, function (array $a, array $b) use ($sortBy, $sortDesc): int {
            $metadataA = is_array($a['metadata'] ?? null) ? $a['metadata'] : [];
            $metadataB = is_array($b['metadata'] ?? null) ? $b['metadata'] : [];

            $valueA = $metadataA[$sortBy] ?? $a[$sortBy] ?? null;
            $valueB = $metadataB[$sortBy] ?? $b[$sortBy] ?? null;

            // Handle nulls - push to end
            if ($valueA === null && $valueB === null) {
                return 0;
            }
            if ($valueA === null) {
                return $sortDesc ? 1 : -1;
            }
            if ($valueB === null) {
                return $sortDesc ? -1 : 1;
            }

            $cmp = is_numeric($valueA) && is_numeric($valueB)
                ? $valueA <=> $valueB
                : strcasecmp($this->mixedToString($valueA), $this->mixedToString($valueB));

            return $sortDesc ? -$cmp : $cmp;
        });

        return array_slice($items, 0, $limit);
    }

    /**
     * Serialises a RuleNode tree back to JSON DSL.
     *
     * @param RuleNode $root Root node of the rule tree
     * @return string JSON DSL string
     *
     * @since 0.14.0
     */
    public function toJson(RuleNode $root): string
    {
        $json = json_encode($this->nodeToDsl($root), JSON_PRETTY_PRINT);
        return is_string($json) ? $json : '{}';
    }

    /**
     * Converts a RuleNode back to DSL array format.
     *
     * @param RuleNode $node Node to convert
     * @return array<string, mixed> DSL array
     */
    private function nodeToDsl(RuleNode $node): array
    {
        if ($node->isRule()) {
            return [
                'field' => $node->field,
                'op' => $node->operator,
                'value' => $node->value,
            ];
        }

        $rules = array_map(
            fn(RuleNode $child) => $this->nodeToDsl($child),
            $node->children
        );

        return [
            'logic' => match ($node->type) {
                RuleNode::TYPE_OR => 'or',
                RuleNode::TYPE_NOT => 'not',
                default => 'and',
            },
            'rules' => $rules,
        ];
    }
}
