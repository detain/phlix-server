<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks;

use Phlix\Plugins\Util\RecursiveDelete;
use PHPUnit\Framework\TestCase;
use Phlix\Config\EffectiveConfig;
use Phlix\Webhooks\WebhookEvent;
use Phlix\Webhooks\WebhookDispatcher;
use Workerman\MySQL\Connection;

/**
 * Consequence tests for the `webhooks.enabled` master kill-switch.
 *
 * ## What was wrong before
 *
 * `config/webhooks.php` shipped `'enabled' => true` with **no consumer at all**.
 * The literal appeared only inside the fallback array `getConfig()` returns when
 * the config file is MISSING, and was never read on any path — a config toggle
 * that did nothing. `getConfig()` additionally did a raw `include` of the file
 * (read-path class (d) NOT REACHABLE), so even once something read it, an admin
 * override could not have reached it.
 *
 * Both halves had to ship together for the setting to be honest, so these tests
 * assert the OBSERVABLE BEHAVIOUR — is a webhook delivered? — rather than that a
 * config value was read.
 *
 * Each test is mutation-verified; see the individual docblocks.
 */
final class WebhookEnabledGateTest extends TestCase
{
    /** @var list<string> minted config roots removed in tearDown (S439 zero-residue). */
    private array $mintedConfigRoots = [];

    protected function setUp(): void
    {
        parent::setUp();
        EffectiveConfig::reset();
    }

    protected function tearDown(): void
    {
        EffectiveConfig::reset();
        foreach ($this->mintedConfigRoots as $root) {
            RecursiveDelete::remove($root);
        }
        $this->mintedConfigRoots = [];
        parent::tearDown();
    }

    /**
     * A settings-store double returning the given `key => [value, type]` rows,
     * matching the convention in EffectiveConfigTest.
     *
     * @param array<string, array{0: string, 1: string}> $rows
     */
    private function fakeSettingsDb(array $rows): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function ($query = '', $params = null) use ($rows): array {
                $sql = is_string($query) ? $query : '';
                if (str_contains($sql, 'SELECT setting_key,')) {
                    $out = [];
                    foreach ($rows as $key => [$value, $type]) {
                        $out[] = [
                            'setting_key'   => $key,
                            'setting_value' => $value,
                            'value_type'    => $type,
                        ];
                    }
                    return $out;
                }
                return [];
            }
        );
        return $db;
    }

    /**
     * Write a throwaway config dir containing only `webhooks.php`.
     *
     * @param array<string, mixed> $webhooks
     */
    private function configDirWith(array $webhooks): string
    {
        $dir = sys_get_temp_dir() . '/phlix_wh_' . uniqid('', true) . '/config';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/webhooks.php', '<?php return ' . var_export($webhooks, true) . ";\n");
        $this->mintedConfigRoots[] = dirname($dir);
        return $dir;
    }

    /**
     * Point EffectiveConfig at a throwaway config dir containing only
     * `webhooks.php` with the given `enabled` value.
     */
    private function withEnabled(bool $enabled): void
    {
        EffectiveConfig::bootstrap(
            $this->fakeSettingsDb([]),
            null,
            $this->configDirWith(['enabled' => $enabled, 'timeout' => 5]),
        );
    }

    private function event(): WebhookEvent
    {
        return new WebhookEvent('media.added', ['id' => 'x'], new \DateTimeImmutable());
    }

    /**
     * CONSEQUENCE: with the switch OFF, nothing is delivered AND no query runs.
     *
     * The guard sits before `getMatchingWebhooks()`, so a mock connection that is
     * never asked for rows proves the short-circuit — not merely that the result
     * was empty, which an all-webhooks-inactive DB would also produce.
     *
     * Mutation-verified: removing the `if (!$this->isEnabled())` block makes the
     * dispatcher query the DB and fails this test.
     */
    public function test_disabling_webhooks_stops_delivery_before_any_db_lookup(): void
    {
        $this->withEnabled(false);

        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $result = (new WebhookDispatcher($db))->dispatch($this->event());

        self::assertSame(0, $result->successCount);
        self::assertSame(0, $result->failureCount);
    }

    /**
     * CONSEQUENCE: with the switch ON, dispatch proceeds to look webhooks up.
     *
     * A disable-only test would pass against an implementation that blocks
     * everything unconditionally, so the enabled path must be shown to get
     * FURTHER — here, as far as the matching-webhooks query.
     *
     * Mutation-verified: hardcoding `isEnabled()` to return false fails this.
     */
    public function test_enabled_webhooks_proceed_to_the_lookup(): void
    {
        $this->withEnabled(true);

        $db = $this->createMock(Connection::class);
        $db->expects($this->atLeastOnce())->method('query')->willReturn([]);

        (new WebhookDispatcher($db))->dispatch($this->event());
    }

    /**
     * CONSEQUENCE: an absent key keeps delivering.
     *
     * Existing installs have no `webhooks.enabled` override and some have no
     * config file at all. The kill-switch must fail OPEN — defaulting to "off"
     * would silently stop every integration on upgrade.
     *
     * Mutation-verified: changing the `?? true` default to `?? false` fails this.
     */
    public function test_an_absent_setting_keeps_webhooks_delivering(): void
    {
        EffectiveConfig::bootstrap(
            $this->fakeSettingsDb([]),
            null,
            $this->configDirWith(['timeout' => 5]),
        );

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        self::assertTrue((new WebhookDispatcher($db))->isEnabled());
    }

    /**
     * CONSEQUENCE: the value is read through EffectiveConfig, so an ADMIN
     * OVERRIDE reaches it — not just the config file.
     *
     * This is the half that the old raw `include` made impossible, and it is the
     * difference between "the file works" and "the setting works". The override
     * says false while the file on disk says true; the override must win.
     *
     * Mutation-verified: reverting getConfig() to `include $configFile` makes the
     * dispatcher see the file's `true` and fails this test.
     */
    public function test_an_admin_override_reaches_the_dispatcher(): void
    {
        // File says ENABLED, override says DISABLED.
        EffectiveConfig::bootstrap(
            $this->fakeSettingsDb(['webhooks.enabled' => ['0', 'bool']]),
            null,
            $this->configDirWith(['enabled' => true, 'timeout' => 5]),
        );

        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        self::assertFalse(
            (new WebhookDispatcher($db))->isEnabled(),
            'A webhooks.enabled override must reach WebhookDispatcher. If this fails, '
            . 'getConfig() is bypassing EffectiveConfig again (read-path class (d)).'
        );

        (new WebhookDispatcher($db))->dispatch($this->event());
    }
}
