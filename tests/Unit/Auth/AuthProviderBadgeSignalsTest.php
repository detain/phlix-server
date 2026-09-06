<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Server\Http\Controllers\AuthProviderController;
use Phlix\Server\Http\Request;

/**
 * S252 — the badge signals the auth-provider admin list was built without.
 *
 * ## The defect
 *
 * `AuthProviderController::listProviders()` iterated the REGISTRY
 * (`getProviders()`), which by construction contains only providers that are
 * enabled AND configured. Two consequences the S44-a UI badge hit head-on:
 *
 *  1. the payload carried no `live` key at all, so the UI's strict
 *     `p?.live === true` read every provider as Disabled; and
 *  2. a configured-but-DISABLED provider was absent from the payload entirely,
 *     making `enabled: false` unrepresentable — the row it belongs to did not exist.
 *
 * ## The fix this pins
 *
 * Iterate the fixed {@see AuthProviderBootstrapper::TOGGLEABLE} universe and emit
 * two independent, per-provider-computed booleans per row:
 *  - `live`    ← {@see AuthProviderRegistry::hasProvider()} (registered in this worker)
 *  - `enabled` ← {@see AuthProviderBootstrapper::isEnabled()} (persisted flag)
 *
 * Every assertion below reads the keys strictly (`assertSame` against exact
 * bools / exact whole-row arrays), so deleting either field from the payload —
 * or hardcoding it — turns a NAMED test red. That is the mutation gate: reverting
 * the controller's `'live' => $live` or `'enabled' => …` line to a constant, or
 * dropping the key, fails at least one test by name.
 */
final class AuthProviderBadgeSignalsTest extends TestCase
{
    /** Code-resident lane token (S252). */
    private const LANE_TOKEN = 'S252BADGELIVEGATEX5R8';

    private AuthProviderRegistry&MockObject $registry;

    private AuthProviderBootstrapper&MockObject $bootstrapper;

    private AuthProviderController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = $this->createMock(AuthProviderRegistry::class);
        $this->bootstrapper = $this->createMock(AuthProviderBootstrapper::class);
        $this->controller = new AuthProviderController($this->registry, $this->bootstrapper);
    }

    /**
     * THE AC — a configured-but-NOT-enabled provider must APPEAR in the payload
     * with `enabled: false, live: false`, beside an enabled control whose row is
     * `enabled: true, live: true`. The two rows carry different values from the
     * same code path, so neither field can be a hardcoded literal.
     */
    public function test_configured_but_disabled_provider_is_listed_disabled_beside_an_enabled_control(): void
    {
        $this->wireRegistry(['oidc']);
        $this->wireFlags(['oidc' => true]);

        $rows = $this->providerRows();

        // The disabled provider is present at all — impossible under the old
        // registry-iterating build, which only ever listed registered providers.
        $this->assertArrayHasKey('ldap', $rows, self::LANE_TOKEN);

        $this->assertSame(
            ['name' => 'ldap', 'supports_authentication' => false, 'live' => false, 'enabled' => false],
            $rows['ldap'],
            'A configured-but-disabled provider must carry an honest disabled badge pair.',
        );

        // The enabled control beside it proves the fields are computed, not fixed.
        $this->assertSame(
            ['name' => 'oidc', 'supports_authentication' => true, 'live' => true, 'enabled' => true],
            $rows['oidc'],
            'The enabled control must carry the opposite badge pair from the SAME code path.',
        );
    }

    /**
     * The two signals are independent axes, not aliases: a flag persisted as
     * enabled but not yet registered in THIS worker (the boot pass hasn't run)
     * must read `enabled: true, live: false` — and its inverse registered-but-
     * flag-off reads `enabled: false, live: true`. Either signal copied from the
     * other reddens this test by name.
     */
    public function test_live_and_enabled_are_independent_boolean_axes_per_provider(): void
    {
        $this->wireRegistry(['github']); // registered here, flag off → live true, enabled false
        $this->wireFlags(['ldap' => true]); // flag on, not in this worker → enabled true, live false

        $rows = $this->providerRows();

        $this->assertTrue($rows['github']['live']);
        $this->assertFalse($rows['github']['enabled']);

        $this->assertFalse($rows['ldap']['live']);
        $this->assertTrue($rows['ldap']['enabled']);

        // The UI half (S44-a) gates on STRICT true; the payload must ship real booleans.
        foreach (['live', 'enabled'] as $signal) {
            foreach ($rows as $name => $row) {
                $this->assertIsBool($row[$signal], "Signal '{$signal}' of '{$name}' must be a JSON boolean.");
            }
        }
    }

    /**
     * Shape gate: the list is exactly the TOGGLEABLE universe, in order, and
     * EVERY row carries both badge keys. Removing either key from the controller
     * payload reddens this named test even when the values happen to agree.
     */
    public function test_every_toggleable_row_carries_both_badge_keys_in_toggleable_order(): void
    {
        $this->wireRegistry([]);
        $this->wireFlags([]);

        $body = json_decode($this->controller->listProviders($this->request(), [])->body, true);

        $this->assertIsArray($body);
        $names = array_column($body['providers'], 'name');
        $this->assertSame(AuthProviderBootstrapper::TOGGLEABLE, $names, self::LANE_TOKEN);

        foreach ($body['providers'] as $row) {
            $this->assertArrayHasKey('live', $row);
            $this->assertArrayHasKey('enabled', $row);
        }
    }

    /**
     * @param list<string> $registered Provider family keys present in the registry.
     */
    private function wireRegistry(array $registered): void
    {
        $this->registry->method('hasProvider')
            ->willReturnCallback(static fn (string $key): bool => in_array($key, $registered, true));
    }

    /**
     * @param array<string, bool> $enabledFlags Persisted enable flags per provider.
     */
    private function wireFlags(array $enabledFlags): void
    {
        $this->bootstrapper->method('isEnabled')
            ->willReturnCallback(static fn (string $name): bool => ($enabledFlags[$name] ?? false) === true);
    }

    /**
     * @return array<string, array<string, mixed>> Rows keyed by provider name.
     */
    private function providerRows(): array
    {
        $body = json_decode($this->controller->listProviders($this->request(), [])->body, true);

        $this->assertIsArray($body);
        $this->assertArrayHasKey('providers', $body);

        $rows = [];
        foreach ($body['providers'] as $row) {
            $rows[(string) $row['name']] = $row;
        }

        return $rows;
    }

    private function request(): Request
    {
        return $this->createMock(Request::class);
    }
}
