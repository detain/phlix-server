<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Playlists;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\ItemRepository;
use Phlex\Playlists\RuleNode;
use Phlex\Playlists\SmartPlaylistEngine;
use Phlex\Playlists\RuleOperators;

class SmartPlaylistEngineTest extends TestCase
{
    private SmartPlaylistEngine $engine;
    private ItemRepository $itemRepository;

    protected function setUp(): void
    {
        $this->itemRepository = $this->createMock(ItemRepository::class);
        $this->engine = new SmartPlaylistEngine($this->itemRepository);
    }

    public function test_build_from_dsl_creates_rule_node_tree(): void
    {
        $dsl = [
            'logic' => 'and',
            'rules' => [
                ['field' => 'genre', 'op' => 'contains', 'value' => 'Drama'],
                ['field' => 'year', 'op' => 'gt', 'value' => 2010],
            ],
        ];

        $root = $this->engine->buildFromDsl($dsl);

        $this->assertInstanceOf(RuleNode::class, $root);
        $this->assertTrue($root->isAnd());
        $this->assertCount(2, $root->children);
        $this->assertSame('genre', $root->children[0]->field);
        $this->assertSame('contains', $root->children[0]->operator);
    }

    public function test_evaluate_and_rule_requires_all_conditions(): void
    {
        $rules = [
            'logic' => 'and',
            'rules' => [
                ['field' => 'genre', 'op' => 'contains', 'value' => 'Drama'],
                ['field' => 'year', 'op' => 'gt', 'value' => 2010],
            ],
        ];

        $items = [
            $this->makeItem(['genre' => 'Drama', 'year' => 2020]), // matches
            $this->makeItem(['genre' => 'Drama', 'year' => 2009]), // fails year
            $this->makeItem(['genre' => 'Comedy', 'year' => 2020]), // fails genre
            $this->makeItem(['genre' => 'Action', 'year' => 2008]), // fails both
        ];

        $result = $this->engine->evaluate($rules, $items);

        $this->assertCount(1, $result);
        $this->assertSame('Drama', $result[0]['metadata']['genre']);
    }

    public function test_evaluate_or_rule_requires_one_condition(): void
    {
        $rules = [
            'logic' => 'or',
            'rules' => [
                ['field' => 'genre', 'op' => 'contains', 'value' => 'Drama'],
                ['field' => 'genre', 'op' => 'contains', 'value' => 'Comedy'],
            ],
        ];

        $items = [
            $this->makeItem(['genre' => 'Drama']),
            $this->makeItem(['genre' => 'Comedy']),
            $this->makeItem(['genre' => 'Action']),
        ];

        $result = $this->engine->evaluate($rules, $items);

        $this->assertCount(2, $result);
    }

    public function test_evaluate_not_rule_inverts_condition(): void
    {
        $rules = [
            'logic' => 'not',
            'rules' => [
                ['field' => 'genre', 'op' => 'contains', 'value' => 'Drama'],
            ],
        ];

        $items = [
            $this->makeItem(['genre' => 'Drama']),
            $this->makeItem(['genre' => 'Comedy']),
            $this->makeItem(['genre' => 'Action']),
        ];

        $result = $this->engine->evaluate($rules, $items);

        $this->assertCount(2, $result);
    }

    public function test_evaluate_nested_groups(): void
    {
        $rules = [
            'logic' => 'and',
            'rules' => [
                ['field' => 'genre', 'op' => 'contains', 'value' => 'Drama'],
                [
                    'logic' => 'or',
                    'rules' => [
                        ['field' => 'rating', 'op' => 'gte', 'value' => 8.0],
                        ['field' => 'criticScore', 'op' => 'gte', 'value' => 85],
                    ],
                ],
            ],
        ];

        $items = [
            $this->makeItem(['genre' => 'Drama', 'rating' => 8.5, 'criticScore' => 70]),
            $this->makeItem(['genre' => 'Drama', 'rating' => 7.0, 'criticScore' => 90]),
            $this->makeItem(['genre' => 'Drama', 'rating' => 7.0, 'criticScore' => 80]),
            $this->makeItem(['genre' => 'Comedy', 'rating' => 9.0, 'criticScore' => 95]),
        ];

        $result = $this->engine->evaluate($rules, $items);

        $this->assertCount(2, $result);
    }

    public function test_evaluate_empty_rules_returns_all_items(): void
    {
        $items = [
            $this->makeItem(['genre' => 'Drama']),
            $this->makeItem(['genre' => 'Comedy']),
        ];

        $result = $this->engine->evaluate([], $items);

        $this->assertCount(2, $result);
    }

    public function test_evaluate_with_limit(): void
    {
        $rules = [
            'logic' => 'and',
            'rules' => [
                ['field' => 'genre', 'op' => 'contains', 'value' => 'Drama'],
            ],
        ];

        $items = array_map(
            fn($i) => $this->makeItem(['genre' => 'Drama', 'title' => "Movie $i"]),
            range(1, 10)
        );

        $result = $this->engine->evaluate($rules, $items, limit: 3);

        $this->assertCount(3, $result);
    }

    public function test_evaluate_sort_by_random(): void
    {
        $rules = [
            'logic' => 'and',
            'rules' => [
                ['field' => 'genre', 'op' => 'contains', 'value' => 'Drama'],
            ],
        ];

        $items = array_map(
            fn($i) => $this->makeItem(['genre' => 'Drama', 'title' => "Movie $i"]),
            range(1, 5)
        );

        // Run multiple times to verify randomization
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->engine->evaluate($rules, $items, sortBy: 'random', sortDesc: true);
        }

        // Results should differ due to random sort (statistically unlikely to be same order 3 times)
        $this->assertTrue(
            $results[0] !== $results[1] || $results[1] !== $results[2],
            'Random sort should produce different orders across calls'
        );
    }

    public function test_to_json_round_trip(): void
    {
        $dsl = [
            'logic' => 'and',
            'rules' => [
                ['field' => 'genre', 'op' => 'contains', 'value' => 'Drama'],
                ['field' => 'year', 'op' => 'gt', 'value' => 2010],
            ],
        ];

        $root = $this->engine->buildFromDsl($dsl);
        $json = $this->engine->toJson($root);
        $decoded = json_decode($json, true);

        $this->assertSame('and', $decoded['logic']);
        $this->assertCount(2, $decoded['rules']);
        $this->assertSame('genre', $decoded['rules'][0]['field']);
        $this->assertSame('contains', $decoded['rules'][0]['op']);
    }

    public function test_evaluate_with_sort_by_field(): void
    {
        $rules = [
            'logic' => 'and',
            'rules' => [],
        ];

        $items = [
            $this->makeItem(['year' => 2010]),
            $this->makeItem(['year' => 2020]),
            $this->makeItem(['year' => 2015]),
        ];

        $result = $this->engine->evaluate($rules, $items, sortBy: 'year', sortDesc: true);

        $this->assertCount(3, $result);
        $this->assertSame(2020, $result[0]['metadata']['year']);
        $this->assertSame(2015, $result[1]['metadata']['year']);
        $this->assertSame(2010, $result[2]['metadata']['year']);
    }

    /**
     * Helper to create a media item with metadata.
     */
    private function makeItem(array $metadata): array
    {
        return [
            'id' => 'test-' . uniqid(),
            'library_id' => 'lib-123',
            'name' => $metadata['title'] ?? 'Test Movie',
            'type' => 'movie',
            'path' => '/test/movie.mp4',
            'metadata' => $metadata,
        ];
    }
}
