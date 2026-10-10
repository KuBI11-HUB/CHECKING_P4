<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$message = '';

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
        redirectTo('/GRANTED/admin/rules.php');
    }

    $scholarshipTypeId = (int)($_POST['scholarship_type_id'] ?? 0);
    $ruleType = $_POST['rule_type'] ?? '';
    $operator = $_POST['operator'] ?? '';
    $thresholdValue = sanitize($_POST['threshold_value'] ?? '');
    $schoolYear = sanitize($_POST['school_year'] ?? '');
    $semester = $_POST['semester'] ?? '';
    $dueDate = $_POST['due_date'] ?? '';
    $dueDate = ($dueDate !== '' && $ruleType === 'DOCUMENT') ? $dueDate : null;
    if ($dueDate !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) || strtotime($dueDate) === false)) {
        $dueDate = null;
    }

    $validRuleTypes = ['GPA', 'UNIT_LOAD', 'DOCUMENT'];
    $validOperators = ['>=', '<=', '>', '<', '=='];
    $validSemesters = ['1st', '2nd', 'Summer'];

    if ($ruleType === 'DOCUMENT') {
        $operator = '==';
    }

    if (!$scholarshipTypeId || !in_array($ruleType, $validRuleTypes, true)
        || !in_array($operator, $validOperators, true) || $thresholdValue === ''
        || $schoolYear === '' || !in_array($semester, $validSemesters, true)) {
        $message = 'Please fill in every field correctly.';
    } elseif ($ruleType !== 'DOCUMENT' && !is_numeric($thresholdValue)) {
        $message = 'The threshold for a GPA or unit load rule must be a number.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO rules (scholarship_type_id, rule_type, operator, threshold_value, school_year, semester, due_date, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$scholarshipTypeId, $ruleType, $operator, $thresholdValue, $schoolYear, $semester, $dueDate]);
        // Scholars' statuses must reflect the new rule right away, not only after their next grade save.
        reevaluateScholarshipType($pdo, $scholarshipTypeId);
        redirectTo('/GRANTED/admin/rules.php');
    }
}

$types = $pdo->query('SELECT * FROM scholarship_types ORDER BY name')->fetchAll();
$rules = $pdo->query(
    'SELECT r.*, st.name AS type_name FROM rules r
     JOIN scholarship_types st ON st.id = r.scholarship_type_id
     ORDER BY r.is_active DESC, st.name, r.rule_type'
)->fetchAll();

$pageTitle = 'Rules';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Rules</h1>

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
                <option value="<?php echo (int)$t['id']; ?>"><?php echo sanitize($t['name']); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="rule_type">Rule type</label>
        <select id="rule_type" name="rule_type" required>
            <option value="GPA">GPA</option>
            <option value="UNIT_LOAD">Unit load</option>
            <option value="DOCUMENT">Document</option>
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

        <label for="threshold_value" id="threshold_label">Minimum / maximum value</label>
        <input type="text" id="threshold_value" name="threshold_value" placeholder="e.g. 2.00 for GPA, 18 for units" required>

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

        function updateRuleForm() {
            var isDocument = ruleType.value === 'DOCUMENT';
            document.getElementById('operator_group').style.display = isDocument ? 'none' : '';
            document.getElementById('due_date_group').style.display = isDocument ? '' : 'none';
            document.getElementById('threshold_label').textContent = isDocument ? 'Document name (exact)' : 'Minimum / maximum value';
            threshold.type = isDocument ? 'text' : 'number';
            threshold.step = 'any';
            threshold.min = '0';
            threshold.placeholder = isDocument ? 'e.g. Certificate of Registration' : 'e.g. 2.00 for GPA, 18 for units';
        }

        ruleType.addEventListener('change', updateRuleForm);
        updateRuleForm();
    </script>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Existing rules</h2>
    <ul class="rule-list">
        <?php foreach ($rules as $r): ?>
            <li>
                <span>
                    <strong><?php echo sanitize($r['type_name']); ?></strong> —
                    <?php echo sanitize($r['rule_type']); ?> <?php echo sanitize($r['operator']); ?> <?php echo sanitize($r['threshold_value']); ?>
                    (<?php echo sanitize($r['school_year']); ?>, <?php echo sanitize($r['semester']); ?>)
                    <?php if (!empty($r['due_date'])): ?>— due <?php echo sanitize($r['due_date']); ?><?php endif; ?>
                    <?php if (!$r['is_active']): ?><em>(inactive)</em><?php endif; ?>
                </span>
                <?php if ($r['is_active']): ?>
                    <form method="POST" action="" style="display:inline;">
                        <?php echo csrfField(); ?>
                        <button type="submit" name="deactivate" value="<?php echo (int)$r['id']; ?>" onclick="return confirm('Deactivate this rule?');" style="margin:0; padding:0; background:none; color:var(--color-accent); font-size:inherit; font-weight:normal; text-decoration:underline;">Deactivate</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <?php if (empty($rules)): ?><li>No rules yet.</li><?php endif; ?>
    </ul>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
