<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$message = '';

// Filters come from the URL. The review forms below post back to this same URL, so they are still here on a POST.
// key => [chip label, documents.status value, or null for "all"]
$statusFilters = [
    'all'      => ['All', null],
    'pending'  => ['Pending', 'Pending Review'],
    'verified' => ['Verified', 'Verified'],
    'rejected' => ['Rejected', 'Rejected'],
];
$statusKey = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!isset($statusFilters[$statusKey])) {
    $statusKey = 'pending';   // the review queue is the default view
}
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$search = function_exists('mb_substr') ? mb_substr($search, 0, 100) : substr($search, 0, 100);

/** URL of this page with the current filters; $override changes single values (pending is the default, so it needs no parameter). */
$reviewUrl = static function (array $override = []) use ($statusKey, $search): string {
    $values = array_merge(['status' => $statusKey, 'q' => $search], $override);
    $query = [];
    if ($values['q'] !== '') {
        $query['q'] = $values['q'];
    }
    if ($values['status'] !== 'pending') {
        $query['status'] = $values['status'];
    }
    return '/GRANTED/admin/document_review.php' . ($query ? '?' . http_build_query($query) : '');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $documentId = (int)($_POST['document_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $remarks = sanitize($_POST['remarks'] ?? '');

    if (!$documentId || !in_array($decision, ['Verified', 'Rejected'], true)) {
        $message = 'Something went wrong with that submission.';
    } else {
        $stmt = $pdo->prepare('SELECT scholar_id FROM documents WHERE id = ?');
        $stmt->execute([$documentId]);
        $doc = $stmt->fetch();

        if ($doc) {
            $stmt = $pdo->prepare(
                'UPDATE documents SET status = ?, remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?'
            );
            $stmt->execute([$decision, $remarks, $_SESSION['user_id'], $documentId]);

            // Document status changed -> re-run the rule engine for this scholar
            evaluateScholar($pdo, $doc['scholar_id']);

            // The reviewed row leaves the Pending view, so say what happened. Back to the same filtered view.
            setFlash('Document marked ' . $decision . '.');
            redirectTo($reviewUrl());
        }
    }
}

$flash = takeFlash();

// Filtering happens in SQL with bound values only.
$where = [];
$params = [];
$statusValue = $statusFilters[$statusKey][1];
if ($statusValue !== null) {
    $where[] = 'd.status = :status';
    $params[':status'] = $statusValue;
}
if ($search !== '') {
    // Older rows hold HTML-escaped text (O'Brien -> O&#039;Brien), so look for both spellings.
    $any = [];
    foreach (array_values(array_unique([$search, htmlspecialchars($search, ENT_QUOTES, 'UTF-8')])) as $i => $spelling) {
        $like = '%' . addcslashes($spelling, '\\%_') . '%';
        $any[] = "s.full_name LIKE :name{$i} OR u.email LIKE :email{$i}";
        $params[":name{$i}"] = $like;
        $params[":email{$i}"] = $like;
    }
    $where[] = '(' . implode(' OR ', $any) . ')';
}

$stmt = $pdo->prepare(
    'SELECT d.*, s.full_name FROM documents d
     JOIN scholars s ON s.id = d.scholar_id
     JOIN users u ON u.id = s.user_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . " ORDER BY (d.status = 'Pending Review') DESC, d.uploaded_at DESC, d.id DESC"
);
$stmt->execute($params);
$documents = $stmt->fetchAll();

$pageTitle = 'Document Review';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Document review</h1>
<?php echo flashBox($flash); ?>
<?php if ($message): ?><p class="form-error"><?php echo sanitize($message); ?></p><?php endif; ?>

<div class="card results-card">
    <div class="results-toolbar">
        <form method="GET" action="/GRANTED/admin/document_review.php" class="search-form" role="search">
            <label for="q" class="visually-hidden">Search scholar name or email</label>
            <input type="search" id="q" name="q" value="<?php echo sanitize($search); ?>" maxlength="100" placeholder="Search name or email">
            <?php if ($statusKey !== 'pending'): ?><input type="hidden" name="status" value="<?php echo sanitize($statusKey); ?>"><?php endif; ?>
            <button type="submit" class="btn btn--secondary"><span class="icon-label"><?php echo uiIcon('search'); ?>Search</span></button>
        </form>

        <nav class="status-chips" aria-label="Filter by document status">
            <?php foreach ($statusFilters as $key => [$label]): ?>
                <a class="status-chip" href="<?php echo sanitize($reviewUrl(['status' => $key])); ?>"<?php echo $statusKey === $key ? ' aria-current="true"' : ''; ?>><?php echo h($label); ?></a>
            <?php endforeach; ?>
        </nav>
    </div>

    <?php if ($search !== ''): ?>
        <div class="filter-chips" aria-label="Active filters">
            <span class="filter-chip"><?php echo sanitize('Search: ' . $search); ?>
                <a href="<?php echo sanitize($reviewUrl(['q' => ''])); ?>" aria-label="Remove filter Search" title="Remove this filter">&times;</a></span>
        </div>
    <?php endif; ?>

    <p class="results-summary">Showing <strong><?php echo count($documents); ?></strong> document<?php echo count($documents) === 1 ? '' : 's'; ?>.</p>

    <div class="table-scroll">
    <table class="data-table data-table--sticky data-table--stack">
        <thead>
            <tr><th>Scholar</th><th>Document</th><th>File</th><th>Status</th><th>Remarks</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($documents as $d): ?>
            <tr class="<?php echo $d['status'] === 'Pending Review' ? 'review-row--pending' : 'review-row--done'; ?>">
                <td><?php echo h($d['full_name']); ?></td>
                <td><?php echo h($d['document_type']); ?></td>
                <td><a href="/GRANTED/admin/view_document.php?id=<?php echo (int)$d['id']; ?>" target="_blank"><span class="icon-label"><?php echo uiIcon('file-text'); ?>View file</span></a></td>
                <td><span class="status-badge <?php
                    echo $d['status'] === 'Verified' ? 'status-badge--pass'
                        : ($d['status'] === 'Rejected' ? 'status-badge--fail' : 'status-badge--pending');
                ?>"><?php echo sanitize($d['status']); ?></span></td>
                <td><?php echo h($d['remarks'] ?: '—'); ?></td>
                <td>
                    <?php if ($d['status'] === 'Pending Review'): ?>
                    <form method="POST" action="" class="review-form">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="document_id" value="<?php echo (int)$d['id']; ?>">
                        <input type="text" name="remarks" placeholder="Remark (needed to reject)" aria-label="Remark for <?php echo h($d['document_type']); ?>">
                        <div class="review-form__buttons">
                            <button type="submit" name="decision" value="Verified" class="btn" aria-label="Verify <?php echo h($d['document_type']); ?> from <?php echo h($d['full_name']); ?>">Verify</button>
                            <button type="submit" name="decision" value="Rejected" class="btn btn--danger" aria-label="Reject <?php echo h($d['document_type']); ?> from <?php echo h($d['full_name']); ?>">Reject</button>
                        </div>
                    </form>
                    <?php else: ?>
                        <em>reviewed</em>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($documents)): ?>
            <tr><td colspan="6" class="empty-state"><span class="empty-state__content"><?php echo uiIcon('inbox'); ?>
                <?php if ($statusKey === 'all' && $search === ''): ?>
                    No documents uploaded yet.
                <?php elseif ($statusKey === 'pending' && $search === ''): ?>
                    No documents are pending review. <a href="<?php echo sanitize($reviewUrl(['status' => 'all'])); ?>">Show all documents</a>.
                <?php else: ?>
                    No documents match these filters. <a href="<?php echo sanitize($reviewUrl(['status' => 'all', 'q' => ''])); ?>">Show all documents</a>.
                <?php endif; ?>
            </span></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('.review-form').forEach(function (form) {
        var remark = form.elements['remarks'];
        var verify = form.querySelector('button[value="Verified"]');
        var reject = form.querySelector('button[value="Rejected"]');
        if (!remark || !verify || !reject) { return; }

        // Enter in the remark box would press the first button (Verify). Make the choice explicit.
        remark.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); }
        });
        remark.addEventListener('input', function () { remark.setCustomValidity(''); });
        verify.addEventListener('click', function () { remark.setCustomValidity(''); });
        // The scholar sees this remark, so a rejection needs one. Browser-side check only.
        reject.addEventListener('click', function () {
            remark.setCustomValidity(remark.value.trim() === '' ? 'Enter a remark so the scholar knows why this was rejected.' : '');
        });
    });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>