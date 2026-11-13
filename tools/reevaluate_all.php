<?php
/**
 * Re-run the rule engine for every active assignment.
 * Email is never sent from this tool (notifications are suppressed).
 * Use it after you change CURRENT_SCHOOL_YEAR / CURRENT_SEMESTER in config/app.php, so statuses
 * match the new term straight away instead of waiting for each scholar's next GWA or document change.
 *
 *   C:\xampp81\php\php.exe tools\reevaluate_all.php
 *
 * Run it EVERY DAY too, before the deadline reminders (for example 07:55, with the reminders at 08:00).
 * A missed deadline only turns a DOCUMENT rule into FAIL when the engine runs, and nothing else runs
 * it when a date simply passes. Set it up in Task Scheduler like the reminders (docs/GMAIL_API_SETUP.md, C6).
 * It only runs from the command line; a browser request gets a 404.
 *
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
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rule_engine.php';
require_once __DIR__ . '/../includes/notifications.php';

$pdo = getDbConnection();
$assignments = $pdo->query(
    'SELECT id, scholar_id, status FROM scholarship_assignments
     WHERE is_active = 1 AND ended_at IS NULL ORDER BY id'
)->fetchAll();

$changed = 0;
foreach ($assignments as $assignment) {
    refreshScholarMetrics($pdo, (int)$assignment['scholar_id']);
    $newStatus = evaluateAssignment($pdo, (int)$assignment['id'], false);
    if ($newStatus !== $assignment['status']) {
        $changed++;
    }
}

echo 'Term ' . CURRENT_SCHOOL_YEAR . ' ' . CURRENT_SEMESTER . ': re-evaluated ' . count($assignments)
   . " assignments, {$changed} changed status. No emails sent.\n";
