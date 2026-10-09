<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');

    if ($name === '') {
        $message = 'Name is required.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO scholarship_types (name, description) VALUES (?, ?)');
        $stmt->execute([$name, $description]);
        redirectTo('/GRANTED/admin/scholarship_types.php');
    }
}

$types = $pdo->query('SELECT * FROM scholarship_types ORDER BY name')->fetchAll();

$pageTitle = 'Scholarship Types';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Scholarship types</h1>

<div class="card">
    <h2>Add a new type</h2>
    <?php if ($message): ?><p class="form-error"><?php echo sanitize($message); ?></p><?php endif; ?>
    <form method="POST" action="">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" placeholder="e.g. Academic Scholarship, CHED, DOST" required>
        <label for="description">Description</label>
        <input type="text" id="description" name="description" placeholder="Short description">
        <button type="submit">Add type</button>
    </form>
</div>

<div class="card">
    <h2>Existing types</h2>
    <table class="data-table">
        <thead><tr><th>Name</th><th>Description</th></tr></thead>
        <tbody>
        <?php foreach ($types as $t): ?>
            <tr>
                <td><?php echo sanitize($t['name']); ?></td>
                <td><?php echo sanitize($t['description']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($types)): ?>
            <tr><td colspan="2">No scholarship types yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
