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
        $message = 'Choose a CSV file under 1 MB.';
    } elseif (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $message = 'Choose a .csv file.';
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
<p style="margin-bottom:1rem;"><a class="back-link" href="/GRANTED/admin/enter_grades.php">&larr; Enter a single grade instead</a></p>

<div class="card">
    <?php if ($message): ?><p class="form-error"><?php echo sanitize($message); ?></p><?php endif; ?>

    <p>Required columns: <strong>email</strong>, <strong>subject</strong>, <strong>grade</strong>, and <strong>units</strong>.</p>
    <p>Optional columns: <strong>school_year</strong> and <strong>semester</strong>. Blank values use the current term (<?php echo h(CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER); ?>).</p>
    <p>Use the first row for column names. Grades must be 1.00–5.00; units must be 1–10. Existing grades are skipped. Maximum: <?php echo GRADE_IMPORT_MAX_ROWS; ?> rows, 1 MB, UTF-8.</p>
<pre style="margin-top:0.75rem; font-size:0.85rem;">email,subject,grade,units,school_year,semester
maria@example.com,CS 101,1.75,3,<?php echo h(CURRENT_SCHOOL_YEAR); ?>,<?php echo h(CURRENT_SEMESTER); ?></pre>

    <form method="POST" action="" enctype="multipart/form-data" id="import-form" style="max-width:34rem;">
        <?php echo csrfField(); ?>
        <label for="csv_file">CSV file</label>
        <div class="dropzone" id="dropzone">
            <div class="dropzone__prompt">
                <span class="icon-label"><?php echo uiIcon('upload'); ?><strong>Choose a CSV file</strong><span class="dropzone__drag-hint"> or drag it here</span></span>
                <small id="file-hint">CSV only &middot; 1 MB maximum &middot; up to <?php echo GRADE_IMPORT_MAX_ROWS; ?> rows</small>
            </div>
            <input type="file" class="dropzone__input" id="csv_file" name="csv_file"
                   accept=".csv,text/csv" title="Choose a CSV file" aria-describedby="file-hint" required>
        </div>
        <p class="form-error" id="file-error" role="alert" hidden></p>

        <div class="file-chip" id="file-chip" hidden>
            <span class="file-chip__type" id="file-type"></span>
            <span class="file-chip__name" id="file-name"></span>
            <span class="file-chip__size" id="file-size"></span>
            <button type="button" class="file-chip__remove" id="file-remove" aria-label="Remove selected file">&times;</button>
        </div>

        <button type="submit" data-submitting-label="Importing...">Import</button>
    </form>

    <script>
    (function () {
        var MAX_BYTES = 1024 * 1024;
        var ALLOWED = ['csv'];

        var form = document.getElementById('import-form');
        var zone = document.getElementById('dropzone');
        var input = document.getElementById('csv_file');
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

        // Convenience only: the server checks type, size and every row again.
        function problemWith(file) {
            if (ALLOWED.indexOf(extensionOf(file.name)) === -1) { return 'Choose a .csv file.'; }
            if (file.size > MAX_BYTES) { return 'Choose a CSV file under 1 MB.'; }
            return '';
        }

        function show(file) {
            clearError();
            var problem = problemWith(file);
            if (problem) { clearFile(); showError(problem); return; }
            typeEl.textContent = extensionOf(file.name).toUpperCase();
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
                showError('Your browser could not take the dropped file. Please use "Choose a CSV file".');
            }
        });

        removeBtn.addEventListener('click', function () { clearFile(); clearError(); input.focus(); });

        // A file dropped outside the zone should not make the browser leave the page.
        window.addEventListener('dragover', function (e) { e.preventDefault(); });
        window.addEventListener('drop', function (e) { e.preventDefault(); });

        if (input.files && input.files.length) { show(input.files[0]); }   // e.g. after the back button
    })();
    </script>
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