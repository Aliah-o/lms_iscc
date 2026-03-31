<?php
require_once __DIR__ . '/helpers/functions.php';
requireLogin();
requireRole('superadmin');

$pdo = getDB();
$steps = [];
$errors = [];

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS forum_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        description TEXT,
        sort_order INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
    $steps[] = 'forum_categories table created';

    $pdo->exec("CREATE TABLE IF NOT EXISTS forum_threads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT NOT NULL,
        title VARCHAR(300) NOT NULL,
        body TEXT NOT NULL,
        created_by INT NOT NULL,
        is_anonymous TINYINT(1) DEFAULT 0,
        status ENUM('active','hidden','deleted') DEFAULT 'active',
        is_locked TINYINT(1) DEFAULT 0,
        is_pinned TINYINT(1) DEFAULT 0,
        view_count INT DEFAULT 0,
        reply_count INT DEFAULT 0,
        last_reply_at DATETIME DEFAULT NULL,
        last_reply_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES forum_categories(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (last_reply_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
    $steps[] = 'forum_threads table created';

    $pdo->exec("CREATE TABLE IF NOT EXISTS forum_posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        thread_id INT NOT NULL,
        body TEXT NOT NULL,
        created_by INT NOT NULL,
        is_anonymous TINYINT(1) DEFAULT 0,
        status ENUM('active','hidden','deleted') DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $steps[] = 'forum_posts table created';

    $pdo->exec("CREATE TABLE IF NOT EXISTS forum_thread_likes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        thread_id INT NOT NULL,
        user_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_like (thread_id, user_id),
        FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $steps[] = 'forum_thread_likes table created';

    $pdo->exec("CREATE TABLE IF NOT EXISTS forum_post_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        thread_id INT DEFAULT NULL,
        post_id INT DEFAULT NULL,
        reported_by INT NOT NULL,
        reason TEXT NOT NULL,
        status ENUM('pending','reviewed','dismissed') DEFAULT 'pending',
        reviewed_by INT DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE,
        FOREIGN KEY (post_id) REFERENCES forum_posts(id) ON DELETE CASCADE,
        FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
    $steps[] = 'forum_post_reports table created';

    $check = $pdo->query("SELECT COUNT(*) FROM forum_categories")->fetchColumn();
    if ($check == 0) {
        $pdo->exec("INSERT INTO forum_categories (name, description, sort_order) VALUES
            ('General Discussion', 'Talk about anything related to campus life and learning.', 1),
            ('Academic Help', 'Ask questions and get help with your studies.', 2),
            ('Announcements', 'Important announcements from staff and administration.', 3),
            ('Off-Topic', 'Casual conversations and fun topics.', 4)
        ");
        $steps[] = 'Default categories seeded';
    }

    auditLog('forum_install', 'Forum module tables created/verified');

} catch (Exception $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forum Module Installation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="fas fa-comments me-2"></i>Forum Module Installation</h4>
                </div>
                <div class="card-body">
                    <?php if (empty($errors)): ?>
                    <div class="alert alert-success">
                        <strong>Success!</strong> Forum module installed successfully.
                    </div>
                    <?php else: ?>
                    <div class="alert alert-danger">
                        <strong>Errors occurred:</strong>
                        <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
                    </div>
                    <?php endif; ?>

                    <h6>Steps completed:</h6>
                    <ul class="list-group mb-3">
                        <?php foreach ($steps as $step): ?>
                        <li class="list-group-item list-group-item-success"><i class="fas fa-check me-2"></i><?= htmlspecialchars($step) ?></li>
                        <?php endforeach; ?>
                    </ul>

                    <a href="<?= BASE_URL ?>/forums.php" class="btn btn-primary">Go to Forums</a>
                    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
