<?php
define('SESSION_IDLE_TIMEOUT', 30 * 60);   // seconds without a request before the user is logged out
ini_set('session.gc_maxlifetime', (string)SESSION_IDLE_TIMEOUT);
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
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

if (isLoggedIn()) {
    if (time() - ($_SESSION['last_activity'] ?? time()) > SESSION_IDLE_TIMEOUT) {
        session_unset();
        session_destroy();
        redirectTo('/GRANTED/auth/login.php?timeout=1');
    }
    $_SESSION['last_activity'] = time();
}
