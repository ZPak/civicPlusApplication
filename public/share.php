<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$docId = (int) ($_GET['doc'] ?? 0);
$stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    render_header('Not found', $staff);
    ?>
    <div class="banner banner-error">Document not found.</div>
    <p><a href="/admin.php" class="back-link">← back to admin</a></p>
    <?php
    render_footer();
    exit;
}

$error = null;
$created_token = null;
$created_slug = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke') {
    $shareId = (int) ($_POST['share_id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM shares WHERE id = ? AND document_id = ?');
    $stmt->execute([$shareId, $doc['id']]);
    $share = $stmt->fetch();
    if ($share) {
        db()->prepare('DELETE FROM shares WHERE id = ?')->execute([$shareId]);
        audit_log('revoke', 'share', $shareId, [
            'document_id' => $doc['id'],
            'recipient_email' => $share['recipient_email'],
        ]);
        flash('Share link revoked.');
    }
    header('Location: /share.php?doc=' . $doc['id']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $slug_input = trim($_POST['slug'] ?? '');
    $slug = $slug_input !== '' ? $slug_input : null;

    $existing = db()->prepare('SELECT id FROM shares WHERE document_id = ? AND recipient_email = ?');
    $existing->execute([$doc['id'], $email]);

    if ($email === '') {
        $error = 'Recipient email is required.';
    } elseif ($existing->fetch()) {
        $error = 'A share link already exists for this recipient. Revoke the existing link before creating a new one.';
    } elseif ($slug !== null && !preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $slug)) {
        $error = 'Slug must contain only lowercase letters, numbers, and hyphens, and must not start or end with a hyphen.';
    } elseif ($slug !== null && strlen($slug) < SLUG_MIN_LENGTH) {
        $error = 'Slug must be at least ' . SLUG_MIN_LENGTH . ' characters.';
    } else {
        $token = random_token();
        $stmt = db()->prepare('
            INSERT INTO shares (document_id, token, recipient_email, slug)
            VALUES (?, ?, ?, ?)
        ');
        try {
            $stmt->execute([$doc['id'], $token, $email, $slug]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed: shares.slug')) {
                $error = 'That slug is already in use. Please choose a different one.';
            } elseif (str_contains($e->getMessage(), 'idx_shares_document_recipient')) {
                $error = 'A share link already exists for this recipient. Revoke the existing link before creating a new one.';
            } else {
                throw $e;
            }
        }

        if (!$error) {
            $shareId = (int) db()->lastInsertId();
            audit_log('create', 'share', $shareId, [
                'document_id' => $doc['id'],
                'recipient_email' => $email,
                'slug' => $slug,
            ]);
            $created_token = $token;
            $created_slug = $slug;
        }
    }
}

$stmt = db()->prepare('SELECT * FROM shares WHERE document_id = ? ORDER BY created_at DESC');
$stmt->execute([$doc['id']]);
$shares = $stmt->fetchAll();

render_header('Share · ' . $doc['title'], $staff);
?>

<a href="/admin.php" class="back-link">← back to admin</a>

<h1 class="page-title">Share "<?= h($doc['title']) ?>"</h1>
<p class="page-subtitle">Generate a share link for a recipient.</p>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<?php if ($created_token): ?>
    <div class="banner banner-success">
        <?php if ($created_slug): ?>
            <strong>Token link</strong> (private, always works):<br>
            <code>http://<?= h($_SERVER['HTTP_HOST']) ?>/view.php?token=<?= h($created_token) ?></code><br><br>
            <strong>Readable link</strong> (optional — use in place of the token if a human-friendly URL is preferable):<br>
            <code>http://<?= h($_SERVER['HTTP_HOST']) ?>/view.php?slug=<?= h($created_slug) ?></code>
        <?php else: ?>
            <strong>Token link</strong> (private, always works):<br>
            <code>http://<?= h($_SERVER['HTTP_HOST']) ?>/view.php?token=<?= h($created_token) ?></code>
        <?php endif ?>
    </div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">Create share link</h2>
    <form method="post">
        <div class="form-field">
            <label for="email">Recipient email</label>
            <input type="email" id="email" name="email" required>
        </div>
        <div class="form-field">
            <label for="slug">Readable slug (optional)</label>
            <input type="text" id="slug" name="slug" value="<?= isset($slug_input) ? h($slug_input) : '' ?>" placeholder="e.g. welcome-packet-2026">
            <small>Lowercase letters, numbers, and hyphens only. Minimum <?= SLUG_MIN_LENGTH ?> characters. If omitted, only the token link is generated.</small>
        </div>
        <button type="submit" class="btn">Generate link</button>
    </form>
</section>

<?php if (!empty($shares)): ?>
<section class="card">
    <h2 class="card-title">Active share links</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Recipient</th>
                <th>Slug</th>
                <th>Created</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($shares as $s): ?>
                <tr>
                    <td><?= h($s['recipient_email']) ?></td>
                    <td><?= $s['slug'] ? h($s['slug']) : '<em>none</em>' ?></td>
                    <td><?= h($s['created_at']) ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Revoke this share link? The recipient will no longer be able to access the document.')">
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="share_id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" class="btn-link">Revoke</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</section>
<?php endif ?>

<?php render_footer(); ?>
