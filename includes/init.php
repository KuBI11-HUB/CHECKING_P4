<?php
define('SESSION_IDLE_TIMEOUT', 30 * 60);   // seconds without a request before the user is logged out
ini_set('session.gc_maxlifetime', (string)SESSION_IDLE_TIMEOUT);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

// Behind the Cloudflare tunnel PHP itself sees plain HTTP, so the visitor's real scheme comes from the forwarded headers.
$onHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    || strpos((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"scheme":"https"') !== false;
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $onHttps]);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

require_once __DIR__ . '/../config/app.php';        // term, mail and Gmail settings (must load before the engine runs)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/rule_engine.php';

// The Google library is only installed on computers that ran `composer install`.
// Everything except sending real emails works without it.
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/results.php';

if (isLoggedIn()) {
    if (time() - ($_SESSION['last_activity'] ?? time()) > SESSION_IDLE_TIMEOUT) {
        session_unset();
        session_destroy();
        redirectTo('/GRANTED/auth/login.php?timeout=1');
    }
    $_SESSION['last_activity'] = time();
}
