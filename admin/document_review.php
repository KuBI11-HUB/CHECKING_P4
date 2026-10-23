<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$message = '';

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

            redirectTo('/GRANTED/admin/document_review.php');
        }
    }
}

$documents = $pdo->query(
    "SELECT d.*, s.full_name FROM documents d
     JOIN scholars s ON s.id = d.scholar_id
     ORDER BY (d.status = 'Pending Review') DESC, d.uploaded_at DESC"
)->fetchAll();

$pageTitle = 'Document Review';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Document review</h1>
<?php if ($message): ?><p class="form-error"><?php echo sanitize($message); ?></p><?php endif; ?>

<div class="card results-card">
    <div class="table-scroll">
    <table class="data-table data-table--sticky">
        <thead>
            <tr><th>Scholar</th><th>Document</th><th>File</th><th>Status</th><th>Remarks</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($documents as $d): ?>
            <tr class="<?php echo $d['status'] === 'Pending Review' ? 'review-row--pending' : 'review-row--done'; ?>">
                <td><?php echo sanitize($d['full_name']); ?></td>
                <td><?php echo h($d['document_type']); ?></td>
                <td><a href="/GRANTED/admin/view_document.php?id=<?php echo (int)$d['id']; ?>" target="_blank"><span class="icon-label"><?php echo uiIcon('file-text'); ?>View file</span></a></td>
                <td><span class="status-badge <?php
                    echo $d['status'] === 'Verified' ? 'status-badge--pass'
                        : ($d['status'] === 'Rejected' ? 'status-badge--fail' : 'status-badge--pending');
                ?>"><?php echo sanitize($d['status']); ?></span></td>
                <td><?php echo sanitize($d['remarks'] ?: '—'); ?></td>
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
            <tr><td colspan="6" class="empty-state"><span class="empty-state__content"><?php echo uiIcon('inbox'); ?>No documents uploaded yet.</span></td></tr>
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
