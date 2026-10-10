<?php
$requiredRole = 'scholar';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();

// Only the logged-in scholar's own rows (found through the session user). error_message is never selected.
$stmt = $pdo->prepare(
    'SELECT n.created_at, n.subject, n.type, n.send_status
     FROM notifications n
     JOIN scholars s ON s.id = n.scholar_id
     WHERE s.user_id = ?
     ORDER BY n.created_at DESC, n.id DESC'
);
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

$typeLabels = ['STATUS_CHANGE' => 'Status change', 'DEADLINE_APPROACHING' => 'Deadline reminder'];

$pageTitle = 'Notifications';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Notifications</h1>

<div class="card">
    <table class="data-table">
        <thead>
            <tr><th>Date</th><th>Subject</th><th>Type</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ($notifications as $n): ?>
            <tr>
                <td><?php echo sanitize($n['created_at']); ?></td>
                <td><?php echo sanitize($n['subject']); ?></td>
                <td><?php echo sanitize($typeLabels[$n['type']] ?? $n['type']); ?></td>
                <td><span class="status-badge <?php
                    echo $n['send_status'] === 'SENT' ? 'status-badge--pass'
                        : ($n['send_status'] === 'FAILED' ? 'status-badge--fail' : 'status-badge--pending');
                ?>"><?php echo sanitize($n['send_status']); ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($notifications)): ?>
            <tr><td colspan="4">You have no notifications yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
