<?php
/**
 * Daily deadline reminders (Phase 4.5). Run from the command line or Windows Task Scheduler:
 *
 *   C:\xampp\php\php.exe C:\xampp\htdocs\GRANTED\cron\send_deadline_reminders.php
 *
 * A scholar gets ONE reminder per document rule when ALL of these are true:
 *   - the rule is an active DOCUMENT rule of the CURRENT term,
 *   - its due date is today or within the next DEADLINE_REMINDER_DAYS days,
 *   - the scholar's latest upload of that document is missing or Rejected
 *     (a Verified or Pending Review upload needs no reminder),
 *   - no reminder was already created for that scholar and rule (a FAILED one is retried).
 *
 * When MAIL_ENABLED is false the reminders are only saved as QUEUED in the `notifications` table.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);   // never runnable from a browser
    exit;
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
require_once __DIR__ . '/../includes/notifications.php';

$pdo = getDbConnection();
$days = max(1, (int)DEADLINE_REMINDER_DAYS);
$today = new DateTimeImmutable('today');
$startDate = $today->format('Y-m-d');
$endDate = $today->modify('+' . $days . ' days')->format('Y-m-d');

$sql = "SELECT s.id AS scholar_id, s.full_name, r.id AS rule_id,
               r.threshold_value AS document_type, r.due_date
        FROM rules r
        JOIN scholars s ON s.scholarship_type_id = r.scholarship_type_id
        LEFT JOIN documents d ON d.id = (
            SELECT d2.id FROM documents d2
            WHERE d2.scholar_id = s.id
              AND d2.document_type = r.threshold_value
              AND d2.school_year = r.school_year
              AND d2.semester = r.semester
            ORDER BY d2.uploaded_at DESC, d2.id DESC LIMIT 1
        )
        WHERE r.is_active = 1
          AND r.rule_type = 'DOCUMENT'
          AND r.school_year = :school_year
          AND r.semester = :semester
          AND r.due_date BETWEEN :start_date AND :end_date
          AND (d.id IS NULL OR d.status = 'Rejected')
          AND NOT EXISTS (
              SELECT 1 FROM notifications n
              WHERE n.scholar_id = s.id
                AND n.rule_id = r.id
                AND n.type = 'DEADLINE_APPROACHING'
                AND n.send_status <> 'FAILED'
          )
        ORDER BY r.due_date, s.id";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':school_year' => CURRENT_SCHOOL_YEAR,
    ':semester' => CURRENT_SEMESTER,
    ':start_date' => $startDate,
    ':end_date' => $endDate,
]);
$rows = $stmt->fetchAll();

$queued = 0;
$sent = 0;
$failed = 0;

foreach ($rows as $row) {
    [$subject, $body, $html] = buildDeadlineReminderEmail($row['full_name'], $row['document_type'], $row['due_date']);
    try {
        $id = logNotification($pdo, [
            'scholar_id' => $row['scholar_id'],
            'type' => 'DEADLINE_APPROACHING',
            'subject' => $subject,
            'message' => $body,
            'rule_id' => $row['rule_id'],
        ]);
        if (!MAIL_ENABLED) {
            $queued++;
        } elseif (sendNotificationEmail($pdo, $id, null, $html)) {
            $sent++;
        } else {
            $failed++;
        }
    } catch (Throwable $e) {
        $failed++;
        error_log('GRANTED deadline reminder could not be created: ' . $e->getMessage());
    }
}

echo "Deadline reminders ({$startDate} to {$endDate}): {$sent} sent, {$queued} queued, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
