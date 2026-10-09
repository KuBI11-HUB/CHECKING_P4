<?php
require_once __DIR__ . '/../includes/init.php';

if (isLoggedIn()) {
    redirectTo(currentRole() === 'admin' ? '/GRANTED/admin/dashboard.php' : '/GRANTED/scholar/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please fill in both fields.';
    } else {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare('SELECT id, email, password_hash, role FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            redirectTo($user['role'] === 'admin' ? '/GRANTED/admin/dashboard.php' : '/GRANTED/scholar/dashboard.php');
        } else {
            $error = 'Invalid email or password.';
        }
    }
}

$pageTitle = 'Log in';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="login-layout">
    <div class="login-layout__brand">
        <h1>GRANTED</h1>
        <p>Scholarship Compliance Monitoring and Renewal Management — University of Luzon</p>
    </div>
    <div class="login-layout__form">
        <h2>Log in</h2>
        <?php if ($error): ?><p class="form-error"><?php echo sanitize($error); ?></p><?php endif; ?>
        <form method="POST" action="">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required autofocus>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
            <button type="submit">Log in</button>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
