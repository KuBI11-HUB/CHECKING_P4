<?php
/**
 * Phase 5 helpers, shared by the admin results table, the CSV export, the scholar detail page
 * and the scholar dashboard rule list (so admin and scholar always see the same rules).
 * Phase 4 (current term, deadlines, notifications, security) is complete; nothing here changes it.
 *
 * Everything is scoped to CURRENT_SCHOOL_YEAR / CURRENT_SEMESTER from config/app.php.
 * Older rows hold text that was HTML-escaped when it was saved (O'Brien -> O&#039;Brien),
 * so print stored text with h() and use plainText() for CSV/plain output.
 */

/** Escape for HTML. Decodes first so already-escaped stored text is not escaped twice. */
function h(?string $value): string
{
    return htmlspecialchars(html_entity_decode($value ?? '', ENT_QUOTES, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function plainText(?string $value): string
{
    return html_entity_decode($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Turns $_GET into clean filters. Anything invalid becomes "no filter". */
function normalizeResultFilters(array $get): array
{
    $program = $get['program'] ?? '';
    $status = $get['status'] ?? '';
    $typeId = $get['scholarship_type_id'] ?? '';
    $search = $get['q'] ?? '';
    $search = is_string($search) ? trim($search) : '';

    return [
        'q' => function_exists('mb_substr') ? mb_substr($search, 0, 100) : substr($search, 0, 100),
        'program' => is_string($program) ? trim($program) : '',
        'status' => (is_string($status) && in_array($status, ['PASS', 'FAIL', 'PENDING'], true)) ? $status : '',
        'scholarship_type_id' => (is_string($typeId) && ctype_digit($typeId)) ? (int)$typeId : 0,
    ];
}

const RESULTS_PER_PAGE_DEFAULT = 25;
const RESULTS_PER_PAGE_OPTIONS = [25, 50, 100];

/** Page number and page size from $_GET. Anything invalid falls back to page 1 / 25 rows. */
function normalizeResultPaging(array $get): array
{
    $perPage = $get['per_page'] ?? '';
    $page = $get['page'] ?? '';

    return [
        'per_page' => (is_string($perPage) && ctype_digit($perPage) && in_array((int)$perPage, RESULTS_PER_PAGE_OPTIONS, true))
            ? (int)$perPage : RESULTS_PER_PAGE_DEFAULT,
        'page' => (is_string($page) && ctype_digit($page) && (int)$page >= 1) ? (int)$page : 1,
    ];
}

/** Query string that reproduces a results view. $overrides changes single values (for example 'page' => 2, or 'q' => '' to drop the search). */
function resultsQueryString(array $filters, array $paging = [], array $overrides = []): string
{
    $values = array_merge($filters, $paging, $overrides);
    $query = [];
    if (($values['q'] ?? '') !== '') {
        $query['q'] = $values['q'];
    }
    if (($values['program'] ?? '') !== '') {
        $query['program'] = $values['program'];
    }
    if (($values['status'] ?? '') !== '') {
        $query['status'] = $values['status'];
    }
    if ((int)($values['scholarship_type_id'] ?? 0) > 0) {
        $query['scholarship_type_id'] = (int)$values['scholarship_type_id'];
    }
    if ((int)($values['per_page'] ?? RESULTS_PER_PAGE_DEFAULT) !== RESULTS_PER_PAGE_DEFAULT) {
        $query['per_page'] = (int)$values['per_page'];
    }
    if ((int)($values['page'] ?? 1) > 1) {
        $query['page'] = (int)$values['page'];
    }
    return http_build_query($query);
}

/** The values the filter dropdowns can offer. Programs are kept exactly as stored. */
function getResultFilterOptions(PDO $pdo): array
{
    $programs = $pdo->query(
        "SELECT DISTINCT program FROM scholars WHERE program IS NOT NULL AND program <> '' ORDER BY program"
    )->fetchAll(PDO::FETCH_COLUMN);
    $types = $pdo->query('SELECT id, name FROM scholarship_types ORDER BY name')->fetchAll();

    return ['programs' => $programs, 'statuses' => ['PASS', 'FAIL', 'PENDING'], 'types' => $types];
}

/** WHERE clause and parameters shared by the results list, its count and the CSV export. A filter only applies when it matches a real option. */
function resultsWhere(PDO $pdo, array $filters): array
{
    $options = getResultFilterOptions($pdo);
    $where = [];
    $params = [];

    $program = $filters['program'] ?? '';
    if ($program !== '' && in_array($program, $options['programs'], true)) {
        $where[] = 's.program = :program';
        $params[':program'] = $program;
    }

    $status = $filters['status'] ?? '';
    if (in_array($status, $options['statuses'], true)) {
        $where[] = 's.current_status = :status';
        $params[':status'] = $status;
    }

    $typeId = (int)($filters['scholarship_type_id'] ?? 0);
    if ($typeId > 0 && in_array($typeId, array_map('intval', array_column($options['types'], 'id')), true)) {
        $where[] = 's.scholarship_type_id = :type_id';
        $params[':type_id'] = $typeId;
    }

    $search = $filters['q'] ?? '';
    if ($search !== '') {
        // Older rows hold HTML-escaped text (O'Brien -> O&#039;Brien), so look for both spellings.
        $spellings = array_values(array_unique([$search, htmlspecialchars($search, ENT_QUOTES, 'UTF-8')]));
        $any = [];
        foreach ($spellings as $i => $spelling) {
            $like = '%' . addcslashes($spelling, '\\%_') . '%';
            $any[] = "s.full_name LIKE :name{$i} OR u.email LIKE :email{$i}";
            $params[":name{$i}"] = $like;
            $params[":email{$i}"] = $like;
        }
        $where[] = '(' . implode(' OR ', $any) . ')';
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

/** Number of scholars matching the filters (ignores paging). */
function countResults(PDO $pdo, array $filters): int
{
    [$whereSql, $params] = resultsWhere($pdo, $filters);
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM scholars s
         JOIN users u ON u.id = s.user_id
         JOIN scholarship_types st ON st.id = s.scholarship_type_id' . $whereSql
    );
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * One row per scholar, with the office decision for the CURRENT term (null when none yet).
 * Without $limit every matching row is returned (the CSV export relies on that).
 */
function getResults(PDO $pdo, array $filters, ?int $limit = null, int $offset = 0): array
{
    [$whereSql, $params] = resultsWhere($pdo, $filters);
    $params[':school_year'] = CURRENT_SCHOOL_YEAR;
    $params[':semester'] = CURRENT_SEMESTER;

    $sql = 'SELECT s.id, s.full_name, s.program, s.year_level, s.current_gpa, s.current_units, s.current_status,
                   u.email, st.name AS type_name,
                   rd.decision AS office_decision, rd.remarks AS decision_remarks
            FROM scholars s
            JOIN users u ON u.id = s.user_id
            JOIN scholarship_types st ON st.id = s.scholarship_type_id
            LEFT JOIN renewal_decisions rd ON rd.scholar_id = s.id
                 AND rd.school_year = :school_year AND rd.semester = :semester'
        . $whereSql
        . ' ORDER BY s.full_name, s.id';
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);   // plain integers, never user text
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Active rules of the current term for one scholar, each with its latest result
 * (PENDING when the rule has not been evaluated yet) and the facts explainRuleResult() needs.
 */
function getRuleBreakdown(PDO $pdo, int $scholarId, int $typeId): array
{
    $stmt = $pdo->prepare(
        "SELECT r.id, r.rule_type, r.operator, r.threshold_value, r.due_date,
                COALESCE(e.result, 'PENDING') AS result, e.evaluated_at,
                s.current_gpa, s.current_units,
                CASE WHEN r.rule_type = 'DOCUMENT' THEN (
                    SELECT d.status FROM documents d
                    WHERE d.scholar_id = s.id AND d.document_type = r.threshold_value
                      AND d.school_year = :doc_year AND d.semester = :doc_semester
                    ORDER BY d.uploaded_at DESC, d.id DESC LIMIT 1
                ) END AS document_status
         FROM rules r
         JOIN scholars s ON s.id = :scholar_id
         LEFT JOIN evaluations e ON e.rule_id = r.id AND e.scholar_id = s.id
         WHERE r.scholarship_type_id = :type_id AND r.is_active = 1
           AND r.school_year = :rule_year AND r.semester = :rule_semester
         ORDER BY FIELD(r.rule_type, 'GPA', 'UNIT_LOAD', 'DOCUMENT', 'DEADLINE'), r.id"
    );
    $stmt->execute([
        ':doc_year' => CURRENT_SCHOOL_YEAR,
        ':doc_semester' => CURRENT_SEMESTER,
        ':scholar_id' => $scholarId,
        ':type_id' => $typeId,
        ':rule_year' => CURRENT_SCHOOL_YEAR,
        ':rule_semester' => CURRENT_SEMESTER,
    ]);
    return $stmt->fetchAll();
}

/** What the rule requires, in words. Built from stored values: print it with h(). */
function describeRule(array $rule): string
{
    $words = ['>=' => 'at least', '<=' => 'at most', '>' => 'more than', '<' => 'less than', '==' => 'exactly'];
    $limit = $words[$rule['operator']] ?? $rule['operator'];

    switch ($rule['rule_type']) {
        case 'GPA':
            return 'GPA must be ' . $limit . ' ' . $rule['threshold_value'];
        case 'UNIT_LOAD':
            return 'Unit load must be ' . $limit . ' ' . $rule['threshold_value'];
        case 'DOCUMENT':
            $text = 'Required document: ' . $rule['threshold_value'];
            return empty($rule['due_date']) ? $text : $text . ' (due ' . $rule['due_date'] . ')';
        default:
            return 'Deadline rule: ' . $rule['threshold_value'];
    }
}

/** Why a rule has its result. Takes a row from getRuleBreakdown(). Print it with h(). */
function explainRuleResult(array $row): string
{
    $result = $row['result'] ?? 'PENDING';

    switch ($row['rule_type']) {
        case 'GPA':
        case 'UNIT_LOAD':
            $label = $row['rule_type'] === 'GPA' ? 'GPA' : 'Unit load';
            $value = $row['rule_type'] === 'GPA' ? $row['current_gpa'] : $row['current_units'];
            if ($value === null) {
                return 'No grades recorded for this term yet.';
            }
            if ($result === 'PENDING') {
                return $label . ' is ' . $value . '; waiting for the next check.';
            }
            return $label . ' is ' . $value . ($result === 'PASS' ? ' — meets this rule.' : ' — does not meet this rule.');

        case 'DOCUMENT':
            switch ($row['document_status'] ?? null) {
                case 'Verified':
                    return 'Document verified.';
                case 'Rejected':
                    return 'Latest upload was rejected; a new upload is needed.';
                case 'Pending Review':
                    return 'Uploaded, waiting for review.';
            }
            if ($result === 'FAIL') {
                return 'Nothing was uploaded by the due date (' . $row['due_date'] . ').';
            }
            return 'Not uploaded yet' . (empty($row['due_date']) ? '.' : ' (due ' . $row['due_date'] . ').');

        default:
            return 'This rule type is not evaluated and stays pending.';
    }
}

function setFlash(string $msg): void
{
    $_SESSION['flash'] = $msg;
}

function takeFlash(): ?string
{
    $msg = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $msg;
}
