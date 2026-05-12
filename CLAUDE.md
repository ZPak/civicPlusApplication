# Folio Take-Home Interview Task

## What this app is

A document-sharing tool. 
 - Create documents via `/admin.php`. 
 - Generate share links via `/share.php`. 
 - View documents via `/view.php?token=<hex>`.
No auth beyond the hardcoded staff row (`id=1`).

## Stack

- PHP 8.3, no framework, no Composer (no composer.json exists — adding it is possible but out of scope)
- SQLite via PDO (`db.sqlite` in project root)
- Plain HTML + CSS, no JS build step
- Docker (PHP built-in server on port 8000)

## Running things

```bash
# Start the app (re-seeds db from scratch each time)
docker compose up

# Run tests
docker compose exec app php tests/test.php

# Run a one-off PHP script inside the container
docker compose exec app php seed.php
```

## Key files

| File | Purpose |
|------|---------|
| `lib/bootstrap.php` | DB connection, `current_staff()`, `audit_log()`, `random_token()`, `h()` |
| `lib/layout.php` | `render_header(string $title, ?array $staff)` and `render_footer()` |
| `schema.sql` | Canonical schema — do not edit directly for migrations |
| `seed.php` | Drops and recreates `db.sqlite`, runs `schema.sql`, inserts fixture data |
| `public/admin.php` | Document list + creation form |
| `public/share.php` | Create share link for a document |
| `public/view.php` | Recipient view, looked up by token |
| `tests/test.php` | Test runner — see pattern below |

## Coding conventions

**DB access** — always use `db()` (returns singleton PDO) with prepared statements and positional `?` placeholders:
```php
$stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();           // single row
$rows = $stmt->fetchAll();       // multiple rows
```

**Output escaping** — always use `h()` for any user-controlled string in HTML:
```php
<?= h($doc['title']) ?>
```

**Audit logging** — call `audit_log()` after every create/update/delete that changes meaningful state:
```php
audit_log('create', 'document', $docId, ['title' => $title]);
audit_log('create', 'share', $shareId, ['document_id' => $doc['id'], 'recipient_email' => $email]);
```
Signature: `audit_log(string $action, string $entity_type, int $entity_id, array $details = [])`

**Page structure** — every public page follows this pattern:
```php
<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();   // omit on recipient-facing pages
// ... logic ...
render_header('Page Title', $staff);
?>
<!-- HTML content -->
<?php render_footer(); ?>
```

**POST/redirect** — forms POST to themselves; on success, redirect with `header('Location: ...')` + `exit`.

**Error display** — use `$error = null` pattern; render via `<div class="banner banner-error"><?= h($error) ?></div>`.

**404 handling**:
```php
http_response_code(404);
render_header('Not found');
// message markup
render_footer();
exit;
```

## Schema migration approach

`schema.sql` is the baseline (do not edit it). Add new migration files — e.g. `migrations/001_add_publish_at.sql` — and apply them in `seed.php` after the base schema is loaded:
```php
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
$pdo->exec(file_get_contents(__DIR__ . '/migrations/001_add_publish_at.sql'));
```
This keeps `docker compose up` working from a fresh clone: seed.php always rebuilds from scratch in the correct order.

## Test pattern

At least one test must be added every time a new feature is built out. If possible, add multiple tests to try and cover a wide variety of use cases and edge cases. Ensure that tests are unit tests and avoid sharing contexts with another test.

Add tests to `tests/test.php` using the `test()` / `assert_true()` helpers already defined there:
```php
test('description of what is being checked', function () {
    $stmt = db()->prepare('...');
    $stmt->execute([...]);
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected a row');
    assert_true($row['column'] === 'expected', 'got: ' . var_export($row['column'], true));
});
```
Tests run against the live `db.sqlite` after seed; each `docker compose exec app php tests/test.php` re-seeds first.

## SQLite notes

- Datetime values are stored as TEXT in ISO 8601 format: `'2026-05-12 14:00:00'`
- `datetime('now')` returns UTC; the app sets timezone to `America/Chicago` via `date_default_timezone_set`
- For scheduled publishing, compare with `datetime('now')` in SQL or PHP's `time()` — be consistent
- No `ALTER TABLE ADD COLUMN` limitations (SQLite supports it for nullable or default-having columns)
- Foreign keys are OFF by default in SQLite; `bootstrap.php` enables them via `PRAGMA foreign_keys = ON`

## What is NOT in scope

- Auth (staff is always row `id=1`)
- Email delivery (share links are just displayed on screen)
- Pagination
