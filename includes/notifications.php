<?php
/**
 * Phase 4 notification helpers. Every notification is saved in the `notifications` table first,
 * then emailed (when MAIL_ENABLED is true). Mail delivery is best effort and never blocks
 * a grade save, document review, or status recalculation.
 */
require_once __DIR__ . '/gmail_mailer.php';

function notificationTextLimit(string $value, int $limit): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
}

/** Names and document names are stored HTML-escaped until the Phase 7 fix (O'Brien -> O&#039;Brien); undo that for emails. */
function notificationPlainText(string $value): string
{
    return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
}

function notificationStatusBadge(string $status): string
{
    $colors = [
        'PASS' => ['#2E6F58', '#E4F0EA'],
        'PENDING' => ['#8A5E0B', '#FBF1E1'],
        'FAIL' => ['#B3492B', '#FBEAE4'],
    ];
    [$textColor, $backgroundColor] = $colors[$status] ?? ['#5C5C5C', '#F7F7F7'];
    $safeStatus = htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<span style="display: inline-block; padding: 6px 12px; border-radius: 16px; background: ' . $backgroundColor
        . '; color: ' . $textColor . '; font-size: 13px; font-weight: bold;">' . $safeStatus . '</span>';
}

function notificationLoginDestinations(): array
{
    $bases = ['This computer' => GRANTED_LOCAL_BASE_URL];
    if (GRANTED_PUBLIC_BASE_URL !== '') {
        $bases['Cloudflare'] = GRANTED_PUBLIC_BASE_URL;
    }

    $destinations = [];
    foreach ($bases as $label => $baseUrl) {
        $parts = parse_url($baseUrl);
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Invalid GRANTED sign-in base URL for ' . $label . '.');
        }

        $path = rtrim($parts['path'] ?? '', '/');
        $path = preg_replace('~/(?:index\.php|auth/login\.php)$~i', '', $path);
        $host = (string)$parts['host'];
        if (strpos($host, ':') !== false && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }
        $authority = $parts['scheme'] . '://' . $host
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $destinations[$label] = $authority . rtrim((string)$path, '/') . '/auth/login.php';
    }

    return $destinations;
}

function notificationLoginText(): string
{
    $links = [];
    foreach (notificationLoginDestinations() as $label => $url) {
        $links[] = 'Sign in (' . $label . '): ' . $url;
    }
    return implode("\n", $links);
}

function notificationLoginButtons(): string
{
    $buttons = [];
    foreach (notificationLoginDestinations() as $label => $url) {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $buttons[] = '<a href="' . $safeUrl . '" style="display:inline-block;margin:0 8px 8px 0;padding:12px 18px;background:#680700;color:#ffffff;text-decoration:none;font-size:14px;font-weight:bold;border-radius:4px;">Sign in to GRANTED (' . $safeLabel . ')</a>';
    }
    return '<p style="margin:24px 0 10px;font-size:14px;font-weight:bold;color:#171717;">Sign in to continue:</p>'
        . '<p style="margin:0;">' . implode('', $buttons) . '</p>';
}

function notificationHtml(string $category, string $title, string $name, string $content): string
{
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCategory = htmlspecialchars($category, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<!doctype html><html lang="en"><head>'
        . '<meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '</head>'
        . '<body style="margin:0;padding:24px 12px;background:#F7F7F7;font-family:Arial,Helvetica,sans-serif;color:#171717;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;margin:0 auto;background:#FFFFFF;border:1px solid #E5E5E5;border-collapse:collapse;">'
        . '<tr><td style="padding:24px 28px;background:#680700;color:#FFFFFF;border-bottom:4px solid #C79900;">'
        . '<div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#FFFFFF;">University of Luzon</div>'
        . '<div style="margin-top:5px;font-size:24px;font-weight:bold;line-height:1.25;">GRANTED</div></td></tr>'
        . '<tr><td style="padding:28px;">'
        . '<div style="font-size:12px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#680700;">' . $safeCategory . '</div>'
        . '<h1 style="margin:8px 0 18px;font-size:24px;line-height:1.3;color:#171717;">' . $safeTitle . '</h1>'
        . '<p style="margin:0 0 20px;font-size:16px;line-height:1.6;">Hello ' . $safeName . ',</p>'
        . $content
        . '<p style="margin:24px 0 0;font-size:14px;line-height:1.6;color:#171717;">Sign in to GRANTED to review your scholarship information and next steps.</p>'
        . notificationLoginButtons() . '</td></tr>'
        . '<tr><td style="padding:18px 28px;background:#F7F7F7;border-top:1px solid #E5E5E5;font-size:12px;line-height:1.6;color:#5C5C5C;">'
        . 'GRANTED Scholarship Office<br>University of Luzon</td></tr></table></body></html>';
}

function buildStatusChangeEmail(string $fullName, string $oldStatus, string $newStatus): array
{
    $name = notificationPlainText($fullName);
    return [
        'GRANTED scholarship status update',
        "Hello {$name},\n\nYour scholarship status has changed from {$oldStatus} to {$newStatus}.\n\nPlease sign in to GRANTED to view your current requirements.\n\n" . notificationLoginText() . "\n\nGRANTED Scholarship Office",
        notificationHtml(
            'Status change',
            'Your status has changed',
            $name,
            '<p style="margin:0 0 18px;font-size:15px;line-height:1.6;">Your calculated scholarship status has been updated. Compare the latest result below:</p>'
                . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">'
                . '<tr><td style="padding:12px 14px;background:#F7F7F7;border-bottom:1px solid #E5E5E5;color:#5C5C5C;">Previous status</td>'
                . '<td style="padding:12px 14px;background:#F7F7F7;border-bottom:1px solid #E5E5E5;text-align:right;">' . notificationStatusBadge($oldStatus) . '</td></tr>'
                . '<tr><td style="padding:14px;background:#FFFFFF;border-left:3px solid #680700;color:#171717;font-weight:bold;">Current status</td>'
                . '<td style="padding:14px;background:#FFFFFF;border-right:1px solid #E5E5E5;text-align:right;">' . notificationStatusBadge($newStatus) . '</td></tr></table>'
        ),
    ];
}

function buildDeadlineReminderEmail(string $fullName, string $documentType, string $dueDate): array
{
    $name = notificationPlainText($fullName);
    $document = notificationPlainText($documentType);
    $due = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
    $validDueDate = $due !== false && $due->format('Y-m-d') === $dueDate;
    $formattedDate = $validDueDate ? $due->format('F j, Y') : $dueDate;
    $daysRemaining = $validDueDate
        ? (int)(new DateTimeImmutable('today'))->diff($due)->format('%r%a')
        : null;
    $timing = $daysRemaining === null
        ? 'Deadline reminder'
        : ($daysRemaining < 0
            ? 'Past due'
            : ($daysRemaining === 0 ? 'Due today' : 'Due in ' . $daysRemaining . ' day' . ($daysRemaining === 1 ? '' : 's')));
    $subject = $daysRemaining === 0
        ? 'GRANTED document due today'
        : ($daysRemaining !== null && $daysRemaining > 0
            ? 'GRANTED document due in ' . $daysRemaining . ' day' . ($daysRemaining === 1 ? '' : 's')
            : 'GRANTED document deadline reminder');
    $safeDocument = htmlspecialchars($document, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeDate = htmlspecialchars($formattedDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeTiming = htmlspecialchars($timing, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return [
        $subject,
        "Hello {$name},\n\n{$timing}: {$document} is due by {$formattedDate} ({$dueDate}). Please upload it in GRANTED before the deadline.\n\n" . notificationLoginText() . "\n\nGRANTED Scholarship Office",
        notificationHtml(
            'Deadline reminder',
            $daysRemaining === 0 ? 'A document is due today' : 'A document deadline is approaching',
            $name,
            '<p style="margin:0 0 18px;font-size:15px;line-height:1.6;">Please upload this required document before its deadline:</p>'
                . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">'
                . '<tr><td style="padding:16px;background:#F7F7F7;border-left:4px solid #C79900;">'
                . '<div style="font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:1px;color:#5C5C5C;">Required document</div>'
                . '<div style="margin-top:6px;font-size:18px;font-weight:bold;line-height:1.4;color:#171717;">' . $safeDocument . '</div>'
                . '<div style="margin-top:14px;padding-top:12px;border-top:1px solid #E5E5E5;font-size:14px;color:#5C5C5C;">'
                . '<span style="font-weight:bold;color:#171717;">' . $safeTiming . '</span>'
                . '<span style="display:block;margin-top:4px;">Deadline: <strong style="color:#680700;">' . $safeDate . '</strong></span>'
                . '</div></td></tr></table>'
        ),
    ];
}

/** Store a queued notification and return its database ID. */
function logNotification(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO notifications (scholar_id, type, channel, subject, message, old_status, new_status, rule_id, send_status)
         VALUES (:scholar_id, :type, 'EMAIL', :subject, :message, :old_status, :new_status, :rule_id, 'QUEUED')"
    );
    $stmt->execute([
        ':scholar_id' => (int)$data['scholar_id'],
        ':type' => $data['type'],
        ':subject' => notificationTextLimit((string)$data['subject'], 200),
        ':message' => (string)$data['message'],
        ':old_status' => $data['old_status'] ?? null,
        ':new_status' => $data['new_status'] ?? null,
        ':rule_id' => isset($data['rule_id']) ? (int)$data['rule_id'] : null,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Send one queued notification. Disabled mail stays QUEUED; any delivery or
 * setup error becomes FAILED and is swallowed so the caller can continue.
 * Returns true only when the email was sent. $sender exists so tests can replace the real Gmail call.
 */
function sendNotificationEmail(PDO $pdo, int $notificationId, ?callable $sender = null, ?string $bodyHtml = null): bool
{
    if (!defined('MAIL_ENABLED') || MAIL_ENABLED !== true) {
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT n.subject, n.message, n.send_status, u.email
             FROM notifications n
             JOIN scholars s ON s.id = n.scholar_id
             JOIN users u ON u.id = s.user_id
             WHERE n.id = ?'
        );
        $stmt->execute([$notificationId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Notification or scholar email was not found.');
        }
        if ($row['send_status'] === 'SENT') {
            return true;
        }

        if ($sender === null) {
            sendGmail($row['email'], $row['subject'], $row['message'], $bodyHtml);
        } else {
            $sender($row['email'], $row['subject'], $row['message']);
        }

        $stmt = $pdo->prepare("UPDATE notifications SET send_status = 'SENT', error_message = NULL, sent_at = NOW() WHERE id = ?");
        $stmt->execute([$notificationId]);
        return true;
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare("UPDATE notifications SET send_status = 'FAILED', error_message = ?, sent_at = NULL WHERE id = ?");
            $stmt->execute([notificationTextLimit($e->getMessage(), 255), $notificationId]);
        } catch (Throwable $recordingError) {
            error_log('GRANTED notification failure could not be recorded: ' . $recordingError->getMessage());
        }
        error_log('GRANTED email notification failed: ' . $e->getMessage());
        return false;
    }
}

/** Called from evaluateScholar() after the new status is saved. Does nothing if the status did not change. */
function notifyStatusChange(PDO $pdo, int $scholarId, ?string $oldStatus, string $newStatus, ?callable $sender = null): void
{
    if ($oldStatus === $newStatus) {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT full_name FROM scholars WHERE id = ?');
        $stmt->execute([$scholarId]);
        $fullName = $stmt->fetchColumn();
        if ($fullName === false) {
            return;
        }

        [$subject, $body, $html] = buildStatusChangeEmail((string)$fullName, (string)$oldStatus, $newStatus);

        $id = logNotification($pdo, [
            'scholar_id' => $scholarId,
            'type' => 'STATUS_CHANGE',
            'subject' => $subject,
            'message' => $body,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);
        sendNotificationEmail($pdo, $id, $sender, $html);
    } catch (Throwable $e) {
        error_log('GRANTED status notification could not be created: ' . $e->getMessage());
    }
}
