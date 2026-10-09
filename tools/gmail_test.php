<?php
/**
 * Command-line test: sends one real email through the Gmail API.
 * Usage:  C:\xampp\php\php.exe tools\gmail_test.php someone@example.com
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/gmail_mailer.php';

$to = $argv[1] ?? '';
if ($to === '') {
    exit("Usage: php tools/gmail_test.php recipient@example.com\n");
}
try {
    $id = sendGmail($to, 'GRANTED test email', "If you can read this, the Gmail API is set up correctly.\n\nSent at " . date('Y-m-d H:i:s') . "\n");
    echo "Sent. Gmail message id: {$id}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
