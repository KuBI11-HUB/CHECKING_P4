<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

const TEMP_PASSWORD_MIN_LENGTH = 8;
const TEMP_PASSWORD_MAX_BYTES = 72;   // bcrypt ignores anything past 72 bytes, so longer values are refused

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('/GRANTED/admin/dashboard.php');
}
verifyCsrf();

$pdo = getDbConnection();
$scholarId = (int)(is_string($_POST['scholar_id'] ?? null) ? $_POST['scholar_id'] : 0);
$detailUrl = '/GRANTED/admin/scholar_detail.php?id=' . $scholarId;

// Only an account that belongs to a scholar can be changed here; admin accounts are never touched.
$stmt = $pdo->prepare(
    "SELECT s.id, s.full_name, u.id AS user_id
     FROM scholars s
     JOIN users u ON u.id = s.user_id
     WHERE s.id = ? AND u.role = 'scholar'"
);
$stmt->execute([$scholarId]);
$target = $stmt->fetch();

if (!$target) {
    http_response_code(404);
    die('Scholar not found.');
}

// Used exactly as typed (no trim, no escaping), the same way the login page reads passwords.
$new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
$confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
$length = function_exists('mb_strlen') ? mb_strlen($new, 'UTF-8') : strlen($new);

if ($new === '' || $confirm === '') {
    setFlash('Not saved: enter the temporary password twice.');
} elseif ($length < TEMP_PASSWORD_MIN_LENGTH) {
    setFlash('Not saved: the temporary password must be at least ' . TEMP_PASSWORD_MIN_LENGTH . ' characters.');
} elseif (strlen($new) > TEMP_PASSWORD_MAX_BYTES) {
    setFlash('Not saved: the temporary password is too long (' . TEMP_PASSWORD_MAX_BYTES . ' characters at most).');
} elseif ($new !== $confirm) {
    setFlash('Not saved: the two passwords do not match.');
} else {
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([password_hash($new, PASSWORD_DEFAULT), (int)$target['user_id']]);

    // Who did it and for whom. The password itself is never logged.
    error_log('GRANTED: admin ' . ($_SESSION['email'] ?? 'unknown') . ' set a temporary password for scholar #' . $scholarId . '.');

    setFlash('Temporary password set for ' . plainText($target['full_name'])
        . '. Ask them to change it right away from the account menu (Change password).');
}

redirectTo($detailUrl);