# Monica CRM – Tag System Extension

## Base Version

These changes were developed on top of Monica CRM (https://github.com/monicahq/monica).

Apply these files to a fresh Monica installation to run the feature.

---

## Table of Contents
- [Setup](#setup)
- [Changed Files](#changed-files)
- [My Approach](#my-approach)
- [What Changed & Why](#what-changed--why)
  - [1. Database & Migration](#1-database--migration)
  - [2. Models](#2-models)
  - [3. Services (Business Logic)](#3-services-business-logic)
  - [4. API Endpoints](#4-api-endpoints)
  - [5. Caching Strategy](#5-caching-strategy)
  - [6. Tests](#6-tests)
- [API Reference](#api-reference)
- [SQL: AND-filter explained](#sql-and-filter-explained)
- [Assumptions & Trade-offs](#assumptions--trade-offs)

## Changed Files

### New Files
| File | Purpose |
|---|---|
| `database/migrations/2026_06_05_000001_add_category_and_color_to_tags_table.php` | Adds `tag_category` and `color` columns to the existing `tags` table |
| `database/migrations/2026_06_05_000002_create_taggables_table.php` | Creates the polymorphic `taggables` pivot table with indexes |
| `app/Models/Taggable.php` | Explicit pivot model for the `taggables` table |
| `app/Domains/Contact/ManageTags/Services/CreateTag.php` | Service: create a tag |
| `app/Domains/Contact/ManageTags/Services/UpdateTag.php` | Service: update a tag |
| `app/Domains/Contact/ManageTags/Services/DestroyTag.php` | Service: delete a tag (with optional reassign) |
| `app/Domains/Contact/ManageTags/Services/AttachTagsToContact.php` | Service: attach one or more tags to a contact |
| `app/Domains/Contact/ManageTags/Services/DetachTagFromContact.php` | Service: remove a tag from a contact |
| `app/Domains/Contact/ManageTags/Api/Controllers/TagController.php` | API controller: tag CRUD with Redis caching |
| `app/Domains/Contact/ManageTags/Api/Controllers/ContactTagController.php` | API controller: contact-tag attach/detach + filtered list |
| `app/Http/Resources/TagResource.php` | JSON response shape for a tag |
| `app/Http/Resources/ContactResource.php` | JSON response shape for a contact (includes tags) |
| `tests/Feature/Tags/TagSystemTest.php` | 4 required feature tests |

### Modified Files
| File | What changed |
|---|---|
| `app/Models/Tag.php` | Added `tag_category`, `color` to `$fillable`; added `contacts()` and `taggables()` relationships |
| `app/Models/Contact.php` | Added `tags()` relationship and `scopeWithAllTags()` query scope |
| `routes/api.php` | Added 7 new API routes scoped under `vaults/{vaultId}` |

---

## My Approach

Before writing a single line of code I spent time reading the existing Monica codebase to understand its patterns:

| Pattern | Monica's approach |
|---|---|
| Business logic | `Services/` classes extending `BaseService` with `rules()` + `permissions()` |
| API responses | Thin controllers → `JsonResource` classes (consistent envelope) |
| Authorization | Permission constants on `Vault` model (`PERMISSION_EDIT`, etc.) |
| Pivot tables | Explicit pivot tables with `cascadeOnDelete` foreign keys |
| Testing | `TestCase` with `DatabaseTransactions` |

I mirrored every one of these patterns so my code looks as if it was written by the original team.

---

## What Changed & Why

### 1. Database & Migration

**Files:**
- `database/migrations/2026_06_05_000001_add_category_and_color_to_tags_table.php`
- `database/migrations/2026_06_05_000002_create_taggables_table.php`

#### What already existed
Monica already had a `tags` table with `id`, `vault_id`, `name`, `slug`, and `timestamps`. Tags were only used for journal **posts** via a `post_tag` pivot.

#### What I added

| Addition | Rationale |
|---|---|
| `tags.tag_category` (nullable string) | Allows users to group tags (Work, Personal …) without breaking existing rows |
| `tags.color` (nullable string) | Hex colour for frontend display |
| **`taggables` polymorphic pivot** | Single pivot for contacts *and* any future model (activities, notes). Each row: `tag_id`, `taggable_id`, `taggable_type` |
| Unique constraint on `(tag_id, taggable_id, taggable_type)` | Prevents duplicate tag attachments at the DB level |
| Index on `(tag_id, taggable_type)` | Speeds up "find all contacts with a given tag" and "count tag usage" queries |
| `cascadeOnDelete` on the foreign key | Deleting a tag automatically removes all its pivot rows — no PHP loop needed |

> **Why a polymorphic pivot and not `contact_tag`?**
> The brief explicitly states "the schema should support tagging activities in the future." A polymorphic pivot (`taggables`) achieves this with *zero additional migrations* — just add a `tags()` relationship to any future model.

---

### 2. Models

#### `App\Models\Tag` (modified)
- Added `tag_category` and `color` to `$fillable`
- Added `contacts()` relationship via the polymorphic pivot
- Added `taggables()` morphMany for raw pivot access

#### `App\Models\Taggable` (new)
- Explicit pivot model — useful for querying pivot rows directly (e.g. counting usage without loading related models)

#### `App\Models\Contact` (modified)
- Added `tags()` `BelongsToMany` via the `taggables` pivot
- Added `scopeWithAllTags(Builder $query, array $tagIds)` — AND-filter (see below)

---

### 3. Services (Business Logic)

All services live in `app/Domains/Contact/ManageTags/Services/` following Monica's domain structure.

| Service | Responsibility |
|---|---|
| `CreateTag` | Validates, slugifies name, persists |
| `UpdateTag` | Validates vault ownership, updates |
| `DestroyTag` | Validates, optionally reassigns contacts (single SQL INSERT…SELECT), then deletes |
| `AttachTagsToContact` | Validates tag IDs belong to the same vault, then `syncWithoutDetaching()` |
| `DetachTagFromContact` | Simple `detach()` on the pivot |

---

### 4. API Endpoints

Controllers in `app/Domains/Contact/ManageTags/Api/Controllers/`.

| Method | Path | Controller | Notes |
|---|---|---|---|
| `GET` | `/api/vaults/{vault}/tags` | `TagController@index` | Cached 10 min in Redis |
| `POST` | `/api/vaults/{vault}/tags` | `TagController@store` | Busts cache |
| `PUT` | `/api/vaults/{vault}/tags/{tag}` | `TagController@update` | Busts cache |
| `DELETE` | `/api/vaults/{vault}/tags/{tag}` | `TagController@destroy` | Supports `reassign_tag_id`; busts cache |
| `GET` | `/api/vaults/{vault}/contacts` | `ContactTagController@index` | Supports `?tags[]=1&tags[]=2` + `?sort=name` |
| `POST` | `/api/vaults/{vault}/contacts/{contact}/tags` | `ContactTagController@store` | Body: `{ "tag_ids": [1,2] }`; busts cache |
| `DELETE` | `/api/vaults/{vault}/contacts/{contact}/tags/{tag}` | `ContactTagController@destroy` | Busts cache |

---

### 5. Caching Strategy

```
Cache key:  tags.vault.{vault_id}
Driver:     Redis
TTL:        600 seconds (10 minutes)
```

#### When is the cache **written**?
`TagController::index` uses `Cache::remember()` — if the key exists the DB query is skipped entirely; if not, the result is computed and stored.

#### When is the cache **invalidated**?
`Cache::forget("tags.vault.{$vaultId}")` is called after every write operation that can change the tag list or its usage counts:

| Trigger | Invalidation point |
|---|---|
| Tag created | `TagController::store` after service call |
| Tag updated | `TagController::update` after service call |
| Tag deleted | `TagController::destroy` after service call |
| Tag attached to contact | `ContactTagController::store` after service call |
| Tag detached from contact | `ContactTagController::destroy` after service call |

---

### 6. Tests

**File:** `tests/Feature/Tags/TagSystemTest.php`

| Test | Scenario |
|---|---|
| `it_creates_a_tag_and_it_appears_in_the_tag_list` | Create → verify in list with `usage_count = 0` |
| `it_filters_contacts_by_all_specified_tags_using_and_logic` | Attach 2 tags to 1 contact; assert only that contact is returned |
| `it_detaches_tag_from_all_contacts_when_deleted` | Delete tag → assert both `tags` and `taggables` rows are gone |
| `it_invalidates_the_redis_cache_when_a_tag_is_created` | Seed stale cache → create tag → assert cache is cleared |

---

## API Reference

### JSON Envelope

All responses follow this consistent structure (matching Monica's existing API style):

```json
// Collection
{ "data": [ ... ] }

// Single resource
{ "data": { ... } }

// Delete
{ "deleted": true, "id": "123" }

// Error
{ "error": { "message": "...", "error_code": 32 } }
```

### Tag Resource shape

```json
{
  "id": 1,
  "name": "Colleague",
  "slug": "colleague",
  "tag_category": "Work",
  "color": "#3b82f6",
  "usage_count": 5,
  "created_at": 1717545600,
  "updated_at": 1717545600
}
```

---

## SQL: AND-filter explained

The query for "contacts with ALL specified tags" must not loop in application code. The scope I wrote produces this SQL:

```sql
SELECT *
FROM contacts
WHERE contacts.id IN (
    SELECT taggable_id
    FROM   taggables
    WHERE  taggable_type = 'App\\Models\\Contact'
    AND    tag_id IN (1, 2)
    GROUP  BY taggable_id
    HAVING COUNT(DISTINCT tag_id) = 2
)
```

**How it works:**
1. The subquery groups the `taggables` pivot by `taggable_id` (contact UUID).
2. `HAVING COUNT(DISTINCT tag_id) = N` keeps only contacts that have **every** tag in the list.
3. The outer query then filters `contacts` by those IDs — it is a single statement with no PHP loops.

---

## Assumptions & Trade-offs

| Assumption / Trade-off | Explanation |
|---|---|
| Tags scoped to vault, not account | Consistent with how Monica handles Labels; a tag from vault A cannot be applied to vault B |
| `color` stored as raw string | Could be a hex value `#3b82f6` or a Tailwind class — keeping it a string lets the frontend decide the format |
| `tag_category` is free-form | Not constrained to an enum; categories can be whatever the user types |
| Polymorphic pivot used instead of `contact_tag` | Future-proofs for activities without new schema |
| Cache key is vault-scoped | One vault's change doesn't evict another vault's cache |
| `INSERT IGNORE` used in reassign | Handles the edge case where the target tag is already on some contacts gracefully |
| No auth/middleware changes | All routes sit behind the existing `auth:sanctum` middleware |
