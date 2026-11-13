<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

const SCHOLAR_YEAR_LEVEL_MAX = 6;

$pdo = getDbConnection();
$scholarId = (int)($_GET['id'] ?? 0);
$detailUrl = '/GRANTED/admin/scholar_detail.php?id=' . $scholarId;
$message = '';

$stmt = $pdo->prepare(
    'SELECT s.id, s.fname, s.mname, s.lname, s.suffix, s.program_id, s.year_level, u.email
     FROM scholars s JOIN users u ON u.id = s.user_id WHERE s.id = ?'
);
$stmt->execute([$scholarId]);
$existing = $stmt->fetch();
if (!$existing) {
    http_response_code(404);
    $pageTitle = 'Scholar not found';
    require_once __DIR__ . '/../includes/header.php';
    echo '<h1>Scholar not found</h1><div class="card"><p>There is no scholar with that ID.</p></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$programs = $pdo->query('SELECT id, name, is_active FROM programs ORDER BY is_active DESC, name')->fetchAll();
$types = $pdo->query('SELECT id, name FROM scholarship_types ORDER BY name')->fetchAll();
$form = [
    'fname' => (string)$existing['fname'],
    'mname' => (string)($existing['mname'] ?? ''),
    'lname' => (string)$existing['lname'],
    'suffix' => (string)($existing['suffix'] ?? ''),
    'program_id' => (string)($existing['program_id'] ?? ''),
    'year_level' => (string)($existing['year_level'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    try {
        if ($action === 'save_profile') {
            foreach (array_keys($form) as $key) {
                $form[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
            }
            $programId = $form['program_id'] === '' ? null : (int)$form['program_id'];
            $yearLevel = $form['year_level'] === ''
                ? null
                : filter_var($form['year_level'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => SCHOLAR_YEAR_LEVEL_MAX]]);
            $programIds = array_map('intval', array_column($programs, 'id'));
            if ($form['fname'] === '' || $form['lname'] === '' || $yearLevel === false
                || ($programId !== null && !in_array($programId, $programIds, true))
                || strlen($form['fname']) > 100 || strlen($form['mname']) > 100
                || strlen($form['lname']) > 100 || strlen($form['suffix']) > 20) {
                throw new InvalidArgumentException('Enter valid required names, program, and year level.');
            }
            $stmt = $pdo->prepare(
                'UPDATE scholars SET fname = ?, mname = ?, lname = ?, suffix = ?, program_id = ?, year_level = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $form['fname'], $form['mname'] !== '' ? $form['mname'] : null,
                $form['lname'], $form['suffix'] !== '' ? $form['suffix'] : null,
                $programId, $yearLevel, $scholarId,
            ]);
            setFlash('Scholar profile updated.');
        } elseif ($action === 'add_assignment') {
            $typeId = (int)($_POST['scholarship_type_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT id FROM scholarship_types WHERE id = ?');
            $stmt->execute([$typeId]);
            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException('Choose a valid scholarship type.');
            }
            $stmt = $pdo->prepare(
                'SELECT id, is_active, ended_at FROM scholarship_assignments
                 WHERE scholar_id = ? AND scholarship_type_id = ?'
            );
            $stmt->execute([$scholarId, $typeId]);
            $assignment = $stmt->fetch();
            if ($assignment && (int)$assignment['is_active'] === 1 && $assignment['ended_at'] === null) {
                throw new InvalidArgumentException('This scholarship assignment is already active.');
            }
            if ($assignment) {
                $stmt = $pdo->prepare(
                    'UPDATE scholarship_assignments
                     SET is_active = 1, ended_at = NULL, start_school_year = ?,
                         start_semester = ?, last_activated_at = CURRENT_TIMESTAMP
                     WHERE id = ?'
                );
                $stmt->execute([CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER, (int)$assignment['id']]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO scholarship_assignments
                        (scholar_id, scholarship_type_id, start_school_year, start_semester, last_activated_at)
                     VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
                );
                $stmt->execute([$scholarId, $typeId, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);
            }
            evaluateScholar($pdo, $scholarId);
            setFlash('Scholarship assignment added or reactivated and evaluated.');
        } elseif ($action === 'end_assignment') {
            $assignmentId = (int)($_POST['assignment_id'] ?? 0);
            $stmt = $pdo->prepare(
                'UPDATE scholarship_assignments
                 SET is_active = 0, ended_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND scholar_id = ? AND is_active = 1 AND ended_at IS NULL'
            );
            $stmt->execute([$assignmentId, $scholarId]);
            if ($stmt->rowCount() !== 1) {
                throw new InvalidArgumentException('That active assignment could not be found.');
            }
            evaluateScholar($pdo, $scholarId);
            setFlash('Scholarship assignment ended.');
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        redirectTo($detailUrl);
    } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
    } catch (PDOException $e) {
        error_log('GRANTED scholar update failed: ' . $e->getMessage());
        $message = 'The change could not be saved. Check the selected values and try again.';
    }
}

$assignments = getScholarAssignments($pdo, $scholarId, false);
$activeTypeIds = [];
foreach ($assignments as $assignment) {
    if ((int)$assignment['is_active'] === 1 && $assignment['ended_at'] === null) {
        $activeTypeIds[] = (int)$assignment['scholarship_type_id'];
    }
}
$pageTitle = 'Edit Scholar';
require_once __DIR__ . '/../includes/header.php';
?>

<p style="margin-bottom:1rem;"><a class="back-link" href="<?php echo $detailUrl; ?>">&larr; Back to <?php echo h(formatScholarName($existing)); ?></a></p>
<h1>Edit scholar profile</h1>
<?php echo flashBox(takeFlash()); ?>
<?php if ($message): ?><p class="form-error"><?php echo h($message); ?></p><?php endif; ?>

<div class="card">
    <h2>Profile</h2>
    <p>Email: <?php echo h($existing['email']); ?> <small>(not editable here)</small></p>
    <form method="POST">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="save_profile">
        <label for="fname">First name</label><input id="fname" name="fname" maxlength="100" value="<?php echo h($form['fname']); ?>" required>
        <label for="mname">Middle name</label><input id="mname" name="mname" maxlength="100" value="<?php echo h($form['mname']); ?>">
        <label for="lname">Last name</label><input id="lname" name="lname" maxlength="100" value="<?php echo h($form['lname']); ?>" required>
        <label for="suffix">Suffix</label><input id="suffix" name="suffix" maxlength="20" value="<?php echo h($form['suffix']); ?>">
        <label for="program_id">Program</label>
        <select id="program_id" name="program_id"><option value="">Not selected</option>
            <?php foreach ($programs as $program): ?>
                <option value="<?php echo (int)$program['id']; ?>" <?php echo $form['program_id'] === (string)$program['id'] ? 'selected' : ''; ?>>
                    <?php echo h($program['name'] . (!(int)$program['is_active'] ? ' (inactive)' : '')); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="year_level">Year level (optional)</label>
        <input type="number" id="year_level" name="year_level" min="1" max="<?php echo SCHOLAR_YEAR_LEVEL_MAX; ?>" value="<?php echo h($form['year_level']); ?>">
        <button type="submit">Save profile</button>
    </form>
</div>

<div class="card">
    <h2>Scholarship assignments</h2>
    <?php if (!$assignments): ?><p>No assignments yet.</p><?php else: ?>
        <table class="data-table"><thead><tr><th>Scholarship</th><th>Status</th><th>State</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($assignments as $assignment): $isActive = (int)$assignment['is_active'] === 1 && $assignment['ended_at'] === null; ?>
            <tr>
                <td><?php echo h($assignment['type_name']); ?></td>
                <td><?php echo h($assignment['status']); ?></td>
                <td><?php echo $isActive ? 'Active' : 'Ended'; ?></td>
                <td><?php if ($isActive): ?>
                    <form method="POST" class="inline-form" onsubmit="return confirm('End this scholarship assignment?');">
                        <?php echo csrfField(); ?><input type="hidden" name="action" value="end_assignment">
                        <input type="hidden" name="assignment_id" value="<?php echo (int)$assignment['id']; ?>">
                        <button class="btn btn--danger" type="submit">End assignment</button>
                    </form>
                <?php else: ?><span>Can be reactivated below</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
    <?php $availableTypes = array_values(array_filter($types, static fn(array $type): bool => !in_array((int)$type['id'], $activeTypeIds, true))); ?>
    <?php if ($availableTypes): ?>
        <form method="POST" style="margin-top:1rem;">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="add_assignment">
            <label for="scholarship_type_id">Add or reactivate scholarship type</label>
            <select id="scholarship_type_id" name="scholarship_type_id" required>
                <?php foreach ($availableTypes as $type): ?><option value="<?php echo (int)$type['id']; ?>"><?php echo h($type['name']); ?></option><?php endforeach; ?>
            </select>
            <button type="submit">Add assignment</button>
        </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
