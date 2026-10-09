<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$scholars = $pdo->query(
    'SELECT s.*, st.name AS type_name FROM scholars s
     JOIN scholarship_types st ON st.id = s.scholarship_type_id
     ORDER BY s.full_name'
)->fetchAll();
$grades = $pdo->query(
    'SELECT g.subject, g.grade, g.units, g.school_year, g.semester, s.full_name
     FROM grades g
     JOIN scholars s ON s.id = g.scholar_id
     ORDER BY s.full_name, g.school_year DESC,
         FIELD(g.semester, "Summer", "2nd", "1st") DESC, g.subject'
)->fetchAll();

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Admin dashboard</h1>
<p>Logged in as <?php echo sanitize($_SESSION['email']); ?>.</p>

<div class="page-actions">
    <a class="btn" href="/GRANTED/admin/enroll_scholar.php">+ Enroll scholar</a>
    <a class="btn" href="/GRANTED/admin/enter_grades.php">+ Enter grades</a>
</div>

<div class="card">
    <h2>Compliance results</h2>
    <table class="data-table">
        <thead>
            <tr><th>Scholar</th><th>Program</th><th>Scholarship type</th><th>GPA (<?php echo sanitize(CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER); ?>)</th><th>Units (current term)</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ($scholars as $s): ?>
            <tr>
                <td><?php echo sanitize($s['full_name']); ?></td>
                <td><?php echo sanitize($s['program'] ?: '—'); ?></td>
                <td><?php echo sanitize($s['type_name']); ?></td>
                <td><?php echo $s['current_gpa'] !== null ? sanitize((string)$s['current_gpa']) : '—'; ?></td>
                <td><?php echo $s['current_units'] !== null ? sanitize((string)$s['current_units']) : '—'; ?></td>
                <td><span class="status-badge <?php echo statusBadgeClass($s['current_status']); ?>"><?php echo sanitize($s['current_status']); ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($scholars)): ?>
            <tr><td colspan="6">No scholars enrolled yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Recorded grades (all terms)</h2>
    <table class="data-table">
        <thead>
            <tr><th>Scholar</th><th>Subject</th><th>Grade</th><th>Units</th><th>School year</th><th>Semester</th></tr>
        </thead>
        <tbody>
        <?php foreach ($grades as $grade): ?>
            <tr>
                <td><?php echo sanitize($grade['full_name']); ?></td>
                <td><?php echo sanitize($grade['subject']); ?></td>
                <td><?php echo sanitize((string)$grade['grade']); ?></td>
                <td><?php echo (int)$grade['units']; ?></td>
                <td><?php echo sanitize($grade['school_year']); ?></td>
                <td><?php echo sanitize($grade['semester']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($grades)): ?>
            <tr><td colspan="6">No grades have been recorded yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
