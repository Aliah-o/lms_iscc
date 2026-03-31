<?php
require_once __DIR__ . '/helpers/functions.php';
requireLogin();
requireRole('superadmin');

$pdo = getDB();
$steps = [];
$errors = [];

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kahoot_games (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        class_id INT DEFAULT NULL,
        created_by INT NOT NULL,
        game_mode ENUM('live','practice') DEFAULT 'live',
        time_limit INT DEFAULT 20 COMMENT 'seconds per question',
        status ENUM('draft','ready','live','completed','archived') DEFAULT 'draft',
        game_pin VARCHAR(6) DEFAULT NULL,
        cover_image VARCHAR(255) DEFAULT NULL,
        shuffle_questions TINYINT(1) DEFAULT 0,
        shuffle_choices TINYINT(1) DEFAULT 0,
        show_leaderboard TINYINT(1) DEFAULT 1,
        max_participants INT DEFAULT 100,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_pin (game_pin),
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE SET NULL,
        INDEX idx_status (status),
        INDEX idx_created_by (created_by)
    ) ENGINE=InnoDB");
    $steps[] = '✅ kahoot_games table created';

    $pdo->exec("CREATE TABLE IF NOT EXISTS kahoot_questions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game_id INT NOT NULL,
        question_text TEXT NOT NULL,
        question_type ENUM('multiple_choice','word_scramble') DEFAULT 'multiple_choice',
        correct_answer VARCHAR(255) DEFAULT NULL,
        question_image VARCHAR(255) DEFAULT NULL,
        question_order INT DEFAULT 0,
        points INT DEFAULT 1000,
        time_limit INT DEFAULT NULL COMMENT 'override per-question, NULL = use game default',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (game_id) REFERENCES kahoot_games(id) ON DELETE CASCADE,
        INDEX idx_game_order (game_id, question_order)
    ) ENGINE=InnoDB");
    $steps[] = '✅ kahoot_questions table created';

    $pdo->exec("CREATE TABLE IF NOT EXISTS kahoot_choices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        question_id INT NOT NULL,
        choice_label CHAR(1) NOT NULL COMMENT 'A, B, C, or D',
        choice_text VARCHAR(500) NOT NULL,
        is_correct TINYINT(1) DEFAULT 0,
        FOREIGN KEY (question_id) REFERENCES kahoot_questions(id) ON DELETE CASCADE,
        INDEX idx_question (question_id)
    ) ENGINE=InnoDB");
    $steps[] = '✅ kahoot_choices table created';

    $pdo->exec("CREATE TABLE IF NOT EXISTS kahoot_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game_id INT NOT NULL,
        host_id INT NOT NULL,
        status ENUM('lobby','playing','reviewing','finished') DEFAULT 'lobby',
        current_question INT DEFAULT 0,
        question_started_at DATETIME DEFAULT NULL,
        started_at DATETIME DEFAULT NULL,
        ended_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (game_id) REFERENCES kahoot_games(id) ON DELETE CASCADE,
        FOREIGN KEY (host_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_status (status),
        INDEX idx_game (game_id)
    ) ENGINE=InnoDB");
    $steps[] = '✅ kahoot_sessions table created';

    $pdo->exec("CREATE TABLE IF NOT EXISTS kahoot_participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        user_id INT NOT NULL,
        nickname VARCHAR(50) DEFAULT NULL,
        score INT DEFAULT 0,
        correct_count INT DEFAULT 0,
        streak INT DEFAULT 0,
        joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_session_user (session_id, user_id),
        FOREIGN KEY (session_id) REFERENCES kahoot_sessions(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_session_score (session_id, score DESC)
    ) ENGINE=InnoDB");
    $steps[] = '✅ kahoot_participants table created';

    $pdo->exec("CREATE TABLE IF NOT EXISTS kahoot_answers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        participant_id INT NOT NULL,
        question_id INT NOT NULL,
        choice_id INT DEFAULT NULL,
        answer_text VARCHAR(255) DEFAULT NULL,
        is_correct TINYINT(1) DEFAULT 0,
        points_earned INT DEFAULT 0,
        time_taken DECIMAL(6,2) DEFAULT NULL COMMENT 'seconds',
        answered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_answer (session_id, participant_id, question_id),
        FOREIGN KEY (session_id) REFERENCES kahoot_sessions(id) ON DELETE CASCADE,
        FOREIGN KEY (participant_id) REFERENCES kahoot_participants(id) ON DELETE CASCADE,
        FOREIGN KEY (question_id) REFERENCES kahoot_questions(id) ON DELETE CASCADE,
        FOREIGN KEY (choice_id) REFERENCES kahoot_choices(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
    $steps[] = '✅ kahoot_answers table created';

    $uploadDir = __DIR__ . '/uploads/kahoot';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
        $steps[] = '✅ uploads/kahoot directory created';
    } else {
        $steps[] = '✅ uploads/kahoot directory already exists';
    }

    auditLog('kahoot_install', 'Kahoot Live Quiz tables installed');
} catch (Exception $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Kahoot Install — ISCC LMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm border-0">
                <div class="card-header text-white" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);">
                    <h4 class="mb-0"><i class="fas fa-gamepad me-2"></i>Kahoot Live Quiz — Database Migration</h4>
                </div>
                <div class="card-body">
                    <?php if (empty($errors)): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><strong>Success!</strong> All tables created. You can now use the Live Quiz feature.</div>
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
                        <a href="<?= BASE_URL ?>/kahoot-games.php" class="btn btn-primary"><i class="fas fa-gamepad me-1"></i>Go to Live Quiz</a>
                        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary"><i class="fas fa-home me-1"></i>Dashboard</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
