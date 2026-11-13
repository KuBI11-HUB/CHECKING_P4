<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

const GWA_IMPORT_MAX_BYTES = 1048576;
const GWA_IMPORT_MAX_ROWS = 500;

$pdo = getDbConnection();
$message = '';
$summary = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $file = $_FILES['csv_file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file((string)$file['tmp_name'])) {
        $message = 'Choose a CSV file under 1 MB.';
    } elseif (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'csv'
        || (int)$file['size'] > GWA_IMPORT_MAX_BYTES) {
        $message = 'Choose a .csv file under 1 MB.';
    } else {
        $handle = fopen($file['tmp_name'], 'rb');
        if ($handle === false) {
            $message = 'The CSV file could not be read.';
        } else {
            $header = fgetcsv($handle, 0, ',', '"', '\\') ?: [];
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)($header[0] ?? ''));
            $header = array_map(static fn($v): string => strtolower(trim((string)$v)), $header);
            $required = ['email', 'school_year', 'semester', 'gwa', 'total_units'];
            $missing = array_diff($required, $header);
            $errors = [];
            $saved = 0;
            $seen = [];
            $affected = [];
            if ($missing) {
                $message = 'Missing CSV columns: ' . implode(', ', $missing) . '.';
            } else {
                $columns = array_flip($header);
                $scholarStmt = $pdo->prepare(
                    'SELECT s.id FROM scholars s JOIN users u ON u.id = s.user_id WHERE LOWER(u.email) = LOWER(?)'
                );
                $sourceStmt = $pdo->prepare(
                    "SELECT d.id
                     FROM documents d
                     LEFT JOIN requirements req ON req.id = d.requirement_id
                     WHERE d.scholar_id = ? AND d.school_year = ? AND d.semester = ?
                       AND d.status = 'Verified'
                       AND (
                            (d.requirement_id IS NOT NULL AND BINARY req.name = BINARY 'Certificate of Grades')
                            OR (d.requirement_id IS NULL AND BINARY d.document_type = BINARY 'Certificate of Grades')
                       )
                     ORDER BY d.uploaded_at DESC, d.id DESC
                     LIMIT 1"
                );
                $upsert = $pdo->prepare(
                    'INSERT INTO gwa_records
                        (scholar_id, school_year, semester, gwa, total_units, source_document_id, encoded_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE gwa = VALUES(gwa), total_units = VALUES(total_units),
                        source_document_id = VALUES(source_document_id), encoded_by = VALUES(encoded_by),
                        encoded_at = CURRENT_TIMESTAMP'
                );
                $rowNumber = 1;
                while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                    $rowNumber++;
                    if (trim(implode('', array_map('strval', $cells))) === '') {
                        continue;
                    }
                    if ($rowNumber > GWA_IMPORT_MAX_ROWS + 1) {
                        $errors[] = 'Import stopped: maximum ' . GWA_IMPORT_MAX_ROWS . ' data rows.';
                        break;
                    }
                    $cell = static function (string $key) use ($columns, $cells): string {
                        return isset($columns[$key]) ? trim((string)($cells[$columns[$key]] ?? '')) : '';
                    };
                    $scholarStmt->execute([$cell('email')]);
                    $scholarId = (int)$scholarStmt->fetchColumn();
                    if ($scholarId < 1) {
                        $errors[] = 'Row ' . $rowNumber . ': no scholar matches the email.';
                        continue;
                    }
                    $rowKey = $scholarId . '|' . $cell('school_year') . '|' . $cell('semester');
                    if (isset($seen[$rowKey])) {
                        $errors[] = 'Row ' . $rowNumber . ': duplicate scholar/term in this file.';
                        continue;
                    }
                    $seen[$rowKey] = true;
                    $schoolYear = $cell('school_year');
                    $semester = $cell('semester');
                    $sourceStmt->execute([$scholarId, $schoolYear, $semester]);
                    $sourceDocumentId = (int)$sourceStmt->fetchColumn();
                    if ($sourceDocumentId < 1) {
                        $errors[] = 'Row ' . $rowNumber . ': no currently verified Certificate of Grades matches this scholar and term.';
                        continue;
                    }
                    $validated = validateGwaInput($pdo, [
                        'scholar_id' => $scholarId,
                        'school_year' => $schoolYear,
                        'semester' => $semester,
                        'gwa' => $cell('gwa'),
                        'total_units' => $cell('total_units'),
                        'source_document_id' => $sourceDocumentId,
                    ]);
                    if ($validated['error'] !== null) {
                        $errors[] = 'Row ' . $rowNumber . ': ' . $validated['error'];
                        continue;
                    }
                    $v = $validated['values'];
                    try {
                        $upsert->execute([
                            $v['scholar_id'], $v['school_year'], $v['semester'], $v['gwa'],
                            $v['total_units'], $v['source_document_id'], (int)$_SESSION['user_id'],
                        ]);
                        $saved++;
                        $affected[$scholarId] = true;
                    } catch (PDOException $e) {
                        error_log('GRANTED GWA CSV row ' . $rowNumber . ' failed: ' . $e->getMessage());
                        $errors[] = 'Row ' . $rowNumber . ': the database rejected this record.';
                    }
                }
                fclose($handle);
                foreach (array_keys($affected) as $scholarId) {
                    evaluateScholar($pdo, (int)$scholarId);
                }
                $summary = ['saved' => $saved, 'errors' => $errors];
            }
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}

$pageTitle = 'Import GWA';
require_once __DIR__ . '/../includes/header.php';
?>
<p><a class="back-link" href="/GRANTED/admin/enter_gwa.php">&larr; Enter GWA manually</a></p>
<h1>Import official GWA from CSV</h1>
<div class="card card--prose">
    <?php if ($message): ?><p class="form-error"><?php echo h($message); ?></p><?php endif; ?>
    <p>Required columns: email, school_year, semester, gwa, total_units. For each row, GRANTED automatically links the most recent verified Certificate of Grades for the same scholar and term. This imports official values only; it never calculates GWA from subject grades.</p>
    <p><a class="btn btn--secondary" href="/GRANTED/admin/download_gwa_template.php">Download template</a></p>
    <form method="POST" enctype="multipart/form-data">
        <?php echo csrfField(); ?>
        <label for="csv_file">CSV file (1 MB maximum)</label>
        <input id="csv_file" name="csv_file" type="file" accept=".csv,text/csv" required>
        <button type="submit">Import and re-evaluate</button>
    </form>
</div>
<?php if ($summary !== null): ?>
<div class="card">
    <p>Saved: <?php echo (int)$summary['saved']; ?> record(s).</p>
    <?php if ($summary['errors']): ?><h2>Rows needing correction</h2><ul><?php foreach ($summary['errors'] as $error): ?><li><?php echo h($error); ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
