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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if ($email === '') {
        $error = 'Recipient email is required.';
    } else {
        $slug = null;
        for ($i = 0; $i < 10; $i++) {
            $candidate = generate_slug($doc['title']);
            $check = db()->prepare('SELECT id FROM shares WHERE slug = ?');
            $check->execute([$candidate]);
            if (!$check->fetch()) {
                $slug = $candidate;
                break;
            }
        }

        $token = random_token();
        $stmt = db()->prepare('
            INSERT INTO shares (document_id, token, recipient_email, slug)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$doc['id'], $token, $email, $slug]);
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
        <button type="submit" class="btn">Generate link</button>
    </form>
</section>

<?php render_footer(); ?>
