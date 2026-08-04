<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Access;

use Phlix\Access\AccessScheduleService;
use Phlix\Access\ProfileAccessPolicy;
use Phlix\Access\ProfileTagService;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Common\Uuid;
use Phlix\Server\Http\Controllers\AccessScheduleController;
use Phlix\Server\Http\Controllers\ProfileTagController;
use Phlix\Server\Http\Request;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S208 — the same cross-profile refusal, against a real MySQL.
 *
 * The unit pins in
 * `tests/Unit/Server/Http/Controllers/ParentalControlsAuthorizationTest.php`
 * drive the handlers over a mocked connection. That is sound for the S208 check
 * itself, because the check is a PHP comparison of the hydrated record's
 * `profileId` against the path parameter and not a WHERE clause — but it can
 * only ever return the row shape the test itself invented.
 *
 * This file removes that last assumption. It writes real rows into
 * `access_schedules` / `profile_tags` (`profile_id CHAR(36)`, per migrations 061
 * and 062) and asserts:
 *
 *  - `AccessSchedule::fromRow()` / `ProfileTag::fromRow()` hydrate the real
 *    36-character UUID. Before S208 both narrowed with `is_numeric()` + `(int)`,
 *    which NO uuid can pass, so every hydrated record carried `profileId === 0`
 *    and the ownership comparison this step adds would have been unimplementable.
 *  - a DELETE naming the wrong `{profileId}` leaves the row physically present.
 *
 * ⚠ Skips when no MySQL is reachable (the dev box has none running); it runs in
 * CI, where `.github/workflows/phpunit.yml` starts MySQL 8.0 and applies every
 * migration before the suite.
 */
final class ParentalControlsCrossProfileRealDbTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;
    private string $userId = '';
    private string $profileOne = '';
    private string $profileTwo = '';
    private int $scheduleOfProfileTwo = 0;
    private int $tagOfProfileTwo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping S208 cross-profile real-DB test. Runs in CI.');
        $db = $this->db;
        $this->assertNotNull($db);

        $this->userId = Uuid::v4();
        $this->profileOne = Uuid::v4();
        $this->profileTwo = Uuid::v4();

        $suffix = substr($this->userId, 0, 8);
        $db->query(
            'INSERT INTO users (id, username, email, password_hash) VALUES (?, ?, ?, ?)',
            [$this->userId, 's208-' . $suffix, 's208-' . $suffix . '@example.test', 'x'],
        );
        $db->query(
            'INSERT INTO user_profiles (id, user_id, name) VALUES (?, ?, ?)',
            [$this->profileOne, $this->userId, 'S208 One'],
        );
        $db->query(
            'INSERT INTO user_profiles (id, user_id, name) VALUES (?, ?, ?)',
            [$this->profileTwo, $this->userId, 'S208 Two'],
        );

        $this->scheduleOfProfileTwo = (new AccessScheduleService($db))->createSchedule(
            $this->profileTwo,
            'S208 Bedtime',
            '20:00:00',
            '22:00:00',
            ['mon', 'tue'],
            true,
        );
        $this->tagOfProfileTwo = (new ProfileTagService($db))->setTag($this->profileTwo, 's208-violence', 'blocked');
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            if ($this->scheduleOfProfileTwo > 0) {
                $db->query('DELETE FROM access_schedules WHERE id = ?', [$this->scheduleOfProfileTwo]);
            }
            if ($this->tagOfProfileTwo > 0) {
                $db->query('DELETE FROM profile_tags WHERE id = ?', [$this->tagOfProfileTwo]);
            }
            if ($this->userId !== '') {
                // user_profiles cascades from users (migration 002 FK).
                $db->query('DELETE FROM users WHERE id = ?', [$this->userId]);
            }
        }

        parent::tearDown();
    }

    public function testAScheduleHydratesTheRealCharThirtySixProfileIdAndNotZero(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $schedule = (new AccessScheduleService($db))->getScheduleById($this->scheduleOfProfileTwo);

        $this->assertNotNull($schedule);
        $this->assertSame($this->profileTwo, $schedule->profileId);
        $this->assertSame($this->profileTwo, $schedule->toArray()['profile_id']);
    }

    public function testATagHydratesTheRealCharThirtySixProfileIdAndNotZero(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $tag = (new ProfileTagService($db))->getTagById($this->tagOfProfileTwo);

        $this->assertNotNull($tag);
        $this->assertSame($this->profileTwo, $tag->profileId);
    }

    public function testDeletingAScheduleUnderTheWrongProfileLeavesTheRowInPlace(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $controller = new AccessScheduleController(
            new AccessScheduleService($db),
            new ProfileAccessPolicy(new UserProfileManager($db), new UserRepository($db)),
        );

        $response = $controller->deleteSchedule(
            $this->request(),
            ['profileId' => $this->profileOne, 'scheduleId' => (string) $this->scheduleOfProfileTwo],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame(
            1,
            $this->countRows('SELECT COUNT(*) AS c FROM access_schedules WHERE id = ?', $this->scheduleOfProfileTwo),
            'the schedule of the OTHER profile must still exist in the table',
        );
    }

    public function testDeletingATagUnderTheWrongProfileLeavesTheRowInPlace(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $controller = new ProfileTagController(
            new ProfileTagService($db),
            new ProfileAccessPolicy(new UserProfileManager($db), new UserRepository($db)),
        );

        $response = $controller->deleteTag(
            $this->request(),
            ['profileId' => $this->profileOne, 'tagId' => (string) $this->tagOfProfileTwo],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame(
            1,
            $this->countRows('SELECT COUNT(*) AS c FROM profile_tags WHERE id = ?', $this->tagOfProfileTwo),
            'the tag of the OTHER profile must still exist in the table',
        );
    }

    public function testDeletingAScheduleUnderItsOwnProfileStillWorks(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $controller = new AccessScheduleController(
            new AccessScheduleService($db),
            new ProfileAccessPolicy(new UserProfileManager($db), new UserRepository($db)),
        );

        $response = $controller->deleteSchedule(
            $this->request(),
            ['profileId' => $this->profileTwo, 'scheduleId' => (string) $this->scheduleOfProfileTwo],
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(
            0,
            $this->countRows('SELECT COUNT(*) AS c FROM access_schedules WHERE id = ?', $this->scheduleOfProfileTwo),
        );
    }

    private function request(): Request
    {
        $request = new Request();
        $request->userId = $this->userId;

        return $request;
    }

    private function countRows(string $sql, int $id): int
    {
        $db = $this->db;
        $this->assertNotNull($db);

        /** @var mixed $rows */
        $rows = $db->query($sql, [$id]);
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return -1;
        }

        /** @var mixed $count */
        $count = $rows[0]['c'] ?? null;

        return is_numeric($count) ? (int) $count : -1;
    }
}
