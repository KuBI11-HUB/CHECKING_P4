<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$filters = normalizeResultFilters($_GET);
$options = getResultFilterOptions($pdo);
$results = getResults($pdo, $filters);
$totalScholars = (int)$pdo->query('SELECT COUNT(*) FROM scholars')->fetchColumn();
$exportQuery = http_build_query(array_filter($filters, fn($value) => $value !== '' && $value !== 0));

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
    <h2>Results</h2>

    <form method="GET" action="/GRANTED/admin/dashboard.php" style="display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end; margin-bottom:1.25rem;">
        <div style="flex:1 1 12rem;">
            <label for="program">Program</label>
            <select id="program" name="program">
                <option value="">All programs</option>
                <?php foreach ($options['programs'] as $program): ?>
                    <?php // The value stays exactly as stored so it matches in SQL; only the label is decoded. ?>
                    <option value="<?php echo sanitize($program); ?>" <?php echo $filters['program'] === $program ? 'selected' : ''; ?>><?php echo h($program); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1 1 10rem;">
            <label for="status">Calculated status</label>
            <select id="status" name="status">
                <option value="">All statuses</option>
                <?php foreach ($options['statuses'] as $status): ?>
                    <option value="<?php echo h($status); ?>" <?php echo $filters['status'] === $status ? 'selected' : ''; ?>><?php echo h($status); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1 1 12rem;">
            <label for="scholarship_type_id">Scholarship type</label>
            <select id="scholarship_type_id" name="scholarship_type_id">
                <option value="">All types</option>
                <?php foreach ($options['types'] as $type): ?>
                    <option value="<?php echo (int)$type['id']; ?>" <?php echo $filters['scholarship_type_id'] === (int)$type['id'] ? 'selected' : ''; ?>><?php echo h($type['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex; gap:0.5rem;">
            <button type="submit" style="margin-top:0;">Apply filters</button>
            <a class="btn" href="/GRANTED/admin/dashboard.php" style="margin-top:0;">Clear filters</a>
            <a class="btn" href="/GRANTED/admin/export_results.php<?php echo $exportQuery !== '' ? '?' . sanitize($exportQuery) : ''; ?>" style="margin-top:0;">Export CSV</a>
        </div>
    </form>

    <p style="margin-bottom:1rem;">
        Results for <strong><?php echo h(CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER); ?></strong>.
        Showing <?php echo count($results); ?> of <?php echo $totalScholars; ?> scholars.
    </p>

    <table class="data-table">
        <thead>
            <tr><th>Scholar</th><th>Program</th><th>Scholarship type</th><th>GPA</th><th>Units</th><th>Calculated status</th><th>Office decision</th></tr>
        </thead>
        <tbody>
        <?php foreach ($results as $s): ?>
            <tr>
                <td><a href="/GRANTED/admin/scholar_detail.php?id=<?php echo (int)$s['id']; ?>"><?php echo h($s['full_name']); ?></a></td>
                <td><?php echo $s['program'] ? h($s['program']) : '—'; ?></td>
                <td><?php echo h($s['type_name']); ?></td>
                <td><?php echo $s['current_gpa'] !== null ? h((string)$s['current_gpa']) : '—'; ?></td>
                <td><?php echo $s['current_units'] !== null ? h((string)$s['current_units']) : '—'; ?></td>
                <td><span class="status-badge <?php echo statusBadgeClass($s['current_status']); ?>"><?php echo h($s['current_status']); ?></span></td>
                <td><?php echo $s['office_decision'] ? h(str_replace('_', ' ', $s['office_decision'])) : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($results)): ?>
            <tr><td colspan="7"><?php echo $totalScholars === 0 ? 'No scholars enrolled yet.' : 'No scholars match these filters.'; ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
