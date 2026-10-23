<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

const GRADE_IMPORT_MAX_BYTES = 1048576;   // 1 MB
const GRADE_IMPORT_MAX_ROWS = 1000;
const GRADE_IMPORT_MAX_LISTED = 100;      // skipped/error lines shown on screen

/** Reads the CSV into memory first, so a too-large file is rejected before anything is saved. */
function readGradeImportFile(string $path): ?array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return null;
    }

    $header = null;
    $records = [];
    $rowNumber = 0;
    $tooMany = false;

    while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $rowNumber++;
        if ($rowNumber === 1) {
            $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)($cells[0] ?? ''));   // Excel's UTF-8 BOM
            $header = array_map(static fn($c) => strtolower(trim((string)$c)), $cells);
            continue;
        }
        if (trim(implode('', array_map('strval', $cells))) === '') {
            continue;   // blank line
        }
        if (count($records) >= GRADE_IMPORT_MAX_ROWS) {
            $tooMany = true;
            break;
        }
        $records[$rowNumber] = $cells;
    }
    fclose($handle);

    return ['header' => $header ?? [], 'records' => $records, 'too_many' => $tooMany];
}

$pdo = getDbConnection();
$message = '';
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $file = $_FILES['csv_file'] ?? null;

    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        $message = 'Please choose a CSV file to import.';
    } elseif (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $message = 'Only .csv files are allowed.';
    } elseif ((int)$file['size'] > GRADE_IMPORT_MAX_BYTES) {
        $message = 'File is too large — 1MB maximum.';
    } else {
        $parsed = readGradeImportFile($file['tmp_name']);
        $missing = $parsed === null ? [] : array_diff(['email', 'subject', 'grade', 'units'], $parsed['header']);

        if ($parsed === null) {
            $message = 'The file could not be read.';
        } elseif ($missing) {
            $message = 'Missing column(s): ' . implode(', ', $missing) . '. The first row must be a header.';
        } elseif ($parsed['too_many']) {
            $message = 'Too many rows — ' . GRADE_IMPORT_MAX_ROWS . ' at most per file. Split the file and import it in parts.';
        } elseif (empty($parsed['records'])) {
            $message = 'The file has a header but no grade rows.';
        } else {
            $col = array_flip($parsed['header']);
            $cell = static fn(array $cells, string $name): string =>
                isset($col[$name]) ? trim((string)($cells[$col[$name]] ?? '')) : '';

            $scholarIds = [];   // lowercase email => scholar id
            foreach ($pdo->query('SELECT s.id, u.email FROM scholars s JOIN users u ON u.id = s.user_id')->fetchAll() as $r) {
                $scholarIds[strtolower(plainText($r['email']))] = (int)$r['id'];
            }

            $insert = $pdo->prepare(
                'INSERT INTO grades (scholar_id, subject, grade, units, school_year, semester) VALUES (?, ?, ?, ?, ?, ?)'
            );

            $imported = 0;
            $skipped = 0;
            $errors = 0;
            $issues = [];     // [row, kind, text] in file order
            $affected = [];   // scholar id => true

            foreach ($parsed['records'] as $rowNo => $cells) {
                if (function_exists('mb_check_encoding') && !mb_check_encoding(implode('', array_map('strval', $cells)), 'UTF-8')) {
                    $errors++;
                    $issues[] = [$rowNo, 'Error', 'The row is not valid UTF-8. Save the file as "CSV UTF-8" and try again.'];
                    continue;
                }

                $email = strtolower(plainText($cell($cells, 'email')));
                $scholarId = $scholarIds[$email] ?? 0;
                if ($scholarId === 0) {
                    $errors++;
                    $issues[] = [$rowNo, 'Error', 'No scholar found with email "' . $email . '".'];
                    continue;
                }

                $schoolYear = $cell($cells, 'school_year');
                $semester = $cell($cells, 'semester');
                $result = validateGradeInput($pdo, [
                    'scholar_id'  => $scholarId,
                    'subject'     => $cell($cells, 'subject'),
                    'grade'       => $cell($cells, 'grade'),
                    'units'       => $cell($cells, 'units'),
                    'school_year' => $schoolYear !== '' ? $schoolYear : CURRENT_SCHOOL_YEAR,
                    'semester'    => $semester !== '' ? $semester : CURRENT_SEMESTER,
                ]);

                if ($result['duplicate']) {
                    $skipped++;
                    $issues[] = [$rowNo, 'Skipped', $result['error']];
                    continue;
                }
                if ($result['error'] !== null) {
                    $errors++;
                    $issues[] = [$rowNo, 'Error', $result['error']];
                    continue;
                }

                $v = $result['values'];
                try {
                    $insert->execute([$v['scholar_id'], $v['subject'], $v['grade'], $v['units'], $v['school_year'], $v['semester']]);
                    $imported++;
                    $affected[$scholarId] = true;
                } catch (PDOException $e) {
                    if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                        $skipped++;
                        $issues[] = [$rowNo, 'Skipped', 'This grade is already recorded.'];
                    } else {
                        error_log('GRANTED grade import row ' . $rowNo . ' failed: ' . $e->getMessage());
                        $errors++;
                        $issues[] = [$rowNo, 'Error', 'The database rejected this row.'];
                    }
                }
            }

            // One evaluation per affected scholar, after all rows are saved (not one per row).
            foreach (array_keys($affected) as $affectedId) {
                evaluateScholar($pdo, (int)$affectedId);
            }

            $summary = [
                'imported'  => $imported,
                'skipped'   => $skipped,
                'errors'    => $errors,
                'evaluated' => count($affected),
                'issues'    => $issues,
            ];
        }
    }
}

$pageTitle = 'Import Grades';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Import grades from CSV</h1>
<p style="margin-bottom:1rem;"><a class="import-back-link" href="/GRANTED/admin/enter_grades.php">&larr; Enter a single grade instead</a></p>

<div class="card">
    <?php if ($message): ?><p class="form-error"><?php echo sanitize($message); ?></p><?php endif; ?>

    <p>The first row must be a header. Columns:</p>
    <ul style="margin:0.5rem 0 0 1.25rem;">
        <li><strong>email</strong>, <strong>subject</strong>, <strong>grade</strong> (1.00–5.00), <strong>units</strong> (1–10): required.</li>
        <li><strong>school_year</strong>, <strong>semester</strong>: optional. When missing or blank, the current term (<?php echo h(CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER); ?>) is used.</li>
        <li>Each row follows the same rules as Enter Grades. Rows already recorded are skipped. Up to <?php echo GRADE_IMPORT_MAX_ROWS; ?> rows, 1MB, UTF-8.</li>
    </ul>
<pre style="margin-top:0.75rem; font-size:0.85rem;">email,subject,grade,units,school_year,semester
maria@example.com,CS 101,1.75,3,2026-2027,1st</pre>

    <form method="POST" action="" enctype="multipart/form-data">
        <?php echo csrfField(); ?>
        <label for="csv_file">CSV file</label>
        <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
        <button type="submit" data-submitting-label="Importing...">Import</button>
    </form>
</div>

<?php if ($summary !== null): ?>
<div class="card">
    <h2>Import summary</h2>
    <?php if ($summary['imported'] > 0): ?>
        <p class="form-success"><?php echo (int)$summary['imported']; ?> grade(s) imported.</p>
    <?php else: ?>
        <p class="form-error">Nothing was imported.</p>
    <?php endif; ?>
    <p style="margin-top:0.75rem;">
        Imported: <strong><?php echo (int)$summary['imported']; ?></strong>
        &nbsp;|&nbsp; Skipped (already recorded): <strong><?php echo (int)$summary['skipped']; ?></strong>
        &nbsp;|&nbsp; Errors: <strong><?php echo (int)$summary['errors']; ?></strong>
        &nbsp;|&nbsp; Scholars re-evaluated: <strong><?php echo (int)$summary['evaluated']; ?></strong>
    </p>

    <?php if (!empty($summary['issues'])): ?>
        <table class="data-table" style="margin-top:1rem;">
            <thead><tr><th>Row</th><th>Result</th><th>Reason</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($summary['issues'], 0, GRADE_IMPORT_MAX_LISTED) as [$rowNo, $kind, $text]): ?>
                <tr>
                    <td><?php echo (int)$rowNo; ?></td>
                    <td><span class="status-badge <?php echo $kind === 'Error' ? 'status-badge--fail' : 'status-badge--pending'; ?>"><?php echo h($kind); ?></span></td>
                    <td><?php echo sanitize($text); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (count($summary['issues']) > GRADE_IMPORT_MAX_LISTED): ?>
            <p style="margin-top:0.75rem;"><small>Showing the first <?php echo GRADE_IMPORT_MAX_LISTED; ?> of <?php echo count($summary['issues']); ?> skipped/error rows.</small></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>