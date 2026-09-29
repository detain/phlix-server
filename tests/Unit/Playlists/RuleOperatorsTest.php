<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Playlists;

use PHPUnit\Framework\TestCase;
use Phlix\Playlists\RuleOperators;

class RuleOperatorsTest extends TestCase
{
    public function test_equals_true_when_values_match(): void
    {
        $this->assertTrue(RuleOperators::equals('Drama', 'Drama'));
        $this->assertTrue(RuleOperators::equals(2020, 2020));
        $this->assertTrue(RuleOperators::equals(true, true));
    }

    public function test_equals_false_when_values_differ(): void
    {
        $this->assertFalse(RuleOperators::equals('Drama', 'Comedy'));
        $this->assertFalse(RuleOperators::equals(2020, 2019));
        $this->assertFalse(RuleOperators::equals('Drama', 'drama')); // case-sensitive
    }

    public function test_notEquals_true_when_values_differ(): void
    {
        $this->assertTrue(RuleOperators::notEquals('Drama', 'Comedy'));
        $this->assertTrue(RuleOperators::notEquals(2020, 2019));
    }

    public function test_notEquals_false_when_values_match(): void
    {
        $this->assertFalse(RuleOperators::notEquals('Drama', 'Drama'));
        $this->assertFalse(RuleOperators::notEquals(2020, 2020));
    }

    public function test_contains_true_when_substring_present(): void
    {
        $this->assertTrue(RuleOperators::contains('Science Fiction Drama', 'Drama'));
        $this->assertTrue(RuleOperators::contains('Action', 'Action'));
    }

    public function test_contains_false_when_substring_absent(): void
    {
        $this->assertFalse(RuleOperators::contains('Science Fiction', 'Drama'));
        $this->assertFalse(RuleOperators::contains('action', 'Action')); // case-sensitive
    }

    public function test_notContains_true_when_substring_absent(): void
    {
        $this->assertTrue(RuleOperators::notContains('Science Fiction', 'Drama'));
    }

    public function test_notContains_false_when_substring_present(): void
    {
        $this->assertFalse(RuleOperators::notContains('Science Fiction Drama', 'Drama'));
    }

    public function test_greaterThan_true_when_greater(): void
    {
        $this->assertTrue(RuleOperators::greaterThan(2021, 2020));
        $this->assertTrue(RuleOperators::greaterThan(8.5, 8.0));
        $this->assertTrue(RuleOperators::greaterThan(1, 0));
    }

    public function test_greaterThan_false_when_less_or_equal(): void
    {
        $this->assertFalse(RuleOperators::greaterThan(2020, 2020));
        $this->assertFalse(RuleOperators::greaterThan(2019, 2020));
    }

    public function test_lessThan_true_when_less(): void
    {
        $this->assertTrue(RuleOperators::lessThan(2019, 2020));
        $this->assertTrue(RuleOperators::lessThan(7.9, 8.0));
    }

    public function test_lessThan_false_when_greater_or_equal(): void
    {
        $this->assertFalse(RuleOperators::lessThan(2020, 2020));
        $this->assertFalse(RuleOperators::lessThan(2021, 2020));
    }

    public function test_between_true_when_value_in_range(): void
    {
        $this->assertTrue(RuleOperators::between(2020, 2010, 2025));
        $this->assertTrue(RuleOperators::between(2010, 2010, 2025)); // inclusive
        $this->assertTrue(RuleOperators::between(2025, 2010, 2025)); // inclusive
        $this->assertTrue(RuleOperators::between(7.5, 5.0, 10.0));
    }

    public function test_between_false_when_value_outside_range(): void
    {
        $this->assertFalse(RuleOperators::between(2009, 2010, 2025));
        $this->assertFalse(RuleOperators::between(2026, 2010, 2025));
    }

    public function test_in_true_when_value_in_array(): void
    {
        $this->assertTrue(RuleOperators::in('Drama', ['Drama', 'Comedy', 'Action']));
        $this->assertTrue(RuleOperators::in(2020, [2019, 2020, 2021]));
        $this->assertTrue(RuleOperators::in(5, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]));
    }

    public function test_in_false_when_value_not_in_array(): void
    {
        $this->assertFalse(RuleOperators::in('Horror', ['Drama', 'Comedy', 'Action']));
        $this->assertFalse(RuleOperators::in(2018, [2019, 2020, 2021]));
    }

    public function test_notIn_true_when_value_not_in_array(): void
    {
        $this->assertTrue(RuleOperators::notIn('Horror', ['Drama', 'Comedy', 'Action']));
    }

    public function test_notIn_false_when_value_in_array(): void
    {
        $this->assertFalse(RuleOperators::notIn('Drama', ['Drama', 'Comedy', 'Action']));
    }

    public function test_startsWith_true_when_prefix_matches(): void
    {
        $this->assertTrue(RuleOperators::startsWith('The Dark Knight', 'The'));
        $this->assertTrue(RuleOperators::startsWith('Star Wars', 'Star'));
    }

    public function test_startsWith_false_when_prefix_does_not_match(): void
    {
        $this->assertFalse(RuleOperators::startsWith('The Dark Knight', 'Dark'));
        $this->assertFalse(RuleOperators::startsWith('star wars', 'Star')); // case-sensitive
    }

    public function test_endsWith_true_when_suffix_matches(): void
    {
        $this->assertTrue(RuleOperators::endsWith('The Dark Knight', 'Knight'));
        $this->assertTrue(RuleOperators::endsWith('movie.mp4', '.mp4'));
    }

    public function test_endsWith_false_when_suffix_does_not_match(): void
    {
        $this->assertFalse(RuleOperators::endsWith('The Dark Knight', 'Dark'));
        $this->assertFalse(RuleOperators::endsWith('movie.mp4', '.MP4')); // case-sensitive
    }

    // ---- L4: exact boundary comparisons + type-aware membership ------------

    public function test_greaterThanOrEqual_matches_exact_boundary(): void
    {
        $this->assertTrue(RuleOperators::greaterThanOrEqual(2010, 2010));
        $this->assertTrue(RuleOperators::greaterThanOrEqual(2011, 2010));
        $this->assertFalse(RuleOperators::greaterThanOrEqual(2009, 2010));
    }

    public function test_lessThanOrEqual_matches_exact_boundary(): void
    {
        $this->assertTrue(RuleOperators::lessThanOrEqual(2010, 2010));
        $this->assertTrue(RuleOperators::lessThanOrEqual(2009, 2010));
        $this->assertFalse(RuleOperators::lessThanOrEqual(2011, 2010));
    }

    public function test_greaterThanOrEqual_has_no_epsilon_dead_zone(): void
    {
        // The pre-L4 engine faked gte with `gt(x, rule - 0.001)`, so this value
        // strictly BELOW the rule matched. It must not anymore.
        $this->assertFalse(RuleOperators::greaterThanOrEqual(2010.9995, 2011));
        $this->assertFalse(RuleOperators::lessThanOrEqual(2011.0005, 2011));
    }

    public function test_in_keeps_numeric_string_equivalence(): void
    {
        $this->assertTrue(RuleOperators::in('2010', [2010]));
        $this->assertTrue(RuleOperators::in(2010, ['2010']));
        $this->assertTrue(RuleOperators::in(2010.0, ['2010']));
    }

    public function test_in_rejects_boolean_number_juggling(): void
    {
        // Loose in_array matched true == 1 and true == '1'.
        $this->assertFalse(RuleOperators::in(true, [1]));
        $this->assertFalse(RuleOperators::in(1, [true]));
        $this->assertFalse(RuleOperators::in(true, ['1']));
    }

    public function test_in_rejects_non_numeric_string_number_mix(): void
    {
        $this->assertFalse(RuleOperators::in('abc', [0]));
        $this->assertFalse(RuleOperators::in('', [0]));
        $this->assertFalse(RuleOperators::in(0, ['abc']));
    }

    public function test_notIn_is_the_complement_of_in(): void
    {
        $this->assertTrue(RuleOperators::notIn(true, [1]));
        $this->assertFalse(RuleOperators::notIn('2010', [2010]));
    }
}
