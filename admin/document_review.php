<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$message = '';

$statusFilters = [
    'all'      => ['All', null],
    'pending'  => ['Pending', 'Pending Review'],
    'verified' => ['Verified', 'Verified'],
    'rejected' => ['Rejected', 'Rejected'],
];
$statusKey = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!isset($statusFilters[$statusKey])) {
    $statusKey = 'pending';
}
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$search = function_exists('mb_substr') ? mb_substr($search, 0, 100) : substr($search, 0, 100);
$requirementId = (is_string($_GET['requirement_id'] ?? null) && ctype_digit($_GET['requirement_id']))
    ? (int)$_GET['requirement_id'] : 0;
$typeId = (is_string($_GET['scholarship_type_id'] ?? null) && ctype_digit($_GET['scholarship_type_id']))
    ? (int)$_GET['scholarship_type_id'] : 0;

$reviewUrl = static function (array $override = []) use ($statusKey, $search, $requirementId, $typeId): string {
    $values = array_merge([
        'status' => $statusKey,
        'q' => $search,
        'requirement_id' => $requirementId,
        'scholarship_type_id' => $typeId,
    ], $override);
    $query = [];
    if ($values['q'] !== '') {
        $query['q'] = $values['q'];
    }
    if ($values['status'] !== 'pending') {
        $query['status'] = $values['status'];
    }
    if ((int)$values['requirement_id'] > 0) {
        $query['requirement_id'] = (int)$values['requirement_id'];
    }
    if ((int)$values['scholarship_type_id'] > 0) {
        $query['scholarship_type_id'] = (int)$values['scholarship_type_id'];
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
            evaluateScholar($pdo, (int)$doc['scholar_id']);
            setFlash('Document marked ' . $decision . '.');
            redirectTo($reviewUrl());
        }
    }
}

$flash = takeFlash();

$where = [];
$params = [];
$statusValue = $statusFilters[$statusKey][1];
if ($statusValue !== null) {
    $where[] = 'd.status = :status';
    $params[':status'] = $statusValue;
}
if ($search !== '') {
    $any = [];
    foreach (array_values(array_unique([$search, htmlspecialchars($search, ENT_QUOTES, 'UTF-8')])) as $i => $spelling) {
        $like = '%' . addcslashes($spelling, '\\%_') . '%';
        $any[] = '(' . scholarNameSearchSql('s', 'review' . $i) . ') OR u.email LIKE :email' . $i;
        foreach (['fname', 'mname', 'lname', 'suffix'] as $part) {
            $params[":review{$i}_{$part}"] = $like;
        }
        $params[":email{$i}"] = $like;
    }
    $where[] = '(' . implode(' OR ', $any) . ')';
}
if ($requirementId > 0) {
    $where[] = 'd.requirement_id = :requirement_id';
    $params[':requirement_id'] = $requirementId;
}
if ($typeId > 0) {
    $where[] = 'EXISTS (
        SELECT 1 FROM scholarship_assignments a
        WHERE a.scholar_id = d.scholar_id
          AND a.scholarship_type_id = :type_id
          AND a.is_active = 1 AND a.ended_at IS NULL
    )';
    $params[':type_id'] = $typeId;
}

$stmt = $pdo->prepare(
    "SELECT d.*, s.fname, s.mname, s.lname, s.suffix, req.name AS requirement_name,
            (
                SELECT GROUP_CONCAT(st.name ORDER BY st.name SEPARATOR ', ')
                FROM scholarship_assignments a
                JOIN scholarship_types st ON st.id = a.scholarship_type_id
                WHERE a.scholar_id = d.scholar_id AND a.is_active = 1 AND a.ended_at IS NULL
            ) AS type_names
     FROM documents d
     JOIN scholars s ON s.id = d.scholar_id
     JOIN users u ON u.id = s.user_id
     LEFT JOIN requirements req ON req.id = d.requirement_id"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . " ORDER BY (d.status = 'Pending Review') DESC, d.uploaded_at DESC, d.id DESC"
);
$stmt->execute($params);
$documents = $stmt->fetchAll();

$requirements = $pdo->query(
    'SELECT id, name FROM requirements WHERE is_active = 1 ORDER BY name'
)->fetchAll();
$types = $pdo->query('SELECT id, name FROM scholarship_types ORDER BY name')->fetchAll();

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
            <?php if ($requirementId > 0): ?><input type="hidden" name="requirement_id" value="<?php echo (int)$requirementId; ?>"><?php endif; ?>
            <?php if ($typeId > 0): ?><input type="hidden" name="scholarship_type_id" value="<?php echo (int)$typeId; ?>"><?php endif; ?>
            <button type="submit" class="btn btn--secondary"><span class="icon-label"><?php echo uiIcon('search'); ?>Search</span></button>
        </form>

        <nav class="status-chips" aria-label="Filter by document status">
            <?php foreach ($statusFilters as $key => [$label]): ?>
                <a class="status-chip" href="<?php echo sanitize($reviewUrl(['status' => $key])); ?>"<?php echo $statusKey === $key ? ' aria-current="true"' : ''; ?>><?php echo h($label); ?></a>
            <?php endforeach; ?>
        </nav>
    </div>

    <details class="filter-block" open>
        <summary>Filters</summary>
        <form method="GET" action="/GRANTED/admin/document_review.php" class="filter-form">
            <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?php echo sanitize($search); ?>"><?php endif; ?>
            <?php if ($statusKey !== 'pending'): ?><input type="hidden" name="status" value="<?php echo sanitize($statusKey); ?>"><?php endif; ?>
            <div class="filter-form__field">
                <label for="requirement_id">Requirement</label>
                <select id="requirement_id" name="requirement_id">
                    <option value="">All requirements</option>
                    <?php foreach ($requirements as $req): ?>
                        <option value="<?php echo (int)$req['id']; ?>" <?php echo $requirementId === (int)$req['id'] ? 'selected' : ''; ?>><?php echo h($req['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-form__field">
                <label for="scholarship_type_id">Scholarship type</label>
                <select id="scholarship_type_id" name="scholarship_type_id">
                    <option value="">All types</option>
                    <?php foreach ($types as $type): ?>
                        <option value="<?php echo (int)$type['id']; ?>" <?php echo $typeId === (int)$type['id'] ? 'selected' : ''; ?>><?php echo h($type['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-form__actions">
                <button type="submit">Apply filters</button>
                <a class="btn btn--secondary" href="/GRANTED/admin/document_review.php">Clear filters</a>
            </div>
        </form>
    </details>

    <p class="results-summary">Showing <strong><?php echo count($documents); ?></strong> document<?php echo count($documents) === 1 ? '' : 's'; ?>.</p>

    <div class="table-scroll">
    <table class="data-table data-table--sticky data-table--stack">
        <thead>
            <tr><th>Scholar</th><th>Scholarship type</th><th>Requirement</th><th>File</th><th>Status</th><th>Remarks</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($documents as $d): ?>
            <?php $fileOk = documentFileIsAvailable($d['filepath'] ?? null); ?>
            <tr class="<?php echo $d['status'] === 'Pending Review' ? 'review-row--pending' : 'review-row--done'; ?>">
                <td><?php echo h(formatScholarName($d)); ?></td>
                <td><?php echo $d['type_names'] ? h($d['type_names']) : '—'; ?></td>
                <td><?php echo h($d['requirement_name'] ?? $d['document_type']); ?></td>
                <td>
                    <?php if ($fileOk): ?>
                        <a href="/GRANTED/admin/view_document.php?id=<?php echo (int)$d['id']; ?>" target="_blank" rel="noopener">View file</a>
                    <?php else: ?>
                        <span class="file-missing" title="File is not on disk"><?php echo uiIcon('circle-alert'); ?> File missing</span>
                    <?php endif; ?>
                </td>
                <td><span class="status-badge <?php
                    echo $d['status'] === 'Verified' ? 'status-badge--pass'
                        : ($d['status'] === 'Rejected' ? 'status-badge--fail' : 'status-badge--pending');
                ?>"><?php echo h($d['status']); ?></span></td>
                <td><?php echo $d['remarks'] ? h($d['remarks']) : '—'; ?></td>
                <td>
                    <?php if ($d['status'] === 'Pending Review'): ?>
                    <form method="POST" action="<?php echo sanitize($reviewUrl()); ?>" class="review-form">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="document_id" value="<?php echo (int)$d['id']; ?>">
                        <label class="visually-hidden" for="remarks-<?php echo (int)$d['id']; ?>">Remarks</label>
                        <input type="text" id="remarks-<?php echo (int)$d['id']; ?>" name="remarks" placeholder="Remark (required to reject)" maxlength="255">
                        <div class="review-form__actions">
                            <button type="submit" name="decision" value="Verified" class="btn" aria-label="Verify <?php echo h($d['document_type']); ?> from <?php echo h(formatScholarName($d)); ?>">Verify</button>
                            <button type="submit" name="decision" value="Rejected" class="btn btn--danger" aria-label="Reject <?php echo h($d['document_type']); ?> from <?php echo h(formatScholarName($d)); ?>">Reject</button>
                        </div>
                    </form>
                    <?php else: ?>
                        <em>reviewed</em>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($documents)): ?>
            <tr><td colspan="7" class="empty-state"><span class="empty-state__content"><?php echo uiIcon('inbox'); ?>
                <?php if ($statusKey === 'pending' && $search === '' && $requirementId === 0 && $typeId === 0): ?>
                    No documents are pending review. <a href="<?php echo sanitize($reviewUrl(['status' => 'all'])); ?>">Show all documents</a>.
                <?php else: ?>
                    No documents match these filters. <a href="/GRANTED/admin/document_review.php">Clear filters</a>.
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
        remark.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); }
        });
        remark.addEventListener('input', function () { remark.setCustomValidity(''); });
        verify.addEventListener('click', function () { remark.setCustomValidity(''); });
        reject.addEventListener('click', function () {
            remark.setCustomValidity(remark.value.trim() === '' ? 'Enter a remark so the scholar knows why this was rejected.' : '');
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>