<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Auth\PasswordPolicy;

/**
 * Consequence tests for the `auth.password.min_length` setting.
 *
 * ## What makes this setting honest
 *
 * The minimum length was a bare `strlen($password) < 8` duplicated at three
 * call sites. Per plan_settings.md §4 rule 1 a setting is only shippable if a
 * consumer reads the EFFECTIVE value, and per the "half-effective" warning a
 * setting wired to only some of its duplicate literals is worse than none — an
 * admin would raise the minimum, see registration honour it, and still be able
 * to set a weaker password from the admin UI.
 *
 * So these tests assert the OBSERVABLE OUTCOME (a password is accepted or
 * rejected) rather than that a value was read. Asserting `minLength() === 12`
 * would pass against an implementation that reads the setting and then ignores
 * it at the comparison — which is precisely the first-pass defect class this
 * program exists to eliminate.
 *
 * Sibling coverage lives in {@see PasswordPolicyEnforcementTest}, which drives
 * the same override through all three real call sites.
 *
 * Every test below was mutation-verified; each docblock names the change that
 * turns it red.
 */
class PasswordPolicyTest extends TestCase
{
    /**
     * A settings store whose effective value for the policy key is $value.
     */
    private function settingsReturning(mixed $value): SettingsRepository
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->with(PasswordPolicy::SETTING_KEY)
            ->willReturn($value);

        return $settings;
    }

    /**
     * CONSEQUENCE: raising the setting actually REJECTS a shorter password.
     *
     * This is the whole feature. An 11-character password is fine under the
     * shipped default and must stop being fine once the minimum is 12.
     *
     * Mutation-verified: making validate() compare against
     * ABSOLUTE_MIN_LENGTH instead of minLength() fails this.
     */
    public function test_raising_the_minimum_rejects_a_now_too_short_password(): void
    {
        $policy = new PasswordPolicy($this->settingsReturning(12));

        self::assertNotNull(
            $policy->validate('elevenchar '),
            'An 11-character password must be rejected once the minimum is 12.'
        );
        self::assertNull($policy->validate('twelvechars!'));
    }

    /**
     * CONSEQUENCE: the error message states the EFFECTIVE minimum.
     *
     * The three literals it replaced hardcoded "at least 8 characters" in the
     * message too. A message that keeps saying 8 while the policy enforces 12
     * is its own small lie, and the admin cannot tell why the save failed.
     *
     * Mutation-verified: hardcoding 8 in the sprintf() fails this.
     */
    public function test_the_rejection_message_reports_the_effective_minimum(): void
    {
        $policy = new PasswordPolicy($this->settingsReturning(16));

        self::assertSame('Password must be at least 16 characters', $policy->validate('short'));
    }

    /**
     * CONSEQUENCE: both legal extremes are ACCEPTED.
     *
     * §4 rule 9: a rejection-only test passes against an off-by-one. A password
     * of exactly the minimum length must be allowed, at both ends of the range.
     *
     * Mutation-verified: changing `strlen($password) < $min` to `<= $min`
     * fails this.
     */
    public function test_a_password_of_exactly_the_minimum_length_is_accepted(): void
    {
        $atFloor = new PasswordPolicy($this->settingsReturning(PasswordPolicy::ABSOLUTE_MIN_LENGTH));
        self::assertNull($atFloor->validate(str_repeat('a', PasswordPolicy::ABSOLUTE_MIN_LENGTH)));

        $atCeiling = new PasswordPolicy($this->settingsReturning(PasswordPolicy::ABSOLUTE_MAX_LENGTH));
        self::assertNull($atCeiling->validate(str_repeat('a', PasswordPolicy::ABSOLUTE_MAX_LENGTH)));
        self::assertNotNull($atCeiling->validate(str_repeat('a', PasswordPolicy::ABSOLUTE_MAX_LENGTH - 1)));
    }

    /**
     * CONSEQUENCE: the setting cannot WEAKEN the policy below the baseline.
     *
     * The schema's `minimum: 8` stops the admin API persisting a weaker value,
     * but a `server_settings` row can arrive by other means — direct SQL, a
     * restored backup, an orphaned row from a renamed key (§12 records that
     * removed keys orphan silently, which has already happened twice on this
     * install). A configurability feature must not become a way to quietly
     * weaken authentication.
     *
     * Mutation-verified: dropping the `max(ABSOLUTE_MIN_LENGTH, ...)` clamp
     * lets a 3-character password through and fails this.
     */
    public function test_an_override_below_the_floor_cannot_weaken_the_policy(): void
    {
        $policy = new PasswordPolicy($this->settingsReturning(3));

        self::assertNotNull(
            $policy->validate('abc'),
            'A 3-character password must still be rejected even when an override '
            . 'asks for a minimum of 3 — the floor may only be raised.'
        );
        self::assertSame(PasswordPolicy::ABSOLUTE_MIN_LENGTH, $policy->minLength());
    }

    /**
     * CONSEQUENCE: an absurd override cannot lock the install out.
     *
     * Without an upper clamp, a fat-fingered 100000 would make every password
     * unsettable — no admin could create a user or reset a password again,
     * and the only fix would be direct DB surgery.
     *
     * Mutation-verified: dropping the `min(ABSOLUTE_MAX_LENGTH, ...)` clamp
     * fails this.
     */
    public function test_an_absurd_override_is_clamped_and_cannot_lock_out_admins(): void
    {
        $policy = new PasswordPolicy($this->settingsReturning(100000));

        self::assertSame(PasswordPolicy::ABSOLUTE_MAX_LENGTH, $policy->minLength());
        self::assertNull($policy->validate(str_repeat('a', PasswordPolicy::ABSOLUTE_MAX_LENGTH)));
    }

    /**
     * CONSEQUENCE: a broken settings store fails SECURE, not open.
     *
     * If the DB read throws, the policy must fall back to the historical floor
     * rather than to "no policy at all". Failing open here would let a
     * transient DB error silently disable the password requirement.
     *
     * Mutation-verified: returning 0 from the catch block fails this.
     */
    public function test_a_failing_settings_store_falls_back_to_the_floor(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new \RuntimeException('db down'));

        $policy = new PasswordPolicy($settings);

        self::assertSame(PasswordPolicy::ABSOLUTE_MIN_LENGTH, $policy->minLength());
        self::assertNotNull($policy->validate('short'));
    }

    /**
     * CONSEQUENCE: a malformed override degrades to the floor, and in
     * particular is not SILENTLY TRUNCATED into a plausible-looking number.
     *
     * `getEffective()` returns mixed and the override column is loosely typed,
     * so anything can arrive here.
     *
     * The interesting cases are `'12abc'` and `12.9`. Values like null/false/
     * 'abc' cast to 0 and are caught by the lower clamp anyway, so they do NOT
     * discriminate between implementations — an earlier revision of this test
     * used only those and its "mutation-verified" claim was FALSE: replacing
     * the match() with a bare `(int) $configured` kept it green. `'12abc'` and
     * `12.9` both cast to 12, which is above the floor and therefore survives
     * the clamp, so they are the only inputs that actually prove the type
     * check runs. A malformed value must not be quietly reinterpreted as a
     * policy the admin never chose.
     *
     * Mutation-verified (for real this time): replacing the match() with a
     * bare `(int) $configured` makes minLength() return 12 for both and fails.
     */
    public function test_a_malformed_override_degrades_to_the_floor(): void
    {
        // Cast to 0 and are rescued by the clamp — included for completeness,
        // but they do not discriminate on their own.
        // Cast to 12 and would survive the clamp — these are the real guards.
        foreach ([null, false, 'not-a-number', [], 0, '12abc', 12.9] as $garbage) {
            $policy = new PasswordPolicy($this->settingsReturning($garbage));

            self::assertSame(
                PasswordPolicy::ABSOLUTE_MIN_LENGTH,
                $policy->minLength(),
                sprintf(
                    'A %s override (%s) must degrade to the floor, not be truncated '
                    . 'into a different policy.',
                    get_debug_type($garbage),
                    var_export($garbage, true)
                )
            );
            self::assertNotNull($policy->validate('short'));
        }
    }

    /**
     * CONSEQUENCE: a numeric-string override is honoured.
     *
     * Override values round-trip through the DB and can come back as strings;
     * treating '12' as garbage would silently ignore a real admin setting.
     *
     * Mutation-verified: removing the is_numeric string arm of the match()
     * fails this.
     */
    public function test_a_numeric_string_override_is_honoured(): void
    {
        $policy = new PasswordPolicy($this->settingsReturning('12'));

        self::assertSame(12, $policy->minLength());
        self::assertNotNull($policy->validate('elevenchar '));
    }

    /**
     * CONSEQUENCE: with no settings store at all, the baseline still applies.
     *
     * Covers unit tests and legacy callers. "No store" must mean "the policy
     * Phlix always had", never "no policy".
     *
     * Mutation-verified: returning 0 from the null-settings branch fails this.
     */
    public function test_without_a_settings_store_the_historical_baseline_applies(): void
    {
        $policy = new PasswordPolicy();

        self::assertSame(PasswordPolicy::ABSOLUTE_MIN_LENGTH, $policy->minLength());
        self::assertNotNull($policy->validate('short12'));
        self::assertNull($policy->validate('exactly8'));
    }

    /**
     * The code floor and the shared schema's `minimum` must agree.
     *
     * If the schema allowed 4 while the code clamped to 8, the admin UI would
     * accept a value it then silently ignored — a control that lies about its
     * own range. This reads the VENDORED schema, so a shared-repo change that
     * drifts from the code floor fails here.
     *
     * Mutation-verified: changing ABSOLUTE_MIN_LENGTH to 6 fails this.
     */
    public function test_the_code_floor_matches_the_shared_schema_bounds(): void
    {
        $path = __DIR__ . '/../../../vendor/detain/phlix-shared/schemas/server-settings.schema.json';
        self::assertFileExists($path);

        /** @var array{properties: array<string, array<string, mixed>>} $schema */
        $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $entry = $schema['properties'][PasswordPolicy::SETTING_KEY] ?? null;

        self::assertIsArray($entry, PasswordPolicy::SETTING_KEY . ' must be declared in the shared schema.');
        self::assertSame(
            PasswordPolicy::ABSOLUTE_MIN_LENGTH,
            $entry['minimum'] ?? null,
            'The schema minimum must equal PasswordPolicy::ABSOLUTE_MIN_LENGTH, or the UI '
            . 'offers a range the code silently refuses to honour.'
        );
        self::assertSame(
            PasswordPolicy::ABSOLUTE_MAX_LENGTH,
            $entry['maximum'] ?? null,
            'The schema maximum must equal PasswordPolicy::ABSOLUTE_MAX_LENGTH.'
        );
        self::assertSame(
            PasswordPolicy::ABSOLUTE_MIN_LENGTH,
            $entry['default'] ?? null,
            'The shipped default must be the historical baseline of 8.'
        );
    }
}
