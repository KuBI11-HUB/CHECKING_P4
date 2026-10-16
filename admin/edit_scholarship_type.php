<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

const TYPE_NAME_MAX = 100;
const TYPE_DESCRIPTION_MAX = 255;

$pdo = getDbConnection();
$typeId = (int)($_GET['id'] ?? 0);
$listUrl = '/GRANTED/admin/scholarship_types.php';
$editUrl = '/GRANTED/admin/edit_scholarship_type.php?id=' . $typeId;

$stmt = $pdo->prepare('SELECT id, name, description FROM scholarship_types WHERE id = ?');
$stmt->execute([$typeId]);
$existing = $stmt->fetch();

if (!$existing) {
    http_response_code(404);
    $pageTitle = 'Scholarship type not found';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <h1>Scholarship type not found</h1>
    <div class="card">
        <p>There is no scholarship type with that ID. It may already have been deleted.</p>
        <p style="margin-top:1rem;"><a class="btn" href="<?php echo $listUrl; ?>">Back to scholarship types</a></p>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$countFor = static function (PDO $pdo, string $sql, int $id): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    return (int)$stmt->fetchColumn();
};
$len = static fn(string $s): int => function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);

$message = '';
$form = ['name' => (string)$existing['name'], 'description' => (string)($existing['description'] ?? '')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (isset($_POST['delete'])) {
        // Checked again here on purpose: the page may be stale, and the button is only hidden in the UI.
        $scholarCount = $countFor($pdo, 'SELECT COUNT(*) FROM scholars WHERE scholarship_type_id = ?', $typeId);
        if ($scholarCount > 0) {
            $message = 'This type cannot be deleted: ' . $scholarCount . ' scholar(s) still use it. Move them to another type first.';
        } else {
            try {
                $stmt = $pdo->prepare('DELETE FROM scholarship_types WHERE id = ?');
                $stmt->execute([$typeId]);
                redirectTo($listUrl);
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1451) {   // foreign key: something else still points at this type
                    $message = 'This type cannot be deleted because rules still refer to it.';
                } else {
                    throw $e;
                }
            }
        }
    } else {
        foreach (array_keys($form) as $key) {
            $form[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
        }
        $name = sanitize($form['name']);
        $description = sanitize($form['description']);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM scholarship_types WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $typeId]);
        $nameTaken = (int)$stmt->fetchColumn() > 0;

        if ($name === '') {
            $message = 'Please enter a name.';
        } elseif ($len($name) > TYPE_NAME_MAX) {
            $message = 'The name is too long (' . TYPE_NAME_MAX . ' characters at most).';
        } elseif ($len($description) > TYPE_DESCRIPTION_MAX) {
            $message = 'The description is too long (' . TYPE_DESCRIPTION_MAX . ' characters at most).';
        } elseif ($nameTaken) {
            $message = 'Another scholarship type already uses that name.';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE scholarship_types SET name = ?, description = ? WHERE id = ?');
                $stmt->execute([$name, $description, $typeId]);
                redirectTo($editUrl . '&saved=1');
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $message = 'Another scholarship type already uses that name.';
                } else {
                    throw $e;
                }
            }
        }
    }
}

$scholarCount = $countFor($pdo, 'SELECT COUNT(*) FROM scholars WHERE scholarship_type_id = ?', $typeId);
$ruleCount = $countFor($pdo, 'SELECT COUNT(*) FROM rules WHERE scholarship_type_id = ?', $typeId);

$pageTitle = 'Edit Scholarship Type';
require_once __DIR__ . '/../includes/header.php';
?>

<p style="margin-bottom:1rem;"><a href="<?php echo $listUrl; ?>">&larr; Back to scholarship types</a></p>
<h1>Edit scholarship type</h1>

<div class="card">
    <p><small>Used by <?php echo $scholarCount; ?> scholar(s) and <?php echo $ruleCount; ?> rule(s). Renaming does not change any status.</small></p>
    <?php if ($message): ?><p class="form-error" style="margin-top:0.75rem;"><?php echo sanitize($message); ?></p><?php endif; ?>
    <?php if (isset($_GET['saved'])): ?><p class="form-success" style="margin-top:0.75rem;">Changes saved.</p><?php endif; ?>

    <form method="POST" action="<?php echo $editUrl; ?>">
        <?php echo csrfField(); ?>

        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="<?php echo h($form['name']); ?>" required>

        <label for="description">Description</label>
        <input type="text" id="description" name="description" value="<?php echo h($form['description']); ?>">

        <button type="submit">Save changes</button>
        <a class="btn" href="<?php echo $listUrl; ?>" style="background:var(--color-ink-soft);">Cancel</a>
    </form>
</div>

<div class="card">
    <h2>Delete this type</h2>
    <?php if ($scholarCount > 0): ?>
        <p>This type cannot be deleted while <?php echo $scholarCount; ?> scholar(s) use it. Move them to another type first (Edit profile on the scholar's page).</p>
    <?php else: ?>
        <p>No scholars use this type. Deleting it cannot be undone.</p>
        <form method="POST" action="<?php echo $editUrl; ?>">
            <?php echo csrfField(); ?>
            <button type="submit" name="delete" value="1" onclick="return confirm('Delete this scholarship type?');" style="background:#B3492B;">Delete type</button>
        </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>