<?php
require_once __DIR__ . '/init.php';

if (!isLoggedIn()) {
    redirectTo('/GRANTED/auth/login.php');
}

if (isset($requiredRole) && currentRole() !== $requiredRole) {
    http_response_code(403);
    die("You don't have access to this page.");
}
