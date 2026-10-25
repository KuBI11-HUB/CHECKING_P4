<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$ruleId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM rules WHERE id = ?');
$stmt->execute([$ruleId]);
$existing = $stmt->fetch();

if (!$existing) {
    http_response_code(404);
    $pageTitle = 'Rule not found';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <h1>Rule not found</h1>
    <div class="card">
        <p>There is no rule with that ID.</p>
        <p style="margin-top:1rem;"><a class="back-link" href="/GRANTED/admin/rules.php">&larr; Back to rules</a></p>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$editUrl = '/GRANTED/admin/edit_rule.php?id=' . $ruleId;
$message = '';

$form = [
    'scholarship_type_id' => (string)$existing['scholarship_type_id'],
    'rule_type'           => (string)$existing['rule_type'],
    'operator'            => (string)$existing['operator'],
    'threshold_value'     => (string)$existing['threshold_value'],
    'school_year'         => (string)$existing['school_year'],
    'semester'            => (string)$existing['semester'],
    'due_date'            => (string)($existing['due_date'] ?? ''),
    'is_active'           => $existing['is_active'] ? '1' : '0',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    foreach (['scholarship_type_id', 'rule_type', 'operator', 'threshold_value', 'school_year', 'semester', 'due_date'] as $key) {
        $form[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
    }
    $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

    $result = validateRuleInput($pdo, $form);

    if ($result['error'] !== null) {
        $message = $result['error'];
    } else {
        $v = $result['values'];
        $oldTypeId = (int)$existing['scholarship_type_id'];

        $stmt = $pdo->prepare(
            'UPDATE rules SET scholarship_type_id = ?, rule_type = ?, operator = ?, threshold_value = ?,
                    school_year = ?, semester = ?, due_date = ?, is_active = ?
             WHERE id = ?'
        );
        $stmt->execute([$v['scholarship_type_id'], $v['rule_type'], $v['operator'], $v['threshold_value'],
                        $v['school_year'], $v['semester'], $v['due_date'], (int)$form['is_active'], $ruleId]);

        // Statuses must follow the edit right away. If the rule moved to another type, both types are affected.
        $count = reevaluateScholarshipType($pdo, $oldTypeId);
        if ($v['scholarship_type_id'] !== $oldTypeId) {
            $count += reevaluateScholarshipType($pdo, $v['scholarship_type_id']);
        }

        setFlash('Rule updated. ' . $count . ' scholar(s) re-evaluated.');
        redirectTo('/GRANTED/admin/rules.php');
    }
}

$types = $pdo->query('SELECT id, name FROM scholarship_types ORDER BY name')->fetchAll();

$pageTitle = 'Edit Rule';
require_once __DIR__ . '/../includes/header.php';
?>

<p style="margin-bottom:1rem;"><a class="back-link" href="/GRANTED/admin/rules.php">&larr; Back to rules</a></p>
<h1>Edit rule</h1>

<div class="card">
    <p><small>Saving re-runs the rules for every scholar on the affected scholarship type(s).</small></p>
    <?php if ($message): ?><p class="form-error" style="margin-top:0.75rem;"><?php echo sanitize($message); ?></p><?php endif; ?>

    <form method="POST" action="<?php echo $editUrl; ?>">
        <?php echo csrfField(); ?>

        <label for="scholarship_type_id">Scholarship type</label>
        <select id="scholarship_type_id" name="scholarship_type_id" required>
            <?php foreach ($types as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>" <?php echo (int)$form['scholarship_type_id'] === (int)$t['id'] ? 'selected' : ''; ?>><?php echo h($t['name']); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="rule_type">Rule type</label>
        <select id="rule_type" name="rule_type" required>
            <?php foreach (['GPA' => 'GPA', 'UNIT_LOAD' => 'Unit load', 'DOCUMENT' => 'Document'] as $value => $label): ?>
                <option value="<?php echo $value; ?>" <?php echo $form['rule_type'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>

        <div id="operator_group">
        <label for="operator">Operator</label>
        <select id="operator" name="operator" required>
            <?php foreach (['>=' => '>= (at least)', '<=' => '<= (at most)', '>' => '> (more than)', '<' => '< (less than)', '==' => '== (exactly)'] as $value => $label): ?>
                <option value="<?php echo h($value); ?>" <?php echo $form['operator'] === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
            <?php endforeach; ?>
        </select>
        </div>

        <label for="threshold_value" id="threshold_label">Minimum / maximum value</label>
        <input type="text" id="threshold_value" name="threshold_value" value="<?php echo h($form['threshold_value']); ?>" required>

        <label for="school_year">School year</label>
        <input type="text" id="school_year" name="school_year" value="<?php echo h($form['school_year']); ?>" placeholder="2026-2027" required>

        <label for="semester">Semester</label>
        <select id="semester" name="semester" required>
            <?php foreach (['1st', '2nd', 'Summer'] as $sem): ?>
                <option value="<?php echo $sem; ?>" <?php echo $form['semester'] === $sem ? 'selected' : ''; ?>><?php echo $sem; ?></option>
            <?php endforeach; ?>
        </select>

        <div id="due_date_group">
        <label for="due_date">Document deadline (optional)</label>
        <input type="date" id="due_date" name="due_date" value="<?php echo h($form['due_date']); ?>">
        </div>

        <label for="is_active" style="display:flex; align-items:center; margin-top:1.25rem;">
            <input type="checkbox" id="is_active" name="is_active" value="1" style="width:auto; margin-right:0.5rem;" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
            Active (untick to deactivate; tick to reactivate)
        </label>

        <button type="submit">Save rule</button>
        <a class="btn btn--secondary" href="/GRANTED/admin/rules.php">Cancel</a>
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
        }

        ruleType.addEventListener('change', updateRuleForm);
        updateRuleForm();
    </script>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>