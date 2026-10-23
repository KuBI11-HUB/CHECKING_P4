<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$message = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $result = validateGradeInput($pdo, $_POST);

    if ($result['error'] !== null) {
        $message = $result['error'];
    } else {
        $v = $result['values'];
        $stmt = $pdo->prepare(
            'INSERT INTO grades (scholar_id, subject, grade, units, school_year, semester) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$v['scholar_id'], $v['subject'], $v['grade'], $v['units'], $v['school_year'], $v['semester']]);

        // evaluateScholar() recalculates GPA/units for the current term, then re-runs the rules.
        $status = evaluateScholar($pdo, $v['scholar_id']);

        $success = 'Grade recorded. Recalculated status: ' . sanitize($status ?? 'PENDING') . '.';
    }
}
$scholars = $pdo->query(
    'SELECT s.id, s.full_name, st.name AS type_name FROM scholars s
     JOIN scholarship_types st ON st.id = s.scholarship_type_id
     ORDER BY s.full_name'
)->fetchAll();

$pageTitle = 'Enter Grades';

require_once __DIR__ . '/../includes/header.php';
?>

<h1>Enter grades</h1>
<p style="margin-bottom:1rem;"><a class="btn btn--secondary" href="/GRANTED/admin/import_grades.php" style="margin-top:0;"><span class="icon-label"><?php echo uiIcon('upload'); ?>Import many grades from a CSV file</span></a></p>
<div class="card">
    <?php if ($message): ?><p class="form-error"><?php echo sanitize($message); ?></p><?php endif; ?>
    <?php if ($success): ?><p class="form-success"><?php echo sanitize($success); ?></p><?php endif; ?>

    <?php if (empty($scholars)): ?>
        <p>You need to <a href="/GRANTED/admin/enroll_scholar.php">enroll a scholar</a> first.</p>
    <?php else: ?>
    <form method="POST" action="">
        <?php echo csrfField(); ?>
        <label for="scholar_id">Scholar</label>
        <select id="scholar_id" name="scholar_id" required>
            <?php foreach ($scholars as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>">
                    <?php echo sanitize($s['full_name']); ?> — <?php echo sanitize($s['type_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="subject">Subject</label>
        <input type="text" id="subject" name="subject" placeholder="e.g. CS 101" required>

        <label for="grade">Grade</label>
        <input type="number" id="grade" name="grade" step="0.01" min="1" max="5" placeholder="e.g. 1.75 or 3.50" required>

        <label for="units">Units</label>
        <input type="number" id="units" name="units" min="1" max="10" required>

        <label for="school_year">School year</label>
        <input type="text" id="school_year" name="school_year" value="<?php echo CURRENT_SCHOOL_YEAR; ?>" placeholder="2026-2027" pattern="\d{4}-\d{4}" title="Format: 2026-2027" required>

        <label for="semester">Semester</label>
        <select id="semester" name="semester" required>
            <option value="1st" <?php echo (CURRENT_SEMESTER === '1st') ? 'selected' : ''; ?>>1st</option>
            <option value="2nd" <?php echo (CURRENT_SEMESTER === '2nd') ? 'selected' : ''; ?>>2nd</option>
            <option value="Summer" <?php echo (CURRENT_SEMESTER === 'Summer') ? 'selected' : ''; ?>>Summer</option>
        </select>

        <button type="submit">Save grade &amp; recalculate</button>
    </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
