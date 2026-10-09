<?php
/**
 * The rule engine — GPA, UNIT_LOAD, and DOCUMENT rules.
 *
 * Phase 4: everything is scoped to the CURRENT term (CURRENT_SCHOOL_YEAR / CURRENT_SEMESTER in
 * config/app.php). Only rules, grades and documents of that term count.
 * DEADLINE still returns PENDING. Deadlines now live on DOCUMENT rules (rules.due_date); for now
 * they are used by the reminder script (cron/send_deadline_reminders.php), not by the status calculation.
 */

function compareValue($actual, string $operator, $threshold): bool
{
    $actual = (float)$actual;
    $threshold = (float)$threshold;

    switch ($operator) {
        case '>=': return $actual >= $threshold;
        case '<=': return $actual <= $threshold;
        case '>':  return $actual > $threshold;
        case '<':  return $actual < $threshold;
        case '==': return $actual == $threshold;
        default:   return false;
    }
}

function evaluateRule(PDO $pdo, array $rule, array $scholar): string
{
    switch ($rule['rule_type']) {
        case 'GPA':
            if ($scholar['current_gpa'] === null) return 'PENDING';
            return compareValue($scholar['current_gpa'], $rule['operator'], $rule['threshold_value'])
                ? 'PASS' : 'FAIL';

        case 'UNIT_LOAD':
            if ($scholar['current_units'] === null) return 'PENDING';
            return compareValue($scholar['current_units'], $rule['operator'], $rule['threshold_value'])
                ? 'PASS' : 'FAIL';

        case 'DOCUMENT':
            // For a DOCUMENT rule, threshold_value holds the *required
            // document_type string* (e.g. "Certificate of Registration"),
            // not a number — this matches how it's documented in the schema.
            // Only a document uploaded for the current term counts.
            $stmt = $pdo->prepare(
                'SELECT status FROM documents WHERE scholar_id = ? AND document_type = ?
                 AND school_year = ? AND semester = ?
                 ORDER BY uploaded_at DESC LIMIT 1'
            );
            $stmt->execute([$scholar['id'], $rule['threshold_value'], CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);
            $doc = $stmt->fetch();

            if (!$doc) return 'PENDING';           // not uploaded yet
            if ($doc['status'] === 'Verified') return 'PASS';
            if ($doc['status'] === 'Rejected') return 'FAIL';
            return 'PENDING';                       // uploaded, awaiting admin review

        case 'DEADLINE':
        default:
            return 'PENDING';
    }
}

function evaluateScholar(PDO $pdo, int $scholarId): ?string
{
    // Always recompute GPA and units for the current term first, so a term change is picked up here too.
    recalculateGpaAndUnits($pdo, $scholarId);

    $stmt = $pdo->prepare('SELECT * FROM scholars WHERE id = ?');
    $stmt->execute([$scholarId]);
    $scholar = $stmt->fetch();
    if (!$scholar) {
        return null;
    }
    $oldStatus = $scholar['current_status'];   // needed to detect a status change (notifications)

    $stmt = $pdo->prepare(
        'SELECT * FROM rules WHERE scholarship_type_id = ? AND is_active = 1
         AND school_year = ? AND semester = ?'
    );
    $stmt->execute([$scholar['scholarship_type_id'], CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);
    $rules = $stmt->fetchAll();

    $allPassed = true;
    $anyPending = false;

    foreach ($rules as $rule) {
        $result = evaluateRule($pdo, $rule, $scholar);

        $stmt = $pdo->prepare(
            'INSERT INTO evaluations (scholar_id, rule_id, result)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE result = VALUES(result), evaluated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$scholarId, $rule['id'], $result]);

        if ($result === 'FAIL') $allPassed = false;
        if ($result === 'PENDING') $anyPending = true;
    }

    if (empty($rules)) {
        $overallStatus = 'PENDING';
    } elseif ($anyPending) {
        $overallStatus = 'PENDING';
    } elseif ($allPassed) {
        $overallStatus = 'PASS';
    } else {
        $overallStatus = 'FAIL';
    }

    $stmt = $pdo->prepare('UPDATE scholars SET current_status = ? WHERE id = ?');
    $stmt->execute([$overallStatus, $scholarId]);

    // Emails the scholar (and logs it) only if the overall status actually changed. Never throws.
    notifyStatusChange($pdo, (int)$scholar['id'], $oldStatus, $overallStatus);

    return $overallStatus;
}

/**
 * Re-run the engine for every scholar on one scholarship type. Call it after a rule is added or
 * deactivated, because the scholars' statuses would otherwise stay out of date until their next
 * grade save or document review. Returns how many scholars were re-evaluated.
 */
function reevaluateScholarshipType(PDO $pdo, int $scholarshipTypeId): int
{
    $stmt = $pdo->prepare('SELECT id FROM scholars WHERE scholarship_type_id = ?');
    $stmt->execute([$scholarshipTypeId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        evaluateScholar($pdo, (int)$id);
    }
    return count($ids);
}

function recalculateGpaAndUnits(PDO $pdo, int $scholarId): void
{
    // Current term only (Phase 4): last semester's grades must not change this semester's GPA.
    $stmt = $pdo->prepare(
        'SELECT SUM(grade * units) AS wsum, SUM(units) AS tunits FROM grades
         WHERE scholar_id = ? AND school_year = ? AND semester = ?'
    );
    $stmt->execute([$scholarId, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);
    $row = $stmt->fetch();

    $totalUnits = $row['tunits'] !== null ? (int)$row['tunits'] : null;
    $gpa = ($totalUnits && $totalUnits > 0) ? ($row['wsum'] / $totalUnits) : null;

    $stmt = $pdo->prepare('UPDATE scholars SET current_gpa = ?, current_units = ? WHERE id = ?');
    $stmt->execute([$gpa, $totalUnits, $scholarId]);
}

/**
 * Returns the list of required document_types (from active DOCUMENT rules)
 * for a scholarship type, each with the scholar's latest upload status for the
 * CURRENT term — this is both the scholar's checklist AND the upload form's dropdown.
 */
function getDocumentChecklist(PDO $pdo, int $scholarId, int $scholarshipTypeId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT threshold_value AS document_type FROM rules
         WHERE scholarship_type_id = ? AND rule_type = 'DOCUMENT' AND is_active = 1
         AND school_year = ? AND semester = ?"
    );
    $stmt->execute([$scholarshipTypeId, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);
    $required = $stmt->fetchAll();

    $checklist = [];
    foreach ($required as $r) {
        $stmt = $pdo->prepare(
            'SELECT status, remarks, uploaded_at FROM documents
             WHERE scholar_id = ? AND document_type = ? AND school_year = ? AND semester = ?
             ORDER BY uploaded_at DESC LIMIT 1'
        );
        $stmt->execute([$scholarId, $r['document_type'], CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);
        $doc = $stmt->fetch();

        $checklist[] = [
            'document_type' => $r['document_type'],
            'status' => $doc['status'] ?? 'Not uploaded',
            'remarks' => $doc['remarks'] ?? null,
        ];
    }
    return $checklist;
}
