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
     *     { "field": "genres", "op": "contains", "value": "Drama" },
     *     { "field": "year", "op": "gt", "value": 2010 }
     *   ]
     * }
     * ```
     *
     * `genres` is the real hydrated metadata key — a `list<string>` that the
     * containment/equality operators match with any-of semantics (M2).
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
     * M2: hydrated metadata stores multi-valued fields as LISTS — the real
     * genre key is the plural `genres` holding `list<string>` (see
     * {@see \Phlix\Media\Metadata\Resolution\FieldMappers}, e.g. its
     * `fromTmdb()`'s `$b->stringList('genres', …)`). The equality/containment
     * operators therefore carry any-of semantics over array item values:
     * `contains` matches when ANY element contains the needle and
     * `notContains`/`notEquals` hold only when NO element matches. Scalar
     * fields keep their original scalar semantics untouched.
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
            'equals' => $this->equalsAnyElement($itemValue, $ruleValue),
            'notEquals' => !$this->equalsAnyElement($itemValue, $ruleValue),
            'contains' => $this->containsAnyElement($itemValue, $ruleValue),
            'notContains' => !$this->containsAnyElement($itemValue, $ruleValue),
            'gt' => RuleOperators::greaterThan($this->toFloat($itemValue), $this->toFloat($ruleValue)),
            // L4: exact boundary comparisons. The old ±0.001 epsilon made
            // gte/lte fire inside a dead zone around the rule value (e.g.
            // year 2010.9995 satisfied `gte 2011`), and the trailing
            // strict-equals arm only rescued same-typed operands anyway.
            'gte' => RuleOperators::greaterThanOrEqual($this->toFloat($itemValue), $this->toFloat($ruleValue)),
            'lt' => RuleOperators::lessThan($this->toFloat($itemValue), $this->toFloat($ruleValue)),
            'lte' => RuleOperators::lessThanOrEqual($this->toFloat($itemValue), $this->toFloat($ruleValue)),
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
     * Any-of equality over list-shaped metadata (M2).
     *
     * A scalar item value keeps the historical strict comparison verbatim; an
     * array value (a hydrated `list<string>` like `genres`) matches when ANY
     * of its elements strictly equals the rule value.
     */
    private function equalsAnyElement(mixed $itemValue, mixed $ruleValue): bool
    {
        if (!is_array($itemValue)) {
            return RuleOperators::equals($itemValue, $ruleValue);
        }

        foreach ($itemValue as $element) {
            if (RuleOperators::equals($element, $ruleValue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Any-of substring containment over list-shaped metadata (M2).
     *
     * A scalar item value keeps the historical string-containment comparison;
     * an array value matches when ANY scalar element contains the needle.
     * Non-scalar (nested) elements are skipped rather than stringified to
     * `''`, so an object-list field can never match via an empty cast.
     */
    private function containsAnyElement(mixed $itemValue, mixed $ruleValue): bool
    {
        if (!is_array($itemValue)) {
            return RuleOperators::contains($this->mixedToString($itemValue), $this->mixedToString($ruleValue));
        }

        $needle = $this->mixedToString($ruleValue);
        foreach ($itemValue as $element) {
            if (!is_scalar($element)) {
                continue;
            }
            if (RuleOperators::contains($this->mixedToString($element), $needle)) {
                return true;
            }
        }

        return false;
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

        usort(
            $items,
            fn(array $a, array $b): int => $this->compareForSort($a, $b, $sortBy, $sortDesc)
        );

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

        // For sorted results with a limit, use bounded top-k selection (M4)
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
     * Collects top-K sorted items with a bounded, streaming selection (M4).
     *
     * Holds at most K items: each streamed match is inserted into a
     * descending-sorted buffer of size K (linear from the tail, so equal
     * values keep encounter order exactly like PHP 8's stable usort), and a
     * candidate worse than the current K-th is dropped immediately. The old
     * body materialised EVERY matching item before slicing — the docblock's
     * "heap-bounded" claim was a lie the code never honoured.
     *
     * Why a sorted buffer and not SplPriorityQueue: the estate's sort
     * comparator is a mixed-typed total order (numeric spaceship when both
     * sides are numeric, case-insensitive string compare otherwise, nulls to
     * the end). SplPriorityQueue can only order by a scalar priority, and no
     * scalar priority exists that reproduces that comparator — a heap swap
     * would silently change WHICH K items survive on string sort fields. The
     * "SQL pushdown (ORDER BY/LIMIT for flat rules)" alternative is deferred
     * for the same identity reason: MySQL JSON-collation ordering does not
     * agree with this comparator, and result identity for existing callers is
     * the hard constraint. Bounded MEMORY is achieved either way.
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
        /** @var list<array<string, mixed>> $top Best-K seen so far, ordered best → worst. */
        $top = [];

        foreach ($this->iterateItemsForLibrary($libraryId) as $item) {
            if ($root !== null && !$this->evaluateNode($root, $item)) {
                continue;
            }

            if (count($top) < $limit) {
                $this->insertIntoSortedBuffer($top, $item, $sortBy, $sortDesc);
                continue;
            }

            // Buffer full: only a candidate strictly better than the current
            // worst kept item earns a slot; everything else is dropped here,
            // which is the entire point of the bound.
            $worst = $top[$limit - 1] ?? null;
            if ($worst !== null && $this->compareForSort($item, $worst, $sortBy, $sortDesc) < 0) {
                array_pop($top);
                $this->insertIntoSortedBuffer($top, $item, $sortBy, $sortDesc);
            }
        }

        return $top;
    }

    /**
     * Inserts one item into the descending-sorted top-K buffer.
     *
     * Scans from the tail (the worst end) and stops at the first position the
     * item does not outrank, so ties keep first-seen order — the same stable
     * ordering PHP 8's usort produces for the full-sort path.
     *
     * @param array<int, array<string, mixed>> $top
     * @param array<string, mixed> $item
     */
    private function insertIntoSortedBuffer(array &$top, array $item, string $sortBy, bool $sortDesc): void
    {
        $index = count($top);
        while ($index > 0 && $this->compareForSort($item, $top[$index - 1], $sortBy, $sortDesc) < 0) {
            $index--;
        }
        array_splice($top, $index, 0, [$item]);
    }

    /**
     * The single source of truth for sort ordering (M4).
     *
     * Mirrors exactly what {@see sortItems()}/{@see sortAndLimit()} used to
     * inline: metadata-first value lookup, nulls to the end, numeric spaceship
     * when both sides are numeric, case-insensitive string compare otherwise.
     * Negative result = $a sorts BEFORE $b.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function compareForSort(array $a, array $b, string $sortBy, bool $sortDesc): int
    {
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
