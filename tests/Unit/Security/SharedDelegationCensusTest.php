<?php

/**
 * Phlix media server component: Security tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Security;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Shared-delegation census — the SRV-CENSUS2 rebaseline of Wave-S1.
 *
 * The SRV-MICRO-era delegation census was quote-agnostic by design (see
 * tests/Unit/Common/Time/HttpDateTest.php and its M4 canary), but it was
 * built for a world whose SSOT bodies lived in IN-REPO twin classes.
 * Wave-S1 (aec5118f pin; b93d73a2 primitives; 4c0ae4f4 auth/origin/digest;
 * 3b878403 relay codec + dialect; 665c691a webhook HMAC) moved roughly
 * seven primitive families' bodies onto vendor/detain/phlix-shared classes,
 * so recognizing "the twin in src/" strains: this census instead COUNTS the
 * delegations directly, and a body that re-inlines its implementation
 * changes the count — reddening the census.
 *
 * Five legs, one classifier (census and canary share every detector, so
 * they can never drift apart — the SRV-MICRO r3 law, kept):
 *
 *  1. Exact per-family static-forward pins across src/ (110 sites, 15
 *     families). S431 discipline: re-pin ONLY from a measured red after
 *     adjudicating every delta. An empty walk returns all-zeros and
 *     reddens — a dead scanner never reads as "law holds".
 *  2. Carrier + member tables: which first-party file forwards which
 *     shared members. A carrier that reclaims a body reddens twice
 *     (the family pin drops AND the member set changes).
 *  3. Grammar-intrusion laws, token-classified (T_CONSTANT values and
 *     exact T_STRING texts — invisible to any quoting style, immune to
 *     comments and docblocks): whole-src for the base64url alphabet
 *     pair ('+/' / '-_'), the legacy 'sha256=' HMAC prefix, and PEM
 *     labels, with one rent-paying allowlist entry; file-scoped for
 *     inline sha256 digests in digest carriers, a pack() header
 *     builder in the relay framer, and an inline state-changing verb
 *     array in the origin-policy carriers.
 *  4. Delegate-purity law SHAPES recognized as delegation, not as an
 *     inline body: (S) static forward `return Shared::m(...)`;
 *     (A) alias const `const X = Shared::X` — counted as a forward and
 *     shape-pinned (a quoted literal value is the M4 class on the
 *     const surface); (O) shared-typed collaborator `$this->policy->m(...)`
 *     or `$policy->m(...)` where the variable/property is typed with a
 *     family class. (O) has zero live carriers today — the canary pins
 *     that a future collaborator-form adoption is counted as
 *     delegation, not demanded to spell itself as (S).
 *
 * Out of scope by design (documented neighbours, not gaps): the IN-REPO
 * SSOT families — Phlix\Common\Env\EnvConfig (bfed746f E-04), HttpDate
 * (E-16) and Token (E-13) own their bodies and are guarded by their own
 * file-scoped census legs; the password_* primitive census stays in
 * PasswordHasherMintSiteCensusTest, whose delegate-purity form already
 * reads the shared class. This census still counts the shared
 * PasswordHasher forward sites as a family (shim + burn-const carriers).
 */
final class SharedDelegationCensusTest extends TestCase
{
    private const F_BASE64URL = 'Phlix\Shared\Security\Base64Url';
    private const F_ED25519_PEM = 'Phlix\Shared\Security\Ed25519Pem';
    private const F_HMAC = 'Phlix\Shared\Security\Hmac';
    private const F_CREDENTIAL_DIGEST = 'Phlix\Shared\Security\CredentialDigest';
    private const F_PKCE = 'Phlix\Shared\Security\Pkce';
    private const F_ORIGIN_POLICY = 'Phlix\Shared\Security\OriginPolicy';
    private const F_SESSION_FAMILY = 'Phlix\Shared\Auth\SessionFamily';
    private const F_SHARED_PASSWORD_HASHER = 'Phlix\Shared\Auth\PasswordHasher';
    private const F_FRAME_CODEC = 'Phlix\Shared\Relay\FrameCodec';
    private const F_SYNCPLAY_DIALECT = 'Phlix\Shared\Relay\SyncPlayDialect';
    private const F_SUPPORT_JSON = 'Phlix\Shared\Support\Json';
    private const F_DB_MYSQL_ERROR = 'Phlix\Shared\Database\MysqlError';
    private const F_DB_WRITE_RESULT = 'Phlix\Shared\Database\WriteResult';
    private const F_DB_UUID = 'Phlix\Shared\Database\Uuid';
    private const F_SETTINGS_CODEC = 'Phlix\Shared\Settings\SettingsCodec';

    /**
     * Exact static-forward site counts over src/ at tip 3dd624e0 (the
     * a3330d5b-era ten-family totals re-measured unchanged; the eleventh
     * family and the r1 adjudication rows below).
     *
     * Staircase provenance (the Wave-S1 record, per family):
     *  - b93d73a2 (S1.2 primitives): Base64Url 9 (JwtHandler x2, SignedUrl,
     *    WebAuthnManager x2, Ed25519KeyManager, HubClient, HubJwtValidator,
     *    Pkce), Ed25519Pem 4 (buildPem/parsePem/fingerprint/PREFIX_BYTES_SERVER),
     *    Pkce 2 (Pkce::computeCodeChallenge, TraktApi), SessionFamily 2
     *    (JwtHandler claim aliases).
     *  - 4c0ae4f4 (S1.3 auth/origin/digest): OriginPolicy 8 (5 forwarded
     *    arms + 2 MODE_* aliases in CookiePolicy, 1 stateChangingMethod in
     *    RequestAuthenticator), CredentialDigest 5, shared PasswordHasher 5
     *    (shim hash/verify/needsRehash/ARGON2ID_OPTIONS + UserRepository
     *    TIMING_BURN_DUMMY_HASH alias).
     *  - 3b878403 (S1.7 relay): FrameCodec 3, SyncPlayDialect 19 (the pure
     *    TYPE_* alias catalog, design M-33).
     *  - 665c691a (S1.10 webhooks): Hmac 4 (compute/verify + 2
     *    SEPARATOR_EQUALS const arms on their call lines).
     *  - 7506f306 (DECODE-ADOPT srv): Support\Json 8 — the seven decodeInbound
     *    sites named in the commit subject plus one same-family forward the
     *    subject lumped; the disk count governs (the walker sees 8).
     *  - r1 fix row (S1 CRITICAL-1, restored at ee9f1c4c atop this baseline):
     *    Base64Url 9→8 — WebAuthnManager's strict-DECODE seam was RESTORED to
     *    the ratified legacy pad-before-strict strtr+str_pad body (the
     *    234k-vector corpus proved the shared strict arm moves the
     *    malformed-shape acceptance policy BOTH directions — 6,646
     *    truncated-padding rejects + 2,264 embedded-whitespace accepts; zero
     *    behavior change is the wave law, strictness modernization banked as a
     *    v0.57 proposal). The encode arm keeps its Base64Url::encode
     *    delegation, so the file remains a carrier — its Base64Url multiset is
     *    now [encode] only — and it pays alphabet-pair grammar rent
     *    (RENT_B64_PAIR_MINTS, rationale there + pinned by
     *    tests/Unit/Security/WebAuthnLegacyDecodeParityTest.php). Both rent
     *    directions are enforced: entry without mint and mint without entry
     *    each redden — this is exactly the law that caught the live working
     *    tree while the revert was staged (three legs red, zero laundering).
     *  - srv-W2/srv-W3a (v0.57 consumer wave, SRV-WAVE-AB2 lane): the
     *    Shared\Database\MysqlError 19 and Shared\Database\WriteResult 20
     *    pins are the RETIREMENT sides, not new
     *    delegations: the in-repo twins were deleted at 2fd292a0/853e68a5 and the consumer
     *    imports now name the vendor kernel directly. The unregistered-shared-import net
     *    caught the imports (red at tip 853e68a5, 'imported that the census has never heard
     *    of'); this registration is the cure, counts re-pinned from the measured walk.
     *  - srv-W3b (same lane): Shared\Database\Uuid 2 — src/Common/Uuid.php became a
     *    thin external-contract shell (phlix-plugin-trakt's host-provided id mint)
     *    forwarding v4()/isV4(); this is the delegation direction the census was built
     *    for, registered at landing rather than caught later.
     *  - srv-W4 (same lane): Shared\Settings\SettingsCodec 1 — the SettingsRepository decode
     *    door forwards to the kernel after a both-direction 37-vector differential
     *    measured 0 deltas; the encode door KEEPS its silent-store body (the R1
     *    server-silent-vs-hub-throw posture split is an owner row, not a wave).
     * Total 110 sites / 47 family-carrier rows (42 distinct files).
     * Re-pin from measured red only.
     *
     * @var array<string, int>
     */
    private const FAMILY_FORWARD_SITES = [
        self::F_BASE64URL => 8,
        self::F_ED25519_PEM => 4,
        self::F_HMAC => 4,
        self::F_CREDENTIAL_DIGEST => 5,
        self::F_PKCE => 2,
        self::F_ORIGIN_POLICY => 8,
        self::F_SESSION_FAMILY => 2,
        self::F_SHARED_PASSWORD_HASHER => 5,
        self::F_FRAME_CODEC => 3,
        self::F_SYNCPLAY_DIALECT => 19,
        self::F_SUPPORT_JSON => 8,
        self::F_DB_MYSQL_ERROR => 19,
        self::F_DB_WRITE_RESULT => 20,
        self::F_DB_UUID => 2,
        self::F_SETTINGS_CODEC => 1,
    ];

    /**
     * Registered carrier tables: family => src-relative file => the exact
     * multiset of shared members it forwards (constants count as members;
     * the alias form `TYPE_INFO = SyncPlayDialect::TYPE_INFO` forwards the
     * member TYPE_INFO). Sorted at compare time; order in the literal is
     * readability only.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const FAMILY_CARRIER_MEMBERS = [
        self::F_BASE64URL => [
            'Auth/JwtHandler.php' => ['encode', 'decode'],
            'Auth/SignedUrl.php' => ['encode'],
            'Auth/WebAuthn/WebAuthnManager.php' => ['encode'],
            'Hub/Ed25519KeyManager.php' => ['encode'],
            'Hub/HubClient.php' => ['decode'],
            'Hub/HubJwtValidator.php' => ['decode'],
            'Plugins/OAuth2/Pkce.php' => ['encode'],
        ],
        self::F_ED25519_PEM => [
            'Hub/Ed25519KeyManager.php' => ['buildPem', 'parsePem', 'fingerprint', 'PREFIX_BYTES_SERVER'],
        ],
        self::F_HMAC => [
            'Webhooks/WebhookEvent.php' => ['compute', 'verify', 'SEPARATOR_EQUALS', 'SEPARATOR_EQUALS'],
        ],
        self::F_CREDENTIAL_DIGEST => [
            'Auth/UserRepository.php' => ['digest'],
            'Plugins/OAuth2/StateCorrelation.php' => ['digest'],
            'Session/SyncPlay/GroupState.php' => ['digest', 'match'],
            'Session/SyncPlay/Messages.php' => ['digest'],
        ],
        self::F_PKCE => [
            'Plugins/OAuth2/Pkce.php' => ['challengeFor'],
            'Server/Integrations/Trakt/TraktApi.php' => ['challengeFor'],
        ],
        self::F_ORIGIN_POLICY => [
            'Auth/CookiePolicy.php' => [
                'custodyMode',
                'httpsForwardedValue',
                'localhostHost',
                'originIsSameSite',
                'stateChangingRequestOriginAllowed',
                'MODE_COOKIE',
                'MODE_BEARER',
            ],
            'Server/Http/RequestAuthenticator.php' => ['stateChangingMethod'],
        ],
        self::F_SESSION_FAMILY => [
            'Auth/JwtHandler.php' => ['CLAIM_PROFILE_ID', 'CLAIM_SESSION_ID'],
        ],
        self::F_SHARED_PASSWORD_HASHER => [
            'Auth/PasswordHasher.php' => ['ARGON2ID_OPTIONS', 'hash', 'needsRehash', 'verify'],
            'Auth/UserRepository.php' => ['TIMING_BURN_DUMMY_HASH'],
        ],
        self::F_FRAME_CODEC => [
            'Hub/RelayMessageFramer.php' => ['encode', 'encodeHello', 'encodeHelloAck'],
        ],
        self::F_SYNCPLAY_DIALECT => [
            'Session/SyncPlay/Messages.php' => [
                'TYPE_GROUP_CREATE',
                'TYPE_GROUP_JOIN',
                'TYPE_GROUP_LEAVE',
                'TYPE_GROUP_STATE',
                'TYPE_GROUP_LIST',
                'TYPE_PLAYBACK_PLAY',
                'TYPE_PLAYBACK_PAUSE',
                'TYPE_PLAYBACK_SEEK',
                'TYPE_PLAYBACK_QUEUE',
                'TYPE_PLAYBACK_SYNC',
                'TYPE_CHAT_MESSAGE',
                'TYPE_CHAT_TYPING',
                'TYPE_HOST_TRANSFER',
                'TYPE_HOST_ELECT',
                'TYPE_TIME_PING',
                'TYPE_TIME_PONG',
                'TYPE_TIME_SYNC',
                'TYPE_ERROR',
                'TYPE_INFO',
            ],
        ],
        self::F_SUPPORT_JSON => [
            'Hub/RelayConsumer.php' => ['decodeInbound', 'decodeInbound'],
            'Playlists/SmartPlaylistController.php' => ['decodeInbound', 'decodeInbound'],
            'Server/Http/Controllers/MediaMarkerController.php' => ['decodeInbound'],
            'Server/Http/Request.php' => ['decodeInbound', 'decodeInbound'],
            'Server/WebSocket/MessageHandler.php' => ['decodeInbound'],
        ],
        self::F_DB_MYSQL_ERROR => [
            'Auth/AuthManager.php' => [
                'DUPLICATE_ENTRY_PHRASE',
                'SQLSTATE_INTEGRITY',
                'driverCode',
                'isDuplicateKey',
            ],
            'Common/Database/MigrationRunner.php' => ['driverCode'],
            'Common/Database/PhlixMySQLConnection.php' => ['CR_SERVER_GONE_ERROR', 'CR_SERVER_LOST', 'driverCode'],
            'Common/Database/RetentionPrune.php' => ['driverCode', 'driverCode', 'isDeadlock'],
            'Media/Library/ItemRepository.php' => [
                'DUPLICATE_ENTRY_PHRASE',
                'SQLSTATE_INTEGRITY',
                'driverCode',
                'isDuplicateKey',
            ],
            'Media/Library/ScanJobRepository.php' => ['ER_LOCK_WAIT_TIMEOUT', 'driverCode', 'driverCode', 'isDeadlock'],
        ],
        self::F_DB_WRITE_RESULT => [
            'Admin/NewsletterSender.php' => ['wroteNothing'],
            'Auth/DbLoginRateLimitStore.php' => ['wroteNothing'],
            'Auth/QuickConnectStateStore.php' => ['wroteNothing', 'wroteNothing', 'wroteNothing'],
            'Auth/TokenSessionRepository.php' => ['wroteNothing'],
            'Auth/WebAuthn/WebAuthnChallengeStore.php' => ['wroteNothing'],
            'Casting/CastingSessionStore.php' => ['wroteNothing'],
            'Media/Library/ScanJobRepository.php' => ['wroteNothing', 'wroteNothing'],
            'Media/Music/MusicLibraryScanner.php' => ['wroteNothing'],
            'Plugins/OAuth2/DbOAuth2StateStore.php' => ['wroteNothing', 'wroteNothing'],
            'Plugins/Oidc/DbOidcStateStore.php' => ['wroteNothing', 'wroteNothing'],
            'Server/Integrations/Lastfm/DbLastfmOAuthStateStore.php' => ['wroteNothing', 'wroteNothing'],
            'Server/Integrations/Trakt/DbTraktOAuthStateStore.php' => ['wroteNothing', 'wroteNothing'],
            'Stats/ClientHeartbeatStore.php' => ['wroteNothing'],
        ],
        self::F_DB_UUID => [
            'Common/Uuid.php' => ['isV4', 'v4'],
        ],
        self::F_SETTINGS_CODEC => [
            'Admin/SettingsRepository.php' => ['decode'],
        ],
    ];

    /**
     * Whole-src grammar laws (token-classified, quote-agnostic).
     */
    private const LAW_B64_PAIR = 'base64url_alphabet_pair';
    private const LAW_SHA_PREFIX = 'legacy_sha256_equals_prefix';
    private const LAW_PEM_LABEL = 'pem_label_literal';
    private const LAW_SHA_DIGEST = 'inline_sha256_digest';
    private const LAW_PACK_HEADER = 'inline_pack_header';
    private const LAW_VERB_PAIR = 'inline_verb_array';
    private const LAW_JSON_LAST_ERROR = 'inline_json_last_error_call';

    /**
     * The rent-paying exceptions to the whole-src base64url alphabet-pair
     * law. QuickConnectStateStore still hand-mints its 32-byte verifier: the
     * adoption to Base64Url::encode(random_bytes(32)) is a behavior-zero
     * one-liner named as a follow-up row in the SRV-CENSUS2 register — until
     * it lands, this entry proves the law has teeth there and never silently
     * widens. WebAuthnManager mints the pair in its RATIFIED legacy strict
     * decode (ee9f1c4c restore; permanent-v0.56 law, see its rationale). The
     * rent test reddens in BOTH directions: an entry whose mint disappeared,
     * and a mint that lacks its entry.
     *
     * @var array<string, string>
     */
    private const RENT_B64_PAIR_MINTS = [
        'Auth/QuickConnectStateStore.php' =>
            'unadopted base64url verifier mint (32B) — adoption onto Base64Url::encode() '
            . 'is queued (SRV-CENSUS2 report); on landing, delete this entry (rent test enforces)',
        'Auth/WebAuthn/WebAuthnManager.php' =>
            'ratified legacy strict base64url decode (strtr+str_pad) RESTORED at ee9f1c4c '
            . '(S1 r1 CRITICAL-1: the shared strict arm moves the malformed-shape policy BOTH '
            . 'directions — 6,646 truncated-padding + 2,264 embedded-whitespace vectors; pinned '
            . 'by tests/Unit/Security/WebAuthnLegacyDecodeParityTest.php; modernization banked '
            . 'v0.57) — the decode arm mints the alphabet pair BY LAW, the encode arm still '
            . 'delegates; this entry is permanent unless the v0.57 strictness ruling lands',
    ];

    /**
     * Files whose migrated families make an inline sha256 digest a law
     * breach: the four CredentialDigest carriers plus both Pkce carriers
     * (shared Pkce::challengeFor owns the raw-digest grammar now).
     *
     * @var list<string>
     */
    private const SHA_DIGEST_SCOPED_FILES = [
        'Auth/UserRepository.php',
        'Plugins/OAuth2/StateCorrelation.php',
        'Session/SyncPlay/GroupState.php',
        'Session/SyncPlay/Messages.php',
        'Plugins/OAuth2/Pkce.php',
        'Server/Integrations/Trakt/TraktApi.php',
    ];

    /**
     * @var list<string> Files that must never re-grow their own relay frame
     *                 header (pack('N').pack('C').pack('n') is FrameCodec's
     *                 body; the framer's own DECODE legs legally unpack(),
     *                 and the token-equality classifier distinguishes them).
     */
    private const PACK_SCOPED_FILES = [
        'Hub/RelayMessageFramer.php',
    ];

    /**
     * @var list<string> Files that must never re-inline the state-changing
     *                 verb array (OriginPolicy::stateChangingMethod owns it).
     */
    private const VERB_SCOPED_FILES = [
        'Auth/CookiePolicy.php',
        'Server/Http/RequestAuthenticator.php',
    ];

    /**
     * @var list<string> DECODE-ADOPT carriers (7506f306): the malformed-input
     *                 dance is Phlix\Shared\Support\Json::decodeInbound's body;
     *                 a raw json_last_error() call growing back in these five
     *                 files is a re-inline (docblock prose is invisible to the
     *                 token classifier — SmartPlaylistController:319 proves it).
     */
    private const JSON_LAST_ERROR_SCOPED_FILES = [
        'Hub/RelayConsumer.php',
        'Playlists/SmartPlaylistController.php',
        'Server/Http/Controllers/MediaMarkerController.php',
        'Server/Http/Request.php',
        'Server/WebSocket/MessageHandler.php',
    ];

    /**
     * Alias-const shape laws: [file, name pattern, expected alias head,
     * exact compliant count]. The head must be the family's imported alias
     * followed by `::`; a T_CONSTANT value (or any other shape) is a
     * re-inline of the shared implementation into the constant.
     *
     * @var list<array{0:string,1:string,2:string,3:int}>
     */
    private const ALIAS_CONST_LAWS = [
        ['Session/SyncPlay/Messages.php', '/^TYPE_/', 'SyncPlayDialect', 19],
        ['Auth/JwtHandler.php', '/^CLAIM_(SESSION_ID|PROFILE_ID)$/', 'SessionFamily', 2],
        ['Auth/CookiePolicy.php', '/^MODE_(COOKIE|BEARER)$/', 'OriginPolicy', 2],
        ['Auth/PasswordHasher.php', '/^ARGON2ID_OPTIONS$/', 'SharedHasher', 1],
        ['Auth/UserRepository.php', '/^TIMING_BURN_DUMMY_HASH$/', 'SharedHasher', 1],
    ];

    /** Memoized walk (the walk tokenizes 2100+ files; sibling-idiom cache). */
    private static ?array $censusCache = null;

    private static function isNoise(array|string $token): bool
    {
        return \is_array($token)
            && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function nextIndex(array $tokens, int $from): ?int
    {
        $count = \count($tokens);
        for ($i = $from; $i < $count; $i++) {
            if (!self::isNoise($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /** Strip the surrounding quotes off a T_CONSTANT_ENCAPSED_STRING text. */
    private static function unquote(string $literal): string
    {
        return \substr($literal, 1, -1);
    }

    /**
     * Local alias => shared FQCN, from this file's `use` imports of family
     * classes only (covers `as` renames: SharedPkce, SharedHasher).
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array<string, string>
     */
    private static function familyAliasMap(array $tokens): array
    {
        $map = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== T_USE) {
                continue;
            }
            $j = self::nextIndex($tokens, $i + 1);
            if ($j === null) {
                break;
            }
            $name = $tokens[$j];
            if (
                !\is_array($name)
                || !\in_array($name[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)
            ) {
                continue; // use-function, use-const, closure use(...) — none import a family class
            }
            $fq = \ltrim($name[1], '\\');
            if (!isset(self::FAMILY_FORWARD_SITES[$fq])) {
                continue;
            }
            $alias = \substr($fq, \strrpos($fq, '\\') + 1);
            $k = self::nextIndex($tokens, $j + 1);
            if ($k !== null && \is_array($tokens[$k]) && $tokens[$k][0] === T_AS) {
                $m = self::nextIndex($tokens, $k + 1);
                if ($m !== null && \is_array($tokens[$m]) && $tokens[$m][0] === T_STRING) {
                    $alias = $tokens[$m][1];
                }
            }
            $map[$alias] = $fq;
        }

        return $map;
    }

    /**
     * Shape (S) + (A): static forwards and alias-const references — every
     * `Family::member` read through the file's import aliases, or written
     * as a leading-FQCN `\Phlix\Shared\...::member`.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @param array<string, string> $aliasMap
     * @return list<array{0:string,1:string}> family FQCN, member
     */
    private static function staticForwardMembers(array $tokens, array $aliasMap): array
    {
        $found = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }
            $family = null;
            if ($token[0] === T_STRING && isset($aliasMap[$token[1]])) {
                $family = $aliasMap[$token[1]];
            } elseif (
                $token[0] === T_NAME_FULLY_QUALIFIED
                && isset(self::FAMILY_FORWARD_SITES[\ltrim($token[1], '\\')])
            ) {
                $family = \ltrim($token[1], '\\');
            }
            if ($family === null) {
                continue;
            }
            $j = self::nextIndex($tokens, $i + 1);
            if ($j === null || !\is_array($tokens[$j]) || $tokens[$j][0] !== T_DOUBLE_COLON) {
                continue; // a type hint, a new, a bare word — not a forward
            }
            $k = self::nextIndex($tokens, $j + 1);
            // PHP 8 lexes the MEMBER of a static forward as T_MATCH when the
            // method is named `match` (reserved word — e.g. CredentialDigest::
            // match() at GroupState.php:355), while the same name after `->`
            // stays T_STRING. Accept both so keyword-named shared members are
            // counted; T_CLASS is deliberately NOT accepted — `Family::class`
            // is a meta-reference, not a delegation.
            if (
                $k !== null
                && \is_array($tokens[$k])
                && \in_array($tokens[$k][0], [T_STRING, T_MATCH], true)
            ) {
                $found[] = [$family, $tokens[$k][1]];
            }
        }

        return $found;
    }

    /**
     * Shape (O): shared-typed collaborator forwards — a property, promoted
     * or plain parameter, or local variable declared with a family type,
     * then called through `->` (directly or via `$this->prop->`).
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @param array<string, string> $aliasMap
     * @return list<array{0:string,1:string}> family FQCN, member
     */
    private static function collaboratorMembers(array $tokens, array $aliasMap): array
    {
        $familyByVar = [];
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }
            $family = null;
            if ($token[0] === T_STRING && isset($aliasMap[$token[1]])) {
                $family = $aliasMap[$token[1]];
            } elseif (
                $token[0] === T_NAME_FULLY_QUALIFIED
                && isset(self::FAMILY_FORWARD_SITES[\ltrim($token[1], '\\')])
            ) {
                $family = \ltrim($token[1], '\\');
            }
            if ($family === null) {
                continue;
            }
            $j = self::nextIndex($tokens, $i + 1);
            if ($j !== null && $tokens[$j] === '?') {
                $j = self::nextIndex($tokens, $j + 1); // ?Family $x (the ? is a plain string token)
            }
            if ($j !== null && \is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                $familyByVar[\ltrim($tokens[$j][1], '$')] = $family;
            }
        }

        $found = [];
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== T_VARIABLE) {
                continue;
            }
            $name = \ltrim($token[1], '$');
            if ($name === 'this') {
                $j = self::nextIndex($tokens, $i + 1);
                if ($j === null || !\is_array($tokens[$j]) || $tokens[$j][0] !== T_OBJECT_OPERATOR) {
                    continue;
                }
                $k = self::nextIndex($tokens, $j + 1);
                if ($k === null || !\is_array($tokens[$k]) || $tokens[$k][0] !== T_STRING) {
                    continue;
                }
                if (!isset($familyByVar[$tokens[$k][1]])) {
                    continue; // ->someOtherProp is not a family collaborator
                }
                $family = $familyByVar[$tokens[$k][1]];
                $m = self::nextIndex($tokens, $k + 1);
                if (
                    $m === null
                    || !\is_array($tokens[$m])
                    || !\in_array($tokens[$m][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                ) {
                    continue; // $this->prop with no method hop
                }
                $n = self::nextIndex($tokens, $m + 1);
                if ($n === null || !\is_array($tokens[$n]) || $tokens[$n][0] !== T_STRING) {
                    continue;
                }
                $found[] = [$family, $tokens[$n][1]];
                continue;
            }
            if (!isset($familyByVar[$name])) {
                continue;
            }
            $j = self::nextIndex($tokens, $i + 1);
            if (
                $j === null
                || !\is_array($tokens[$j])
                || !\in_array($tokens[$j][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            ) {
                continue;
            }
            $k = self::nextIndex($tokens, $j + 1);
            if ($k === null || !\is_array($tokens[$k]) || $tokens[$k][0] !== T_STRING) {
                continue;
            }
            $found[] = [$familyByVar[$name], $tokens[$k][1]];
        }

        return $found;
    }

    /**
     * THE shared grammar classifier — both the disk census and the canaries
     * run through this one function, so a law cannot be wider in the canary
     * than on disk (the SRV-MICRO r3 M4 lesson, kept structural).
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<int> line numbers of every intrusion the law sees
     */
    private static function findGrammarIntrusions(array $tokens, string $law): array
    {
        $lines = [];
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $value = self::unquote($token[1]);
                $trip = false;
                if ($law === self::LAW_B64_PAIR) {
                    $trip = $value === '+/' || $value === '-_';
                } elseif ($law === self::LAW_SHA_PREFIX) {
                    $trip = $value === 'sha256=';
                } elseif ($law === self::LAW_PEM_LABEL) {
                    $trip = \str_starts_with($value, '-----BEGIN ') || \str_starts_with($value, '-----END ');
                } elseif ($law === self::LAW_VERB_PAIR && $value === 'POST') {
                    $j = self::nextIndex($tokens, $i + 1);
                    $k = $j === null ? null : self::nextIndex($tokens, $j + 1);
                    $trip = $j !== null
                        && $tokens[$j] === ','
                        && $k !== null
                        && \is_array($tokens[$k])
                        && $tokens[$k][0] === T_CONSTANT_ENCAPSED_STRING
                        && self::unquote($tokens[$k][1]) === 'PUT';
                }
                if ($trip) {
                    $lines[] = $token[2];
                }
                continue;
            }

            if ($token[0] !== T_STRING) {
                continue;
            }

            if ($law === self::LAW_PACK_HEADER && $token[1] === 'pack') {
                // Token-text equality: 'unpack' is a different text and the
                // framer's legal decode legs (:158/:197/:210) never trip.
                $j = self::nextIndex($tokens, $i + 1);
                if ($j !== null && $tokens[$j] === '(') {
                    $lines[] = $token[2];
                }
            } elseif ($law === self::LAW_SHA_DIGEST && $token[1] === 'hash') {
                $j = self::nextIndex($tokens, $i + 1);
                $k = $j === null ? null : self::nextIndex($tokens, $j + 1);
                if (
                    $j !== null
                    && $tokens[$j] === '('
                    && $k !== null
                    && \is_array($tokens[$k])
                    && $tokens[$k][0] === T_CONSTANT_ENCAPSED_STRING
                    && self::unquote($tokens[$k][1]) === 'sha256'
                ) {
                    $lines[] = $token[2];
                }
            } elseif ($law === self::LAW_JSON_LAST_ERROR && $token[1] === 'json_last_error') {
                // Token-text equality: json_last_error_msg is different text, and
                // JSON_ERROR_* are T_STRING constants this branch never looks at.
                $j = self::nextIndex($tokens, $i + 1);
                if ($j !== null && $tokens[$j] === '(') {
                    $lines[] = $token[2];
                }
            }
        }

        return $lines;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:list<int>,1:int} violation lines, compliant count
     */
    private static function aliasConstCompliance(
        array $tokens,
        string $namePattern,
        string $expectedAlias
    ): array {
        $violations = [];
        $compliant = 0;
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== T_CONST) {
                continue;
            }
            $j = self::nextIndex($tokens, $i + 1);
            if ($j === null || !\is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                continue;
            }
            if (\preg_match($namePattern, $tokens[$j][1]) !== 1) {
                continue;
            }
            $k = self::nextIndex($tokens, $j + 1);
            $bad = $k === null || $tokens[$k] !== '=';
            if (!$bad) {
                $m = self::nextIndex($tokens, $k + 1);
                if ($m === null || !\is_array($tokens[$m]) || $tokens[$m][0] !== T_STRING) {
                    $bad = true; // quoted literal, interpolation, concat — all re-inlines
                } elseif ($tokens[$m][1] !== $expectedAlias) {
                    $bad = true; // aliasing a different family than the law registered
                } else {
                    $n = self::nextIndex($tokens, $m + 1);
                    $bad = $n === null || !\is_array($tokens[$n]) || $tokens[$n][0] !== T_DOUBLE_COLON;
                }
            }
            if ($bad) {
                $violations[] = $token[2];
            } else {
                $compliant++;
            }
        }

        return [$violations, $compliant];
    }

    /**
     * @return list<array{0:int,1:string,2:int}|string>
     */
    private function tokensOf(string $relative): array
    {
        $path = \dirname(__DIR__, 3) . '/src/' . $relative;
        $this->assertFileExists($path, 'The census-scoped file must exist: ' . $relative);

        /** @var list<array{0:int,1:string,2:int}|string> $tokens */
        $tokens = token_get_all((string) file_get_contents($path));

        return $tokens;
    }

    /**
     * One walk of src/: per-family exact static-forward counts and carrier
     * member multisets, collaborator-shape sites, and whole-src grammar
     * findings. Memoized (the walk tokenizes 2100+ files once for the class).
     *
     * @return array{
     *     counts: array<string, int>,
     *     members: array<string, array<string, list<string>>>,
     *     collaborators: array<string, array<string, list<string>>>,
     *     wholeSrc: array<string, array<string, list<int>>>,
     *     imports: array<string, list<string>>
     * }
     */
    private function censusData(): array
    {
        if (self::$censusCache !== null) {
            /** @var array{counts: array<string, int>, members: array<string, array<string, list<string>>>, collaborators: array<string, array<string, list<string>>>, wholeSrc: array<string, array<string, list<int>>>, imports: array<string, list<string>>} */
            return self::$censusCache;
        }

        $srcRoot = \dirname(__DIR__, 3) . '/src';
        $this->assertDirectoryExists($srcRoot, 'The census must scan the real production tree.');

        $counts = array_fill_keys(array_keys(self::FAMILY_FORWARD_SITES), 0);
        $members = array_fill_keys(array_keys(self::FAMILY_FORWARD_SITES), []);
        $collaborators = array_fill_keys(array_keys(self::FAMILY_FORWARD_SITES), []);
        $wholeSrc = [
            self::LAW_B64_PAIR => [],
            self::LAW_SHA_PREFIX => [],
            self::LAW_PEM_LABEL => [],
        ];
        $imports = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = \substr($file->getPathname(), \strlen($srcRoot) + 1);
            /** @var list<array{0:int,1:string,2:int}|string> $tokens */
            $tokens = token_get_all((string) file_get_contents($file->getPathname()));
            $aliasMap = self::familyAliasMap($tokens);
            foreach ($this->sharedImportNames($tokens, $relative) as $importedFqcn) {
                $imports[$importedFqcn][$relative] = true;
            }

            foreach (self::staticForwardMembers($tokens, $aliasMap) as [$family, $member]) {
                $counts[$family]++;
                $members[$family][$relative][] = $member;
            }
            foreach (self::collaboratorMembers($tokens, $aliasMap) as [$family, $member]) {
                $collaborators[$family][$relative][] = $member;
            }
            foreach (array_keys($wholeSrc) as $law) {
                $hits = self::findGrammarIntrusions($tokens, $law);
                if ($hits !== []) {
                    $wholeSrc[$law][$relative] = $hits;
                }
            }
        }

        ksort($counts);
        foreach ($members as $family => $byFile) {
            ksort($byFile);
            $members[$family] = $byFile;
        }
        foreach ($collaborators as $family => $byFile) {
            ksort($byFile);
            $collaborators[$family] = $byFile;
        }
        foreach ($wholeSrc as $law => $byFile) {
            ksort($byFile);
            $wholeSrc[$law] = $byFile;
        }
        foreach ($imports as $fq => $byFile) {
            ksort($byFile);
            $imports[$fq] = array_keys($byFile);
        }

        self::$censusCache = [
            'counts' => $counts,
            'members' => $members,
            'collaborators' => $collaborators,
            'wholeSrc' => $wholeSrc,
            'imports' => $imports,
        ];

        return self::$censusCache;
    }

    public function test_the_census_counts_every_landed_shared_wave_delegation(): void
    {
        $data = $this->censusData();

        // censusData() ksorts the walk (deterministic disk order); the pin is
        // written in declaration order — sort both so a value regression still
        // reddens while key ORDER alone never masquerades as one.
        $expected = self::FAMILY_FORWARD_SITES;
        \ksort($expected);

        $this->assertSame(
            $expected,
            $data['counts'],
            'SRV-CENSUS2: a shared-delegation family gained or lost forward sites. A loss '
            . 'means a body was RE-INLINED — the exact defect this census exists to redden. '
            . 'A gain is a new adoption — re-pin from this measured red after adjudicating '
            . 'the delta (S431 discipline). A dead walker returns all-zeros and still '
            . 'reddens here: an empty census never reads as "the law holds".'
        );
    }

    public function test_carrier_and_member_tables_match_the_disk_census(): void
    {
        $data = $this->censusData();

        foreach (self::FAMILY_CARRIER_MEMBERS as $family => $carriers) {
            $actual = $data['members'][$family];
            $expectedFiles = array_keys($carriers);
            sort($expectedFiles);
            $actualFiles = array_keys($actual);
            sort($actualFiles);
            $this->assertSame(
                $expectedFiles,
                $actualFiles,
                $family . ': the carrier set drifted. A file that stopped forwarding is a '
                . 're-inlined body; a file that started forwarding is an unregistered '
                . 'delegation — either way, register or restore, then rotate from measured red.'
            );
            foreach ($carriers as $relative => $expectedMembers) {
                $actualMembers = $actual[$relative] ?? [];
                sort($expectedMembers);
                sort($actualMembers);
                $this->assertSame(
                    $expectedMembers,
                    $actualMembers,
                    $family . ' @ ' . $relative . ': the forwarded member multiset drifted '
                    . '(a method reclaimed inline, or the seam moved to a different member).'
                );
            }
        }

        foreach ($data['collaborators'] as $family => $byFile) {
            $this->assertSame(
                [],
                $byFile,
                $family . ': a shared-typed collaborator forward appeared. The recognizer '
                . '(shape O) is proven by the canary leg; the static-forward pins do not '
                . 'count it, so adopting the collaborator form must re-baseline BOTH legs '
                . 'consciously from measured red — never let the census thin silently.'
            );
        }
    }

    public function test_whole_src_grammar_intrusion_laws_are_clear_and_exceptions_pay_rent(): void
    {
        $data = $this->censusData();

        $b64 = $data['wholeSrc'][self::LAW_B64_PAIR];
        $offenders = [];
        foreach ($b64 as $relative => $lines) {
            if (!isset(self::RENT_B64_PAIR_MINTS[$relative])) {
                $offenders[] = $relative . ':' . implode(',', $lines);
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'base64url law: an alphabet-pair mint (\'+/\' / \'-_\') appeared outside the '
            . 'rent list. That grammar is Phlix\Shared\Security\Base64Url\'s body verbatim — '
            . 'call Base64Url::encode() instead of re-growing the strtr copy.'
        );

        $stale = [];
        foreach (array_keys(self::RENT_B64_PAIR_MINTS) as $relative) {
            if (!isset($b64[$relative])) {
                $stale[] = $relative;
            }
        }
        $this->assertSame(
            [],
            $stale,
            'base64url rent rot: these files no longer hand-mint an alphabet pair, so the '
            . 'allowlist entry must be deleted in the same commit (never leave a hiding place).'
        );

        $this->assertSame(
            [],
            $data['wholeSrc'][self::LAW_SHA_PREFIX],
            'Hmac law: a literal \'sha256=\' prefix reappeared in first-party code — the '
            . 'legacy webhook signature header is Phlix\Shared\Security\Hmac::compute() with '
            . 'Hmac::SEPARATOR_EQUALS, not a hand-built concat (665c691a, design M-07).'
        );

        $this->assertSame(
            [],
            $data['wholeSrc'][self::LAW_PEM_LABEL],
            'Ed25519 law: a PEM label literal reappeared in first-party code — PEM framing '
            . 'is Phlix\Shared\Security\Ed25519Pem\'s body; keep it there.'
        );
    }

    public function test_carrier_scoped_grammar_intrusion_laws_are_clear(): void
    {
        $digest = [];
        foreach (self::SHA_DIGEST_SCOPED_FILES as $relative) {
            $hits = self::findGrammarIntrusions($this->tokensOf($relative), self::LAW_SHA_DIGEST);
            if ($hits !== []) {
                $digest[] = $relative . ':' . implode(',', $hits);
            }
        }
        $this->assertSame(
            [],
            $digest,
            'digest law: an inline hash(\'sha256\', ...) grew back inside a CredentialDigest/Pkce '
            . 'carrier — those families\' grammars live in the shared classes (4c0ae4f4/b93d73a2).'
        );

        $pack = [];
        foreach (self::PACK_SCOPED_FILES as $relative) {
            $hits = self::findGrammarIntrusions($this->tokensOf($relative), self::LAW_PACK_HEADER);
            if ($hits !== []) {
                $pack[] = $relative . ':' . implode(',', $hits);
            }
        }
        $this->assertSame(
            [],
            $pack,
            'framer law: pack( reappeared in RelayMessageFramer — the relay frame header is '
            . 'FrameCodec::encode\'s body (3b878403); the decode legs\' unpack() calls are legal '
            . 'and the token-equality classifier proves it every run.'
        );

        $verbs = [];
        foreach (self::VERB_SCOPED_FILES as $relative) {
            $hits = self::findGrammarIntrusions($this->tokensOf($relative), self::LAW_VERB_PAIR);
            if ($hits !== []) {
                $verbs[] = $relative . ':' . implode(',', $hits);
            }
        }
        $this->assertSame(
            [],
            $verbs,
            'verb law: an inline [\'POST\', \'PUT\', ...] array grew back in an origin-policy '
            . 'carrier — OriginPolicy::stateChangingMethod() owns that vocabulary (4c0ae4f4).'
        );

        $dances = [];
        foreach (self::JSON_LAST_ERROR_SCOPED_FILES as $relative) {
            $hits = self::findGrammarIntrusions($this->tokensOf($relative), self::LAW_JSON_LAST_ERROR);
            if ($hits !== []) {
                $dances[] = $relative . ':' . implode(',', $hits);
            }
        }
        $this->assertSame(
            [],
            $dances,
            'json law: a global json_last_error() call grew back in a shared-Json carrier — '
            . 'Json::decodeInbound() owns the failure discrimination (7506f306 retired the '
            . 'dance; docblock prose about it is token-invisible by design).'
        );
    }

    public function test_alias_constants_keep_their_delegate_shape(): void
    {
        foreach (self::ALIAS_CONST_LAWS as [$relative, $pattern, $alias, $floor]) {
            [$violations, $compliant] = self::aliasConstCompliance(
                $this->tokensOf($relative),
                $pattern,
                $alias
            );
            $this->assertSame(
                [],
                $violations,
                $relative . ': consts matching ' . $pattern . ' must be pure aliases of '
                . $alias . ':: — a quoted literal value re-inlines the shared implementation '
                . 'into the constant (the M4 class, this time on the const surface).'
            );
            $this->assertSame(
                $floor,
                $compliant,
                $relative . ': the ' . $pattern . ' alias-const population drifted from '
                . $floor . ' — rotate this floor and the family pin together, from measured red.'
            );
        }
    }

    public function test_canary_double_quoted_alphabet_pair_re_inline_trips_the_law(): void
    {
        $double = "<?php\n\$sig = rtrim(strtr(base64_encode(\$bytes), \"+/\", \"-_\"), \"=\");\n";
        $this->assertSame(
            [2, 2],
            self::findGrammarIntrusions(token_get_all($double), self::LAW_B64_PAIR),
            'A double-quoted alphabet-pair re-inline must trip the census exactly as hard as '
            . 'the historical single-quoted one (M4, reborn for the shared wave).'
        );

        $single = "<?php\n\$sig = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');\n";
        $this->assertSame(
            [2, 2],
            self::findGrammarIntrusions(token_get_all($single), self::LAW_B64_PAIR),
            'Both quote spellings must classify identically — the law reads T_CONSTANT token '
            . 'values, so census and canary cannot drift apart by quoting.'
        );
    }

    public function test_canary_each_intrusion_grammar_is_caught(): void
    {
        $cases = [
            'sha256-equals concat (double quoted)' => [
                self::LAW_SHA_PREFIX,
                "<?php\nreturn \"sha256=\" . \$h;\n",
            ],
            'sha256-equals concat (single quoted)' => [
                self::LAW_SHA_PREFIX,
                "<?php\nreturn 'sha256=' . \$h;\n",
            ],
            'PEM label mint (double quoted)' => [
                self::LAW_PEM_LABEL,
                "<?php\nreturn \"-----BEGIN PRIVATE KEY-----\\n\" . \$b64;\n",
            ],
            'inline sha256 digest (single quoted)' => [
                self::LAW_SHA_DIGEST,
                "<?php\nreturn hash('sha256', \$password);\n",
            ],
            'inline sha256 digest (double quoted, raw)' => [
                self::LAW_SHA_DIGEST,
                "<?php\nreturn hash(\"sha256\", \$verifier, true);\n",
            ],
            'inline pack header (both quotes)' => [
                self::LAW_PACK_HEADER,
                "<?php\nreturn pack(\"N\", \$seq) . pack('C', \$type->value);\n",
            ],
            'inline verb array (single quoted)' => [
                self::LAW_VERB_PAIR,
                "<?php\nif (in_array(\$m, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {}\n",
            ],
            'inline verb array (double quoted)' => [
                self::LAW_VERB_PAIR,
                "<?php\nif (in_array(\$m, [\"POST\", \"PUT\"], true)) {}\n",
            ],
            'inline json_last_error dance (single quoted call form)' => [
                self::LAW_JSON_LAST_ERROR,
                "<?php\nif (null === json_decode(\$body, true) && json_last_error() !== JSON_ERROR_NONE) {}\n",
            ],
        ];
        foreach ($cases as $label => [$law, $snippet]) {
            $this->assertNotEmpty(
                self::findGrammarIntrusions(token_get_all($snippet), $law),
                $label . ' must trip the shared classifier — this canary IS the teeth test.'
            );
        }

        $decode = "<?php\n\$len = unpack('nlen', substr(\$bytes, 5, 2));\n";
        $this->assertSame(
            [],
            self::findGrammarIntrusions(token_get_all($decode), self::LAW_PACK_HEADER),
            'The pack law must not false-red the framer\'s legal unpack decode legs '
            . '(token text equality separates pack from unpack).'
        );

        [$violations, $compliant] = self::aliasConstCompliance(
            token_get_all("<?php\nclass Synthetic\n{\n    public const TYPE_ERROR = 'syncplay_error';\n}\n"),
            '/^TYPE_/',
            'SyncPlayDialect'
        );
        $this->assertSame([4], $violations, 'A quoted dialect literal in a TYPE_* const is a re-inline.');
        $this->assertSame(0, $compliant);
    }

    public function test_canary_delegate_shapes_are_recognized_as_delegation(): void
    {
        // Shape (S) — static forward.
        $staticTokens = token_get_all(
            "<?php\nuse Phlix\Shared\Security\Base64Url;\nclass SyntheticS\n"
            . "{\n    public function e(string \$bytes): string\n"
            . "    {\n        return Base64Url::encode(\$bytes);\n    }\n}\n"
        );
        $this->assertSame(
            [[self::F_BASE64URL, 'encode']],
            self::staticForwardMembers($staticTokens, self::familyAliasMap($staticTokens)),
            'Shape S (static forward) must register as delegation.'
        );
        $this->assertSame(
            [],
            self::collaboratorMembers($staticTokens, self::familyAliasMap($staticTokens)),
            'Shape S must not double-register as a collaborator forward.'
        );

        // Shape (A) — alias const: a forward site AND shape-compliant.
        $aliasTokens = token_get_all(
            "<?php\nuse Phlix\Shared\Relay\SyncPlayDialect;\nclass SyntheticA\n"
            . "{\n    public const TYPE_INFO = SyncPlayDialect::TYPE_INFO;\n}\n"
        );
        $this->assertSame(
            [[self::F_SYNCPLAY_DIALECT, 'TYPE_INFO']],
            self::staticForwardMembers($aliasTokens, self::familyAliasMap($aliasTokens)),
            'Shape A (alias const) must count in the family forward census.'
        );
        [$violations, $compliant] = self::aliasConstCompliance($aliasTokens, '/^TYPE_/', 'SyncPlayDialect');
        $this->assertSame([], $violations);
        $this->assertSame(1, $compliant);

        // Shape (O) — shared-typed collaborator (zero live carriers today;
        // a future adoption of this shape is delegation, not a breach).
        $collabTokens = token_get_all(
            "<?php\nuse Phlix\Shared\Security\OriginPolicy;\nclass SyntheticO\n"
            . "{\n    private ?OriginPolicy \$policy = null;\n"
            . "    public function mode(string \$a): string\n"
            . "    {\n        return \$this->policy->custodyMode(\$a, '', '', '', '');\n    }\n"
            . "    public static function verb(OriginPolicy \$op): bool\n"
            . "    {\n        return \$op->stateChangingMethod('POST');\n    }\n}\n"
        );
        $this->assertSame(
            [[self::F_ORIGIN_POLICY, 'custodyMode'], [self::F_ORIGIN_POLICY, 'stateChangingMethod']],
            self::collaboratorMembers($collabTokens, self::familyAliasMap($collabTokens)),
            'Shape O (collaborator forwards through a nullable property and a plain param) '
            . 'must register as delegation.'
        );
        $this->assertSame(
            [],
            self::staticForwardMembers($collabTokens, self::familyAliasMap($collabTokens)),
            'A collaborator-only carrier must not be miscounted as a static forward.'
        );
    }

    /**
     * All `use Phlix\Shared\...` imported class names in one file, alias-
     * independent (keyed on the imported FQCN). Plain and comma-multi uses
     * only; a group use aborts the census LOUDLY rather than silently
     * missing an import — the completeness law is worthless with holes.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<string>
     */
    private function sharedImportNames(array $tokens, string $relative): array
    {
        $names = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== T_USE) {
                continue;
            }
            $j = self::nextIndex($tokens, $i + 1);
            if ($j === null) {
                continue;
            }
            if (\is_array($tokens[$j]) && \in_array($tokens[$j][0], [T_FUNCTION, T_CONST], true)) {
                continue; // `use function`/`use const` carry no class name
            }
            if ($tokens[$j] === '(') {
                continue; // closure use-binding, not an import
            }
            for ($k = $j; $k < $count; $k++) {
                $u = $tokens[$k];
                if (\is_string($u)) {
                    if ($u === ';') {
                        break;
                    }
                    if ($u === '{') {
                        $this->fail(
                            'Group use detected in ' . $relative . ' — extend the census import'
                            . ' walker consciously; a silently missed import breaks the'
                            . ' completeness law.'
                        );
                    }
                    continue;
                }
                if (\in_array($u[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $name = ltrim($u[1], '\\');
                    if (\str_starts_with($name, 'Phlix\\Shared\\')) {
                        $names[] = $name;
                    }
                }
            }
            $i = $k; // resume past the statement's ';'
        }

        return $names;
    }

    /**
     * Shared imports that are contracts/value types rather than delegable
     * behavior: DTOs, events, wire vocabularies, interfaces, schema paths.
     * The completeness leg admits exactly these, and each still pays rent —
     * the moment its last import disappears the entry must be deleted.
     *
     * @var array<string, string>
     */
    private const SHARED_TYPE_ONLY = [
        'Phlix\Shared\Arr\RadarrClient' => 'arr API client class (instantiated collaborator, not a primitive seam)',
        'Phlix\Shared\Arr\SyncResult' => 'result VO',
        'Phlix\Shared\Arr\Transport\ArrTransportInterface' => 'transport contract',
        'Phlix\Shared\Arr\TrashGuidesProvider' => 'data provider class',
        'Phlix\Shared\Auth\AuthResult' => 'auth result VO',
        'Phlix\Shared\Auth\JwtClaims' => 'claims VO',
        'Phlix\Shared\Auth\ProviderInterface' => 'auth provider contract',
        'Phlix\Shared\Auth\UserInfo' => 'user DTO',
        'Phlix\Shared\Events\Auth\UserCreated' => 'event VO',
        'Phlix\Shared\Events\Auth\UserLoggedIn' => 'event VO',
        'Phlix\Shared\Events\Auth\UserLoggedOut' => 'event VO',
        'Phlix\Shared\Events\Library\LibraryScanCompleted' => 'event VO',
        'Phlix\Shared\Events\Library\LibraryScanStarted' => 'event VO',
        'Phlix\Shared\Events\Library\MediaItemAdded' => 'event VO',
        'Phlix\Shared\Events\Playback\PlaybackPaused' => 'event VO',
        'Phlix\Shared\Events\Playback\PlaybackResumed' => 'event VO',
        'Phlix\Shared\Events\Playback\PlaybackStarted' => 'event VO',
        'Phlix\Shared\Events\Playback\PlaybackStopped' => 'event VO',
        'Phlix\Shared\Hub\ClaimRequest' => 'relay wire DTO',
        'Phlix\Shared\Hub\ClaimResponse' => 'relay wire DTO',
        'Phlix\Shared\Hub\HeartbeatDto' => 'relay wire DTO',
        'Phlix\Shared\Hub\LibraryRef' => 'relay wire DTO',
        'Phlix\Shared\Metadata\MetadataSourceInterface' => 'metadata provider contract',
        'Phlix\Shared\Plugin\ConfigurableInterface' => 'plugin contract',
        'Phlix\Shared\Plugin\EventNameMap' => 'plugin vocabulary VO',
        'Phlix\Shared\Plugin\LifecycleInterface' => 'plugin contract',
        'Phlix\Shared\Plugin\Manifest' => 'manifest VO',
        'Phlix\Shared\Plugin\ManifestValidationError' => 'typed exception',
        'Phlix\Shared\Relay\RelayFrame' => 'frame VO',
        'Phlix\Shared\Relay\RelayFrameType' => 'frame vocabulary enum (type names, not the encode grammar)',
        'Phlix\Shared\Relay\RelayHttpRequest' => 'tunnel wire DTO',
        'Phlix\Shared\Relay\RelayHttpRequestChunk' => 'tunnel wire DTO',
        'Phlix\Shared\Relay\RelayHttpRequestCodec' =>
            'tunnel codec — instantiated collaborator; candidate family for a future delegation wave',
        'Phlix\Shared\Relay\RelayHttpRequestHead' => 'tunnel wire DTO',
        'Phlix\Shared\Relay\RelayHttpResponseCodec' => 'tunnel codec — same future-wave note',
        'Phlix\Shared\Relay\RelayHttpResponseHead' => 'tunnel wire DTO',
        'Phlix\Shared\Relay\RelayWireCodecInterface' => 'codec contract',
        'Phlix\Shared\Schema\SchemaPaths' => 'config schema path constants',
        'Phlix\Shared\Subtitle\Exception\QuotaExceeded' => 'typed exception',
        'Phlix\Shared\Subtitle\SubtitleCandidate' => 'subtitle VO',
        'Phlix\Shared\Subtitle\SubtitleFile' => 'subtitle VO',
        'Phlix\Shared\Subtitle\SubtitleSourceInterface' => 'subtitle provider contract',
    ];

    public function test_every_shared_import_is_a_registered_family_or_a_declared_type_only_contract(): void
    {
        $data = $this->censusData();
        $familySet = array_keys(self::FAMILY_FORWARD_SITES);

        $unknown = [];
        foreach ($data['imports'] as $fq => $files) {
            if (!\in_array($fq, $familySet, true) && !isset(self::SHARED_TYPE_ONLY[$fq])) {
                $unknown[] = $fq . ' (imported by ' . implode(', ', $files) . ')';
            }
        }
        sort($unknown);
        $this->assertSame(
            [],
            $unknown,
            'A Phlix\Shared class was imported that the census has never heard of. If it '
            . 'carries implementation, register the family (FAMILY_FORWARD_SITES + carrier '
            . 'table + grammar law + canary); if it is a contract/DTO/event vocabulary, add '
            . 'a one-line rationale to SHARED_TYPE_ONLY. This red is the net that caught '
            . 'Support\Json at DECODE-ADOPT — the next wave gets caught the same way.'
        );

        $stale = [];
        foreach (array_keys(self::SHARED_TYPE_ONLY) as $fq) {
            if (!isset($data['imports'][$fq])) {
                $stale[] = $fq;
            }
        }
        sort($stale);
        $this->assertSame(
            [],
            $stale,
            'SHARED_TYPE_ONLY rot: these classes are no longer imported anywhere in src/ — '
            . 'delete the entries (a type-only allowance can never become a hiding place).'
        );

        foreach ($familySet as $fq) {
            $imported = $data['imports'][$fq] ?? [];
            sort($imported);
            $seen = array_values(array_unique(array_merge(
                array_keys($data['members'][$fq] ?? []),
                array_keys($data['collaborators'][$fq] ?? [])
            )));
            sort($seen);
            $orphans = array_values(array_diff($imported, $seen));
            $this->assertSame(
                [],
                $orphans,
                $fq . ': imported by these src files, but the census sees no static-forward '
                . 'and no collaborator site there — either the walker missed a NEW delegation '
                . 'shape (grow the recognizer consciously, the way T_MATCH acceptance caught '
                . 'CredentialDigest::match) or the import is dead. The census must never '
                . 'thin silently (' . implode(', ', $orphans) . ').'
            );
        }
    }
}
