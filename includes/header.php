<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? sanitize($pageTitle) . ' — GRANTED' : 'GRANTED'; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@600;700&family=Public+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/GRANTED/assets/css/style.css">
</head>
<body>
<?php if (isLoggedIn()): ?>
<header class="site-header">
    <div class="site-header__brand">GRANTED</div>
    <nav class="site-header__nav">
        <?php if (currentRole() === 'admin'): ?>
            <a href="/GRANTED/admin/dashboard.php">Dashboard</a>
            <a href="/GRANTED/admin/scholarship_types.php">Scholarship Types</a>
            <a href="/GRANTED/admin/rules.php">Rules</a>
            <a href="/GRANTED/admin/enroll_scholar.php">Enroll Scholar</a>
            <a href="/GRANTED/admin/enter_grades.php">Enter Grades</a>
            <a href="/GRANTED/admin/document_review.php">Document Review</a>
        <?php else: ?>
            <a href="/GRANTED/scholar/dashboard.php">My Status</a>
            <a href="/GRANTED/scholar/upload_document.php">Upload Documents</a>
        <?php endif; ?>
        <span class="site-header__role"><?php echo sanitize(currentRole()); ?></span>
        <a href="/GRANTED/auth/logout.php">Log out</a>
    </nav>
</header>
<?php endif; ?>
<main class="site-main">
