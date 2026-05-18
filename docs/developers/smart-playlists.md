# Smart Playlists Developer Guide

**Since:** 0.14.0
**Phase:** H.1 (Smart Features)

Smart playlists auto-populate based on JSON DSL rules evaluated against the media library at scan time and on folder-watch events. Unlike manual playlists, smart playlists dynamically update as content changes.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Smart Playlist System                     │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌─────────────┐    ┌──────────────┐    ┌────────────────┐  │
│  │  Router     │───▶│ Controller   │───▶│ Repository     │  │
│  │  (HTTP API) │    │ (CRUD+       │    │ (MySQL CRUD)   │  │
│  │             │    │  Preview)    │    │                │  │
│  └─────────────┘    └──────────────┘    └────────────────┘  │
│                            │                    │            │
│                            ▼                    │            │
│                     ┌──────────────┐            │            │
│                     │   Engine      │◀───────────┘            │
│                     │ (Evaluate    │                         │
│                     │  Rules)       │                         │
│                     └──────────────┘                         │
│                            ▲                                 │
│                            │                                 │
│  ┌─────────────┐    ┌──────────────┐    ┌────────────────┐  │
│  │ Folder      │───▶│ Refresh      │───▶│ Listener       │  │
│  │ Watcher     │    │ Handler      │    │ Registry       │  │
│  │             │    │              │    │ (PSR-14)       │  │
│  └─────────────┘    └──────────────┘    └────────────────┘  │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

## JSON DSL Rule Format

The rule DSL mirrors Plex/Emby's smart playlist structure for familiarity:

```json
{
  "logic": "and",
  "rules": [
    { "field": "genre", "op": "contains", "value": "Drama" },
    { "field": "year", "op": "gt", "value": 2010 },
    {
      "logic": "or",
      "rules": [
        { "field": "rating", "op": "gte", "value": 8.0 },
        { "field": "criticScore", "op": "gte", "value": 85 }
      ]
    }
  ]
}
```

### Field Names

Fields map to `metadata_json` keys in media items:
- `genre` - Genre(s) as string or array
- `year` - Release year
- `rating` - Content rating (G, PG, etc.)
- `criticScore` - Critic review score
- `title` - Media title
- `studio` - Production studio
- `director` - Director name
- `actor` - Actor names
- `addedAt` - Library add timestamp

## Operators

| Operator | Description | Value Type |
|----------|-------------|------------|
| `equals` | Exact match (case-sensitive) | string, int, float |
| `notEquals` | Not equal | string, int, float |
| `contains` | Substring match | string |
| `notContains` | No substring | string |
| `gt` | Greater than | int, float |
| `gte` | Greater than or equal | int, float |
| `lt` | Less than | int, float |
| `lte` | Less than or equal | int, float |
| `between` | Range (inclusive) | array [lo, hi] |
| `in` | Set membership | array |
| `notIn` | Not in set | array |
| `startsWith` | String prefix | string |
| `endsWith` | String suffix | string |

## Adding New Operators

To add a new operator:

1. **Add method to `RuleOperators`:**
   ```php
   public static function myOperator(mixed $itemValue, mixed $ruleValue): bool
   {
       // Implementation
   }
   ```

2. **Register in `SmartPlaylistEngine::evaluateRule`:**
   ```php
   'myOperator' => RuleOperators::myOperator($itemValue, $ruleValue),
   ```

3. **Add tests to `RuleOperatorsTest`**

4. **Update this documentation**

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/smart-playlists` | List all smart playlists |
| POST | `/api/v1/smart-playlists` | Create smart playlist |
| GET | `/api/v1/smart-playlists/{id}` | Get playlist details |
| PUT | `/api/v1/smart-playlists/{id}` | Update playlist |
| DELETE | `/api/v1/smart-playlists/{id}` | Delete playlist |
| POST | `/api/v1/smart-playlists/{id}/preview` | Preview rules against library |

### Create Example

```bash
curl -X POST /api/v1/smart-playlists \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Drama Collection",
    "library_id": "lib-123",
    "rules_json": "{\"logic\":\"and\",\"rules\":[{\"field\":\"genre\",\"op\":\"contains\",\"value\":\"Drama\"}]}",
    "limit": 20,
    "sort_by": "rating",
    "sort_desc": true
  }'
```

### Preview Example

```bash
curl -X POST /api/v1/smart-playlists/{id}/preview \
  -H "Content-Type: application/json" \
  -d '{
    "rules_json": "{\"logic\":\"and\",\"rules\":[{\"field\":\"year\",\"op\":\"gt\",\"value\":2020}]}",
    "limit": 10
  }'
```

## Database Schema

```sql
CREATE TABLE smart_playlists (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    library_id CHAR(36) NOT NULL,
    rules_json JSON NOT NULL,
    `limit` INT DEFAULT 0,
    sort_by VARCHAR(32) DEFAULT 'addedAt',
    sort_desc TINYINT(1) DEFAULT 1,
    created_at DATETIME,
    updated_at DATETIME,
    INDEX idx_smart_pl_library (library_id)
);
```

## Evaluation Algorithm

1. Parse JSON DSL into `RuleNode` tree via `buildFromDsl()`
2. For each media item in library:
   - Recursively evaluate node tree
   - AND nodes: all children must match
   - OR nodes: at least one child must match
   - NOT nodes: invert child result
   - RULE nodes: apply operator to field value
3. Apply sorting (random shuffles in place)
4. Apply limit (slice to N items)

## Events

### LibraryUpdated

Fired when folder watcher detects library changes:

```php
final class LibraryUpdated
{
    public function __construct(
        public readonly string $libraryId,
        public readonly string $path,
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {}
}
```

## Future: Collections (Phase H.2)

H.2 will introduce collections that can combine smart playlists and manual selections. The `SmartPlaylistChanged` event will be added for collection synchronization.

## Testing

Run all playlist tests:

```bash
./vendor/bin/phpunit --testsuite Unit tests/unit/Playlists/
./vendor/bin/phpunit tests/integration/Playlists/
```

Coverage target: ≥85% on `SmartPlaylistEngine`, `RuleNode`, `RuleOperators`, `SmartPlaylistRepository`.
