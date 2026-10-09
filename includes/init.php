<?php
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
