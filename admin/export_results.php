<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$results = getResults($pdo, normalizeResultFilters($_GET));

/** Plain text for a CSV cell. A leading = + - @ tab or CR could run as a spreadsheet formula, so it gets a ' in front. */
function csvText(?string $value): string
{
    $text = plainText($value);
    if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $text;
    }
    return $text;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="granted-results-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows accents correctly

fputcsv($out, ['Name', 'Email', 'Program', 'Year level', 'Scholarship type', 'School year', 'Semester',
    'GPA', 'Units', 'Calculated status', 'Office decision', 'Decision remarks'], ',', '"', '\\');

foreach ($results as $s) {
    fputcsv($out, [
        csvText($s['full_name']),
        csvText($s['email']),
        csvText($s['program']),
        $s['year_level'],
        csvText($s['type_name']),
        CURRENT_SCHOOL_YEAR,
        CURRENT_SEMESTER,
        $s['current_gpa'],
        $s['current_units'],
        $s['current_status'],
        $s['office_decision'],
        csvText($s['decision_remarks']),
    ], ',', '"', '\\');
}
fclose($out);
