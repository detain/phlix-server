# Review: Step D.5 — Invite-link sharing

**Step:** D.5
**Plan file:** `d.5-invite-links.md`
**Target repos:** `detain/phlex-hub` (primary) + `detain/phlex-server`

## 1. Verify preconditions (hub)

```bash
cd /home/sites/phlex-hub
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'd.5-*'
```

## 2. Verify preconditions (server)

```bash
cd /home/sites/phlex
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'd.5-*'
```

## 3. Run the hub verification bar

```bash
cd /home/sites/phlex-hub
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'InviteLink'
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```

## 4. Check hub deliverables

- [ ] `src/Hub/InviteLink.php` DTO
- [ ] `src/Hub/InviteLinkHandler.php`
- [ ] `src/Server/Http/Controllers/InviteLinkController.php`
- [ ] `migrations/009_invite_links.sql`
- [ ] Routes: `/invite/{token}`, `/api/v1/me/invite-links` (POST/GET/DELETE)
- [ ] Smarty pages: `invite-link.tpl`, `accept-invite.tpl`
- [ ] `ProviderManager` on hub registers `InviteLinkHandler`
- [ ] `docs/hub/invite-links.md` created
- [ ] Hub CHANGELOG

## 5. Check server deliverables

- [ ] Server CHANGELOG has D.5 entry

## 6. Reject conditions

- Any test fails on hub
- PHPStan new errors on hub
- PHPCS errors on hub
- Coverage < 85 % on `InviteLinkHandler`
- Missing PHPDoc
- Server work missing
