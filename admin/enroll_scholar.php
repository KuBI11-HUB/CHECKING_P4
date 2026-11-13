<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$message = '';
$success = '';
$types = $pdo->query('SELECT id, name FROM scholarship_types ORDER BY name')->fetchAll();
$programs = $pdo->query('SELECT id, name FROM programs WHERE is_active = 1 ORDER BY name')->fetchAll();

$form = [
    'fname' => '',
    'mname' => '',
    'lname' => '',
    'suffix' => '',
    'program_id' => '',
    'year_level' => '',
    'email' => '',
];
$selectedTypes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    foreach (array_keys($form) as $key) {
        $form[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    }
    $selectedTypes = array_values(array_unique(array_filter(
        array_map('intval', is_array($_POST['scholarship_type_ids'] ?? null) ? $_POST['scholarship_type_ids'] : []),
        static fn(int $id): bool => $id > 0
    )));
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $yearLevel = $form['year_level'] === ''
        ? null
        : filter_var($form['year_level'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 6]]);
    $programId = $form['program_id'] === '' ? null : (int)$form['program_id'];

    $typeIds = array_map('intval', array_column($types, 'id'));
    $programIds = array_map('intval', array_column($programs, 'id'));
    if ($form['fname'] === '' || $form['lname'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)
        || strlen($password) < 8 || !$selectedTypes
        || count(array_diff($selectedTypes, $typeIds)) > 0
        || ($programId !== null && !in_array($programId, $programIds, true))
        || $yearLevel === false) {
        $message = 'Enter a first and last name, valid email, password of at least 8 characters, valid program/year, and at least one scholarship type.';
    } elseif (mb_strlen($form['fname']) > 100 || mb_strlen($form['mname']) > 100
        || mb_strlen($form['lname']) > 100 || mb_strlen($form['suffix']) > 20) {
        $message = 'One or more name fields exceed the allowed length.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)');
            $stmt->execute([$form['email'], password_hash($password, PASSWORD_DEFAULT), 'scholar']);
            $userId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO scholars (user_id, fname, mname, lname, suffix, program_id, year_level)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $form['fname'],
                $form['mname'] !== '' ? $form['mname'] : null,
                $form['lname'],
                $form['suffix'] !== '' ? $form['suffix'] : null,
                $programId,
                $yearLevel,
            ]);
            $scholarId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO scholarship_assignments
                    (scholar_id, scholarship_type_id, start_school_year, start_semester, last_activated_at)
                 VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
            );
            foreach ($selectedTypes as $typeId) {
                $stmt->execute([$scholarId, $typeId, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);
            }
            $pdo->commit();
            evaluateScholar($pdo, $scholarId);
            setFlash(formatScholarName([
                'fname' => $form['fname'], 'mname' => $form['mname'],
                'lname' => $form['lname'], 'suffix' => $form['suffix'],
            ]) . ' was enrolled.');
            redirectTo('/GRANTED/admin/dashboard.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('GRANTED scholar enrollment failed: ' . $e->getMessage());
            $message = 'The scholar could not be enrolled. Check the email and details, then try again.';
        }
    }
}

$pageTitle = 'Enroll Scholar';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Enroll a scholar</h1>
<p>Create a scholar account and assign their awarded scholarships.</p>

<div class="card">
    <?php if ($message): ?><p class="form-error"><?php echo h($message); ?></p><?php endif; ?>
    <?php if (!$types): ?><p>Add a <a href="/GRANTED/admin/scholarship_types.php">scholarship type</a> before enrolling.</p><?php endif; ?>
    <?php if (!$programs): ?><p>No active programs are available. Add one in <a href="/GRANTED/admin/programs.php">Programs</a> or leave the program blank.</p><?php endif; ?>
    <?php if ($types): ?>
    <form method="POST" action="" class="enroll-scholar-form">
        <?php echo csrfField(); ?>
        <label for="fname">First name</label>
        <input type="text" id="fname" name="fname" maxlength="100" value="<?php echo h($form['fname']); ?>" required>
        <label for="mname">Middle name (optional)</label>
        <input type="text" id="mname" name="mname" maxlength="100" value="<?php echo h($form['mname']); ?>">
        <label for="lname">Last name</label>
        <input type="text" id="lname" name="lname" maxlength="100" value="<?php echo h($form['lname']); ?>" required>
        <label for="suffix">Suffix (optional)</label>
        <input type="text" id="suffix" name="suffix" maxlength="20" value="<?php echo h($form['suffix']); ?>">

        <label for="program_id">Program</label>
        <select id="program_id" name="program_id">
            <option value="">Not selected</option>
            <?php foreach ($programs as $program): ?>
                <option value="<?php echo (int)$program['id']; ?>" <?php echo $form['program_id'] === (string)$program['id'] ? 'selected' : ''; ?>><?php echo h($program['name']); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="year_level">Year level</label>
        <input type="number" id="year_level" name="year_level" min="1" max="6" value="<?php echo h($form['year_level']); ?>">

        <fieldset class="scholarship-picker" aria-describedby="scholarship-picker-help" aria-required="true">
            <legend>Scholarship type(s) <span class="scholarship-picker__required">Required</span></legend>
            <p id="scholarship-picker-help" class="scholarship-picker__help">Select all scholarships the student has been awarded. At least one is required.</p>
            <div class="scholarship-picker__options">
            <?php foreach ($types as $type): ?>
                <label class="scholarship-picker__option">
                    <input type="checkbox" name="scholarship_type_ids[]" value="<?php echo (int)$type['id']; ?>" <?php echo in_array((int)$type['id'], $selectedTypes, true) ? 'checked' : ''; ?>>
                    <span><?php echo h($type['name']); ?></span>
                </label>
            <?php endforeach; ?>
            </div>
        </fieldset>

        <label for="email">Login email</label>
        <input type="email" id="email" name="email" maxlength="150" value="<?php echo h($form['email']); ?>" required>
        <label for="password">Initial password</label>
        <input type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
        <button type="submit" <?php echo !$types ? 'disabled' : ''; ?>>Enroll scholar</button>
    </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
