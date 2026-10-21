<?php
// Per-computer overrides live in config/app.local.php (git-ignored). Load it FIRST so it can
// override any default below, for example MAIL_ENABLED, or CURRENT_SEMESTER while testing.
if (is_file(__DIR__ . '/app.local.php')) {
    require __DIR__ . '/app.local.php';
}

// ---- Time zone ------------------------------------------------------------------------
// Dates computed in PHP (for example "is this deadline within 7 days from today?") use this zone,
// whatever C:\xampp\php\php.ini says.
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set(APP_TIMEZONE);

// ---- Current term (Phase 4.1) --------------------------------------------------
// Rules, GPA, units and documents are all evaluated for THIS term only.
// Change these two lines when a new semester starts.
defined('CURRENT_SCHOOL_YEAR') || define('CURRENT_SCHOOL_YEAR', '2026-2027');
defined('CURRENT_SEMESTER')    || define('CURRENT_SEMESTER', '1st');          // '1st' | '2nd' | 'Summer'

// Email sign-in links. Update the public base URL whenever a quick Cloudflare tunnel URL changes.
defined('GRANTED_LOCAL_BASE_URL')  || define('GRANTED_LOCAL_BASE_URL', 'http://localhost/GRANTED/index.php');
defined('GRANTED_PUBLIC_BASE_URL') || define('GRANTED_PUBLIC_BASE_URL', '');

// ---- Deadline reminders (Phase 4.5) ---------------------------------------------
defined('DEADLINE_REMINDER_DAYS') || define('DEADLINE_REMINDER_DAYS', 7);     // remind this many days ahead

// ---- Mail (Phase 4.4) -------------------------------------------------------------
defined('MAIL_ENABLED')           || define('MAIL_ENABLED', false);           // true only where Gmail is set up    
defined('MAIL_FROM_EMAIL')        || define('MAIL_FROM_EMAIL', 'dummygranted5@gmail.com');
defined('MAIL_FROM_NAME')         || define('MAIL_FROM_NAME', 'GRANTED Scholarship Office');
defined('GMAIL_CREDENTIALS_PATH') || define('GMAIL_CREDENTIALS_PATH', __DIR__ . '/gmail_credentials.json');
defined('GMAIL_TOKEN_PATH')       || define('GMAIL_TOKEN_PATH', __DIR__ . '/gmail_token.json');
defined('GMAIL_REDIRECT_URI')     || define('GMAIL_REDIRECT_URI', 'http://localhost/GRANTED/tools/gmail_authorize.php');

// ---- Login rate limit (Phase 7) ---------------------------------------------------
defined('LOGIN_RATE_LIMIT_ENABLED') || define('LOGIN_RATE_LIMIT_ENABLED', true);
defined('LOGIN_MAX_FAILURES')       || define('LOGIN_MAX_FAILURES', 5);    // per email from one IP
defined('LOGIN_WINDOW_MINUTES')     || define('LOGIN_WINDOW_MINUTES', 15);

defined('GRANTED_PRIVACY_URL') || define('GRANTED_PRIVACY_URL', '');