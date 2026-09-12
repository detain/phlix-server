<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Support\SyncPlay;

use Phlix\Session\SyncPlay\GroupState;
use Phlix\Session\SyncPlay\SyncPlaySnapshotService;

/**
 * In-memory stand-in for the REST-owned `syncplay_snapshots` store, used by
 * unit venues that drive the S445 write-through CONTROLLER rail without a
 * database. The rail's persist step (publishGroup/removeGroup) and its
 * hydrate step (loadSerialized) resolve entirely against the retained rows,
 * so read-modify-write across controller calls behaves like the shared store
 * — which is precisely the property those venues need. The real store's
 * byte-level SQL behavior is covered by the Integration suite.
 *
 * Deliberately FAILS LOUD on the two read rails (listGroups/getGroupState): a
 * unit venue reaching them through this fake means it is no longer pinning
 * what it claims to pin.
 */
final class InMemorySyncPlaySnapshotService extends SyncPlaySnapshotService
{
    /** @var array<string, array<string, mixed>> serialized_state rows by group id */
    private array $rows = [];

    public function publishGroup(GroupState $group): void
    {
        $this->rows[$group->getId()] = $group->serialize();
    }

    public function removeGroup(string $groupId): void
    {
        unset($this->rows[$groupId]);
    }

    public function loadSerialized(string $groupId): ?array
    {
        return $this->rows[$groupId] ?? null;
    }

    public function getGroupState(string $groupId): ?array
    {
        $serialized = $this->rows[$groupId] ?? null;
        if ($serialized === null) {
            return null;
        }

        return GroupState::deserialize($serialized)->getState();
    }

    public function listGroups(): array
    {
        $groups = [];
        foreach ($this->rows as $serialized) {
            $group = GroupState::deserialize($serialized);
            $groups[] = [
                'id' => $group->getId(),
                'name' => $group->getName(),
                'member_count' => $group->getMemberCount(),
                'has_password' => $group->hasPassword(),
                'current_media' => $group->getCurrentMediaId(),
                'is_playing' => $group->isPlaying(),
            ];
        }

        return $groups;
    }

    /** @return array<string, array<string, mixed>> retained rows (assertion surface) */
    public function rows(): array
    {
        return $this->rows;
    }
}
