<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$filters = normalizeResultFilters($_GET);
$paging = normalizeResultPaging($_GET);
$options = getResultFilterOptions($pdo);

$matching = countResults($pdo, $filters);
$totalPages = max(1, (int)ceil($matching / $paging['per_page']));
$paging['page'] = min($paging['page'], $totalPages);          // a page past the end shows the last page
$offset = ($paging['page'] - 1) * $paging['per_page'];
$results = getResults($pdo, $filters, $paging['per_page'], $offset);

$totalScholars = (int)$pdo->query('SELECT COUNT(*) FROM scholars')->fetchColumn();
$statusCounts = ['PASS' => 0, 'PENDING' => 0, 'FAIL' => 0];
foreach ($pdo->query('SELECT current_status, COUNT(*) AS c FROM scholars GROUP BY current_status')->fetchAll() as $row) {
    if (isset($statusCounts[$row['current_status']])) {
        $statusCounts[$row['current_status']] = (int)$row['c'];
    }
}
$exportQuery = resultsQueryString($filters);                          // the CSV export ignores paging
$_SESSION['results_query'] = resultsQueryString($filters, $paging);   // "Back to results" on a scholar page returns to this view

$resultsUrl = static function (array $override = []) use ($filters, $paging): string {
    $query = resultsQueryString($filters, $paging, $override);
    return '/GRANTED/admin/dashboard.php' . ($query !== '' ? '?' . $query : '');
};

/** Hidden inputs that carry the current filters through a GET form. Empty values are left out. */
$hiddenFields = static function (array $values): string {
    $html = '';
    foreach ($values as $name => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $html .= '<input type="hidden" name="' . sanitize((string)$name) . '" value="' . sanitize((string)$value) . '">';
    }
    return $html;
};

// Active filters shown as removable chips. Status has its own chip row, so it is not listed here.
$activeFilters = [];
if ($filters['q'] !== '') {
    $activeFilters[] = ['Search', $filters['q'], ['q' => '']];
}
if ($filters['program'] !== '' && in_array($filters['program'], $options['programs'], true)) {
    $activeFilters[] = ['Program', $filters['program'], ['program' => '']];
}
foreach ($options['types'] as $type) {
    if ($filters['scholarship_type_id'] === (int)$type['id']) {
        $activeFilters[] = ['Type', $type['name'], ['scholarship_type_id' => 0]];
    }
}

$statusChips = ['' => 'All', 'PASS' => 'PASS', 'FAIL' => 'FAIL', 'PENDING' => 'PENDING'];

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-page dashboard-page--admin">
<div class="page-head">
    <div>
        <h1>Scholars</h1>
        <p class="page-head__sub">Results for <strong><?php echo h(CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER); ?></strong></p>
    </div>
</div>

<div class="stat-strip" role="group" aria-label="Enrolled scholars by calculated status">
    <div class="stat"><span class="stat__label">Total scholars</span><span class="stat__value"><?php echo $totalScholars; ?></span></div>
    <div class="stat stat--pass"><span class="stat__label">PASS</span><span class="stat__value"><?php echo $statusCounts['PASS']; ?></span></div>
    <div class="stat stat--pending"><span class="stat__label">PENDING</span><span class="stat__value"><?php echo $statusCounts['PENDING']; ?></span></div>
    <div class="stat stat--fail"><span class="stat__label">FAIL</span><span class="stat__value"><?php echo $statusCounts['FAIL']; ?></span></div>
</div>

<div class="card results-card">
    <div class="results-toolbar">
        <form method="GET" action="/GRANTED/admin/dashboard.php" class="search-form" role="search">
            <label for="q" class="visually-hidden">Search name or email</label>
            <input type="search" id="q" name="q" value="<?php echo sanitize($filters['q']); ?>" maxlength="100" placeholder="Search name or email">
            <?php echo $hiddenFields([
                'program' => $filters['program'],
                'status' => $filters['status'],
                'scholarship_type_id' => $filters['scholarship_type_id'] > 0 ? $filters['scholarship_type_id'] : '',
                'per_page' => $paging['per_page'] !== RESULTS_PER_PAGE_DEFAULT ? $paging['per_page'] : '',
            ]); ?>
            <button type="submit" class="btn btn--secondary"><span class="icon-label"><?php echo uiIcon('search'); ?>Search</span></button>
        </form>

        <nav class="status-chips" aria-label="Filter by calculated status">
            <?php foreach ($statusChips as $value => $label): ?>
                <a class="status-chip" href="<?php echo sanitize($resultsUrl(['status' => $value, 'page' => 1])); ?>"<?php echo $filters['status'] === $value ? ' aria-current="true"' : ''; ?>><?php echo h($label); ?></a>
            <?php endforeach; ?>
        </nav>
    </div>

    <details class="filter-block" open>
        <summary>Filters<?php echo $activeFilters ? ' · ' . count($activeFilters) . ' active' : ''; ?></summary>
        <form method="GET" action="/GRANTED/admin/dashboard.php" class="filter-form">
            <?php echo $hiddenFields(['q' => $filters['q'], 'status' => $filters['status']]); ?>
            <div class="filter-form__field">
                <label for="program">Program</label>
                <select id="program" name="program">
                    <option value="">All programs</option>
                    <?php foreach ($options['programs'] as $program): ?>
                        <?php // The value stays exactly as stored so it matches in SQL; only the label is decoded. ?>
                        <option value="<?php echo sanitize($program); ?>" <?php echo $filters['program'] === $program ? 'selected' : ''; ?>><?php echo h($program); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-form__field">
                <label for="scholarship_type_id">Scholarship type</label>
                <select id="scholarship_type_id" name="scholarship_type_id">
                    <option value="">All types</option>
                    <?php foreach ($options['types'] as $type): ?>
                        <option value="<?php echo (int)$type['id']; ?>" <?php echo $filters['scholarship_type_id'] === (int)$type['id'] ? 'selected' : ''; ?>><?php echo h($type['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-form__field filter-form__field--narrow">
                <label for="per_page">Rows per page</label>
                <select id="per_page" name="per_page">
                    <?php foreach (RESULTS_PER_PAGE_OPTIONS as $size): ?>
                        <option value="<?php echo $size; ?>" <?php echo $paging['per_page'] === $size ? 'selected' : ''; ?>><?php echo $size; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-form__actions">
                <button type="submit">Apply filters</button>
                <a class="btn btn--secondary" href="/GRANTED/admin/dashboard.php">Clear filters</a>
                <a class="btn btn--secondary" href="/GRANTED/admin/export_results.php<?php echo $exportQuery !== '' ? '?' . sanitize($exportQuery) : ''; ?>">Export CSV</a>
            </div>
        </form>
    </details>

    <?php if ($activeFilters): ?>
        <div class="filter-chips" aria-label="Active filters">
            <?php foreach ($activeFilters as [$label, $value, $override]): ?>
                <span class="filter-chip"><?php echo h($label . ': ' . $value); ?>
                    <a href="<?php echo sanitize($resultsUrl($override + ['page' => 1])); ?>" aria-label="Remove filter <?php echo h($label); ?>" title="Remove this filter">&times;</a></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="results-summary">
        <?php if ($matching > 0): ?>
            Showing <strong><?php echo $offset + 1; ?>&ndash;<?php echo $offset + count($results); ?></strong>
            of <strong><?php echo $matching; ?></strong> scholars<?php echo $matching !== $totalScholars ? ' (' . $totalScholars . ' enrolled)' : ''; ?>.
        <?php else: ?>
            Showing <strong>0</strong> of <strong><?php echo $totalScholars; ?></strong> scholars.
        <?php endif; ?>
    </p>

    <div class="table-scroll">
    <table class="data-table data-table--sticky">
        <thead>
            <tr><th>Scholar</th><th>Program</th><th>Scholarship type</th><th class="num">GPA</th><th class="num">Units</th><th>Calculated status</th><th>Office decision</th></tr>
        </thead>
        <tbody>
        <?php foreach ($results as $s): ?>
            <tr>
                <td><a href="/GRANTED/admin/scholar_detail.php?id=<?php echo (int)$s['id']; ?>"><?php echo h($s['full_name']); ?></a></td>
                <td><?php echo $s['program'] ? h($s['program']) : '—'; ?></td>
                <td><?php echo h($s['type_name']); ?></td>
                <td class="num"><?php echo $s['current_gpa'] !== null ? h((string)$s['current_gpa']) : '—'; ?></td>
                <td class="num"><?php echo $s['current_units'] !== null ? h((string)$s['current_units']) : '—'; ?></td>
                <td><span class="status-badge <?php echo statusBadgeClass($s['current_status']); ?>"><?php echo h($s['current_status']); ?></span></td>
                <td><?php echo $s['office_decision'] ? '<span class="decision-chip">' . h(str_replace('_', ' ', $s['office_decision'])) . '</span>' : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($results)): ?>
            <tr><td colspan="7" class="empty-state"><span class="empty-state__content"><?php echo uiIcon('inbox'); ?>
                <?php if ($totalScholars === 0): ?>
                    No scholars enrolled yet. <a href="/GRANTED/admin/enroll_scholar.php">Enroll the first scholar</a>.
                <?php else: ?>
                    No scholars match these filters. <a href="/GRANTED/admin/dashboard.php">Clear filters</a>.
                <?php endif; ?>
            </span></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="pager" aria-label="Pages of results">
            <?php if ($paging['page'] > 1): ?>
                <a class="btn btn--secondary" href="<?php echo sanitize($resultsUrl(['page' => $paging['page'] - 1])); ?>">&larr; Previous</a>
            <?php else: ?>
                <span class="pager__disabled">&larr; Previous</span>
            <?php endif; ?>
            <span>Page <?php echo $paging['page']; ?> of <?php echo $totalPages; ?></span>
            <?php if ($paging['page'] < $totalPages): ?>
                <a class="btn btn--secondary" href="<?php echo sanitize($resultsUrl(['page' => $paging['page'] + 1])); ?>">Next &rarr;</a>
            <?php else: ?>
                <span class="pager__disabled">Next &rarr;</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
</div>

<script>
    // On phones the filter block starts collapsed (the chips above the table still show what is active).
    (function () {
        var block = document.querySelector('.filter-block');
        if (block && window.matchMedia('(max-width: 760px)').matches) { block.open = false; }
    })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>