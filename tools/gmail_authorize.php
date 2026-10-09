<?php
/**
 * ONE-TIME SETUP PAGE — links the sender Gmail account to GRANTED. Admin only.
 * Open it in the browser: http://localhost/GRANTED/tools/gmail_authorize.php
 * On success it saves config/gmail_token.json (git-ignored; never commit it).
 */
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/app.php';

function authorizePage(string $title, string $message, int $status = 200): void
{
    http_response_code($status);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title></head>'
       . '<body style="font-family:sans-serif;max-width:640px;margin:40px auto">'
       . '<h2>' . htmlspecialchars($title) . '</h2><p>' . $message . '</p>'
       . '<p><a href="/GRANTED/admin/dashboard.php">Back to the admin dashboard</a></p></body></html>';
    exit;
}

if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    authorizePage('Google library missing', 'Run <code>composer install</code> in the project folder, then reload this page.', 500);
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/gmail_mailer.php';

if (!is_file(GMAIL_CREDENTIALS_PATH)) {
    authorizePage('Credentials file missing', 'Put the JSON file you downloaded from Google Cloud at <code>config/gmail_credentials.json</code>, then reload.', 500);
}

$client = gmailClient();
$client->setRedirectUri(GMAIL_REDIRECT_URI);

// The user pressed "Cancel" or Google refused the sign-in.
if (isset($_GET['error'])) {
    authorizePage('Authorization was not completed', 'Google returned: <code>' . htmlspecialchars((string)$_GET['error']) . '</code>. '
        . 'If it says <code>access_denied</code>, make sure the app is published ("In production") or the sender account is a test user.', 400);
}

// Google sent the user back with a one-time code.
if (isset($_GET['code'])) {
    $expected = (string)($_SESSION['gmail_oauth_state'] ?? '');
    unset($_SESSION['gmail_oauth_state']);
    if ($expected === '' || !isset($_GET['state']) || !hash_equals($expected, (string)$_GET['state'])) {
        authorizePage('Security check failed', 'This sign-in could not be verified. Please <a href="/GRANTED/tools/gmail_authorize.php">start again</a>.', 400);
    }

    $token = $client->fetchAccessTokenWithAuthCode((string)$_GET['code']);
    if (isset($token['error'])) {
        authorizePage('Google rejected the code', htmlspecialchars((string)($token['error_description'] ?? $token['error'])), 400);
    }
    if (empty($token['refresh_token'])) {
        authorizePage('No refresh token received',
            'Google did not return a refresh token. Remove GRANTED at <code>https://myaccount.google.com/permissions</code> '
            . '(signed in as the sender account), then <a href="/GRANTED/tools/gmail_authorize.php">authorize again</a>.', 400);
    }

    file_put_contents(GMAIL_TOKEN_PATH, json_encode($token, JSON_PRETTY_PRINT), LOCK_EX);
    authorizePage('Gmail is connected', 'The token was saved to <code>config/gmail_token.json</code>. '
        . 'Now test it: <code>C:\xampp\php\php.exe tools\gmail_test.php your.email@example.com</code>');
}

// First visit: send the browser to Google's sign-in page.
$state = bin2hex(random_bytes(16));
$_SESSION['gmail_oauth_state'] = $state;
$client->setState($state);
header('Location: ' . $client->createAuthUrl());
exit;
