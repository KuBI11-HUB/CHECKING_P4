<?php
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/error_page.php';

if (!isLoggedIn()) {
    redirectTo('/GRANTED/auth/login.php');
}

if (isset($requiredRole) && currentRole() !== $requiredRole) {
    renderErrorPage(403);
}
