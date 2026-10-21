<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

const SCHOLAR_NAME_MAX = 100;
const SCHOLAR_PROGRAM_MAX = 100;
const SCHOLAR_YEAR_LEVEL_MAX = 6;

$pdo = getDbConnection();
$scholarId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT s.id, s.full_name, s.program, s.year_level, s.scholarship_type_id, u.email
     FROM scholars s
     JOIN users u ON u.id = s.user_id
     WHERE s.id = ?'
);
$stmt->execute([$scholarId]);
$existing = $stmt->fetch();

if (!$existing) {
    http_response_code(404);
    $pageTitle = 'Scholar not found';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <h1>Scholar not found</h1>
    <div class="card">
        <p>There is no scholar with that ID.</p>
        <p style="margin-top:1rem;"><a class="btn" href="/GRANTED/admin/dashboard.php">Back to results</a></p>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$detailUrl = '/GRANTED/admin/scholar_detail.php?id=' . $scholarId;
$editUrl = '/GRANTED/admin/edit_scholar.php?id=' . $scholarId;
$message = '';

$types = $pdo->query('SELECT id, name FROM scholarship_types ORDER BY name')->fetchAll();
$typeIds = array_map('intval', array_column($types, 'id'));
$len = static fn(string $s): int => function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);

$form = [
    'full_name'           => (string)$existing['full_name'],
    'program'             => (string)($existing['program'] ?? ''),
    'year_level'          => (string)($existing['year_level'] ?? ''),
    'scholarship_type_id' => (string)$existing['scholarship_type_id'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    foreach (array_keys($form) as $key) {
        $form[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
    }
    $fullName = sanitize($form['full_name']);
    $program = sanitize($form['program']);
    $yearRaw = trim($form['year_level']);
    $newTypeId = (int)$form['scholarship_type_id'];
    $yearLevel = $yearRaw === '' ? null : filter_var($yearRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => SCHOLAR_YEAR_LEVEL_MAX]]);

    if ($fullName === '') {
        $message = 'Please enter the scholar\'s full name.';
    } elseif ($len($fullName) > SCHOLAR_NAME_MAX) {
        $message = 'The name is too long (' . SCHOLAR_NAME_MAX . ' characters at most).';
    } elseif ($len($program) > SCHOLAR_PROGRAM_MAX) {
        $message = 'The program is too long (' . SCHOLAR_PROGRAM_MAX . ' characters at most).';
    } elseif ($yearLevel === false) {
        $message = 'Year level must be a whole number from 1 to ' . SCHOLAR_YEAR_LEVEL_MAX . ', or left blank.';
    } elseif (!in_array($newTypeId, $typeIds, true)) {
        $message = 'Please choose a scholarship type.';
    } else {
        $stmt = $pdo->prepare(
            'UPDATE scholars SET full_name = ?, program = ?, year_level = ?, scholarship_type_id = ? WHERE id = ?'
        );
        $stmt->execute([$fullName, $program !== '' ? $program : null, $yearLevel, $newTypeId, $scholarId]);

        if ($newTypeId !== (int)$existing['scholarship_type_id']) {
            // New type = new rules. evaluateScholar() is the only thing allowed to set status, GPA and units.
            $status = evaluateScholar($pdo, $scholarId);
            setFlash('Profile updated. The scholarship type changed; recalculated status: ' . ($status ?? 'PENDING') . '.');
        } else {
            setFlash('Profile updated.');
        }
        redirectTo($detailUrl);
    }
}

$pageTitle = 'Edit Scholar';
require_once __DIR__ . '/../includes/header.php';
?>

<p style="margin-bottom:1rem;"><a href="<?php echo $detailUrl; ?>">&larr; Back to <?php echo h($existing['full_name']); ?></a></p>
<h1>Edit scholar profile</h1>

<div class="card">
    <p>Email: <?php echo h($existing['email']); ?> <small>(not editable here)</small></p>
    <?php if ($message): ?><p class="form-error" style="margin-top:0.75rem;"><?php echo sanitize($message); ?></p><?php endif; ?>

    <form method="POST" action="<?php echo $editUrl; ?>">
        <?php echo csrfField(); ?>

        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" value="<?php echo h($form['full_name']); ?>" required>

        <label for="program">Program</label>
        <input type="text" id="program" name="program" value="<?php echo h($form['program']); ?>">

        <label for="year_level">Year level (1–<?php echo SCHOLAR_YEAR_LEVEL_MAX; ?>, optional)</label>
        <input type="number" id="year_level" name="year_level" min="1" max="<?php echo SCHOLAR_YEAR_LEVEL_MAX; ?>" value="<?php echo h($form['year_level']); ?>">

        <label for="scholarship_type_id">Scholarship type</label>
        <select id="scholarship_type_id" name="scholarship_type_id" required>
            <?php foreach ($types as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>" <?php echo (int)$form['scholarship_type_id'] === (int)$t['id'] ? 'selected' : ''; ?>><?php echo h($t['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <p style="margin-top:0.5rem;"><small>Changing the type re-runs the new type's rules for this scholar immediately.</small></p>

        <button type="submit">Save profile</button>
        <a class="btn btn--secondary" href="<?php echo $detailUrl; ?>">Cancel</a>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>