<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$message = '';
$types = $pdo->query('SELECT * FROM scholarship_types ORDER BY name')->fetchAll();
$requirements = $pdo->query('SELECT id, name FROM requirements WHERE is_active = 1 ORDER BY name')->fetchAll();
// Read-only list filter from the URL (?type=ID). Anything that is not an existing type means "all".
$typeFilter = is_string($_GET['type'] ?? null) && ctype_digit($_GET['type']) ? (int)$_GET['type'] : 0;
if (!in_array($typeFilter, array_map('intval', array_column($types, 'id')), true)) {
    $typeFilter = 0;
}
$statusFilter = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['active', 'inactive'], true)
    ? $_GET['status']
    : 'all';
$rulesUrl = static function (int $typeId = 0, ?string $status = null) use ($statusFilter): string {
    $params = [];
    if ($typeId > 0) {
        $params['type'] = $typeId;
    }
    $status = $status ?? $statusFilter;
    if ($status !== 'all') {
        $params['status'] = $status;
    }
    return '/GRANTED/admin/rules.php' . ($params ? '?' . http_build_query($params) : '');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (isset($_POST['deactivate'])) {
        $ruleId = (int)$_POST['deactivate'];
        $stmt = $pdo->prepare('SELECT scholarship_type_id FROM rules WHERE id = ?');
        $stmt->execute([$ruleId]);
        $typeId = $stmt->fetchColumn();

        $stmt = $pdo->prepare('UPDATE rules SET is_active = 0 WHERE id = ?');
        $stmt->execute([$ruleId]);

        // Without this, scholars keep an old FAIL/PASS caused by the rule that was just switched off.
        if ($typeId !== false) {
            reevaluateScholarshipType($pdo, (int)$typeId);
        }
        redirectTo($rulesUrl($typeFilter));
    }

    $result = validateRuleInput($pdo, $_POST);
    if ($result['error'] !== null) {
        $message = $result['error'];
    } else {
        $v = $result['values'];
        $stmt = $pdo->prepare(
            'INSERT INTO rules (scholarship_type_id, rule_type, requirement_id, operator, threshold_value, school_year, semester, due_date, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$v['scholarship_type_id'], $v['rule_type'], $v['requirement_id'], $v['operator'],
                        $v['threshold_value'], $v['school_year'], $v['semester'], $v['due_date']]);
        // Assignment statuses must reflect the new rule right away.
        reevaluateScholarshipType($pdo, $v['scholarship_type_id']);
        redirectTo($rulesUrl($typeFilter > 0 ? $v['scholarship_type_id'] : 0));
    }
}

$flash = takeFlash();
$conditions = [];
$params = [];
if ($typeFilter > 0) {
    $conditions[] = 'r.scholarship_type_id = ?';
    $params[] = $typeFilter;
}
if ($statusFilter !== 'all') {
    $conditions[] = 'r.is_active = ?';
    $params[] = $statusFilter === 'active' ? 1 : 0;
}
$stmt = $pdo->prepare(
    'SELECT r.*, st.name AS type_name, req.name AS requirement_name FROM rules r
     JOIN scholarship_types st ON st.id = r.scholarship_type_id
     LEFT JOIN requirements req ON req.id = r.requirement_id'
    . ($conditions ? ' WHERE ' . implode(' AND ', $conditions) : '')
    . ' ORDER BY r.is_active DESC, st.name, r.rule_type'
);
$stmt->execute($params);
$rules = $stmt->fetchAll();

$pageTitle = 'Rules';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Rules</h1>

<?php echo flashBox($flash); ?>

<div class="card">
    <h2>Add a rule</h2>
    <?php if ($message): ?><p class="form-error"><?php echo sanitize($message); ?></p><?php endif; ?>
    <?php if (empty($types)): ?>
        <p>You need at least one <a href="/GRANTED/admin/scholarship_types.php">scholarship type</a> before adding a rule.</p>
    <?php else: ?>
    <form method="POST" action="">
        <?php echo csrfField(); ?>
        <label for="scholarship_type_id">Scholarship type</label>
        <select id="scholarship_type_id" name="scholarship_type_id" required>
            <?php foreach ($types as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>" <?php echo $typeFilter === (int)$t['id'] ? 'selected' : ''; ?>><?php echo sanitize($t['name']); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="rule_type">Rule type</label>
        <select id="rule_type" name="rule_type" required>
            <option value="GWA">GWA</option>
            <option value="UNIT_LOAD">Unit load</option>
            <option value="DOCUMENT">Document</option>
            <option value="DEADLINE">Deadline (not evaluated yet)</option>
        </select>

        <div id="operator_group">
        <label for="operator">Operator</label>
        <select id="operator" name="operator" required>
            <option value=">=">&gt;= (at least)</option>
            <option value="<="><= (at most)</option>
            <option value=">">&gt; (more than)</option>
            <option value="<"><  (less than)</option>
            <option value="==">== (exactly)</option>
        </select>
        </div>

        <label for="threshold_value" id="threshold_label">Threshold</label>
        <input type="text" id="threshold_value" name="threshold_value" placeholder="Numeric threshold" required>

        <label for="requirement_id">Requirement (for DOCUMENT rule)</label>
        <select id="requirement_id" name="requirement_id">
            <option value="">Use exact legacy document label instead</option>
            <?php foreach ($requirements as $requirement): ?>
                <option value="<?php echo (int)$requirement['id']; ?>"><?php echo h($requirement['name']); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="school_year">School year</label>
        <input type="text" id="school_year" name="school_year" value="<?php echo CURRENT_SCHOOL_YEAR; ?>" placeholder="2026-2027" required>

        <label for="semester">Semester</label>
        <select id="semester" name="semester" required>
            <option value="1st" <?php echo (CURRENT_SEMESTER === '1st') ? 'selected' : ''; ?>>1st</option>
            <option value="2nd" <?php echo (CURRENT_SEMESTER === '2nd') ? 'selected' : ''; ?>>2nd</option>
            <option value="Summer" <?php echo (CURRENT_SEMESTER === 'Summer') ? 'selected' : ''; ?>>Summer</option>
        </select>

        <div id="due_date_group">
        <label for="due_date">Document deadline (optional)</label>
        <input type="date" id="due_date" name="due_date">
        </div>

        <button type="submit">Add rule</button>
    </form>
    <script>
        var ruleType = document.getElementById('rule_type');
        var threshold = document.getElementById('threshold_value');
        var requirement = document.getElementById('requirement_id');

        function updateRuleForm() {
            var isDocument = ruleType.value === 'DOCUMENT';
            var isMappedDocument = isDocument && requirement.value !== '';
            document.getElementById('operator_group').style.display = isDocument ? 'none' : '';
            document.getElementById('due_date_group').style.display = isDocument ? '' : 'none';
            requirement.disabled = !isDocument;
            threshold.required = !isMappedDocument;
            threshold.disabled = isMappedDocument;
            document.getElementById('threshold_label').textContent = isDocument
                ? (isMappedDocument ? 'Legacy document name (not needed for a mapped requirement)' : 'Legacy document name (exact, if unmapped)')
                : 'Threshold';
            threshold.type = 'text';
            threshold.step = 'any';
            threshold.min = '0';
            threshold.placeholder = isMappedDocument ? 'Using the selected requirement'
                : (isDocument ? 'Only needed for an unmapped legacy rule' : 'Numeric threshold; UNIT_LOAD uses verified Certificate of Grades units');
        }

        ruleType.addEventListener('change', updateRuleForm);
        requirement.addEventListener('change', updateRuleForm);
        updateRuleForm();
    </script>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Existing rules</h2>
    <?php if (count($types) > 1): ?>
    <nav class="status-chips" aria-label="Filter rules by scholarship type" style="margin-bottom:1rem;">
        <a class="status-chip" href="<?php echo $rulesUrl(); ?>"<?php echo $typeFilter === 0 ? ' aria-current="true"' : ''; ?>>All types</a>
        <?php foreach ($types as $t): ?>
            <a class="status-chip" href="<?php echo $rulesUrl((int)$t['id']); ?>"<?php echo $typeFilter === (int)$t['id'] ? ' aria-current="true"' : ''; ?>><?php echo h($t['name']); ?></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <nav class="status-chips" aria-label="Filter rules by active status" style="margin-bottom:1rem;">
        <a class="status-chip" href="<?php echo $rulesUrl($typeFilter, 'all'); ?>"<?php echo $statusFilter === 'all' ? ' aria-current="true"' : ''; ?>>All statuses</a>
        <a class="status-chip" href="<?php echo $rulesUrl($typeFilter, 'active'); ?>"<?php echo $statusFilter === 'active' ? ' aria-current="true"' : ''; ?>>Active</a>
        <a class="status-chip" href="<?php echo $rulesUrl($typeFilter, 'inactive'); ?>"<?php echo $statusFilter === 'inactive' ? ' aria-current="true"' : ''; ?>>Inactive</a>
    </nav>
    <p class="results-summary">Showing <strong><?php echo count($rules); ?></strong> rule<?php echo count($rules) === 1 ? '' : 's'; ?><?php echo $typeFilter > 0 ? ' for this type' : ''; ?>.</p>
    <ul class="rule-list">
        <?php foreach ($rules as $r): ?>
            <li class="rule-list__item">
                <div class="rule-list__details">
                    <strong><?php echo sanitize($r['type_name']); ?></strong>
                    <span class="rule-list__summary">
                        <?php echo h(describeRule($r)); ?> — <?php echo h($r['school_year'] . ', ' . $r['semester']); ?> semester
                        <?php if (!$r['is_active']): ?><em>(inactive)</em><?php endif; ?>
                    </span>
                </div>
                <div class="rule-list__actions">
                    <a class="rule-list__action rule-list__action--edit" href="/GRANTED/admin/edit_rule.php?id=<?php echo (int)$r['id']; ?>" data-confirmation-hide>Edit</a>
                    <?php if ($r['is_active']): ?>
                        <form method="POST" action="" data-confirmation>
                            <?php echo csrfField(); ?>
                            <button class="rule-list__action rule-list__action--deactivate" type="button" data-confirmation-trigger>Deactivate</button>
                            <div class="form-info action-confirmation" data-confirmation-panel hidden>
                                <p>Deactivate this rule? It will stop applying on the next evaluation.</p>
                                <div class="action-confirmation__actions">
                                    <button class="btn btn--secondary" type="button" data-confirmation-cancel>Cancel</button>
                                    <button class="rule-list__action rule-list__action--deactivate" type="submit" name="deactivate" value="<?php echo (int)$r['id']; ?>">Confirm</button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
        <?php if (empty($rules)): ?><li><?php echo $typeFilter > 0 ? 'No rules for this type yet.' : 'No rules yet.'; ?></li><?php endif; ?>
    </ul>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
