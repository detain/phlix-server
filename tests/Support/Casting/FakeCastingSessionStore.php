<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Support\Casting;

use Phlix\Casting\CastingSessionRecord;
use Phlix\Casting\CastingSessionStoreInterface;
use RuntimeException;

/**
 * In-memory {@see CastingSessionStoreInterface} for manager unit tests
 * (Device-M1). Mirrors the real store's observable contract — the unique
 * (type, device) register, the gone-row `touch() === false` signal, the
 * REPLACE semantics of `deleteByDevice()` — without a database.
 *
 * Test knobs (public by design, this is a test double):
 *  - `failInsert`      : every insert() returns false (start must fail closed)
 *  - `failTouchWith`   : touch() throws this exception (DB blip fail-open proof)
 *  - `failFindWith`    : find() throws this exception (re-attach DB blip proof)
 *  - `goneFor`         : touch(sessionId) answers false for these ids
 */
final class FakeCastingSessionStore implements CastingSessionStoreInterface
{
    /** @var array<string, CastingSessionRecord> session id => row */
    public array $rows = [];

    public bool $failInsert = false;

    public ?RuntimeException $failTouchWith = null;

    public ?RuntimeException $failFindWith = null;

    /** @var list<string> session ids whose rows are considered gone by touch() */
    public array $goneFor = [];

    /**
     * @var list<array{sessionId: string, type: string, deviceId: string,
     *      userId: string, state: array<string, mixed>}>
     */
    public array $insertCalls = [];

    /** @var list<string> */
    public array $touchCalls = [];

    /** @var list<string> */
    public array $deleteCalls = [];

    /** @var list<array{type: string, deviceId: string}> */
    public array $deleteByDeviceCalls = [];

    /** @var list<array{type: string, deviceId: string}> */
    public array $findCalls = [];

    public function insert(string $sessionId, string $type, string $deviceId, string $userId, array $state): bool
    {
        $this->insertCalls[] = [
            'sessionId' => $sessionId,
            'type' => $type,
            'deviceId' => $deviceId,
            'userId' => $userId,
            'state' => $state,
        ];

        if ($this->failInsert) {
            return false;
        }

        // Mirror the unique register: one row per (type, device).
        foreach ($this->rows as $id => $row) {
            if ($row->type === $type && $row->deviceId === $deviceId) {
                unset($this->rows[$id]);
            }
        }

        $now = '2026-10-01 00:00:00';
        $this->rows[$sessionId] = new CastingSessionRecord(
            $sessionId,
            $type,
            $deviceId,
            $userId,
            $state,
            $now,
            $now,
        );

        return true;
    }

    public function find(string $type, string $deviceId): ?CastingSessionRecord
    {
        $this->findCalls[] = ['type' => $type, 'deviceId' => $deviceId];

        if ($this->failFindWith !== null) {
            throw $this->failFindWith;
        }

        foreach ($this->rows as $row) {
            if ($row->type === $type && $row->deviceId === $deviceId) {
                return $row;
            }
        }

        return null;
    }

    public function touch(string $sessionId): bool
    {
        $this->touchCalls[] = $sessionId;

        if ($this->failTouchWith !== null) {
            throw $this->failTouchWith;
        }

        if (in_array($sessionId, $this->goneFor, true)) {
            unset($this->rows[$sessionId]);
            return false;
        }

        return isset($this->rows[$sessionId]);
    }

    public function delete(string $sessionId): void
    {
        $this->deleteCalls[] = $sessionId;
        unset($this->rows[$sessionId]);
    }

    public function deleteByDevice(string $type, string $deviceId): void
    {
        $this->deleteByDeviceCalls[] = ['type' => $type, 'deviceId' => $deviceId];

        foreach ($this->rows as $id => $row) {
            if ($row->type === $type && $row->deviceId === $deviceId) {
                unset($this->rows[$id]);
            }
        }
    }

    public function findByUser(string $userId): array
    {
        $records = [];
        foreach ($this->rows as $row) {
            if ($row->userId === $userId) {
                $records[] = $row;
            }
        }

        return $records;
    }

    /**
     * Seed a row directly (simulating "started on another worker").
     *
     * @param array<string, mixed> $state
     */
    public function seed(string $sessionId, string $type, string $deviceId, string $userId, array $state): void
    {
        $now = '2026-10-01 00:00:00';
        $this->rows[$sessionId] = new CastingSessionRecord(
            $sessionId,
            $type,
            $deviceId,
            $userId,
            $state,
            $now,
            $now,
        );
    }
}
