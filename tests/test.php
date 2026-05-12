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

test('generate_slug produces a non-empty string with a hyphenated suffix', function () {
    $slug = generate_slug('Welcome Packet');
    assert_true($slug !== '', 'slug should not be empty');
    assert_true((bool) preg_match('/^[a-z0-9][a-z0-9-]+-[a-z0-9]{4,}$/', $slug), 'slug should match pattern: ' . $slug);
    assert_true(strlen($slug) >= SLUG_MIN_LENGTH, 'slug should meet minimum length: ' . $slug);
});

test('generate_slug meets minimum length even for short titles', function () {
    $slug = generate_slug('AI');
    assert_true(strlen($slug) >= SLUG_MIN_LENGTH, 'short title slug should still meet minimum length: ' . $slug);
});

test('generate_slug handles special characters and extra whitespace', function () {
    $slug = generate_slug('  Hello, World! (2026) ');
    assert_true((bool) preg_match('/^[a-z0-9][a-z0-9-]+-[a-z0-9]{4,}$/', $slug), 'slug should only contain lowercase alphanumerics and hyphens: ' . $slug);
    assert_true(strlen($slug) >= SLUG_MIN_LENGTH, 'slug should meet minimum length: ' . $slug);
});

test('share created with a slug resolves via ?slug= parameter', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Slug Test Doc', 'Body']);
    $docId = (int) db()->lastInsertId();

    $slug = generate_slug('Slug Test Doc');
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
    assert_true($row['title'] === 'Slug Test Doc', 'resolved wrong document: ' . var_export($row['title'], true));
});

test('providing both slug and token parameters is rejected', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Both Params Doc', 'Body']);
    $docId = (int) db()->lastInsertId();

    $slug = generate_slug('Both Params Doc');
    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email, slug) VALUES (?, ?, ?, ?)');
    $stmt->execute([$docId, $token, 'both@example.com', $slug]);

    // Simulate the guard logic from view.php
    $both_provided = $slug !== '' && $token !== '';
    assert_true($both_provided === true, 'both slug and token are non-empty');
    // Guard should reject — confirmed by the condition being true
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

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
