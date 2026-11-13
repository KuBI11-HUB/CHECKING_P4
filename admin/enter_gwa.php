<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$message = '';
$success = '';
$form = [
    'scholar_id' => '',
    'school_year' => CURRENT_SCHOOL_YEAR,
    'semester' => CURRENT_SEMESTER,
    'gwa' => '',
    'total_units' => '',
    'source_document_id' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    foreach (array_keys($form) as $key) {
        $form[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    }
    $result = validateGwaInput($pdo, $_POST);
    if ($result['error'] !== null) {
        $message = $result['error'];
    } else {
        $v = $result['values'];
        $stmt = $pdo->prepare(
            'INSERT INTO gwa_records
                (scholar_id, school_year, semester, gwa, total_units, source_document_id, encoded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE gwa = VALUES(gwa), total_units = VALUES(total_units),
                source_document_id = VALUES(source_document_id), encoded_by = VALUES(encoded_by),
                encoded_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $v['scholar_id'], $v['school_year'], $v['semester'], $v['gwa'],
            $v['total_units'], $v['source_document_id'], (int)$_SESSION['user_id'],
        ]);
        evaluateScholar($pdo, $v['scholar_id']);
        $success = 'Official GWA saved from the verified Certificate of Grades. Active assignments were re-evaluated.';
        $form['gwa'] = '';
        $form['total_units'] = '';
        $form['source_document_id'] = '';
    }
}

$scholars = $pdo->query(
    'SELECT s.id, s.fname, s.mname, s.lname, s.suffix, u.email
     FROM scholars s JOIN users u ON u.id = s.user_id
     ORDER BY s.lname, s.fname, s.id'
)->fetchAll();
$sources = $pdo->query(
    "SELECT d.id, d.scholar_id, d.school_year, d.semester, d.document_type, req.name AS requirement_name,
            s.fname, s.mname, s.lname, s.suffix
     FROM documents d
     JOIN scholars s ON s.id = d.scholar_id
     LEFT JOIN requirements req ON req.id = d.requirement_id
     WHERE d.status = 'Verified'
       AND (
            (d.requirement_id IS NOT NULL AND BINARY req.name = BINARY 'Certificate of Grades')
            OR (d.requirement_id IS NULL AND BINARY d.document_type = BINARY 'Certificate of Grades')
       )
     ORDER BY d.school_year DESC, d.semester, s.lname, s.fname"
)->fetchAll();
$records = $pdo->query(
    'SELECT g.*, s.fname, s.mname, s.lname, s.suffix, u.email
     FROM gwa_records g JOIN scholars s ON s.id = g.scholar_id JOIN users u ON u.id = s.user_id
     ORDER BY g.school_year DESC, g.semester, s.lname, s.fname LIMIT 100'
)->fetchAll();
$pageTitle = 'Enter Official GWA';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Enter official GWA</h1>
<?php if ($message): ?><p class="form-error"><?php echo h($message); ?></p><?php endif; ?>
<?php if ($success): ?><p class="form-success"><?php echo h($success); ?></p><?php endif; ?>

<div class="card">
    <p class="gwa-help">Use the verified Certificate of Grades. Enter its official GWA and total units.</p>
    <form method="POST">
        <?php echo csrfField(); ?>
        <label for="scholar_id">Scholar</label>
        <select id="scholar_id" name="scholar_id" required>
            <option value="">Choose scholar</option>
            <?php foreach ($scholars as $scholar): ?>
                <option value="<?php echo (int)$scholar['id']; ?>" <?php echo $form['scholar_id'] === (string)$scholar['id'] ? 'selected' : ''; ?>>
                    <?php echo h(formatScholarName($scholar) . ' — ' . $scholar['email']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="school_year">School year</label>
        <input id="school_year" name="school_year" value="<?php echo h($form['school_year']); ?>" pattern="\d{4}-\d{4}" required>
        <label for="semester">Semester</label>
        <select id="semester" name="semester" required>
            <?php foreach (['1st', '2nd', 'Summer'] as $semester): ?>
                <option value="<?php echo $semester; ?>" <?php echo $form['semester'] === $semester ? 'selected' : ''; ?>><?php echo $semester; ?></option>
            <?php endforeach; ?>
        </select>
        <label for="gwa">Official GWA</label>
        <input id="gwa" name="gwa" type="number" min="1" max="5" step="0.01" value="<?php echo h($form['gwa']); ?>" required>
        <label for="total_units">Total units</label>
        <input id="total_units" name="total_units" type="number" min="1" step="1" value="<?php echo h($form['total_units']); ?>" required>
        <label for="source_document_id">Verified Certificate of Grades for the same scholar and term</label>
        <select id="source_document_id" name="source_document_id" required>
            <option value="">Choose source document</option>
            <?php foreach ($sources as $source): ?>
                <option value="<?php echo (int)$source['id']; ?>"
                    data-scholar="<?php echo (int)$source['scholar_id']; ?>"
                    data-year="<?php echo h($source['school_year']); ?>"
                    data-semester="<?php echo h($source['semester']); ?>"
                    <?php echo $form['source_document_id'] === (string)$source['id'] ? 'selected' : ''; ?>>
                    #<?php echo (int)$source['id']; ?> — <?php echo h(formatScholarName($source) . ' — ' . $source['school_year'] . ' ' . $source['semester']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Save GWA and re-evaluate</button>
    </form>
</div>

<p><a class="btn btn--secondary" href="/GRANTED/admin/import_gwa.php">Import GWA records from CSV</a>
   <a class="btn btn--secondary" href="/GRANTED/admin/download_gwa_template.php">Download GWA CSV template</a></p>

<div class="card">
    <h2>Recent GWA records</h2>
    <?php if (!$records): ?><p>No GWA records entered yet.</p><?php else: ?>
    <table class="data-table"><thead><tr><th>Scholar</th><th>Term</th><th>GWA</th><th>Units</th><th>Source document</th></tr></thead><tbody>
        <?php foreach ($records as $record): ?>
            <tr><td><?php echo h(formatScholarName($record)); ?></td>
                <td><?php echo h($record['school_year'] . ' ' . $record['semester']); ?></td>
                <td><?php echo h((string)$record['gwa']); ?></td><td><?php echo (int)$record['total_units']; ?></td>
                <td>#<?php echo (int)$record['source_document_id']; ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
</div>

<script>
(function () {
    var scholar = document.getElementById('scholar_id');
    var year = document.getElementById('school_year');
    var semester = document.getElementById('semester');
    var source = document.getElementById('source_document_id');
    function filterSources() {
        Array.prototype.forEach.call(source.options, function (option) {
            if (!option.value) { option.hidden = false; return; }
            option.hidden = option.dataset.scholar !== scholar.value
                || option.dataset.year !== year.value
                || option.dataset.semester !== semester.value;
            if (option.hidden && option.selected) { source.value = ''; }
        });
    }
    [scholar, year, semester].forEach(function (element) { element.addEventListener('change', filterSources); });
    filterSources();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
