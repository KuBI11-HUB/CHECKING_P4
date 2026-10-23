<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? sanitize($pageTitle) . ' — GRANTED' : 'GRANTED'; ?></title>
    <link rel="icon" type="image/jpeg" href="/GRANTED/assets/icons/LOGO.jpg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/GRANTED/assets/css/style.css">
    <script>document.documentElement.className += ' js';</script>
</head>
<body>
<?php if (isLoggedIn()): ?>
<?php
$headerAccount = currentAccount(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
$headerPrivacy = privacyLink();
?>
<?php if (isAdminShell() || isScholarShell()): ?>
<?php
$shellNav = isAdminShell()
    ? [
        ['Dashboard', 'layout-list', '/GRANTED/admin/dashboard.php', ['dashboard.php', 'scholar_detail.php', 'edit_scholar.php', 'edit_grade.php']],
        ['Scholarship Types', 'book-text', '/GRANTED/admin/scholarship_types.php', ['scholarship_types.php', 'edit_scholarship_type.php']],
        ['Rules', 'list-checks', '/GRANTED/admin/rules.php', ['rules.php', 'edit_rule.php']],
        ['Enroll Scholar', 'users', '/GRANTED/admin/enroll_scholar.php', ['enroll_scholar.php']],
        ['Enter Grades', 'pencil', '/GRANTED/admin/enter_grades.php', ['enter_grades.php', 'import_grades.php']],
        ['Document Review', 'file-text', '/GRANTED/admin/document_review.php', ['document_review.php']],
    ]
    : [
        ['My Status', 'layout-list', '/GRANTED/scholar/dashboard.php', ['dashboard.php']],
        ['Upload Documents', 'file-text', '/GRANTED/scholar/upload_document.php', ['upload_document.php']],
        ['Notifications', 'bell', '/GRANTED/scholar/notifications.php', ['notifications.php']],
    ];
$activePage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
?>
<div class="portal-shell <?php echo isAdminShell() ? 'portal-shell--admin' : 'portal-shell--scholar'; ?>">
    <header class="portal-topbar">
        <button type="button" class="nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="site-nav">Menu</button>
        <span class="portal-topbar__brand"><img class="portal-brand__logo" src="/GRANTED/assets/icons/LOGO.jpg" alt="">GRANTED</span>
        <?php include __DIR__ . '/account_menu.php'; ?>
    </header>
    <aside class="portal-sidebar" id="site-nav">
        <div class="portal-sidebar__brand">
            <div class="portal-sidebar__identity">
                <img class="portal-brand__logo" src="/GRANTED/assets/icons/LOGO.jpg" alt="">
                <p class="portal-sidebar__name">GRANTED</p>
            </div>
            <p class="portal-sidebar__org">University of Luzon</p>
        </div>
        <nav class="portal-sidebar__nav" aria-label="Main">
            <?php foreach ($shellNav as [$label, $icon, $href, $pages]): ?>
                <?php $isActive = in_array($activePage, $pages, true); ?>
                <a href="<?php echo $href; ?>"<?php echo $isActive ? ' class="is-active" aria-current="page"' : ''; ?>><?php echo uiIcon($icon); ?><span><?php echo $label; ?></span></a>
            <?php endforeach; ?>
        </nav>
    </aside>
<?php else: ?>
<header class="site-header">
    <div class="site-header__brand">GRANTED</div>
    <nav class="site-header__nav" id="site-nav" aria-label="Main">
        <a href="/GRANTED/scholar/dashboard.php"><span class="icon-label"><?php echo uiIcon('layout-list'); ?>My Status</span></a>
        <a href="/GRANTED/scholar/upload_document.php"><span class="icon-label"><?php echo uiIcon('file-text'); ?>Upload Documents</span></a>
        <a href="/GRANTED/scholar/notifications.php"><span class="icon-label"><?php echo uiIcon('bell'); ?>Notifications</span></a>
    </nav>
    <div class="site-header__actions">
        <button type="button" class="nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="site-nav">Menu</button>
        <?php include __DIR__ . '/account_menu.php'; ?>
    </div>
</header>
<?php endif; ?>
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
<?php if (isAdminShell() || isScholarShell()): ?><div class="portal-content"><?php endif; ?>
<main class="site-main">