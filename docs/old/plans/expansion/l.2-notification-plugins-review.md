# Step L.2 — Notification Provider Plugins: Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit tests/unit/Webhooks/Plugins/
# MUST be green; ≥ 8 new tests

# ── 2. PHPStan level 9 ─────────────────────────────────────
./vendor/bin/phpstan analyze src/Webhooks/Plugins --level=9 --no-progress
# Zero errors

# ── 3. PHPCS PSR-12 ──────────────────────────────────────────
./vendor/bin/phpcs --standard=PSR12 src/Webhooks/Plugins/
# Clean

# ── 4. Syntax check ─────────────────────────────────────────
find src/Webhooks/Plugins -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Empty output
```

## Acceptance Criteria

- [ ] All 7 plugins implement `WebhookPluginInterface` with `getName()`, `getSupportedEvents()`, `send()`.
- [ ] `DiscordPlugin` transforms `WebhookEvent` into Discord embed with color, title, description, timestamp, footer.
- [ ] `SlackPlugin` transforms events into Slack Block Kit payload (section, context, divider blocks).
- [ ] `TelegramPlugin` sends `POST` to `https://api.telegram.org/bot{token}/sendMessage` with `chat_id`, `text` (Markdown), `parse_mode: Markdown`.
- [ ] `NtfyPlugin` sends `POST` to `{server}/{topic}` with `message`, `tags` (array), `priority` (1-5).
- [ ] `PushoverPlugin` sends `POST` to `https://api.pushover.net/1/messages.json` with `user`, `token`, `message`, `title`.
- [ ] `ApprisePlugin` sends generic HTTP POST to configured `url` with JSON body.
- [ ] `MqttPlugin` uses `phpMQTT` or `file_get_contents` POST to MQTT broker REST API with JSON payload to configured `topic`.
- [ ] `PluginRegistry` manages plugin instances and provides `get()`, `listAll()`, `register()`.
- [ ] `AbstractNotificationPlugin` provides `formatMessage()`, `getEmbedColor()`, `buildPayload()` helpers.
- [ ] Config `config/notifications.php` with all 7 provider sections.
- [ ] ≥ 8 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

(End of file - total 42 lines)
