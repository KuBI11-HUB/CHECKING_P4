<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$scholarId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$detailUrl = '/GRANTED/admin/scholar_detail.php?id=' . $scholarId;
$stmt = $pdo->prepare(
    'SELECT s.*, u.email, p.name AS program_name
     FROM scholars s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN programs p ON p.id = s.program_id
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
    <div class="card"><p>There is no scholar with that ID.</p><p><a class="back-link" href="/GRANTED/admin/dashboard.php">&larr; Back to results</a></p></div>
    <?php require_once __DIR__ . '/../includes/footer.php'; exit; ?>
    <?php
}

$assignments = getScholarAssignments($pdo, $scholarId, false);
$activeIds = [];
foreach ($assignments as $assignment) {
    if ((int)$assignment['is_active'] && $assignment['ended_at'] === null) {
        $activeIds[(int)$assignment['id']] = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    if (!isset($activeIds[$assignmentId])) {
        setFlash('Not saved. Choose an active scholarship assignment.');
        redirectTo($detailUrl);
    }
    if (isset($_POST['rerun'])) {
        $status = evaluateAssignment($pdo, $assignmentId);
        setFlash('Check re-run. Calculated status: ' . ($status ?? 'PENDING') . '.');
        redirectTo($detailUrl);
    }

    $decision = is_string($_POST['decision'] ?? null) ? $_POST['decision'] : '';
    $remarks = trim(is_string($_POST['remarks'] ?? null) ? $_POST['remarks'] : '');
    $seenStatus = is_string($_POST['seen_status'] ?? null) ? $_POST['seen_status'] : '';
    $assignment = null;
    foreach ($assignments as $candidate) {
        if ((int)$candidate['id'] === $assignmentId) {
            $assignment = $candidate;
            break;
        }
    }
    $currentStatus = (string)$assignment['status'];
    $naturalDecision = ['PASS' => 'RENEWED', 'FAIL' => 'NOT_RENEWED'];
    if (!in_array($decision, ['RENEWED', 'NOT_RENEWED', 'ON_HOLD'], true)) {
        setFlash('Not saved. Choose a decision.');
    } elseif ($seenStatus !== $currentStatus) {
        setFlash('Not saved. The calculated status changed. Review it and save the decision again.');
    } elseif ($decision !== 'ON_HOLD' && ($naturalDecision[$currentStatus] ?? null) !== $decision && $remarks === '') {
        setFlash('Not saved. Add remarks when the decision differs from the calculated status.');
    } elseif (strlen($remarks) > 2000) {
        setFlash('Not saved. Keep remarks under 2,000 characters.');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO renewal_decisions
                 (assignment_id, school_year, semester, decision, remarks, calculated_status_at_decision, decided_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE decision = VALUES(decision), remarks = VALUES(remarks),
                 calculated_status_at_decision = VALUES(calculated_status_at_decision),
                 decided_by = VALUES(decided_by), decided_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $assignmentId, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER, $decision,
            $remarks !== '' ? $remarks : null, $currentStatus, (int)$_SESSION['user_id'],
        ]);
        setFlash('Decision saved for ' . $assignment['type_name'] . '.');
    }
    redirectTo($detailUrl);
}

$flash = takeFlash();
$rulesByAssignment = [];
$decisionsByAssignment = [];
foreach ($assignments as $assignment) {
    $assignmentId = (int)$assignment['id'];
    $rulesByAssignment[$assignmentId] = getRuleBreakdown($pdo, $scholarId, $assignmentId);
    $stmt = $pdo->prepare(
        'SELECT rd.*, u.email AS decided_by_email
         FROM renewal_decisions rd JOIN users u ON u.id = rd.decided_by
         WHERE rd.assignment_id = ? AND rd.school_year = ? AND rd.semester = ?'
    );
    $stmt->execute([$assignmentId, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);
    $decisionsByAssignment[$assignmentId] = $stmt->fetch() ?: null;
}
$gwaRecord = getUsableGwaRecord($pdo, $scholarId, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER);

$stmt = $pdo->prepare(
    'SELECT d.*, req.name AS requirement_name
     FROM documents d LEFT JOIN requirements req ON req.id = d.requirement_id
     WHERE d.scholar_id = ? AND d.school_year = ? AND d.semester = ?
     ORDER BY d.uploaded_at DESC, d.id DESC'
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

$backQuery = (string)($_SESSION['results_query'] ?? '');
if (!preg_match('/\A[A-Za-z0-9%=&_.+~-]*\z/', $backQuery)) {
    $backQuery = '';
}
$backUrl = '/GRANTED/admin/dashboard.php' . ($backQuery !== '' ? '?' . $backQuery : '');
$pageTitle = formatScholarName($scholar);
require_once __DIR__ . '/../includes/header.php';
?>

<p style="margin-bottom:1rem;"><a class="back-link" href="<?php echo h($backUrl); ?>">&larr; Back to results</a></p>
<h1><?php echo h(formatScholarName($scholar)); ?></h1>
<?php echo flashBox($flash); ?>

<div class="card">
    <h2>Profile</h2>
    <p>Email: <?php echo h($scholar['email']); ?></p>
    <p>Program: <?php echo h($scholar['program_name'] ?? '—'); ?> &nbsp;|&nbsp; Year level: <?php echo $scholar['year_level'] ? (int)$scholar['year_level'] : '—'; ?></p>
    <p><a class="btn" href="/GRANTED/admin/edit_scholar.php?id=<?php echo $scholarId; ?>">Edit scholar profile</a></p>
</div>

<div class="card">
    <h2>Account access</h2>
    <p class="form-info">Walk-in requests only. Verify the person's school ID against the name and login email before setting a temporary password.</p>
    <form method="POST" action="/GRANTED/admin/set_scholar_password.php" style="max-width:26rem;" autocomplete="off" data-confirmation>
        <?php echo csrfField(); ?>
        <input type="hidden" name="scholar_id" value="<?php echo $scholarId; ?>">
        <label for="login_email">Login email</label>
        <input type="email" id="login_email" value="<?php echo h($scholar['email']); ?>" readonly>
        <label for="account_new_password">Temporary password (at least 8 characters)</label>
        <input type="password" id="account_new_password" name="new_password" autocomplete="new-password" minlength="8" required>
        <label for="account_confirm_password">Confirm temporary password</label>
        <input type="password" id="account_confirm_password" name="confirm_password" autocomplete="new-password" minlength="8" required>
        <button type="submit" data-confirmation-trigger>Set temporary password</button>
        <div class="form-info action-confirmation" data-confirmation-panel hidden role="group" aria-label="Confirm temporary password">
            <p>Set a temporary password? Their current password will stop working.</p>
            <div class="temporary-password-confirmation__actions">
                <button class="btn btn--secondary" type="button" data-confirmation-cancel>Cancel</button>
                <button type="submit">Confirm</button>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <h2>Official GWA (<?php echo h(CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER); ?>)</h2>
    <p>GWA: <?php echo $gwaRecord ? h((string)$gwaRecord['gwa']) : 'Not currently available'; ?>
       &nbsp;|&nbsp; Total units: <?php echo $gwaRecord ? (int)$gwaRecord['total_units'] : '—'; ?>
       &nbsp;|&nbsp; Source document: <?php echo $gwaRecord ? '#' . (int)$gwaRecord['source_document_id'] : '—'; ?></p>
    <p><a class="btn btn--secondary" href="/GRANTED/admin/enter_gwa.php">Enter or update GWA</a></p>
</div>

<h2>Scholarship assignments</h2>
<?php if (!$assignments): ?><div class="card"><p>No scholarship assignments are linked to this scholar.</p></div><?php endif; ?>
<?php foreach ($assignments as $assignment): ?>
<?php
$assignmentId = (int)$assignment['id'];
$rules = $rulesByAssignment[$assignmentId];
$savedDecision = $decisionsByAssignment[$assignmentId];
$active = isset($activeIds[$assignmentId]);
?>
<section class="card">
    <h3><?php echo h($assignment['type_name']); ?><?php if (!$active): ?> <small>(inactive)</small><?php endif; ?></h3>
    <p>Calculated status:
        <span class="status-badge <?php echo statusBadgeClass($assignment['status']); ?>"><?php echo h($assignment['status']); ?></span>
    </p>
    <?php if ($active): ?>
    <form method="POST" action="<?php echo h($detailUrl); ?>">
        <?php echo csrfField(); ?>
        <input type="hidden" name="assignment_id" value="<?php echo $assignmentId; ?>">
        <button type="submit" name="rerun" value="1" class="btn btn--secondary">Re-run check</button>
    </form>
    <?php endif; ?>
    <h4>Current-term rules</h4>
    <ul class="rule-list">
        <?php foreach ($rules as $rule): ?>
            <li><span><?php echo h(describeRule($rule)); ?><br><small><?php echo h(explainRuleResult($rule)); ?></small></span>
                <span class="status-badge <?php echo statusBadgeClass($rule['result']); ?>"><?php echo h($rule['result']); ?></span></li>
        <?php endforeach; ?>
        <?php if (!$rules): ?><li>No active rules for this term.</li><?php endif; ?>
    </ul>
    <h4>Office renewal decision</h4>
    <?php if ($savedDecision): ?>
        <p><?php echo h(str_replace('_', ' ', $savedDecision['decision'])); ?>, recorded by <?php echo h($savedDecision['decided_by_email']); ?> on <?php echo h($savedDecision['decided_at']); ?>.</p>
        <?php if ($savedDecision['remarks']): ?><p>Remarks: <?php echo h($savedDecision['remarks']); ?></p><?php endif; ?>
    <?php else: ?><p>No office decision recorded for this term.</p><?php endif; ?>
    <?php if ($active): ?>
    <form method="POST" action="<?php echo h($detailUrl); ?>">
        <?php echo csrfField(); ?>
        <input type="hidden" name="assignment_id" value="<?php echo $assignmentId; ?>">
        <input type="hidden" name="seen_status" value="<?php echo h($assignment['status']); ?>">
        <label for="decision-<?php echo $assignmentId; ?>">Decision</label>
        <select id="decision-<?php echo $assignmentId; ?>" name="decision" required>
            <option value="">Choose a decision</option>
            <?php foreach (['RENEWED' => 'Renewed', 'NOT_RENEWED' => 'Not renewed', 'ON_HOLD' => 'On hold'] as $value => $label): ?>
                <option value="<?php echo $value; ?>" <?php echo ($savedDecision['decision'] ?? '') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        <label for="remarks-<?php echo $assignmentId; ?>">Remarks (required if decision differs)</label>
        <textarea id="remarks-<?php echo $assignmentId; ?>" name="remarks" rows="3" maxlength="2000"><?php echo h($savedDecision['remarks'] ?? ''); ?></textarea>
        <button type="submit">Save decision</button>
    </form>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<div class="card">
    <h2>Documents (<?php echo h(CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER); ?>)</h2>
    <table class="data-table"><thead><tr><th>Document</th><th>Status</th><th>Uploaded</th><th>Remarks</th><th>File</th></tr></thead><tbody>
    <?php foreach ($documents as $document): ?>
        <tr><td><?php echo h($document['requirement_name'] ?? $document['document_type']); ?></td>
            <td><?php echo h($document['status']); ?></td><td><?php echo h($document['uploaded_at']); ?></td>
            <td><?php echo h($document['remarks'] ?: '—'); ?></td>
            <td><a href="/GRANTED/admin/view_document.php?id=<?php echo (int)$document['id']; ?>" target="_blank">View file</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$documents): ?><tr><td colspan="5">No documents uploaded for this term.</td></tr><?php endif; ?>
    </tbody></table>
</div>

<div class="card">
    <h2>Notifications (latest 20)</h2>
    <table class="data-table"><thead><tr><th>Date</th><th>Subject</th><th>Type</th><th>Send status</th><th>Error</th></tr></thead><tbody>
    <?php foreach ($notifications as $notification): ?>
        <tr><td><?php echo h($notification['created_at']); ?></td><td><?php echo h($notification['subject']); ?></td>
            <td><?php echo h($typeLabels[$notification['type']] ?? $notification['type']); ?></td>
            <td><?php echo h($notification['send_status']); ?></td><td><?php echo h($notification['error_message'] ?: '—'); ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$notifications): ?><tr><td colspan="5">No notifications yet.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
