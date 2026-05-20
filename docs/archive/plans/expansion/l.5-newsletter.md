# Step L.5 — Weekly Newsletter Email

**Phase:** L (Notifications, Stats & Admin)
**Step:** L.5
**Depends on:** L.3 (stats data)
**Review:** Yes — see `l.5-newsletter-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement a **weekly newsletter email** system that:
- Sends an HTML email every week to users summarizing their activity: watch time, new shows added, top media of the week.
- Uses a queue-based system (Workerman Timer) to send emails at a configurable time.
- Supports HTML email with embedded styles and media posters.
- Stores email delivery logs and retry failed deliveries.

## 2. Context (what already exists)

Read first:

- `src/Stats/StatsCollector.php` — from L.3.
- `src/Server/Core/Application.php` — existing Workerman worker setup.
- `src/Media/Library/LibraryManager.php` — library data for "new this week".

## 3. Scope — files to create / modify

### Create

#### Newsletter generator

- `src/Admin/NewsletterGenerator.php`:
  ```php
  class NewsletterGenerator
  {
      public function __construct(
          private readonly StatsCollector $stats,
          private readonly LibraryManager $library,
          private readonly Connection $db,
      ) {}

      /** Generate HTML email content for a user. */
      public function generateForUser(string $userId, \DateTimeInterface $weekStart): array {
          // Returns [
          //   'subject' => string,
          //   'html_body' => string,
          //   'plain_text' => string,
          //   'week_watch_time_minutes' => int,
          //   'new_items_count' => int,
          //   'top_media' => array,
          // ]
      }

      /** Get list of users who should receive the newsletter. */
      public function getRecipientUserIds(): array {}
  }
  ```

#### Email sender

- `src/Admin/NewsletterSender.php`:
  ```php
  class NewsletterSender
  {
      public function __construct(
          private readonly Connection $db,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Queue newsletter emails for all eligible users. */
      public function queueAll(): int {}

      /** Send pending queued emails (called by Workerman Timer). */
      public function processQueue(int $batchSize = 50): int {}

      /** Get delivery status. */
      public function getDeliveryStats(): array {}
  }
  ```

#### Database schema

- `migrations/020_newsletter.sql`:
  ```sql
  CREATE TABLE newsletter_queue (
      id CHAR(36) PRIMARY KEY,
      user_id CHAR(36) NOT NULL,
      week_start DATE NOT NULL,
      status ENUM('pending','sent','failed') DEFAULT 'pending',
      attempts INT DEFAULT 0,
      last_attempt_at DATETIME,
      sent_at DATETIME,
      error_message TEXT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_user_week (user_id, week_start),
      INDEX idx_status (status)
  );
  ```

#### Config

- `config/newsletter.php`:
  ```php
  return [
      'enabled' => false,
      'send_day' => 'sunday',      // day of week to send
      'send_hour' => 9,           // hour (0-23) in server timezone
      'batch_size' => 50,          // emails per batch
      'from_email' => 'phlex@example.com',
      'from_name' => 'Phlex Media Server',
      'subject_template' => 'Your Phlex Weekly Watch Report',
  ];
  ```

#### Smarty template

- `public/templates/emails/newsletter.tpl`:
  - HTML email with responsive design
  - Watch time summary (total minutes, sessions count)
  - Top 5 media of the week with posters
  - New items added to library this week
  - "View in Phlex" CTA button
  - Unsubscribe link at bottom

#### Tests

- `tests/Unit/Admin/NewsletterGeneratorTest.php`
- `tests/Unit/Admin/NewsletterSenderTest.php`

### Modify

- `src/Server/Core/Application.php` — register newsletter timer (if enabled, start weekly timer).
- `composer.json` — no new dependencies (plain `mail()` or use existing email infrastructure).
- `CHANGELOG.md` — add entry.

## 4. Approach

1. Branch from master (after L.3 merged): `git checkout -b l.5-newsletter`.
2. Build `NewsletterGenerator` that queries L.3 stats for per-user week data.
3. Use Smarty to render HTML email template from `newsletter.tpl`.
4. Build `NewsletterSender` with queue table and batch processing via Workerman Timer.
5. Write tests using mocks.
6. Verify: PHPUnit green, PHPStan level 9, PHPCS clean.
7. Commit + PR + merge.

## 5. Tests (REQUIRED — minimum bar)

1. `NewsletterGeneratorTest::test_generate_for_user_returns_email_content`
2. `NewsletterGeneratorTest::test_generate_includes_watch_time`
3. `NewsletterGeneratorTest::test_generate_includes_top_media`
4. `NewsletterGeneratorTest::test_get_recipient_user_ids`
5. `NewsletterSenderTest::test_queue_all_creates_queue_entries`
6. `NewsletterSenderTest::test_process_queue_sends_emails`
7. `NewsletterSenderTest::test_get_delivery_stats`

## 6. Acceptance Criteria

- [ ] `NewsletterGenerator::generateForUser()` produces `subject`, `html_body`, `plain_text`, `week_watch_time_minutes`, `new_items_count`, `top_media` fields.
- [ ] `NewsletterGenerator::getRecipientUserIds()` returns users who logged in within last 30 days.
- [ ] `NewsletterSender::queueAll()` inserts one row per user into `newsletter_queue` with `status=pending`.
- [ ] `NewsletterSender::processQueue()` processes up to `batch_size` pending rows, marks them `sent` or `failed`.
- [ ] `newsletter_queue` table: id, user_id, week_start, status, attempts, sent_at, error_message.
- [ ] Smarty template at `public/templates/emails/newsletter.tpl` with HTML email layout.
- [ ] Config `config/newsletter.php` with `enabled`, `send_day`, `send_hour`, `batch_size`, `from_email`.
- [ ] Workerman Timer schedules newsletter send at configured day/hour.
- [ ] ≥ 7 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b l.5-newsletter
# ... implement ...
./vendor/bin/phpunit tests/Unit/Admin/
./vendor/bin/phpstan analyze src/Admin --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/Admin/
git add -A
git commit -m "Step L.5: Weekly newsletter email"
unset GITHUB_TOKEN
gh pr create --title "Step L.5: Weekly newsletter email" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `l.5-newsletter-review.md`.

(End of file - total 146 lines)
