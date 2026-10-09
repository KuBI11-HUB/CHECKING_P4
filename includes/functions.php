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
