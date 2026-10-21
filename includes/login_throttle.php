<?php
/**
 * Simple login throttle (Phase 7). Failed logins go into `login_attempts`. Too many from one IP for
 * one email (or three times that many from one IP) pause logins for LOGIN_WINDOW_MINUTES.
 * It is keyed on IP + email, never email alone, so a stranger cannot lock the admin out from elsewhere.
 * If the table is missing or the database errors, the throttle steps aside and logs the problem.
 */

/** Behind the Cloudflare tunnel every request arrives from 127.0.0.1, so trust CF-Connecting-IP only in that case. */
function loginClientIp(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (in_array($remote, ['127.0.0.1', '::1'], true)) {
        $forwarded = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if (is_string($forwarded) && filter_var($forwarded, FILTER_VALIDATE_IP)) {
            return $forwarded;
        }
    }
    return $remote !== '' ? $remote : 'unknown';
}

function loginAttemptKey(string $email): string
{
    return substr(strtolower($email), 0, 255);
}

function loginThrottleEnabled(): bool
{
    return defined('LOGIN_RATE_LIMIT_ENABLED') && LOGIN_RATE_LIMIT_ENABLED === true;
}

function loginIsThrottled(PDO $pdo, string $email): bool
{
    if (!loginThrottleEnabled()) {
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS ip_failures, COALESCE(SUM(email = ?), 0) AS email_failures
             FROM login_attempts
             WHERE ip = ? AND attempted_at > (NOW() - INTERVAL ' . (int)LOGIN_WINDOW_MINUTES . ' MINUTE)'
        );
        $stmt->execute([loginAttemptKey($email), loginClientIp()]);
        $row = $stmt->fetch();
        return (int)$row['email_failures'] >= LOGIN_MAX_FAILURES
            || (int)$row['ip_failures'] >= LOGIN_MAX_FAILURES * 3;
    } catch (Throwable $e) {
        error_log('GRANTED login throttle check failed: ' . $e->getMessage());
        return false;
    }
}

function loginRecordFailure(PDO $pdo, string $email): void
{
    if (!loginThrottleEnabled()) {
        return;
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO login_attempts (ip, email) VALUES (?, ?)');
        $stmt->execute([loginClientIp(), loginAttemptKey($email)]);
        $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    } catch (Throwable $e) {
        error_log('GRANTED login failure could not be recorded: ' . $e->getMessage());
    }
}

function loginClearFailures(PDO $pdo, string $email): void
{
    if (!loginThrottleEnabled()) {
        return;
    }
    try {
        $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE ip = ? AND email = ?');
        $stmt->execute([loginClientIp(), loginAttemptKey($email)]);
    } catch (Throwable $e) {
        error_log('GRANTED login failures could not be cleared: ' . $e->getMessage());
    }
}