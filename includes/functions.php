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

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function currentRole(): ?string
{
    return $_SESSION['role'] ?? null;
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
        http_response_code(403);
        die('Your form expired or is invalid. Please go back, refresh the page and try again.');
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

/**
 * Validates grade form input. Shared by enter_grades.php and edit_grade.php so both apply
 * the same rules and messages. $excludeGradeId lets an edit ignore the row being edited
 * in the duplicate check.
 * Returns ['error' => string|null, 'values' => cleaned values ready for INSERT/UPDATE].
 */
function validateGradeInput(PDO $pdo, array $input, ?int $excludeGradeId = null): array
{
    $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';

    $values = [
        'scholar_id'  => (int)($input['scholar_id'] ?? 0),
        'subject'     => sanitize($text('subject')),
        'grade'       => trim($text('grade')),
        'units'       => filter_var($input['units'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]),
        'school_year' => sanitize($text('school_year')),
        'semester'    => $text('semester'),
    ];

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM scholars WHERE id = ?');
    $stmt->execute([$values['scholar_id']]);
    $scholarExists = (int)$stmt->fetchColumn() > 0;

    $error = null;
    $duplicate = false;
    if (!$scholarExists) {
        $error = 'Please choose a scholar.';
    } elseif ($values['subject'] === '') {
        $error = 'Please enter a subject.';
    } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $values['grade'])
        || (float)$values['grade'] < 1 || (float)$values['grade'] > 5) {
        $error = 'Grade must be a number from 1.00 to 5.00 (up to two decimals).';
    } elseif ($values['units'] === false) {
        $error = 'Units must be a whole number from 1 to 10.';
    } elseif (!preg_match('/^\d{4}-\d{4}$/', $values['school_year'])) {
        $error = 'School year must look like 2026-2027.';
    } elseif (!in_array($values['semester'], ['1st', '2nd', 'Summer'], true)) {
        $error = 'Semester must be 1st, 2nd or Summer.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM grades
             WHERE scholar_id = ? AND subject = ? AND school_year = ? AND semester = ? AND id <> ?'
        );
        $stmt->execute([
            $values['scholar_id'], $values['subject'], $values['school_year'], $values['semester'],
            $excludeGradeId ?? 0,
        ]);
        if ((int)$stmt->fetchColumn() > 0) {
            $duplicate = true;
            $error = 'A grade for "' . $values['subject']
                . '" is already recorded for this scholar in '
                . $values['school_year'] . ' ' . $values['semester'] . '. Duplicate grades are not allowed.';
        }
    }

        return ['error' => $error, 'values' => $values, 'duplicate' => $duplicate];
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

    $values = [
        'scholarship_type_id' => (int)$text('scholarship_type_id'),
        'rule_type'           => $ruleType,
        'operator'            => $operator,
        'threshold_value'     => sanitize($text('threshold_value')),
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
        || !in_array($values['rule_type'], ['GPA', 'UNIT_LOAD', 'DOCUMENT'], true)
        || !in_array($values['operator'], ['>=', '<=', '>', '<', '=='], true)
        || $values['threshold_value'] === ''
        || $values['school_year'] === ''
        || !in_array($values['semester'], ['1st', '2nd', 'Summer'], true)) {
        $error = 'Please fill in every field correctly.';
    } elseif ($values['rule_type'] !== 'DOCUMENT' && !is_numeric($values['threshold_value'])) {
        $error = 'The threshold for a GPA or unit load rule must be a number.';
    }

    return ['error' => $error, 'values' => $values];
}