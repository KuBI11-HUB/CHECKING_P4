<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
            $description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
            $recurring = (($_POST['is_recurring'] ?? '') === '1') ? 1 : 0;
            if ($name === '' || strlen($name) > 100 || strlen($description) > 65535) {
                throw new InvalidArgumentException('Enter a requirement name of 1–100 characters and a valid description.');
            }
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE requirements SET name = ?, description = ?, is_recurring = ? WHERE id = ?');
                $stmt->execute([$name, $description !== '' ? $description : null, $recurring, $id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO requirements (name, description, is_recurring) VALUES (?, ?, ?)');
                $stmt->execute([$name, $description !== '' ? $description : null, $recurring]);
            }
            setFlash('Requirement saved.');
        } elseif ($action === 'toggle') {
            $stmt = $pdo->prepare('UPDATE requirements SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?');
            $stmt->execute([(int)($_POST['id'] ?? 0)]);
            if ($stmt->rowCount() !== 1) {
                throw new InvalidArgumentException('Requirement not found.');
            }
            setFlash('Requirement status updated.');
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        redirectTo('/GRANTED/admin/requirements.php');
    } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            $message = 'A requirement with that name already exists.';
        } else {
            throw $e;
        }
    }
}

$requirements = $pdo->query(
    "SELECT r.*,
        (SELECT COUNT(*) FROM documents d WHERE d.requirement_id = r.id) AS document_count,
        (SELECT COUNT(*) FROM rules ru WHERE ru.requirement_id = r.id) AS rule_count
     FROM requirements r ORDER BY r.is_active DESC, r.name"
)->fetchAll();
$pageTitle = 'Requirements';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Requirements</h1>
<?php echo flashBox(takeFlash()); ?>
<?php if ($message): ?><p class="form-error"><?php echo h($message); ?></p><?php endif; ?>

<div class="card">
    <h2>Add requirement</h2>
    <p>Use office-approved names. Do not map old document labels unless their equivalence is confirmed.</p>
    <form method="POST">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="save">
        <label for="name">Requirement name</label><input id="name" name="name" maxlength="100" required>
        <label for="description">Description (optional)</label><textarea id="description" name="description" rows="3"></textarea>
        <label for="is_recurring">Frequency</label>
        <select id="is_recurring" name="is_recurring">
            <option value="1">Recurring — checked each term</option>
            <option value="0">Once-only — verified document can be reused</option>
        </select>
        <button type="submit">Add requirement</button>
    </form>
</div>

<div class="card">
    <h2>Catalogue</h2>
    <?php if (!$requirements): ?><p>No requirements have been added yet.</p><?php else: ?>
    <table class="data-table"><thead><tr><th>Requirement</th><th>Frequency</th><th>Documents / rules</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($requirements as $requirement): ?>
            <tr>
                <td><form method="POST" class="requirement-edit-form" data-show-save-on-change>
                    <?php echo csrfField(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)$requirement['id']; ?>">
                    <input aria-label="Requirement name" name="name" maxlength="100" value="<?php echo h($requirement['name']); ?>" required>
                    <textarea aria-label="Requirement description" name="description" rows="2"><?php echo h($requirement['description'] ?? ''); ?></textarea>
                    <select aria-label="Requirement frequency" name="is_recurring">
                        <option value="1" <?php echo (int)$requirement['is_recurring'] === 1 ? 'selected' : ''; ?>>Recurring</option>
                        <option value="0" <?php echo (int)$requirement['is_recurring'] === 0 ? 'selected' : ''; ?>>Once-only</option>
                    </select>
                    <button class="btn btn--secondary catalogue-edit-save" type="submit" data-change-save hidden>Save</button>
                </form></td>
                <td><?php echo (int)$requirement['is_recurring'] === 1 ? 'Recurring' : 'Once-only'; ?></td>
                <td><?php echo (int)$requirement['document_count']; ?> / <?php echo (int)$requirement['rule_count']; ?></td>
                <td><?php echo (int)$requirement['is_active'] === 1 ? 'Active' : 'Inactive'; ?></td>
                <td><form method="POST">
                    <?php echo csrfField(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$requirement['id']; ?>">
                    <button class="btn btn--secondary" type="submit"><?php echo (int)$requirement['is_active'] === 1 ? 'Deactivate' : 'Reactivate'; ?></button>
                </form></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
