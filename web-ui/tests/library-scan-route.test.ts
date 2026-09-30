/**
 * Route-gate pins for the `/app/library/scan` surface (L-4 follow-up).
 *
 * phlix-server b3aece4e strips the absolute-fs `paths` key from
 * `/api/v1/libraries` payloads for NON-admin callers. The page riding that
 * endpoint (LibraryScanPage) is an admin surface, so the SPA route must carry
 * `meta.requiresAdmin` — otherwise a logged-in non-admin reaches the page and
 * (pre @phlix/ui paths-hardening tag) crashes its row render on the missing key.
 *
 * Runtime: node's built-in test runner (`npm test`) with native TS type
 * stripping — same lean pattern as tests/i18n.test.ts.
 *
 * The bounce BEHAVIOR of `meta.requiresAdmin` (non-admin → home, admin → pass,
 * anonymous → login) is pinned upstream in @phlix/ui's createPhlixApp.test.ts
 * ('admin-only routes (meta.requiresAdmin)' block); what is consumer-owned —
 * and therefore pinned HERE — is that this route actually sets the exact key
 * the guard reads, in the same shape the admin siblings use.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { buildAdminRoutes } from '@phlix/ui';

/** The object literal (source text) of the library-scan route in src/main.ts. */
function libraryScanRouteSource(): string {
    const main = readFileSync(new URL('../src/main.ts', import.meta.url), 'utf8');
    const start = main.indexOf("path: '/app/library/scan'");
    assert.ok(start !== -1, 'src/main.ts must define the /app/library/scan route');
    // The route object closes at the first '}' after the path — none of its
    // fields (path/name/component/meta) open nested braces beyond inline objects,
    // and the meta object's own '}' is matched by counting depth from 'start-1'
    // (the route's opening '{' is the last '{' before the path line).
    const open = main.lastIndexOf('{', start);
    let depth = 0;
    for (let i = open; i < main.length; i++) {
        if (main[i] === '{') depth++;
        else if (main[i] === '}') {
            depth--;
            if (depth === 0) return main.slice(open, i + 1);
        }
    }
    assert.fail('unbalanced braces after the /app/library/scan route');
}

describe('/app/library/scan route gate (L-4)', () => {
    it('carries meta.requiresAdmin === true in source', () => {
        const block = libraryScanRouteSource();
        assert.match(
            block,
            /meta:\s*\{\s*requiresAdmin:\s*true\s*\}/,
            'the library-scan route must set `meta: { requiresAdmin: true }` — ' +
                'the exact key ui authGuard checks (to.meta?.requiresAdmin === true)',
        );
    });

    it('uses the SAME meta shape the admin section siblings carry', () => {
        // Cross-check the key name against the LIVE vendored ui, not a frozen
        // string: buildAdminRoutes() is the estate's requiresAdmin reference —
        // if ui ever renames the meta key, its own section meta flips and this
        // comparison goes red before the scan route silently loses protection.
        const adminSection = buildAdminRoutes()[0];
        assert.equal(adminSection.meta?.requiresAdmin, true);
        const block = libraryScanRouteSource();
        const scanFlag = /requiresAdmin:\s*(\w+)/.exec(block)?.[1];
        assert.equal(scanFlag, String(adminSection.meta?.requiresAdmin));
    });
});
