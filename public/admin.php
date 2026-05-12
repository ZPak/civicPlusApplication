<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
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
            INSERT INTO documents (title, body, created_by, publish_at, show_publish_date)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$title, $body, $staff['id'], $publish_at, $show_publish_date]);
        $docId = (int) db()->lastInsertId();

        audit_log('create', 'document', $docId, ['title' => $title, 'publish_at' => $publish_at, 'show_publish_date' => $show_publish_date]);

        header('Location: /admin.php?created=' . $docId);
        exit;
    }
}

$search   = trim($_GET['q'] ?? '');
$page     = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;
$like     = '%' . $search . '%';

$count_stmt = db()->prepare('
    SELECT COUNT(*) AS total
    FROM documents d
    WHERE d.title LIKE ?
');
$count_stmt->execute([$like]);
$total       = (int) $count_stmt->fetch()['total'];
$total_pages = max(1, (int) ceil($total / $per_page));
$page        = min($page, $total_pages);

$stmt = db()->prepare('
    SELECT d.*, s.name AS creator_name
    FROM documents d
    JOIN staff s ON s.id = d.created_by
    WHERE d.title LIKE ?
    ORDER BY d.title ASC
    LIMIT ? OFFSET ?
');
$stmt->execute([$like, $per_page, $offset]);
$docs = $stmt->fetchAll();

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document #<?= (int) $_GET['created'] ?> created.</div>
<?php endif ?>

<?php if (!empty($_GET['updated'])): ?>
    <div class="banner banner-success">Document #<?= (int) $_GET['updated'] ?> updated.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at (optional — leave blank to publish immediately)</label>
            <input type="datetime-local" id="publish_at" name="publish_at">
        </div>
        <div class="form-field">
            <label>
                <input type="checkbox" name="show_publish_date" value="1" checked>
                Show publish date to recipients before the document is live
            </label>
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>

    <form method="get" class="search-form">
        <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search by title…">
        <button type="submit" class="btn">Search</button>
        <?php if ($search !== ''): ?>
            <a href="/admin.php" class="btn-link">Clear</a>
        <?php endif ?>
    </form>

    <?php if (empty($docs)): ?>
        <p class="empty"><?= $search !== '' ? 'No documents matched "' . h($search) . '".' : 'No documents yet.' ?></p>
    <?php else: ?>
        <p class="pagination-summary">
            Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total) ?> of <?= $total ?> document<?= $total !== 1 ? 's' : '' ?>
            <?= $search !== '' ? ' matching "' . h($search) . '"' : '' ?>
        </p>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th>Publishes</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="id">#<?= (int) $d['id'] ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td><?= $d['publish_at'] ? h($d['publish_at']) : '<em>immediately</em>' ?></td>
                        <td>
                            <?php if ($d['publish_at'] && $d['publish_at'] > date('Y-m-d H:i:s')): ?>
                                <a href="/edit.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Edit →</a>
                            <?php endif ?>
                            <a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Create share →</a>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="/admin.php?<?= http_build_query(array_filter(['q' => $search, 'page' => $page - 1])) ?>" class="btn-link">← Previous</a>
                <?php endif ?>
                <span>Page <?= $page ?> of <?= $total_pages ?></span>
                <?php if ($page < $total_pages): ?>
                    <a href="/admin.php?<?= http_build_query(array_filter(['q' => $search, 'page' => $page + 1])) ?>" class="btn-link">Next →</a>
                <?php endif ?>
            </div>
        <?php endif ?>
    <?php endif ?>
</section>

<?php render_footer(); ?>
