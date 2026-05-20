<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Playlists;

use PHPUnit\Framework\TestCase;
use Phlix\Playlists\RuleNode;

class RuleNodeTest extends TestCase
{
    public function test_constructor_stores_all_properties(): void
    {
        $children = [
            new RuleNode(RuleNode::TYPE_RULE, 'genre', 'contains', 'Drama'),
        ];
        $node = new RuleNode(
            type: RuleNode::TYPE_AND,
            field: null,
            operator: null,
            value: null,
            children: $children
        );

        $this->assertSame(RuleNode::TYPE_AND, $node->type);
        $this->assertNull($node->field);
        $this->assertNull($node->operator);
        $this->assertNull($node->value);
        $this->assertSame($children, $node->children);
    }

    public function test_type_constants_are_correct_strings(): void
    {
        $this->assertSame('and', RuleNode::TYPE_AND);
        $this->assertSame('or', RuleNode::TYPE_OR);
        $this->assertSame('not', RuleNode::TYPE_NOT);
        $this->assertSame('rule', RuleNode::TYPE_RULE);
    }

    public function test_is_rule_returns_true_for_rule_node(): void
    {
        $node = new RuleNode(RuleNode::TYPE_RULE, 'genre', 'contains', 'Drama');
        $this->assertTrue($node->isRule());
    }

    public function test_is_rule_returns_false_for_group_node(): void
    {
        $node = new RuleNode(RuleNode::TYPE_AND);
        $this->assertFalse($node->isRule());
    }

    public function test_is_group_returns_true_for_group_nodes(): void
    {
        $andNode = new RuleNode(RuleNode::TYPE_AND);
        $orNode = new RuleNode(RuleNode::TYPE_OR);
        $notNode = new RuleNode(RuleNode::TYPE_NOT);

        $this->assertTrue($andNode->isGroup());
        $this->assertTrue($orNode->isGroup());
        $this->assertTrue($notNode->isGroup());
    }

    public function test_is_group_returns_false_for_rule_node(): void
    {
        $node = new RuleNode(RuleNode::TYPE_RULE, 'genre', 'contains', 'Drama');
        $this->assertFalse($node->isGroup());
    }

    public function test_is_and_returns_true_only_for_and(): void
    {
        $andNode = new RuleNode(RuleNode::TYPE_AND);
        $orNode = new RuleNode(RuleNode::TYPE_OR);

        $this->assertTrue($andNode->isAnd());
        $this->assertFalse($orNode->isAnd());
    }

    public function test_is_or_returns_true_only_for_or(): void
    {
        $andNode = new RuleNode(RuleNode::TYPE_AND);
        $orNode = new RuleNode(RuleNode::TYPE_OR);

        $this->assertFalse($andNode->isOr());
        $this->assertTrue($orNode->isOr());
    }

    public function test_is_not_returns_true_only_for_not(): void
    {
        $notNode = new RuleNode(RuleNode::TYPE_NOT);
        $andNode = new RuleNode(RuleNode::TYPE_AND);

        $this->assertTrue($notNode->isNot());
        $this->assertFalse($andNode->isNot());
    }
}
