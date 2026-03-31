<?php
require_once __DIR__ . '/helpers/functions.php';
requireLogin();
requireRole('superadmin');

$pdo = getDB();
$steps = [];
$errors = [];

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS forum_karma INT DEFAULT 0");
    $steps[] = 'Added forum_karma column to users table';
} catch (Exception $e) {
    try {
        $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'forum_karma'");
        if ($check->rowCount() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN forum_karma INT DEFAULT 0");
            $steps[] = 'Added forum_karma column to users table';
        } else {
            $steps[] = 'forum_karma column already exists';
        }
    } catch (Exception $e2) {
        $errors[] = 'forum_karma: ' . $e2->getMessage();
    }
}

try {
    $check = $pdo->query("SHOW COLUMNS FROM forum_threads LIKE 'anon_display_name'");
    if ($check->rowCount() === 0) {
        $pdo->exec("ALTER TABLE forum_threads ADD COLUMN anon_display_name VARCHAR(100) DEFAULT NULL");
        $steps[] = 'Added anon_display_name column to forum_threads table';
    } else {
        $steps[] = 'anon_display_name column already exists in forum_threads';
    }
} catch (Exception $e) {
    $errors[] = 'forum_threads anon_display_name: ' . $e->getMessage();
}

try {
    $check = $pdo->query("SHOW COLUMNS FROM forum_posts LIKE 'anon_display_name'");
    if ($check->rowCount() === 0) {
        $pdo->exec("ALTER TABLE forum_posts ADD COLUMN anon_display_name VARCHAR(100) DEFAULT NULL");
        $steps[] = 'Added anon_display_name column to forum_posts table';
    } else {
        $steps[] = 'anon_display_name column already exists in forum_posts';
    }
} catch (Exception $e) {
    $errors[] = 'forum_posts anon_display_name: ' . $e->getMessage();
}

try {
    $threads = $pdo->query("SELECT DISTINCT created_by FROM forum_threads WHERE status = 'active'");
    foreach ($threads->fetchAll() as $row) {
        $userId = $row['created_by'];
        $threadCount = $pdo->prepare("SELECT COUNT(*) FROM forum_threads WHERE created_by = ? AND status = 'active'");
        $threadCount->execute([$userId]);
        $tc = (int)$threadCount->fetchColumn();

        $postCount = $pdo->prepare("SELECT COUNT(*) FROM forum_posts WHERE created_by = ? AND status = 'active'");
        $postCount->execute([$userId]);
        $pc = (int)$postCount->fetchColumn();

        $likeCount = $pdo->prepare("
            SELECT COUNT(*) FROM forum_thread_likes ftl
            JOIN forum_threads ft ON ftl.thread_id = ft.id
            WHERE ft.created_by = ?
        ");
        $likeCount->execute([$userId]);
        $lc = (int)$likeCount->fetchColumn();

        $karma = ($tc * 5) + ($pc * 2) + ($lc * 1);
        $pdo->prepare("UPDATE users SET forum_karma = ? WHERE id = ?")->execute([$karma, $userId]);
    }
    $steps[] = 'Backfilled karma for existing forum users';
} catch (Exception $e) {
    $errors[] = 'Karma backfill: ' . $e->getMessage();
}

try {
    $pdo->exec("ALTER TABLE forum_notifications MODIFY COLUMN type ENUM('like','reply','mention','pin','lock','system') NOT NULL");
    $steps[] = 'Updated forum_notifications type enum to include system';
} catch (Exception $e) {
    $errors[] = 'forum_notifications type update: ' . $e->getMessage();
}

auditLog('forum_migrate_v3', 'Forum v3 migration: karma, anon names, notification types');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forum Migration v3</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="fas fa-database me-2"></i>Forum Migration v3</h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <strong>Errors:</strong>
                            <ul class="mb-0 mt-1">
                                <?php foreach ($errors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($steps)): ?>
                        <div class="alert alert-success">
                            <strong>Completed Steps:</strong>
                            <ul class="mb-0 mt-1">
                                <?php foreach ($steps as $step): ?>
                                <li><?= htmlspecialchars($step) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/forums.php" class="btn btn-primary">Go to Forums</a>
                    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary">Dashboard</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
