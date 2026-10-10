<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$message = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $scholarId = (int)($_POST['scholar_id'] ?? 0);
    $subject = sanitize($_POST['subject'] ?? '');
    $grade = $_POST['grade'] ?? '';
    $units = (int)($_POST['units'] ?? 0);
    $schoolYear = sanitize($_POST['school_year'] ?? '');
    $semester = $_POST['semester'] ?? '';

    if (!$scholarId || $subject === '' || $grade === '' || !$units || $schoolYear === '' || $semester === '') {
        $message = 'Please fill in every field.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO grades (scholar_id, subject, grade, units, school_year, semester) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$scholarId, $subject, $grade, $units, $schoolYear, $semester]);

        // Recalculate this scholar's GPA/units, then re-run the rule engine —
        // this is the auto-trigger from the pseudocode (Module 3 -> Module 5).
        recalculateGpaAndUnits($pdo, $scholarId);
        $status = evaluateScholar($pdo, $scholarId);

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
        <input type="text" id="grade" name="grade" placeholder="e.g. 1.75 or 3.50" required>

        <label for="units">Units</label>
        <input type="number" id="units" name="units" min="1" max="10" required>

        <label for="school_year">School year</label>
        <input type="text" id="school_year" name="school_year" value="<?php echo CURRENT_SCHOOL_YEAR; ?>" placeholder="2026-2027" required>

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
