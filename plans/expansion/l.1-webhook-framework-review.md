# Step L.1 — Webhook Plugin Framework: Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit tests/unit/Webhooks/
# MUST be green; ≥ 7 new tests

# ── 2. PHPStan level 9 ─────────────────────────────────────
./vendor/bin/phpstan analyze src/Webhooks --level=9 --no-progress
# Zero errors

# ── 3. PHPCS PSR-12 ──────────────────────────────────────────
./vendor/bin/phpcs --standard=PSR12 src/Webhooks/
# Clean

# ── 4. Syntax check ─────────────────────────────────────────
find src/Webhooks -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Empty output

# ── 5. Migration check ──────────────────────────────────────
ls migrations/018_webhooks.sql
# File must exist
```

## Acceptance Criteria

- [ ] `WebhookEvent` has `eventType`, `payload`, `occurredAt`, `toArray()`, `getSignature(secret)` returning HMAC-SHA256.
- [ ] `WebhookDispatcher::dispatch()` sends HTTP POST to all active webhooks matching the event type.
- [ ] `X-Phlex-Signature: sha256=<hmac>` header included in every dispatch request.
- [ ] `WebhookPluginInterface` defines `getName()`, `getSupportedEvents()`, `send(WebhookEvent): bool`.
- [ ] `webhooks` table: id, name, url, secret, events_json, is_active, failure_count.
- [ ] `webhook_logs` table: stores response_code, response_body, error_message per dispatch.
- [ ] Admin API: `GET /api/v1/admin/webhooks`, `POST /api/v1/admin/webhooks`, `DELETE /api/v1/admin/webhooks/{id}`, `POST /api/v1/admin/webhooks/{id}/test`.
- [ ] Config `config/webhooks.php` with `enabled`, `timeout`, `max_retries`, `parallel_dispatch`.
- [ ] `WebhookDispatcher` uses Workerman Timer for non-blocking async dispatch.
- [ ] ≥ 7 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

(End of file - total 42 lines)
