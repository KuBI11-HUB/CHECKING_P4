<?php
$requiredRole = 'scholar';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$stmt = $pdo->prepare('SELECT * FROM scholars WHERE user_id = ?');
$stmt->execute([(int)$_SESSION['user_id']]);
$scholar = $stmt->fetch();
$assignments = [];
$checklist = [];
$outstanding = [];
$gwaRecord = null;

if ($scholar) {
    $scholarId = (int)$scholar['id'];
    $assignments = getScholarAssignments($pdo, $scholarId);
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
            <p class="page-head__sub">Your scholarships for <?php echo h(currentTermLabel()); ?></p>
        </div>
    </div>

    <?php if (!$scholar): ?>
        <div class="card"><p>No scholar record is linked to this account yet. Contact the Scholarship Office.</p></div>
    <?php else: ?>

    <section class="card" aria-labelledby="scholarship-list-title">
        <h2 id="scholarship-list-title">Scholarships</h2>
        <?php if (!$assignments): ?>
            <p>Contact the Scholarship Office. No active scholarship is linked to your account.</p>
        <?php else: ?>
            <ul class="rule-list">
                <?php foreach ($assignments as $assignment): ?>
                    <li>
                        <span>
                            <strong><?php echo h($assignment['type_name']); ?></strong>
                            <span class="status-badge <?php echo statusBadgeClass($assignment['status']); ?>"><?php echo h($assignment['status']); ?></span>
                            <div class="status-next"><?php echo h(statusNextStep($assignment['status'], 'scholar')); ?></div>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card scholar-summary" aria-labelledby="gwa-title">
        <h2 id="gwa-title">Official GWA this term</h2>
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

    <section class="card dashboard-section">
        <h2>Document checklist</h2>
        <?php if (empty($checklist)): ?>
            <p>No documents are required for your scholarships this term.</p>
        <?php else: ?>
            <ul class="rule-list">
                <?php foreach ($checklist as $item): ?>
                    <li>
                        <span><?php echo h($item['document_type']); ?>
                            <?php echo dueDateHtml($item['due_date'], $item['status']); ?>
                        </span>
                        <span class="status-badge <?php
                            echo $item['status'] === 'Verified' ? 'status-badge--pass'
                                : ($item['status'] === 'Rejected' ? 'status-badge--fail' : 'status-badge--pending');
                        ?>"><?php echo h($item['status']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($outstanding): ?>
                <p class="dashboard-section__action">
                    <a class="btn" href="/GRANTED/scholar/upload_document.php">
                        <span class="icon-label"><?php echo uiIcon('upload'); ?>Upload missing items</span>
                    </a>
                </p>
            <?php else: ?>
                <p>Nothing due — every required document on your checklist is verified.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>