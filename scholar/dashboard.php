<?php
$requiredRole = 'scholar';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$stmt = $pdo->prepare('SELECT * FROM scholars WHERE user_id = ?');
$stmt->execute([(int)$_SESSION['user_id']]);
$scholar = $stmt->fetch();
$assignments = [];
$rulesByAssignment = [];
$checklist = [];
$outstanding = [];
$gwaRecord = null;

if ($scholar) {
    $scholarId = (int)$scholar['id'];
    $assignments = getScholarAssignments($pdo, $scholarId);
    foreach ($assignments as $assignment) {
        $assignmentId = (int)$assignment['id'];
        $rulesByAssignment[$assignmentId] = getRuleBreakdown($pdo, $scholarId, $assignmentId);
    }
    $checklist = getScholarDocumentChecklist($pdo, $scholarId);
    foreach ($checklist as $item) {
        if ($item['status'] !== 'Verified') {
            $outstanding[] = $item;
        }
    }
    $gwaRecord = getUsableGwaRecord($pdo, $scholarId, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER);
}

$pageTitle = 'My Status';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-page dashboard-page--scholar">
    <div class="page-head">
        <div>
            <h1>My status</h1>
            <p class="page-head__sub">Your scholarship and academic progress</p>
        </div>
    </div>

    <?php if (!$scholar): ?>
        <div class="card"><p>No scholar record is linked to this account yet.</p></div>
    <?php else: ?>

    <section class="card scholar-summary" aria-labelledby="scholar-summary-title">
        <div class="scholar-summary__heading">
            <div>
                <p class="scholar-summary__label">Current term</p>
                <h2 id="scholar-summary-title"><?php echo h(CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER); ?></h2>
            </div>
        </div>
        <dl class="scholar-summary__metrics">
            <div>
                <dt>Official GWA</dt>
                <dd><?php echo $gwaRecord !== null ? h((string)$gwaRecord['gwa']) : 'Not yet recorded'; ?></dd>
            </div>
            <div>
                <dt>Total units</dt>
                <dd><?php echo $gwaRecord !== null ? (int)$gwaRecord['total_units'] : 'Not yet recorded'; ?></dd>
            </div>
        </dl>
    </section>

    <?php if (!$assignments): ?>
        <section class="card"><h2>Scholarships</h2><p>No active scholarship assignments are linked to your account.</p></section>
    <?php else: ?>
        <?php foreach ($assignments as $assignment): ?>
        <?php $assignmentId = (int)$assignment['id']; ?>
        <section class="card dashboard-section">
            <div class="scholar-summary__heading">
                <h2><?php echo h($assignment['type_name']); ?></h2>
                <span class="status-badge <?php echo statusBadgeClass($assignment['status']); ?>"><?php echo h($assignment['status']); ?></span>
            </div>
            <h3>Rule-by-rule breakdown</h3>
            <ul class="rule-list">
                <?php foreach ($rulesByAssignment[$assignmentId] as $rule): ?>
                    <li>
                        <span><?php echo h(describeRule($rule)); ?></span>
                        <span class="status-badge <?php echo statusBadgeClass($rule['result']); ?>"><?php echo h($rule['result']); ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($rulesByAssignment[$assignmentId])): ?>
                    <li class="empty-state">No rules have been set for this scholarship in the current term.</li>
                <?php endif; ?>
            </ul>
        </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <section class="card dashboard-section">
        <h2>Document checklist</h2>
        <?php if (empty($checklist)): ?>
            <p>No documents are required for your active scholarship assignments.</p>
        <?php elseif (empty($outstanding)): ?>
            <p>Nothing outstanding — every required document has been verified.</p>
        <?php else: ?>
            <ul class="rule-list">
                <?php foreach ($outstanding as $item): ?>
                    <li>
                        <span><?php echo h($item['document_type']); ?><?php echo dueDateHtml($item['due_date'], $item['status']); ?></span>
                        <span class="status-badge <?php
                            echo $item['status'] === 'Rejected' ? 'status-badge--fail' : 'status-badge--pending';
                        ?>"><?php echo h($item['status']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="dashboard-section__action"><a class="btn" href="/GRANTED/scholar/upload_document.php"><span class="icon-label"><?php echo uiIcon('upload'); ?>Upload documents</span></a></p>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
