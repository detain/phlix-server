# Step L.5 — Weekly Newsletter Email: Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit tests/Unit/Admin/NewsletterGeneratorTest.php tests/Unit/Admin/NewsletterSenderTest.php
# MUST be green; ≥ 7 new tests

# ── 2. PHPStan level 9 ─────────────────────────────────────
./vendor/bin/phpstan analyze src/Admin/NewsletterGenerator.php src/Admin/NewsletterSender.php --level=9 --no-progress
# Zero errors

# ── 3. PHPCS PSR-12 ──────────────────────────────────────────
./vendor/bin/phpcs --standard=PSR12 src/Admin/NewsletterGenerator.php src/Admin/NewsletterSender.php
# Clean

# ── 4. Syntax check ─────────────────────────────────────────
find src/Admin -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Empty output

# ── 5. Migration check ──────────────────────────────────────
ls migrations/020_newsletter.sql
# File must exist
```

## Acceptance Criteria

- [ ] `NewsletterGenerator::generateForUser()` returns array with `subject`, `html_body`, `plain_text`, `week_watch_time_minutes`, `new_items_count`, `top_media` (array of up to 5 items with title + poster).
- [ ] `generateForUser()` queries `StatsCollector` for watch time and `LibraryManager` for new items this week.
- [ ] `generateForUser()` includes top 5 most-played media items for the user this week.
- [ ] `NewsletterGenerator::getRecipientUserIds()` returns only users with login within last 30 days.
- [ ] `NewsletterSender::queueAll()` creates one `newsletter_queue` row per recipient with `status='pending'`.
- [ ] `NewsletterSender::processQueue($batchSize)` processes up to `$batchSize` pending rows per call.
- [ ] `processQueue()` calls `NewsletterGenerator::generateForUser()` for each row and sends email.
- [ ] On success: `status='sent'`, `sent_at=NOW()`.
- [ ] On failure: `status='failed'`, `error_message` stored, `attempts` incremented.
- [ ] `newsletter_queue` table has all required columns.
- [ ] Smarty template `public/templates/emails/newsletter.tpl` produces HTML email with watch time, top media, new items, CTA button, unsubscribe link.
- [ ] Config `config/newsletter.php` with all settings (`enabled`, `send_day`, `send_hour`, `batch_size`, `from_email`, `from_name`, `subject_template`).
- [ ] Workerman Timer registered to trigger `processQueue()` at configured day/hour (e.g., every Sunday at 9am).
- [ ] ≥ 7 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

(End of file - total 45 lines)
