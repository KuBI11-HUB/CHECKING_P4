<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

<div class="card">
    <table class="data-table">
        <thead>
            <tr><th>Scholar</th><th>Document</th><th>File</th><th>Status</th><th>Remarks</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($documents as $d): ?>
            <tr>
                <td><?php echo sanitize($d['full_name']); ?></td>
                <td><?php echo sanitize($d['document_type']); ?></td>
                <td><a href="/GRANTED/uploads/documents/<?php echo sanitize($d['filepath']); ?>" target="_blank">View file</a></td>
                <td><span class="status-badge <?php
                    echo $d['status'] === 'Verified' ? 'status-badge--pass'
                        : ($d['status'] === 'Rejected' ? 'status-badge--fail' : 'status-badge--pending');
                ?>"><?php echo sanitize($d['status']); ?></span></td>
                <td><?php echo sanitize($d['remarks'] ?: '—'); ?></td>
                <td>
                    <?php if ($d['status'] === 'Pending Review'): ?>
                    <form method="POST" action="" style="display:flex; gap:0.4rem; align-items:center;">
                        <input type="hidden" name="document_id" value="<?php echo (int)$d['id']; ?>">
                        <input type="text" name="remarks" placeholder="remark (required if rejecting)" style="width:160px;">
                        <button type="submit" name="decision" value="Verified" class="btn" style="margin-top:0;">Verify</button>
                        <button type="submit" name="decision" value="Rejected" class="btn" style="margin-top:0; background:#B3492B;">Reject</button>
                    </form>
                    <?php else: ?>
                        <em>reviewed</em>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($documents)): ?>
            <tr><td colspan="6">No documents uploaded yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
