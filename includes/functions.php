<?php

function redirectTo(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function sanitize(?string $value): string
{
    return htmlspecialchars(trim($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function currentRole(): ?string
{
    return $_SESSION['role'] ?? null;
}

function statusBadgeClass(?string $status): string
{
    switch ($status) {
        case 'PASS': return 'status-badge--pass';
        case 'FAIL': return 'status-badge--fail';
        default: return 'status-badge--pending';
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrfToken(), $sent)) {
        http_response_code(403);
        die('Your form expired or is invalid. Please go back, refresh the page and try again.');
    }
}

function dueDateHtml(?string $dueDate, string $status): string
{
    if (!$dueDate) {
        return '';
    }
    $html = 'Due ' . sanitize($dueDate);

    // A document that is already uploaded (or verified) is no longer "late" on the scholar's side.
    if (!in_array($status, ['Verified', 'Pending Review'], true)) {
        $days = (int)(new DateTimeImmutable('today'))->diff(new DateTimeImmutable($dueDate))->format('%r%a');
        if ($days < 0) {
            $html .= ' — <strong style="color: var(--color-fail);">OVERDUE</strong>';
        } elseif ($days === 0) {
            $html .= ' — due today';
        } else {
            $html .= ' — due in ' . $days . ($days === 1 ? ' day' : ' days');
        }
    }
    return '<br><small>' . $html . '</small>';
}
