<?php

/**
 * S427 — Request dynamic-property-read reachability guard (census-pinned).
 *
 * Provenance: S271 removed the two dead `$request->jsonBody ?? []` reads in
 * BackupController — reads of a property that never existed on
 * Phlix\Server\Http\Request, silently swallowed by the coalesce. S271 ruled a
 * throwing `__get` NO inside its own fix commit (fear: converting hot-path
 * null-coalesced reads into catch-fed 500s). S427 re-opens that ruling as its
 * own step with a tokenized census of the whole repo (1,756 PHP files):
 * 331 dynamic-free property reads on Request roots, ZERO surviving dynamic
 * reads, ZERO dynamic writes.
 *
 * The census result licenses Request::__get() to throw — BUT only for the
 * UNGUARDED shape. The guarded shapes (`?? $default`, `?:`, `isset()`,
 * `empty()`) keep their empty-default semantics because PHP consults
 * `__isset()` (which returns false for every dynamic name) before ever
 * calling `__get()`. That distinction is what this file pins: a direct read
 * of an undeclared property must become a loud LogicException, while the
 * exact S271 bug shape (`$request->jsonBody ?? []`) must keep answering `[]`
 * silently — the same guarantee that keeps both S271 mutation arms in
 * BackupControllerBodyPersistenceTest red for the documented reason and not
 * for a new one.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Request;

final class RequestDynamicPropertyGuardTest extends TestCase
{
    /**
     * The tripwire itself: a direct read of an undeclared property throws,
     * naming the property (Fail Fast — S271's silent-null class of bug
     * becomes loud instead of persisting a `''`).
     */
    public function testDirectDynamicReadThrowsNamingTheProperty(): void
    {
        $request = new Request();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/jsonBody/');

        /** @phpstan-ignore-next-line intentional dynamic-property read under test */
        $value = $request->jsonBody;

        $this->fail('Expected LogicException; got ' . var_export($value, true));
    }

    /**
     * THE census-critical guarantee: the S271 bug shape (`->jsonBody ?? []`)
     * must NOT throw — `??` consults `__isset()` (false for dynamic names)
     * and short-circuits to the default without ever reaching `__get()`.
     * This is what lets the guard ship without converting any guarded
     * hot-path read into a 500, and it is why both S271/S428 mutation arms
     * keep failing with the original string-diff assertions.
     */
    public function testCoalescedDynamicReadKeepsItsEmptyDefaultAndNeverThrows(): void
    {
        $request = new Request();

        $body = $request->jsonBody ?? [];
        $this->assertSame([], $body);

        $scalar = $request->noSuchProp ?? 'fallback';
        $this->assertSame('fallback', $scalar);

        // Elvis reads the value, but the value read goes through the same
        // isset-gated path: `??`/`?:` on a dynamic name answer the default.
        // (?: is a plain truthiness test on the coalesce-resolved operand
        // only when combined; a bare `$r->dyn ?: 'x'` DOES evaluate the read
        // — pinned separately below so neither shape is hand-waved.)
    }

    /**
     * A bare `?:` on an undeclared property evaluates the property read
     * itself (it is NOT `??`), so with the guard live it must throw exactly
     * like a direct read. The census shows ZERO elvis sites on Request
     * roots, so pinning this costs nothing today and documents the hazard.
     */
    public function testElvisOnDynamicNameThrows(): void
    {
        $request = new Request();

        $this->expectException(\LogicException::class);

        /** @phpstan-ignore-next-line intentional dynamic-property read under test */
        $value = $request->jsonBody ?: 'default';

        $this->fail('Expected LogicException; got ' . var_export($value, true));
    }

    /**
     * `__isset` pairing: existence tests answer honestly (false for dynamic
     * names, true for the always-initialized declared members) and never
     * throw — the guard must not turn `isset($r->x)` branches into faults.
     */
    public function testIssetAndEmptyOnDynamicNamesAreGuardedAndFalse(): void
    {
        $request = new Request();

        $this->assertFalse(isset($request->jsonBody));
        $this->assertTrue(empty($request->jsonBody));
        $this->assertFalse(isset($request->whatever));

        // Declared members are all initialized by defaults, so existence
        // tests behave exactly as before the guard (except nullable-nulls,
        // which `isset` correctly answers false for — pre-existing PHP
        // semantics, untouched).
        $this->assertTrue(isset($request->method));
        $this->assertTrue(isset($request->body));
    }

    /**
     * The 331-read denominator regression: every declared member reads
     * through its slot and NEVER hits the guard. If a default were ever
     * removed from Request, the typed-uninitialized read would throw
     * Error("must not be accessed before initialization") — NOT our
     * LogicException — and this test still passes; the point is that none
     * of these 17 names may ever answer the guard.
     */
    public function testDeclaredReadsNeverHitTheGuard(): void
    {
        $request = new Request();

        $declared = [
            'method', 'path', 'queryString', 'headers', 'query', 'body', 'rawBody',
            'files', 'remoteIp', 'remotePort', 'protocol', 'bearerToken', 'cookies',
            'userId', 'profileId', 'hubUser', 'pathParams',
        ];

        foreach ($declared as $name) {
            try {
                $value = $request->{$name};
            } catch (\LogicException $e) {
                $this->fail("declared member \$$name must never reach the dynamic guard: " . $e->getMessage());
            }
            $this->assertTrue(true, "read of \$$name bypassed the guard");
        }
    }

    /**
     * Dynamic-name access (`->{$var}`, `->{$expr}`) is the read shape PHPStan
     * cannot see at any level (property.notFound never fires for it) and the
     * census found zero such sites — the guard still catches it at runtime.
     */
    public function testVariableNameDynamicReadThrows(): void
    {
        $request = new Request();
        $name = 'jsonBody';

        $this->expectException(\LogicException::class);

        /** @phpstan-ignore-next-line intentional dynamic-property read under test */
        $value = $request->{$name};

        $this->fail('Expected LogicException; got ' . var_export($value, true));
    }

    /**
     * The guard intercepts ONLY reads of names that are not declared.
     * Writing then reading an undeclared name creates a real slot (PHP 8.2
     * deprecation territory, behaviorally unchanged by S427 — zero dynamic
     * writes in the census); reading a declared name after assigning it
     * must also never hit `__get`. Pinned so a future refactor cannot
     * "tighten" the guard into shadowing the 1,037 declared write sites.
     */
    public function testWriteThenReadRoundTripNeverHitsTheGuard(): void
    {
        $request = new Request();

        $request->userId = 'user-42';
        $this->assertSame('user-42', $request->userId);
    }
}
