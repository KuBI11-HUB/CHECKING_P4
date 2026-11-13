<?php
require_once __DIR__ . '/includes/init.php';
if (isLoggedIn()) {
    redirectTo(currentRole() === 'admin' ? '/GRANTED/admin/dashboard.php' : '/GRANTED/scholar/dashboard.php');
} else {
    redirectTo('/GRANTED/auth/login.php');
}
