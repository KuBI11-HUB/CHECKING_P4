<?php

function redirectTo(string $path): void
{
    header('Location: ' . $path);
    exit;
}
/** Input cleaning for SAVING: trim only. Since Phase 7 text is stored raw and escaped when printed (h()). */
function clean(?string $value): string
{
    return trim($value ?? '');
}
// Output escaping only. Do NOT use it on values you are about to save (use clean()).
function sanitize(?string $value): string
{
    return htmlspecialchars(trim($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatScholarName(array $scholar, string $style = 'table'): string
{
    $fname = trim((string)($scholar['fname'] ?? ''));
    $mname = trim((string)($scholar['mname'] ?? ''));
    $lname = trim((string)($scholar['lname'] ?? ''));
    $suffix = trim((string)($scholar['suffix'] ?? ''));
    $middleInitial = $mname !== ''
        ? (function_exists('mb_substr') ? mb_substr($mname, 0, 1) : substr($mname, 0, 1)) . '.'
        : '';
    $last = trim($lname . ($suffix !== '' ? ' ' . $suffix : ''));

    if ($style === 'account') {
        return trim(implode(' ', array_filter([$fname, $middleInitial, $last], static fn(string $part): bool => $part !== '')));
    }

    $first = trim(implode(' ', array_filter([$fname, $middleInitial], static fn(string $part): bool => $part !== '')));
    return $first !== '' && $last !== '' ? $last . ', ' . $first : ($last !== '' ? $last : $first);
}

function scholarNameSearchSql(string $alias, string $prefix): string
{
    if (!preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*\z/', $alias)
        || !preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*\z/', $prefix)) {
        throw new InvalidArgumentException('Invalid internal SQL alias or parameter prefix.');
    }
    return "({$alias}.fname LIKE :{$prefix}_fname OR {$alias}.mname LIKE :{$prefix}_mname"
        . " OR {$alias}.lname LIKE :{$prefix}_lname OR {$alias}.suffix LIKE :{$prefix}_suffix)";
}

function uiIcon(string $name): string
{
    $icons = [
        'bell' => 'bell.svg',
        'book-text' => 'book-text.svg',
        'circle-alert' => 'circle-alert.svg',
        'circle-check' => 'circle-check-big.svg',
        'file-text' => 'file-text.svg',
        'inbox' => 'inbox.svg',
        'layout-list' => 'layout-list.svg',
        'list-checks' => 'list-checks.svg',
        'log-out' => 'log-out.svg',
        'pencil' => 'pencil.svg',
        'search' => 'search.svg',
        'settings' => 'settings.svg',
        'upload' => 'upload.svg',
        'users' => 'users.svg',
    ];
    if (!isset($icons[$name])) {
        throw new InvalidArgumentException('Unknown UI icon: ' . $name);
    }

    $svg = file_get_contents(__DIR__ . '/../assets/icons/' . $icons[$name]);
    if ($svg === false) {
        throw new RuntimeException('Could not read UI icon: ' . $icons[$name]);
    }

    return str_replace(
        ['<svg ', ' preview-icon"'],
        ['<svg aria-hidden="true" focusable="false" ', ' ui-icon"'],
        $svg
    );
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function currentRole(): ?string
{
    return $_SESSION['role'] ?? null;
}

function isAdminShell(): bool
{
    return isLoggedIn() && currentRole() === 'admin';
}

function isScholarShell(): bool
{
    return isLoggedIn() && currentRole() === 'scholar';
}

function currentTermLabel(): string
{
    $sem = CURRENT_SEMESTER;
    $semLabel = $sem === 'Summer' ? 'Summer' : $sem . ' semester';
    return CURRENT_SCHOOL_YEAR . ', ' . $semLabel;
}

function statusBadgeClass(?string $status): string
{
    switch ($status) {
        case 'PASS': return 'status-badge--pass';
        case 'FAIL': return 'status-badge--fail';
        default: return 'status-badge--pending';
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrfToken(), $sent)) {
        // The login page loads init.php only, so make sure the error page code is here too.
        require_once __DIR__ . '/error_page.php';
        renderErrorPage(
            403,
            'Form expired',
            'Your session expired or this form is no longer valid. Nothing was saved. Go back, reload the page and try again.'
        );
    }
}

function dueDateHtml(?string $dueDate, string $status): string
{
    if (!$dueDate) {
        return '';
    }
    $html = 'Due ' . sanitize($dueDate);

    // A document that is already uploaded (or verified) is no longer "late" on the scholar's side.
    if (!in_array($status, ['Verified', 'Pending Review'], true)) {
        $days = (int)(new DateTimeImmutable('today'))->diff(new DateTimeImmutable($dueDate))->format('%r%a');
        if ($days < 0) {
            $html .= ' — <strong style="color: var(--color-fail);">OVERDUE</strong>';
        } elseif ($days === 0) {
            $html .= ' — due today';
        } else {
            $html .= ' — due in ' . $days . ($days === 1 ? ' day' : ' days');
        }
    }
    return '<br><small>' . $html . '</small>';
}

function validateGwaInput(PDO $pdo, array $input): array
{
    $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
    $positiveId = static function (string $key) use ($input): int {
        $raw = $input[$key] ?? null;
        if (is_int($raw)) {
            return $raw > 0 ? $raw : 0;
        }
        if (!is_string($raw)) {
            return 0;
        }
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? 0 : $id;
    };
    $gwa = trim($text('gwa'));
    $units = filter_var($input['total_units'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $values = [
        'scholar_id' => $positiveId('scholar_id'),
        'school_year' => trim($text('school_year')),
        'semester' => trim($text('semester')),
        'gwa' => $gwa,
        'total_units' => $units,
        'source_document_id' => $positiveId('source_document_id'),
    ];

    $error = null;
    if ($values['scholar_id'] < 1) {
        $error = 'Choose a scholar.';
    } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $gwa) || (float)$gwa < 1 || (float)$gwa > 5) {
        $error = 'GWA must be between 1.00 and 5.00, with up to two decimals.';
    } elseif ($units === false) {
        $error = 'Total units must be a positive whole number.';
    } elseif (!preg_match('/^\d{4}-\d{4}$/', $values['school_year'])) {
        $error = 'School year must look like 2026-2027.';
    } elseif (!in_array($values['semester'], ['1st', '2nd', 'Summer'], true)) {
        $error = 'Semester must be 1st, 2nd or Summer.';
    } elseif ($values['source_document_id'] < 1) {
        $error = 'Choose the verified Certificate of Grades used as the source.';
    }

    if ($error === null) {
        $stmt = $pdo->prepare(
            "SELECT d.id
             FROM documents d
             LEFT JOIN requirements req ON req.id = d.requirement_id
             WHERE d.id = ? AND d.scholar_id = ?
               AND d.school_year = ? AND d.semester = ?
               AND d.status = 'Verified'
               AND (
                    (d.requirement_id IS NOT NULL AND BINARY req.name = BINARY 'Certificate of Grades')
                    OR (d.requirement_id IS NULL AND BINARY d.document_type = BINARY 'Certificate of Grades')
               )"
        );
        $stmt->execute([
            $values['source_document_id'],
            $values['scholar_id'],
            $values['school_year'],
            $values['semester'],
        ]);
        if ($stmt->fetchColumn() === false) {
            $error = 'The source must be a currently verified Certificate of Grades for this scholar and term.';
        }
    }

    if ($units !== false) {
        $values['total_units'] = $units;
    }
    return ['error' => $error, 'values' => $values];
}

/**
 * Validates rule form input. Shared by rules.php (add) and edit_rule.php (edit) so both apply
 * the same checks and messages.
 * Returns ['error' => string|null, 'values' => cleaned values ready for INSERT/UPDATE].
 */
function validateRuleInput(PDO $pdo, array $input): array
{
    $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';

    $ruleType = $text('rule_type');
    $operator = $text('operator');

    $dueDate = $text('due_date');
    $dueDate = ($dueDate !== '' && $ruleType === 'DOCUMENT') ? $dueDate : null;
    if ($dueDate !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) || strtotime($dueDate) === false)) {
        $dueDate = null;
    }

    if ($ruleType === 'DOCUMENT') {
        $operator = '==';
    }

    $requirementId = (int)($input['requirement_id'] ?? 0);
    $requirementName = '';
    if ($requirementId > 0) {
        $stmt = $pdo->prepare('SELECT name FROM requirements WHERE id = ? AND is_active = 1');
        $stmt->execute([$requirementId]);
        $requirementName = (string)($stmt->fetchColumn() ?: '');
    }
    $values = [
        'scholarship_type_id' => (int)$text('scholarship_type_id'),
        'rule_type'           => $ruleType,
        'requirement_id'      => $requirementId > 0 ? $requirementId : null,
        'operator'            => $operator,
        'threshold_value'     => $ruleType === 'DOCUMENT' && $requirementName !== ''
            ? $requirementName : trim($text('threshold_value')),
        'school_year'         => sanitize($text('school_year')),
        'semester'            => $text('semester'),
        'due_date'            => $dueDate,
    ];

    $typeExists = false;
    if ($values['scholarship_type_id'] > 0) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM scholarship_types WHERE id = ?');
        $stmt->execute([$values['scholarship_type_id']]);
        $typeExists = (int)$stmt->fetchColumn() > 0;
    }

    $error = null;
    if (!$typeExists
        || !in_array($values['rule_type'], ['GWA', 'UNIT_LOAD', 'DOCUMENT', 'DEADLINE'], true)
        || !in_array($values['operator'], ['>=', '<=', '>', '<', '=='], true)
        || $values['threshold_value'] === ''
        || $values['school_year'] === ''
        || !in_array($values['semester'], ['1st', '2nd', 'Summer'], true)) {
        $error = 'Please fill in every field correctly.';
    } elseif ($values['rule_type'] === 'DOCUMENT' && $values['requirement_id'] === null
        && trim($text('threshold_value')) === '') {
        $error = 'Choose a requirement.';
    } elseif ($values['rule_type'] !== 'DOCUMENT' && $values['rule_type'] !== 'DEADLINE'
        && !is_numeric($values['threshold_value'])) {
        $error = 'The threshold for a GWA or unit load rule must be a number.';
    } elseif ($values['rule_type'] === 'DOCUMENT' && $values['requirement_id'] !== null && $requirementName === '') {
        $error = 'Choose an active requirement.';
    }

    return ['error' => $error, 'values' => $values];
}

/** Up to two initials from structured scholar names. */
function accountInitials(string $name): string
{
    $name = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
    if (preg_match_all('/[\p{L}\p{N}]+/u', $name, $matches) === false || empty($matches[0])) {
        return '?';
    }
    $words = $matches[0];
    $take = static fn(string $s, int $n): string => function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
    $initials = count($words) >= 2 ? $take($words[0], 1) . $take($words[count($words) - 1], 1) : $take($words[0], 2);
    return function_exists('mb_strtoupper') ? mb_strtoupper($initials) : strtoupper($initials);
}

/** Name, email, role and initials for the account chip and the Settings page. */
function currentAccount(?PDO $pdo = null): array
{
    $role = (string)currentRole();
    $email = (string)($_SESSION['email'] ?? '');
    $name = $role === 'admin' ? 'Administrator' : '';   // admins have no profile name in the schema

    if ($role === 'scholar') {
        try {
            $pdo = $pdo ?? getDbConnection();
            $stmt = $pdo->prepare('SELECT fname, mname, lname, suffix FROM scholars WHERE user_id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $scholar = $stmt->fetch();
            if ($scholar) {
                $name = formatScholarName($scholar, 'account');
            }
        } catch (Throwable $e) {
            error_log('GRANTED account menu could not load the name: ' . $e->getMessage());   // the header must never break a page
        }
    }
    if ($name === '') {
        $at = strpos($email, '@');
        $name = $at > 0 ? substr($email, 0, $at) : ($email !== '' ? $email : ucfirst($role));
    }

    return ['name' => $name, 'email' => $email, 'role' => $role, 'initials' => accountInitials($name)];
}

/** Where the Privacy link points: GRANTED_PRIVACY_URL if set, else /GRANTED/privacy.php if that page exists, else null (no link). */
function privacyLink(): ?array
{
    $url = defined('GRANTED_PRIVACY_URL') ? (string)GRANTED_PRIVACY_URL : '';
    if (preg_match('#^https?://#i', $url)) {
        return ['href' => $url, 'external' => true];
    }
    if (is_file(__DIR__ . '/../privacy.php')) {
        return ['href' => '/GRANTED/privacy.php', 'external' => false];
    }
    return null;
}

/** One consistent box for a message saved with setFlash(). Messages starting "Not saved" are errors; anything else is a success. */
function flashBox(?string $message): string
{
    if ($message === null || $message === '') {
        return '';
    }
    $isError = stripos($message, 'Not saved') === 0;
    return '<div class="flash ' . ($isError ? 'flash--error' : 'flash--success') . '" role="' . ($isError ? 'alert' : 'status') . '">'
        . uiIcon($isError ? 'circle-alert' : 'circle-check') . '<span>' . h($message) . '</span></div>';
}

function statusNextStep(?string $status, string $audience = 'scholar'): string
{
    $status = $status ?: 'PENDING';
    if ($audience === 'admin') {
        switch ($status) {
            case 'PASS':
                return 'All current-term rules are met.';
            case 'FAIL':
                return 'At least one current-term rule is not met.';
            default:
                return 'Waiting on documents, GWA, or a rule that is not decided yet.';
        }
    }

    switch ($status) {
        case 'PASS':
            return 'You meet the current requirements for this scholarship.';
        case 'FAIL':
            return 'Something still needs attention. Check your checklist or contact the Scholarship Office.';
        default:
            return 'Waiting for a required document or official GWA for this term.';
    }
}

function documentStoragePath(string $storedName): string
{
    return __DIR__ . '/../uploads/documents/' . basename($storedName);
}

function documentFileIsAvailable(?string $storedName): bool
{
    if ($storedName === null || $storedName === '') {
        return false;
    }
    $path = documentStoragePath($storedName);
    return is_file($path) && is_readable($path);
}

function summarizePendingReasons(array $ruleRows): string
{
    $bits = [];
    foreach ($ruleRows as $row) {
        if (($row['result'] ?? '') !== 'PENDING') {
            continue;
        }
        switch ($row['rule_type'] ?? '') {
            case 'GWA':
                $bits[] = 'official GWA not yet usable';
                break;
            case 'UNIT_LOAD':
                $bits[] = 'unit load not yet usable';
                break;
            case 'DOCUMENT':
                $label = $row['requirement_name'] ?? $row['threshold_value'] ?? 'document';
                $bits[] = $label . ' still outstanding';
                break;
            case 'DEADLINE':
                $bits[] = 'deadline rule not evaluated yet';
                break;
            default:
                $bits[] = 'a rule is still pending';
        }
    }
    $bits = array_values(array_unique($bits));
    if ($bits === []) {
        return '';
    }
    if (count($bits) === 1) {
        return 'Waiting on ' . $bits[0] . '.';
    }
    $last = array_pop($bits);
    return 'Waiting on ' . implode(', ', $bits) . ' and ' . $last . '.';
}