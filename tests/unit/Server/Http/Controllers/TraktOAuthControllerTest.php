<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Server\Http\Controllers;

use Phlex\Plugins\Scrobbler\Trakt\TraktOAuthStateStore;
use Phlex\Server\Http\Controllers\TraktOAuthController;
use Phlex\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Covers the CSRF state validation behaviour of the Trakt OAuth callback.
 *
 * The controller MUST reject any callback whose `state` parameter does
 * not correspond to a previously-issued state, and MUST refuse to honour
 * a replay of an already-consumed state value.
 *
 * See post-O.7 wave 1 security audit, finding H.4.
 */
final class TraktOAuthControllerTest extends TestCase
{
    public function test_callback_with_wrong_state_returns_403(): void
    {
        $store = new FakeTraktOAuthStateStore();
        $store->put('expected-state', 'verifier-xyz');

        $controller = new TraktOAuthController(logger: null, stateStore: $store);

        $response = $controller->callback(new Request(), [
            'code' => 'auth-code-aaa',
            'state' => 'spoofed-state',
        ]);

        self::assertSame(403, $response->statusCode);
    }

    public function test_callback_after_state_already_consumed_returns_403(): void
    {
        $store = new FakeTraktOAuthStateStore();
        $store->put('one-shot-state', 'verifier-xyz');

        $controller = new TraktOAuthController(logger: null, stateStore: $store);

        // First consume succeeds at the state-check level; we don't care
        // about the downstream token exchange because the second call must
        // be rejected up front.
        $controller->callback(new Request(), [
            'code' => 'auth-code-aaa',
            'state' => 'one-shot-state',
        ]);

        $replay = $controller->callback(new Request(), [
            'code' => 'auth-code-aaa',
            'state' => 'one-shot-state',
        ]);

        self::assertSame(403, $replay->statusCode);
    }

    public function test_callback_without_state_returns_400(): void
    {
        $store = new FakeTraktOAuthStateStore();

        $controller = new TraktOAuthController(logger: null, stateStore: $store);

        $response = $controller->callback(new Request(), [
            'code' => 'auth-code-aaa',
            'state' => '',
        ]);

        self::assertSame(400, $response->statusCode);
    }
}

/**
 * Plain in-memory store used by the controller test. Mirrors the
 * one-shot contract of the production implementation.
 *
 * @internal Test fixture only.
 */
final class FakeTraktOAuthStateStore implements TraktOAuthStateStore
{
    /** @var array<string, string> */
    private array $entries = [];

    public function put(string $state, string $codeVerifier): void
    {
        $this->entries[$state] = $codeVerifier;
    }

    public function consume(string $state): ?string
    {
        if (!isset($this->entries[$state])) {
            return null;
        }
        $verifier = $this->entries[$state];
        unset($this->entries[$state]);
        return $verifier;
    }
}
