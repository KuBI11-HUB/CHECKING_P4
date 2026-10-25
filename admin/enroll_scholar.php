<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$message = '';
$success = '';

$types = $pdo->query('SELECT * FROM scholarship_types ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $fullName = sanitize($_POST['full_name'] ?? '');
    $program = sanitize($_POST['program'] ?? '');
    $yearLevel = (int)($_POST['year_level'] ?? 0);
    $scholarshipTypeId = (int)($_POST['scholarship_type_id'] ?? 0);
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($fullName === '' || $email === '' || $password === '' || !$scholarshipTypeId) {
        $message = 'Full name, scholarship type, email, and password are all required.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $message = 'That email is already in use.';
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)');
                $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), 'scholar']);
                $userId = (int)$pdo->lastInsertId();

                $stmt = $pdo->prepare(
                    'INSERT INTO scholars (user_id, full_name, program, year_level, scholarship_type_id, current_status)
                     VALUES (?, ?, ?, ?, ?, "PENDING")'
                );
                $stmt->execute([$userId, $fullName, $program, $yearLevel ?: null, $scholarshipTypeId]);
                $scholarId = (int)$pdo->lastInsertId();

                $pdo->commit();

                // Run once now so a freshly enrolled scholar isn't left with no evaluation at all
                evaluateScholar($pdo, $scholarId);

                $success = sanitize($fullName) . ' was enrolled. Give them the login email and initial password.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = 'The scholar could not be enrolled. Check the details and try again.';
            }
        }
    }
}

$pageTitle = 'Enroll Scholar';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Enroll a scholar</h1>
<p>Only use this after someone has already been awarded a scholarship — this is not a registration form.</p>

<div class="card">
    <?php if ($message): ?><p class="form-error"><?php echo sanitize($message); ?></p><?php endif; ?>
    <?php if ($success): ?><p class="form-success"><?php echo $success; ?></p><?php endif; ?>

    <?php if (empty($types)): ?>
        <p>You need at least one <a href="/GRANTED/admin/scholarship_types.php">scholarship type</a> before enrolling a scholar.</p>
    <?php else: ?>
    <form method="POST" action="">
        <?php echo csrfField(); ?>
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" required>

        <label for="program">Program</label>
        <input type="text" id="program" name="program" placeholder="Example: BS Computer Science">

        <label for="year_level">Year level</label>
        <input type="number" id="year_level" name="year_level" min="1" max="6">

        <label for="scholarship_type_id">Scholarship type</label>
        <select id="scholarship_type_id" name="scholarship_type_id" required>
            <?php foreach ($types as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>"><?php echo sanitize($t['name']); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="email">Login email</label>
        <input type="email" id="email" name="email" placeholder="Scholar's login email" required>

        <label for="password">Initial password</label>
        <input type="text" id="password" name="password" placeholder="Set an initial password" required>

        <button type="submit">Enroll scholar</button>
    </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
