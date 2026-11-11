<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="granted-grades-template.csv"');

echo "email,subject,grade,units,school_year,semester\r\n";
echo "scholar@example.com,CS 101,1.75,3,2026-2027,1st\r\n";
