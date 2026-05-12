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

$is_editable = $doc['publish_at'] !== null && $doc['publish_at'] > date('Y-m-d H:i:s');

if (!$is_editable) {
    http_response_code(403);
    render_header('Not editable', $staff);
    ?>
    <div class="banner banner-error">This document is already live and cannot be edited.</div>
    <p><a href="/admin.php" class="back-link">← back to admin</a></p>
    <?php
    render_footer();
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $publish_at_raw = trim($_POST['publish_at'] ?? '');
    $publish_at = $publish_at_raw !== '' ? date('Y-m-d H:i:s', strtotime($publish_at_raw)) : null;
    $show_publish_date = isset($_POST['show_publish_date']) ? 1 : 0;

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        $stmt = db()->prepare('
            UPDATE documents SET title = ?, body = ?, publish_at = ?, show_publish_date = ?
            WHERE id = ?
        ');
        $stmt->execute([$title, $body, $publish_at, $show_publish_date, $doc['id']]);

        audit_log('update', 'document', $doc['id'], [
            'title' => $title,
            'publish_at' => $publish_at,
            'show_publish_date' => $show_publish_date,
        ]);

        header('Location: /admin.php?updated=' . $doc['id']);
        exit;
    }
}

// datetime-local input requires 'Y-m-dTH:i' format
$publish_at_input = $doc['publish_at'] ? date('Y-m-d\TH:i', strtotime($doc['publish_at'])) : '';

render_header('Edit · ' . $doc['title'], $staff);
?>

<a href="/admin.php" class="back-link">← back to admin</a>

<h1 class="page-title">Edit "<?= h($doc['title']) ?>"</h1>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" value="<?= h($doc['title']) ?>" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required><?= h($doc['body']) ?></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at (leave blank to publish immediately)</label>
            <input type="datetime-local" id="publish_at" name="publish_at" value="<?= h($publish_at_input) ?>">
        </div>
        <div class="form-field">
            <label>
                <input type="checkbox" name="show_publish_date" value="1" <?= $doc['show_publish_date'] ? 'checked' : '' ?>>
                Show publish date to recipients before the document is live
            </label>
        </div>
        <button type="submit" class="btn">Save changes</button>
    </form>
</section>

<?php render_footer(); ?>
