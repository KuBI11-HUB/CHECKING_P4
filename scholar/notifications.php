<?php
$requiredRole = 'scholar';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();

$stmt = $pdo->prepare(
    'SELECT n.created_at, n.subject, n.type, n.send_status
     FROM notifications n
     JOIN scholars s ON s.id = n.scholar_id
     WHERE s.user_id = ?
     ORDER BY n.created_at DESC, n.id DESC'
);
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

$typeLabels = [
    'STATUS_CHANGE' => 'Status updates',
    'DEADLINE_APPROACHING' => 'Deadline reminders',
];
$grouped = [];
foreach ($notifications as $n) {
    $key = $n['type'] ?? 'OTHER';
    $grouped[$key][] = $n;
}

$pageTitle = 'Notifications';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Notifications</h1>

<?php if (!$notifications): ?>
<div class="card"><p>You have no notifications yet.</p></div>
<?php else: ?>
    <?php foreach ($typeLabels as $type => $heading): ?>
        <?php if (empty($grouped[$type])) { continue; } ?>
        <div class="card">
            <h2><?php echo h($heading); ?></h2>
            <table class="data-table">
                <thead>
                    <tr><th>Date</th><th>Message</th><th>Delivery</th></tr>
                </thead>
                <tbody>
                <?php foreach ($grouped[$type] as $n): ?>
                    <tr>
                        <td><?php echo h($n['created_at']); ?></td>
                        <td><?php echo h($n['subject']); ?></td>
                        <td><?php
                            $label = $n['send_status'] === 'SENT' ? 'Sent'
                                : ($n['send_status'] === 'FAILED' ? 'Could not send' : 'Queued');
                            echo h($label);
                        ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
    <?php
    $other = array_diff_key($grouped, $typeLabels);
    foreach ($other as $type => $rows):
    ?>
        <div class="card">
            <h2>Other notices</h2>
            <table class="data-table">
                <thead><tr><th>Date</th><th>Message</th><th>Delivery</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $n): ?>
                    <tr>
                        <td><?php echo h($n['created_at']); ?></td>
                        <td><?php echo h($n['subject']); ?></td>
                        <td><?php echo h($n['send_status']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>