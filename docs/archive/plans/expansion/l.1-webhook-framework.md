# Step L.1 — Webhook Plugin Framework

**Phase:** L (Notifications, Stats & Admin)
**Step:** L.1
**Depends on:** Master (no prior L steps)
**Review:** Yes — see `l.1-webhook-framework-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Build a **webhook plugin framework** that lets Phlex send events (playback started, library updated, download complete, etc.) to any HTTP endpoint. This is the foundation for L.2's notification plugins (Discord, Slack, etc.).

Events are dispatched as JSON POST requests with a signature header (`X-Phlex-Signature: sha256=<hmac>`) for verification. Plugins implement a simple `WebhookPluginInterface` and register via config.

## 2. Context (what already exists)

Read first:

- `src/Common/Logger/LoggerFactory.php` — existing logger pattern.
- `config/` — existing config structure.
- `src/Session/PlaybackController.php` — playback events to dispatch.
- `src/Media/Library/LibraryManager.php` — library events.

## 3. Scope — files to create / modify

### Create

#### Core webhook system

- `src/Webhooks/WebhookEvent.php`:
  ```php
  class WebhookEvent
  {
      public function __construct(
          public readonly string $eventType,    // 'playback.started', 'library.updated', etc.
          public readonly array $payload,       // event-specific data
          public readonly \DateTimeImmutable $occurredAt,
      ) {}

      public function toArray(): array {}
      public function getSignature(string $secret): string {} // HMAC-SHA256
  }
  ```

- `src/Webhooks/WebhookDispatcher.php`:
  ```php
  class WebhookDispatcher
  {
      public function __construct(
          private readonly Connection $db,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Register a new webhook endpoint. */
      public function register(string $name, string $url, string $secret, array $events): string {}

      /** Remove a webhook. */
      public function unregister(string $webhookId): bool {}

      /** Dispatch an event to all matching webhooks. */
      public function dispatch(WebhookEvent $event): DispatchResult {}

      /** List all registered webhooks. */
      public function listWebhooks(): array {}
  }
  ```

- `src/Webhooks/DispatchResult.php`:
  ```php
  class DispatchResult
  {
      public function __construct(
          public readonly int $successCount,
          public readonly int $failureCount,
          public readonly array $failures, // [{webhook_id, url, error}]
      ) {}
  }
  ```

- `src/Webhooks/WebhookPluginInterface.php`:
  ```php
  interface WebhookPluginInterface
  {
      public static function getName(): string;
      public static function getSupportedEvents(): array;
      public function send(WebhookEvent $event): bool;
  }
  ```

#### Database schema

- `migrations/018_webhooks.sql`:
  ```sql
  CREATE TABLE webhooks (
      id CHAR(36) PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      url VARCHAR(2048) NOT NULL,
      secret VARCHAR(255) NOT NULL,
      events_json TEXT NOT NULL,
      is_active BOOLEAN DEFAULT TRUE,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      last_triggered_at TIMESTAMP NULL,
      failure_count INT DEFAULT 0
  );
  CREATE TABLE webhook_logs (
      id CHAR(36) PRIMARY KEY,
      webhook_id CHAR(36) NOT NULL,
      event_type VARCHAR(100) NOT NULL,
      response_code INT,
      response_body TEXT,
      error_message TEXT,
      triggered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_webhook_triggered (webhook_id, triggered_at)
  );
  ```

#### HTTP endpoints

- `src/Server/Http/Controllers/Webhooks/WebhookAdminController.php`:
  - `GET /api/v1/admin/webhooks` — list all webhooks
  - `POST /api/v1/admin/webhooks` — register a webhook
  - `DELETE /api/v1/admin/webhooks/{id}` — remove a webhook
  - `POST /api/v1/admin/webhooks/{id}/test` — send test event

#### Config

- `config/webhooks.php`:
  ```php
  return [
      'enabled' => true,
      'timeout' => 5, // seconds
      'max_retries' => 2,
      'parallel_dispatch' => true,
  ];
  ```

#### Tests

- `tests/Unit/Webhooks/WebhookEventTest.php`
- `tests/Unit/Webhooks/WebhookDispatcherTest.php`

### Modify

- `composer.json` — no new dependencies.
- `CHANGELOG.md` — add entry.

## 4. Approach

1. Branch from master: `git checkout -b l.1-webhook-framework`.
2. Build `WebhookEvent` with HMAC-SHA256 signing.
3. Build `WebhookDispatcher` that queries DB for matching webhooks and sends async HTTP POST.
4. Use `Workerman\Timer` for async dispatch (non-blocking).
5. Store dispatch logs in `webhook_logs` table.
6. Write tests.
7. Verify: PHPUnit green, PHPStan level 9, PHPCS clean.
8. Commit + PR + merge.

## 5. Tests (REQUIRED)

1. `WebhookEventTest::test_event_to_array_includes_all_fields`
2. `WebhookEventTest::test_get_signature_produces_hmac_sha256`
3. `WebhookDispatcherTest::test_register_creates_webhook`
4. `WebhookDispatcherTest::test_unregister_removes_webhook`
5. `WebhookDispatcherTest::test_dispatch_sends_to_matching_webhooks`
6. `WebhookDispatcherTest::test_dispatch_returns_failure_on_http_error`
7. `WebhookDispatcherTest::test_list_webhooks_returns_all`

## 6. Acceptance Criteria

- [ ] `WebhookEvent` has `eventType`, `payload`, `occurredAt`, `toArray()`, `getSignature()`.
- [ ] `WebhookDispatcher::dispatch()` sends HTTP POST to all webhooks matching the event type.
- [ ] HMAC-SHA256 signature in `X-Phlex-Signature` header.
- [ ] `WebhookPluginInterface` defines `getName()`, `getSupportedEvents()`, `send()`.
- [ ] `webhooks` table stores registered webhooks with secrets.
- [ ] `webhook_logs` table stores dispatch history.
- [ ] Admin API: GET/POST/DELETE `/api/v1/admin/webhooks`, POST test.
- [ ] Config `config/webhooks.php` with `enabled`, `timeout`, `max_retries`.
- [ ] ≥ 7 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b l.1-webhook-framework
# ... implement ...
./vendor/bin/phpunit tests/Unit/Webhooks/
./vendor/bin/phpstan analyze src/Webhooks --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/Webhooks/
git add -A
git commit -m "Step L.1: Webhook plugin framework"
unset GITHUB_TOKEN
gh pr create --title "Step L.1: Webhook plugin framework" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `l.1-webhook-framework-review.md`.

(End of file - total 154 lines)
