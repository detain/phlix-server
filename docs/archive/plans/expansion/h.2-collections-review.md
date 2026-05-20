# Review: Step H.2 — Collections (manual + rule-based)

**Step:** H.2
**Plan file:** `h.2-collections.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show H.2 squashed commit
git branch --list 'h.2-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 15 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'CollectionManager|CollectionRepository|CollectionItemRepository'
# Expected: each ≥ 85%

# ─── Static analysis ───
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
# Expected: [OK] No errors

# ─── Code style ───
./vendor/bin/phpcs --standard=PSR12 src/
# Expected: clean (warnings OK, 0 errors)

# ─── Syntax ───
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Expected: empty output
```

## 3. Check deliverables

For each acceptance criterion in `h.2-collections.md` §7:

- [ ] `migrations/005_collections.sql` runs cleanly
- [ ] `Collection` entity with all fields (id, name, libraryId, smartPlaylistId, parentId, sortOrder, createdAt, updatedAt)
- [ ] `CollectionWithItems` DTO with hydrated items
- [ ] `CollectionRepository` full CRUD with parameterized queries
- [ ] `CollectionItemRepository` membership CRUD
- [ ] `CollectionManager::addItem()` / `removeItem()` working
- [ ] `CollectionManager::bulkAddFromSearch()` accepts array of IDs
- [ ] `CollectionManager::refreshSmartCollection()` re-evaluates engine and syncs diff
- [ ] All 9 REST endpoints wired in `CollectionController`
- [ ] `SmartPlaylistRefreshHandler` calls `CollectionManager::refreshSmartCollection()`
- [ ] `docs/developers/collections.md` written
- [ ] CHANGELOG has H.2 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-H.2 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.14.0`
- Coverage of any new class drops below 85%
- Smart collection refresh does a full wipe instead of diffing
- `bulkAddFromSearch` is not properly parameterized
