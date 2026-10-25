<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';
redirectTo('/GRANTED/admin/dashboard.php');
