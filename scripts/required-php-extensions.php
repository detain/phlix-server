<?php

/**
 * S314 — THE derived PHP-extension contract for phlix-server.
 *
 * This file is the SINGLE source of truth for "which PHP extensions does
 * phlix-server's own source actually need". Three consumers read it, and a test
 * pins all of them together so they cannot drift apart:
 *
 *   1. `composer.json`'s `require` block — one `"ext-<name>": "*"` per entry.
 *   2. `composer.json`'s `config.platform` block — the same set, pinned so that
 *      dependency RESOLUTION is identical on the dev box (PHP 8.3.6, 62 files in
 *      conf.d), on CI (PHP 8.3) and on production (PHP 8.5).
 *   3. {@see \Phlix\Tests\Unit\Platform\RequiredPhpExtensionsContractTest},
 *      which asserts (a) the two composer.json blocks match this list exactly,
 *      and (b) every entry's named symbol is STILL present in the file this
 *      list cites. An entry that stops being justified goes RED and has to be
 *      removed deliberately.
 *
 * [[S304]] consumes this list rather than deriving a second one. Two
 * independently-derived lists drift; there is exactly one here.
 *
 * ## How this list was derived — from CALL SITES, never from the environment
 *
 * A list read off `get_loaded_extensions()` is a check derived from its own
 * subject: it self-adjusts to whatever box computes it and can never fail. So
 * the derivation tokenised all 742 PHP files in `src/` (998,825 tokens,
 * 14,452 global function-call sites), attributed each call site to an extension
 * via `ReflectionExtension` used ONLY as a symbol->extension dictionary, and
 * then printed the UNATTRIBUTED bucket as a control: every called global name
 * that is neither user-defined in the corpus, nor declared by a vendor package,
 * nor owned by a loaded extension. That bucket came back containing only
 * `use X as Y` aliases and `self` — i.e. nothing that could be hiding a call
 * into an extension the deriving box happened not to have loaded.
 *
 * An extension earns an entry here only when at least one call site is
 * UNGUARDED — that is, when the absence of the extension is a fatal `Error`
 * rather than a degraded path.
 *
 * ## Deliberately NOT required (each has a guard, cited)
 *
 *   - `swoole`   — `public/index.php:26` `extension_loaded('swoole')`; the whole
 *                  coroutine runtime degrades gracefully. Named in the phpunit /
 *                  psalm / e2e workflows instead, where it IS needed.
 *   - `ffi`      — `src/Media/Markers/Fingerprinting/ChromaPrintFfi.php:89`
 *                  `extension_loaded('FFI')`, and the caller falls back.
 *   - `intl`     — `src/Media/Metadata/SeriesCandidateSelector.php:142`
 *                  `class_exists(Normalizer::class)`, returns the input unchanged.
 *   - `phar`     — `src/Plugins/Installer/HttpInstaller.php:572`
 *                  `class_exists(\PharData::class)`, `.tar.gz` plugins refuse.
 *   - `pcre`, `date`, `reflection`, `spl`, `session`, `tokenizer` — no direct
 *                  call sites of our own, and PHP cannot be built without the
 *                  first four.
 *
 * ## Two entries that `vendor/composer/platform_check.php` cannot enforce
 *
 * `symfony/polyfill-ctype` and `symfony/polyfill-mbstring` declare
 * `provide: {ext-ctype, ext-mbstring}`, so Composer's autoload-time platform
 * check deliberately omits those two (the polyfill really does supply the
 * functions). They are still declared here and in `require`, because the
 * polyfill is a fallback, not the intent.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 *
 * @return array<string, array{symbol: string, source: string, why: string}>
 *         Keyed by extension name (the `ext-` prefix is NOT included).
 */

declare(strict_types=1);

return [
    'ctype' => [
        'symbol' => 'ctype_digit(',
        'source' => 'src/Auth/SignedUrl.php',
        'why' => 'Validating the expiry field of a signed media URL before it is compared. A '
            . 'signed-URL gate that cannot check its own numeric fields is not a gate.',
    ],
    'curl' => [
        'symbol' => 'curl_init(',
        'source' => 'src/Hub/HttpClient.php',
        'why' => 'Every outbound HTTP call the server makes that is not on the Workerman event '
            . 'loop: hub enrollment/heartbeat, S3 backups, artwork fetches, the IMDb dataset '
            . 'import, Trakt and the plugin catalog. 165 unguarded call sites across 9 files.',
    ],
    'dom' => [
        'symbol' => 'new \\DOMDocument(',
        'source' => 'src/LiveTv/Tuners/Iptv/XmlTvParser.php',
        'why' => 'Parsing XMLTV guide data and building the DASH manifest tree.',
    ],
    'exif' => [
        'symbol' => 'exif_read_data(',
        'source' => 'src/Media/Library/PhotoScanner.php',
        'why' => 'Reading capture date, orientation and dimensions off scanned photos, and XMP off '
            . 'PDFs in BookScanner. Unguarded: without the extension a photo library scan fatals '
            . 'with "Call to undefined function exif_read_data()".',
    ],
    'fileinfo' => [
        'symbol' => 'finfo_open(',
        'source' => 'src/Media/Storage/ArtworkStorage.php',
        'why' => 'Sniffing the real MIME type of uploaded/downloaded artwork instead of trusting '
            . 'the declared one — the check that stops a "poster" being served as HTML.',
    ],
    'filter' => [
        'symbol' => 'filter_var(',
        'source' => 'src/Common/Http/TrustedProxyResolver.php',
        'why' => 'FILTER_VALIDATE_IP on the resolved client address, FILTER_FLAG_NO_PRIV_RANGE / '
            . 'NO_RES_RANGE in the SSRF guard, and FILTER_VALIDATE_EMAIL at registration. Three '
            . 'security boundaries, 61 call sites.',
    ],
    'gd' => [
        'symbol' => 'imagecreatetruecolor(',
        'source' => 'src/Media/Storage/ArtworkStorage.php',
        'why' => 'Every poster/backdrop resize and re-encode. Unguarded.',
    ],
    'hash' => [
        'symbol' => 'hash_hmac(',
        'source' => 'src/Auth/JwtHandler.php',
        'why' => 'HS256 JWT signing, the S3 SigV4 signature, and the constant-time hash_equals() '
            . 'used by SignedUrl and ProfileAccessPolicy. Without hash_equals() the signature '
            . 'comparison becomes a timing oracle rather than merely failing.',
    ],
    'iconv' => [
        'symbol' => 'iconv(',
        'source' => 'src/Media/Library/ItemRepository.php',
        'why' => 'Scrubbing invalid UTF-8 out of scanner-derived strings before they are bound '
            . 'into a media write. A byte sequence that reaches MySQL unscrubbed is error 1366 '
            . 'and a half-written media_streams set.',
    ],
    'json' => [
        'symbol' => 'json_encode(',
        'source' => 'src/Auth/JwtHandler.php',
        'why' => 'JWT header/claims encoding, every API request and response body, and '
            . 'metadata_json hydration. 79 call sites across 40 files.',
    ],
    'ldap' => [
        'symbol' => 'ldap_escape(',
        'source' => 'src/Plugins/Ldap/LdapConnection.php',
        'why' => 'LDAP_ESCAPE_FILTER on user-supplied values before they enter a search filter — '
            . 'the LDAP-injection guard for the LDAP auth provider. Also required by '
            . 'directorytree/ldaprecord.',
    ],
    'libxml' => [
        'symbol' => 'libxml_use_internal_errors(',
        'source' => 'src/Dlna/CdsControlHandler.php',
        'why' => 'Suppressing and then reading libxml errors around DLNA SOAP and XMLTV parsing, '
            . 'and LIBXML_NONET on the SOAP argument parse (the XXE/network-fetch guard).',
    ],
    'mbstring' => [
        'symbol' => 'mb_substr(',
        'source' => 'src/Server/Http/Controllers/MediaMatchController.php',
        'why' => 'Character-wise clamping of attacker-influenced strings before they are persisted '
            . 'or logged. A byte-wise substr() here is both a MySQL 1366 and an unbounded-write '
            . 'risk, so this is a correctness requirement, not a cosmetic one.',
    ],
    'openssl' => [
        'symbol' => 'ssl://',
        'source' => 'src/Network/UpnpIgdClient.php',
        'why' => 'The https UPnP-IGD control call opens an `ssl://` stream; without ext-openssl '
            . 'PHP has no such transport and stream_socket_client() fails. Also a hard requirement '
            . 'of web-token/jwt-framework, web-auth/webauthn-lib and web-auth/cose-lib.',
    ],
    'pcntl' => [
        'symbol' => 'SIGUSR2',
        'source' => 'src/Server/Http/Controllers/Admin/AdminRestartController.php',
        'why' => 'ext-pcntl owns the SIG* constants, and SIGUSR2 is referenced UNGUARDED on the '
            . 'admin graceful-reload path (SIGTERM/SIGKILL likewise in LiveTv\\Recorder, inside a '
            . 'guard that only tests posix_kill). A missing pcntl is an undefined-constant Error, '
            . 'not a degraded restart.',
    ],
    'pdo' => [
        'symbol' => 'PDO::ATTR_EMULATE_PREPARES',
        'source' => 'src/Common/Database/PhlixMySQLConnection.php',
        'why' => 'The MySQL connection this whole application runs on is a PDO handle; '
            . 'PhlixMySQLConnection sets PDO attributes directly. Also required by workerman/mysql.',
    ],
    'pdo_mysql' => [
        'symbol' => 'Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY',
        'source' => 'src/Common/Database/PhlixMySQLConnection.php',
        'why' => 'The mysql PDO driver itself, plus its driver-specific buffered-query attribute — '
            . 'the setting that stops an unbuffered result surviving a Swoole coroutine yield.',
    ],
    'posix' => [
        'symbol' => 'posix_kill(',
        'source' => 'src/Server/Http/Controllers/Admin/AdminRestartController.php',
        'why' => 'sendSignal() calls posix_kill() UNGUARDED on the admin restart/reload endpoint. '
            . 'Other posix_kill() sites are guarded; this one, reachable over HTTP, is not.',
    ],
    'random' => [
        'symbol' => 'random_bytes(',
        'source' => 'src/Auth/JwtHandler.php',
        'why' => 'The CSPRNG behind JWT jti values, OAuth state tokens, WebAuthn challenges and '
            . 'every generated secret. 24 random_bytes() plus 2 random_int() call sites, none '
            . 'guarded.',
    ],
    'simplexml' => [
        'symbol' => 'simplexml_load_string(',
        'source' => 'src/Admin/S3Client.php',
        'why' => 'Parsing S3 list/error responses, DLNA device descriptions and SSDP payloads. '
            . '14 call sites, only 2 of which sit behind a function_exists() guard.',
    ],
    'sockets' => [
        'symbol' => 'socket_create(',
        'source' => 'src/Discovery/Mdns/MdnsSocket.php',
        'why' => 'mDNS/SSDP multicast discovery, HDHomeRun discovery, NAT-PMP and the port-forward '
            . 'probe are raw sockets. 187 call sites; ext-sockets is NOT enabled in a default PHP '
            . 'build, so this is the entry most likely to be genuinely absent.',
    ],
    'sodium' => [
        'symbol' => 'sodium_crypto_sign_verify_detached(',
        'source' => 'src/Hub/HubJwtValidator.php',
        'why' => 'Ed25519 verification of the hub enrollment JWT, Ed25519KeyManager\'s keypair '
            . 'generation, and the XSalsa20-Poly1305 secretbox that protects Trakt OAuth tokens at '
            . 'rest. Named NOWHERE before S314 despite guarding all three.',
    ],
    'zip' => [
        'symbol' => 'new \\ZipArchive(',
        'source' => 'src/Media/Library/BookScanner.php',
        'why' => 'Reading EPUB/CBZ containers. BookScanner instantiates ZipArchive unguarded (the '
            . 'plugin installer guards its own use, the scanner does not).',
    ],
    'zlib' => [
        'symbol' => 'gzopen(',
        'source' => 'src/Media/Metadata/Imdb/ImdbDatasetImporter.php',
        'why' => 'Streaming the gzipped IMDb TSV datasets, and gzencode() for HTTP response '
            . 'compression in the Workerman handler.',
    ],
];
