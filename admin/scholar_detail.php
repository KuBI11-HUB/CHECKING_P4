<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$scholarId = (int)($_GET['id'] ?? 0);
$detailUrl = '/GRANTED/admin/scholar_detail.php?id=' . $scholarId;

$stmt = $pdo->prepare(
    'SELECT s.*, u.email, st.name AS type_name FROM scholars s
     JOIN users u ON u.id = s.user_id
     JOIN scholarship_types st ON st.id = s.scholarship_type_id
     WHERE s.id = ?'
);
$stmt->execute([$scholarId]);
$scholar = $stmt->fetch();

if (!$scholar) {
    http_response_code(404);
    $pageTitle = 'Scholar not found';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <h1>Scholar not found</h1>
    <div class="card">
        <p>There is no scholar with that ID.</p>
        <p style="margin-top:1rem;"><a class="btn" href="/GRANTED/admin/dashboard.php">Back to results</a></p>
        <p><a class="btn" href="/GRANTED/admin/edit_scholar.php?id=<?php echo $scholarId; ?>" style="margin-top:0.75rem;">Edit profile</a></p>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (isset($_POST['rerun'])) {
        $newStatus = evaluateScholar($pdo, $scholarId);
        setFlash('Check re-run. Calculated status: ' . ($newStatus ?? 'PENDING') . '.');
        redirectTo($detailUrl);
    }

    $decision = $_POST['decision'] ?? '';
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $seenStatus = $_POST['seen_status'] ?? '';
    $currentStatus = $scholar['current_status'];
    $naturalDecision = ['PASS' => 'RENEWED', 'FAIL' => 'NOT_RENEWED'];   // what the calculation points to

    if (!in_array($decision, ['RENEWED', 'NOT_RENEWED', 'ON_HOLD'], true)) {
        setFlash('Not saved: please choose a decision.');
    } elseif ($seenStatus !== $currentStatus) {
        setFlash('Not saved: the calculated status changed to ' . $currentStatus . ' after you opened this page. Please review it and decide again.');
    } elseif ($decision !== 'ON_HOLD' && ($naturalDecision[$currentStatus] ?? null) !== $decision && $remarks === '') {
        setFlash('Not saved: remarks are required when the decision differs from the calculated status (' . $currentStatus . ').');
    } elseif (strlen($remarks) > 2000) {
        setFlash('Not saved: remarks are too long (2000 characters at most).');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO renewal_decisions
                 (scholar_id, school_year, semester, decision, remarks, calculated_status_at_decision, decided_by)
             VALUES (:scholar_id, :school_year, :semester, :decision, :remarks, :status, :decided_by)
             ON DUPLICATE KEY UPDATE decision = VALUES(decision), remarks = VALUES(remarks),
                 calculated_status_at_decision = VALUES(calculated_status_at_decision),
                 decided_by = VALUES(decided_by), decided_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':scholar_id' => $scholarId,
            ':school_year' => CURRENT_SCHOOL_YEAR,
            ':semester' => CURRENT_SEMESTER,
            ':decision' => $decision,
            ':remarks' => $remarks !== '' ? $remarks : null,
            ':status' => $currentStatus,
            ':decided_by' => (int)$_SESSION['user_id'],
        ]);
        setFlash('Decision saved: ' . str_replace('_', ' ', $decision) . ' (calculated status: ' . $currentStatus . ').');
    }
    redirectTo($detailUrl);
}

$flash = takeFlash();

$rules = getRuleBreakdown($pdo, $scholarId, (int)$scholar['scholarship_type_id']);
$lastEvaluated = null;
$byResult = ['PASS' => [], 'FAIL' => [], 'PENDING' => []];
foreach ($rules as $rule) {
    $byResult[$rule['result']][] = $rule;
    if ($rule['evaluated_at'] !== null && ($lastEvaluated === null || $rule['evaluated_at'] > $lastEvaluated)) {
        $lastEvaluated = $rule['evaluated_at'];
    }
}

$stmt = $pdo->prepare(
    "SELECT id, subject, grade, units, school_year, semester FROM grades
     WHERE scholar_id = ?
     ORDER BY school_year DESC, FIELD(semester, '1st', '2nd', 'Summer') DESC, subject"
);
$stmt->execute([$scholarId]);
$grades = [];        // current term: these feed GPA and units
$otherGrades = [];   // other terms: not counted, but must stay correctable (e.g. a wrong school year)
foreach ($stmt->fetchAll() as $g) {
    if ($g['school_year'] === CURRENT_SCHOOL_YEAR && $g['semester'] === CURRENT_SEMESTER) {
        $grades[] = $g;
    } else {
        $otherGrades[] = $g;
    }
}

$gradeActions = static function (array $g): string {
    $id = (int)$g['id'];
    return '<a href="/GRANTED/admin/edit_grade.php?id=' . $id . '">Edit</a>'
        . '<form method="POST" action="/GRANTED/admin/delete_grade.php" style="display:inline; margin-left:0.75rem;">'
        . csrfField()
        . '<input type="hidden" name="grade_id" value="' . $id . '">'
        . '<button type="submit" onclick="return confirm(\'Delete this grade? The status will be recalculated.\');" '
        . 'style="margin:0; padding:0; background:none; color:var(--color-fail); font-size:inherit; font-weight:normal; text-decoration:underline;">Delete</button>'
        . '</form>';
};

$stmt = $pdo->prepare(
    'SELECT id, document_type, status, remarks, uploaded_at FROM documents
     WHERE scholar_id = ? AND school_year = ? AND semester = ? ORDER BY uploaded_at DESC, id DESC'
);
$stmt->execute([$scholarId, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);
$documents = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT created_at, subject, type, send_status, error_message FROM notifications
     WHERE scholar_id = ? ORDER BY created_at DESC, id DESC LIMIT 20'
);
$stmt->execute([$scholarId]);
$notifications = $stmt->fetchAll();
$typeLabels = ['STATUS_CHANGE' => 'Status change', 'DEADLINE_APPROACHING' => 'Deadline reminder'];

$stmt = $pdo->prepare(
    'SELECT rd.*, u.email AS decided_by_email FROM renewal_decisions rd
     JOIN users u ON u.id = rd.decided_by
     WHERE rd.scholar_id = ? AND rd.school_year = ? AND rd.semester = ?'
);
$stmt->execute([$scholarId, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);
$savedDecision = $stmt->fetch();

// Back to the exact results view (filters + page) the admin came from; the dashboard saves it in the session.
$backQuery = (string)($_SESSION['results_query'] ?? '');
if (!preg_match('/\A[A-Za-z0-9%=&_.+~-]*\z/', $backQuery)) {
    $backQuery = '';
}
$backUrl = '/GRANTED/admin/dashboard.php' . ($backQuery !== '' ? '?' . $backQuery : '');

$pageTitle = plainText($scholar['full_name']);
require_once __DIR__ . '/../includes/header.php';
?>

<p style="margin-bottom:1rem;"><a href="<?php echo sanitize($backUrl); ?>">&larr; Back to results</a></p>
<h1><?php echo h($scholar['full_name']); ?></h1>

<?php echo flashBox($flash); ?>

<div class="card">
    <h2>Profile</h2>
    <p>Email: <?php echo h($scholar['email']); ?></p>
    <p>Program: <?php echo $scholar['program'] ? h($scholar['program']) : '—'; ?>
       &nbsp;|&nbsp; Year level: <?php echo $scholar['year_level'] ? (int)$scholar['year_level'] : '—'; ?></p>
    <p>Scholarship type: <?php echo h($scholar['type_name']); ?></p>
    <p style="margin-top:1rem;"><a class="btn" href="/GRANTED/admin/edit_scholar.php?id=<?php echo $scholarId; ?>" style="margin-top:0;">Edit scholar profile</a></p>
</div>

<div class="card">
    <h2>Account access</h2>
    <p class="form-info">Walk-in requests only. Before setting a temporary password, check the person's school ID card against the full name and login email shown on this page. If anything does not match, do not continue.</p>
    <form method="POST" action="/GRANTED/admin/set_scholar_password.php" style="max-width:26rem;" autocomplete="off">
        <?php echo csrfField(); ?>
        <input type="hidden" name="scholar_id" value="<?php echo $scholarId; ?>">

        <label for="login_email">Login email (read-only)</label>
        <input type="email" id="login_email" value="<?php echo h($scholar['email']); ?>" readonly autocomplete="off" style="background:var(--color-bg);">

        <label for="account_new_password">Temporary password (at least 8 characters)</label>
        <input type="password" id="account_new_password" name="new_password" autocomplete="new-password" minlength="8" required>

        <label for="account_confirm_password">Confirm temporary password</label>
        <input type="password" id="account_confirm_password" name="confirm_password" autocomplete="new-password" minlength="8" required>

        <button type="submit" onclick="return confirm('Set a new password for this scholar? Their old password will stop working.');">Set temporary password</button>
    </form>
    <p style="margin-top:1rem;"><small>This only changes the sign-in password. It does not change the scholar's status or any renewal record.</small></p>
</div>

<div class="card">
    <h2>Status (<?php echo h(CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER); ?>)</h2>
    <p>Calculated status:
        <span class="status-badge <?php echo statusBadgeClass($scholar['current_status']); ?>"><?php echo h($scholar['current_status']); ?></span>
    </p>
    <p>GPA: <?php echo $scholar['current_gpa'] !== null ? h((string)$scholar['current_gpa']) : '—'; ?>
       &nbsp;|&nbsp; Units: <?php echo $scholar['current_units'] !== null ? h((string)$scholar['current_units']) : '—'; ?></p>
    <p>Last evaluated: <?php echo $lastEvaluated !== null ? h($lastEvaluated) : '—'; ?></p>
    <form method="POST" action="<?php echo $detailUrl; ?>">
        <?php echo csrfField(); ?>
        <button type="submit" name="rerun" value="1" class="btn btn--secondary" style="margin-top:1rem;">Re-run check</button>
    </form>
</div>

<div class="card">
    <h2>Why this status</h2>
    <?php if (empty($rules)): ?>
        <p>There are no active rules for this scholarship type in the current term, so the status stays PENDING.</p>
    <?php elseif ($scholar['current_status'] === 'PASS'): ?>
        <p>All <?php echo count($rules); ?> active rules passed.</p>
    <?php elseif ($scholar['current_status'] === 'FAIL'): ?>
        <p>No rule is still waiting, and at least one rule failed:</p>
        <ul style="margin:0.5rem 0 0 1.25rem;">
            <?php foreach ($byResult['FAIL'] as $r): ?>
                <li><?php echo h(describeRule($r)); ?> — <?php echo h(explainRuleResult($r)); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>At least one rule is still waiting, so the overall status stays PENDING:</p>
        <ul style="margin:0.5rem 0 0 1.25rem;">
            <?php foreach ($byResult['PENDING'] as $r): ?>
                <li><?php echo h(describeRule($r)); ?> — <?php echo h(explainRuleResult($r)); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if (!empty($byResult['FAIL'])): ?>
            <p style="margin-top:0.75rem;">Already failed (this will decide the status once nothing is waiting):</p>
            <ul style="margin:0.5rem 0 0 1.25rem;">
                <?php foreach ($byResult['FAIL'] as $r): ?>
                    <li><?php echo h(describeRule($r)); ?> — <?php echo h(explainRuleResult($r)); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Rule-by-rule</h2>
    <ul class="rule-list">
        <?php foreach ($rules as $r): ?>
            <li>
                <span><?php echo h(describeRule($r)); ?><br><small><?php echo h(explainRuleResult($r)); ?></small></span>
                <span class="status-badge <?php echo statusBadgeClass($r['result']); ?>"><?php echo h($r['result']); ?></span>
            </li>
        <?php endforeach; ?>
        <?php if (empty($rules)): ?>
            <li>No active rules for the current term.</li>
        <?php endif; ?>
    </ul>
</div>

<div class="card">
    <h2>Grades (<?php echo h(CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER); ?>)</h2>
    <table class="data-table">
        <thead>
            <tr><th>Subject</th><th class="num">Grade</th><th class="num">Units</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($grades as $g): ?>
            <tr>
                <td><?php echo h($g['subject']); ?></td>
                <td class="num"><?php echo h((string)$g['grade']); ?></td>
                <td class="num"><?php echo (int)$g['units']; ?></td>
                <td><?php echo $gradeActions($g); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($grades)): ?>
            <tr><td colspan="4">No grades recorded for this term.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (!empty($otherGrades)): ?>
<div class="card">
    <h2>Grades from other terms</h2>
    <p style="margin-bottom:1rem;"><small>These do not count toward the current term's GPA or units.</small></p>
    <table class="data-table">
        <thead>
            <tr><th>Subject</th><th class="num">Grade</th><th class="num">Units</th><th>Term</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($otherGrades as $g): ?>
            <tr>
                <td><?php echo h($g['subject']); ?></td>
                <td class="num"><?php echo h((string)$g['grade']); ?></td>
                <td class="num"><?php echo (int)$g['units']; ?></td>
                <td><?php echo h($g['school_year'] . ' ' . $g['semester']); ?></td>
                <td><?php echo $gradeActions($g); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card">
    <h2>Documents (<?php echo h(CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER); ?>)</h2>
    <table class="data-table">
        <thead>
            <tr><th>Document</th><th>Status</th><th>Uploaded</th><th>Remarks</th><th>File</th></tr>
        </thead>
        <tbody>
        <?php foreach ($documents as $d): ?>
            <tr>
                <td><?php echo h($d['document_type']); ?></td>
                <td><span class="status-badge <?php
                    echo $d['status'] === 'Verified' ? 'status-badge--pass'
                        : ($d['status'] === 'Rejected' ? 'status-badge--fail' : 'status-badge--pending');
                ?>"><?php echo h($d['status']); ?></span></td>
                <td><?php echo h($d['uploaded_at']); ?></td>
                <td><?php echo $d['remarks'] ? h($d['remarks']) : '—'; ?></td>
                <td><a href="/GRANTED/admin/view_document.php?id=<?php echo (int)$d['id']; ?>" target="_blank">View file</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($documents)): ?>
            <tr><td colspan="5">No documents uploaded for this term.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Notifications (latest 20)</h2>
    <table class="data-table">
        <thead>
            <tr><th>Date</th><th>Subject</th><th>Type</th><th>Send status</th><th>Error</th></tr>
        </thead>
        <tbody>
        <?php foreach ($notifications as $n): ?>
            <tr>
                <td><?php echo h($n['created_at']); ?></td>
                <td><?php echo h($n['subject']); ?></td>
                <td><?php echo h($typeLabels[$n['type']] ?? $n['type']); ?></td>
                <td><?php echo h($n['send_status']); ?></td>
                <td><?php echo $n['error_message'] ? h($n['error_message']) : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($notifications)): ?>
            <tr><td colspan="5">No notifications yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Renewal decision (<?php echo h(CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER); ?>)</h2>
    <p>Calculated status:
        <span class="status-badge <?php echo statusBadgeClass($scholar['current_status']); ?>"><?php echo h($scholar['current_status']); ?></span>
        &nbsp;|&nbsp; Office decision:
        <?php if ($savedDecision): ?><span class="decision-chip"><?php echo h(str_replace('_', ' ', $savedDecision['decision'])); ?></span><?php else: ?><em>Not recorded yet</em><?php endif; ?>
    </p>
    <?php if ($savedDecision): ?>
        <p>Recorded by <?php echo h($savedDecision['decided_by_email']); ?> on <?php echo h($savedDecision['decided_at']); ?>
           (calculated status at that time: <?php echo h($savedDecision['calculated_status_at_decision'] ?? '—'); ?>).</p>
    <?php endif; ?>
    <p><small>This is the office's own record. It never changes the calculated status and scholars cannot see it.</small></p>

    <form method="POST" action="<?php echo $detailUrl; ?>">
        <?php echo csrfField(); ?>
        <input type="hidden" name="seen_status" value="<?php echo h($scholar['current_status']); ?>">

        <label for="decision">Decision</label>
        <select id="decision" name="decision" required>
            <option value="">Choose a decision</option>
            <?php foreach (['RENEWED' => 'Renewed', 'NOT_RENEWED' => 'Not renewed', 'ON_HOLD' => 'On hold'] as $value => $label): ?>
                <option value="<?php echo $value; ?>" <?php echo ($savedDecision['decision'] ?? '') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>

        <label for="remarks">Remarks (required when the decision differs from the calculated status)</label>
        <textarea id="remarks" name="remarks" rows="4" maxlength="2000"><?php echo h($savedDecision['remarks'] ?? ''); ?></textarea>

        <button type="submit" name="save_decision" value="1">Save decision</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
