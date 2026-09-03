<?php

/**
 * S415-pin golden-vector dumper — captures what the production SyncPlay router
 * ACTUALLY emits into `tests/Fixtures/SyncPlay/syncplay-envelope-pin-vectors.json`.
 *
 * Server-side twin of the S404/S415 method the contracts repo applied to
 * `@phlix/contracts`: drive the REAL code path and dump the emission, never
 * imitate it. The venue is the one `SyncPlayEnvelopePinTest` runs — the
 * PRODUCTION router composed from `ContainerFactory::defaultProviders()` with
 * ONLY the MySQL `Connection` doubled (shared via
 * `tests/Support/SyncPlay/SyncPlayEnvelopePinHarness`), so the fixture is a
 * byte-for-byte record of what the pin asserts, dumpable and re-verifiable at
 * any time: the pin test compares every fresh run against this file (and
 * throws on drift), it does not merely trust it.
 *
 * The emitted `keyPaths` are the FULL ordered key paths of each decoded
 * response (dict keys, list indexes, in emission order). Deterministic rails —
 * listGroups, getGroup, leaveGroup and both error arms — additionally carry
 * their complete body; createGroup/joinGroup do not (the manager mints a
 * random group id and time() stamps), they are pinned by key paths + the
 * value assertions inside the test.
 *
 * CROSS-CHECK (hard requirement, no partial fixtures): --contracts points at
 * the phlix-contracts checkout at the S415 merge. The script extracts the
 * same abstract ordered-key-shape digest from
 * `test/fixtures/syncplay-envelope-vectors.json` there, compares it to the
 * live digest rail-by-rail, REFUSES to write on any disagreement (that is a
 * PARK condition for the lane, not a re-pin), and stamps the contracts file
 * sha + md5 + digest into this fixture's provenance.
 *
 * Usage:
 *   php scripts/dump-syncplay-envelope-pin-vectors.php \
 *       --contracts /path/to/phlix-contracts \
 *       > tests/Fixtures/SyncPlay/syncplay-envelope-pin-vectors.json
 *
 * @see \Phlix\Tests\Support\SyncPlay\SyncPlayEnvelopePinHarness venue (shared)
 * @see \Phlix\Tests\Unit\Server\Http\Controllers\SyncPlayEnvelopePinTest the pin
 */

declare(strict_types=1);

use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Tests\Support\SyncPlay\SyncPlayEnvelopePinHarness;
use PHPUnit\Framework\MockObject\Generator\Generator;
use Workerman\MySQL\Connection;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "FAIL: {$autoload} not found — run composer install in the server checkout first.\n");
    exit(1);
}
require_once $autoload;

$contractsPath = null;
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--contracts' && isset($argv[$i + 1])) {
        $contractsPath = rtrim($argv[$i + 1], '/');
        $i++;
    }
}
if ($contractsPath === null || !is_dir($contractsPath)) {
    fwrite(STDERR, "FAIL: --contracts <checkout> is REQUIRED — the cross-check is not optional.\n");
    exit(1);
}

$serverRoot = dirname(__DIR__);
$serverSha = trim((string) shell_exec('git -C ' . escapeshellarg($serverRoot) . ' rev-parse HEAD 2>/dev/null'));
if (preg_match('/^[0-9a-f]{40}$/', $serverSha) !== 1) {
    fwrite(STDERR, "FAIL: could not resolve the server HEAD sha.\n");
    exit(1);
}
$contractsSha = trim((string) shell_exec('git -C ' . escapeshellarg($contractsPath) . ' rev-parse HEAD 2>/dev/null'));
if (preg_match('/^[0-9a-f]{40}$/', $contractsSha) !== 1) {
    fwrite(STDERR, "FAIL: could not resolve the contracts HEAD sha at {$contractsPath}.\n");
    exit(1);
}

$contractsVectorsPath = $contractsPath . '/test/fixtures/syncplay-envelope-vectors.json';
if (!is_file($contractsVectorsPath)) {
    fwrite(STDERR, "FAIL: {$contractsVectorsPath} not found.\n");
    exit(1);
}
$contractsVectors = json_decode((string) file_get_contents($contractsVectorsPath), true, 512, JSON_THROW_ON_ERROR);

// --- Venue: identical construction to the pin test, generator doubles instead
// --- of TestCase helpers (the harness owns the SQL routing; nothing drifts).
$generator = new Generator();
/** @var Connection&PHPUnit\Framework\MockObject\MockObject $connection */
$connection = $generator->testDouble(Connection::class, true, [], [], '', false);
SyncPlayEnvelopePinHarness::configureConnection($connection);
/** @var ConnectionPool&PHPUnit\Framework\MockObject\Stub $poolStub */
$poolStub = $generator->testDouble(ConnectionPool::class, true, [], [], '', false);
$poolStub->method('getPooledConnection')->willReturn($connection);

$loggerDir = sys_get_temp_dir() . '/phlix_s415pin_dump_' . uniqid('', true);
mkdir($loggerDir, 0775, true);
$loggerConfigPath = $loggerDir . '/logger.php';
file_put_contents(
    $loggerConfigPath,
    "<?php\nreturn ['default' => 'file', 'handlers' => ['file' => ['type' => 'stream', 'path' => "
    . var_export($loggerDir . '/app.log', true) . ", 'level' => 'error']]];\n"
);

$container = SyncPlayEnvelopePinHarness::buildContainer($connection, [
    'logger_config_path' => $loggerConfigPath,
    'db_config_path' => null,
]);
SyncPlayEnvelopePinHarness::seedConnectionPool($connection);
$application = SyncPlayEnvelopePinHarness::buildApplication($poolStub, $container);

// --- Drive every rail once, same sequence the test's capture uses.
$fail = static function (string $msg): never {
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
};

$assert200 = static function (array $rail, string $name) use ($fail): void {
    if ($rail['status'] !== 200) {
        $fail("rail {$name} did not dispatch to a 200 — got {$rail['status']}: " . json_encode($rail['body']));
    }
};

[, $created] = SyncPlayEnvelopePinHarness::drive(
    $application,
    'POST',
    '/api/v1/syncplay/groups',
    ['name' => 'Movie Night', 'memberId' => 'pin_host', 'memberName' => 'Host One'],
    'pin_host'
);
$groupId = (string) ($created['group']['group_id'] ?? '');
if ($groupId === '') {
    $fail('createGroup rail returned no group_id — nothing downstream can run.');
}
$createRail = ['status' => 200, 'body' => $created];

[, $joined] = SyncPlayEnvelopePinHarness::drive(
    $application,
    'POST',
    '/api/v1/syncplay/groups/' . $groupId . '/join',
    ['memberId' => 'pin_guest', 'memberName' => 'Guest Two'],
    'pin_guest'
);
$joinRail = ['status' => 200, 'body' => $joined];

[$listStatus, $listBody] = SyncPlayEnvelopePinHarness::drive(
    $application,
    'GET',
    '/api/v1/syncplay/groups',
    [],
    'pin_user'
);
$listRail = ['status' => $listStatus, 'body' => $listBody];

[$getStatus, $getBody] = SyncPlayEnvelopePinHarness::drive(
    $application,
    'GET',
    '/api/v1/syncplay/groups/' . SyncPlayEnvelopePinHarness::SEEDED_GROUP_ID,
    [],
    'pin_user'
);
$getRail = ['status' => $getStatus, 'body' => $getBody];

// The getGroup RAIL mirrors the contracts vectors' empty-queue getGroup, so
// its serialized_state seed has no media and an empty queue. The POPULATED
// getState() witness (media + queue entry + playing) lives under
// SEEDED_FULL_GROUP_ID and is what the cross-check compares to the contracts
// vectors' `groupState.state` — populated against populated, empty against empty.
[, $fullBody] = SyncPlayEnvelopePinHarness::drive(
    $application,
    'GET',
    '/api/v1/syncplay/groups/' . SyncPlayEnvelopePinHarness::SEEDED_FULL_GROUP_ID,
    [],
    'pin_user'
);
$groupState = $fullBody['group'] ?? null;
if (!is_array($groupState)) {
    $fail('the populated getState() witness did not emit a group.');
}

[$leaveStatus, $leaveBody] = SyncPlayEnvelopePinHarness::drive(
    $application,
    'POST',
    '/api/v1/syncplay/groups/' . $groupId . '/leave',
    ['memberId' => 'pin_guest'],
    'pin_guest'
);
$leaveRail = ['status' => $leaveStatus, 'body' => $leaveBody];

[$err400Status, $err400Body] = SyncPlayEnvelopePinHarness::drive(
    $application,
    'POST',
    '/api/v1/syncplay/groups',
    ['name' => ''],
    'pin_user'
);
[$err404Status, $err404Body] = SyncPlayEnvelopePinHarness::drive(
    $application,
    'GET',
    '/api/v1/syncplay/groups/sp_does_not_exist',
    [],
    'pin_user'
);

$rails = [
    'listGroups' => $listRail,
    'createGroup' => $createRail,
    'getGroup' => $getRail,
    'joinGroup' => $joinRail,
    'leaveGroup' => $leaveRail,
    'createGroupError' => ['status' => $err400Status, 'body' => $err400Body],
    'getGroupNotFound' => ['status' => $err404Status, 'body' => $err404Body],
];
foreach ($rails as $name => $rail) {
    if ($name === 'createGroupError' || $name === 'getGroupNotFound') {
        continue;
    }
    $assert200($rail, (string) $name);
}
// The two error arms must be exactly their pinned statuses.
if ($err400Status !== 400 || array_keys($err400Body) !== ['error']) {
    $fail('create @400 arm changed shape.');
}
if ($err404Status !== 404 || array_keys($err404Body) !== ['error']) {
    $fail('getGroup @404 arm changed shape.');
}
// Fail-fast on the authority shapes themselves (mirrors the pin's constants).
if (array_keys($createRail['body']) !== ['success', 'group']) {
    $fail('create envelope changed.');
}
if (array_keys($created['group']) !== SyncPlayEnvelopePinHarness::GROUP_STATE_KEYS) {
    $fail(
        'getState() key order changed — the S415 authority ruling must be re-measured '
        . 'BEFORE this fixture regenerates.'
    );
}
if (!is_array($created['group']['members']) || array_keys($created['group']['members']) !== ['pin_host']) {
    $fail('members is not a dict keyed by member id.');
}
$firstMember = $created['group']['members']['pin_host'] ?? [];
if (!is_array($firstMember) || array_keys($firstMember) !== SyncPlayEnvelopePinHarness::MEMBER_VALUE_KEYS) {
    $fail('member value shape changed.');
}
if ($getBody['group']['queue'] !== []) {
    $fail('the getGroup RAIL seed must carry the empty queue (mirrors the contracts getGroup rail).');
}

// --- Cross-check against the contracts golden vectors, shape by shape.
// Fail-fast on the POPULATED witness specifically: it is the one arm whose
// queue-item shape (media_id, media_info, added_at, added_by) the pin cares
// about — the empty-queue getGroup rail cannot cover it.
if (
    array_keys($groupState) !== SyncPlayEnvelopePinHarness::GROUP_STATE_KEYS
    || array_keys($groupState['queue'][0] ?? []) !== ['media_id', 'media_info', 'added_at', 'added_by']
    || $groupState['playback_state'] !== 'playing'
) {
    $fail('the populated getState() witness shape drifted from the pinned 12-key + queue-item spelling.');
}

$liveDigestRails = [];
foreach ($rails as $name => $rail) {
    $liveDigestRails[(string) $name] = SyncPlayEnvelopePinHarness::abstractKeyPaths($rail['body']);
}
$liveDigestRails['groupState'] = SyncPlayEnvelopePinHarness::abstractKeyPaths($groupState);

$contractsDigestRails = [];
foreach ($contractsVectors['rails'] as $name => $record) {
    $contractsDigestRails[(string) $name] = SyncPlayEnvelopePinHarness::abstractKeyPaths($record['response']);
}
$contractsDigestRails['groupState'] = SyncPlayEnvelopePinHarness::abstractKeyPaths(
    $contractsVectors['groupState']['state']
);

if (array_keys($liveDigestRails) !== array_keys($contractsDigestRails)) {
    $fail(
        'rail name-sets differ between this venue and the contracts vectors: '
        . json_encode([array_keys($liveDigestRails), array_keys($contractsDigestRails)])
    );
}
foreach ($liveDigestRails as $name => $paths) {
    if ($paths !== $contractsDigestRails[$name]) {
        $fail("ordered-key shape disagreement on rail '{$name}' — "
            . 'PARK the lane per S415 rules, do not re-pin either side: '
            . json_encode(['server' => $paths, 'contracts' => $contractsDigestRails[$name]]));
    }
}

$fixture = [
    '$comment' => 'GENERATED by scripts/dump-syncplay-envelope-pin-vectors.php through the PRODUCTION router '
        . '(ContainerFactory::defaultProviders() + Application::dispatch; MySQL Connection doubled, no database) '
        . 'from the REAL SyncPlayController/SyncPlayManager/SyncPlaySnapshotService/GroupState code '
        . '— do not hand-edit. '
        . 'Every "body" and "keyPaths" value below is emitted bytes decoded from a real Response; only the seeded '
        . 'snapshot INPUT (harness listRows()/serializedState()) is constructed, and its clock fields frozen for '
        . 'byte-stable re-dumps. Re-dump only after re-verifying the S415 authority ruling at the stamped sha; the '
        . 'pin test SyncPlayEnvelopePinTest re-drives the venue on every CI run and reddens on drift.',
    'provenance' => [
        'marker' => 'syncplay-pin-v1',
        'serverRepo' => 'detain/phlix-server',
        'dumpedAtSha' => $serverSha,
        'authoritySha' => SyncPlayEnvelopePinHarness::AUTHORITY_SHA,
        'generator' => 'scripts/dump-syncplay-envelope-pin-vectors.php',
        'venue' => 'production router: ContainerFactory::defaultProviders() + Application::dispatch, '
            . 'MySQL Connection doubled, no DB',
        'authority' => 'src/Server/Http/Controllers/SyncPlayController.php + src/Session/SyncPlay/SyncPlayManager.php '
            . '+ src/Session/SyncPlay/SyncPlaySnapshotService.php + src/Session/SyncPlay/GroupState.php',
        'contractsCrossCheck' => [
            'repo' => 'detain/phlix-contracts',
            'file' => 'test/fixtures/syncplay-envelope-vectors.json',
            'sha' => $contractsSha,
            'fileMd5' => md5_file($contractsVectorsPath) ?: '',
            'digestAlgorithm' => 'md5(json_encode(ksort({rail: abstractKeyPaths(body)}))) '
                . '— abstractKeyPaths collapses list indexes to [*] and members-dict keys to *',
            'orderedKeySetDigest' => SyncPlayEnvelopePinHarness::keyPathsDigest($liveDigestRails),
        ],
    ],
    'rails' => [],
    'groupState' => $groupState,
];

$deterministic = ['listGroups', 'getGroup', 'leaveGroup', 'createGroupError', 'getGroupNotFound'];
foreach ($rails as $name => $rail) {
    $record = ['status' => $rail['status'], 'keyPaths' => SyncPlayEnvelopePinHarness::orderedKeyPaths($rail['body'])];
    if (in_array($name, $deterministic, true)) {
        $record['body'] = $rail['body'];
    }
    $fixture['rails'][(string) $name] = $record;
}

// The seeded list/get bodies must equal what the harness declares deterministic —
// guards the dumper itself against a silently changed mapping (mirrors test 2/4).
if ($listBody !== ['groups' => SyncPlayEnvelopePinHarness::expectedListRows()]) {
    $fail('listGroups mapping disagrees with harness expectedListRows().');
}

LoggerFactory::reset();
SyncPlayEnvelopePinHarness::resetConnectionPool();
foreach (glob($loggerDir . '/*') ?: [] as $f) {
    if (is_file($f)) {
        unlink($f);
    }
}
rmdir($loggerDir);

echo json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
