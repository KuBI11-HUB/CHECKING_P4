<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('/GRANTED/admin/dashboard.php');
}
verifyCsrf();

$pdo = getDbConnection();
$gradeId = (int)($_POST['grade_id'] ?? 0);

$stmt = $pdo->prepare('SELECT scholar_id, subject FROM grades WHERE id = ?');
$stmt->execute([$gradeId]);
$grade = $stmt->fetch();

if (!$grade) {
    http_response_code(404);
    die('Grade not found. It may already have been deleted.');
}

$scholarId = (int)$grade['scholar_id'];

$stmt = $pdo->prepare('DELETE FROM grades WHERE id = ? AND scholar_id = ?');
$stmt->execute([$gradeId, $scholarId]);

// Recalculates GPA/units (back to empty if this was the last current-term grade), then re-runs the rules.
$status = evaluateScholar($pdo, $scholarId);

setFlash('Grade deleted: ' . plainText($grade['subject']) . '. Recalculated status: ' . ($status ?? 'PENDING') . '.');
redirectTo('/GRANTED/admin/scholar_detail.php?id=' . $scholarId);