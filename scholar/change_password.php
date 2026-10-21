<?php
$requiredRole = 'scholar';
require_once __DIR__ . '/../includes/auth_check.php';

const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_BYTES = 72;   // bcrypt ignores everything after 72 bytes, so longer passwords are refused instead of silently cut

$pdo = getDbConnection();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // Passwords are used exactly as typed (no trim, no escaping), the same way the login page reads them.
    $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    // Own account only: the user comes from the session, never from the request.
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $hash = $stmt->fetchColumn();

    $newLength = function_exists('mb_strlen') ? mb_strlen($new, 'UTF-8') : strlen($new);

    if ($current === '' || $new === '' || $confirm === '') {
        $message = 'Please fill in all three fields.';
    } elseif ($hash === false || !password_verify($current, $hash)) {
        $message = 'Your current password is incorrect.';
    } elseif ($newLength < PASSWORD_MIN_LENGTH) {
        $message = 'The new password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    } elseif (strlen($new) > PASSWORD_MAX_BYTES) {
        $message = 'The new password is too long (' . PASSWORD_MAX_BYTES . ' characters at most).';
    } elseif ($new !== $confirm) {
        $message = 'The new password and the confirmation do not match.';
    } elseif ($new === $current) {
        $message = 'The new password must be different from the current one.';
    } else {
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);

        session_regenerate_id(true);
        setFlash('Your password was changed.');
        redirectTo('/GRANTED/scholar/change_password.php');
    }
}

$flash = takeFlash();

$pageTitle = 'Change Password';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Change password</h1>

<div class="card">
    <?php echo flashBox($flash); ?>
    <?php if ($message): ?><p class="form-error"><?php echo sanitize($message); ?></p><?php endif; ?>

    <form method="POST" action="" style="max-width:26rem;">
        <?php echo csrfField(); ?>

        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>

        <label for="new_password">New password (at least <?php echo PASSWORD_MIN_LENGTH; ?> characters)</label>
        <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" required>

        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" required>

        <button type="submit" data-submitting-label="Saving...">Change password</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>