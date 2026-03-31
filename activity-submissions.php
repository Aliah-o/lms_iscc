<?php
$pageTitle = 'Activity Submission';
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'superadmin', 'student');
$pdo = getDB();
$user = currentUser();
$role = $user['role'];
$breadcrumbPills = [$role === 'superadmin' ? 'Administration' : ($role === 'student' ? 'My Learning' : 'Teaching'), 'Activity Submission'];

// ─── Load Activity ───
$activityId = intval($_GET['activity_id'] ?? 0);
if (!$activityId) { flash('error', 'Activity not found.'); redirect('/grades.php'); }

$stmt = $pdo->prepare("SELECT ga.*, ic.instructor_id, ic.subject_name, ic.course_code, ic.program_code, ic.year_level, ic.section_id,
    s.section_name
    FROM graded_activities ga
    JOIN instructor_classes ic ON ga.class_id = ic.id
    JOIN sections s ON ic.section_id = s.id
    WHERE ga.id = ?");
$stmt->execute([$activityId]);
$activity = $stmt->fetch();
if (!$activity) { flash('error', 'Activity not found.'); redirect('/grades.php'); }

$classId = $activity['class_id'];

// ─── Authorization ───
if ($role === 'instructor') {
    if ($activity['instructor_id'] != $user['id']) {
        flash('error', 'Access denied.');
        redirect('/grades.php');
    }
} elseif ($role === 'student') {
    $enrollChk = $pdo->prepare("SELECT 1 FROM class_enrollments WHERE class_id = ? AND student_id = ?");
    $enrollChk->execute([$classId, $user['id']]);
    if (!$enrollChk->fetch()) {
        flash('error', 'You are not enrolled in this class.');
        redirect('/grades.php');
    }
}

$actTypeLabels = ['quiz' => 'Quiz', 'lab_activity' => 'Lab Activity', 'exam' => 'Exam'];
$actTypeColors = ['quiz' => '#F59E0B', 'lab_activity' => '#3B82F6', 'exam' => '#EF4444'];
$actTypeIcons = ['quiz' => 'fa-question-circle', 'lab_activity' => 'fa-flask', 'exam' => 'fa-file-alt'];

$actStatus = getActivityStatus($activity);
$passingGrade = floatval(getSetting('passing_grade', '75'));

// ─── POST Handlers ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect("/activity-submissions.php?activity_id=$activityId"); }

    $action = $_POST['action'] ?? '';

    // ─── Student: Submit File ───
    if ($action === 'submit' && $role === 'student') {
        if (!$activity['is_submittable']) {
            flash('error', 'This activity does not accept submissions.');
            redirect("/activity-submissions.php?activity_id=$activityId");
        }

        // Check existing submission
        $existStmt = $pdo->prepare("SELECT * FROM activity_submissions WHERE activity_id = ? AND student_id = ?");
        $existStmt->execute([$activityId, $user['id']]);
        $existing = $existStmt->fetch();

        if (!canStudentSubmit($activity, $existing)) {
            flash('error', 'Submissions are not open for this activity.');
            redirect("/activity-submissions.php?activity_id=$activityId");
        }

        // Validate file
        $fileCheck = validateSubmissionFile($_FILES['submission_file'] ?? null);
        if (!$fileCheck['ok']) {
            flash('error', $fileCheck['error']);
            redirect("/activity-submissions.php?activity_id=$activityId");
        }

        $file = $_FILES['submission_file'];
        $originalName = basename($file['name']);
        $storedName = storeSubmissionFile($file['tmp_name'], $originalName, $activityId, $user['id']);

        if (!$storedName) {
            flash('error', 'Failed to save file. Please try again.');
            redirect("/activity-submissions.php?activity_id=$activityId");
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file(__DIR__ . '/uploads/submissions/' . $storedName);
        $isLate = ($actStatus === 'overdue') ? 1 : 0;
        $now = date('Y-m-d H:i:s');

        if ($existing) {
            // Archive current submission to history
            $pdo->prepare("INSERT INTO submission_history (activity_id, student_id, file_name, original_name, file_type, file_size, version, submitted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$activityId, $user['id'], $existing['file_name'], $existing['original_name'],
                    $existing['file_type'], $existing['file_size'], $existing['version'], $existing['submitted_at']]);

            // Update current submission
            $newVersion = intval($existing['version']) + 1;
            $pdo->prepare("UPDATE activity_submissions SET
                file_name = ?, original_name = ?, file_type = ?, file_size = ?,
                submitted_at = ?, is_late = ?, version = ?, status = 'submitted',
                raw_score = NULL, late_penalty = NULL, final_score = NULL, feedback = NULL, graded_by = NULL, graded_at = NULL
                WHERE activity_id = ? AND student_id = ?")
                ->execute([$storedName, $originalName, $mimeType, $file['size'],
                    $now, $isLate, $newVersion, $activityId, $user['id']]);

            flash('success', "Resubmission uploaded successfully (version $newVersion).");
        } else {
            $pdo->prepare("INSERT INTO activity_submissions (activity_id, student_id, file_name, original_name, file_type, file_size, submitted_at, is_late, status, version)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'submitted', 1)")
                ->execute([$activityId, $user['id'], $storedName, $originalName, $mimeType, $file['size'], $now, $isLate]);
            flash('success', 'File submitted successfully.');
        }

        auditLog('submission_uploaded', "Student submitted file for activity #$activityId" . ($isLate ? ' (late)' : ''));

        // Notify instructor about the submission
        $studentName = $user['first_name'] . ' ' . $user['last_name'];
        $notifLink = BASE_URL . "/activity-submissions.php?activity_id=$activityId";
        addNotification(
            $activity['instructor_id'],
            'student_submission',
            "$studentName submitted " . ($existing ? 'a resubmission for' : 'work for') . " \"{$activity['title']}\"" . ($isLate ? ' (late)' : ''),
            $activityId,
            $notifLink
        );

        redirect("/activity-submissions.php?activity_id=$activityId");
    }

    // ─── Teacher: Grade Submission ───
    elseif ($action === 'grade' && ($role === 'instructor' || $role === 'superadmin')) {
        $studentId = intval($_POST['student_id'] ?? 0);
        $rawScore = max(0, min(floatval($activity['max_score']), floatval($_POST['raw_score'] ?? 0)));
        $feedback = trim($_POST['feedback'] ?? '');

        $subStmt = $pdo->prepare("SELECT * FROM activity_submissions WHERE activity_id = ? AND student_id = ?");
        $subStmt->execute([$activityId, $studentId]);
        $submission = $subStmt->fetch();

        if (!$submission) {
            flash('error', 'Submission not found.');
            redirect("/activity-submissions.php?activity_id=$activityId");
        }

        // Compute late penalty
        $latePenalty = 0;
        if ($submission['is_late'] && $activity['allow_late']) {
            $latePenalty = computeLatePenalty($activity, $submission['submitted_at']);
        }

        $finalScore = computeFinalScore($activity, $rawScore, $latePenalty);

        $pdo->prepare("UPDATE activity_submissions SET
            raw_score = ?, late_penalty = ?, final_score = ?, feedback = ?,
            graded_by = ?, graded_at = NOW(), status = 'graded'
            WHERE activity_id = ? AND student_id = ?")
            ->execute([$rawScore, $latePenalty, $finalScore, $feedback ?: null,
                $user['id'], $activityId, $studentId]);

        // Sync to graded_activity_scores for grade computation
        syncSubmissionToActivityScore($pdo, $activityId, $studentId, $finalScore, $user['id']);

        // Auto-sync component grades
        syncActivityToGrades($pdo, $classId, $activity['grading_period'], $user['id']);

        auditLog('submission_graded', "Graded student #$studentId on activity #$activityId: raw=$rawScore, penalty=$latePenalty, final=$finalScore");
        flash('success', "Student graded successfully. Final score: $finalScore / " . number_format($activity['max_score'], 0));

        // Notify student about grading
        $graderName = $user['first_name'] . ' ' . $user['last_name'];
        $notifMsg = "$graderName graded your submission for \"{$activity['title']}\": $finalScore / " . number_format($activity['max_score'], 0);
        if (!empty($feedback)) {
            $notifMsg .= " — \"" . (mb_strlen($feedback) > 80 ? mb_substr($feedback, 0, 80) . '...' : $feedback) . "\"";
        }
        $notifLink = BASE_URL . "/activity-submissions.php?activity_id=$activityId";
        addNotification($studentId, 'submission_graded', $notifMsg, $activityId, $notifLink);

        redirect("/activity-submissions.php?activity_id=$activityId");
    }
}

// ─── Load Data ───
$students = [];
$submissions = [];
$submissionHistory = [];

if ($role === 'student') {
    // Student: load own submission
    $subStmt = $pdo->prepare("SELECT * FROM activity_submissions WHERE activity_id = ? AND student_id = ?");
    $subStmt->execute([$activityId, $user['id']]);
    $mySubmission = $subStmt->fetch();

    // Load version history
    if ($mySubmission && $mySubmission['version'] > 1) {
        $histStmt = $pdo->prepare("SELECT * FROM submission_history WHERE activity_id = ? AND student_id = ? ORDER BY version DESC");
        $histStmt->execute([$activityId, $user['id']]);
        $submissionHistory = $histStmt->fetchAll();
    }
} else {
    // Teacher/Admin: load all students + submissions
    $stuStmt = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.username, u.program_code
        FROM class_enrollments ce
        JOIN users u ON ce.student_id = u.id
        WHERE ce.class_id = ?
        ORDER BY u.last_name, u.first_name");
    $stuStmt->execute([$classId]);
    $students = $stuStmt->fetchAll();

    $subStmt = $pdo->prepare("SELECT asub.*, u.first_name as grader_fn, u.last_name as grader_ln
        FROM activity_submissions asub
        LEFT JOIN users u ON asub.graded_by = u.id
        WHERE asub.activity_id = ?");
    $subStmt->execute([$activityId]);
    while ($s = $subStmt->fetch()) {
        $submissions[$s['student_id']] = $s;
    }
}

require_once __DIR__ . '/views/layouts/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <a href="<?= BASE_URL ?>/grades.php?class_id=<?= $classId ?>&view=activities&period=<?= e($activity['grading_period']) ?>" class="btn btn-sm btn-outline-secondary me-2">
            <i class="fas fa-arrow-left me-1"></i>Back to Activities
        </a>
        <span class="fw-bold" style="font-size:1.05rem;">
            <i class="fas <?= $actTypeIcons[$activity['activity_type']] ?? 'fa-file' ?> me-1" style="color:<?= $actTypeColors[$activity['activity_type']] ?? '#666' ?>"></i>
            <?= e($activity['title']) ?>
        </span>
        <span class="badge bg-light text-dark border ms-2"><?= $actTypeLabels[$activity['activity_type']] ?? $activity['activity_type'] ?></span>
        <?php if ($activity['is_submittable']): ?>
        <?= activityStatusBadge($activity) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Activity Info Card -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-8">
                <h6 class="fw-bold mb-2"><?= e($activity['title']) ?></h6>
                <?php if (!empty($activity['description'])): ?>
                <p style="font-size:0.9rem;color:var(--gray-600);"><?= nl2br(e($activity['description'])) ?></p>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-3" style="font-size:0.85rem;">
                    <span><i class="fas fa-book me-1 text-primary"></i><?= e($activity['subject_name']) ?></span>
                    <span><i class="fas fa-graduation-cap me-1 text-info"></i><?= e(PROGRAMS[$activity['program_code']] ?? $activity['program_code']) ?></span>
                    <span><i class="fas fa-calendar me-1 text-secondary"></i><?= ucfirst($activity['grading_period']) ?></span>
                    <span><i class="fas fa-star me-1 text-warning"></i>Max: <?= number_format($activity['max_score'], 0) ?> pts</span>
                </div>
            </div>
            <div class="col-md-4">
                <?php if ($activity['is_submittable']): ?>
                <div class="p-3 rounded" style="background:var(--gray-50);font-size:0.85rem;">
                    <h6 class="fw-bold mb-2"><i class="fas fa-clock me-1"></i>Deadlines</h6>
                    <?php if (!empty($activity['open_date'])): ?>
                    <div class="mb-1"><strong>Opens:</strong> <?= date('M j, Y g:i A', strtotime($activity['open_date'])) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($activity['due_date'])): ?>
                    <div class="mb-1"><strong>Due:</strong> <?= date('M j, Y g:i A', strtotime($activity['due_date'])) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($activity['close_date'])): ?>
                    <div class="mb-1"><strong>Closes:</strong> <?= date('M j, Y g:i A', strtotime($activity['close_date'])) ?></div>
                    <?php endif; ?>
                    <?php $policy = getLatePolicySummary($activity); if ($policy): ?>
                    <div class="mt-2 text-warning"><i class="fas fa-exclamation-triangle me-1"></i><?= e($policy) ?></div>
                    <?php endif; ?>
                    <?php if ($activity['allow_resubmit']): ?>
                    <div class="mt-1 text-success"><i class="fas fa-redo me-1"></i>Resubmission allowed</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($role === 'student'): ?>
<!-- ═══════════════ STUDENT VIEW ═══════════════ -->

<?php if (!$activity['is_submittable']): ?>
<div class="card">
    <div class="card-body text-center py-4 text-muted">
        <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
        This activity does not require a file submission. Your instructor will enter your score directly.
    </div>
</div>

<?php else: ?>

<?php if ($mySubmission): ?>
<!-- Current Submission -->
<div class="card mb-3 border-<?= $mySubmission['status'] === 'graded' ? 'success' : 'primary' ?>">
    <div class="card-header">
        <span>
            <i class="fas fa-file-check me-2"></i>Your Submission
            <?php if ($mySubmission['is_late']): ?>
            <span class="badge bg-warning text-dark ms-2"><i class="fas fa-clock me-1"></i>Late</span>
            <?php endif; ?>
            <span class="badge bg-<?= $mySubmission['status'] === 'graded' ? 'success' : ($mySubmission['status'] === 'submitted' ? 'info' : 'secondary') ?> ms-2">
                <?= ucfirst($mySubmission['status']) ?>
            </span>
            <span class="badge bg-light text-dark border ms-1">v<?= $mySubmission['version'] ?></span>
        </span>
    </div>
    <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#EFF6FF,#DBEAFE);display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-file-alt fa-lg text-primary"></i>
            </div>
            <div class="flex-grow-1">
                <div class="fw-bold"><?= e($mySubmission['original_name']) ?></div>
                <div style="font-size:0.8rem;color:var(--gray-500);">
                    <?= number_format($mySubmission['file_size'] / 1024, 1) ?> KB &bull;
                    Submitted <?= date('M j, Y g:i A', strtotime($mySubmission['submitted_at'])) ?>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/submission-download.php?id=<?= $mySubmission['id'] ?>" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-download me-1"></i>Download
            </a>
        </div>

        <?php if ($mySubmission['status'] === 'graded'): ?>
        <div class="p-3 rounded mb-2" style="background:var(--gray-50);">
            <h6 class="fw-bold mb-2"><i class="fas fa-check-circle text-success me-1"></i>Grade</h6>
            <div class="row g-3">
                <div class="col-auto">
                    <div class="text-muted" style="font-size:0.8rem;">Raw Score</div>
                    <div class="fw-bold fs-5"><?= number_format($mySubmission['raw_score'], 1) ?> / <?= number_format($activity['max_score'], 0) ?></div>
                </div>
                <?php if ($mySubmission['late_penalty'] > 0): ?>
                <div class="col-auto">
                    <div class="text-muted" style="font-size:0.8rem;">Late Penalty</div>
                    <div class="fw-bold fs-5 text-danger">
                        -<?= $activity['late_penalty_type'] === 'percentage'
                            ? number_format($mySubmission['late_penalty'], 1) . '%'
                            : number_format($mySubmission['late_penalty'], 1) . ' pts' ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="col-auto">
                    <div class="text-muted" style="font-size:0.8rem;">Final Score</div>
                    <div class="fw-bold fs-5 <?= ($mySubmission['final_score'] / $activity['max_score'] * 100) >= $passingGrade ? 'text-success' : 'text-danger' ?>">
                        <?= number_format($mySubmission['final_score'], 1) ?> / <?= number_format($activity['max_score'], 0) ?>
                    </div>
                </div>
            </div>
            <?php if (!empty($mySubmission['feedback'])): ?>
            <div class="mt-3 p-3 rounded border" style="background:#FFF7ED;border-color:#FDBA74 !important;">
                <div class="fw-bold mb-1" style="font-size:0.85rem;color:#C2410C;">
                    <i class="fas fa-comment-dots me-1"></i>Instructor's Feedback
                </div>
                <div style="font-size:0.92rem;line-height:1.6;color:#1E293B;"><?= nl2br(e($mySubmission['feedback'])) ?></div>
            </div>
            <?php endif; ?>
            <div class="mt-2" style="font-size:0.75rem;color:var(--gray-400);">
                Graded on <?= date('M j, Y g:i A', strtotime($mySubmission['graded_at'])) ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Version History -->
<?php if (!empty($submissionHistory)): ?>
<div class="card mb-3">
    <div class="card-header">
        <span><i class="fas fa-history me-2"></i>Submission History</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-modern mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Version</th>
                        <th>File</th>
                        <th>Size</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($submissionHistory as $hist): ?>
                    <tr>
                        <td><span class="badge bg-light text-dark border">v<?= $hist['version'] ?></span></td>
                        <td><?= e($hist['original_name']) ?></td>
                        <td><?= number_format($hist['file_size'] / 1024, 1) ?> KB</td>
                        <td><?= date('M j, Y g:i A', strtotime($hist['submitted_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Submit / Resubmit Form -->
<?php if (canStudentSubmit($activity, $mySubmission)): ?>
<div class="card border-primary border-opacity-25">
    <div class="card-header bg-primary bg-opacity-10">
        <span class="fw-bold text-primary">
            <i class="fas fa-upload me-2"></i><?= $mySubmission ? 'Resubmit' : 'Submit Your Work' ?>
        </span>
    </div>
    <div class="card-body">
        <?php if ($actStatus === 'overdue'): ?>
        <div class="alert alert-warning py-2" style="font-size:0.85rem;">
            <i class="fas fa-exclamation-triangle me-1"></i>
            This submission is past the deadline. A late penalty will apply.
            <?php $policy = getLatePolicySummary($activity); if ($policy): ?>
            <strong><?= e($policy) ?></strong>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="submit">
            <div class="mb-3">
                <label class="form-label fw-bold">Upload File <span class="text-danger">*</span></label>
                <input type="file" name="submission_file" class="form-control" required
                    accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.jpg,.jpeg,.png">
                <div class="form-text">
                    <i class="fas fa-info-circle me-1"></i>
                    Accepted: PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, TXT, JPG, PNG. Max size: 25 MB.
                </div>
            </div>
            <?php if ($mySubmission): ?>
            <div class="alert alert-info py-2" style="font-size:0.85rem;">
                <i class="fas fa-redo me-1"></i>
                Your previous submission (v<?= $mySubmission['version'] ?>) will be archived and replaced by this new upload.
                <?php if ($mySubmission['status'] === 'graded'): ?>
                <br><strong>Note:</strong> Your existing grade will be cleared and must be re-graded by the instructor.
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary-gradient">
                <i class="fas fa-paper-plane me-1"></i><?= $mySubmission ? 'Resubmit' : 'Submit' ?>
            </button>
        </form>
    </div>
</div>

<?php elseif (!$mySubmission): ?>
<!-- Cannot submit -->
<div class="card">
    <div class="card-body text-center py-4">
        <?php if ($actStatus === 'not_open'): ?>
        <i class="fas fa-lock fa-2x mb-2 d-block text-secondary"></i>
        <div class="text-muted">This activity is not yet open for submissions.</div>
        <?php if (!empty($activity['open_date'])): ?>
        <div class="mt-1" style="font-size:0.85rem;">Opens: <?= date('M j, Y g:i A', strtotime($activity['open_date'])) ?></div>
        <?php endif; ?>
        <?php elseif ($actStatus === 'closed' || ($actStatus === 'overdue' && !$activity['allow_late'])): ?>
        <i class="fas fa-ban fa-2x mb-2 d-block text-danger"></i>
        <div class="text-danger fw-bold">Submissions are closed.</div>
        <div class="text-muted mt-1" style="font-size:0.85rem;">The deadline has passed and this activity no longer accepts submissions.</div>
        <span class="badge bg-danger mt-2"><i class="fas fa-exclamation-circle me-1"></i>Missing</span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php else: ?>
<!-- ═══════════════ TEACHER / ADMIN VIEW ═══════════════ -->

<?php
$totalStudents = count($students);
$submittedCount = count($submissions);
$gradedCount = count(array_filter($submissions, fn($s) => $s['status'] === 'graded'));
$lateCount = count(array_filter($submissions, fn($s) => $s['is_late']));
$missingCount = $totalStudents - $submittedCount;
?>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="fw-bold fs-4 text-primary"><?= $submittedCount ?>/<?= $totalStudents ?></div>
            <div style="font-size:0.8rem;color:var(--gray-500);">Submitted</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="fw-bold fs-4 text-success"><?= $gradedCount ?></div>
            <div style="font-size:0.8rem;color:var(--gray-500);">Graded</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="fw-bold fs-4 text-warning"><?= $lateCount ?></div>
            <div style="font-size:0.8rem;color:var(--gray-500);">Late</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="fw-bold fs-4 text-danger"><?= $missingCount ?></div>
            <div style="font-size:0.8rem;color:var(--gray-500);">Missing</div>
        </div>
    </div>
</div>

<?php if (!$activity['is_submittable']): ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle me-1"></i>
    This activity is <strong>not submittable</strong>. Scores are entered directly in the Graded Activities tab.
    <a href="<?= BASE_URL ?>/grades.php?class_id=<?= $classId ?>&view=activities&period=<?= e($activity['grading_period']) ?>" class="alert-link">Go to Activities</a>
</div>
<?php else: ?>

<!-- Student Submissions Table -->
<div class="card">
    <div class="card-header">
        <span><i class="fas fa-users me-2"></i>Student Submissions</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-modern mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="min-width:40px;">#</th>
                        <th style="min-width:180px;">Student</th>
                        <th class="text-center">Status</th>
                        <th>File</th>
                        <th class="text-center">Submitted</th>
                        <th class="text-center">Raw Score</th>
                        <th class="text-center">Penalty</th>
                        <th class="text-center">Final</th>
                        <th class="text-center" style="min-width:120px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $idx => $stu):
                        $sub = $submissions[$stu['id']] ?? null;
                    ?>
                    <tr>
                        <td class="text-muted"><?= $idx + 1 ?></td>
                        <td>
                            <div class="fw-bold"><?= e($stu['last_name'] . ', ' . $stu['first_name']) ?></div>
                            <div style="font-size:0.75rem;color:var(--gray-400);"><?= e($stu['username']) ?></div>
                        </td>
                        <td class="text-center">
                            <?php if (!$sub): ?>
                            <span class="badge bg-danger-subtle text-danger"><i class="fas fa-times-circle me-1"></i>Missing</span>
                            <?php elseif ($sub['status'] === 'graded'): ?>
                            <span class="badge bg-success-subtle text-success"><i class="fas fa-check-circle me-1"></i>Graded</span>
                            <?php else: ?>
                            <span class="badge bg-info-subtle text-info"><i class="fas fa-file-upload me-1"></i>Submitted</span>
                            <?php endif; ?>
                            <?php if ($sub && $sub['is_late']): ?>
                            <span class="badge bg-warning-subtle text-warning"><i class="fas fa-clock me-1"></i>Late</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($sub): ?>
                            <div style="font-size:0.85rem;">
                                <a href="<?= BASE_URL ?>/submission-download.php?id=<?= $sub['id'] ?>" class="text-decoration-none" title="Download">
                                    <i class="fas fa-file-alt me-1"></i><?= e($sub['original_name']) ?>
                                </a>
                                <span class="text-muted ms-1">(v<?= $sub['version'] ?>)</span>
                            </div>
                            <div style="font-size:0.72rem;color:var(--gray-400);"><?= number_format($sub['file_size'] / 1024, 1) ?> KB</div>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center" style="font-size:0.82rem;">
                            <?php if ($sub): ?>
                            <?= date('M j, g:i A', strtotime($sub['submitted_at'])) ?>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?= $sub && $sub['raw_score'] !== null ? number_format($sub['raw_score'], 1) : '—' ?>
                        </td>
                        <td class="text-center">
                            <?php if ($sub && $sub['late_penalty'] > 0): ?>
                            <span class="text-danger">-<?= $activity['late_penalty_type'] === 'percentage'
                                ? number_format($sub['late_penalty'], 1) . '%'
                                : number_format($sub['late_penalty'], 1) ?></span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($sub && $sub['final_score'] !== null): ?>
                            <span class="fw-bold <?= ($sub['final_score'] / $activity['max_score'] * 100) >= $passingGrade ? 'text-success' : 'text-danger' ?>">
                                <?= number_format($sub['final_score'], 1) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($sub): ?>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#gradeModal<?= $stu['id'] ?>"
                                title="<?= $sub['status'] === 'graded' ? 'Re-grade' : 'Grade' ?>">
                                <i class="fas fa-<?= $sub['status'] === 'graded' ? 'edit' : 'check' ?> me-1"></i>
                                <?= $sub['status'] === 'graded' ? 'Re-grade' : 'Grade' ?>
                            </button>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:0.8rem;">No submission</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Grade Modals -->
<?php foreach ($students as $stu):
    $sub = $submissions[$stu['id']] ?? null;
    if (!$sub) continue;

    $autoLatePenalty = 0;
    if ($sub['is_late'] && $activity['allow_late']) {
        $autoLatePenalty = computeLatePenalty($activity, $sub['submitted_at']);
    }
?>
<div class="modal fade" id="gradeModal<?= $stu['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="grade">
                <input type="hidden" name="student_id" value="<?= $stu['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-check-circle me-2 text-success"></i>
                        Grade: <?= e($stu['first_name'] . ' ' . $stu['last_name']) ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Submission Info -->
                    <div class="p-3 rounded mb-3" style="background:var(--gray-50);">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="fas fa-file-alt text-primary"></i>
                            <strong><?= e($sub['original_name']) ?></strong>
                            <span class="text-muted">(v<?= $sub['version'] ?>)</span>
                            <a href="<?= BASE_URL ?>/submission-download.php?id=<?= $sub['id'] ?>" class="btn btn-sm btn-outline-primary ms-auto">
                                <i class="fas fa-download me-1"></i>Download
                            </a>
                        </div>
                        <div style="font-size:0.8rem;color:var(--gray-500);">
                            Submitted: <?= date('M j, Y g:i A', strtotime($sub['submitted_at'])) ?>
                            <?php if ($sub['is_late']): ?>
                            <span class="badge bg-warning text-dark ms-1"><i class="fas fa-clock me-1"></i>Late</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Raw Score <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="raw_score" class="form-control grade-raw-input"
                                min="0" max="<?= $activity['max_score'] ?>" step="0.5" required
                                value="<?= $sub['raw_score'] !== null ? number_format($sub['raw_score'], 1, '.', '') : '' ?>"
                                data-max="<?= $activity['max_score'] ?>"
                                data-penalty="<?= $autoLatePenalty ?>"
                                data-penalty-type="<?= e($activity['late_penalty_type']) ?>">
                            <span class="input-group-text">/ <?= number_format($activity['max_score'], 0) ?></span>
                        </div>
                    </div>

                    <?php if ($sub['is_late'] && $autoLatePenalty > 0): ?>
                    <div class="alert alert-warning py-2" style="font-size:0.85rem;">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>Auto-computed late penalty:</strong>
                        <?= $activity['late_penalty_type'] === 'percentage'
                            ? number_format($autoLatePenalty, 1) . '% of max score'
                            : number_format($autoLatePenalty, 1) . ' points' ?>
                        <div class="mt-1">
                            <strong>Estimated final score: </strong>
                            <span class="grade-final-preview fw-bold">—</span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Feedback</label>
                        <textarea name="feedback" class="form-control" rows="3" maxlength="2000" placeholder="Optional feedback for the student..."><?= e($sub['feedback'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Save Grade
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>
<?php endif; ?>

<script>
// Auto-compute final score preview in grade modals
document.querySelectorAll('.grade-raw-input').forEach(inp => {
    inp.addEventListener('input', function() {
        const maxScore = parseFloat(this.dataset.max) || 100;
        const penaltyVal = parseFloat(this.dataset.penalty) || 0;
        const penaltyType = this.dataset.penaltyType;
        const raw = parseFloat(this.value) || 0;
        const previewEl = this.closest('.modal-body').querySelector('.grade-final-preview');
        if (!previewEl) return;

        let deduction = 0;
        if (penaltyType === 'percentage') {
            deduction = (penaltyVal / 100) * maxScore;
        } else {
            deduction = penaltyVal;
        }
        const finalScore = Math.max(0, raw - deduction);
        previewEl.textContent = finalScore.toFixed(1) + ' / ' + maxScore.toFixed(0);
        previewEl.style.color = (finalScore / maxScore * 100) >= <?= $passingGrade ?> ? '#10B981' : '#EF4444';
    });
    // Trigger on load
    inp.dispatchEvent(new Event('input'));
});
</script>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
