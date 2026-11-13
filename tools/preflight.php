<?php
/**
 * Read-only local preflight. Run from CLI only:
 *   C:\xampp81\php\php.exe tools\preflight.php
 *
 * This script performs SELECTs and filesystem checks only.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

function preflightLine(string $level, string $message): void
{
    echo str_pad('[' . $level . ']', 6) . ' ' . $message . "\n";
}

$fail = 0;
$warn = 0;

$term = CURRENT_SEMESTER === 'Summer'
    ? CURRENT_SEMESTER
    : CURRENT_SEMESTER . ' semester';
preflightLine('OK', 'Configured term: ' . CURRENT_SCHOOL_YEAR . ', ' . $term);

$uploadDir = realpath(__DIR__ . '/../uploads/documents');
if ($uploadDir === false || !is_dir($uploadDir)) {
    preflightLine('FAIL', 'uploads/documents directory is missing');
    $fail++;
} elseif (!is_writable($uploadDir)) {
    preflightLine('WARN', 'uploads/documents is not writable (scholar uploads will fail)');
    $warn++;
} else {
    preflightLine('OK', 'uploads/documents exists and is writable');
}

$demoPdf = $uploadDir !== false ? $uploadDir . DIRECTORY_SEPARATOR . 'demo-certificate-of-grades.pdf' : '';
$assetPdf = __DIR__ . '/../assets/demo-certificate-of-grades.pdf';
if ($demoPdf !== '' && is_file($demoPdf)) {
    preflightLine('OK', 'Demo document present in uploads/documents');
} elseif (is_file($assetPdf)) {
    preflightLine('WARN', 'Demo PDF is in assets/ but not uploads/documents/ (see README)');
    $warn++;
} else {
    preflightLine('WARN', 'Demo Certificate of Grades PDF not found (optional unless using demo_seed.sql)');
    $warn++;
}

$requiredTables = [
    'users', 'programs', 'scholarship_types', 'requirements', 'scholars',
    'scholarship_assignments', 'rules', 'documents', 'gwa_records',
    'evaluations', 'renewal_decisions', 'notifications', 'login_attempts',
];

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    preflightLine('OK', 'Database connection succeeded (' . DB_NAME . ')');

    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($db !== 'granted_dev') {
        preflightLine('WARN', 'Connected database is "' . $db . '", expected granted_dev for local demo work');
        $warn++;
    }

    $existing = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);
    $existing = array_map('strval', $existing);
    foreach ($requiredTables as $table) {
        if (in_array($table, $existing, true)) {
            preflightLine('OK', 'Table present: ' . $table);
        } else {
            preflightLine('FAIL', 'Missing table: ' . $table);
            $fail++;
        }
    }

    if (in_array('rules', $existing, true)) {
        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM rules
             WHERE is_active = 1 AND school_year = ? AND semester = ?'
        );
        $countStmt->execute([CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);
        $ruleCount = (int)$countStmt->fetchColumn();
        if ($ruleCount === 0) {
            preflightLine('WARN', 'No active rules for the configured term (statuses will stay PENDING)');
            $warn++;
        } else {
            preflightLine('OK', $ruleCount . ' active rule(s) for the configured term');
        }
    }
} catch (Throwable $e) {
    preflightLine('FAIL', 'Database check failed: ' . $e->getMessage());
    $fail++;
}

if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
    preflightLine('WARN', 'MAIL_ENABLED is true (demo usually keeps mail off)');
    $warn++;
} else {
    preflightLine('OK', 'MAIL_ENABLED is false');
}

if (defined('DEMO_MODE') && DEMO_MODE) {
    preflightLine('OK', 'DEMO_MODE is on (demo banner will show)');
} else {
    preflightLine('OK', 'DEMO_MODE is off');
}

echo "\nSummary: {$fail} FAIL, {$warn} WARN\n";
exit($fail > 0 ? 1 : 0);
