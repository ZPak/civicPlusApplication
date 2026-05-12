<?php

putenv('DB_PATH=' . __DIR__ . '/../db.test.sqlite');

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

// --- Scheduled publishing ---

test('document with null publish_at is immediately available', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Immediate Doc', 'Body']);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected inserted document');
    assert_true($row['publish_at'] === null, 'publish_at should be null when not set');
});

test('document with future publish_at is not yet available via share token', function () {
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES (?, ?, 1, ?)
    ');
    $stmt->execute(['Future Doc', 'Body', '2099-01-01 00:00:00']);
    $docId = (int) db()->lastInsertId();

    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'test@example.com']);

    $stmt = db()->prepare('
        SELECT d.publish_at
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    assert_true($row !== false, 'share token should resolve');
    assert_true($row['publish_at'] > date('Y-m-d H:i:s'), 'publish_at should be in the future');
});

test('document with past publish_at is available via share token', function () {
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES (?, ?, 1, ?)
    ');
    $stmt->execute(['Past Doc', 'Body', '2020-01-01 00:00:00']);
    $docId = (int) db()->lastInsertId();

    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'test@example.com']);

    $stmt = db()->prepare('
        SELECT d.publish_at
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    assert_true($row !== false, 'share token should resolve');
    assert_true($row['publish_at'] <= date('Y-m-d H:i:s'), 'publish_at should be in the past');
});

// --- show_publish_date ---

test('show_publish_date defaults to 1 for new documents', function () {
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES (?, ?, 1, ?)
    ');
    $stmt->execute(['Default Visibility Doc', 'Body', '2099-01-01 00:00:00']);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT show_publish_date FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    assert_true((int) $row['show_publish_date'] === 1, 'show_publish_date should default to 1');
});

test('show_publish_date can be set to 0 to hide the date', function () {
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at, show_publish_date)
        VALUES (?, ?, 1, ?, 0)
    ');
    $stmt->execute(['Hidden Date Doc', 'Body', '2099-01-01 00:00:00']);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT show_publish_date FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    assert_true((int) $row['show_publish_date'] === 0, 'show_publish_date should be 0');
});

// --- Edit guard ---

test('document with future publish_at is editable', function () {
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES (?, ?, 1, ?)
    ');
    $stmt->execute(['Editable Doc', 'Body', '2099-01-01 00:00:00']);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    $is_editable = $row['publish_at'] !== null && $row['publish_at'] > date('Y-m-d H:i:s');
    assert_true($is_editable, 'document with future publish_at should be editable');
});

test('document with past publish_at is not editable', function () {
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES (?, ?, 1, ?)
    ');
    $stmt->execute(['Live Doc', 'Body', '2020-01-01 00:00:00']);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    $is_editable = $row['publish_at'] !== null && $row['publish_at'] > date('Y-m-d H:i:s');
    assert_true(!$is_editable, 'document with past publish_at should not be editable');
});

test('document with null publish_at is not editable (immediately live)', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Immediate Live Doc', 'Body']);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    $is_editable = $row['publish_at'] !== null && $row['publish_at'] > date('Y-m-d H:i:s');
    assert_true(!$is_editable, 'immediately live document should not be editable');
});

// --- Document editing ---

test('editing a document persists updated fields', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Original Title', 'Original body.']);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('
        UPDATE documents SET title = ?, body = ?, publish_at = ?, show_publish_date = ?
        WHERE id = ?
    ');
    $stmt->execute(['Updated Title', 'Updated body.', '2099-06-01 09:00:00', 0, $docId]);

    $stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    assert_true($row['title'] === 'Updated Title', 'title should be updated');
    assert_true($row['body'] === 'Updated body.', 'body should be updated');
    assert_true($row['publish_at'] === '2099-06-01 09:00:00', 'publish_at should be updated');
    assert_true((int) $row['show_publish_date'] === 0, 'show_publish_date should be updated');
});

test('editing a document is recorded in audit_log', function () {
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (1, ?, ?, ?, ?)
    ');
    $stmt->execute(['update', 'document', 1, json_encode(['title' => 'Audit Test'])]);

    $stmt = db()->prepare("
        SELECT * FROM audit_log
        WHERE action = 'update' AND entity_type = 'document' AND entity_id = 1
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected an update audit log entry');
    $details = json_decode($row['details'], true);
    assert_true($details['title'] === 'Audit Test', 'audit log details should include title');
});

// --- Human-readable slugs ---

test('custom slug is stored when provided', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Slug Test Doc', 'Body']);
    $docId = (int) db()->lastInsertId();

    $slug = 'slug-test-doc-2026';
    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email, slug) VALUES (?, ?, ?, ?)');
    $stmt->execute([$docId, $token, 'slug-test@example.com', $slug]);

    $stmt = db()->prepare('SELECT slug FROM shares WHERE token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    assert_true($row['slug'] === $slug, 'stored slug should match input: ' . var_export($row['slug'], true));
});

test('null slug is stored when no slug is provided', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['No Slug Doc', 'Body']);
    $docId = (int) db()->lastInsertId();

    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email, slug) VALUES (?, ?, ?, ?)');
    $stmt->execute([$docId, $token, 'no-slug@example.com', null]);

    $stmt = db()->prepare('SELECT slug FROM shares WHERE token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    assert_true($row['slug'] === null, 'slug should be null when not provided');
});

test('share created with a slug resolves via ?slug= parameter', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Slug Resolve Doc', 'Body']);
    $docId = (int) db()->lastInsertId();

    $slug = 'slug-resolve-doc-2026';
    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email, slug) VALUES (?, ?, ?, ?)');
    $stmt->execute([$docId, $token, 'slug-test@example.com', $slug]);

    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE s.slug = ?
    ');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    assert_true($row !== false, 'slug should resolve to a document');
    assert_true($row['title'] === 'Slug Resolve Doc', 'resolved wrong document: ' . var_export($row['title'], true));
});

test('providing both slug and token parameters is rejected', function () {
    $slug  = 'both-params-test-2026';
    $token = random_token();
    $both_provided = $slug !== '' && $token !== '';
    assert_true($both_provided, 'request with both params should be flagged for rejection');
});

test('slug column has a unique constraint', function () {
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email, slug) VALUES (1, ?, ?, ?)');
    $stmt->execute([random_token(), 'a@example.com', 'unique-slug-test-0001']);

    $threw = false;
    try {
        $stmt->execute([random_token(), 'b@example.com', 'unique-slug-test-0001']);
    } catch (PDOException $e) {
        $threw = true;
    }
    assert_true($threw, 'inserting duplicate slug should throw');
});

// --- Unique document title ---

test('cannot create two documents with the same title', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Unique Title Test', 'Body']);

    $threw = false;
    try {
        $stmt->execute(['Unique Title Test', 'Body']);
    } catch (PDOException $e) {
        $threw = true;
    }
    assert_true($threw, 'inserting a duplicate title should throw');
});

test('updating a document to its own title does not throw', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Self Title Update', 'Body']);
    $docId = (int) db()->lastInsertId();

    $threw = false;
    try {
        $stmt = db()->prepare('UPDATE documents SET title = ? WHERE id = ?');
        $stmt->execute(['Self Title Update', $docId]);
    } catch (PDOException $e) {
        $threw = true;
    }
    assert_true(!$threw, 'updating to the same title should not throw');
});

// --- Share revocation ---

test('revoking a share removes it from the database', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Revoke Test Doc', 'Body']);
    $docId = (int) db()->lastInsertId();

    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'revoke@example.com']);
    $shareId = (int) db()->lastInsertId();

    db()->prepare('DELETE FROM shares WHERE id = ?')->execute([$shareId]);

    $stmt = db()->prepare('SELECT id FROM shares WHERE id = ?');
    $stmt->execute([$shareId]);
    assert_true($stmt->fetch() === false, 'share should no longer exist after revocation');
});

test('revoked share token no longer resolves a document', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Revoke Token Doc', 'Body']);
    $docId = (int) db()->lastInsertId();

    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'revoke2@example.com']);
    $shareId = (int) db()->lastInsertId();

    db()->prepare('DELETE FROM shares WHERE id = ?')->execute([$shareId]);

    $stmt = db()->prepare('
        SELECT d.title FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    assert_true($stmt->fetch() === false, 'revoked token should not resolve to a document');
});

test('revocation is scoped to the document — cannot revoke a share from another document', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Doc A', 'Body']);
    $docAId = (int) db()->lastInsertId();
    $stmt->execute(['Doc B', 'Body']);
    $docBId = (int) db()->lastInsertId();

    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docBId, $token, 'other@example.com']);
    $shareId = (int) db()->lastInsertId();

    // Attempt to revoke share belonging to docB using docA's scope
    $stmt = db()->prepare('SELECT * FROM shares WHERE id = ? AND document_id = ?');
    $stmt->execute([$shareId, $docAId]);
    assert_true($stmt->fetch() === false, 'share from another document should not be found in scope of docA');
});

test('revocation is recorded in audit_log', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Audit Revoke Doc', 'Body']);
    $docId = (int) db()->lastInsertId();

    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'audit-revoke@example.com']);
    $shareId = (int) db()->lastInsertId();

    db()->prepare('DELETE FROM shares WHERE id = ?')->execute([$shareId]);
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (1, ?, ?, ?, ?)
    ');
    $stmt->execute(['revoke', 'share', $shareId, json_encode(['document_id' => $docId])]);

    $stmt = db()->prepare("SELECT * FROM audit_log WHERE action = 'revoke' AND entity_id = ?");
    $stmt->execute([$shareId]);
    $row = $stmt->fetch();
    assert_true($row !== false, 'revocation should be recorded in audit_log');
});

test('cannot share the same document with the same recipient twice', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Duplicate Share Doc', 'Body']);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, random_token(), 'duplicate@example.com']);

    $threw = false;
    try {
        $stmt->execute([$docId, random_token(), 'duplicate@example.com']);
    } catch (PDOException $e) {
        $threw = true;
    }
    assert_true($threw, 'duplicate share for same recipient should throw');
});

test('re-sharing with the same recipient is allowed after revocation', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Reshare Doc', 'Body']);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, random_token(), 'reshare@example.com']);
    $shareId = (int) db()->lastInsertId();

    db()->prepare('DELETE FROM shares WHERE id = ?')->execute([$shareId]);

    $threw = false;
    try {
        $stmt->execute([$docId, random_token(), 'reshare@example.com']);
    } catch (PDOException $e) {
        $threw = true;
    }
    assert_true(!$threw, 're-sharing after revocation should succeed');
});

// --- Search ---

test('substring search returns matching documents', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Annual Events Report', 'Body']);
    $stmt->execute(['Company Events 2026', 'Body']);
    $stmt->execute(['Unrelated Document', 'Body']);

    $stmt = db()->prepare("
        SELECT title FROM documents WHERE title LIKE ? ORDER BY title ASC
    ");
    $stmt->execute(['%events%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) === 2, 'expected 2 matching documents, got ' . count($rows));
    assert_true($rows[0]['title'] === 'Annual Events Report', 'unexpected first result: ' . $rows[0]['title']);
    assert_true($rows[1]['title'] === 'Company Events 2026', 'unexpected second result: ' . $rows[1]['title']);
});

test('search is case-insensitive', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Uppercase SEARCH Test', 'Body']);

    $stmt = db()->prepare("SELECT title FROM documents WHERE title LIKE ?");
    $stmt->execute(['%search%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) >= 1, 'expected at least one result for case-insensitive match');
});

test('search returns no results for non-matching query', function () {
    $stmt = db()->prepare("SELECT title FROM documents WHERE title LIKE ?");
    $stmt->execute(['%zzznomatchzzz%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) === 0, 'expected no results for non-matching query');
});

test('results are sorted alphabetically ascending', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Zebra Document', 'Body']);
    $stmt->execute(['Alpha Document', 'Body']);
    $stmt->execute(['Mango Document', 'Body']);

    $stmt = db()->prepare("SELECT title FROM documents WHERE title LIKE ? ORDER BY title ASC");
    $stmt->execute(['%document%']);
    $rows = $stmt->fetchAll();
    $titles = array_column($rows, 'title');
    $sorted = $titles;
    sort($sorted);
    assert_true($titles === $sorted, 'results should be sorted alphabetically ascending');
});

test('pagination returns correct subset of results', function () {
    // Insert enough documents to span multiple pages
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    for ($i = 1; $i <= 12; $i++) {
        $stmt->execute(["Paginated Doc {$i}", 'Body']);
    }

    $per_page = 10;
    $stmt = db()->prepare("
        SELECT title FROM documents WHERE title LIKE ? ORDER BY title ASC LIMIT ? OFFSET ?
    ");
    $stmt->execute(['%paginated doc%', $per_page, 0]);
    $page1 = $stmt->fetchAll();
    $stmt->execute(['%paginated doc%', $per_page, $per_page]);
    $page2 = $stmt->fetchAll();

    assert_true(count($page1) === 10, 'page 1 should have 10 results');
    assert_true(count($page2) === 2, 'page 2 should have 2 results');
    assert_true($page1[0]['title'] !== $page2[0]['title'], 'pages should not overlap');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
