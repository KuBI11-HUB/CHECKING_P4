<?php
$requiredRole = 'scholar';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();

$stmt = $pdo->prepare(
    'SELECT s.*, st.name AS type_name FROM scholars s
     JOIN scholarship_types st ON st.id = s.scholarship_type_id
     WHERE s.user_id = ?'
);
$stmt->execute([$_SESSION['user_id']]);
$scholar = $stmt->fetch();

$evaluations = [];
$checklist = [];
$outstanding = [];
$grades = [];

if ($scholar) {
    $stmt = $pdo->prepare(
        'SELECT subject, grade, units, school_year, semester FROM grades
         WHERE scholar_id = ?
         ORDER BY school_year DESC, FIELD(semester, "Summer", "2nd", "1st") DESC, subject'
    );
    $stmt->execute([$scholar['id']]);
    $grades = $stmt->fetchAll();

    // Same function the admin detail page uses: active rules of the current term only.
    $evaluations = getRuleBreakdown($pdo, (int)$scholar['id'], (int)$scholar['scholarship_type_id']);

    $checklist = getDocumentChecklist($pdo, $scholar['id'], $scholar['scholarship_type_id']);
    foreach ($checklist as $item) {
        if ($item['status'] !== 'Verified') {
            $outstanding[] = $item;
        }
    }
}

$pageTitle = 'My Status';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>My status</h1>
<p>Logged in as <?php echo sanitize($_SESSION['email']); ?>.</p>

<?php if (!$scholar): ?>
    <div class="card"><p>No scholar record is linked to this account yet.</p></div>
<?php else: ?>

<div class="card">
    <h2><?php echo sanitize($scholar['type_name']); ?></h2>
    <p>Overall status:
        <span class="status-badge <?php echo statusBadgeClass($scholar['current_status']); ?>">
            <?php echo sanitize($scholar['current_status']); ?>
        </span>
    </p>
    <p>Current GPA (<?php echo sanitize(CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER); ?>): <?php echo $scholar['current_gpa'] !== null ? sanitize((string)$scholar['current_gpa']) : 'Not yet recorded'; ?>
       &nbsp;|&nbsp; Current-term units: <?php echo $scholar['current_units'] !== null ? sanitize((string)$scholar['current_units']) : 'Not yet recorded'; ?></p>
</div>

<div class="card">
    <h2>Grade history (all terms)</h2>
    <table class="data-table">
        <thead>
            <tr><th>Subject</th><th>Grade</th><th>Units</th><th>School year</th><th>Semester</th></tr>
        </thead>
        <tbody>
        <?php foreach ($grades as $grade): ?>
            <tr>
                <td><?php echo sanitize($grade['subject']); ?></td>
                <td><?php echo sanitize((string)$grade['grade']); ?></td>
                <td><?php echo (int)$grade['units']; ?></td>
                <td><?php echo sanitize($grade['school_year']); ?></td>
                <td><?php echo sanitize($grade['semester']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($grades)): ?>
            <tr><td colspan="5">No grades have been recorded yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Rule-by-rule breakdown</h2>
    <ul class="rule-list">
        <?php foreach ($evaluations as $e): ?>
            <li>
                <span><?php echo h(describeRule($e)); ?></span>
                <span class="status-badge <?php echo statusBadgeClass($e['result']); ?>"><?php echo h($e['result']); ?></span>
            </li>
        <?php endforeach; ?>
        <?php if (empty($evaluations)): ?>
            <li>No rules have been set for your scholarship type yet.</li>
        <?php endif; ?>
    </ul>
</div>

<div class="card">
    <h2>Pending checklist</h2>
    <?php if (empty($checklist)): ?>
        <p>No documents are required for your scholarship type.</p>
    <?php elseif (empty($outstanding)): ?>
        <p>Nothing outstanding — every required document has been verified.</p>
    <?php else: ?>
        <ul class="rule-list">
            <?php foreach ($outstanding as $item): ?>
                <li>
                    <span><?php echo sanitize($item['document_type']); ?>
                        <?php echo dueDateHtml($item['due_date'], $item['status']); ?>
                    </span>
                    <span class="status-badge <?php
                        echo $item['status'] === 'Rejected' ? 'status-badge--fail' : 'status-badge--pending';
                    ?>"><?php echo sanitize($item['status']); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p style="margin-top:1rem;"><a class="btn" href="/GRANTED/scholar/upload_document.php">Upload documents</a></p>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
