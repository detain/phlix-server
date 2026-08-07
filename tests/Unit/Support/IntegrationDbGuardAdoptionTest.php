<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * S126 — the mechanical check that stops the 36th private MySQL probe.
 *
 * ## What it is guarding against
 *
 * Before S126, **35** files under `tests/` each declared their own
 * `private function isMysqlReachable()` — an `@fsockopen()` port probe — and
 * treated its success as "the database works". Measured on 2026-07-27:
 * `grep -rl 'function isMysqlReachable' tests/` → 35 files, all 35 also
 * containing `markTestSkipped` (80 occurrences), and `grep -rn fsockopen tests/`
 * → 37 hits in the same 35 files (the two extras being docblock prose in
 * `tests/Integration/Auth/AccountLinkIntegrationTest.php` and
 * `tests/Integration/Auth/UserIdentitiesMigrationIntegrationTest.php`). The 35
 * bodies had already drifted into three variants (28 / 5 / 2 by md5), differing
 * only in the local variable name (`$sock` vs `$socket`) and one blank line — so
 * copy-paste, not divergent intent.
 *
 * A port probe cannot distinguish "no MySQL on this box" (a legitimate skip)
 * from "wrong credentials / missing database" (a real failure), and the count
 * grew from 24 on 2026-07-25 to 35 on 2026-07-27 — faster than any hand cleanup.
 * {@see \Phlix\Tests\Support\Database\IntegrationDbGuard} is the replacement;
 * this test is what keeps it the only copy.
 *
 * ## The threat model, stated so the rules can be judged against it
 *
 * This guard exists to stop **accidental** recurrence: someone opens the
 * nearest integration test, copies its `setUp()`, and ships copy 36. That is
 * how all 35 arose — the bodies are byte-identical modulo a variable name. It
 * is **not** an adversarial sandbox, and a static rule over source text can
 * never be one: a determined author can always spell the probe in a way no
 * token scan recognises.
 *
 * That fixes the priority order, and it is the opposite of the intuitive one:
 *
 * > **A false positive costs more than an escape.** An escape leaves the tree
 * > exactly as safe as it was before this file existed. A false positive gets
 * > the whole check deleted, and then nothing is caught at all — S120's lesson,
 * > learned here rather than reasoned about.
 *
 * So the rules below are deliberately narrow where narrowing is the only way to
 * stay quiet, and every limit is written down (see "Known limits") instead of
 * being papered over with a rule that would fire on innocent code.
 *
 * ## Four rules
 *
 * 1. {@see testNoTestDeclaresItsOwnMysqlReachabilityProbe()} — the historical
 *    *name*, `isMysqlReachable`, declared anywhere under `tests/`.
 * 2. {@see testNoTestOpensItsOwnSocketProbe()} — the historical *mechanism*, a
 *    raw socket call outside {@see SHARED_SUPPORT_DIR}.
 * 3. {@see testNoTestCatchesTheGuardAndSkips()} — the *defect* rather than
 *    either spelling: a skip reached from a `catch` around a database
 *    acquisition. After S126 the cheapest way to reintroduce "a broken database
 *    reports as an absent one" no longer looks like a socket probe at all:
 *
 *    ```php
 *    try { $this->db = $this->requireRealDatabase('…'); }
 *    catch (Throwable $e) { $this->markTestSkipped('no DB: ' . $e->getMessage()); }
 *    ```
 *
 *    That restores the defect *exactly* — {@see \Phlix\Tests\Support\Database\IntegrationDbUnusableException}
 *    is thrown for a reachable-but-unusable database precisely so the run
 *    reddens, and this shape converts it straight back into a green skip — while
 *    passing rules 1 and 2 and looking like the 35 files around it.
 * 4. {@see testTheSharedGuardIsAdoptedByTheIntegrationSuite()} — the shared
 *    guard is actually used; a silent rule set over a tree that stopped calling
 *    the guard would prove nothing.
 *
 * ## Static, not runtime — and why that is the opposite call from S120
 *
 * S120 shipped a *runtime* assertion-escape guard because its fact — "did an
 * assertion failure decide this test's outcome?" — is only knowable while the
 * test runs. The fact here is the exact opposite: "does this file declare its
 * own port probe?" is pure source text, fully available to `token_get_all()`,
 * with no runtime component. A runtime check would also be blind exactly when it
 * matters, because the defect's whole symptom is that the test **skips** — a
 * skipped test executes almost nothing, so on a machine with no MySQL a runtime
 * guard would never see the new copy. Static is the only form that does.
 *
 * Everything here is **token**-based, never `str_contains()` over raw source:
 * a name that appears only in a docblock, a comment or (for the identifier
 * rules) a string literal is not matched. That is what keeps the rules free of
 * the prose false positives a plain grep produces — two pre-S126 files carried
 * the word `fsockopen` in a docblock, and this file's own failure messages name
 * `RequiresRealDatabase`, `fsockopen` and `ConnectionPool` throughout without
 * matching anything.
 *
 * Planting is not ceremony: it is what found that the original rule-2 helper
 * keyed its results *by line*, so `@fsockopen(getenv('DB_HOST') ?: '127.0.0.1', …)`
 * was silently dropped when the trailing `getenv` on the same line overwrote it,
 * and it is what found that the same helper matched only `T_STRING`, so a single
 * leading backslash — `\fsockopen(`, which `php-cs-fixer`'s
 * `native_function_invocation` inserts automatically — evaded the rule entirely.
 * Both are fixed: {@see calledFunctionNames()} returns a list and normalises
 * every qualified-name token through {@see lastNameSegment()}.
 *
 * ## Narrowings, and what each one costs
 *
 * A narrowing is a deliberate trade of coverage for silence. Both are recorded
 * with the escape they buy, because a narrowing presented as free is a lie the
 * next reader will believe:
 *
 *  - `stream_socket_client()` / `socket_create()` / `socket_connect()`, and
 *    `fopen()` on a `tcp://`-style URL (which cannot actually open a socket —
 *    {@see NETWORK_URL_CALLS}), are only flagged in a file that also
 *    references the database configuration ({@see mentionsDatabaseConfig()}).
 *    Without it the rule fires on `tests/Unit/Discovery/Mdns/MdnsSocketTest.php:107,133`,
 *    which opens a UDP socket for mDNS and has nothing to do with MySQL.
 *    **Cost:** a probe that resolves its target by reading `config/database.php`
 *    into variables mentions none of `DB_HOST`/`DB_PORT`/`3306`/`ConnectionPool`
 *    — so `config/database.php` is itself a marker, but a probe that reaches the
 *    address by some fourth route still escapes.
 *    `fsockopen()`/`pfsockopen()` need no narrowing: they are the historical
 *    mechanism and have no other user in the tree.
 *  - Rule 3 requires a {@see DB_ACQUISITION_MARKERS} name (or
 *    `ConnectionPool::init()`) lexically inside the `try`. **Cost:** an
 *    acquisition moved one hop into a helper escapes — see "Known limits".
 *    A bare `ConnectionPool::getConnection()` is deliberately *not* an
 *    acquisition marker: it is the accessor for a connection the guard has
 *    already validated, and treating it as one makes this legitimate, desirable
 *    block fail with no remedy available —
 *
 *    ```php
 *    try { ConnectionPool::getConnection('mysql')->query('CREATE INDEX …'); }
 *    catch (Throwable $e) { $this->markTestSkipped('migration 072 not applied'); }
 *    ```
 *
 *    — which is "skip because a specific schema object is absent", not "skip
 *    because the database is unreachable". Three files already call
 *    `ConnectionPool::getConnection`. The pre-guard acquisition entry point
 *    `ConnectionPool::init()` stays a marker, so the historical shape is still
 *    caught.
 *
 * ## Known limits — escapes that are accepted, not overlooked
 *
 * Each of these was planted and measured as NOT flagged. They are left open
 * because closing them needs a rule broad enough to fire on innocent code, and
 * per the threat model above that trade is the wrong way round:
 *
 *  - the skip moved *after* the catch (`catch (Throwable $e) { $why = …; }` then
 *    `if ($why !== null) { $this->markTestSkipped($why); }`);
 *  - the acquisition moved one hop into a helper the `try` calls;
 *  - a probe spelled through `call_user_func('fsockopen', …)`, `eval()`, or any
 *    other indirection that hides the name from the token stream;
 *  - `@fopen("tcp://$host:$port", 'r')` — the *interpolated* spelling of the
 *    `fopen` rule. `@fopen('tcp://' . $host . ':' . $port, 'r')` is flagged, the
 *    interpolated one is not ({@see firstArgumentOpensANetworkUrl()}). Left open
 *    deliberately: `fopen()` cannot open a socket transport in either spelling
 *    ({@see NETWORK_URL_CALLS}), so no *working* probe hides here — and the
 *    working interpolated shape, `stream_socket_client("tcp://{$host}:{$port}", …)`,
 *    is flagged, because {@see DB_TARGETED_SOCKET_CALLS} applies no
 *    first-argument test at all.
 *
 * What *is* covered one hop out is the **skip**: a `catch` that calls a private
 * helper which itself calls `markTestSkipped()`/`markTestIncomplete()` is
 * flagged, transitively, because "private setUp helpers" is this tree's house
 * style rather than an evasion ({@see skipHelperNames()}).
 *
 * ## No opt-out, and where a genuine socket goes
 *
 * Like S120 there is no suppression list. A test that genuinely needs to open a
 * socket puts it behind a named helper anywhere under {@see SHARED_SUPPORT_DIR}
 * (`tests/Support/`), which is where shared test infrastructure lives and which
 * rules 2 and 3 exempt wholesale — verified by planting
 * `tests/Support/Network/PortHelper.php`, i.e. the remedy the failure message
 * prints, and confirming it clears the failure. That is a location rule, not an
 * escape hatch: it cannot be used to silence the check without the change being
 * visible in a shared directory.
 *
 * ## Delivery
 *
 * This is an ordinary PHPUnit test rather than a `scripts/` check wired into the
 * workflow. S120 needed a separate script because a PHPUnit *extension* cannot
 * influence the exit code under this repo's configuration
 * (`scripts/assertion-escape-check.php:8-22`); a plain test has no such problem,
 * needs no DB, fails the suite natively, and — the point — fires on the
 * developer's machine at the moment the copy is written rather than in CI later.
 *
 * It does not depend on S128: `phpstan.neon.dist` sets `paths: [src]`, so
 * PHPStan does not read `tests/` today, and this check deliberately does not
 * rely on that changing.
 */
final class IntegrationDbGuardAdoptionTest extends TestCase
{
    /**
     * Relative path, from the repo root, of the directory tree allowed to open a
     * socket and to own a `catch`/skip around a database acquisition.
     *
     * `tests/Support/` as a whole, not `tests/Support/Database/`: the failure
     * message of rule 2 tells the reader to put a genuine socket "behind a named
     * helper under tests/Support/", and a remedy that does not clear the failure
     * is worse than no remedy. Everything under here is shared infrastructure
     * rather than a test, so a probe added here is one reviewable copy by
     * construction. Verified: no file under `tests/Support/` uses
     * `RequiresRealDatabase` in a class body, so widening the exemption does not
     * move {@see EXPECTED_ADOPTERS}.
     */
    private const SHARED_SUPPORT_DIR = 'tests/Support';

    /**
     * Files that must carry `use RequiresRealDatabase;` **in a class body**.
     *
     * 35 files declared a private probe when S126 was written and every one of
     * them was migrated. Asserted as an exact count, not a floor: a floor of
     * `>= 35` against a count of 36 (the old whole-file substring match counted
     * this very file, whose failure messages name the trait) left exactly one
     * unit of slack, so one migrated test could silently drop the trait and the
     * check stayed green. If a real-DB test is legitimately deleted or added,
     * change this number in the same commit — that is the point.
     *
     * 36 since `tests/Integration/Media/StreamLanguageUtf8RoundTripTest.php`
     * was added: it proves a multi-byte `media_streams.language` value survives
     * the write that byte-wise truncation used to fail with MySQL 1366, which
     * only a real utf8mb4 server can decide.
     *
     * 37 since S158's
     * `tests/Integration/Media/Library/MovedTopLevelFileKeepsIdentityTest.php`:
     * it proves a moved top-level file keeps its row id and its cascading user
     * data. The in-memory `Connection` doubles return canned rows wholesale and
     * ignore the statement's column list, so no double can show whether
     * `media_items.path` was actually written, whether the STORED generated
     * `path_hash` followed it, or whether the `ON DELETE CASCADE` fired.
     *
     * 39 since the S99/S101 AC audit added the two real-DB legs their existing
     * coverage structurally could not have:
     * `tests/Integration/Media/MediaListBackdropIntegrationTest.php` — the S101
     * list backdrop read back through `ItemRepository::hydrateItem()`'s
     * `metadata_json` decode, which every pre-existing S101 test skipped by
     * handing the shaper an already-decoded `metadata` array (measured: with the
     * decode removed, `WebPortalRouterMediaTest` stays 35/35 green and this file
     * reddens); and
     * `tests/Integration/Media/MusicScannerToApiReadPathIntegrationTest.php` —
     * the S99 music API read back off rows the REAL `MusicLibraryScanner` wrote,
     * where the sibling `MusicApiReadPathIntegrationTest` seeds its own `INSERT`s
     * (measured: with the scanner's `duration_secs` write zeroed, that file stays
     * 14/14 green and this one reddens).
     *
     * 40 since S208's
     * `tests/Integration/Access/ParentalControlsCrossProfileRealDbTest.php`: the
     * cross-profile refusal it pins turns on `AccessSchedule::fromRow()` /
     * `ProfileTag::fromRow()` hydrating a real `CHAR(36)` `profile_id` (both used
     * to narrow it with `is_numeric()` + `(int)`, so every record carried
     * `profileId === 0`), and a canned-row `Connection` double can only ever hand
     * back the shape the test itself invented.
     *
     * 41 since S79's
     * `tests/Integration/Media/UserItemDataProfileMigrationTest.php`: the claim it
     * pins is that `migrations/100_user_item_data_profile_id.sql` preserves every
     * existing `user_item_data` row under some profile. That is a row-count
     * equality across a real `ALTER TABLE ... DROP PRIMARY KEY, ADD PRIMARY KEY`
     * over live rows, which a canned-row `Connection` double cannot express at
     * all. It is also the only adopter that opens its OWN connection to a scratch
     * database it creates, because the pre-migration state it needs (a nullable
     * `profile_id`) is unreachable on the shared, already-migrated `phlix_test`.
     */
    private const EXPECTED_ADOPTERS = 41;

    /**
     * Bare function calls that are a MySQL reachability probe under any
     * circumstances. Historical mechanism; no other user has ever existed here.
     */
    private const UNCONDITIONAL_SOCKET_CALLS = ['fsockopen', 'pfsockopen'];

    /**
     * Bare function calls that open a socket but have legitimate non-database
     * users in this tree, so they are only flagged in a file that also
     * references the database configuration ({@see mentionsDatabaseConfig()}).
     */
    private const DB_TARGETED_SOCKET_CALLS = ['stream_socket_client', 'socket_create', 'socket_connect'];

    /**
     * Calls flagged only when their first argument is a `tcp://`-style URL —
     * i.e. an *attempted* reachability probe. `fopen($tmpFile, 'w')` is not one,
     * and hundreds of tests do that, so these need the transport prefix *and*
     * the database-config narrowing before they are flagged.
     * {@see NETWORK_URL_SCHEMES}.
     *
     * ⚠ Neither of these functions can actually open such a URL, and this
     * docblock used to claim otherwise. `tcp://`, `udp://`, `ssl://`, `tls://`,
     * `unix://` and `udg://` are socket **transports** (`stream_get_transports()`),
     * not stream **wrappers** (`stream_get_wrappers()`), and only a wrapper is
     * reachable from `fopen()` / `file_get_contents()`. Measured on this box,
     * PHP 8.3.6:
     *
     * ```
     * in_array('tcp', stream_get_wrappers(), true)  -> false
     * stream_get_wrappers()   -> https,ftps,compress.zlib,php,file,glob,data,http,…
     * stream_get_transports() -> tcp,udp,unix,udg,ssl,tls,tlsv1.*,async.*
     * \@fopen('tcp://127.0.0.1:3306', 'r')  -> false, and error_get_last() is
     *   "fopen(tcp://127.0.0.1:3306): Failed to open stream: No such file or
     *    directory" — the FILE wrapper's ENOENT, i.e. the argument was treated
     *    as a filename. The same address via @stream_socket_client() returns
     *    "Connection refused", i.e. it really dialled.
     * ```
     *
     * So this rule can only ever fire on a probe that never worked. It is kept,
     * not deleted, because that is still worth naming at the moment it is
     * written: a reachability check that is a constant `false` makes its test
     * skip on every box forever, which is the S126 outcome — a green run that
     * never touched a database — reached by a different route. The spellings
     * that DO open a socket are covered by {@see UNCONDITIONAL_SOCKET_CALLS} and
     * {@see DB_TARGETED_SOCKET_CALLS}, neither of which applies any
     * first-argument test.
     */
    private const NETWORK_URL_CALLS = ['fopen', 'file_get_contents'];

    /**
     * Socket transport prefixes — "this argument is an address, not a path".
     * Written like stream wrappers, but they are not wrappers; see
     * {@see NETWORK_URL_CALLS} for what that costs this rule.
     */
    private const NETWORK_URL_SCHEMES = ['tcp://', 'udp://', 'ssl://', 'tls://', 'unix://', 'udg://'];

    /**
     * Identifier markers that say "this file talks to the configured MySQL",
     * matched on **name** tokens only (last segment, so a fully-qualified
     * spelling counts). Narrowing only — never used to widen a rule.
     */
    private const DB_CONFIG_NAME_MARKERS = [
        'ConnectionPool',
        'IntegrationDbGuard',
        'PhlixMySQLConnection',
        'PooledMySQLConnection',
    ];

    /**
     * The same, matched inside **string literals** only — `getenv('DB_HOST')`
     * puts the name in a string, not in an identifier. Comments and docblocks
     * are never consulted: a `3306` in a docblock explaining that a socket is
     * *not* MySQL used to arm this narrowing, which re-armed the one false
     * positive the narrowing exists to suppress.
     */
    private const DB_CONFIG_STRING_MARKERS = ['DB_HOST', 'DB_PORT', 'config/database.php'];

    /**
     * The default MySQL port, matched as a whole number in a numeric literal or
     * a string literal — `(?<!\d)3306(?!\d)`, so `33060` (the MySQL X protocol
     * port) and any longer id that happens to contain the digits do not arm it.
     */
    private const DB_PORT_LITERAL_PATTERN = '/(?<!\d)3306(?!\d)/';

    /**
     * Identifiers whose presence inside a `try` block means "this block acquires
     * the real database". A `catch` around one of these that reaches a skip is
     * the post-S126 spelling of the S126 defect.
     *
     * Matched on the last segment of the name, so
     * `\Phlix\Tests\Support\Database\IntegrationDbGuard::connection()` and a bare
     * `IntegrationDbGuard::connection()` both count.
     *
     * ⚠ `ConnectionPool` is deliberately absent — see the class docblock's
     * "Narrowings" section. `ConnectionPool::init()`, the pre-guard acquisition
     * entry point, is covered by {@see DB_ACQUISITION_STATIC_CALLS} instead.
     */
    private const DB_ACQUISITION_MARKERS = [
        'requireRealDatabase',
        'requireHealthyDatabase',
        'IntegrationDbGuard',
        'IntegrationDbUnusableException',
        'PhlixMySQLConnection',
        'PooledMySQLConnection',
    ];

    /**
     * `Class::method()` acquisition markers, for a class whose *other* methods
     * are legitimate inside a `try`.
     *
     * @var array<string, list<string>>
     */
    private const DB_ACQUISITION_STATIC_CALLS = ['ConnectionPool' => ['init']];

    /** The PHPUnit calls that end a test early with a green result. */
    private const SKIP_CALLS = ['markTestSkipped', 'markTestIncomplete'];

    /** Tokens that never carry meaning for any rule here. */
    private const IGNORED_TOKENS = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /**
     * The whole-tree scan, computed once per PHPUnit process.
     *
     * PHPUnit builds a fresh instance per test method, so without this the tree
     * was tokenised once per method. Memoising the *findings* (four small
     * arrays) rather than the token streams is what makes this cheap: the scan
     * below tokenises one file, inspects it, and discards the tokens before
     * reading the next, so peak memory is one file's tokens rather than 705
     * files' — measured 298 MB / 3.3 s before. Bounded to a single entry, so it
     * cannot grow.
     *
     * @var array{probes: list<string>, sockets: list<string>, catchSkips: list<string>, adopters: list<string>}|null
     */
    private static ?array $scan = null;

    public function testNoTestDeclaresItsOwnMysqlReachabilityProbe(): void
    {
        $offenders = self::scan()['probes'];

        $this->assertSame(
            [],
            $offenders,
            "S126: a private isMysqlReachable() probe reappeared under tests/.\n"
            . "An fsockopen() port probe cannot tell \"no MySQL on this box\" (skip) from\n"
            . "\"wrong credentials / missing database\" (a real failure), and in the default\n"
            . "pooled configuration nothing after it can fail either — PooledMySQLConnection\n"
            . "opens no socket in its constructor (src/Common/Database/PooledMySQLConnection.php:108).\n"
            . "Remedy: `use Phlix\\Tests\\Support\\Database\\RequiresRealDatabase;` in the class body\n"
            . "and call \$this->requireRealDatabase('skipping <what>. Runs in CI.') instead.\n"
            . "If this is a NEW test file, that also raises the adopter count, so bump\n"
            . "EXPECTED_ADOPTERS in this file by one in the same commit — see\n"
            . "testTheSharedGuardIsAdoptedByTheIntegrationSuite().\n"
            . 'Offending declarations: ' . implode(', ', $offenders),
        );
    }

    public function testNoTestOpensItsOwnSocketProbe(): void
    {
        $offenders = self::scan()['sockets'];

        $this->assertSame(
            [],
            $offenders,
            "S126: a raw socket call appeared in a test outside " . self::SHARED_SUPPORT_DIR . "/.\n"
            . "This is the mechanism of the 35 duplicated MySQL probes S126 removed: a port\n"
            . "probe that succeeds proves only that SOMETHING is listening, never that the\n"
            . "database is usable, so a broken config is reported as an absent one.\n"
            . "Remedy for MySQL: `use Phlix\\Tests\\Support\\Database\\RequiresRealDatabase;` and call\n"
            . "\$this->requireRealDatabase('skipping <what>. Runs in CI.').\n"
            . "Remedy for a socket that is genuinely something else: move the call into a named\n"
            . "helper class anywhere under " . self::SHARED_SUPPORT_DIR . "/ (e.g.\n"
            . self::SHARED_SUPPORT_DIR . "/Network/PortHelper.php) and call that from the test, so\n"
            . "there is one reviewable copy rather than 35. That whole tree is exempt from this rule.\n"
            . 'Offending calls: ' . implode(', ', $offenders),
        );
    }

    /**
     * The rule that describes the defect rather than its 2026-07 spelling.
     *
     * `IntegrationDbUnusableException` exists so that "something is listening on
     * the DB port but no query can run over it" reddens the run. Catching it —
     * or catching anything thrown by a database-acquisition call — and skipping
     * converts it straight back into the green skip S126 removed, without ever
     * mentioning `isMysqlReachable` or `fsockopen`.
     *
     * Skipping from a `catch` is only flagged when the `try` actually *acquires*
     * the database. `try { <use an already-validated connection> } catch { skip }`
     * — the "this migration is not applied on this box" shape — is legitimate and
     * is not flagged; see the class docblock.
     */
    public function testNoTestCatchesTheGuardAndSkips(): void
    {
        $offenders = self::scan()['catchSkips'];

        $this->assertSame(
            [],
            $offenders,
            "S126: a catch around a database-ACQUISITION call ends in a skip.\n"
            . "That is the S126 defect in its post-migration spelling: IntegrationDbUnusableException\n"
            . "is raised precisely so a reachable-but-UNUSABLE database reddens the run, and swallowing\n"
            . "it into a skip reports success without ever touching a database — the exact outcome the\n"
            . "35 fsockopen() probes produced.\n"
            . "Remedy: call \$this->requireRealDatabase('skipping <what>. Runs in CI.') WITHOUT a\n"
            . "try/catch. It already skips on genuine absence (nothing listening) all by itself.\n"
            . "If you are skipping because a specific migration/index/table is absent rather than\n"
            . "because the database is unreachable, keep the acquisition OUTSIDE the try and leave\n"
            . "only the schema probe inside it — that shape is deliberately not flagged.\n"
            . 'Offending catch blocks: ' . implode(', ', $offenders),
        );
    }

    /**
     * The migrated tree must actually be using the shared guard — a rule that is
     * silent because nothing calls the guard at all would prove nothing.
     *
     * Counts real, in-class-body `use RequiresRealDatabase;` trait usage via the
     * token stream. A whole-file `str_contains()` would also count a file that
     * keeps only the `use Phlix\…\RequiresRealDatabase;` import or a
     * `{@see RequiresRealDatabase}` docblock after the in-class `use` was
     * dropped — which is the realistic regression shape (setUp rewritten,
     * imports left behind), and 12 migrated files mention the name 2–3 times.
     */
    public function testTheSharedGuardIsAdoptedByTheIntegrationSuite(): void
    {
        $adopters = self::scan()['adopters'];

        $this->assertCount(
            self::EXPECTED_ADOPTERS,
            $adopters,
            sprintf(
                'S126: %d files declare `use RequiresRealDatabase;` in a class body, expected %d. '
                . 'If a real-DB test was added or removed, update EXPECTED_ADOPTERS in the same '
                . 'commit; if the trait was dropped from a test while its import or docblock stayed '
                . 'behind, restore it. Current adopters: %s',
                count($adopters),
                self::EXPECTED_ADOPTERS,
                implode(', ', $adopters),
            ),
        );
    }

    /**
     * One tokenise-inspect-discard pass over every `*.php` under `tests/`.
     *
     * @return array{probes: list<string>, sockets: list<string>, catchSkips: list<string>, adopters: list<string>}
     */
    private static function scan(): array
    {
        if (self::$scan !== null) {
            return self::$scan;
        }

        $root = dirname(__DIR__, 3);

        $probes = [];
        $sockets = [];
        $catchSkips = [];
        $adopters = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/tests', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1);
            /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
            $tokens = token_get_all((string) file_get_contents($path));

            foreach (self::declaredFunctionNames($tokens) as [$line, $name]) {
                if ($name === 'isMysqlReachable') {
                    $probes[] = $relative . ':' . $line;
                }
            }

            // Everything below exempts tests/Support/ wholesale — see
            // SHARED_SUPPORT_DIR. This file's own rule names live in string
            // literals and docblocks, which no rule here can see.
            if (!str_starts_with($relative, self::SHARED_SUPPORT_DIR . '/')) {
                foreach (self::socketProbeLines($tokens) as $line) {
                    $sockets[] = $relative . ':' . $line;
                }

                foreach (self::catchAndSkipLines($tokens) as $line) {
                    $catchSkips[] = $relative . ':' . $line;
                }

                if (in_array('RequiresRealDatabase', self::traitUses($tokens), true)) {
                    $adopters[] = $relative;
                }
            }

            unset($tokens);
        }

        sort($probes);
        sort($sockets);
        sort($catchSkips);
        sort($adopters);

        self::$scan = [
            'probes' => $probes,
            'sockets' => $sockets,
            'catchSkips' => $catchSkips,
            'adopters' => $adopters,
        ];

        return self::$scan;
    }

    /**
     * Lines of socket-probe calls in one file.
     *
     * {@see UNCONDITIONAL_SOCKET_CALLS} count on their own;
     * {@see DB_TARGETED_SOCKET_CALLS} and a {@see NETWORK_URL_CALLS} call on a
     * `tcp://`-style URL only count in a file that also references the database
     * configuration. The narrowing pass runs only when there is something to
     * narrow, so the ~700 files with no socket call in them never pay for it.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<int>
     */
    private static function socketProbeLines(array $tokens): array
    {
        $lines = [];
        $narrowed = [];

        foreach (self::calledFunctionNames($tokens) as [$line, $name, $paren]) {
            if (in_array($name, self::UNCONDITIONAL_SOCKET_CALLS, true)) {
                $lines[] = $line;
                continue;
            }

            if (in_array($name, self::DB_TARGETED_SOCKET_CALLS, true)) {
                $narrowed[] = $line;
                continue;
            }

            if (
                in_array($name, self::NETWORK_URL_CALLS, true)
                && self::firstArgumentOpensANetworkUrl($tokens, $paren)
            ) {
                $narrowed[] = $line;
            }
        }

        if ($narrowed !== [] && self::mentionsDatabaseConfig($tokens)) {
            $lines = array_merge($lines, $narrowed);
        }

        sort($lines);

        return array_values(array_unique($lines));
    }

    /**
     * Whether the first argument of the call whose `(` sits at `$paren` begins
     * with a **single-token** string literal carrying a socket transport prefix
     * ({@see NETWORK_URL_SCHEMES} — a transport, not a stream wrapper, and
     * {@see NETWORK_URL_CALLS} for why that matters).
     *
     * ```
     * \@fopen('tcp://' . $host . ':' . $port, 'r')   matches
     * \@fopen("tcp://$host:$port", 'r')              does NOT match — see below
     * fopen($this->tempDir . '/x.mp4', 'w')         does not match
     * ```
     *
     * ⚠ The interpolated spelling is a measured gap, not an oversight. This test
     * requires `T_CONSTANT_ENCAPSED_STRING`; a double-quoted interpolated string
     * produces no such token — `token_get_all()` emits the bare `"` character,
     * then `T_ENCAPSED_AND_WHITESPACE 'tcp://'`, `T_VARIABLE '$host'`, … — so it
     * is rejected below even though it does lead with a literal. It is left open
     * because widening it would buy coverage of no working probe: neither
     * spelling can open a socket through `fopen()` at all, and the *functional*
     * interpolated shape is already flagged elsewhere with no first-argument
     * test — measured, `@stream_socket_client("tcp://{$host}:{$port}", $errno,
     * $errstr, 0.5)` in a file whose only DB markers are
     * `getenv('DB_HOST')`/`getenv('DB_PORT')` fires via
     * {@see DB_TARGETED_SOCKET_CALLS}.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function firstArgumentOpensANetworkUrl(array $tokens, int $paren): bool
    {
        $index = self::significantIndex($tokens, $paren + 1);

        if ($index === null) {
            return false;
        }

        $token = $tokens[$index];

        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return false;
        }

        $literal = self::stringLiteralValue($token);

        foreach (self::NETWORK_URL_SCHEMES as $scheme) {
            if (stripos($literal, $scheme) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a file references the configured MySQL at all. Narrowing only —
     * see the class docblock.
     *
     * Token-aware on purpose. The predecessor was a `str_contains()` over raw
     * source, which contradicted this file's own design principle and re-armed
     * the very false positive the narrowing exists to suppress: a `3306` written
     * in a docblock — for instance a reviewer's note explaining that an mDNS
     * socket is *not* MySQL — was enough to arm the rule on that file.
     * Comments, docblocks and identifiers are told apart here, and the port
     * number is matched with digit boundaries so `33060` does not count.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function mentionsDatabaseConfig(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            if (self::isNameToken($token[0])) {
                if (in_array(self::lastNameSegment($token[1]), self::DB_CONFIG_NAME_MARKERS, true)) {
                    return true;
                }

                continue;
            }

            if ($token[0] === T_LNUMBER && preg_match(self::DB_PORT_LITERAL_PATTERN, $token[1]) === 1) {
                return true;
            }

            if (!in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            $literal = self::stringLiteralValue($token);

            if (preg_match(self::DB_PORT_LITERAL_PATTERN, $literal) === 1) {
                return true;
            }

            foreach (self::DB_CONFIG_STRING_MARKERS as $marker) {
                if (str_contains($literal, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The text of a string-literal token with its surrounding quotes removed.
     *
     * @param array{0: int, 1: string, 2: int} $token
     */
    private static function stringLiteralValue(array $token): string
    {
        if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return $token[1];
        }

        $quote = substr($token[1], 0, 1);

        return $quote === "'" || $quote === '"' ? substr($token[1], 1, -1) : $token[1];
    }

    /**
     * Names declared with the `function` keyword, as `[line, name]` pairs.
     *
     * ⚠ A *list*, not a map keyed by line. Keying by line silently drops every
     * occurrence but the last on a shared line — the bug that let
     * `@fsockopen(getenv('DB_HOST') ?: '127.0.0.1', …)` evade the socket rule
     * entirely, because `getenv` came after it on the same line and overwrote it.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{0: int, 1: string}>
     */
    private static function declaredFunctionNames(array $tokens): array
    {
        $names = [];

        foreach (self::functionDeclarations($tokens) as [$line, $name, $_nameIndex]) {
            $names[] = [$line, $name];
        }

        return $names;
    }

    /**
     * Every `function <name>` declaration, as `[line, name, nameIndex]`.
     * Closures and arrow functions have no name and are not returned.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{0: int, 1: string, 2: int}>
     */
    private static function functionDeclarations(array $tokens): array
    {
        $declarations = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];

                if (is_array($next) && in_array($next[0], self::IGNORED_TOKENS, true)) {
                    continue;
                }

                // `function &foo()` — a by-reference return.
                if ($next === '&') {
                    continue;
                }

                if (is_array($next) && $next[0] === T_STRING) {
                    $declarations[] = [$next[2], $next[1], $j];
                }

                break;
            }
        }

        return $declarations;
    }

    /**
     * Named function/method bodies, as `[name, openBraceIndex, closeBraceIndex]`.
     * Abstract and interface methods (no body) are skipped.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private static function functionBodies(array $tokens): array
    {
        $bodies = [];
        $count = count($tokens);

        foreach (self::functionDeclarations($tokens) as [$_line, $name, $nameIndex]) {
            $paren = null;

            for ($i = $nameIndex + 1; $i < $count; $i++) {
                if ($tokens[$i] === '(') {
                    $paren = $i;
                    break;
                }

                if ($tokens[$i] === ';' || self::opensBrace($tokens[$i])) {
                    break;
                }
            }

            $parenEnd = $paren === null ? null : self::matchingParen($tokens, $paren);

            if ($parenEnd === null) {
                continue;
            }

            $open = null;

            for ($i = $parenEnd + 1; $i < $count; $i++) {
                if ($tokens[$i] === ';') {
                    break;
                }

                if (self::opensBrace($tokens[$i])) {
                    $open = $i;
                    break;
                }
            }

            $close = $open === null ? null : self::matchingBrace($tokens, $open);

            if ($open !== null && $close !== null) {
                $bodies[] = [$name, $open, $close];
            }
        }

        return $bodies;
    }

    /**
     * Everything in this file that ends a test early with a green result:
     * `markTestSkipped`/`markTestIncomplete` plus every method whose body
     * reaches one of them, transitively.
     *
     * This is the one place the rules look a hop out from the `catch`, and it is
     * deliberate: `catch (Throwable $e) { $this->noDatabase($e); }` with a
     * private `noDatabase()` that skips is the same defect as an inline skip,
     * and every one of the 35 migrated files already owns private `setUp`
     * helpers, so "one hop" is this tree's house style rather than an evasion.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<string>
     */
    private static function skipHelperNames(array $tokens): array
    {
        $bodies = self::functionBodies($tokens);
        $names = self::SKIP_CALLS;

        do {
            $added = false;

            foreach ($bodies as [$name, $open, $close]) {
                if (in_array($name, $names, true)) {
                    continue;
                }

                if (self::spanNames($tokens, $open, $close, $names) !== []) {
                    $names[] = $name;
                    $added = true;
                }
            }
        } while ($added);

        return $names;
    }

    /**
     * Trait names brought in by a `use` **inside a class body**, unqualified.
     *
     * Three `use` spellings share the keyword and must be told apart:
     *  - a file-level import (`use Foo\Bar;`) — always at brace depth 0;
     *  - a closure capture (`function () use ($x)`) — the next significant token
     *    is `(`;
     *  - a trait use (`use Bar;` / `use Foo\Bar;` / `use A, B { … }`) — what this
     *    returns.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<string>
     */
    private static function traitUses(array $tokens): array
    {
        $names = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (self::opensBrace($token)) {
                $depth++;
                continue;
            }

            if ($token === '}') {
                $depth--;
                continue;
            }

            if (!is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            if ($depth === 0 || self::significantToken($tokens, $i, 1) === '(') {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];

                if ($next === ';' || $next === '{') {
                    break;
                }

                if (is_array($next) && self::isNameToken($next[0])) {
                    $names[] = self::lastNameSegment($next[1]);
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Lines at which a `catch` reaches a skip, where that `catch` either names
     * `IntegrationDbUnusableException` or guards a `try` that acquires the
     * database ({@see DB_ACQUISITION_MARKERS}, {@see DB_ACQUISITION_STATIC_CALLS}).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<int>
     */
    private static function catchAndSkipLines(array $tokens): array
    {
        $lines = [];
        $count = count($tokens);
        // Resolved on first use, not up front: most files under tests/ contain no
        // `try` at all, and walking every method body of all 705 of them to build
        // the transitive skip-helper set doubled this test's wall time.
        $skipNames = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_TRY) {
                continue;
            }

            $open = self::nextBrace($tokens, $i);
            $close = $open === null ? null : self::matchingBrace($tokens, $open);

            if ($open === null || $close === null) {
                continue;
            }

            $acquiresDatabase = self::spanAcquiresDatabase($tokens, $open, $close);
            $cursor = $close;

            while (true) {
                $next = self::significantIndex($tokens, $cursor + 1);

                if ($next === null) {
                    break;
                }

                $clause = $tokens[$next];

                if (!is_array($clause) || $clause[0] !== T_CATCH) {
                    break;
                }

                $body = self::nextBrace($tokens, $next);
                $bodyEnd = $body === null ? null : self::matchingBrace($tokens, $body);

                if ($body === null || $bodyEnd === null) {
                    break;
                }

                $catchesTheGuard = $acquiresDatabase
                    || self::spanNames($tokens, $next, $body, ['IntegrationDbUnusableException']) !== [];

                if ($catchesTheGuard) {
                    $skipNames ??= self::skipHelperNames($tokens);

                    foreach (self::spanNames($tokens, $body, $bodyEnd, $skipNames) as [$line, $_name]) {
                        $lines[] = $line;
                    }
                }

                $cursor = $bodyEnd;
            }
        }

        sort($lines);

        return array_values(array_unique($lines));
    }

    /**
     * Whether the token span `($from, $to)` acquires the real database.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function spanAcquiresDatabase(array $tokens, int $from, int $to): bool
    {
        if (self::spanNames($tokens, $from, $to, self::DB_ACQUISITION_MARKERS) !== []) {
            return true;
        }

        for ($i = $from + 1; $i < $to; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || !self::isNameToken($token[0])) {
                continue;
            }

            $methods = self::DB_ACQUISITION_STATIC_CALLS[self::lastNameSegment($token[1])] ?? null;

            if ($methods === null) {
                continue;
            }

            $colon = self::significantIndex($tokens, $i + 1);
            $method = $colon === null ? null : self::significantIndex($tokens, $colon + 1);

            if ($colon === null || $method === null) {
                continue;
            }

            $colonToken = $tokens[$colon];
            $methodToken = $tokens[$method];

            if (!is_array($colonToken) || $colonToken[0] !== T_DOUBLE_COLON) {
                continue;
            }

            if (is_array($methodToken) && $methodToken[0] === T_STRING && in_array($methodToken[1], $methods, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bare function names that are immediately followed by `(`, as
     * `[line, name, parenIndex]` triples — see {@see declaredFunctionNames()}
     * for why this is not keyed by line.
     *
     * ⚠ Every name token is normalised through {@see lastNameSegment()}, so
     * `\fsockopen(` (`T_NAME_FULLY_QUALIFIED`) counts exactly as `fsockopen(`
     * (`T_STRING`) does. Matching only `T_STRING` let one leading backslash —
     * the form `php-cs-fixer`'s `native_function_invocation` writes — carry the
     * entire pre-S126 probe past this rule.
     *
     * Deliberately ignores `->name(`, `?->name(`, `::name(`, `new Name(` and
     * `#[Attr(` so a method, constructor or attribute that happens to share a
     * name with a global function is not matched.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{0: int, 1: string, 2: int}>
     */
    private static function calledFunctionNames(array $tokens): array
    {
        $names = [];
        $count = count($tokens);
        $qualifiers = [
            T_OBJECT_OPERATOR,
            T_NULLSAFE_OBJECT_OPERATOR,
            T_DOUBLE_COLON,
            T_FUNCTION,
            T_NEW,
            T_ATTRIBUTE,
        ];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || !self::isNameToken($token[0])) {
                continue;
            }

            $previous = self::significantToken($tokens, $i, -1);

            if (is_array($previous) && in_array($previous[0], $qualifiers, true)) {
                continue;
            }

            $paren = self::significantIndex($tokens, $i + 1);

            if ($paren !== null && $tokens[$paren] === '(') {
                $names[] = [$token[2], self::lastNameSegment($token[1]), $paren];
            }
        }

        return $names;
    }

    /**
     * Occurrences of any `$wanted` identifier strictly between two token indexes,
     * as `[line, name]` pairs. Matches on the last segment of a qualified name,
     * so `\Phlix\Common\Database\ConnectionPool` and `ConnectionPool` both count.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<string>                                  $wanted
     * @return list<array{0: int, 1: string}>
     */
    private static function spanNames(array $tokens, int $from, int $to, array $wanted): array
    {
        $found = [];

        for ($i = $from + 1; $i < $to; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || !self::isNameToken($token[0])) {
                continue;
            }

            $name = self::lastNameSegment($token[1]);

            if (in_array($name, $wanted, true)) {
                $found[] = [$token[2], $name];
            }
        }

        return $found;
    }

    /**
     * Index of the first `{` at or after `$from`, or `null`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextBrace(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($i = $from; $i < $count; $i++) {
            if (self::opensBrace($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Index of the `}` closing the `{` at `$open`, or `null`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function matchingBrace(array $tokens, int $open): ?int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $token = $tokens[$i];

            if (self::opensBrace($token)) {
                $depth++;
                continue;
            }

            if ($token === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Index of the `)` closing the `(` at `$open`, or `null`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function matchingParen(array $tokens, int $open): ?int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i] === '(') {
                $depth++;
                continue;
            }

            if ($tokens[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * `{`, plus the two interpolation openers that are also closed by a plain
     * `}` (`"{$a}"` and `"${a}"`), so brace depth stays balanced inside strings.
     *
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function opensBrace(array|string $token): bool
    {
        if ($token === '{') {
            return true;
        }

        return is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
    }

    private static function isNameToken(int $type): bool
    {
        return in_array($type, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);
    }

    private static function lastNameSegment(string $name): string
    {
        $parts = explode('\\', $name);

        return (string) end($parts);
    }

    /**
     * The nearest token in `$direction` that is not whitespace or a comment.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function significantToken(array $tokens, int $from, int $direction): array|string|null
    {
        for ($i = $from + $direction; $i >= 0 && $i < count($tokens); $i += $direction) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], self::IGNORED_TOKENS, true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * Index of the first token at or after `$from` that is not whitespace or a
     * comment.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function significantIndex(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($i = max(0, $from); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], self::IGNORED_TOKENS, true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
