<?php
/**
 * Re-run the rule engine for EVERY scholar.
 * Use it after you change CURRENT_SCHOOL_YEAR / CURRENT_SEMESTER in config/app.php, so statuses
 * match the new term straight away instead of waiting for each scholar's next grade or document change.
 *
 *   C:\xampp\php\php.exe tools\reevaluate_all.php
 *
 * Note: if MAIL_ENABLED is true, scholars whose status changes will be emailed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
require_once __DIR__ . '/../includes/rule_engine.php';
require_once __DIR__ . '/../includes/notifications.php';

$pdo = getDbConnection();
$scholars = $pdo->query('SELECT id, current_status FROM scholars ORDER BY id')->fetchAll();

$changed = 0;
foreach ($scholars as $scholar) {
    $newStatus = evaluateScholar($pdo, (int)$scholar['id']);
    if ($newStatus !== $scholar['current_status']) {
        $changed++;
    }
}

echo 'Term ' . CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER . ': re-evaluated ' . count($scholars)
   . " scholars, {$changed} changed status.\n";
