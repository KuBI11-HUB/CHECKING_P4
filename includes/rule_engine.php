<?php
/**
 * Assignment-scoped evaluator for the final schema.
 * Only this module writes scholarship_assignments.status and scholar metric caches.
 */

function compareValue(int|float|string $actual, string $operator, int|float|string $threshold): bool
{
    $actual = (float)$actual;
    $threshold = (float)$threshold;

    switch ($operator) {
        case '>=': return $actual >= $threshold;
        case '<=': return $actual <= $threshold;
        case '>': return $actual > $threshold;
        case '<': return $actual < $threshold;
        case '==': return $actual == $threshold;
        default: return false;
    }
}

/**
 * Return the current-term GWA only when its source is still a valid, verified
 * Certificate of Grades for the same scholar and term.
 */
function getUsableGwaRecord(PDO $pdo, int $scholarId, string $schoolYear, string $semester): ?array
{
    $stmt = $pdo->prepare(
        "SELECT g.id, g.scholar_id, g.school_year, g.semester, g.gwa, g.total_units,
                g.source_document_id
         FROM gwa_records g
         JOIN documents d ON d.id = g.source_document_id
         LEFT JOIN requirements req ON req.id = d.requirement_id
         WHERE g.scholar_id = :scholar_id
           AND g.school_year = :school_year AND g.semester = :semester
           AND d.status = 'Verified'
           AND d.scholar_id = g.scholar_id
           AND d.school_year = g.school_year AND d.semester = g.semester
           AND (
                (d.requirement_id IS NOT NULL AND BINARY req.name = BINARY 'Certificate of Grades')
                OR (d.requirement_id IS NULL AND BINARY d.document_type = BINARY 'Certificate of Grades')
           )
         LIMIT 1"
    );
    $stmt->execute([
        ':scholar_id' => $scholarId,
        ':school_year' => $schoolYear,
        ':semester' => $semester,
    ]);
    $record = $stmt->fetch();
    return $record ?: null;
}

function refreshScholarMetrics(PDO $pdo, int $scholarId): ?array
{
    $record = getUsableGwaRecord($pdo, $scholarId, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER);
    $gwa = $record !== null ? $record['gwa'] : null;
    $units = $record !== null ? $record['total_units'] : null;
    $stmt = $pdo->prepare('UPDATE scholars SET current_gwa = ?, current_units = ? WHERE id = ?');
    $stmt->execute([$gwa, $units, $scholarId]);
    return $record;
}

function matchingDocumentRows(PDO $pdo, array $rule, int $scholarId): array
{
    $requirementId = $rule['requirement_id'] !== null ? (int)$rule['requirement_id'] : null;
    $documentName = (string)($rule['requirement_name'] ?? $rule['threshold_value']);
    $isRecurring = $rule['is_recurring'] === null ? true : (bool)$rule['is_recurring'];

    $sql = 'SELECT status, remarks, uploaded_at FROM documents
            WHERE scholar_id = :scholar_id
              AND ((:requirement_id IS NOT NULL AND requirement_id = :requirement_id_match)
                   OR (requirement_id IS NULL AND BINARY document_type = BINARY :document_type))';
    $params = [
        ':scholar_id' => $scholarId,
        ':requirement_id' => $requirementId,
        ':requirement_id_match' => $requirementId,
        ':document_type' => $documentName,
    ];
    if ($isRecurring) {
        $sql .= ' AND school_year = :school_year AND semester = :semester';
        $params[':school_year'] = CURRENT_SCHOOL_YEAR;
        $params[':semester'] = CURRENT_SEMESTER;
    }
    $sql .= ' ORDER BY uploaded_at DESC, id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function evaluateRule(PDO $pdo, array $rule, array $scholar): string
{
    $scholarId = (int)($scholar['scholar_id'] ?? $scholar['id'] ?? 0);
    if ($scholarId < 1) {
        throw new InvalidArgumentException('Rule evaluation requires a scholar ID.');
    }

    switch ($rule['rule_type']) {
        case 'GWA':
            $gwa = getUsableGwaRecord($pdo, $scholarId, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER);
            if ($gwa === null) {
                return 'PENDING';
            }
            return compareValue($gwa['gwa'], $rule['operator'], $rule['threshold_value']) ? 'PASS' : 'FAIL';

        case 'UNIT_LOAD':
            $record = getUsableGwaRecord($pdo, $scholarId, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER);
            if ($record === null) {
                return 'PENDING';
            }
            return compareValue($record['total_units'], $rule['operator'], $rule['threshold_value']) ? 'PASS' : 'FAIL';

        case 'DOCUMENT':
            $documents = matchingDocumentRows($pdo, $rule, $scholarId);
            foreach ($documents as $document) {
                if ($document['status'] === 'Verified') {
                    return 'PASS';
                }
            }
            if (!empty($documents)) {
                return $documents[0]['status'] === 'Rejected' ? 'FAIL' : 'PENDING';
            }
            $pastDue = !empty($rule['due_date']) && date('Y-m-d') > $rule['due_date'];
            return $pastDue ? 'FAIL' : 'PENDING';

        case 'DEADLINE':
        default:
            return 'PENDING';
    }
}

function evaluateAssignment(PDO $pdo, int $assignmentId): ?string
{
    $stmt = $pdo->prepare(
        'SELECT a.id AS assignment_id, a.scholar_id, a.scholarship_type_id, a.status,
                a.is_active, a.ended_at, s.fname, s.mname, s.lname, s.suffix,
                s.current_gwa, s.current_units, st.name AS scholarship_type_name
         FROM scholarship_assignments a
         JOIN scholars s ON s.id = a.scholar_id
         JOIN scholarship_types st ON st.id = a.scholarship_type_id
         WHERE a.id = ?'
    );
    $stmt->execute([$assignmentId]);
    $assignment = $stmt->fetch();
    if (!$assignment || !(int)$assignment['is_active'] || $assignment['ended_at'] !== null) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT r.id, r.rule_type, r.requirement_id, r.operator, r.threshold_value,
                r.school_year, r.semester, r.due_date, req.name AS requirement_name,
                req.is_recurring
         FROM rules r
         LEFT JOIN requirements req ON req.id = r.requirement_id
         WHERE r.scholarship_type_id = ? AND r.is_active = 1
           AND r.school_year = ? AND r.semester = ?"
    );
    $stmt->execute([
        (int)$assignment['scholarship_type_id'],
        CURRENT_SCHOOL_YEAR,
        CURRENT_SEMESTER,
    ]);
    $rules = $stmt->fetchAll();

    $allPassed = $rules !== [];
    $anyFailed = false;
    $anyPending = $rules === [];
    foreach ($rules as $rule) {
        $result = evaluateRule($pdo, $rule, $assignment);
        $stmt = $pdo->prepare(
            'INSERT INTO evaluations (scholar_id, rule_id, result)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE result = VALUES(result), evaluated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([(int)$assignment['scholar_id'], (int)$rule['id'], $result]);

        if ($result !== 'PASS') {
            $allPassed = false;
        }
        if ($result === 'FAIL') {
            $anyFailed = true;
        } elseif ($result === 'PENDING') {
            $anyPending = true;
        }
    }

    $newStatus = $anyPending ? 'PENDING' : ($allPassed ? 'PASS' : ($anyFailed ? 'FAIL' : 'PENDING'));
    $oldStatus = (string)$assignment['status'];
    if ($newStatus !== $oldStatus) {
        $stmt = $pdo->prepare(
            'UPDATE scholarship_assignments SET status = ? WHERE id = ? AND status = ?'
        );
        $stmt->execute([$newStatus, $assignmentId, $oldStatus]);
        if ($stmt->rowCount() > 0) {
            notifyAssignmentStatusChange($pdo, $assignment, $oldStatus, $newStatus);
        }
    }

    return $newStatus;
}

/**
 * Evaluate all active assignments for one scholar, then refresh that scholar's
 * current-term GWA/unit caches. Returns the worst active assignment status for
 * callers that still display a scholar-level summary.
 */
function evaluateScholar(PDO $pdo, int $scholarId): ?string
{
    $stmt = $pdo->prepare(
        'SELECT id FROM scholarship_assignments
         WHERE scholar_id = ? AND is_active = 1 AND ended_at IS NULL
         ORDER BY id'
    );
    $stmt->execute([$scholarId]);
    $assignmentIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$assignmentIds) {
        refreshScholarMetrics($pdo, $scholarId);
        return null;
    }

    refreshScholarMetrics($pdo, $scholarId);
    $statuses = [];
    foreach ($assignmentIds as $assignmentId) {
        $status = evaluateAssignment($pdo, $assignmentId);
        if ($status !== null) {
            $statuses[] = $status;
        }
    }
    if (in_array('FAIL', $statuses, true)) {
        return 'FAIL';
    }
    if (in_array('PENDING', $statuses, true)) {
        return 'PENDING';
    }
    return $statuses ? 'PASS' : null;
}

function reevaluateScholarshipType(PDO $pdo, int $scholarshipTypeId): int
{
    $stmt = $pdo->prepare(
        'SELECT id FROM scholarship_assignments
         WHERE scholarship_type_id = ? AND is_active = 1 AND ended_at IS NULL
         ORDER BY id'
    );
    $stmt->execute([$scholarshipTypeId]);
    $assignmentIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ($assignmentIds as $assignmentId) {
        $scholarIdStmt = $pdo->prepare('SELECT scholar_id FROM scholarship_assignments WHERE id = ?');
        $scholarIdStmt->execute([$assignmentId]);
        $scholarId = (int)$scholarIdStmt->fetchColumn();
        if ($scholarId > 0) {
            refreshScholarMetrics($pdo, $scholarId);
        }
        evaluateAssignment($pdo, $assignmentId);
    }
    return count($assignmentIds);
}

function getScholarAssignments(PDO $pdo, int $scholarId, bool $activeOnly = true): array
{
    $sql = 'SELECT a.*, st.name AS type_name
            FROM scholarship_assignments a
            JOIN scholarship_types st ON st.id = a.scholarship_type_id
            WHERE a.scholar_id = ?';
    if ($activeOnly) {
        $sql .= ' AND a.is_active = 1 AND a.ended_at IS NULL';
    }
    $sql .= ' ORDER BY a.is_active DESC, a.last_activated_at DESC, a.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$scholarId]);
    return $stmt->fetchAll();
}

function getDocumentChecklist(PDO $pdo, int $scholarId, int $assignmentId): array
{
    $stmt = $pdo->prepare(
        "SELECT r.requirement_id, req.name AS requirement_name, req.is_recurring,
                r.threshold_value, MIN(r.due_date) AS due_date
         FROM rules r
         JOIN scholarship_assignments a
           ON a.scholarship_type_id = r.scholarship_type_id
         LEFT JOIN requirements req ON req.id = r.requirement_id
         WHERE a.id = ? AND a.scholar_id = ? AND a.is_active = 1
           AND a.ended_at IS NULL AND r.rule_type = 'DOCUMENT' AND r.is_active = 1
           AND r.school_year = ? AND r.semester = ?
         GROUP BY r.requirement_id, req.name, req.is_recurring, r.threshold_value
         ORDER BY COALESCE(req.name, r.threshold_value)"
    );
    $stmt->execute([$assignmentId, $scholarId, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);
    $items = $stmt->fetchAll();
    $checklist = [];
    foreach ($items as $item) {
        $rule = [
            'requirement_id' => $item['requirement_id'],
            'requirement_name' => $item['requirement_name'],
            'is_recurring' => $item['is_recurring'],
            'threshold_value' => $item['threshold_value'],
        ];
        $documents = matchingDocumentRows($pdo, $rule, $scholarId);
        $matched = null;
        foreach ($documents as $document) {
            if ($document['status'] === 'Verified') {
                $matched = $document;
                break;
            }
        }
        $matched = $matched ?? ($documents[0] ?? null);
        $checklist[] = [
            'requirement_id' => $item['requirement_id'],
            'document_type' => $item['requirement_name'] ?? $item['threshold_value'],
            'due_date' => $item['due_date'],
            'status' => $matched['status'] ?? 'Not uploaded',
            'remarks' => $matched['remarks'] ?? null,
        ];
    }
    return $checklist;
}

function getScholarDocumentChecklist(PDO $pdo, int $scholarId): array
{
    $combined = [];
    foreach (getScholarAssignments($pdo, $scholarId) as $assignment) {
        foreach (getDocumentChecklist($pdo, $scholarId, (int)$assignment['id']) as $item) {
            $key = $item['requirement_id'] !== null
                ? 'requirement:' . (int)$item['requirement_id']
                : 'document:' . $item['document_type'];
            if (!isset($combined[$key])) {
                $item['assignment_ids'] = [(int)$assignment['id']];
                $combined[$key] = $item;
                continue;
            }
            $combined[$key]['assignment_ids'][] = (int)$assignment['id'];
            if ($combined[$key]['status'] !== 'Verified' && $item['status'] === 'Verified') {
                $combined[$key]['status'] = 'Verified';
                $combined[$key]['remarks'] = $item['remarks'];
            }
            if ($item['due_date'] !== null
                && ($combined[$key]['due_date'] === null || $item['due_date'] < $combined[$key]['due_date'])) {
                $combined[$key]['due_date'] = $item['due_date'];
            }
        }
    }
    return array_values($combined);
}
