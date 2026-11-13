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
            if ($name === '' || strlen($name) > 100) {
                throw new InvalidArgumentException('Enter a program name of 1–100 characters.');
            }
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE programs SET name = ? WHERE id = ?');
                $stmt->execute([$name, $id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO programs (name) VALUES (?)');
                $stmt->execute([$name]);
            }
            setFlash('Program saved.');
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE programs SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() !== 1) {
                throw new InvalidArgumentException('Program not found.');
            }
            setFlash('Program status updated.');
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        redirectTo('/GRANTED/admin/programs.php');
    } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            $message = 'A program with that name already exists.';
        } else {
            throw $e;
        }
    }
}

$programs = $pdo->query(
    'SELECT p.*, (SELECT COUNT(*) FROM scholars s WHERE s.program_id = p.id) AS scholar_count
     FROM programs p ORDER BY p.is_active DESC, p.name'
)->fetchAll();
$pageTitle = 'Programs';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="programs-page">
    <header class="page-head">
        <div>
            <h1>Programs</h1>
            <p class="page-head__sub">Manage the academic programs used for scholar records.</p>
        </div>
    </header>
    <?php echo flashBox(takeFlash()); ?>
    <?php if ($message): ?><p class="form-error"><?php echo h($message); ?></p><?php endif; ?>

    <section class="card programs-add-card" aria-labelledby="add-program-heading">
        <h2 id="add-program-heading">Add a program</h2>
        <form method="POST" class="program-create-form">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="save">
            <div class="program-create-form__field">
                <label for="name">Program name</label>
                <input id="name" name="name" maxlength="100" required>
            </div>
            <button type="submit">Add program</button>
        </form>
    </section>

    <section class="card programs-catalogue" aria-labelledby="program-catalogue-heading">
        <div class="programs-catalogue__heading">
            <h2 id="program-catalogue-heading">Program catalogue</h2>
            <span class="programs-count"><?php echo count($programs); ?> <?php echo count($programs) === 1 ? 'program' : 'programs'; ?></span>
        </div>
        <?php if (!$programs): ?><p>No programs yet. Add the office-approved program names above.</p><?php else: ?>
        <div class="table-scroll">
            <table class="data-table programs-table">
                <thead><tr><th>Program</th><th>Scholars</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($programs as $program): ?>
                        <?php $isActive = (int)$program['is_active'] === 1; ?>
                        <tr>
                            <td>
                                <form method="POST" class="program-edit-form" data-show-save-on-change>
                                    <?php echo csrfField(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)$program['id']; ?>">
                                    <input aria-label="Program name for <?php echo h($program['name']); ?>" name="name" maxlength="100" value="<?php echo h($program['name']); ?>" required>
                                    <button class="btn btn--secondary catalogue-edit-save" type="submit" data-change-save hidden>Save</button>
                                </form>
                            </td>
                            <td><?php echo (int)$program['scholar_count']; ?></td>
                            <td><span class="program-status <?php echo $isActive ? 'program-status--active' : 'program-status--inactive'; ?>"><?php echo $isActive ? 'Active' : 'Inactive'; ?></span></td>
                            <td>
                                <form method="POST" class="program-action-form">
                                    <?php echo csrfField(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$program['id']; ?>">
                                    <button class="btn btn--secondary" type="submit"><?php echo $isActive ? 'Deactivate' : 'Reactivate'; ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
