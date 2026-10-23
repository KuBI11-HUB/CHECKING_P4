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
<div class="login-split">
    <section class="login-split__form" aria-labelledby="login-title">
        <h1 id="login-title" class="login-split__title">Log in to GRANTED</h1>

        <?php if (isset($_GET['timeout'])): ?><p class="form-info" role="status">You were logged out after 30 minutes of inactivity. Please log in again.</p><?php endif; ?>
        <?php if ($error): ?><p class="form-error" role="alert"><?php echo sanitize($error); ?></p><?php endif; ?>

        <form method="POST" action="">
            <?php echo csrfField(); ?>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" autocomplete="username" required autofocus>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <button type="submit" data-submitting-label="Logging in...">Log in</button>
        </form>
    </section>

    <aside class="login-split__brand" aria-label="About GRANTED">
        <p class="login-split__name">GRANTED</p>
        <p class="login-split__org">University of Luzon</p>
        <ul class="login-split__notes">
            <li>Check your scholarship status and what each requirement needs.</li>
            <li>Upload compliance documents before their deadlines.</li>
            <li>See notifications about status changes and reminders.</li>
        </ul>
    </aside>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
