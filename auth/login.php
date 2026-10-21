<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/login_throttle.php';
if (isLoggedIn()) {
    redirectTo(currentRole() === 'admin' ? '/GRANTED/admin/dashboard.php' : '/GRANTED/scholar/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = clean(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if ($email === '' || $password === '') {
        $error = 'Please fill in both fields.';
    } else {
        $pdo = getDbConnection();

        if (loginIsThrottled($pdo, $email)) {
            $error = 'Too many failed attempts. Please wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.';
        } else {
            $stmt = $pdo->prepare('SELECT id, email, password_hash, role FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                loginClearFailures($pdo, $email);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                redirectTo($user['role'] === 'admin' ? '/GRANTED/admin/dashboard.php' : '/GRANTED/scholar/dashboard.php');
            } else {
                loginRecordFailure($pdo, $email);
                $error = 'Invalid email or password.';
            }
        }
    }
}

$pageTitle = 'Log in';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="login-page">
    <div class="login-card">
        <div class="login-card__brand">
            <svg class="login-card__mark" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
                <path fill="currentColor" d="M24 4l16 6v14c0 9-7 16-16 20C15 40 8 33 8 24V10z"/>
                <path d="M16 24l6 6 11-12" fill="none" stroke="#FFFFFF" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <h1 class="login-card__title">GRANTED</h1>
            <p class="login-card__subtitle">Scholarship compliance monitoring &middot; University of Luzon</p>
        </div>

        <?php if (isset($_GET['timeout'])): ?><p class="form-info" role="status">You were logged out after 30 minutes of inactivity. Please log in again.</p><?php endif; ?>
        <?php if ($error): ?><p class="form-error" role="alert"><?php echo sanitize($error); ?></p><?php endif; ?>

        <form method="POST" action="">
            <?php echo csrfField(); ?>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" autocomplete="username" required autofocus>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <button type="submit">Log in</button>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
