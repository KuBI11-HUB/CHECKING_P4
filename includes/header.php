<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? sanitize($pageTitle) . ' — GRANTED' : 'GRANTED'; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@600;700&family=Public+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/GRANTED/assets/css/style.css">
    <script>document.documentElement.className += ' js';</script>
</head>
<body>
<?php if (isLoggedIn()): ?>
<?php
$headerAccount = currentAccount(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
$headerPrivacy = privacyLink();
?>
<header class="site-header">
    <div class="site-header__brand">GRANTED</div>
    <nav class="site-header__nav" id="site-nav" aria-label="Main">
        <?php if (currentRole() === 'admin'): ?>
            <a href="/GRANTED/admin/dashboard.php"><span class="icon-label"><?php echo uiIcon('layout-list'); ?>Dashboard</span></a>
            <a href="/GRANTED/admin/scholarship_types.php">Scholarship Types</a>
            <a href="/GRANTED/admin/rules.php">Rules</a>
            <a href="/GRANTED/admin/enroll_scholar.php"><span class="icon-label"><?php echo uiIcon('users'); ?>Enroll Scholar</span></a>
            <a href="/GRANTED/admin/enter_grades.php">Enter Grades</a>
            <a href="/GRANTED/admin/document_review.php"><span class="icon-label"><?php echo uiIcon('file-text'); ?>Document Review</span></a>
        <?php else: ?>
            <a href="/GRANTED/scholar/dashboard.php"><span class="icon-label"><?php echo uiIcon('layout-list'); ?>My Status</span></a>
            <a href="/GRANTED/scholar/upload_document.php"><span class="icon-label"><?php echo uiIcon('file-text'); ?>Upload Documents</span></a>
            <a href="/GRANTED/scholar/notifications.php">Notifications</a>
        <?php endif; ?>
    </nav>
    <div class="site-header__actions">
        <button type="button" class="nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="site-nav">Menu</button>
        <details class="account-menu">
            <summary class="account-chip" aria-label="Account menu for <?php echo h($headerAccount['name']); ?>">
                <span class="account-chip__avatar" aria-hidden="true"><?php echo h($headerAccount['initials']); ?></span>
                <span class="account-chip__name"><?php echo h($headerAccount['name']); ?></span>
                <span class="account-chip__chevron" aria-hidden="true"></span>
            </summary>
            <div class="account-menu__panel">
                <div class="account-menu__who">
                    <span class="account-menu__avatar" aria-hidden="true"><?php echo h($headerAccount['initials']); ?></span>
                    <span class="account-menu__id">
                        <strong><?php echo h($headerAccount['name']); ?></strong>
                        <span><?php echo h($headerAccount['email']); ?></span>
                        <span class="account-menu__role"><?php echo h(ucfirst($headerAccount['role'])); ?></span>
                    </span>
                </div>
                <div class="account-menu__sep" role="separator"></div>
                <a href="/GRANTED/settings.php"><span class="icon-label"><?php echo uiIcon('settings'); ?>Settings</span></a>
                <?php if ($headerPrivacy): ?>
                    <a href="<?php echo sanitize($headerPrivacy['href']); ?>"<?php echo $headerPrivacy['external'] ? ' target="_blank" rel="noopener"' : ''; ?>>Privacy</a>
                <?php endif; ?>
                <div class="account-menu__sep" role="separator"></div>
                <a href="/GRANTED/auth/logout.php"><span class="icon-label"><?php echo uiIcon('log-out'); ?>Log out</span></a>
            </div>
        </details>
    </div>
</header>
<script>
    (function () {
        var toggle = document.getElementById('nav-toggle');
        var nav = document.getElementById('site-nav');
        if (toggle && nav) {
            toggle.addEventListener('click', function () {
                var open = nav.classList.toggle('is-open');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        var menu = document.querySelector('.account-menu');
        if (!menu) { return; }
        document.addEventListener('click', function (e) {
            if (menu.open && !menu.contains(e.target)) { menu.open = false; }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && menu.open) { menu.open = false; menu.querySelector('summary').focus(); }
        });
    })();
</script>
<?php endif; ?>
<main class="site-main">