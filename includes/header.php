<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? sanitize($pageTitle) . ' — GRANTED' : 'GRANTED'; ?></title>
    <link rel="icon" type="image/png" href="/GRANTED/assets/icons/LOGO.png">
    <?php include __DIR__ . '/stylesheets.php'; ?>
    <script>document.documentElement.className += ' js';</script>
    <?php include __DIR__ . '/theme_head.php'; ?>
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
        ['Dashboard', 'layout-list', '/GRANTED/admin/dashboard.php', ['dashboard.php', 'scholar_detail.php', 'edit_scholar.php']],
        ['Scholarship Types', 'book-text', '/GRANTED/admin/scholarship_types.php', ['scholarship_types.php', 'edit_scholarship_type.php']],
        ['Rules', 'circle-check', '/GRANTED/admin/rules.php', ['rules.php', 'edit_rule.php']],
        ['Programs', 'inbox', '/GRANTED/admin/programs.php', ['programs.php']],
        ['Requirements', 'list-checks', '/GRANTED/admin/requirements.php', ['requirements.php']],
        ['Enroll Scholar', 'users', '/GRANTED/admin/enroll_scholar.php', ['enroll_scholar.php']],
        ['Enter GWA', 'pencil', '/GRANTED/admin/enter_gwa.php', ['enter_gwa.php', 'import_gwa.php']],
        ['Document Review', 'file-text', '/GRANTED/admin/document_review.php', ['document_review.php']],
    ]
    : [
        ['My Status', 'layout-list', '/GRANTED/scholar/dashboard.php', ['dashboard.php']],
        ['Upload Documents', 'file-text', '/GRANTED/scholar/upload_document.php', ['upload_document.php']],
        ['Notifications', 'bell', '/GRANTED/scholar/notifications.php', ['notifications.php']],
    ];
$dashboardHref = isAdminShell() ? '/GRANTED/admin/dashboard.php' : '/GRANTED/scholar/dashboard.php';
$activePage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
?>
<div class="portal-shell <?php echo isAdminShell() ? 'portal-shell--admin' : 'portal-shell--scholar'; ?>">
    <header class="portal-topbar">
        <button type="button" class="nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="site-nav">Menu</button>
        <a class="portal-topbar__brand" href="<?php echo $dashboardHref; ?>"><img class="portal-brand__logo" src="/GRANTED/assets/icons/LOGO.png" alt="">GRANTED</a>
        <?php include __DIR__ . '/account_menu.php'; ?>
    </header>
    <aside class="portal-sidebar" id="site-nav">
        <div class="portal-sidebar__brand">
            <a class="portal-sidebar__identity" href="<?php echo $dashboardHref; ?>">
                <img class="portal-brand__logo" src="/GRANTED/assets/icons/LOGO.png" alt="">
                <p class="portal-sidebar__name">GRANTED</p>
            </a>
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
<?php if (defined('DEMO_MODE') && DEMO_MODE && isLoggedIn()): ?>
<div class="demo-banner" role="status">Demo data — synthetic accounts and documents only. Not official University records.</div>
<?php endif; ?>
<main class="site-main">
<?php if (isLoggedIn() && (isAdminShell() || isScholarShell())): ?>
<p class="term-chip" title="Configured evaluation term"><span class="term-chip__label">Term</span> <?php echo h(currentTermLabel()); ?></p>
<?php endif; ?>