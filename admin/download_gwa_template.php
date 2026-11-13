<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="granted-gwa-template.csv"');
echo "\xEF\xBB\xBF";
echo "email,school_year,semester,gwa,total_units\r\n";
