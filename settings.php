<?php
// Shared by admin and scholar. There is deliberately no $requiredRole: any signed-in user may open it,
// and it only ever touches the signed-in user's OWN account (the user comes from the session, never from the request).
require_once __DIR__ . '/includes/auth_check.php';

const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_BYTES = 72;   // bcrypt ignores everything after 72 bytes, so longer passwords are refused

$pdo = getDbConnection();
$account = currentAccount($pdo);
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // Passwords are used exactly as typed (no trim, no escaping), the same way the login page reads them.
    $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $hash = $stmt->fetchColumn();

    $newLength = function_exists('mb_strlen') ? mb_strlen($new, 'UTF-8') : strlen($new);

    $problem = '';
    if ($current === '' || $new === '' || $confirm === '') {
        $problem = 'Enter all three password fields.';
    } elseif ($hash === false || !password_verify($current, $hash)) {
        $problem = 'Current password is incorrect.';
    } elseif ($newLength < PASSWORD_MIN_LENGTH) {
        $problem = 'The new password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    } elseif (strlen($new) > PASSWORD_MAX_BYTES) {
        $problem = 'The new password must be no longer than ' . PASSWORD_MAX_BYTES . ' characters.';
    } elseif ($new !== $confirm) {
        $problem = 'The new password and confirmation do not match.';
    } elseif ($new === $current) {
        $problem = 'Choose a new password that differs from the current password.';
    }

    if ($problem !== '') {
        $notice = flashBox('Not saved. ' . $problem);
    } else {
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);

        session_regenerate_id(true);
        setFlash('Your password was changed.');
        redirectTo('/GRANTED/settings.php#password');
    }
}

$flash = takeFlash();
if ($notice === '') {
    $notice = flashBox($flash);
}
$privacy = privacyLink();

$pageTitle = 'Settings';
require_once __DIR__ . '/includes/header.php';
?>

<h1>Settings</h1>

<div class="card">
    <h2>Profile</h2>
    <dl class="settings-list">
        <dt>Name</dt><dd><?php echo h($account['name']); ?></dd>
        <dt>Email</dt><dd><?php echo h($account['email']); ?></dd>
        <dt>Role</dt><dd><?php echo h(ucfirst($account['role'])); ?></dd>
    </dl>
    <?php if ($account['role'] === 'scholar'): ?>
        <p class="settings-note">Scholarship records are managed by the office. Contact the scholarship office for profile changes.</p>
    <?php endif; ?>
</div>

<div class="card" id="password">
    <h2>Password</h2>
    <?php echo $notice; ?>
    <form method="POST" action="/GRANTED/settings.php#password" style="max-width:26rem;">
        <?php echo csrfField(); ?>

        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>

        <label for="new_password">New password (at least <?php echo PASSWORD_MIN_LENGTH; ?> characters)</label>
        <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" required>

        <label for="confirm_password">Confirm password</label>
        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" required>

        <button type="submit" data-submitting-label="Saving...">Save password</button>
    </form>
</div>

<?php if ($privacy): ?>
<div class="card">
    <h2>About</h2>
    <p><a href="<?php echo sanitize($privacy['href']); ?>"<?php echo $privacy['external'] ? ' target="_blank" rel="noopener"' : ''; ?>>Privacy policy</a></p>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>