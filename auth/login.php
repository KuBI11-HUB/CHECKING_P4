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
        $error = 'Enter your email and password.';
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

$introParam = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && !isset($_GET['timeout'])
    && is_string($_GET['intro'] ?? null)
    ? $_GET['intro']
    : '';
$showIntro = in_array($introParam, ['1', 'replay'], true);
$replayIntro = $introParam === 'replay';

$pageTitle = 'Sign in';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="login-split" id="login-split">
    <?php if ($showIntro): ?>
    <script>
        (function () {
            var root = document.getElementById('login-split');
            var seen = false;
            try { seen = <?php echo $replayIntro ? 'false' : 'true'; ?> && window.localStorage.getItem('granted_intro_seen') === '1'; } catch (e) {}
            if (root && !seen) { root.classList.add('is-intro'); }
        })();
    </script>
    <?php endif; ?>
    <aside class="login-split__brand" aria-label="About GRANTED">
        <img class="login-split__logo" src="/GRANTED/assets/icons/LOGO.png" alt="">
        <p class="login-split__name">GRANTED</p>
        <p class="login-split__welcome">Scholarship compliance monitoring</p>
        <p class="login-split__line">University of Luzon</p>
    </aside>

    <section class="login-split__form" aria-labelledby="login-title">
        <?php if ($showIntro): ?>
        <div class="login-intro" id="login-intro">
            <div class="login-intro__inner">
                <p class="login-intro__purpose">Check your requirements, upload documents, and get notified when your status changes.</p>
                <a class="login-intro__go" id="login-intro-go" href="/GRANTED/auth/login.php">Sign in</a>
                <p class="login-intro__note">Accounts are issued by the Scholarship Office.</p>
            </div>
        </div>
        <?php endif; ?>
        <h1 id="login-title" class="login-split__title">Sign in</h1>
        <p class="login-split__sub">Sign in with the account issued by the scholarship office.</p>

        <?php if (isset($_GET['timeout'])): ?><p class="form-info" role="status">You were logged out after 30 minutes of inactivity. Please sign in again.</p><?php endif; ?>
        <?php if ($error): ?><p class="form-error" role="alert"><?php echo sanitize($error); ?></p><?php endif; ?>

        <form method="POST" action="">
            <?php echo csrfField(); ?>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" autocomplete="username" placeholder="Enter your email address" required autofocus>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
            <button type="submit" data-submitting-label="Signing in...">Sign in</button>
        </form>

        <p class="login-split__help">Need help with your password? Visit the Scholarship Office with your school ID. Staff can set a temporary password for you.</p>
        <nav class="login-split__footer-links" aria-label="University links">
            <a href="https://ul.edu.ph/" target="_blank" rel="noopener noreferrer">University of Luzon</a>
            <a href="https://www.facebook.com/luzonuniversity" target="_blank" rel="noopener noreferrer">Facebook</a>
        </nav>
    </section>
</div>
<?php if ($showIntro): ?>
<script>
    (function () {
        var root = document.getElementById('login-split');
        var go = document.getElementById('login-intro-go');
        if (!root || !go) { return; }
        go.addEventListener('click', function (e) {
            if (!root.classList.contains('is-intro')) { return; }
            e.preventDefault();
            try { window.localStorage.setItem('granted_intro_seen', '1'); } catch (err) {}
            try { window.history.replaceState(null, '', window.location.pathname); } catch (err) {}
            root.classList.remove('is-intro');
            var email = document.getElementById('email');
            if (email) { email.focus({ preventScroll: true }); }
        });
    })();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
