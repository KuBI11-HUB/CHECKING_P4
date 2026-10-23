<?php
/**
 * Standalone 403 / 404 page. Load includes/init.php first (session and helpers).
 * Sends the status code, prints one centered card and ends the request.
 */

/** [button label, URL] for this visitor: admin dashboard, scholar My Status, or the login page. */
function errorHomeLink(): array
{
    if (isLoggedIn() && currentRole() === 'admin') {
        return ['Back to dashboard', '/GRANTED/admin/dashboard.php'];
    }
    if (isLoggedIn() && currentRole() === 'scholar') {
        return ['Back to My Status', '/GRANTED/scholar/dashboard.php'];
    }
    return ['Go to log in', '/GRANTED/auth/login.php'];
}

function renderErrorPage(int $status): void
{
    $copy = [
        403 => ['Access denied', 'Your account does not have permission to open this page.'],
        404 => ['Page not found', 'This page does not exist, or it has moved.'],
    ];
    if (!isset($copy[$status])) {
        $status = 404;
    }
    [$title, $message] = $copy[$status];
    [$homeLabel, $homeUrl] = errorHomeLink();

    if (!headers_sent()) {
        http_response_code($status);
        header('Cache-Control: no-store');
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?php echo h($title); ?> — GRANTED</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/GRANTED/assets/css/style.css">
</head>
<body>
<main class="error-page">
    <div class="error-card">
        <p class="error-card__brand">GRANTED</p>
        <h1><?php echo h($title); ?></h1>
        <p class="error-card__message"><?php echo h($message); ?></p>
        <div class="error-card__actions">
            <a class="btn" href="<?php echo h($homeUrl); ?>"><?php echo h($homeLabel); ?></a>
            <?php if ($status === 403): ?>
                <button type="button" class="btn btn--secondary" id="go-back" hidden>Go back</button>
            <?php endif; ?>
        </div>
        <p class="error-card__code">Error <?php echo (int)$status; ?></p>
    </div>
</main>
<script>
    (function () {
        var back = document.getElementById('go-back');
        if (back && window.history.length > 1) {
            back.hidden = false;
            back.addEventListener('click', function () { window.history.back(); });
        }
    })();
</script>
</body>
</html>
<?php
    exit;
}