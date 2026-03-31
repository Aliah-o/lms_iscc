<?php
/**
 * ISCC LMS Database Migration Script v1.1
 * Run once: http://localhost/iscc-lms/migrate.php
 * Safe to run multiple times (idempotent).
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';

$errors = [];

// Auto-detect base path for asset URLs
$_mBasePath = rtrim(str_replace('\\', '/', str_replace(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '', str_replace('\\', '/', __DIR__))), '/');
$steps = [];

try {
    $pdo = getDB();
} catch (Exception $e) {
    die('<div style="font-family:Inter,sans-serif;text-align:center;padding:60px;"><h2>Database Connection Failed</h2><p>' . htmlspecialchars($e->getMessage()) . '</p></div>');
}

// Helper: check if column exists
function columnExists($pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) { return false; }
}

// Helper: check if table exists
function tableExists($pdo, $table) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) { return false; }
}

// Helper: check if index exists
function indexExists($pdo, $table, $index) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $stmt->execute([$table, $index]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) { return false; }
}

try {
    // ─── 1. lesson_attachments table (fixes fatal error in lessons.php) ───
    if (!tableExists($pdo, 'lesson_attachments')) {
        $pdo->exec("CREATE TABLE lesson_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lesson_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) DEFAULT NULL,
            original_name VARCHAR(255) NOT NULL,
            file_type VARCHAR(100) NOT NULL,
            file_size BIGINT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $steps[] = 'Created lesson_attachments table';
    } else {
        $steps[] = 'lesson_attachments table already exists';
    }

    if (!columnExists($pdo, 'lesson_attachments', 'file_path')) {
        $pdo->exec("ALTER TABLE lesson_attachments ADD COLUMN file_path VARCHAR(255) DEFAULT NULL AFTER file_name");
        $steps[] = 'Added file_path column to lesson_attachments';
    }

    // ─── 2. Missing columns on lessons table ───
    if (!columnExists($pdo, 'lessons', 'video_url')) {
        $pdo->exec("ALTER TABLE lessons ADD COLUMN video_url VARCHAR(500) DEFAULT NULL AFTER content");
        $steps[] = 'Added video_url column to lessons';
    }
    if (!columnExists($pdo, 'lessons', 'link_url')) {
        $pdo->exec("ALTER TABLE lessons ADD COLUMN link_url VARCHAR(500) DEFAULT NULL AFTER video_url");
        $steps[] = 'Added link_url column to lessons';
    }
    if (!columnExists($pdo, 'lessons', 'link_title')) {
        $pdo->exec("ALTER TABLE lessons ADD COLUMN link_title VARCHAR(200) DEFAULT NULL AFTER link_url");
        $steps[] = 'Added link_title column to lessons';
    }

    // ─── 3. grade_chain table (used by blockchain grade recording) ───
    if (!tableExists($pdo, 'grade_chain')) {
        $pdo->exec("CREATE TABLE grade_chain (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            class_id INT NOT NULL,
            course_code VARCHAR(20) NOT NULL,
            subject_name VARCHAR(200) NOT NULL,
            component VARCHAR(50) NOT NULL,
            grading_period VARCHAR(20) NOT NULL,
            score DECIMAL(5,2) DEFAULT 0,
            recorded_by INT NOT NULL,
            prev_hash VARCHAR(64) DEFAULT 'GENESIS',
            block_hash VARCHAR(64) NOT NULL,
            block_data TEXT,
            is_valid TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $steps[] = 'Created grade_chain table';
    } else {
        $steps[] = 'grade_chain table already exists';
    }

    // ─── 4. certificates table ───
    if (!tableExists($pdo, 'certificates')) {
        $pdo->exec("CREATE TABLE certificates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            class_id INT NOT NULL,
            course_code VARCHAR(20) NOT NULL,
            subject_name VARCHAR(200) NOT NULL,
            final_grade DECIMAL(5,2) DEFAULT 0,
            grade_status VARCHAR(10) DEFAULT 'PASSED',
            certificate_hash VARCHAR(64) UNIQUE NOT NULL,
            qr_data TEXT,
            issued_by INT NOT NULL,
            semester VARCHAR(30),
            academic_year VARCHAR(20),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE CASCADE,
            FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $steps[] = 'Created certificates table';
    } else {
        $steps[] = 'certificates table already exists';
    }

    // ─── 5. attendance table (if not auto-created yet) ───
    if (!tableExists($pdo, 'attendance')) {
        $pdo->exec("CREATE TABLE attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            student_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            status ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
            remarks VARCHAR(255) DEFAULT NULL,
            recorded_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_attendance (class_id, student_id, attendance_date),
            FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $steps[] = 'Created attendance table';
    } else {
        $steps[] = 'attendance table already exists';
    }

    // ─── 6. school_years table ───
    if (!tableExists($pdo, 'school_years')) {
        $pdo->exec("CREATE TABLE school_years (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(20) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status ENUM('active','archived') DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_sy_name (name)
        ) ENGINE=InnoDB");
        $steps[] = 'Created school_years table';

        // Seed default school year
        $pdo->exec("INSERT INTO school_years (name, start_date, end_date, status) VALUES ('2025-2026', '2025-08-01', '2026-06-10', 'active')");
        $steps[] = 'Seeded default school year 2025-2026';
    } else {
        $steps[] = 'school_years table already exists';
    }

    // ─── 7. Add school_year_id to instructor_classes ───
    if (!columnExists($pdo, 'instructor_classes', 'school_year_id')) {
        $pdo->exec("ALTER TABLE instructor_classes ADD COLUMN school_year_id INT DEFAULT NULL AFTER is_active");
        $steps[] = 'Added school_year_id column to instructor_classes';

        // Assign existing classes to the active school year
        $activeSY = $pdo->query("SELECT id FROM school_years WHERE status = 'active' ORDER BY end_date DESC LIMIT 1")->fetch();
        if ($activeSY) {
            $pdo->prepare("UPDATE instructor_classes SET school_year_id = ? WHERE school_year_id IS NULL")->execute([$activeSY['id']]);
            $steps[] = 'Assigned existing classes to active school year';
        }
    } else {
        $steps[] = 'school_year_id column already exists';
    }

    // ─── 8. graded_activities table ───
    if (!tableExists($pdo, 'graded_activities')) {
        $pdo->exec("CREATE TABLE graded_activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            title VARCHAR(200) NOT NULL,
            activity_type ENUM('quiz','lab_activity','exam') NOT NULL,
            max_score DECIMAL(5,2) DEFAULT 100,
            grading_period ENUM('midterm','final') DEFAULT 'midterm',
            created_by INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_class_type (class_id, activity_type, grading_period),
            FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $steps[] = 'Created graded_activities table';
    } else {
        $steps[] = 'graded_activities table already exists';
    }

    // ─── 9. graded_activity_scores table ───
    if (!tableExists($pdo, 'graded_activity_scores')) {
        $pdo->exec("CREATE TABLE graded_activity_scores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            activity_id INT NOT NULL,
            student_id INT NOT NULL,
            score DECIMAL(5,2) DEFAULT 0,
            updated_by INT DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_activity_score (activity_id, student_id),
            FOREIGN KEY (activity_id) REFERENCES graded_activities(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $steps[] = 'Created graded_activity_scores table';
    } else {
        $steps[] = 'graded_activity_scores table already exists';
    }

    // ─── 10. Ensure settings for school year ───
    $sySettings = [
        'sy_auto_archive' => '1',
        'theme_mode' => 'light',
        'theme_sidebar' => '#0F172A',
        'theme_navbar' => '#FFFFFF',
    ];
    foreach ($sySettings as $k => $v) {
        $chk = $pdo->prepare("SELECT 1 FROM settings WHERE setting_key = ?");
        $chk->execute([$k]);
        if (!$chk->fetch()) {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
        }
    }
    $steps[] = 'School year settings verified';

    // ─── 11. Add forum_karma column to users if missing ───
    if (!columnExists($pdo, 'users', 'forum_karma')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN forum_karma INT DEFAULT 0");
        $steps[] = 'Added forum_karma column to users';
    }

    if (!columnExists($pdo, 'users', 'plain_password')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN plain_password VARCHAR(255) DEFAULT NULL AFTER password");
        $steps[] = 'Added plain_password column to users';
    }

    if (!columnExists($pdo, 'users', 'student_id_no')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN student_id_no VARCHAR(10) DEFAULT NULL AFTER role");
        $steps[] = 'Added student_id_no column to users';
    }

    if (!columnExists($pdo, 'users', 'profile_picture')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL AFTER email");
        $steps[] = 'Added profile_picture column to users';
    }

    if (!columnExists($pdo, 'users', 'theme_mode')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN theme_mode ENUM('light','dark','custom') DEFAULT 'light' AFTER profile_picture");
        $steps[] = 'Added theme_mode column to users';
    }

    if (!columnExists($pdo, 'users', 'theme_accent')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN theme_accent VARCHAR(7) DEFAULT '#4F46E5' AFTER theme_mode");
        $steps[] = 'Added theme_accent column to users';
    }

    if (!columnExists($pdo, 'users', 'theme_sidebar')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN theme_sidebar VARCHAR(7) DEFAULT '#0F172A' AFTER theme_accent");
        $steps[] = 'Added theme_sidebar column to users';
    }

    if (!columnExists($pdo, 'users', 'theme_navbar')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN theme_navbar VARCHAR(7) DEFAULT '#FFFFFF' AFTER theme_sidebar");
        $steps[] = 'Added theme_navbar column to users';
    }

    if (!columnExists($pdo, 'instructor_classes', 'subject_image')) {
        $pdo->exec("ALTER TABLE instructor_classes ADD COLUMN subject_image VARCHAR(255) DEFAULT NULL AFTER description");
        $steps[] = 'Added subject_image column to instructor_classes';
    }

    if (!indexExists($pdo, 'users', 'unique_student_id_no')) {
        $pdo->exec("ALTER TABLE users ADD UNIQUE KEY unique_student_id_no (student_id_no)");
        $steps[] = 'Added unique_student_id_no index to users';
    }

    // ─── 12. Add submission columns to graded_activities ───
    $subCols = [
        'is_submittable' => "TINYINT(1) DEFAULT 0",
        'allow_late' => "TINYINT(1) DEFAULT 0",
        'allow_resubmit' => "TINYINT(1) DEFAULT 0",
        'open_date' => "DATETIME DEFAULT NULL",
        'due_date' => "DATETIME DEFAULT NULL",
        'close_date' => "DATETIME DEFAULT NULL",
        'late_penalty_type' => "ENUM('percentage','fixed') DEFAULT 'percentage'",
        'late_penalty_amount' => "DECIMAL(5,2) DEFAULT 0",
        'late_penalty_interval' => "ENUM('per_day','per_hour') DEFAULT 'per_day'",
        'late_penalty_max' => "DECIMAL(5,2) DEFAULT NULL",
        'description' => "TEXT DEFAULT NULL",
    ];
    $addedSubCols = 0;
    foreach ($subCols as $col => $def) {
        if (!columnExists($pdo, 'graded_activities', $col)) {
            $pdo->exec("ALTER TABLE graded_activities ADD COLUMN $col $def");
            $addedSubCols++;
        }
    }
    $steps[] = $addedSubCols > 0
        ? "Added $addedSubCols submission columns to graded_activities"
        : 'graded_activities submission columns already exist';

    // ─── 13. Create activity_submissions table ───
    if (!tableExists($pdo, 'activity_submissions')) {
        $pdo->exec("CREATE TABLE activity_submissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            activity_id INT NOT NULL,
            student_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            file_type VARCHAR(100) DEFAULT NULL,
            file_size INT DEFAULT 0,
            raw_score DECIMAL(5,2) DEFAULT NULL,
            late_penalty DECIMAL(5,2) DEFAULT NULL,
            final_score DECIMAL(5,2) DEFAULT NULL,
            feedback TEXT DEFAULT NULL,
            graded_by INT DEFAULT NULL,
            graded_at DATETIME DEFAULT NULL,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_late TINYINT(1) DEFAULT 0,
            status ENUM('submitted','graded','missing') DEFAULT 'submitted',
            version INT DEFAULT 1,
            INDEX idx_activity_student (activity_id, student_id),
            INDEX idx_student (student_id),
            FOREIGN KEY (activity_id) REFERENCES graded_activities(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $steps[] = 'Created activity_submissions table';
    } else {
        $steps[] = 'activity_submissions table already exists';
    }

    // ─── 14. Create submission_history table ───
    if (!tableExists($pdo, 'submission_history')) {
        $pdo->exec("CREATE TABLE submission_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            activity_id INT NOT NULL,
            student_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            file_type VARCHAR(100) DEFAULT NULL,
            file_size INT DEFAULT 0,
            version INT DEFAULT 1,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activity_student_hist (activity_id, student_id),
            FOREIGN KEY (activity_id) REFERENCES graded_activities(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $steps[] = 'Created submission_history table';
    } else {
        $steps[] = 'submission_history table already exists';
    }

    // ─── 15. Add grading_period column to attendance table & update unique key ───
    try {
        $pdo->query("SELECT grading_period FROM attendance LIMIT 1");
        $steps[] = 'attendance.grading_period column already exists';
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE attendance ADD COLUMN grading_period ENUM('midterm','final') NOT NULL DEFAULT 'midterm' AFTER status");
        $steps[] = 'Added grading_period column to attendance table';
    }
    // Update unique key to include grading_period (allows same-day records for different periods)
    try {
        $idxStmt = $pdo->query("SHOW INDEX FROM attendance WHERE Key_name = 'unique_attendance'");
        $idxCols = [];
        while ($idx = $idxStmt->fetch()) { $idxCols[] = $idx['Column_name']; }
        if (!in_array('grading_period', $idxCols)) {
            $pdo->exec("ALTER TABLE attendance DROP INDEX unique_attendance, ADD UNIQUE KEY unique_attendance (class_id, student_id, attendance_date, grading_period)");
            $steps[] = 'Updated attendance unique key to include grading_period';
        } else {
            $steps[] = 'Attendance unique key already includes grading_period';
        }
    } catch (Exception $e) {
        $steps[] = 'Attendance unique key update skipped: ' . $e->getMessage();
    }

    // ─── 16. Add link column to notifications table ───
    if (!columnExists($pdo, 'notifications', 'link')) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN link VARCHAR(500) DEFAULT NULL AFTER reference_id");
        $steps[] = 'Added link column to notifications table';
    } else {
        $steps[] = 'notifications.link column already exists';
    }

    // ─── 17. Add quiz settings columns (deadline, max_attempts, time_limit) ───
    $quizCols = [
        'deadline' => 'DATETIME DEFAULT NULL',
        'max_attempts' => 'INT DEFAULT 0',
        'time_limit' => 'INT DEFAULT 0',
    ];
    $addedQuizCols = 0;
    foreach ($quizCols as $col => $def) {
        if (!columnExists($pdo, 'quizzes', $col)) {
            $pdo->exec("ALTER TABLE quizzes ADD COLUMN $col $def");
            $addedQuizCols++;
        }
    }
    $steps[] = $addedQuizCols > 0
        ? "Added $addedQuizCols quiz settings columns (deadline, max_attempts, time_limit)"
        : 'Quiz settings columns already exist';

    $success = true;

} catch (Exception $e) {
    $errors[] = $e->getMessage();
    $success = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISCC LMS Migration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= $_mBasePath ?>/assets/css/style.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?= $_mBasePath ?>/assets/css/logo.png">
</head>
<body>
<div class="install-wrapper">
    <div class="install-card">
        <div class="text-center mb-4">
            <div style="width:60px;height:60px;border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;overflow:hidden;">
                <img src="<?= $_mBasePath ?>/assets/css/logo.png" alt="ISCC Logo" style="width:100%;height:100%;object-fit:contain;">
            </div>
            <h2>ISCC LMS Migration v1.1</h2>
            <p class="text-muted">Database schema update for new features</p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>
            <?php foreach ($errors as $err): ?><div><?= htmlspecialchars($err) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i> Migration completed successfully!</div>
        <?php endif; ?>

        <div class="mb-4">
            <?php foreach ($steps as $step): ?>
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fas fa-check-circle text-success"></i>
                <span style="font-size:0.88rem;"><?= htmlspecialchars($step) ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="card mb-3" style="border:1px solid #E2E8F0;">
            <div class="card-body" style="font-size:0.85rem;">
                <h6 class="fw-bold mb-2">What was updated:</h6>
                <ul class="mb-0">
                    <li>lesson_attachments table (fixes lessons.php crash)</li>
                    <li>Missing lesson columns (video_url, link_url, link_title)</li>
                    <li>grade_chain &amp; certificates tables</li>
                    <li>school_years table + instructor_classes.school_year_id</li>
                    <li>graded_activities &amp; graded_activity_scores tables</li>
                    <li>Default school year 2025&ndash;2026 seeded</li>
                    <li>Submission columns on graded_activities (deadline, late penalty config)</li>
                    <li>activity_submissions &amp; submission_history tables</li>
                    <li>attendance.grading_period column (per-period attendance tracking)</li>
                    <li>Final Grade = (Midterm WA + Tentative Final WA) &divide; 2 computation</li>
                    <li>notifications.link column (clickable notification links)</li>
                    <li>Quiz settings columns: deadline, max_attempts, time_limit</li>
                </ul>
            </div>
        </div>

        <a href="<?= $_mBasePath ?>/dashboard.php" class="btn btn-primary-gradient w-100 justify-content-center"><i class="fas fa-arrow-right me-1"></i>Go to Dashboard</a>
    </div>
</div>
</body>
</html>
