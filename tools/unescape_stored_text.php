<?php
/**
 * One-time Phase 7 migration. Text used to be saved HTML-escaped (O'Brien -> O&#039;Brien).
 * From Phase 7 on it is saved raw and escaped when printed. This decodes the old rows.
 *
 *   C:\xampp\php\php.exe tools\unescape_stored_text.php           dry run: shows what would change
 *   C:\xampp\php\php.exe tools\unescape_stored_text.php --apply   writes the changes
 *
 * BACK UP THE DATABASE FIRST. Run --apply ONCE, then tools\reevaluate_all.php
 * (documents that never matched because of double escaping may now match and change statuses;
 * set MAIL_ENABLED to false first if you do not want those status emails).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$apply = in_array('--apply', $argv ?? [], true);

// Only columns that went through sanitize() before saving. renewal_decisions.remarks and the
// notifications table were never escaped on save, so they are deliberately not listed.
$targets = [
    'users'             => ['email'],
    'scholars'          => ['full_name', 'program'],
    'scholarship_types' => ['name', 'description'],
    'rules'             => ['threshold_value', 'school_year'],
    'grades'            => ['subject', 'school_year'],
    'documents'         => ['document_type', 'remarks', 'school_year'],
];

/** Some rows were escaped twice (escaped on save, shown in a form, escaped again on the next save). */
function decodeStored(string $value): string
{
    for ($i = 0; $i < 3; $i++) {
        $decoded = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        if ($decoded === $value) {
            break;
        }
        $value = $decoded;
    }
    return $value;
}

$pdo = getDbConnection();
$total = 0;

foreach ($targets as $table => $columns) {
    foreach ($columns as $column) {
        // Table and column names come from the fixed list above, never from input.
        $rows = $pdo->query("SELECT id, `$column` AS v FROM `$table` WHERE `$column` LIKE '%&%;%'")->fetchAll();

        $changes = [];
        foreach ($rows as $row) {
            $old = (string)$row['v'];
            $new = decodeStored($old);
            if ($new !== $old) {
                $changes[] = [(int)$row['id'], $old, $new];
            }
        }

        echo "{$table}.{$column}: " . count($changes) . " row(s) to change\n";
        foreach (array_slice($changes, 0, 5) as [$id, $old, $new]) {
            echo "    #{$id}  {$old}  ->  {$new}\n";
        }

        if ($apply && $changes) {
            $pdo->beginTransaction();
            try {
                $update = $pdo->prepare("UPDATE `$table` SET `$column` = ? WHERE id = ?");
                foreach ($changes as [$id, , $new]) {
                    $update->execute([$new, $id]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                fwrite(STDERR, "    FAILED and rolled back: " . $e->getMessage() . "\n");
                exit(1);
            }
        }
        $total += count($changes);
    }
}

echo ($apply ? "Applied. " : "Dry run only (add --apply to save). ") . "{$total} value(s) " . ($apply ? 'changed' : 'would change') . ".\n";