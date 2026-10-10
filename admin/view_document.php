<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$stmt = $pdo->prepare('SELECT filepath FROM documents WHERE id = ?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$storedName = $stmt->fetchColumn();

$mimeTypes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$storedName = $storedName !== false ? basename($storedName) : '';
$path = __DIR__ . '/../uploads/documents/' . $storedName;
$ext = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));

if ($storedName === '' || !isset($mimeTypes[$ext]) || !is_file($path)) {
    http_response_code(404);
    die('File not found.');
}

header('Content-Type: ' . $mimeTypes[$ext]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
