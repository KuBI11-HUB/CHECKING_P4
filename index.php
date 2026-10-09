<?php
require_once __DIR__ . '/includes/init.php';
if (isLoggedIn()) {
    redirectTo(currentRole() === 'admin' ? 'admin/dashboard.php' : 'scholar/dashboard.php');
} else {
    redirectTo('auth/login.php');
}
