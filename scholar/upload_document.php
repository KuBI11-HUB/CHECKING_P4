<?php
$requiredRole = 'scholar';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM scholars WHERE user_id = ?');
$stmt->execute([$_SESSION['user_id']]);
$scholar = $stmt->fetch();

$message = '';
$success = $_SESSION['upload_success'] ?? '';
unset($_SESSION['upload_success']);
$checklist = [];

if ($scholar) {
    $checklist = getDocumentChecklist($pdo, $scholar['id'], $scholar['scholarship_type_id']);
}

if ($scholar && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $documentType = sanitize($_POST['document_type'] ?? '');
    $submissionToken = $_POST['submission_token'] ?? '';
    $file = $_FILES['document_file'] ?? null;

    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if (!is_string($submissionToken) || !preg_match('/\A[a-f0-9]{64}\z/', $submissionToken)) {
        $message = 'This upload form is invalid. Please refresh the page and try again.';
    } elseif ($documentType === '' || !$file || $file['error'] !== UPLOAD_ERR_OK) {
        $message = 'Please choose which document you\'re uploading and select a file.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $message = 'Only PDF, JPG, or PNG files are allowed.';
        } elseif ($file['size'] > $maxSize) {
            $message = 'File is too large — 5MB maximum.';
        } else {
            $uploadDir = __DIR__ . '/../uploads/documents/';
            $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
            $storedPath = $uploadDir . $storedName;

            if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
                $message = 'Upload failed — please try again.';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        "INSERT INTO documents
                            (scholar_id, document_type, filepath, submission_token, status, school_year, semester)
                         VALUES (?, ?, ?, ?, 'Pending Review', ?, ?)"
                    );
                    // The term comes from config/app.php (CURRENT_SCHOOL_YEAR / CURRENT_SEMESTER).
                    $stmt->execute([
                        $scholar['id'],
                        $documentType,
                        $storedName,
                        $submissionToken,
                        CURRENT_SCHOOL_YEAR,
                        CURRENT_SEMESTER,
                    ]);
                } catch (PDOException $e) {
                    if (is_file($storedPath) && !unlink($storedPath)) {
                        throw new RuntimeException('Could not remove an unrecorded uploaded file.', 0, $e);
                    }

                    if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                        $message = 'This upload has already been received. Refresh the page before submitting another file.';
                    } else {
                        throw $e;
                    }
                }

                if ($message === '') {
                    evaluateScholar($pdo, $scholar['id']);
                    $_SESSION['upload_success'] = $documentType . ' uploaded and is now pending review.';
                    redirectTo('/GRANTED/scholar/upload_document.php');
                }
            }
        }
    }
}

$submissionToken = bin2hex(random_bytes(32));

$pageTitle = 'Upload Documents';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Upload compliance documents</h1>

<?php if (!$scholar): ?>
    <div class="card"><p>No scholar record is linked to this account yet.</p></div>
<?php else: ?>

<div class="card">
    <h2>Your checklist</h2>
    <ul class="rule-list">
        <?php foreach ($checklist as $item): ?>
            <li>
                <span><?php echo sanitize($item['document_type']); ?>
                    <?php echo dueDateHtml($item['due_date'], $item['status']); ?>
                    <?php if ($item['remarks']): ?><br><small><?php echo sanitize($item['remarks']); ?></small><?php endif; ?>
                </span>
                <span class="status-badge <?php
                    echo $item['status'] === 'Verified' ? 'status-badge--pass'
                        : ($item['status'] === 'Rejected' ? 'status-badge--fail' : 'status-badge--pending');
                ?>"><?php echo sanitize($item['status']); ?></span>
            </li>
        <?php endforeach; ?>
        <?php if (empty($checklist)): ?>
            <li>No documents are required for your scholarship type yet.</li>
        <?php endif; ?>
    </ul>
</div>

<?php if (!empty($checklist)): ?>
<div class="card">
    <h2>Upload a document</h2>
    <?php if ($message): ?><p class="form-error"><?php echo sanitize($message); ?></p><?php endif; ?>
    <?php if ($success): ?><p class="form-success"><?php echo sanitize($success); ?></p><?php endif; ?>
    <form method="POST" action="" enctype="multipart/form-data">
        <?php echo csrfField(); ?>
        <input type="hidden" name="submission_token" value="<?php echo sanitize($submissionToken); ?>">
        <label for="document_type">Which document is this?</label>
        <select id="document_type" name="document_type" required>
            <?php foreach ($checklist as $item): ?>
                <option value="<?php echo sanitize($item['document_type']); ?>"><?php echo sanitize($item['document_type']); ?></option>
            <?php endforeach; ?>
        </select>
        <label for="document_file">File (PDF, JPG, or PNG — 5MB max)</label>
        <input type="file" id="document_file" name="document_file" accept=".pdf,.jpg,.jpeg,.png" required>
        <button type="submit" data-submitting-label="Uploading...">Upload</button>
    </form>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
