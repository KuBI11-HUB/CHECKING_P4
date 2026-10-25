<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$gradeId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT g.id, g.scholar_id, g.subject, g.grade, g.units, g.school_year, g.semester, s.full_name
     FROM grades g
     JOIN scholars s ON s.id = g.scholar_id
     WHERE g.id = ?'
);
$stmt->execute([$gradeId]);
$existing = $stmt->fetch();

if (!$existing) {
    http_response_code(404);
    $pageTitle = 'Grade not found';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <h1>Grade not found</h1>
    <div class="card">
        <p>There is no grade with that ID. It may already have been deleted.</p>
        <p style="margin-top:1rem;"><a class="back-link" href="/GRANTED/admin/dashboard.php">&larr; Back to results</a></p>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$scholarId = (int)$existing['scholar_id'];
$detailUrl = '/GRANTED/admin/scholar_detail.php?id=' . $scholarId;
$editUrl = '/GRANTED/admin/edit_grade.php?id=' . $gradeId;
$message = '';

$form = [
    'subject' => (string)$existing['subject'],
    'grade' => (string)$existing['grade'],
    'units' => (string)$existing['units'],
    'school_year' => (string)$existing['school_year'],
    'semester' => (string)$existing['semester'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // Keep what the admin typed so a failed save does not wipe the form.
    foreach (array_keys($form) as $key) {
        $form[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
    }

    // The scholar of a grade never changes here; only the grade's own fields do.
    $result = validateGradeInput($pdo, $form + ['scholar_id' => $scholarId], $gradeId);

    if ($result['error'] !== null) {
        $message = $result['error'];
    } else {
        $v = $result['values'];
        try {
            $stmt = $pdo->prepare(
                'UPDATE grades SET subject = ?, grade = ?, units = ?, school_year = ?, semester = ?
                 WHERE id = ? AND scholar_id = ?'
            );
            $stmt->execute([$v['subject'], $v['grade'], $v['units'], $v['school_year'], $v['semester'], $gradeId, $scholarId]);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {   // a unique key caught a duplicate the check missed
                $message = 'A grade for "' . html_entity_decode($v['subject'], ENT_QUOTES, 'UTF-8')
                    . '" is already recorded for this scholar in ' . $v['school_year'] . ' ' . $v['semester']
                    . '. Duplicate grades are not allowed.';
            } else {
                throw $e;
            }
        }

        if ($message === '') {
            $status = evaluateScholar($pdo, $scholarId);
            setFlash('Grade updated: ' . plainText($v['subject']) . '. Recalculated status: ' . ($status ?? 'PENDING') . '.');
            redirectTo($detailUrl);
        }
    }
}

$pageTitle = 'Edit Grade';
require_once __DIR__ . '/../includes/header.php';
?>

<p style="margin-bottom:1rem;"><a class="back-link" href="<?php echo $detailUrl; ?>">&larr; Back to <?php echo h($existing['full_name']); ?></a></p>
<h1>Edit grade</h1>

<div class="card">
    <p>Scholar: <strong><?php echo h($existing['full_name']); ?></strong></p>
    <p><small>Saving recalculates this scholar's GPA, units and status for the current term (<?php echo h(CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER); ?>).</small></p>
    <?php if ($message): ?><p class="form-error" style="margin-top:0.75rem;"><?php echo sanitize($message); ?></p><?php endif; ?>

    <form method="POST" action="<?php echo $editUrl; ?>">
        <?php echo csrfField(); ?>

        <label for="subject">Subject</label>
        <input type="text" id="subject" name="subject" value="<?php echo h($form['subject']); ?>" required>

        <label for="grade">Grade</label>
        <input type="number" id="grade" name="grade" step="0.01" min="1" max="5" value="<?php echo h($form['grade']); ?>" required>

        <label for="units">Units</label>
        <input type="number" id="units" name="units" min="1" max="10" value="<?php echo h($form['units']); ?>" required>

        <label for="school_year">School year</label>
        <input type="text" id="school_year" name="school_year" value="<?php echo h($form['school_year']); ?>" placeholder="2026-2027" pattern="\d{4}-\d{4}" title="Format: 2026-2027" required>

        <label for="semester">Semester</label>
        <select id="semester" name="semester" required>
            <?php foreach (['1st', '2nd', 'Summer'] as $sem): ?>
                <option value="<?php echo $sem; ?>" <?php echo $form['semester'] === $sem ? 'selected' : ''; ?>><?php echo $sem; ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit">Save changes</button>
        <a class="btn btn--secondary" href="<?php echo $detailUrl; ?>">Cancel</a>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>