<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins;

use Phlex\Shared\Plugin\ManifestType;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Shared\Plugin\ManifestType
 */
final class ManifestTypeTest extends TestCase
{
    /**
     * Mirror of the eleven values from PHLEX_EXPANSION_PLAN.md §5. If
     * either side drifts this test fails — that drift is intentional
     * friction.
     *
     * @return list<string>
     */
    private function expectedValues(): array
    {
        return [
            'metadata-provider',
            'subtitle-provider',
            'auth-provider',
            'library-type',
            'notifier',
            'scrobbler',
            'tuner',
            'transcoder-hook',
            'ui-theme',
            'arr-integration',
            'analytics-sink',
        ];
    }

    public function test_tryFrom_returns_enum_for_each_known_value(): void
    {
        foreach ($this->expectedValues() as $value) {
            $case = ManifestType::tryFrom($value);
            $this->assertNotNull($case, sprintf('Expected enum case for "%s".', $value));
            $this->assertSame($value, $case->value);
        }
    }

    public function test_tryFrom_returns_null_for_unknown_value(): void
    {
        $this->assertNull(ManifestType::tryFrom('totally-not-real'));
        $this->assertNull(ManifestType::tryFrom(''));
    }

    public function test_enum_has_exactly_eleven_cases(): void
    {
        $this->assertCount(11, ManifestType::cases());
    }

    public function test_each_case_value_is_kebab_case(): void
    {
        foreach (ManifestType::cases() as $case) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9-]*$/',
                $case->value,
                sprintf('Enum case %s should be kebab-case.', $case->name),
            );
        }
    }
}
