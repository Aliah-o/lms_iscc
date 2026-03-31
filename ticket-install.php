<?php
require_once __DIR__ . '/helpers/functions.php';
requireLogin();
requireRole('superadmin');

$pdo = getDB();
$steps = [];
$errors = [];

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_number VARCHAR(20) NOT NULL,
        user_id INT NOT NULL,
        category ENUM('bug','feature','account','grades','classes','quiz','general') DEFAULT 'general',
        priority ENUM('low','medium','high','critical') DEFAULT 'medium',
        subject VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
        assigned_to INT DEFAULT NULL,
        resolution_notes TEXT DEFAULT NULL,
        resolved_at DATETIME DEFAULT NULL,
        closed_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_ticket (ticket_number),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_status (status),
        INDEX idx_user (user_id),
        INDEX idx_priority (priority),
        INDEX idx_category (category)
    ) ENGINE=InnoDB");
    $steps[] = '✅ support_tickets table created';

    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_replies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        is_internal TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_ticket (ticket_id)
    ) ENGINE=InnoDB");
    $steps[] = '✅ ticket_replies table created';

    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        reply_id INT DEFAULT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_size INT DEFAULT 0,
        uploaded_by INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_ticket (ticket_id)
    ) ENGINE=InnoDB");
    $steps[] = '✅ ticket_attachments table created';

    $uploadDir = __DIR__ . '/uploads/tickets';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
        $steps[] = '✅ uploads/tickets directory created';
    } else {
        $steps[] = '✅ uploads/tickets directory already exists';
    }

    auditLog('ticket_install', 'Support Ticket tables installed');
} catch (Exception $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ticket System Install — ISCC LMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm border-0">
                <div class="card-header text-white" style="background:linear-gradient(135deg,#e74c3c,#c0392b);">
                    <h4 class="mb-0"><i class="fas fa-ticket-alt me-2"></i>Support Ticket System — Database Migration</h4>
                </div>
                <div class="card-body">
                    <?php if (empty($errors)): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><strong>Success!</strong> All tables created. The ticketing system is now ready.</div>
                    <?php else: ?>
                    <div class="alert alert-danger">
                        <strong><i class="fas fa-exclamation-triangle me-2"></i>Errors:</strong>
                        <ul class="mb-0 mt-2"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
                    </div>
                    <?php endif; ?>
                    <h6 class="fw-bold mb-3">Migration Steps:</h6>
                    <ul class="list-group mb-4">
                        <?php foreach ($steps as $step): ?>
                        <li class="list-group-item list-group-item-success"><?= $step ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="d-flex gap-2">
                        <a href="<?= BASE_URL ?>/tickets.php" class="btn btn-primary"><i class="fas fa-ticket-alt me-1"></i>Go to Tickets</a>
                        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary"><i class="fas fa-home me-1"></i>Dashboard</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
