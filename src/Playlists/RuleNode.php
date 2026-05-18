<?php

declare(strict_types=1);

namespace Phlex\Playlists;

/**
 * Immutable AST node representing a rule or rule-group in a smart playlist.
 *
 * A smart playlist's rules are expressed as a tree of RuleNodes, where:
 * - TYPE_AND/TYPE_OR nodes have children and combine their results
 * - TYPE_NOT nodes invert the result of their single child
 * - TYPE_RULE nodes are leaf comparisons against media item metadata
 *
 * @since 0.14.0
 */
final class RuleNode
{
    public const TYPE_AND = 'and';
    public const TYPE_OR = 'or';
    public const TYPE_NOT = 'not';
    public const TYPE_RULE = 'rule';

    /**
     * @param string $type TYPE_AND | TYPE_OR | TYPE_NOT | TYPE_RULE
     * @param string|null $field e.g. 'genre', 'year', 'rating' (for TYPE_RULE)
     * @param string|null $operator 'equals', 'contains', 'gt', 'lt', 'between', 'in' (for TYPE_RULE)
     * @param mixed $value string|int|array depending on operator (for TYPE_RULE)
     * @param array<RuleNode> $children RuleNode[] for AND/OR/NOT groups
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $field = null,
        public readonly ?string $operator = null,
        public readonly mixed $value = null,
        public readonly array $children = [],
    ) {
    }

    /**
     * Check if this node is a leaf rule (not a group).
     *
     * @since 0.14.0
     */
    public function isRule(): bool
    {
        return $this->type === self::TYPE_RULE;
    }

    /**
     * Check if this node is a group node (AND/OR/NOT).
     *
     * @since 0.14.0
     */
    public function isGroup(): bool
    {
        return in_array($this->type, [self::TYPE_AND, self::TYPE_OR, self::TYPE_NOT], true);
    }

    /**
     * Check if this is an AND group.
     *
     * @since 0.14.0
     */
    public function isAnd(): bool
    {
        return $this->type === self::TYPE_AND;
    }

    /**
     * Check if this is an OR group.
     *
     * @since 0.14.0
     */
    public function isOr(): bool
    {
        return $this->type === self::TYPE_OR;
    }

    /**
     * Check if this is a NOT group.
     *
     * @since 0.14.0
     */
    public function isNot(): bool
    {
        return $this->type === self::TYPE_NOT;
    }
}
