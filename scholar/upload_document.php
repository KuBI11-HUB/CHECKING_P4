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
$documentType = '';

if ($scholar) {
    $checklist = getDocumentChecklist($pdo, $scholar['id'], $scholar['scholarship_type_id']);
}

if ($scholar && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    // Used exactly as posted: the dropdown posts the stored checklist text, which is also what the rule engine compares.
    $documentType = trim(is_string($_POST['document_type'] ?? null) ? $_POST['document_type'] : '');
    $submissionToken = $_POST['submission_token'] ?? '';
    $file = $_FILES['document_file'] ?? null;
    $fileError = is_array($file) ? (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if (!is_string($submissionToken) || !preg_match('/\A[a-f0-9]{64}\z/', $submissionToken)) {
        $message = 'This upload form is invalid. Please refresh the page and try again.';
    } elseif ($documentType === '' || $fileError === UPLOAD_ERR_NO_FILE) {
        $message = 'Please choose which document you\'re uploading and select a file.';
    } elseif (!in_array($documentType, array_column($checklist, 'document_type'), true)) {
        $message = 'Please choose one of the documents on your checklist.';
    } elseif ($fileError === UPLOAD_ERR_INI_SIZE || $fileError === UPLOAD_ERR_FORM_SIZE) {
        $message = 'File is too large — 5MB maximum.';
    } elseif ($fileError !== UPLOAD_ERR_OK) {
        $message = 'Upload failed — please try again.';
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

<h1 class="upload-page__title">Upload compliance documents</h1>

<?php if (!$scholar): ?>
    <div class="card"><p>No scholar record is linked to this account yet.</p></div>
<?php else: ?>

<?php if ($success): ?><p class="form-success icon-message" role="status"><?php echo uiIcon('circle-check'); ?><span><?php echo h($success); ?></span></p><?php endif; ?>

<div class="card">
    <h2>Your checklist</h2>
    <ul class="rule-list">
        <?php foreach ($checklist as $item): ?>
            <li>
                <span><?php echo h($item['document_type']); ?>
                    <?php echo dueDateHtml($item['due_date'], $item['status']); ?>
                    <?php if ($item['remarks']): ?><br><small><?php echo h($item['remarks']); ?></small><?php endif; ?>
                </span>
                <span class="status-badge <?php
                    echo $item['status'] === 'Verified' ? 'status-badge--pass'
                        : ($item['status'] === 'Rejected' ? 'status-badge--fail' : 'status-badge--pending');
                ?>"><?php echo sanitize($item['status']); ?></span>
            </li>
        <?php endforeach; ?>
        <?php if (empty($checklist)): ?>
            <li class="empty-state"><span class="empty-state__content"><?php echo uiIcon('inbox'); ?>No documents are required for your scholarship type yet.</span></li>
        <?php endif; ?>
    </ul>
</div>

<?php if (!empty($checklist)): ?>
<div class="card upload-card">
    <h2>Upload a document</h2>
    <p class="upload-term">For <strong><?php echo h(CURRENT_SCHOOL_YEAR); ?> &middot; <?php echo h(CURRENT_SEMESTER); ?> semester</strong></p>
    <?php if ($message): ?><p class="form-error icon-message" role="alert" style="margin-top:0.75rem;"><?php echo uiIcon('circle-alert'); ?><span><?php echo sanitize($message); ?></span></p><?php endif; ?>

    <form method="POST" action="" enctype="multipart/form-data" id="upload-form" class="upload-form">
        <?php echo csrfField(); ?>
        <input type="hidden" name="submission_token" value="<?php echo sanitize($submissionToken); ?>">

        <label for="document_type">Which document is this?</label>
        <select id="document_type" name="document_type" required>
            <?php foreach ($checklist as $item): ?>
                <option value="<?php echo sanitize($item['document_type']); ?>" <?php echo $documentType === $item['document_type'] ? 'selected' : ''; ?>><?php echo h($item['document_type']); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="document_file">File</label>
        <div class="dropzone" id="dropzone">
            <div class="dropzone__prompt">
                <span class="icon-label"><?php echo uiIcon('upload'); ?><strong>Choose a file</strong><span class="dropzone__drag-hint"> or drag it here</span></span>
                <small id="file-hint">PDF, JPG or PNG &middot; 5 MB maximum</small>
            </div>
            <input type="file" class="dropzone__input" id="document_file" name="document_file"
                   accept=".pdf,.jpg,.jpeg,.png" title="Choose a file" aria-describedby="file-hint" required>
        </div>
        <p class="form-error" id="file-error" role="alert" hidden></p>

        <div class="file-chip" id="file-chip" hidden>
            <span class="file-chip__type" id="file-type"></span>
            <span class="file-chip__name" id="file-name"></span>
            <span class="file-chip__size" id="file-size"></span>
            <button type="button" class="file-chip__remove" id="file-remove" aria-label="Remove selected file">&times;</button>
        </div>

        <div class="upload-actions">
            <button type="submit" data-submitting-label="Uploading...">Upload</button>
            <button type="reset" class="btn btn--secondary">Cancel</button>
        </div>
    </form>

    <script>
    (function () {
        var MAX_BYTES = 5 * 1024 * 1024;
        var ALLOWED = ['pdf', 'jpg', 'jpeg', 'png'];

        var form = document.getElementById('upload-form');
        var zone = document.getElementById('dropzone');
        var input = document.getElementById('document_file');
        var chip = document.getElementById('file-chip');
        var typeEl = document.getElementById('file-type');
        var nameEl = document.getElementById('file-name');
        var sizeEl = document.getElementById('file-size');
        var removeBtn = document.getElementById('file-remove');
        var errorEl = document.getElementById('file-error');
        if (!form || !zone || !input) { return; }

        function extensionOf(name) {
            var dot = name.lastIndexOf('.');
            return dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
        }
        function formatSize(bytes) {
            return bytes < 1048576
                ? Math.max(1, Math.round(bytes / 1024)) + ' KB'
                : (bytes / 1048576).toFixed(1) + ' MB';
        }
        function showError(text) { errorEl.textContent = text; errorEl.hidden = false; }
        function clearError() { errorEl.textContent = ''; errorEl.hidden = true; }
        function clearFile() { input.value = ''; chip.hidden = true; }

        // Convenience only: the server checks type and size again.
        function problemWith(file) {
            if (ALLOWED.indexOf(extensionOf(file.name)) === -1) { return 'Only PDF, JPG, or PNG files are allowed.'; }
            if (file.size > MAX_BYTES) { return 'File is too large — 5MB maximum.'; }
            return '';
        }

        function show(file) {
            clearError();
            var problem = problemWith(file);
            if (problem) { clearFile(); showError(problem); return; }
            var ext = extensionOf(file.name);
            typeEl.textContent = (ext === 'jpeg' ? 'jpg' : ext).toUpperCase();
            nameEl.textContent = file.name;
            nameEl.title = file.name;
            sizeEl.textContent = formatSize(file.size);
            chip.hidden = false;
        }

        input.addEventListener('change', function () {
            if (input.files && input.files.length) { show(input.files[0]); } else { clearFile(); }
        });

        zone.addEventListener('dragenter', function (e) { e.preventDefault(); zone.classList.add('is-dragover'); });
        zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('is-dragover'); });
        zone.addEventListener('dragleave', function () { zone.classList.remove('is-dragover'); });
        zone.addEventListener('drop', function (e) {
            e.preventDefault();
            zone.classList.remove('is-dragover');
            var files = e.dataTransfer ? e.dataTransfer.files : null;
            if (!files || !files.length) { return; }
            clearError();
            if (files.length > 1) { clearFile(); showError('Please drop one file at a time.'); return; }
            try { input.files = files; } catch (err) { /* checked below */ }
            if (input.files && input.files.length === 1) {
                show(input.files[0]);
            } else {
                clearFile();
                showError('Your browser could not take the dropped file. Please use "Choose a file".');
            }
        });

        removeBtn.addEventListener('click', function () { clearFile(); clearError(); input.focus(); });
        form.addEventListener('reset', function () { chip.hidden = true; clearError(); });

        // A file dropped outside the zone should not make the browser leave the page.
        window.addEventListener('dragover', function (e) { e.preventDefault(); });
        window.addEventListener('drop', function (e) { e.preventDefault(); });

        if (input.files && input.files.length) { show(input.files[0]); }   // e.g. after the back button
    })();
    </script>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>