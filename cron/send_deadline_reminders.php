<?php
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
require_once __DIR__ . '/../includes/notifications.php';

$pdo = getDbConnection();
$days = max(1, (int)DEADLINE_REMINDER_DAYS);
$today = new DateTimeImmutable('today');
$startDate = $today->format('Y-m-d');
$endDate = $today->modify('+' . $days . ' days')->format('Y-m-d');

$sql = "SELECT a.id AS assignment_id, s.id AS scholar_id, s.fname, s.mname, s.lname, s.suffix,
               r.id AS rule_id, COALESCE(req.name, r.threshold_value) AS document_type, r.due_date
        FROM rules r
        JOIN scholarship_assignments a ON a.scholarship_type_id = r.scholarship_type_id
          AND a.is_active = 1 AND a.ended_at IS NULL
        JOIN scholars s ON s.id = a.scholar_id
        LEFT JOIN requirements req ON req.id = r.requirement_id
        LEFT JOIN documents d ON d.id = (
            SELECT d2.id FROM documents d2
            WHERE d2.scholar_id = s.id
              AND ((r.requirement_id IS NOT NULL AND d2.requirement_id = r.requirement_id)
                   OR (r.requirement_id IS NULL AND d2.requirement_id IS NULL
                       AND BINARY d2.document_type = BINARY r.threshold_value))
              AND (COALESCE(req.is_recurring, 1) = 0
                   OR (d2.school_year = r.school_year AND d2.semester = r.semester))
            ORDER BY d2.uploaded_at DESC, d2.id DESC LIMIT 1
        )
        WHERE r.is_active = 1 AND r.rule_type = 'DOCUMENT'
          AND r.school_year = :school_year AND r.semester = :semester
          AND r.due_date BETWEEN :start_date AND :end_date
          AND (d.id IS NULL OR d.status = 'Rejected')
          AND NOT EXISTS (
              SELECT 1 FROM notifications n
              WHERE n.assignment_id = a.id AND n.rule_id = r.id
                AND n.type = 'DEADLINE_APPROACHING' AND n.send_status <> 'FAILED'
          )
        ORDER BY r.due_date, s.id, a.id";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':school_year' => CURRENT_SCHOOL_YEAR,
    ':semester' => CURRENT_SEMESTER,
    ':start_date' => $startDate,
    ':end_date' => $endDate,
]);

$queued = 0;
$sent = 0;
$failed = 0;
foreach ($stmt->fetchAll() as $row) {
    [$subject, $body, $html] = buildDeadlineReminderEmail(
        formatScholarName($row, 'account'),
        (string)$row['document_type'],
        (string)$row['due_date']
    );
    try {
        $id = logNotification($pdo, [
            'scholar_id' => (int)$row['scholar_id'],
            'assignment_id' => (int)$row['assignment_id'],
            'type' => 'DEADLINE_APPROACHING',
            'subject' => $subject,
            'message' => $body,
            'rule_id' => (int)$row['rule_id'],
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
