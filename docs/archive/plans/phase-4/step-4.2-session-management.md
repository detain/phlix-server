# Step 4.2: Session Management

**Phase:** 4 - Authentication & Session Management  
**Plan File:** step-4.2-session-management.md  
**Objective:** Implement session tracking, device management, and playback session handling

---

## Overview

This step implements session management for tracking user sessions across devices and managing playback state.

**Prerequisites:** Step 4.1 must be completed first.

---

## Tasks

### 4.2.1 Create Session Manager

Create `src/Session/SessionManager.php`:
```php
<?php

namespace Phlex\Session;

use Phlex\Common\Database\Connection;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;

class SessionManager
{
    private Connection $db;
    private array $activeSessions = [];
    private StructuredLogger $logger;

    public function __construct(Connection $db)
    {
        $this->db = $db;
        $this->logger = LoggerFactory::get(LogChannels::SESSION);
    }

    public function createSession(string $userId, string $deviceId, string $deviceName, string $deviceType): string
    {
        // Check if session already exists for this device
        $existing = $this->findByDeviceId($deviceId);
        if ($existing) {
            $this->updateActivity($existing['id']);
            return $existing['id'];
        }

        $sessionId = $this->generateUuid();

        $this->db->query(
            "INSERT INTO sessions (id, user_id, device_id, device_name, device_type) VALUES (?, ?, ?, ?, ?)",
            [$sessionId, $userId, $deviceId, $deviceName, $deviceType]
        );

        $this->activeSessions[$sessionId] = [
            'id' => $sessionId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'last_activity' => time(),
        ];

        $this->logger->info('Session created', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'device_id' => $deviceId,
        ]);

        return $sessionId;
    }

    public function getSession(string $sessionId): ?array
    {
        if (isset($this->activeSessions[$sessionId])) {
            return $this->activeSessions[$sessionId];
        }

        $result = $this->db->query(
            "SELECT * FROM sessions WHERE id = ?",
            [$sessionId]
        );

        if (empty($result)) {
            return null;
        }

        return $result[0];
    }

    public function findByDeviceId(string $deviceId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM sessions WHERE device_id = ? ORDER BY last_activity DESC LIMIT 1",
            [$deviceId]
        );

        return $result[0] ?? null;
    }

    public function getUserSessions(string $userId): array
    {
        return $this->db->query(
            "SELECT * FROM sessions WHERE user_id = ? ORDER BY last_activity DESC",
            [$userId]
        );
    }

    public function updateActivity(string $sessionId): void
    {
        $this->db->query(
            "UPDATE sessions SET last_activity = NOW() WHERE id = ?",
            [$sessionId]
        );

        if (isset($this->activeSessions[$sessionId])) {
            $this->activeSessions[$sessionId]['last_activity'] = time();
        }
    }

    public function endSession(string $sessionId): void
    {
        $session = $this->getSession($sessionId);
        if ($session) {
            $this->db->query("DELETE FROM sessions WHERE id = ?", [$sessionId]);
            unset($this->activeSessions[$sessionId]);
            
            $this->logger->info('Session ended', ['session_id' => $sessionId]);
        }
    }

    public function endAllUserSessions(string $userId, ?string $exceptSessionId = null): void
    {
        $sql = "DELETE FROM sessions WHERE user_id = ?";
        $params = [$userId];

        if ($exceptSessionId) {
            $sql .= " AND id != ?";
            $params[] = $exceptSessionId;
        }

        $this->db->query($sql, $params);

        $this->logger->info('All user sessions ended', [
            'user_id' => $userId,
            'except_session' => $exceptSessionId,
        ]);
    }

    public function cleanupStaleSessions(int $maxIdleSeconds = 86400): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $maxIdleSeconds);

        $result = $this->db->query(
            "SELECT id FROM sessions WHERE last_activity < ?",
            [$cutoff]
        );

        $count = count($result);

        if ($count > 0) {
            $this->db->query(
                "DELETE FROM sessions WHERE last_activity < ?",
                [$cutoff]
            );

            $this->logger->info('Cleaned up stale sessions', ['count' => $count]);
        }

        return $count;
    }

    public function getActiveSessionCount(): int
    {
        return count($this->activeSessions);
    }

    public function getOnlineUsers(): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - 300); // 5 minutes
        $result = $this->db->query(
            "SELECT DISTINCT user_id FROM sessions WHERE last_activity > ?",
            [$cutoff]
        );

        return array_column($result, 'user_id');
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
```

### 4.2.2 Create Playback Controller

Create `src/Session/PlaybackController.php`:
```php
<?php

namespace Phlex\Session;

use Phlex\Common\Database\Connection;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;

class PlaybackController
{
    private Connection $db;
    private SessionManager $sessionManager;
    private StructuredLogger $logger;

    public function __construct(Connection $db, SessionManager $sessionManager)
    {
        $this->db = $db;
        $this->sessionManager = $sessionManager;
        $this->logger = LoggerFactory::get(LogChannels::SESSION);
    }

    public function reportProgress(string $sessionId, string $mediaItemId, int $positionTicks, int $durationTicks, bool $isPaused): void
    {
        // Update or create playback state
        $this->db->query(
            "INSERT INTO playback_state (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE 
                position_ticks = VALUES(position_ticks),
                duration_ticks = VALUES(duration_ticks),
                playback_status = VALUES(playback_status),
                updated_at = NOW()",
            [
                $this->generateUuid(),
                $sessionId,
                $mediaItemId,
                $positionTicks,
                $durationTicks,
                $isPaused ? 'paused' : 'playing',
            ]
        );

        // Update session activity
        $this->sessionManager->updateActivity($sessionId);
    }

    public function getPlaybackState(string $sessionId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM playback_state WHERE session_id = ? ORDER BY updated_at DESC LIMIT 1",
            [$sessionId]
        );

        return $result[0] ?? null;
    }

    public function getUserProgress(string $userId, string $mediaItemId): ?array
    {
        $result = $this->db->query(
            "SELECT ps.* FROM playback_state ps
             INNER JOIN sessions s ON ps.session_id = s.id
             WHERE s.user_id = ? AND ps.media_item_id = ?
             ORDER BY ps.updated_at DESC LIMIT 1",
            [$userId, $mediaItemId]
        );

        return $result[0] ?? null;
    }

    public function markAsWatched(string $sessionId, string $mediaItemId): void
    {
        $this->db->query(
            "UPDATE playback_state SET playback_status = 'stopped', position_ticks = 0 WHERE session_id = ? AND media_item_id = ?",
            [$sessionId, $mediaItemId]
        );
    }

    public function clearProgress(string $sessionId, string $mediaItemId): void
    {
        $this->db->query(
            "DELETE FROM playback_state WHERE session_id = ? AND media_item_id = ?",
            [$sessionId, $mediaItemId]
        );
    }

    public function getContinueWatching(string $userId, int $limit = 10): array
    {
        $result = $this->db->query(
            "SELECT ps.*, mi.name, mi.type, mi.metadata_json
             FROM playback_state ps
             INNER JOIN sessions s ON ps.session_id = s.id
             INNER JOIN media_items mi ON ps.media_item_id = mi.id
             WHERE s.user_id = ?
               AND ps.playback_status IN ('playing', 'paused')
               AND ps.position_ticks > 0
               AND ps.position_ticks < (ps.duration_ticks * 0.95)
             ORDER BY ps.updated_at DESC
             LIMIT ?",
            [$userId, $limit]
        );

        return array_map(function ($row) {
            $row['metadata'] = json_decode($row['metadata_json'] ?? '{}', true);
            return $row;
        }, $result);
    }

    public function getRecentlyWatched(string $userId, int $limit = 20): array
    {
        $result = $this->db->query(
            "SELECT ps.*, mi.name, mi.type, mi.metadata_json
             FROM playback_state ps
             INNER JOIN sessions s ON ps.session_id = s.id
             INNER JOIN media_items mi ON ps.media_item_id = mi.id
             WHERE s.user_id = ?
             ORDER BY ps.updated_at DESC
             LIMIT ?",
            [$userId, $limit]
        );

        return array_map(function ($row) {
            $row['metadata'] = json_decode($row['metadata_json'] ?? '{}', true);
            return $row;
        }, $result);
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
```

### 4.2.3 Create Session Controller

Create `src/Server/Http/Controllers/SessionController.php`:
```php
<?php

namespace Phlex\Server\Http\Controllers;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Session\SessionManager;
use Phlex\Session\PlaybackController;

class SessionController
{
    private SessionManager $sessionManager;
    private PlaybackController $playbackController;

    public function __construct(
        SessionManager $sessionManager,
        PlaybackController $playbackController
    ) {
        $this->sessionManager = $sessionManager;
        $this->playbackController = $playbackController;
    }

    public function listSessions(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $sessions = $this->sessionManager->getUserSessions($userId);
        return (new Response())->json(['sessions' => $sessions]);
    }

    public function endSession(Request $request, array $params): Response
    {
        $sessionId = $params['id'] ?? '';
        $session = $this->sessionManager->getSession($sessionId);

        if (!$session) {
            return (new Response())->status(404)->json(['error' => 'Session not found']);
        }

        // Verify ownership
        if ($session['user_id'] !== ($request->userId ?? '')) {
            return (new Response())->status(403)->json(['error' => 'Forbidden']);
        }

        $this->sessionManager->endSession($sessionId);

        return (new Response())->json(['message' => 'Session ended']);
    }

    public function reportProgress(Request $request, array $params): Response
    {
        $sessionId = $params['id'] ?? '';
        $data = $request->body;

        if (empty($data['media_item_id']) || !isset($data['position_ticks'])) {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: media_item_id, position_ticks',
            ]);
        }

        $this->playbackController->reportProgress(
            $sessionId,
            $data['media_item_id'],
            (int)$data['position_ticks'],
            (int)($data['duration_ticks'] ?? 0),
            (bool)($data['is_paused'] ?? false)
        );

        return (new Response())->json(['message' => 'Progress updated']);
    }

    public function getProgress(Request $request, array $params): Response
    {
        $sessionId = $params['id'] ?? '';
        $state = $this->playbackController->getPlaybackState($sessionId);

        if (!$state) {
            return (new Response())->json(['progress' => null]);
        }

        return (new Response())->json(['progress' => $state]);
    }

    public function getContinueWatching(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $items = $this->playbackController->getContinueWatching($userId);
        return (new Response())->json(['items' => $items]);
    }

    public function getRecentlyWatched(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $items = $this->playbackController->getRecentlyWatched($userId);
        return (new Response())->json(['items' => $items]);
    }
}
```

### 4.2.4 Create Unit Tests

Create `tests/unit/Session/SessionManagerTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use Phlex\Session\SessionManager;

class SessionManagerTest extends TestCase
{
    public function testCanCreateSessionManager(): void
    {
        $db = $this->createMock(\Phlex\Common\Database\Connection::class);
        $manager = new SessionManager($db);
        
        $this->assertInstanceOf(SessionManager::class, $manager);
    }
}
```

---

## Verification

1. Run unit tests:
```bash
cd /home/sites/phlex
./vendor/bin/phpunit tests/unit/Session/ --testdox
```

2. Verify classes exist:
```bash
ls -la /home/sites/phlex/src/Session/
```

---

## Git Workflow

```bash
cd /home/sites/phlex
git checkout -b step-4.2-session-management
git add .
git commit -m "Step 4.2: Implement session and playback management"
unset GITHUB_TOKEN
gh pr create --title "Step 4.2: Session Management" --body "Implements SessionManager and PlaybackController for session tracking and playback state."
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Next Step

After completing and merging this step, proceed to **Step 4.R: Phase 4 Review** (`plans/phase-4/step-4.R-phase-review.md`).
