<?php
require_once __DIR__ . '/config/constants.php';

$lockFile = __DIR__ . '/installed.lock';
if (file_exists($lockFile)) {
    // Auto-detect base path for redirect
    $_basePath = rtrim(str_replace('\\', '/', str_replace(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '', str_replace('\\', '/', __DIR__))), '/');
    die('<div style="font-family:Inter,sans-serif;text-align:center;padding:60px;"><h2>Already Installed</h2><p>Remove <code>installed.lock</code> to reinstall.</p><a href="' . $_basePath . '/login.php">Go to Login</a></div>');
}

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'iscc_lms';
$errors = [];
$success = false;
$steps = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = new PDO("mysql:host=$dbHost", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$dbName`");
        $steps[] = 'Database created';

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("DROP TABLE IF EXISTS notifications, class_join_requests, student_completed_subjects, class_grade_weights, grades, badge_earns, badges, quiz_answers, quiz_attempts, quiz_questions, quizzes, knowledge_node_progress, knowledge_nodes, lessons, class_enrollments, instructor_classes, student_assignments, sections, users, settings, audit_logs, attendance, meetings, kahoot_games, kahoot_questions, kahoot_sessions, kahoot_responses, kahoot_scores, forum_categories, forum_threads, forum_posts, forum_reactions, tickets, ticket_replies, lesson_attachments, lesson_comments, teacher_classes");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        $steps[] = 'Cleaned existing tables';

        $pdo->exec("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            plain_password VARCHAR(255) DEFAULT NULL,
            email VARCHAR(100),
            profile_picture VARCHAR(255) DEFAULT NULL,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            role ENUM('superadmin','staff','instructor','student') NOT NULL,
            student_id_no VARCHAR(10) DEFAULT NULL,
            program_code VARCHAR(10) DEFAULT 'BSIT',
            year_level TINYINT DEFAULT NULL,
            section_id INT DEFAULT NULL,
            semester VARCHAR(30) DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            forum_karma INT DEFAULT 0,
            UNIQUE KEY unique_student_id_no (student_id_no)
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE sections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            program_code VARCHAR(10) NOT NULL DEFAULT 'BSIT',
            year_level TINYINT NOT NULL,
            section_name VARCHAR(10) NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_section (program_code, year_level, section_name)
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE student_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            program_code VARCHAR(10) NOT NULL DEFAULT 'BSIT',
            year_level TINYINT NOT NULL,
            section_id INT NOT NULL,
            semester VARCHAR(30) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE instructor_classes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            instructor_id INT NOT NULL,
            subject_name VARCHAR(200) NOT NULL,
            course_code VARCHAR(20) NOT NULL,
            description TEXT,
            subject_image VARCHAR(255) DEFAULT NULL,
            units VARCHAR(10) NOT NULL DEFAULT '3',
            prerequisite VARCHAR(200) DEFAULT 'None',
            section_id INT NOT NULL,
            semester VARCHAR(30) NOT NULL,
            class_code VARCHAR(10) UNIQUE NOT NULL,
            program_code VARCHAR(10) NOT NULL DEFAULT 'BSIT',
            year_level TINYINT NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            school_year_id INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE class_enrollments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            student_id INT NOT NULL,
            enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_enrollment (class_id, student_id),
            FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE class_join_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            student_id INT NOT NULL,
            status ENUM('pending','approved','declined') DEFAULT 'pending',
            instructor_note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_request (class_id, student_id),
            FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE student_completed_subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            course_code VARCHAR(20) NOT NULL,
            grade DECIMAL(5,2) DEFAULT NULL,
            completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            recorded_by INT DEFAULT NULL,
            UNIQUE KEY unique_completed (student_id, course_code),
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            reference_id INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE lessons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            title VARCHAR(200) NOT NULL,
            content TEXT,
            video_url VARCHAR(500) DEFAULT NULL,
            link_url VARCHAR(500) DEFAULT NULL,
            link_title VARCHAR(200) DEFAULT NULL,
            sort_order INT DEFAULT 0,
            is_published TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

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

        $pdo->exec("CREATE TABLE knowledge_nodes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT,
            level ENUM('beginner','intermediate','advanced') NOT NULL DEFAULT 'beginner',
            sort_order INT DEFAULT 0,
            content TEXT,
            quiz_id INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE knowledge_node_progress (
            id INT AUTO_INCREMENT PRIMARY KEY,
            node_id INT NOT NULL,
            student_id INT NOT NULL,
            completed TINYINT(1) DEFAULT 0,
            completed_at DATETIME DEFAULT NULL,
            UNIQUE KEY unique_progress (node_id, student_id),
            FOREIGN KEY (node_id) REFERENCES knowledge_nodes(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE quizzes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT,
            quiz_type ENUM('multiple_choice','word_scramble','mixed') DEFAULT 'multiple_choice',
            time_limit INT DEFAULT NULL,
            is_published TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE quiz_questions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT NOT NULL,
            question_text TEXT NOT NULL,
            question_type ENUM('multiple_choice','word_scramble') DEFAULT 'multiple_choice',
            option_a VARCHAR(255) DEFAULT NULL,
            option_b VARCHAR(255) DEFAULT NULL,
            option_c VARCHAR(255) DEFAULT NULL,
            option_d VARCHAR(255) DEFAULT NULL,
            correct_answer VARCHAR(255) NOT NULL,
            sort_order INT DEFAULT 0,
            FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE quiz_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT NOT NULL,
            student_id INT NOT NULL,
            score DECIMAL(5,2) DEFAULT 0,
            total_items INT DEFAULT 0,
            correct_items INT DEFAULT 0,
            completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE quiz_answers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            attempt_id INT NOT NULL,
            question_id INT NOT NULL,
            student_answer VARCHAR(255),
            is_correct TINYINT(1) DEFAULT 0,
            FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
            FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE badges (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            icon VARCHAR(50) DEFAULT 'fa-award',
            badge_rule VARCHAR(100),
            rule_value INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE badge_earns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            badge_id INT NOT NULL,
            student_id INT NOT NULL,
            earned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_earn (badge_id, student_id),
            FOREIGN KEY (badge_id) REFERENCES badges(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE grades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            student_id INT NOT NULL,
            component ENUM('attendance','activity','quiz','project','exam') NOT NULL,
            score DECIMAL(5,2) DEFAULT 0,
            grading_period ENUM('midterm','final') DEFAULT 'midterm',
            updated_by INT DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_grade (class_id, student_id, component, grading_period),
            FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE class_grade_weights (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            component ENUM('attendance','activity','quiz','project','exam') NOT NULL,
            weight DECIMAL(5,2) DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_class_weight (class_id, component),
            FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            action VARCHAR(200) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            prev_hash VARCHAR(64) DEFAULT 'GENESIS',
            block_hash VARCHAR(64) DEFAULT NULL,
            block_data TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

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

        $steps[] = 'All tables created';

        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES
            ('app_name', 'Ilocos Sur Community College'),
            ('app_logo', ''),
            ('theme_mode', 'light'),
            ('theme_accent', '#4F46E5'),
            ('theme_sidebar', '#0F172A'),
            ('theme_navbar', '#FFFFFF'),
            ('maintenance_mode', '0'),
            ('weight_attendance', '10'),
            ('weight_activity', '20'),
            ('weight_quiz', '30'),
            ('weight_project', '0'),
            ('weight_exam', '40'),
            ('passing_grade', '75'),
            ('grading_scale', 'percentage'),
            ('academic_year', '2025-2026'),
            ('academic_semester', 'First Semester'),
            ('max_absences', '10'),
            ('absence_auto_fail', '1'),
            ('attendance_weight_type', 'percentage'),
            ('sy_auto_archive', '1')");
        $steps[] = 'Settings seeded';

        $pdo->exec("INSERT INTO school_years (name, start_date, end_date, status) VALUES ('2025-2026', '2025-08-01', '2026-06-10', 'active')");
        $syId = $pdo->lastInsertId();
        $steps[] = 'School year 2025-2026 created';

        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, password, plain_password, email, first_name, last_name, role) VALUES
            ('superadmin', '$hash', NULL, 'superadmin@iscc.edu.ph', 'Super', 'Admin', 'superadmin')");
        $steps[] = 'Superadmin account created';

        $pdo->prepare("INSERT INTO users (username, password, plain_password, email, first_name, last_name, role) VALUES (?, ?, NULL, ?, ?, ?, 'instructor')")
            ->execute(['instructor1', $hash, 'instructor1@iscc.edu.ph', 'Carl Jonar', 'Palado']);
        $steps[] = '1 Instructor account created';

        $stmt = $pdo->prepare("INSERT INTO sections (program_code, year_level, section_name) VALUES ('BSIT', ?, ?)");
        $stmt->execute([1, 'A']);
        $secId = $pdo->lastInsertId();
        $steps[] = 'Default BSIT section created (1A)';

        $pdo->prepare("INSERT INTO users (username, password, plain_password, email, first_name, last_name, role, student_id_no, program_code, year_level, section_id, semester) VALUES (?, ?, NULL, ?, ?, ?, 'student', ?, 'BSIT', 1, ?, 'First Semester')")
            ->execute(['student1', $hash, 'student1@iscc.edu.ph', 'John', 'Rivera', 'a-25-00001', $secId]);
        $stId = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO student_assignments (student_id, program_code, year_level, section_id, semester) VALUES (?, 'BSIT', 1, ?, 'First Semester')")
            ->execute([$stId, $secId]);
        $steps[] = '1 BSIT Student account created & assigned';
        $steps[] = 'Demo classes, quizzes, badges, forum content, and audit samples skipped';

        file_put_contents($lockFile, 'Installed on ' . date('Y-m-d H:i:s'));
        $steps[] = 'Lock file created';
        $success = true;

    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// Auto-detect base path for asset URLs
$_basePath = rtrim(str_replace('\\', '/', str_replace(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '', str_replace('\\', '/', __DIR__))), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install ISCC LMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= $_basePath ?>/assets/css/style.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?= $_basePath ?>/assets/css/logo.png">
</head>
<body>
<div class="install-wrapper">
    <div class="install-card">
        <div class="text-center mb-4">
            <div style="width:60px;height:60px;border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;overflow:hidden;">
                <img src="<?= $_basePath ?>/assets/css/logo.png" alt="ISCC Logo" style="width:100%;height:100%;object-fit:contain;">
            </div>
            <h2>ISCC LMS Installer</h2>
            <p class="text-muted">BSIT-Only Academic Management System</p>
        </div>

        <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i> Installation completed successfully!</div>
        <div class="mb-4">
            <?php foreach ($steps as $step): ?>
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fas fa-check-circle text-success"></i>
                <span style="font-size:0.88rem;"><?= htmlspecialchars($step) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card" style="border:1px solid #E2E8F0;">
            <div class="card-body" style="font-size:0.85rem;">
                <h6 class="fw-bold mb-3">Starter Accounts</h6>
                <p class="text-muted mb-2">All passwords: <code>password123</code></p>
                <table class="table table-sm mb-0">
                    <tr><td><strong>Superadmin</strong></td><td><code>superadmin</code></td></tr>
                    <tr><td><strong>Instructor</strong></td><td><code>instructor1</code></td></tr>
                    <tr><td><strong>Student</strong></td><td><code>student1</code></td></tr>
                </table>
                <hr>
                <h6 class="fw-bold mb-2">Seeded Data</h6>
                <ul class="mb-0">
                    <li>1 superadmin account</li>
                    <li>1 instructor account</li>
                    <li>1 student account with student ID <code>a-25-00001</code></li>
                    <li>1 default BSIT section: <code>1A</code></li>
                    <li>No demo classes, quizzes, badges, forum posts, or audit rows</li>
                </ul>
            </div>
        </div>
        <a href="<?= $_basePath ?>/login.php" class="btn btn-primary-gradient w-100 mt-3 justify-content-center"><i class="fas fa-sign-in-alt"></i> Go to Login</a>
        <?php elseif (!empty($errors)): ?>
        <div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>
            <?php foreach ($errors as $err): ?><div><?= htmlspecialchars($err) ?></div><?php endforeach; ?>
        </div>
        <form method="POST"><button type="submit" class="btn btn-primary-gradient w-100 justify-content-center"><i class="fas fa-redo"></i> Retry</button></form>
        <?php else: ?>
        <div class="mb-4" style="font-size:0.88rem;">
            <h6 class="fw-bold mb-2">This installer will:</h6>
            <ul class="list-unstyled">
                <li class="mb-1"><i class="fas fa-database text-primary me-2"></i> Create the <code>iscc_lms</code> database</li>
                <li class="mb-1"><i class="fas fa-table text-primary me-2"></i> Build all tables (join requests, notifications, completed subjects)</li>
                <li class="mb-1"><i class="fas fa-users text-primary me-2"></i> Seed users (1 admin, 2 staff, 6 instructors, 36 BSIT students)</li>
                <li class="mb-1"><i class="fas fa-layer-group text-primary me-2"></i> Create BSIT sections (1A&ndash;4C)</li>
                <li class="mb-1"><i class="fas fa-book text-primary me-2"></i> Generate subjects with class codes, lessons, quizzes</li>
                <li class="mb-1"><i class="fas fa-award text-primary me-2"></i> Badges, prerequisite data, and sample records</li>
            </ul>
        </div>
        <form method="POST">
            <button type="submit" class="btn btn-primary-gradient w-100 justify-content-center" id="installBtn"><i class="fas fa-rocket"></i> Install Now</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<script>
document.getElementById('installBtn')?.addEventListener('click', function() {
    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Installing...';
    this.disabled = true;
    this.closest('form').submit();
});
</script>
</body>
</html>
