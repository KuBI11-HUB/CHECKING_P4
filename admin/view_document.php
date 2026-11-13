<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$docId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT d.id, d.filepath, d.document_type, d.status, s.fname, s.mname, s.lname, s.suffix
     FROM documents d
     JOIN scholars s ON s.id = d.scholar_id
     WHERE d.id = ?'
);
$stmt->execute([$docId]);
$doc = $stmt->fetch();

$mimeTypes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$storedName = $doc ? basename((string)$doc['filepath']) : '';
$path = $storedName !== '' ? documentStoragePath($storedName) : '';
$ext = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));

if (!$doc || $storedName === '' || !isset($mimeTypes[$ext]) || !documentFileIsAvailable($storedName)) {
    http_response_code(404);
    $pageTitle = 'Document file missing';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <h1>Document file missing</h1>
    <div class="card">
        <p class="form-error icon-message" role="alert"><?php echo uiIcon('circle-alert'); ?>
            <span>The file for this document is not on the server. The database row may still exist.</span>
        </p>
        <?php if ($doc): ?>
            <p>Scholar: <strong><?php echo h(formatScholarName($doc)); ?></strong><br>
               Document: <strong><?php echo h((string)$doc['document_type']); ?></strong>
               (<?php echo h((string)$doc['status']); ?>)</p>
        <?php endif; ?>
        <p>Ask the scholar to upload again, or restore the file into <code>uploads/documents/</code> if this is a local demo fixture.</p>
        <p style="margin-top:1rem;"><a class="back-link" href="/GRANTED/admin/document_review.php">&larr; Back to document review</a></p>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

header('Content-Type: ' . $mimeTypes[$ext]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
