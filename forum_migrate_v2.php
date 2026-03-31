<?php
require_once __DIR__ . '/helpers/functions.php';
requireLogin();
requireRole('superadmin');

$pdo = getDB();
$steps = [];
$errors = [];

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS forum_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type ENUM('like','reply','mention','pin','lock') NOT NULL,
        thread_id INT DEFAULT NULL,
        post_id INT DEFAULT NULL,
        triggered_by INT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        message TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE,
        FOREIGN KEY (post_id) REFERENCES forum_posts(id) ON DELETE CASCADE,
        FOREIGN KEY (triggered_by) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_read (user_id, is_read),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB");
    $steps[] = 'forum_notifications table created';

    $pdo->exec("CREATE TABLE IF NOT EXISTS forum_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        thread_id INT DEFAULT NULL,
        post_id INT DEFAULT NULL,
        file_name VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        file_type VARCHAR(100) NOT NULL,
        file_size INT NOT NULL DEFAULT 0,
        uploaded_by INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE,
        FOREIGN KEY (post_id) REFERENCES forum_posts(id) ON DELETE CASCADE,
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $steps[] = 'forum_attachments table created';

    $forumUploadDir = __DIR__ . '/uploads/forums';
    if (!is_dir($forumUploadDir)) {
        mkdir($forumUploadDir, 0755, true);
        $steps[] = 'uploads/forums directory created';
    } else {
        $steps[] = 'uploads/forums directory already exists';
    }

    auditLog('forum_migrate_v2', 'Forum notifications & attachments tables created');

} catch (Exception $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forum Migration v2</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="fas fa-database me-2"></i>Forum Migration v2 — Notifications & Attachments</h4>
                </div>
                <div class="card-body">
                    <?php if (empty($errors)): ?>
                    <div class="alert alert-success"><strong>Success!</strong> Migration completed.</div>
                    <?php else: ?>
                    <div class="alert alert-danger">
                        <strong>Errors:</strong>
                        <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
                    </div>
                    <?php endif; ?>
                    <h6>Steps:</h6>
                    <ul class="list-group mb-3">
                        <?php foreach ($steps as $step): ?>
                        <li class="list-group-item list-group-item-success"><i class="fas fa-check me-2"></i><?= htmlspecialchars($step) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?= BASE_URL ?>/forums.php" class="btn btn-primary">Go to Forums</a>
                    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary">Dashboard</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
