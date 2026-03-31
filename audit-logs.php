<?php
$pageTitle = 'Audit Logs';
require_once __DIR__ . '/helpers/functions.php';
requireRole('superadmin', 'staff');
$pdo = getDB();
$user = currentUser();
$breadcrumbPills = ['Administration', 'Audit Logs'];

$filterAction = $_GET['action_filter'] ?? '';
$filterUser = intval($_GET['user_filter'] ?? 0);
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = "1=1";
$params = [];

if ($filterAction) {
    $where .= " AND al.action LIKE ?";
    $params[] = "%$filterAction%";
}
if ($filterUser) {
    $where .= " AND al.user_id = ?";
    $params[] = $filterUser;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al WHERE $where");
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRecords / $perPage));

$stmt = $pdo->prepare("SELECT al.*, u.first_name, u.last_name, u.username, u.role as user_role 
    FROM audit_logs al 
    LEFT JOIN users u ON al.user_id = u.id 
    WHERE $where 
    ORDER BY al.created_at DESC 
    LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

$logUsers = $pdo->query("SELECT DISTINCT u.id, u.first_name, u.last_name, u.username FROM users u JOIN audit_logs al ON al.user_id = u.id ORDER BY u.last_name")->fetchAll();

require_once __DIR__ . '/views/layouts/header.php';
?>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Filter by Action</label>
                <select name="action_filter" class="form-select">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $a): ?>
                    <option value="<?= e($a) ?>" <?= $filterAction === $a ? 'selected' : '' ?>><?= e($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Filter by User</label>
                <select name="user_filter" class="form-select">
                    <option value="">All Users</option>
                    <?php foreach ($logUsers as $lu): ?>
                    <option value="<?= $lu['id'] ?>" <?= $filterUser === $lu['id'] ? 'selected' : '' ?>><?= e($lu['last_name'] . ', ' . $lu['first_name']) ?> (<?= e($lu['username']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary-gradient flex-grow-1"><i class="fas fa-filter me-1"></i>Filter</button>
                <a href="<?= BASE_URL ?>/audit-logs.php" class="btn btn-outline-secondary"><i class="fas fa-times"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span><i class="fas fa-clipboard-list me-2"></i>Audit Logs</span>
        <span class="text-muted" style="font-size:0.82rem;"><?= number_format($totalRecords) ?> records</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-modern mb-0">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>IP Address</th>
                        <th>Date/Time</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="6" class="text-center py-4 text-muted">No logs found.</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="text-muted" style="font-size:0.78rem;"><?= $log['id'] ?></td>
                    <td>
                        <?php if ($log['first_name']): ?>
                        <div class="fw-bold" style="font-size:0.85rem;"><?= e($log['first_name'] . ' ' . $log['last_name']) ?></div>
                        <div style="font-size:0.72rem;color:var(--gray-400);"><?= e($log['username']) ?> • <span class="badge-role <?= e($log['user_role']) ?>"><?= e(ucfirst($log['user_role'])) ?></span></div>
                        <?php else: ?>
                        <span class="text-muted">System</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $actionColors = [
                            'user_login' => 'success', 'user_logout' => 'secondary',
                            'user_created' => 'primary', 'user_toggled' => 'warning',
                            'section_created' => 'info', 'section_toggled' => 'warning',
                            'student_assigned' => 'primary', 'instructor_assigned' => 'primary',
                            'student_enrolled' => 'success', 'lesson_created' => 'info',
                            'node_created' => 'info', 'node_completed' => 'success',
                            'quiz_created' => 'info', 'quiz_completed' => 'success',
                            'badge_created' => 'warning', 'settings_updated' => 'dark',
                            'system_install' => 'danger',
                        ];
                        $color = $actionColors[$log['action']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $color ?>"><?= e($log['action']) ?></span>
                    </td>
                    <td style="font-size:0.82rem;color:var(--gray-600);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($log['details']) ?></td>
                    <td style="font-size:0.78rem;font-family:monospace;color:var(--gray-500);"><?= e($log['ip_address']) ?></td>
                    <td style="font-size:0.78rem;color:var(--gray-500);white-space:nowrap;"><?= date('M d, Y g:i:sa', strtotime($log['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-4 d-flex justify-content-center">
    <ul class="pagination">
        <?php if ($page > 1): ?>
        <li class="page-item"><a class="page-link" href="<?= BASE_URL ?>/audit-logs.php?page=<?= $page - 1 ?>&action_filter=<?= urlencode($filterAction) ?>&user_filter=<?= $filterUser ?>"><i class="fas fa-chevron-left"></i></a></li>
        <?php endif; ?>
        <?php
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        for ($p = $startPage; $p <= $endPage; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="<?= BASE_URL ?>/audit-logs.php?page=<?= $p ?>&action_filter=<?= urlencode($filterAction) ?>&user_filter=<?= $filterUser ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <li class="page-item"><a class="page-link" href="<?= BASE_URL ?>/audit-logs.php?page=<?= $page + 1 ?>&action_filter=<?= urlencode($filterAction) ?>&user_filter=<?= $filterUser ?>"><i class="fas fa-chevron-right"></i></a></li>
        <?php endif; ?>
    </ul>
</nav>
<?php endif; ?>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
