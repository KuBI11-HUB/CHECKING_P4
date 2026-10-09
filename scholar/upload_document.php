<?php
$requiredRole = 'scholar';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM scholars WHERE user_id = ?');
$stmt->execute([$_SESSION['user_id']]);
$scholar = $stmt->fetch();

$message = '';
$success = '';
$checklist = [];

if ($scholar) {
    $checklist = getDocumentChecklist($pdo, $scholar['id'], $scholar['scholarship_type_id']);
}

if ($scholar && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $documentType = sanitize($_POST['document_type'] ?? '');
    $file = $_FILES['document_file'] ?? null;

    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if ($documentType === '' || !$file || $file['error'] !== UPLOAD_ERR_OK) {
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

            if (move_uploaded_file($file['tmp_name'], $uploadDir . $storedName)) {
                $stmt = $pdo->prepare(
                    "INSERT INTO documents (scholar_id, document_type, filepath, status, school_year, semester)
                     VALUES (?, ?, ?, 'Pending Review', ?, ?)"
                );
                // The term comes from config/app.php (CURRENT_SCHOOL_YEAR / CURRENT_SEMESTER).
                $stmt->execute([$scholar['id'], $documentType, $storedName, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);

                evaluateScholar($pdo, $scholar['id']);

                $success = sanitize($documentType) . ' uploaded and is now pending review.';
                $checklist = getDocumentChecklist($pdo, $scholar['id'], $scholar['scholarship_type_id']);
            } else {
                $message = 'Upload failed — please try again.';
            }
        }
    }
}

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
        <label for="document_type">Which document is this?</label>
        <select id="document_type" name="document_type" required>
            <?php foreach ($checklist as $item): ?>
                <option value="<?php echo sanitize($item['document_type']); ?>"><?php echo sanitize($item['document_type']); ?></option>
            <?php endforeach; ?>
        </select>
        <label for="document_file">File (PDF, JPG, or PNG — 5MB max)</label>
        <input type="file" id="document_file" name="document_file" accept=".pdf,.jpg,.jpeg,.png" required>
        <button type="submit">Upload</button>
    </form>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
