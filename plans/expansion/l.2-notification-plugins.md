# Step L.2 — Notification Provider Plugins

**Phase:** L (Notifications, Stats & Admin)
**Step:** L.2
**Depends on:** L.1 (webhook framework is required)
**Review:** Yes — see `l.2-notification-plugins-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement webhook-based notification plugins for:
- **Discord** — rich embeds with color, author, thumbnail.
- **Slack** — Block Kit payloads with attachments.
- **Telegram** — Markdown-formatted messages via Bot API.
- **ntfy** — Simple pub/sub notifications via ntfy.sh or self-hosted.
- **Pushover** — Priority notifications to mobile devices.
- **Apprise** — Bridge to 50+ notification services.
- **MQTT** — Publish to an MQTT broker for IoT integration.

Each plugin implements `WebhookPluginInterface` (from L.1) and transforms `WebhookEvent` into the platform-specific payload.

## 2. Context (what already exists)

Read first:

- `src/Webhooks/WebhookPluginInterface.php` — from L.1.
- `src/Webhooks/WebhookDispatcher.php` — from L.1.
- `config/webhooks.php` — from L.1.

## 3. Scope — files to create / modify

### Create

#### Base plugin

- `src/Webhooks/Plugins/AbstractNotificationPlugin.php`:
  ```php
  abstract class AbstractNotificationPlugin implements WebhookPluginInterface
  {
      protected function formatMessage(WebhookEvent $event): string {}
      protected function getEmbedColor(WebhookEvent $event): int {} // RGB int
      abstract protected function buildPayload(WebhookEvent $event): array;
  }
  ```

#### Discord plugin

- `src/Webhooks/Plugins/DiscordPlugin.php`:
  - `getName(): string` → `'discord'`
  - `getSupportedEvents(): array` → `['playback.started', 'playback.ended', 'library.updated', 'download.complete']`
  - `send(WebhookEvent $event): bool` — builds Discord embed with color, title, description, thumbnail.

#### Slack plugin

- `src/Webhooks/Plugins/SlackPlugin.php`:
  - `getName(): string` → `'slack'`
  - `send(WebhookEvent $event): bool` — builds Block Kit payload.

#### Telegram plugin

- `src/Webhooks/Plugins/TelegramPlugin.php`:
  - `getName(): string` → `'telegram'`
  - Uses Telegram Bot API: `POST https://api.telegram.org/bot{TOKEN}/sendMessage` with `chat_id` and `text`.

#### ntfy plugin

- `src/Webhooks/Plugins/NtfyPlugin.php`:
  - `getName(): string` → `'ntfy'`
  - Uses ntfy REST API: `POST https://ntfy.sh/{topic}` with tags, priority, and message.

#### Pushover plugin

- `src/Webhooks/Plugins/PushoverPlugin.php`:
  - `getName(): string` → `'pushover'`
  - Uses Pushover API: `POST https://api.pushover.net/1/messages.json`.

#### Apprise plugin

- `src/Webhooks/Plugins/ApprisePlugin.php`:
  - `getName(): string` → `'apprise'`
  - Calls configured Apprise URL as webhook receiver.

#### MQTT plugin

- `src/Webhooks/Plugins/MqttPlugin.php`:
  - `getName(): string` → `'mqtt'`
  - Publishes JSON payload to configured MQTT broker/topic.

#### Plugin registry

- `src/Webhooks/PluginRegistry.php`:
  ```php
  class PluginRegistry
  {
      /** @var array<string, class-string<WebhookPluginInterface>> */
      private array $plugins = [];

      public function register(WebhookPluginInterface $plugin): void {}
      public function get(string $name): ?WebhookPluginInterface {}
      public function listAll(): array {}
  }
  ```

#### Config

- `config/notifications.php`:
  ```php
  return [
      'discord' => ['webhook_url' => '', 'enabled' => false],
      'slack' => ['webhook_url' => '', 'enabled' => false],
      'telegram' => ['bot_token' => '', 'chat_id' => '', 'enabled' => false],
      'ntfy' => ['topic' => '', 'server' => 'https://ntfy.sh', 'enabled' => false],
      'pushover' => ['user_key' => '', 'api_token' => '', 'enabled' => false],
      'apprise' => ['url' => '', 'enabled' => false],
      'mqtt' => ['broker' => 'localhost:1883', 'topic' => 'phlex/events', 'username' => '', 'password' => '', 'enabled' => false],
  ];
  ```

#### Tests

- `tests/unit/Webhooks/Plugins/DiscordPluginTest.php`
- `tests/unit/Webhooks/Plugins/SlackPluginTest.php`
- `tests/unit/Webhooks/Plugins/TelegramPluginTest.php`
- `tests/unit/Webhooks/Plugins/NtfyPluginTest.php`

### Modify

- `composer.json` — no new dependencies (plain HTTP).
- `src/Server/Core/Application.php` — register plugin registry.
- `CHANGELOG.md` — add entry.

## 4. Approach

1. Branch from master (after L.1 merged): `git checkout -b l.2-notification-plugins`.
2. Build `AbstractNotificationPlugin` with shared formatting helpers.
3. Implement each plugin as a thin adapter translating `WebhookEvent` to platform-specific payload.
4. Each plugin uses `file_get_contents` with stream context for HTTP POST (no Guzzle).
5. Write tests using mocks for HTTP layer.
6. Verify: PHPUnit green, PHPStan level 9, PHPCS clean.
7. Commit + PR + merge.

## 5. Tests (REQUIRED — minimum bar)

1. `DiscordPluginTest::test_send_builds_correct_embed_payload`
2. `DiscordPluginTest::test_get_name_returns_discord`
3. `SlackPluginTest::test_send_builds_block_kit_payload`
4. `SlackPluginTest::test_supported_events`
5. `TelegramPluginTest::test_send_builds_markdown_message`
6. `TelegramPluginTest::test_get_name_returns_telegram`
7. `NtfyPluginTest::test_send_posts_to_ntfy_api`
8. `NtfyPluginTest::test_get_name_returns_ntfy`

## 6. Acceptance Criteria

- [ ] All 7 plugins implement `WebhookPluginInterface`.
- [ ] `DiscordPlugin` sends rich embeds with color, title, description, timestamp.
- [ ] `SlackPlugin` sends Block Kit payloads.
- [ ] `TelegramPlugin` sends Markdown-formatted messages.
- [ ] `NtfyPlugin` uses ntfy REST API with tags and priority.
- [ ] `PushoverPlugin` uses Pushover API with title and message.
- [ ] `ApprisePlugin` calls Apprise URL as generic webhook receiver.
- [ ] `MqttPlugin` publishes JSON to configured MQTT broker/topic.
- [ ] `PluginRegistry` manages all plugin instances.
- [ ] Config `config/notifications.php` with per-provider settings.
- [ ] ≥ 8 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b l.2-notification-plugins
# ... implement ...
./vendor/bin/phpunit tests/unit/Webhooks/Plugins/
./vendor/bin/phpstan analyze src/Webhooks/Plugins --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/Webhooks/Plugins/
git add -A
git commit -m "Step L.2: Discord/Slack/Telegram/ntfy/Pushover/Apprise/MQTT notification plugins"
unset GITHUB_TOKEN
gh pr create --title "Step L.2: Notification provider plugins" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `l.2-notification-plugins-review.md`.

(End of file - total 151 lines)
