<?php
$pageTitle = 'Grades';
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'superadmin', 'student');
$pdo = getDB();
$user = currentUser();
$role = $user['role'];
$breadcrumbPills = [$role === 'superadmin' ? 'Administration' : ($role === 'student' ? 'My Learning' : 'Teaching'), 'Grades'];

try {
    $pdo->query("SELECT 1 FROM grades LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS grades (
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
}

try {
    $pdo->query("SELECT 1 FROM class_grade_weights LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS class_grade_weights (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        component ENUM('attendance','activity','quiz','project','exam') NOT NULL,
        weight DECIMAL(5,2) DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_class_weight (class_id, component),
        FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
}

// Auto-create graded_activities tables if missing
try {
    $pdo->query("SELECT 1 FROM graded_activities LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS graded_activities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        title VARCHAR(200) NOT NULL,
        activity_type ENUM('quiz','lab_activity','exam') NOT NULL,
        max_score DECIMAL(5,2) DEFAULT 100,
        grading_period ENUM('midterm','final') DEFAULT 'midterm',
        created_by INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_class_type (class_id, activity_type, grading_period),
        FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
}

try {
    $pdo->query("SELECT 1 FROM graded_activity_scores LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS graded_activity_scores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        activity_id INT NOT NULL,
        student_id INT NOT NULL,
        score DECIMAL(5,2) DEFAULT 0,
        updated_by INT DEFAULT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_activity_score (activity_id, student_id),
        FOREIGN KEY (activity_id) REFERENCES graded_activities(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
}

$defaults = [
    'weight_attendance' => '10', 'weight_activity' => '20', 'weight_quiz' => '30',
    'weight_project' => '0', 'weight_exam' => '40', 'passing_grade' => '75',
];
foreach ($defaults as $k => $v) {
    $chk = $pdo->prepare("SELECT 1 FROM settings WHERE setting_key = ?");
    $chk->execute([$k]);
    if (!$chk->fetch()) {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
    }
}

$globalWeights = [
    'attendance' => floatval(getSetting('weight_attendance', '10')),
    'activity'   => floatval(getSetting('weight_activity', '20')),
    'quiz'       => floatval(getSetting('weight_quiz', '30')),
    'project'    => floatval(getSetting('weight_project', '0')),
    'exam'       => floatval(getSetting('weight_exam', '40')),
];
$passingGrade = floatval(getSetting('passing_grade', '75'));

function getClassWeights($pdo, $classId, $globalWeights) {
    $stmt = $pdo->prepare("SELECT component, weight FROM class_grade_weights WHERE class_id = ?");
    $stmt->execute([$classId]);
    $overrides = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($overrides)) {
        return [
            'attendance' => floatval($overrides['attendance'] ?? $globalWeights['attendance']),
            'activity'   => floatval($overrides['activity'] ?? $globalWeights['activity']),
            'quiz'       => floatval($overrides['quiz'] ?? $globalWeights['quiz']),
            'project'    => floatval($overrides['project'] ?? $globalWeights['project']),
            'exam'       => floatval($overrides['exam'] ?? $globalWeights['exam']),
        ];
    }
    return $globalWeights;
}

$classId = intval($_GET['class_id'] ?? 0);
$period = $_GET['period'] ?? 'midterm';
if (!in_array($period, ['midterm', 'final'])) $period = 'midterm';
$view = $_GET['view'] ?? 'grades';
if (!in_array($view, ['grades', 'weights', 'activities'])) $view = 'grades';

if ($role === 'student' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    flash('error', 'Access denied.'); redirect('/grades.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect('/grades.php'); }

    $action = $_POST['action'] ?? '';
    $postClassId = intval($_POST['class_id'] ?? $classId);

    if ($postClassId && $role === 'instructor') {
        $check = $pdo->prepare("SELECT id FROM instructor_classes WHERE id = ? AND instructor_id = ?");
        $check->execute([$postClassId, $user['id']]);
        if (!$check->fetch()) { flash('error', 'Access denied.'); redirect('/grades.php'); }
    }

    if ($action === 'save_grades' && $postClassId) {
        $classWeights = getClassWeights($pdo, $postClassId, $globalWeights);
        $allComponents = ['attendance','activity','quiz','project','exam'];
        $grades = $_POST['grades'] ?? [];
        $savePeriod = $_POST['grading_period'] ?? 'midterm';
        $count = 0;

        $classInfoForChain = $pdo->prepare("SELECT course_code, subject_name FROM instructor_classes WHERE id = ?");
        $classInfoForChain->execute([$postClassId]);
        $chainClass = $classInfoForChain->fetch();
        $chainCourseCode = $chainClass['course_code'] ?? '';
        $chainSubjectName = $chainClass['subject_name'] ?? '';

        foreach ($grades as $studentId => $comps) {
            $studentId = intval($studentId);
            foreach ($comps as $component => $score) {
                if (!in_array($component, $allComponents)) continue;
                $score = max(0, min(100, floatval($score)));
                $pdo->prepare("INSERT INTO grades (class_id, student_id, component, score, grading_period, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE score = VALUES(score), updated_by = VALUES(updated_by), updated_at = NOW()")
                    ->execute([$postClassId, $studentId, $component, $score, $savePeriod, $user['id']]);

                try {
                    addGradeToChain($studentId, $postClassId, $chainCourseCode, $chainSubjectName, $component, $savePeriod, $score, $user['id']);
                } catch (Exception $e) {}

                $count++;
            }
        }
        auditLogChained('grades_updated', "Updated $count grade entries for class #$postClassId ($savePeriod)");
        flash('success', "Grades saved successfully ($count entries updated).");
        redirect("/grades.php?class_id=$postClassId&period=$savePeriod");
    }

    elseif ($action === 'save_weights' && $postClassId) {
        $w = [
            'attendance' => max(0, min(100, floatval($_POST['w_attendance'] ?? 0))),
            'activity'   => max(0, min(100, floatval($_POST['w_activity'] ?? 0))),
            'quiz'       => max(0, min(100, floatval($_POST['w_quiz'] ?? 0))),
            'project'    => max(0, min(100, floatval($_POST['w_project'] ?? 0))),
            'exam'       => max(0, min(100, floatval($_POST['w_exam'] ?? 0))),
        ];
        $total = array_sum($w);
        if (abs($total - 100) > 0.01) {
            flash('error', "Component weights must total 100%. Current total: " . number_format($total, 2) . "%");
            redirect("/grades.php?class_id=$postClassId&view=weights");
        }
        foreach ($w as $comp => $weight) {
            $pdo->prepare("INSERT INTO class_grade_weights (class_id, component, weight)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE weight = VALUES(weight), updated_at = NOW()")
                ->execute([$postClassId, $comp, $weight]);
        }
        auditLogChained('class_weights_updated', "Updated grade weights for class #$postClassId: " . json_encode($w));
        flash('success', 'Component weights saved for this class.');
        redirect("/grades.php?class_id=$postClassId&view=weights");
    }

    elseif ($action === 'reset_weights' && $postClassId) {
        $pdo->prepare("DELETE FROM class_grade_weights WHERE class_id = ?")->execute([$postClassId]);
        auditLogChained('class_weights_reset', "Reset grade weights to defaults for class #$postClassId");
        flash('success', 'Weights reset to system defaults.');
        redirect("/grades.php?class_id=$postClassId&view=weights");
    }

    // ─── Graded Activities CRUD ───
    elseif ($action === 'create_activity' && $postClassId) {
        $actTitle = trim($_POST['act_title'] ?? '');
        $actType = $_POST['act_type'] ?? '';
        $actMaxScore = max(1, min(1000, floatval($_POST['act_max_score'] ?? 100)));
        $actPeriod = $_POST['act_period'] ?? 'midterm';
        if (!in_array($actType, ['quiz', 'lab_activity', 'exam'])) {
            flash('error', 'Invalid activity type.');
        } elseif (empty($actTitle)) {
            flash('error', 'Activity title is required.');
        } elseif (!in_array($actPeriod, ['midterm', 'final'])) {
            flash('error', 'Invalid grading period.');
        } else {
            // Submission fields
            $isSubmittable = isset($_POST['is_submittable']) ? 1 : 0;
            $description = trim($_POST['act_description'] ?? '');
            $openDate = !empty($_POST['open_date']) ? $_POST['open_date'] : null;
            $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
            $closeDate = !empty($_POST['close_date']) ? $_POST['close_date'] : null;
            $allowLate = isset($_POST['allow_late']) ? 1 : 0;
            $allowResubmit = isset($_POST['allow_resubmit']) ? 1 : 0;
            $latePenaltyType = in_array($_POST['late_penalty_type'] ?? '', ['percentage','fixed']) ? $_POST['late_penalty_type'] : 'percentage';
            $latePenaltyAmount = max(0, floatval($_POST['late_penalty_amount'] ?? 0));
            $latePenaltyInterval = in_array($_POST['late_penalty_interval'] ?? '', ['per_day','per_hour']) ? $_POST['late_penalty_interval'] : 'per_day';
            $latePenaltyMax = !empty($_POST['late_penalty_max']) ? max(0, floatval($_POST['late_penalty_max'])) : null;

            // Validate dates if submittable
            if ($isSubmittable && $dueDate && $openDate && strtotime($dueDate) < strtotime($openDate)) {
                flash('error', 'Due date cannot be before open date.');
                redirect("/grades.php?class_id=$postClassId&view=activities&period=$actPeriod");
                exit;
            }
            if ($isSubmittable && $closeDate && $dueDate && strtotime($closeDate) < strtotime($dueDate)) {
                flash('error', 'Close date cannot be before due date.');
                redirect("/grades.php?class_id=$postClassId&view=activities&period=$actPeriod");
                exit;
            }

            $pdo->prepare("INSERT INTO graded_activities (class_id, title, activity_type, max_score, grading_period, created_by,
                is_submittable, description, open_date, due_date, close_date, allow_late, allow_resubmit,
                late_penalty_type, late_penalty_amount, late_penalty_interval, late_penalty_max)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$postClassId, $actTitle, $actType, $actMaxScore, $actPeriod, $user['id'],
                    $isSubmittable, $description ?: null, $openDate, $dueDate, $closeDate, $allowLate, $allowResubmit,
                    $latePenaltyType, $latePenaltyAmount, $latePenaltyInterval, $latePenaltyMax]);

            $newActId = $pdo->lastInsertId();

            // Notify all enrolled students about the new activity
            $enrolledStudents = $pdo->prepare("SELECT student_id FROM class_enrollments WHERE class_id = ?");
            $enrolledStudents->execute([$postClassId]);
            $teacherName = $user['first_name'] . ' ' . $user['last_name'];
            $actTypeLabel = ['quiz' => 'Quiz', 'lab_activity' => 'Lab Activity', 'exam' => 'Exam'][$actType] ?? $actType;
            $notifLink = $isSubmittable
                ? BASE_URL . "/activity-submissions.php?activity_id=$newActId"
                : BASE_URL . "/grades.php?class_id=$postClassId&view=activities&period=$actPeriod";
            while ($enrolled = $enrolledStudents->fetch()) {
                addNotification(
                    $enrolled['student_id'],
                    'new_activity',
                    "$teacherName posted a new $actTypeLabel: \"$actTitle\"",
                    $newActId,
                    $notifLink
                );
            }

            auditLog('activity_created', "Created graded activity '$actTitle' (type: $actType, submittable: $isSubmittable) for class #$postClassId");
            flash('success', "Activity \"$actTitle\" created successfully.");
        }
        redirect("/grades.php?class_id=$postClassId&view=activities&period=$actPeriod");
    }

    elseif ($action === 'edit_activity' && $postClassId) {
        $actId = intval($_POST['activity_id'] ?? 0);
        $chk = $pdo->prepare("SELECT id, grading_period FROM graded_activities WHERE id = ? AND class_id = ?");
        $chk->execute([$actId, $postClassId]);
        $editAct = $chk->fetch();
        if ($editAct) {
            $actTitle = trim($_POST['act_title'] ?? '');
            $actType = $_POST['act_type'] ?? '';
            $actMaxScore = max(1, min(1000, floatval($_POST['act_max_score'] ?? 100)));
            $actPeriod = $_POST['act_period'] ?? $editAct['grading_period'];
            if (!in_array($actType, ['quiz', 'lab_activity', 'exam'])) {
                flash('error', 'Invalid activity type.');
            } elseif (empty($actTitle)) {
                flash('error', 'Activity title is required.');
            } elseif (!in_array($actPeriod, ['midterm', 'final'])) {
                flash('error', 'Invalid grading period.');
            } else {
                $isSubmittable = isset($_POST['is_submittable']) ? 1 : 0;
                $description = trim($_POST['act_description'] ?? '');
                $openDate = !empty($_POST['open_date']) ? $_POST['open_date'] : null;
                $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
                $closeDate = !empty($_POST['close_date']) ? $_POST['close_date'] : null;
                $allowLate = isset($_POST['allow_late']) ? 1 : 0;
                $allowResubmit = isset($_POST['allow_resubmit']) ? 1 : 0;
                $latePenaltyType = in_array($_POST['late_penalty_type'] ?? '', ['percentage','fixed']) ? $_POST['late_penalty_type'] : 'percentage';
                $latePenaltyAmount = max(0, floatval($_POST['late_penalty_amount'] ?? 0));
                $latePenaltyInterval = in_array($_POST['late_penalty_interval'] ?? '', ['per_day','per_hour']) ? $_POST['late_penalty_interval'] : 'per_day';
                $latePenaltyMax = !empty($_POST['late_penalty_max']) ? max(0, floatval($_POST['late_penalty_max'])) : null;

                if ($isSubmittable && $dueDate && $openDate && strtotime($dueDate) < strtotime($openDate)) {
                    flash('error', 'Due date cannot be before open date.');
                    redirect("/grades.php?class_id=$postClassId&view=activities&period=$actPeriod");
                    exit;
                }
                if ($isSubmittable && $closeDate && $dueDate && strtotime($closeDate) < strtotime($dueDate)) {
                    flash('error', 'Close date cannot be before due date.');
                    redirect("/grades.php?class_id=$postClassId&view=activities&period=$actPeriod");
                    exit;
                }

                $pdo->prepare("UPDATE graded_activities SET title = ?, activity_type = ?, max_score = ?, grading_period = ?,
                    is_submittable = ?, description = ?, open_date = ?, due_date = ?, close_date = ?, allow_late = ?, allow_resubmit = ?,
                    late_penalty_type = ?, late_penalty_amount = ?, late_penalty_interval = ?, late_penalty_max = ?
                    WHERE id = ? AND class_id = ?")
                    ->execute([$actTitle, $actType, $actMaxScore, $actPeriod,
                        $isSubmittable, $description ?: null, $openDate, $dueDate, $closeDate, $allowLate, $allowResubmit,
                        $latePenaltyType, $latePenaltyAmount, $latePenaltyInterval, $latePenaltyMax,
                        $actId, $postClassId]);

                // Re-sync grades if period or type changed
                syncActivityToGrades($pdo, $postClassId, $actPeriod, $user['id']);
                if ($actPeriod !== $editAct['grading_period']) {
                    syncActivityToGrades($pdo, $postClassId, $editAct['grading_period'], $user['id']);
                }

                auditLog('activity_updated', "Updated graded activity '$actTitle' (#$actId) for class #$postClassId");
                flash('success', "Activity \"$actTitle\" updated successfully.");
            }
        } else {
            flash('error', 'Activity not found.');
        }
        redirect("/grades.php?class_id=$postClassId&view=activities&period=" . ($actPeriod ?? 'midterm'));
    }

    elseif ($action === 'delete_activity' && $postClassId) {
        $actId = intval($_POST['activity_id'] ?? 0);
        $chk = $pdo->prepare("SELECT id, is_submittable FROM graded_activities WHERE id = ? AND class_id = ?");
        $chk->execute([$actId, $postClassId]);
        $delAct = $chk->fetch();
        if ($delAct) {
            // Delete submission files if it was submittable
            if ($delAct['is_submittable']) {
                $subFiles = $pdo->prepare("SELECT file_name FROM activity_submissions WHERE activity_id = ?");
                $subFiles->execute([$actId]);
                while ($sf = $subFiles->fetch()) {
                    $fp = __DIR__ . '/uploads/submissions/' . $sf['file_name'];
                    if (file_exists($fp)) @unlink($fp);
                }
                $histFiles = $pdo->prepare("SELECT file_name FROM submission_history WHERE activity_id = ?");
                $histFiles->execute([$actId]);
                while ($hf = $histFiles->fetch()) {
                    $fp = __DIR__ . '/uploads/submissions/' . $hf['file_name'];
                    if (file_exists($fp)) @unlink($fp);
                }
                $pdo->prepare("DELETE FROM submission_history WHERE activity_id = ?")->execute([$actId]);
                $pdo->prepare("DELETE FROM activity_submissions WHERE activity_id = ?")->execute([$actId]);
            }
            $pdo->prepare("DELETE FROM graded_activity_scores WHERE activity_id = ?")->execute([$actId]);
            $pdo->prepare("DELETE FROM graded_activities WHERE id = ?")->execute([$actId]);
            auditLog('activity_deleted', "Deleted graded activity #$actId from class #$postClassId");
            flash('success', 'Activity deleted.');
            // Re-sync grades after deletion
            $delPeriod = $_POST['act_period'] ?? 'midterm';
            syncActivityToGrades($pdo, $postClassId, $delPeriod, $user['id']);
        }
        redirect("/grades.php?class_id=$postClassId&view=activities&period=" . ($_POST['act_period'] ?? 'midterm'));
    }

    elseif ($action === 'save_activity_scores' && $postClassId) {
        $actId = intval($_POST['activity_id'] ?? 0);
        $scores = $_POST['act_scores'] ?? [];
        $chk = $pdo->prepare("SELECT id, max_score, grading_period FROM graded_activities WHERE id = ? AND class_id = ?");
        $chk->execute([$actId, $postClassId]);
        $actInfo = $chk->fetch();
        if ($actInfo) {
            $count = 0;
            foreach ($scores as $studentId => $score) {
                $studentId = intval($studentId);
                $score = max(0, min(floatval($actInfo['max_score']), floatval($score)));
                $pdo->prepare("INSERT INTO graded_activity_scores (activity_id, student_id, score, updated_by)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE score = VALUES(score), updated_by = VALUES(updated_by), updated_at = NOW()")
                    ->execute([$actId, $studentId, $score, $user['id']]);
                $count++;
            }
            // Auto-sync activity scores to component grades
            syncActivityToGrades($pdo, $postClassId, $actInfo['grading_period'], $user['id']);
            auditLog('activity_scores_saved', "Saved $count scores for activity #$actId in class #$postClassId");
            flash('success', "Scores saved for $count students. Component grades updated automatically.");
        }
        redirect("/grades.php?class_id=$postClassId&view=activities&period=" . ($actInfo['grading_period'] ?? 'midterm'));
    }
}

if ($role === 'superadmin') {
    $classes = $pdo->query("SELECT tc.*, u.first_name as instructor_fn, u.last_name as instructor_ln, s.section_name,
        (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id = tc.id) as student_count
        FROM instructor_classes tc
        JOIN users u ON tc.instructor_id = u.id
        JOIN sections s ON tc.section_id = s.id
        WHERE tc.is_active = 1
        ORDER BY tc.program_code, tc.year_level, s.section_name")->fetchAll();
} elseif ($role === 'student') {
    $stmt = $pdo->prepare("SELECT tc.*, s.section_name, u.first_name as instructor_fn, u.last_name as instructor_ln
        FROM class_enrollments ce
        JOIN instructor_classes tc ON ce.class_id = tc.id
        JOIN sections s ON tc.section_id = s.id
        JOIN users u ON tc.instructor_id = u.id
        WHERE ce.student_id = ? AND tc.is_active = 1
        ORDER BY tc.subject_name");
    $stmt->execute([$user['id']]);
    $classes = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT tc.*, s.section_name,
        (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id = tc.id) as student_count
        FROM instructor_classes tc
        JOIN sections s ON tc.section_id = s.id
        WHERE tc.instructor_id = ? AND tc.is_active = 1
        ORDER BY tc.program_code, tc.year_level");
    $stmt->execute([$user['id']]);
    $classes = $stmt->fetchAll();
}

$students = [];
$currentGrades = [];
$classInfo = null;
$classWeights = $globalWeights;
if ($classId) {
    if ($role === 'instructor') {
        $chk = $pdo->prepare("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id WHERE tc.id = ? AND tc.instructor_id = ?");
        $chk->execute([$classId, $user['id']]);
        $classInfo = $chk->fetch();
    } elseif ($role === 'student') {
        $chk = $pdo->prepare("SELECT tc.*, s.section_name, u.first_name as instructor_fn, u.last_name as instructor_ln
            FROM class_enrollments ce
            JOIN instructor_classes tc ON ce.class_id = tc.id
            JOIN sections s ON tc.section_id = s.id
            JOIN users u ON tc.instructor_id = u.id
            WHERE ce.class_id = ? AND ce.student_id = ?");
        $chk->execute([$classId, $user['id']]);
        $classInfo = $chk->fetch();
    } else {
        $chk = $pdo->prepare("SELECT tc.*, s.section_name, u.first_name as instructor_fn, u.last_name as instructor_ln FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id JOIN users u ON tc.instructor_id = u.id WHERE tc.id = ?");
        $chk->execute([$classId]);
        $classInfo = $chk->fetch();
    }

    if ($classInfo) {
        $classWeights = getClassWeights($pdo, $classId, $globalWeights);

        $stmt = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.username, u.program_code
            FROM class_enrollments ce
            JOIN users u ON ce.student_id = u.id
            WHERE ce.class_id = ?
            ORDER BY u.last_name, u.first_name");
        $stmt->execute([$classId]);
        $students = $stmt->fetchAll();

        // Load grades for BOTH periods (needed for Final Grade computation)
        $allPeriodGrades = ['midterm' => [], 'final' => []];
        $gStmt = $pdo->prepare("SELECT student_id, component, score, grading_period FROM grades WHERE class_id = ?");
        $gStmt->execute([$classId]);
        while ($g = $gStmt->fetch()) {
            $allPeriodGrades[$g['grading_period']][$g['student_id']][$g['component']] = $g['score'];
        }
        $currentGrades = $allPeriodGrades[$period] ?? [];

        // Auto-compute attendance scores from attendance table (per-period)
        try {
            foreach (['midterm', 'final'] as $attPer) {
                foreach ($students as $stu) {
                    $attStmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM attendance WHERE class_id = ? AND student_id = ? AND grading_period = ? GROUP BY status");
                    $attStmt->execute([$classId, $stu['id'], $attPer]);
                    $attData = $attStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                    $total = array_sum($attData);
                    if ($total > 0) {
                        $present = intval($attData['present'] ?? 0) + intval($attData['late'] ?? 0);
                        $rate = round(($present / $total) * 100, 2);
                        $allPeriodGrades[$attPer][$stu['id']]['attendance'] = $rate;
                    } else {
                        // No attendance records for this period — remove any stale score
                        unset($allPeriodGrades[$attPer][$stu['id']]['attendance']);
                    }
                }
            }
            $currentGrades = $allPeriodGrades[$period] ?? [];
        } catch (Exception $e) { /* attendance table may not exist yet */ }

        // Load graded activities for Activities tab
        $classActivities = [];
        try {
            $actStmt = $pdo->prepare("SELECT ga.*, 
                (SELECT COUNT(*) FROM graded_activity_scores gas WHERE gas.activity_id = ga.id AND gas.score > 0) as scored_count,
                (SELECT COUNT(*) FROM activity_submissions asub WHERE asub.activity_id = ga.id) as submission_count,
                (SELECT COUNT(*) FROM activity_submissions asub WHERE asub.activity_id = ga.id AND asub.status = 'graded') as graded_count
                FROM graded_activities ga WHERE ga.class_id = ? ORDER BY ga.grading_period, ga.activity_type, ga.created_at");
            $actStmt->execute([$classId]);
            $classActivities = $actStmt->fetchAll();
        } catch (Exception $e) { /* table may not exist yet */ }

        // Load activity scores for each activity
        $activityScores = [];
        try {
            foreach ($classActivities as $act) {
                $scStmt = $pdo->prepare("SELECT student_id, score FROM graded_activity_scores WHERE activity_id = ?");
                $scStmt->execute([$act['id']]);
                while ($sc = $scStmt->fetch()) {
                    $activityScores[$act['id']][$sc['student_id']] = $sc['score'];
                }
            }
        } catch (Exception $e) {}
    } else {
        $classId = 0;
    }
}

$componentMeta = [
    'attendance' => ['label' => 'Attendance', 'icon' => 'fa-calendar-check', 'color' => '#10B981'],
    'activity'   => ['label' => 'Activity',   'icon' => 'fa-tasks',          'color' => '#3B82F6'],
    'quiz'       => ['label' => 'Quiz',       'icon' => 'fa-question-circle','color' => '#F59E0B'],
    'project'    => ['label' => 'Project',    'icon' => 'fa-project-diagram','color' => '#8B5CF6'],
    'exam'       => ['label' => 'Exam',       'icon' => 'fa-file-alt',      'color' => '#EF4444'],
];
$components = [];
foreach ($componentMeta as $key => $meta) {
    $components[$key] = array_merge($meta, ['weight' => $classWeights[$key]]);
}
$activeComponents = array_filter($components, fn($c) => $c['weight'] > 0);

// Pre-compute weighted averages for both periods (used in Final Grade)
$periodWA = ['midterm' => [], 'final' => []];
if ($classId && !empty($students)) {
    foreach (['midterm', 'final'] as $waPer) {
        foreach ($students as $stu) {
            $wa = 0;
            foreach ($activeComponents as $key => $comp) {
                $score = floatval($allPeriodGrades[$waPer][$stu['id']][$key] ?? 0);
                $wa += ($score * $comp['weight'] / 100);
            }
            $periodWA[$waPer][$stu['id']] = round($wa, 2);
        }
    }
}

$hasCustomWeights = false;
if ($classId) {
    $cwChk = $pdo->prepare("SELECT COUNT(*) FROM class_grade_weights WHERE class_id = ?");
    $cwChk->execute([$classId]);
    $hasCustomWeights = $cwChk->fetchColumn() > 0;
}

require_once __DIR__ . '/views/layouts/header.php';
?>

<?php if (!$classId): ?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <span><i class="fas fa-clipboard-list me-2"></i><?= $role === 'student' ? 'Select a Class to View Grades' : 'Select a Class to Manage Grades' ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($classes)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                    No classes found. <?= $role === 'instructor' ? 'You have not been assigned to any classes yet.' : ($role === 'student' ? 'You are not enrolled in any classes yet.' : '') ?>
                </div>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($classes as $cls): ?>
                    <div class="col-md-6 col-lg-4">
                        <a href="<?= BASE_URL ?>/grades.php?class_id=<?= $cls['id'] ?>" class="text-decoration-none">
                            <div class="card h-100 border" style="transition:all .2s;cursor:pointer;" onmouseover="this.style.borderColor='var(--primary)';this.style.transform='translateY(-2px)'" onmouseout="this.style.borderColor='';this.style.transform=''">
                                <div class="card-body">
                                    <div class="d-flex align-items-center gap-3 mb-2">
                                        <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,var(--primary-50),var(--primary-100));display:flex;align-items:center;justify-content:center;">
                                            <i class="fas fa-chalkboard" style="color:var(--primary);"></i>
                                        </div>
                                        <div>
                                            <h6 class="mb-0 fw-bold"><?= e($cls['subject_name'] ?? 'General') ?></h6>
                                            <small class="text-muted">
                                                <?= e(PROGRAMS[$cls['program_code']] ?? $cls['program_code']) ?> &bull;
                                                <?= e(YEAR_LEVELS[$cls['year_level']] ?? '') ?> &bull;
                                                Section <?= e($cls['section_name']) ?>
                                            </small>
                                        </div>
                                    </div>
                                    <?php if (($role === 'superadmin' || $role === 'student') && isset($cls['instructor_fn'])): ?>
                                    <div style="font-size:0.8rem;color:var(--gray-500);"><i class="fas fa-user me-1"></i><?= e($cls['instructor_fn'] . ' ' . $cls['instructor_ln']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($role !== 'student'): ?>
                                    <div class="mt-2" style="font-size:0.8rem;color:var(--gray-500);"><i class="fas fa-users me-1"></i><?= $cls['student_count'] ?> students</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <a href="<?= BASE_URL ?>/grades.php" class="btn btn-sm btn-outline-secondary me-2"><i class="fas fa-arrow-left me-1"></i>Back</a>
        <span class="fw-bold" style="font-size:1.05rem;"><?= e($classInfo['subject_name'] ?? 'General') ?></span>
        <span class="text-muted ms-2" style="font-size:0.85rem;">
            <?= e(PROGRAMS[$classInfo['program_code']] ?? '') ?> &bull; <?= e(YEAR_LEVELS[$classInfo['year_level']] ?? '') ?> &bull; Sec. <?= e($classInfo['section_name']) ?>
            <?php if (($role === 'superadmin' || $role === 'student') && isset($classInfo['instructor_fn'])): ?>
            &bull; <i class="fas fa-user ms-1 me-1"></i><?= e($classInfo['instructor_fn'] . ' ' . $classInfo['instructor_ln']) ?>
            <?php endif; ?>
        </span>
    </div>
</div>

<?php if ($role === 'student'): ?>
<ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item">
        <a class="nav-link <?= $view === 'grades' ? 'active' : '' ?>" href="<?= BASE_URL ?>/grades.php?class_id=<?= $classId ?>&period=<?= $period ?>">
            <i class="fas fa-table me-1"></i>My Grades
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $view === 'activities' ? 'active' : '' ?>" href="<?= BASE_URL ?>/grades.php?class_id=<?= $classId ?>&view=activities&period=<?= $period ?>">
            <i class="fas fa-tasks me-1"></i>Activities & Submissions
        </a>
    </li>
</ul>
<?php else: ?>
<ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item">
        <a class="nav-link <?= $view === 'grades' ? 'active' : '' ?>" href="<?= BASE_URL ?>/grades.php?class_id=<?= $classId ?>&period=<?= $period ?>">
            <i class="fas fa-table me-1"></i>Grade Sheet
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $view === 'activities' ? 'active' : '' ?>" href="<?= BASE_URL ?>/grades.php?class_id=<?= $classId ?>&view=activities&period=<?= $period ?>">
            <i class="fas fa-tasks me-1"></i>Graded Activities
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $view === 'weights' ? 'active' : '' ?>" href="<?= BASE_URL ?>/grades.php?class_id=<?= $classId ?>&view=weights">
            <i class="fas fa-balance-scale me-1"></i>% Component Weights
        </a>
    </li>
</ul>
<?php endif; ?>

<?php if ($role === 'student' && $view === 'grades'): ?>
<?php
// Use already-loaded $allPeriodGrades for student view
$studentGrades = [];
foreach (['midterm', 'final'] as $p) {
    $studentGrades[$p] = $allPeriodGrades[$p][$user['id']] ?? [];
}

// Compute WA for both periods
$midtermWA = 0; $finalWA = 0;
$hasMidtermGrades = false; $hasFinalGrades = false;
foreach ($activeComponents as $key => $comp) {
    $mVal = floatval($studentGrades['midterm'][$key] ?? 0);
    $fVal = floatval($studentGrades['final'][$key] ?? 0);
    if ($mVal > 0) $hasMidtermGrades = true;
    if ($fVal > 0) $hasFinalGrades = true;
    $midtermWA += ($mVal * $comp['weight'] / 100);
    $finalWA   += ($fVal * $comp['weight'] / 100);
}
$hasBothPeriods = $hasMidtermGrades && $hasFinalGrades;
$computedFinalGrade = $hasBothPeriods ? round(($midtermWA + $finalWA) / 2, 2) : ($period === 'midterm' ? round($midtermWA, 2) : round($finalWA, 2));
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/grades.php?class_id=<?= $classId ?>&period=midterm" class="btn btn-sm <?= $period === 'midterm' ? 'btn-primary' : 'btn-outline-secondary' ?>">Midterm</a>
        <a href="<?= BASE_URL ?>/grades.php?class_id=<?= $classId ?>&period=final" class="btn btn-sm <?= $period === 'final' ? 'btn-primary' : 'btn-outline-secondary' ?>">Final</a>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/grade-export.php?type=print&mode=student&class_id=<?= $classId ?>&student_id=<?= $user['id'] ?>&period=<?= e($period) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-print me-1"></i>Print
        </a>
        <a href="<?= BASE_URL ?>/grade-export.php?type=csv&mode=student&class_id=<?= $classId ?>&student_id=<?= $user['id'] ?>&period=<?= e($period) ?>" class="btn btn-sm btn-outline-success">
            <i class="fas fa-file-csv me-1"></i>Export CSV
        </a>
        <a href="<?= BASE_URL ?>/grade-export.php?type=print&mode=student&class_id=<?= $classId ?>&student_id=<?= $user['id'] ?>&period=both" target="_blank" class="btn btn-sm btn-outline-info">
            <i class="fas fa-file-alt me-1"></i>Full Report
        </a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center" style="font-size:0.82rem;">
            <span class="fw-bold text-muted"><i class="fas fa-weight-hanging me-1"></i>Component Weights:</span>
            <?php foreach ($activeComponents as $key => $comp): ?>
            <span class="badge bg-light text-dark border">
                <i class="fas <?= $comp['icon'] ?> me-1" style="color:<?= $comp['color'] ?>"></i><?= e($comp['label']) ?>: <?= number_format($comp['weight'], 1) ?>%
            </span>
            <?php endforeach; ?>
            <span class="badge bg-warning text-dark">Passing: <?= $passingGrade ?>%</span>
        </div>
    </div>
</div>

<?php
$myGrades = $studentGrades[$period] ?? [];
$totalWeighted = 0;
foreach ($activeComponents as $key => $comp) {
    $score = floatval($myGrades[$key] ?? 0);
    $totalWeighted += ($score * $comp['weight'] / 100);
}
$avg = $totalWeighted;
?>

<!-- Final Grade Summary Card — only show when BOTH periods have grades -->
<?php if ($hasBothPeriods): ?>
<div class="card mb-4" style="border-radius:16px;border-left:4px solid <?= $computedFinalGrade >= $passingGrade ? '#10B981' : '#EF4444' ?>;">
    <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="fas fa-graduation-cap me-2"></i>Final Grade Summary</h6>
        <div class="row g-3 align-items-center text-center">
            <div class="col-md-3">
                <div class="p-3 rounded" style="background:#F0F9FF;">
                    <div class="text-muted mb-1" style="font-size:0.78rem;">Midterm Grade</div>
                    <div class="fw-bold" style="font-size:1.4rem;color:<?= $midtermWA >= $passingGrade ? '#10B981' : '#EF4444' ?>;">
                        <?= number_format($midtermWA, 2) ?>
                    </div>
                </div>
            </div>
            <div class="col-md-1 d-none d-md-block">
                <i class="fas fa-plus text-muted"></i>
            </div>
            <div class="col-md-3">
                <div class="p-3 rounded" style="background:#FFF7ED;">
                    <div class="text-muted mb-1" style="font-size:0.78rem;">Tentative Final Grade</div>
                    <div class="fw-bold" style="font-size:1.4rem;color:<?= $finalWA >= $passingGrade ? '#10B981' : '#EF4444' ?>;">
                        <?= number_format($finalWA, 2) ?>
                    </div>
                </div>
            </div>
            <div class="col-md-1 d-none d-md-block">
                <i class="fas fa-equals text-muted"></i>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded" style="background:<?= $computedFinalGrade >= $passingGrade ? '#D1FAE5' : '#FEE2E2' ?>;">
                    <div class="text-muted mb-1" style="font-size:0.78rem;">Final Grade</div>
                    <div class="fw-bold" style="font-size:1.8rem;color:<?= $computedFinalGrade >= $passingGrade ? '#065F46' : '#991B1B' ?>;">
                        <?= number_format($computedFinalGrade, 2) ?>
                    </div>
                    <span class="badge bg-<?= $computedFinalGrade >= $passingGrade ? 'success' : 'danger' ?>" style="font-size:0.8rem;">
                        <?= $computedFinalGrade >= $passingGrade ? 'PASSED' : 'FAILED' ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="text-muted text-center mt-2" style="font-size:0.75rem;">
            <i class="fas fa-info-circle me-1"></i>Final Grade = (Midterm Grade + Tentative Final Grade) &divide; 2
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info d-flex align-items-center mb-4" style="border-radius:12px;">
    <i class="fas fa-info-circle me-2"></i>
    <span>Final Grade will be computed once both <strong>Midterm</strong> and <strong>Final</strong> periods have grades.</span>
</div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card text-center" style="border-radius:16px;overflow:hidden;">
            <div class="card-body py-4">
                <div style="width:90px;height:90px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;
                    font-size:1.8rem;font-weight:800;border:4px solid <?= $avg >= $passingGrade ? '#10B981' : '#EF4444' ?>;
                    background:<?= $avg >= $passingGrade ? 'linear-gradient(135deg,#D1FAE5,#A7F3D0)' : 'linear-gradient(135deg,#FEE2E2,#FCA5A5)' ?>;
                    color:<?= $avg >= $passingGrade ? '#065F46' : '#991B1B' ?>;">
                    <?= number_format($avg, 1) ?>
                </div>
                <h6 class="fw-bold mb-1">Weighted Average</h6>
                <span class="badge bg-<?= $avg >= $passingGrade ? 'success' : 'danger' ?> fs-6">
                    <?= $avg >= $passingGrade ? 'PASSED' : 'FAILED' ?>
                </span>
                <div class="mt-2" style="font-size:0.8rem;color:var(--gray-500);">
                    <?= ucfirst($period) ?> Period
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card" style="border-radius:16px;">
            <div class="card-header"><span><i class="fas fa-chart-bar me-2"></i>Component Scores</span></div>
            <div class="card-body">
                <?php foreach ($activeComponents as $key => $comp):
                    $score = floatval($myGrades[$key] ?? 0);
                    $barColor = $score >= $passingGrade ? $comp['color'] : '#EF4444';
                ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span style="font-size:0.88rem;font-weight:600;">
                            <i class="fas <?= $comp['icon'] ?> me-1" style="color:<?= $comp['color'] ?>"></i>
                            <?= e($comp['label']) ?>
                            <small class="text-muted fw-normal">(<?= number_format($comp['weight'], 1) ?>%)</small>
                        </span>
                        <span class="fw-bold" style="font-size:1rem;color:<?= $score >= $passingGrade ? '#10B981' : '#EF4444' ?>"><?= number_format($score, 2) ?></span>
                    </div>
                    <div class="progress" style="height:10px;border-radius:6px;background:var(--gray-100);">
                        <div class="progress-bar" style="width:<?= $score ?>%;background:<?= $barColor ?>;border-radius:6px;transition:width .5s;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="card" style="border-radius:16px;">
    <div class="card-header"><span><i class="fas fa-table me-2"></i>Grade Details (<?= ucfirst($period) ?>)</span></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-modern mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Component</th>
                        <th class="text-center">Weight</th>
                        <th class="text-center">Score</th>
                        <th class="text-center">Weighted</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeComponents as $key => $comp):
                        $score = floatval($myGrades[$key] ?? 0);
                        $weighted = $score * $comp['weight'] / 100;
                    ?>
                    <tr>
                        <td>
                            <i class="fas <?= $comp['icon'] ?> me-2" style="color:<?= $comp['color'] ?>"></i>
                            <strong><?= e($comp['label']) ?></strong>
                        </td>
                        <td class="text-center" style="font-size:0.85rem;"><?= number_format($comp['weight'], 1) ?>%</td>
                        <td class="text-center">
                            <span class="fw-bold" style="font-size:1.05rem;color:<?= $score >= $passingGrade ? '#10B981' : ($score > 0 ? '#EF4444' : 'var(--gray-400)') ?>;">
                                <?= $score > 0 ? number_format($score, 2) : '—' ?>
                            </span>
                        </td>
                        <td class="text-center" style="font-size:0.88rem;color:var(--gray-600);">
                            <?= $score > 0 ? number_format($weighted, 2) : '—' ?>
                        </td>
                        <td class="text-center">
                            <?php if ($score > 0): ?>
                            <span class="badge bg-<?= $score >= $passingGrade ? 'success' : 'danger' ?>-subtle text-<?= $score >= $passingGrade ? 'success' : 'danger' ?>">
                                <?= $score >= $passingGrade ? 'Passed' : 'Below' ?>
                            </span>
                            <?php else: ?>
                            <span class="badge bg-light text-muted">No grade</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:var(--gray-50);">
                        <td class="fw-bold">Weighted Average</td>
                        <td class="text-center fw-bold">100%</td>
                        <td></td>
                        <td class="text-center">
                            <span class="fw-bold" style="font-size:1.1rem;color:<?= $avg >= $passingGrade ? '#10B981' : '#EF4444' ?>"><?= number_format($avg, 2) ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= $avg >= $passingGrade ? 'success' : 'danger' ?>">
                                <?= $avg >= $passingGrade ? 'PASSED' : 'FAILED' ?>
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php elseif ($view === 'activities'): ?>
<?php
// Filter activities by selected period
$periodActivities = array_filter($classActivities, fn($a) => $a['grading_period'] === $period);
$actTypeLabels = ['quiz' => 'Quiz', 'lab_activity' => 'Lab Activity', 'exam' => 'Exam'];
$actTypeColors = ['quiz' => '#F59E0B', 'lab_activity' => '#3B82F6', 'exam' => '#EF4444'];
$actTypeIcons = ['quiz' => 'fa-question-circle', 'lab_activity' => 'fa-flask', 'exam' => 'fa-file-alt'];
?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/grades.php?class_id=<?= $classId ?>&view=activities&period=midterm" class="btn btn-sm <?= $period === 'midterm' ? 'btn-primary' : 'btn-outline-secondary' ?>">Midterm</a>
        <a href="<?= BASE_URL ?>/grades.php?class_id=<?= $classId ?>&view=activities&period=final" class="btn btn-sm <?= $period === 'final' ? 'btn-primary' : 'btn-outline-secondary' ?>">Final</a>
    </div>
    <?php if ($role !== 'student'): ?>
    <button class="btn btn-sm btn-primary-gradient" data-bs-toggle="modal" data-bs-target="#createActivityModal">
        <i class="fas fa-plus me-1"></i>New Graded Activity
    </button>
    <?php endif; ?>
</div>

<div class="alert alert-info" style="font-size:0.85rem;">
    <i class="fas fa-info-circle me-1"></i>
    Create graded activities (Quizzes, Lab Activities, Exams) and enter individual student scores. 
    Scores are <strong>automatically averaged</strong> and synced to the corresponding component grade in the Grade Sheet.
    <span class="badge bg-light text-dark border ms-1">Quiz → Quiz component</span>
    <span class="badge bg-light text-dark border ms-1">Lab Activity → Activity component</span>
    <span class="badge bg-light text-dark border ms-1">Exam → Exam component</span>
</div>

<?php if (empty($periodActivities)): ?>
<div class="card">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-clipboard-list fa-2x mb-2 d-block"></i>
        No graded activities for <?= ucfirst($period) ?> period yet.
        <?php if ($role !== 'student'): ?>
        <br><small>Click "New Graded Activity" to create one.</small>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<?php foreach ($periodActivities as $act): ?>
<div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span>
            <i class="fas <?= $actTypeIcons[$act['activity_type']] ?? 'fa-file' ?> me-2" style="color:<?= $actTypeColors[$act['activity_type']] ?? '#666' ?>"></i>
            <strong><?= e($act['title']) ?></strong>
            <span class="badge bg-light text-dark border ms-2"><?= $actTypeLabels[$act['activity_type']] ?? $act['activity_type'] ?></span>
            <span class="text-muted ms-2" style="font-size:0.8rem;">Max: <?= number_format($act['max_score'], 0) ?> pts</span>
            <?php if ($act['is_submittable']): ?>
            <span class="badge bg-primary ms-2"><i class="fas fa-upload me-1"></i>Submittable</span>
            <?= activityStatusBadge($act) ?>
            <?php endif; ?>
        </span>
        <div class="d-flex align-items-center gap-2">
            <?php if ($act['is_submittable']): ?>
            <a href="<?= BASE_URL ?>/activity-submissions.php?activity_id=<?= $act['id'] ?>" class="btn btn-sm btn-outline-primary" title="View Submissions">
                <i class="fas fa-file-upload me-1"></i>
                <?php if ($role !== 'student'): ?>
                Submissions <span class="badge bg-primary ms-1"><?= intval($act['submission_count']) ?>/<?= count($students) ?></span>
                <?php else: ?>
                Submit
                <?php endif; ?>
            </a>
            <?php endif; ?>
            <span class="badge bg-secondary" style="font-size:0.75rem;">
                <?= intval($act['scored_count']) ?>/<?= count($students) ?> scored
            </span>
            <?php if ($role !== 'student'): ?>
            <button type="button" class="btn btn-sm btn-outline-warning" title="Edit Activity"
                onclick="openEditActivity(<?= htmlspecialchars(json_encode([
                    'id' => $act['id'],
                    'title' => $act['title'],
                    'activity_type' => $act['activity_type'],
                    'max_score' => $act['max_score'],
                    'grading_period' => $act['grading_period'],
                    'description' => $act['description'] ?? '',
                    'is_submittable' => intval($act['is_submittable']),
                    'open_date' => $act['open_date'] ?? '',
                    'due_date' => $act['due_date'] ?? '',
                    'close_date' => $act['close_date'] ?? '',
                    'allow_late' => intval($act['allow_late'] ?? 0),
                    'allow_resubmit' => intval($act['allow_resubmit'] ?? 0),
                    'late_penalty_type' => $act['late_penalty_type'] ?? 'percentage',
                    'late_penalty_amount' => floatval($act['late_penalty_amount'] ?? 0),
                    'late_penalty_interval' => $act['late_penalty_interval'] ?? 'per_day',
                    'late_penalty_max' => $act['late_penalty_max'] ?? '',
                ]), ENT_QUOTES) ?>)">
                <i class="fas fa-edit"></i>
            </button>
            <form method="POST" class="d-inline" onsubmit="return confirmForm(this, 'Delete this activity and all its scores?', 'Delete Activity')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_activity">
                <input type="hidden" name="class_id" value="<?= $classId ?>">
                <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if ($role === 'student'): ?>
        <?php
            $myScore = $activityScores[$act['id']][$user['id']] ?? null;
        ?>
        <div class="p-3">
            <?php if (!empty($act['description'])): ?>
            <div class="mb-2" style="font-size:0.85rem;color:var(--gray-600);">
                <i class="fas fa-info-circle me-1"></i><?= nl2br(e($act['description'])) ?>
            </div>
            <?php endif; ?>
            <?php if ($act['is_submittable'] && !empty($act['due_date'])): ?>
            <div class="mb-2" style="font-size:0.82rem;">
                <i class="fas fa-clock me-1 text-warning"></i>
                <strong>Due:</strong> <?= date('M j, Y g:i A', strtotime($act['due_date'])) ?>
                <?php $policyText = getLatePolicySummary($act); if ($policyText): ?>
                <span class="text-muted ms-2">(<?= e($policyText) ?>)</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted">Your Score:</span>
                <?php if ($myScore !== null): ?>
                <span class="fw-bold fs-5" style="color: <?= ($myScore / $act['max_score'] * 100) >= $passingGrade ? '#10B981' : '#EF4444' ?>">
                    <?= number_format($myScore, 1) ?> / <?= number_format($act['max_score'], 0) ?>
                </span>
                <span class="badge bg-<?= ($myScore / $act['max_score'] * 100) >= $passingGrade ? 'success' : 'danger' ?>-subtle text-<?= ($myScore / $act['max_score'] * 100) >= $passingGrade ? 'success' : 'danger' ?>">
                    <?= number_format($myScore / $act['max_score'] * 100, 1) ?>%
                </span>
                <?php else: ?>
                <span class="text-muted">Not yet scored</span>
                <?php endif; ?>
                <?php if ($act['is_submittable']): ?>
                <a href="<?= BASE_URL ?>/activity-submissions.php?activity_id=<?= $act['id'] ?>" class="btn btn-sm btn-primary ms-auto">
                    <i class="fas fa-upload me-1"></i>View / Submit
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_activity_scores">
            <input type="hidden" name="class_id" value="<?= $classId ?>">
            <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
            <div class="table-responsive">
                <table class="table table-modern mb-0 align-middle">
                    <thead>
                        <tr>
                            <th style="min-width:40px;">#</th>
                            <th style="min-width:180px;">Student</th>
                            <th class="text-center" style="min-width:120px;">Score / <?= number_format($act['max_score'], 0) ?></th>
                            <th class="text-center" style="min-width:80px;">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $idx => $stu): 
                            $sc = $activityScores[$act['id']][$stu['id']] ?? '';
                            $pct = ($sc !== '' && $act['max_score'] > 0) ? number_format(floatval($sc) / $act['max_score'] * 100, 1) : '';
                        ?>
                        <tr>
                            <td class="text-muted"><?= $idx + 1 ?></td>
                            <td>
                                <div class="fw-bold"><?= e($stu['last_name'] . ', ' . $stu['first_name']) ?></div>
                                <div style="font-size:0.75rem;color:var(--gray-400);"><?= e($stu['username']) ?></div>
                            </td>
                            <td class="text-center">
                                <input type="number" name="scores[<?= $stu['id'] ?>]"
                                    class="form-control form-control-sm text-center act-score-input"
                                    value="<?= $sc !== '' ? number_format(floatval($sc), 1) : '' ?>"
                                    min="0" max="<?= $act['max_score'] ?>" step="0.5"
                                    data-max="<?= $act['max_score'] ?>"
                                    placeholder="—"
                                    style="width:90px;margin:0 auto;">
                            </td>
                            <td class="text-center">
                                <span class="act-pct text-muted" style="font-size:0.85rem;"><?= $pct ? $pct . '%' : '—' ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-3 d-flex justify-content-end gap-2 border-top">
                <button type="submit" class="btn btn-sm btn-primary-gradient">
                    <i class="fas fa-save me-1"></i>Save Scores
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($role !== 'student'): ?>
<!-- Create Activity Modal -->
<div class="modal fade" id="createActivityModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_activity">
                <input type="hidden" name="class_id" value="<?= $classId ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>New Graded Activity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Activity Title <span class="text-danger">*</span></label>
                        <input type="text" name="act_title" class="form-control" required maxlength="200" placeholder="e.g. Quiz 1 - Data Structures">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Activity Type <span class="text-danger">*</span></label>
                        <select name="act_type" class="form-select" required>
                            <option value="">Select type...</option>
                            <option value="quiz">Quiz</option>
                            <option value="lab_activity">Lab Activity</option>
                            <option value="exam">Exam</option>
                        </select>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Quiz → syncs to Quiz grade | Lab Activity → syncs to Activity grade | Exam → syncs to Exam grade
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Max Score <span class="text-danger">*</span></label>
                            <input type="number" name="act_max_score" class="form-control" required min="1" max="1000" step="1" value="100">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Grading Period <span class="text-danger">*</span></label>
                            <select name="act_period" class="form-select" required>
                                <option value="midterm" <?= $period === 'midterm' ? 'selected' : '' ?>>Midterm</option>
                                <option value="final" <?= $period === 'final' ? 'selected' : '' ?>>Final</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Description / Instructions</label>
                        <textarea name="act_description" class="form-control" rows="2" maxlength="2000" placeholder="Optional instructions for students..."></textarea>
                    </div>

                    <hr>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="is_submittable" id="chkSubmittable" value="1">
                        <label class="form-check-label fw-bold" for="chkSubmittable">
                            <i class="fas fa-upload me-1"></i>Require File Submission
                        </label>
                        <div class="form-text">Students must upload a document (PDF, DOCX, etc.) up to 25 MB.</div>
                    </div>

                    <div id="submissionFields" style="display:none;">
                        <div class="card border-primary border-opacity-25 mb-3">
                            <div class="card-body">
                                <h6 class="fw-bold text-primary mb-3"><i class="fas fa-calendar-alt me-1"></i>Deadline Settings</h6>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Open Date</label>
                                        <input type="datetime-local" name="open_date" class="form-control form-control-sm">
                                        <div class="form-text">When students can start submitting</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Due Date</label>
                                        <input type="datetime-local" name="due_date" class="form-control form-control-sm">
                                        <div class="form-text">Submission deadline</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Close Date</label>
                                        <input type="datetime-local" name="close_date" class="form-control form-control-sm">
                                        <div class="form-text">No submissions after this</div>
                                    </div>
                                </div>

                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" name="allow_resubmit" id="chkResubmit" value="1" checked>
                                    <label class="form-check-label" for="chkResubmit">Allow resubmission (keeps version history)</label>
                                </div>

                                <hr class="my-3">
                                <h6 class="fw-bold text-warning mb-3"><i class="fas fa-exclamation-triangle me-1"></i>Late Penalty</h6>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="allow_late" id="chkAllowLate" value="1">
                                    <label class="form-check-label" for="chkAllowLate">Accept late submissions (with penalty)</label>
                                </div>

                                <div id="latePenaltyFields" style="display:none;">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Penalty Type</label>
                                            <select name="late_penalty_type" class="form-select form-select-sm">
                                                <option value="percentage">Percentage (%)</option>
                                                <option value="fixed">Fixed Points</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Amount</label>
                                            <input type="number" name="late_penalty_amount" class="form-control form-control-sm" min="0" max="100" step="0.5" value="5" placeholder="e.g. 5">
                                            <div class="form-text">Deducted per interval</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Interval</label>
                                            <select name="late_penalty_interval" class="form-select form-select-sm">
                                                <option value="per_day">Per Day Late</option>
                                                <option value="per_hour">Per Hour Late</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row g-3 mt-1">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Max Penalty Cap</label>
                                            <input type="number" name="late_penalty_max" class="form-control form-control-sm" min="0" max="100" step="0.5" placeholder="No cap">
                                            <div class="form-text">Leave blank for no cap</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-gradient">
                        <i class="fas fa-plus me-1"></i>Create Activity
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle submission fields visibility (Create modal)
document.getElementById('chkSubmittable').addEventListener('change', function() {
    document.getElementById('submissionFields').style.display = this.checked ? 'block' : 'none';
});
document.getElementById('chkAllowLate').addEventListener('change', function() {
    document.getElementById('latePenaltyFields').style.display = this.checked ? 'block' : 'none';
});
</script>

<!-- Edit Activity Modal -->
<div class="modal fade" id="editActivityModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_activity">
                <input type="hidden" name="class_id" value="<?= $classId ?>">
                <input type="hidden" name="activity_id" id="edit_activity_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Graded Activity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Activity Title <span class="text-danger">*</span></label>
                        <input type="text" name="act_title" id="edit_title" class="form-control" required maxlength="200">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Activity Type <span class="text-danger">*</span></label>
                        <select name="act_type" id="edit_type" class="form-select" required>
                            <option value="quiz">Quiz</option>
                            <option value="lab_activity">Lab Activity</option>
                            <option value="exam">Exam</option>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Max Score <span class="text-danger">*</span></label>
                            <input type="number" name="act_max_score" id="edit_max_score" class="form-control" required min="1" max="1000" step="1">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Grading Period <span class="text-danger">*</span></label>
                            <select name="act_period" id="edit_period" class="form-select" required>
                                <option value="midterm">Midterm</option>
                                <option value="final">Final</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description / Instructions</label>
                        <textarea name="act_description" id="edit_description" class="form-control" rows="2" maxlength="2000"></textarea>
                    </div>
                    <hr>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="is_submittable" id="edit_chkSubmittable" value="1">
                        <label class="form-check-label fw-bold" for="edit_chkSubmittable">
                            <i class="fas fa-upload me-1"></i>Require File Submission
                        </label>
                    </div>
                    <div id="edit_submissionFields" style="display:none;">
                        <div class="card border-primary border-opacity-25 mb-3">
                            <div class="card-body">
                                <h6 class="fw-bold text-primary mb-3"><i class="fas fa-calendar-alt me-1"></i>Deadline Settings</h6>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Open Date</label>
                                        <input type="datetime-local" name="open_date" id="edit_open_date" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Due Date</label>
                                        <input type="datetime-local" name="due_date" id="edit_due_date" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Close Date</label>
                                        <input type="datetime-local" name="close_date" id="edit_close_date" class="form-control form-control-sm">
                                    </div>
                                </div>
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" name="allow_resubmit" id="edit_chkResubmit" value="1">
                                    <label class="form-check-label" for="edit_chkResubmit">Allow resubmission</label>
                                </div>
                                <hr class="my-3">
                                <h6 class="fw-bold text-warning mb-3"><i class="fas fa-exclamation-triangle me-1"></i>Late Penalty</h6>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="allow_late" id="edit_chkAllowLate" value="1">
                                    <label class="form-check-label" for="edit_chkAllowLate">Accept late submissions (with penalty)</label>
                                </div>
                                <div id="edit_latePenaltyFields" style="display:none;">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Penalty Type</label>
                                            <select name="late_penalty_type" id="edit_penalty_type" class="form-select form-select-sm">
                                                <option value="percentage">Percentage (%)</option>
                                                <option value="fixed">Fixed Points</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Amount</label>
                                            <input type="number" name="late_penalty_amount" id="edit_penalty_amount" class="form-control form-control-sm" min="0" max="100" step="0.5">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Interval</label>
                                            <select name="late_penalty_interval" id="edit_penalty_interval" class="form-select form-select-sm">
                                                <option value="per_day">Per Day Late</option>
                                                <option value="per_hour">Per Hour Late</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row g-3 mt-1">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Max Penalty Cap</label>
                                            <input type="number" name="late_penalty_max" id="edit_penalty_max" class="form-control form-control-sm" min="0" max="100" step="0.5" placeholder="No cap">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-gradient">
                        <i class="fas fa-save me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle submission fields visibility (Edit modal)
document.getElementById('edit_chkSubmittable').addEventListener('change', function() {
    document.getElementById('edit_submissionFields').style.display = this.checked ? 'block' : 'none';
});
document.getElementById('edit_chkAllowLate').addEventListener('change', function() {
    document.getElementById('edit_latePenaltyFields').style.display = this.checked ? 'block' : 'none';
});

function formatDatetimeLocal(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return '';
    const pad = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}

function openEditActivity(data) {
    document.getElementById('edit_activity_id').value = data.id;
    document.getElementById('edit_title').value = data.title;
    document.getElementById('edit_type').value = data.activity_type;
    document.getElementById('edit_max_score').value = data.max_score;
    document.getElementById('edit_period').value = data.grading_period;
    document.getElementById('edit_description').value = data.description || '';

    // Submittable toggle
    const chkSub = document.getElementById('edit_chkSubmittable');
    chkSub.checked = !!data.is_submittable;
    document.getElementById('edit_submissionFields').style.display = chkSub.checked ? 'block' : 'none';

    // Dates
    document.getElementById('edit_open_date').value = formatDatetimeLocal(data.open_date);
    document.getElementById('edit_due_date').value = formatDatetimeLocal(data.due_date);
    document.getElementById('edit_close_date').value = formatDatetimeLocal(data.close_date);

    // Resubmit
    document.getElementById('edit_chkResubmit').checked = !!data.allow_resubmit;

    // Late penalty
    const chkLate = document.getElementById('edit_chkAllowLate');
    chkLate.checked = !!data.allow_late;
    document.getElementById('edit_latePenaltyFields').style.display = chkLate.checked ? 'block' : 'none';
    document.getElementById('edit_penalty_type').value = data.late_penalty_type || 'percentage';
    document.getElementById('edit_penalty_amount').value = data.late_penalty_amount || 0;
    document.getElementById('edit_penalty_interval').value = data.late_penalty_interval || 'per_day';
    document.getElementById('edit_penalty_max').value = data.late_penalty_max || '';

    new bootstrap.Modal(document.getElementById('editActivityModal')).show();
}
</script>
<?php endif; ?>

<script>
document.querySelectorAll('.act-score-input').forEach(inp => {
    inp.addEventListener('input', function() {
        const max = parseFloat(this.dataset.max) || 100;
        let v = parseFloat(this.value);
        const pctEl = this.closest('tr').querySelector('.act-pct');
        if (!isNaN(v) && v >= 0) {
            if (v > max) { v = max; this.value = v; }
            pctEl.textContent = (v / max * 100).toFixed(1) + '%';
            pctEl.style.color = (v / max * 100) >= <?= $passingGrade ?> ? '#10B981' : '#EF4444';
        } else {
            pctEl.textContent = '—';
            pctEl.style.color = '';
        }
    });
});
</script>

<?php elseif ($view === 'weights'): ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <span><i class="fas fa-balance-scale me-2"></i>% Component Weights</span>
                <?php if ($hasCustomWeights): ?>
                <span class="badge bg-info ms-2">Custom</span>
                <?php else: ?>
                <span class="badge bg-secondary ms-2">System Defaults</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="alert alert-info" style="font-size:0.85rem;">
                    <i class="fas fa-info-circle me-1"></i>
                    Component weights must total <strong>100%</strong>. Adjust the values below to set the grading breakdown for this class.
                    Components with 0% weight will not appear in the grade sheet.
                </div>
                <form method="POST" id="weightsForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_weights">
                    <input type="hidden" name="class_id" value="<?= $classId ?>">
                    <div class="row g-3">
                        <?php foreach ($componentMeta as $key => $meta): ?>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas <?= $meta['icon'] ?> me-1" style="color:<?= $meta['color'] ?>"></i>
                                <?= $meta['label'] ?> (%)
                            </label>
                            <input type="number" name="w_<?= $key ?>" class="form-control weight-input"
                                value="<?= number_format($classWeights[$key], 2, '.', '') ?>"
                                min="0" max="100" step="0.01" required>
                        </div>
                        <?php endforeach; ?>

                        <div class="col-12">
                            <div class="d-flex align-items-center gap-3 p-3 rounded" style="background:var(--gray-50);">
                                <span class="text-muted fw-bold" style="font-size:0.85rem;">Total:</span>
                                <span class="fw-bold fs-5" id="weightTotal">100.00</span><span class="text-muted">%</span>
                                <span id="weightStatus"></span>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="d-flex rounded overflow-hidden" style="height:32px;" id="weightBar"></div>
                        </div>

                        <div class="col-12 d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn btn-primary-gradient" id="saveWeightsBtn">
                                <i class="fas fa-save me-1"></i>Save Policy
                            </button>
                            <?php if ($hasCustomWeights): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirmForm(this, 'Reset weights to system defaults? Custom weights for this class will be removed.', 'Reset Weights')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reset_weights">
                                <input type="hidden" name="class_id" value="<?= $classId ?>">
                                <button type="submit" class="btn btn-outline-warning">
                                    <i class="fas fa-undo me-1"></i>Reset to Defaults
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><span><i class="fas fa-chart-pie me-2"></i>Current Breakdown</span></div>
            <div class="card-body">
                <?php foreach ($componentMeta as $key => $meta): $val = $classWeights[$key]; ?>
                <div class="d-flex align-items-center gap-2 py-2" style="border-bottom:1px solid var(--gray-100);">
                    <div style="width:10px;height:10px;border-radius:50%;background:<?= $meta['color'] ?>;"></div>
                    <div class="flex-grow-1" style="font-size:0.85rem;"><?= $meta['label'] ?></div>
                    <div class="fw-bold" style="font-size:0.85rem;"><?= number_format($val, 2) ?>%</div>
                </div>
                <?php endforeach; ?>
                <?php $totalW = array_sum($classWeights); ?>
                <div class="d-flex align-items-center gap-2 py-2 mt-1">
                    <div style="width:10px;height:10px;border-radius:50%;background:var(--gray-600);"></div>
                    <div class="flex-grow-1 fw-bold" style="font-size:0.85rem;">Total</div>
                    <div class="fw-bold <?= abs($totalW - 100) < 0.01 ? 'text-success' : 'text-danger' ?>" style="font-size:0.85rem;"><?= number_format($totalW, 2) ?>%</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><span><i class="fas fa-info-circle me-2"></i>About Weights</span></div>
            <div class="card-body" style="font-size:0.85rem;color:var(--gray-500);">
                <p class="mb-2"><i class="fas fa-check text-success me-1"></i> Each class can have its own custom weight distribution.</p>
                <p class="mb-2"><i class="fas fa-check text-success me-1"></i> If no custom weights are set, system defaults are used.</p>
                <p class="mb-2"><i class="fas fa-check text-success me-1"></i> Components set to 0% are hidden from the grade sheet.</p>
                <p class="mb-0"><i class="fas fa-check text-success me-1"></i> Passing grade: <strong><?= $passingGrade ?>%</strong> (set by admin).</p>
            </div>
        </div>
    </div>
</div>

<script>
const wColors = ['#10B981','#3B82F6','#F59E0B','#8B5CF6','#EF4444'];
const wLabels = ['Attendance','Activity','Quiz','Project','Exam'];

function updateWeightUI() {
    const inputs = document.querySelectorAll('.weight-input');
    let total = 0;
    const vals = [];
    inputs.forEach(inp => { const v = parseFloat(inp.value) || 0; total += v; vals.push(v); });

    const totalEl = document.getElementById('weightTotal');
    const statusEl = document.getElementById('weightStatus');
    const barEl = document.getElementById('weightBar');
    const saveBtn = document.getElementById('saveWeightsBtn');

    totalEl.textContent = total.toFixed(2);

    if (Math.abs(total - 100) < 0.01) {
        totalEl.style.color = 'var(--success)';
        statusEl.innerHTML = '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Valid</span>';
        saveBtn.disabled = false;
    } else {
        totalEl.style.color = 'var(--danger, #EF4444)';
        statusEl.innerHTML = '<span class="badge bg-danger"><i class="fas fa-times me-1"></i>' + (total < 100 ? 'Under' : 'Over') + ' by ' + Math.abs(total - 100).toFixed(2) + '%</span>';
        saveBtn.disabled = true;
    }

    let barHTML = '';
    vals.forEach((v, i) => {
        if (v > 0) {
            barHTML += '<div style="width:'+v+'%;background:'+wColors[i]+';display:flex;align-items:center;justify-content:center;color:#fff;font-size:0.7rem;font-weight:600;transition:width .3s;" title="'+wLabels[i]+': '+v+'%">'+(v >= 8 ? wLabels[i]+' '+v+'%' : v+'%')+'</div>';
        }
    });
    barEl.innerHTML = barHTML;
}

document.querySelectorAll('.weight-input').forEach(inp => {
    inp.addEventListener('input', updateWeightUI);
    inp.addEventListener('change', function() {
        let v = parseFloat(this.value);
        if (isNaN(v) || v < 0) v = 0;
        if (v > 100) v = 100;
        this.value = v.toFixed(2);
        updateWeightUI();
    });
});
updateWeightUI();

document.getElementById('weightsForm').addEventListener('submit', function(e) {
    const inputs = document.querySelectorAll('.weight-input');
    let total = 0;
    inputs.forEach(inp => total += parseFloat(inp.value) || 0);
    if (Math.abs(total - 100) > 0.01) {
        e.preventDefault();
        if (typeof showToast === 'function') showToast('Component weights must total exactly 100%. Current: ' + total.toFixed(2) + '%', 'error');
    }
});
</script>

<?php else: ?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/grades.php?class_id=<?= $classId ?>&period=midterm" class="btn btn-sm <?= $period === 'midterm' ? 'btn-primary' : 'btn-outline-secondary' ?>">Midterm</a>
        <a href="<?= BASE_URL ?>/grades.php?class_id=<?= $classId ?>&period=final" class="btn btn-sm <?= $period === 'final' ? 'btn-primary' : 'btn-outline-secondary' ?>">Final</a>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <?php if ($hasCustomWeights): ?>
        <span class="badge bg-info" style="font-size:0.75rem;"><i class="fas fa-sliders-h me-1"></i>Custom Weights</span>
        <?php endif; ?>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-file-export me-1"></i>Export / Print
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><h6 class="dropdown-header"><i class="fas fa-print me-1"></i>Print View</h6></li>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/grade-export.php?type=print&mode=class&class_id=<?= $classId ?>&period=<?= e($period) ?>" target="_blank">
                    <i class="fas fa-table me-2 text-primary"></i><?= ucfirst($period) ?> Grade Sheet
                </a></li>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/grade-export.php?type=print&mode=class&class_id=<?= $classId ?>&period=both" target="_blank">
                    <i class="fas fa-layer-group me-2 text-info"></i>Full Report (Both Periods)
                </a></li>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header"><i class="fas fa-file-csv me-1"></i>CSV Download</h6></li>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/grade-export.php?type=csv&mode=class&class_id=<?= $classId ?>&period=<?= e($period) ?>">
                    <i class="fas fa-download me-2 text-success"></i><?= ucfirst($period) ?> Grades (CSV)
                </a></li>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/grade-export.php?type=csv&mode=class&class_id=<?= $classId ?>&period=both">
                    <i class="fas fa-download me-2 text-success"></i>All Periods (CSV)
                </a></li>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header"><i class="fas fa-user me-1"></i>Per Student</h6></li>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/grade-export.php?type=print&mode=class&class_id=<?= $classId ?>&period=both" target="_blank">
                    <i class="fas fa-id-card me-2 text-purple"></i>Print All Student Reports
                </a></li>
            </ul>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center" style="font-size:0.82rem;">
            <span class="fw-bold text-muted"><i class="fas fa-weight-hanging me-1"></i>Component Weights:</span>
            <?php foreach ($activeComponents as $key => $comp): ?>
            <span class="badge bg-light text-dark border">
                <i class="fas <?= $comp['icon'] ?> me-1" style="color:<?= $comp['color'] ?>"></i><?= e($comp['label']) ?>: <?= number_format($comp['weight'], 1) ?>%
            </span>
            <?php endforeach; ?>
            <span class="badge bg-warning text-dark">Passing: <?= $passingGrade ?>%</span>
            <a href="<?= BASE_URL ?>/grades.php?class_id=<?= $classId ?>&view=weights" class="ms-auto text-primary" style="font-size:0.78rem;"><i class="fas fa-edit me-1"></i>Edit Weights</a>
        </div>
    </div>
</div>

<?php if (empty($students)): ?>
<div class="card">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-user-slash fa-2x mb-2 d-block"></i>
        No students enrolled in this class.
    </div>
</div>
<?php else: ?>
<form method="POST" id="gradesForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_grades">
    <input type="hidden" name="class_id" value="<?= $classId ?>">
    <input type="hidden" name="grading_period" value="<?= e($period) ?>">

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-modern mb-0 align-middle" id="gradesTable">
                    <thead>
                        <tr>
                            <th style="min-width:40px;">#</th>
                            <th style="min-width:180px;">Student</th>
                            <?php foreach ($activeComponents as $key => $comp): ?>
                            <th class="text-center" style="min-width:100px;">
                                <i class="fas <?= $comp['icon'] ?> me-1" style="color:<?= $comp['color'] ?>;font-size:0.7rem;"></i>
                                <?= e($comp['label']) ?>
                                <div style="font-size:0.7rem;font-weight:400;color:var(--gray-400);"><?= number_format($comp['weight'], 1) ?>%</div>
                            </th>
                            <?php endforeach; ?>
                            <th class="text-center" style="min-width:100px;">Weighted Avg</th>
                            <th class="text-center" style="min-width:90px;background:#F0F9FF;">
                                <i class="fas fa-calculator me-1" style="font-size:0.7rem;color:#3B82F6;"></i>Midterm
                                <div style="font-size:0.65rem;font-weight:400;color:var(--gray-400);">WA</div>
                            </th>
                            <th class="text-center" style="min-width:90px;background:#FFF7ED;">
                                <i class="fas fa-calculator me-1" style="font-size:0.7rem;color:#F59E0B;"></i>Final
                                <div style="font-size:0.65rem;font-weight:400;color:var(--gray-400);">Tentative WA</div>
                            </th>
                            <th class="text-center" style="min-width:100px;background:#F0FDF4;">
                                <i class="fas fa-graduation-cap me-1" style="font-size:0.7rem;color:#10B981;"></i>Final Grade
                                <div style="font-size:0.65rem;font-weight:400;color:var(--gray-400);">(M+F)&divide;2</div>
                            </th>
                            <th class="text-center" style="min-width:80px;">Status</th>
                            <th class="text-center" style="min-width:60px;">Export</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $idx => $stu): ?>
                        <tr>
                            <td class="text-muted"><?= $idx + 1 ?></td>
                            <td>
                                <div class="fw-bold"><?= e($stu['last_name'] . ', ' . $stu['first_name']) ?></div>
                                <div style="font-size:0.75rem;color:var(--gray-400);"><?= e($stu['username']) ?></div>
                            </td>
                            <?php foreach ($activeComponents as $key => $comp): ?>
                            <td class="text-center">
                                <?php if ($key === 'attendance'): ?>
                                <input type="number" name="grades[<?= $stu['id'] ?>][<?= $key ?>]"
                                    class="form-control form-control-sm text-center grade-input"
                                    value="<?= isset($currentGrades[$stu['id']][$key]) ? number_format($currentGrades[$stu['id']][$key], 2) : '0.00' ?>"
                                    min="0" max="100" step="0.01"
                                    data-student="<?= $stu['id'] ?>" data-weight="<?= $comp['weight'] ?>"
                                    style="width:80px;margin:0 auto;background:#f0fdf4;cursor:not-allowed;"
                                    readonly title="Auto-computed from Attendance records">
                                <?php else: ?>
                                <input type="number" name="grades[<?= $stu['id'] ?>][<?= $key ?>]"
                                    class="form-control form-control-sm text-center grade-input"
                                    value="<?= isset($currentGrades[$stu['id']][$key]) ? number_format($currentGrades[$stu['id']][$key], 2) : '0.00' ?>"
                                    min="0" max="100" step="0.01"
                                    data-student="<?= $stu['id'] ?>" data-weight="<?= $comp['weight'] ?>"
                                    style="width:80px;margin:0 auto;">
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="text-center">
                                <span class="fw-bold weighted-avg" id="avg_<?= $stu['id'] ?>" style="font-size:0.95rem;">0.00</span>
                            </td>
                            <td class="text-center" style="background:#F0F9FF;">
                                <span class="fw-bold" id="mwa_<?= $stu['id'] ?>" style="font-size:0.85rem;color:#3B82F6;">
                                    <?= number_format($periodWA['midterm'][$stu['id']] ?? 0, 2) ?>
                                </span>
                            </td>
                            <td class="text-center" style="background:#FFF7ED;">
                                <span class="fw-bold" id="fwa_<?= $stu['id'] ?>" style="font-size:0.85rem;color:#F59E0B;">
                                    <?= number_format($periodWA['final'][$stu['id']] ?? 0, 2) ?>
                                </span>
                            </td>
                            <td class="text-center" style="background:#F0FDF4;">
                                <?php
                                $stuMidWA = $periodWA['midterm'][$stu['id']] ?? 0;
                                $stuFinWA = $periodWA['final'][$stu['id']] ?? 0;
                                $stuHasBoth = ($stuMidWA > 0 && $stuFinWA > 0);
                                $fg = $stuHasBoth ? round(($stuMidWA + $stuFinWA) / 2, 2) : null;
                                ?>
                                <span class="fw-bold" id="fg_<?= $stu['id'] ?>" style="font-size:1rem;color:<?= ($fg !== null && $fg >= $passingGrade) ? '#065F46' : ($fg !== null ? '#991B1B' : '#9CA3AF') ?>;">
                                    <?= $fg !== null ? number_format($fg, 2) : '--' ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="grade-status" id="status_<?= $stu['id'] ?>"></span>
                            </td>
                            <td class="text-center">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="dropdown" title="Export student grades">
                                        <i class="fas fa-file-export" style="font-size:0.75rem;"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/grade-export.php?type=print&mode=student&class_id=<?= $classId ?>&student_id=<?= $stu['id'] ?>&period=both" target="_blank">
                                            <i class="fas fa-print me-2 text-primary"></i>Print Report
                                        </a></li>
                                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/grade-export.php?type=csv&mode=student&class_id=<?= $classId ?>&student_id=<?= $stu['id'] ?>&period=both">
                                            <i class="fas fa-file-csv me-2 text-success"></i>Download CSV
                                        </a></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <div style="font-size:0.82rem;color:var(--gray-500);">
            <i class="fas fa-info-circle me-1"></i>Enter scores (0-100) for each component. Weighted average is calculated automatically.
        </div>
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>/grade-export.php?type=print&mode=class&class_id=<?= $classId ?>&period=<?= e($period) ?>" target="_blank" class="btn btn-outline-primary">
                <i class="fas fa-print me-1"></i>Print
            </a>
            <a href="<?= BASE_URL ?>/grade-export.php?type=csv&mode=class&class_id=<?= $classId ?>&period=<?= e($period) ?>" class="btn btn-outline-success">
                <i class="fas fa-file-csv me-1"></i>CSV
            </a>
            <button type="submit" class="btn btn-primary-gradient" onclick="return confirmForm(this.form, 'Save all grades for this class (<?= e($period) ?>)? This will overwrite existing entries.', 'Save Grades')">
                <i class="fas fa-save me-1"></i>Save Grades
            </button>
        </div>
    </div>
</form>

<script>
// Other period's grades (read-only, from DB)
const otherPeriodGrades = <?php
    $otherPeriod = ($period === 'midterm') ? 'final' : 'midterm';
    $otherData = [];
    foreach ($students as $stu) {
        $wa = 0;
        foreach ($activeComponents as $key => $comp) {
            $score = floatval($allPeriodGrades[$otherPeriod][$stu['id']][$key] ?? 0);
            $wa += ($score * $comp['weight'] / 100);
        }
        $otherData[$stu['id']] = round($wa, 2);
    }
    echo json_encode($otherData);
?>;
const currentPeriod = '<?= e($period) ?>';

function recalcGrades() {
    const students = new Set();
    document.querySelectorAll('.grade-input').forEach(input => students.add(input.dataset.student));

    students.forEach(sid => {
        const inputs = document.querySelectorAll('.grade-input[data-student="'+sid+'"]');
        let totalWeighted = 0, totalWeight = 0;
        inputs.forEach(inp => {
            const w = parseFloat(inp.dataset.weight) || 0;
            const v = parseFloat(inp.value) || 0;
            totalWeighted += (v * w / 100);
            totalWeight += w;
        });
        const avg = totalWeight > 0 ? totalWeighted : 0;
        const avgEl = document.getElementById('avg_' + sid);
        const statusEl = document.getElementById('status_' + sid);
        avgEl.textContent = avg.toFixed(2);

        // Update Midterm WA & Final WA columns
        const mwaEl = document.getElementById('mwa_' + sid);
        const fwaEl = document.getElementById('fwa_' + sid);
        const fgEl = document.getElementById('fg_' + sid);

        let midWA, finWA;
        if (currentPeriod === 'midterm') {
            midWA = avg;
            finWA = otherPeriodGrades[sid] || 0;
        } else {
            midWA = otherPeriodGrades[sid] || 0;
            finWA = avg;
        }

        mwaEl.textContent = midWA.toFixed(2);
        fwaEl.textContent = finWA.toFixed(2);

        const passing = <?= $passingGrade ?>;
        const hasBothPeriods = midWA > 0 && finWA > 0;
        let gradeForStatus;

        if (hasBothPeriods) {
            const finalGrade = (midWA + finWA) / 2;
            fgEl.textContent = finalGrade.toFixed(2);
            fgEl.style.color = finalGrade >= passing ? '#065F46' : '#991B1B';
            gradeForStatus = finalGrade;
        } else {
            fgEl.textContent = '--';
            fgEl.style.color = '#9CA3AF';
            gradeForStatus = avg; // Use current period WA for status
        }

        // Color Midterm WA
        mwaEl.style.color = midWA >= passing ? '#065F46' : '#991B1B';
        // Color Final WA
        fwaEl.style.color = finWA >= passing ? '#065F46' : '#991B1B';

        // Status based on available grade
        if (gradeForStatus >= passing) {
            avgEl.style.color = 'var(--success)';
            statusEl.innerHTML = '<span class="badge bg-success-subtle text-success">Passed</span>';
        } else {
            avgEl.style.color = 'var(--danger, #EF4444)';
            statusEl.innerHTML = '<span class="badge bg-danger-subtle text-danger">Failed</span>';
        }
    });
}

document.querySelectorAll('.grade-input').forEach(input => {
    input.addEventListener('input', recalcGrades);
    input.addEventListener('change', function() {
        let v = parseFloat(this.value);
        if (isNaN(v) || v < 0) v = 0;
        if (v > 100) v = 100;
        this.value = v.toFixed(2);
        recalcGrades();
    });
});
recalcGrades();
</script>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
