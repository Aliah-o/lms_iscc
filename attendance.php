<?php
$pageTitle = 'Attendance';
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'superadmin');
$pdo = getDB();
$user = currentUser();
$role = $user['role'];
$breadcrumbPills = [$role === 'superadmin' ? 'Administration' : 'Teaching', 'Attendance'];

try {
    $pdo->query("SELECT 1 FROM attendance LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        student_id INT NOT NULL,
        attendance_date DATE NOT NULL,
        status ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
        grading_period ENUM('midterm','final') NOT NULL DEFAULT 'midterm',
        remarks VARCHAR(255) DEFAULT NULL,
        recorded_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_attendance (class_id, student_id, attendance_date, grading_period),
        FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
}

$classId = intval($_GET['class_id'] ?? 0);
$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) $selectedDate = date('Y-m-d');
$attPeriod = $_GET['att_period'] ?? 'midterm';
if (!in_array($attPeriod, ['midterm', 'final'])) $attPeriod = 'midterm';
$view = $_GET['view'] ?? 'mark';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect('/attendance.php'); }

    $action = $_POST['action'] ?? '';
    $postClassId = intval($_POST['class_id'] ?? $classId);
    $postDate = $_POST['attendance_date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postDate)) $postDate = date('Y-m-d');

    if ($postClassId && $role === 'instructor') {
        $check = $pdo->prepare("SELECT id FROM instructor_classes WHERE id = ? AND instructor_id = ?");
        $check->execute([$postClassId, $user['id']]);
        if (!$check->fetch()) { flash('error', 'Access denied.'); redirect('/attendance.php'); }
    }

    if ($action === 'save_attendance' && $postClassId) {
        $statuses = $_POST['status'] ?? [];
        $remarks = $_POST['remarks'] ?? [];
        $postPeriod = $_POST['att_period'] ?? 'midterm';
        if (!in_array($postPeriod, ['midterm', 'final'])) $postPeriod = 'midterm';
        $count = 0;

        foreach ($statuses as $studentId => $status) {
            $studentId = intval($studentId);
            if (!in_array($status, ['present', 'absent', 'late', 'excused'])) continue;
            $remark = trim($remarks[$studentId] ?? '');
            if (strlen($remark) > 255) $remark = substr($remark, 0, 255);

            $pdo->prepare("INSERT INTO attendance (class_id, student_id, attendance_date, status, grading_period, remarks, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), grading_period = VALUES(grading_period), remarks = VALUES(remarks), recorded_by = VALUES(recorded_by), updated_at = NOW()")
                ->execute([$postClassId, $studentId, $postDate, $status, $postPeriod, $remark ?: null, $user['id']]);
            $count++;
        }
        // Auto-sync attendance score to grades
        syncAttendanceToGrades($pdo, $postClassId, $user['id']);

        auditLog('attendance_saved', "Saved attendance ($postPeriod) for $count students in class #$postClassId on $postDate");
        flash('success', "Attendance saved for $count students on " . date('M d, Y', strtotime($postDate)) . " (" . ucfirst($postPeriod) . "). Grades updated automatically.");
        redirect("/attendance.php?class_id=$postClassId&date=$postDate&att_period=$postPeriod");
    }

    elseif ($action === 'mark_all' && $postClassId) {
        $markStatus = $_POST['mark_status'] ?? 'present';
        if (!in_array($markStatus, ['present', 'absent', 'late', 'excused'])) $markStatus = 'present';
        $postPeriod = $_POST['att_period'] ?? 'midterm';
        if (!in_array($postPeriod, ['midterm', 'final'])) $postPeriod = 'midterm';

        $enrolledStmt = $pdo->prepare("SELECT student_id FROM class_enrollments WHERE class_id = ?");
        $enrolledStmt->execute([$postClassId]);
        $enrolledStudents = $enrolledStmt->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;

        foreach ($enrolledStudents as $sid) {
            $pdo->prepare("INSERT INTO attendance (class_id, student_id, attendance_date, status, grading_period, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), grading_period = VALUES(grading_period), recorded_by = VALUES(recorded_by), updated_at = NOW()")
                ->execute([$postClassId, $sid, $postDate, $markStatus, $postPeriod, $user['id']]);
            $count++;
        }
        // Auto-sync attendance score to grades
        syncAttendanceToGrades($pdo, $postClassId, $user['id']);

        auditLog('attendance_mark_all', "Marked all $count students as '$markStatus' ($postPeriod) in class #$postClassId on $postDate");
        flash('success', "All $count students marked as " . ucfirst($markStatus) . " (" . ucfirst($postPeriod) . "). Grades updated automatically.");
        redirect("/attendance.php?class_id=$postClassId&date=$postDate&att_period=$postPeriod");
    }

    elseif ($action === 'delete_record' && $postClassId) {
        $recordDate = $_POST['record_date'] ?? '';
        if ($recordDate) {
            $del = $pdo->prepare("DELETE FROM attendance WHERE class_id = ? AND attendance_date = ?");
            $del->execute([$postClassId, $recordDate]);
            // Re-sync attendance score to grades after deletion
            syncAttendanceToGrades($pdo, $postClassId, $user['id']);

            auditLog('attendance_deleted', "Deleted attendance records for class #$postClassId on $recordDate");
            flash('success', 'Attendance record deleted. Grades updated.');
        }
        redirect("/attendance.php?class_id=$postClassId&view=records");
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
$currentAttendance = [];
$classInfo = null;
$attendanceDates = [];
$summaryData = [];

if ($classId) {
    if ($role === 'instructor') {
        $chk = $pdo->prepare("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id WHERE tc.id = ? AND tc.instructor_id = ?");
        $chk->execute([$classId, $user['id']]);
        $classInfo = $chk->fetch();
    } else {
        $chk = $pdo->prepare("SELECT tc.*, s.section_name, u.first_name as instructor_fn, u.last_name as instructor_ln FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id JOIN users u ON tc.instructor_id = u.id WHERE tc.id = ?");
        $chk->execute([$classId]);
        $classInfo = $chk->fetch();
    }

    if ($classInfo) {
        $stmt = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.username
            FROM class_enrollments ce
            JOIN users u ON ce.student_id = u.id
            WHERE ce.class_id = ?
            ORDER BY u.last_name, u.first_name");
        $stmt->execute([$classId]);
        $students = $stmt->fetchAll();

        $aStmt = $pdo->prepare("SELECT student_id, status, remarks FROM attendance WHERE class_id = ? AND attendance_date = ? AND grading_period = ?");
        $aStmt->execute([$classId, $selectedDate, $attPeriod]);
        while ($a = $aStmt->fetch()) {
            $currentAttendance[$a['student_id']] = ['status' => $a['status'], 'remarks' => $a['remarks']];
        }

        $dStmt = $pdo->prepare("SELECT DISTINCT attendance_date, grading_period,
            SUM(status = 'present') as present_count,
            SUM(status = 'absent') as absent_count,
            SUM(status = 'late') as late_count,
            SUM(status = 'excused') as excused_count,
            COUNT(*) as total
            FROM attendance WHERE class_id = ? AND grading_period = ?
            GROUP BY attendance_date, grading_period ORDER BY attendance_date DESC");
        $dStmt->execute([$classId, $attPeriod]);
        $attendanceDates = $dStmt->fetchAll();

        $sStmt = $pdo->prepare("SELECT student_id,
            SUM(status = 'present') as present_count,
            SUM(status = 'absent') as absent_count,
            SUM(status = 'late') as late_count,
            SUM(status = 'excused') as excused_count,
            COUNT(*) as total_days
            FROM attendance WHERE class_id = ? AND grading_period = ?
            GROUP BY student_id");
        $sStmt->execute([$classId, $attPeriod]);
        while ($s = $sStmt->fetch()) {
            $summaryData[$s['student_id']] = $s;
        }
    } else {
        $classId = 0;
    }
}

$maxAbsences = intval(getSetting('max_absences', '10'));

$statusMeta = [
    'present' => ['label' => 'Present', 'icon' => 'fa-check-circle', 'color' => '#10B981', 'bg' => '#D1FAE5'],
    'absent'  => ['label' => 'Absent',  'icon' => 'fa-times-circle', 'color' => '#EF4444', 'bg' => '#FEE2E2'],
    'late'    => ['label' => 'Late',    'icon' => 'fa-clock',        'color' => '#F59E0B', 'bg' => '#FEF3C7'],
    'excused' => ['label' => 'Excused', 'icon' => 'fa-info-circle',  'color' => '#3B82F6', 'bg' => '#DBEAFE'],
];

require_once __DIR__ . '/views/layouts/header.php';
?>

<?php if (!$classId): ?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <span><i class="fas fa-calendar-check me-2"></i>Select a Class to Manage Attendance</span>
            </div>
            <div class="card-body">
                <?php if (empty($classes)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                    No classes found. <?= $role === 'instructor' ? 'You have not been assigned to any classes yet.' : '' ?>
                </div>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($classes as $cls): ?>
                    <div class="col-md-6 col-lg-4">
                        <a href="<?= BASE_URL ?>/attendance.php?class_id=<?= $cls['id'] ?>" class="text-decoration-none">
                            <div class="card h-100 border" style="transition:all .2s;cursor:pointer;" onmouseover="this.style.borderColor='var(--primary)';this.style.transform='translateY(-2px)'" onmouseout="this.style.borderColor='';this.style.transform=''">
                                <div class="card-body">
                                    <div class="d-flex align-items-center gap-3 mb-2">
                                        <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#D1FAE5,#A7F3D0);display:flex;align-items:center;justify-content:center;">
                                            <i class="fas fa-calendar-check" style="color:#059669;"></i>
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
                                    <?php if ($role === 'superadmin' && isset($cls['instructor_fn'])): ?>
                                    <div style="font-size:0.8rem;color:var(--gray-500);"><i class="fas fa-user me-1"></i><?= e($cls['instructor_fn'] . ' ' . $cls['instructor_ln']) ?></div>
                                    <?php endif; ?>
                                    <div class="mt-2" style="font-size:0.8rem;color:var(--gray-500);"><i class="fas fa-users me-1"></i><?= $cls['student_count'] ?> students</div>
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
        <a href="<?= BASE_URL ?>/attendance.php" class="btn btn-sm btn-outline-secondary me-2"><i class="fas fa-arrow-left me-1"></i>Back</a>
        <span class="fw-bold" style="font-size:1.05rem;"><?= e($classInfo['subject_name'] ?? 'General') ?></span>
        <span class="text-muted ms-2" style="font-size:0.85rem;">
            <?= e(PROGRAMS[$classInfo['program_code']] ?? '') ?> &bull; <?= e(YEAR_LEVELS[$classInfo['year_level']] ?? '') ?> &bull; Sec. <?= e($classInfo['section_name']) ?>
            <?php if ($role === 'superadmin' && isset($classInfo['instructor_fn'])): ?>
            &bull; <i class="fas fa-user ms-1 me-1"></i><?= e($classInfo['instructor_fn'] . ' ' . $classInfo['instructor_ln']) ?>
            <?php endif; ?>
        </span>
    </div>
</div>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <ul class="nav nav-pills gap-2 mb-0">
        <li class="nav-item">
            <a class="nav-link <?= $view === 'mark' ? 'active' : '' ?>" href="<?= BASE_URL ?>/attendance.php?class_id=<?= $classId ?>&date=<?= $selectedDate ?>&att_period=<?= $attPeriod ?>">
                <i class="fas fa-edit me-1"></i>Mark Attendance
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $view === 'records' ? 'active' : '' ?>" href="<?= BASE_URL ?>/attendance.php?class_id=<?= $classId ?>&view=records&att_period=<?= $attPeriod ?>">
                <i class="fas fa-history me-1"></i>Records & Summary
            </a>
        </li>
    </ul>
    <div class="btn-group" role="group">
        <a href="<?= BASE_URL ?>/attendance.php?class_id=<?= $classId ?>&date=<?= $selectedDate ?>&view=<?= $view ?>&att_period=midterm" class="btn btn-sm <?= $attPeriod === 'midterm' ? 'btn-primary' : 'btn-outline-primary' ?>">Midterm</a>
        <a href="<?= BASE_URL ?>/attendance.php?class_id=<?= $classId ?>&date=<?= $selectedDate ?>&view=<?= $view ?>&att_period=final" class="btn btn-sm <?= $attPeriod === 'final' ? 'btn-primary' : 'btn-outline-primary' ?>">Final</a>
    </div>
</div>

<?php if ($view === 'records'): ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header">
                <span><i class="fas fa-users me-2"></i>Student Attendance Summary</span>
                <span class="badge bg-secondary ms-2"><?= count($students) ?> Students</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($students)): ?>
                <div class="text-center py-4 text-muted"><i class="fas fa-user-slash fa-2x mb-2 d-block"></i>No students enrolled.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:0.85rem;">
                        <thead>
                            <tr style="background:var(--gray-50);">
                                <th class="ps-3">#</th>
                                <th>Student</th>
                                <th class="text-center" style="color:#10B981;">Present</th>
                                <th class="text-center" style="color:#EF4444;">Absent</th>
                                <th class="text-center" style="color:#F59E0B;">Late</th>
                                <th class="text-center" style="color:#3B82F6;">Excused</th>
                                <th class="text-center">Total</th>
                                <th class="text-center">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $i => $stu):
                                $sd = $summaryData[$stu['id']] ?? ['present_count' => 0, 'absent_count' => 0, 'late_count' => 0, 'excused_count' => 0, 'total_days' => 0];
                                $rate = $sd['total_days'] > 0 ? round(($sd['present_count'] + $sd['late_count']) / $sd['total_days'] * 100, 1) : 0;
                                $absentWarning = intval($sd['absent_count']) >= $maxAbsences;
                            ?>
                            <tr class="<?= $absentWarning ? 'table-danger' : '' ?>">
                                <td class="ps-3 text-muted"><?= $i + 1 ?></td>
                                <td>
                                    <div class="fw-semibold"><?= e($stu['last_name'] . ', ' . $stu['first_name']) ?></div>
                                    <small class="text-muted">@<?= e($stu['username']) ?></small>
                                </td>
                                <td class="text-center fw-bold" style="color:#10B981;"><?= $sd['present_count'] ?></td>
                                <td class="text-center fw-bold" style="color:#EF4444;"><?= $sd['absent_count'] ?><?= $absentWarning ? ' <i class="fas fa-exclamation-triangle" title="Exceeded max absences"></i>' : '' ?></td>
                                <td class="text-center fw-bold" style="color:#F59E0B;"><?= $sd['late_count'] ?></td>
                                <td class="text-center fw-bold" style="color:#3B82F6;"><?= $sd['excused_count'] ?></td>
                                <td class="text-center fw-bold"><?= $sd['total_days'] ?></td>
                                <td class="text-center">
                                    <span class="badge rounded-pill" style="background:<?= $rate >= 80 ? '#D1FAE5' : ($rate >= 60 ? '#FEF3C7' : '#FEE2E2') ?>;color:<?= $rate >= 80 ? '#059669' : ($rate >= 60 ? '#D97706' : '#DC2626') ?>;font-size:0.78rem;">
                                        <?= $rate ?>%
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <span><i class="fas fa-calendar-alt me-2"></i>Recorded Dates</span>
                <span class="badge bg-secondary ms-2"><?= count($attendanceDates) ?></span>
            </div>
            <div class="card-body p-0" style="max-height:500px;overflow-y:auto;">
                <?php if (empty($attendanceDates)): ?>
                <div class="text-center py-4 text-muted"><i class="fas fa-calendar-times fa-2x mb-2 d-block"></i>No records yet.</div>
                <?php else: ?>
                <?php foreach ($attendanceDates as $dr): ?>
                <div class="d-flex align-items-center py-2 px-3 gap-2 att-date-row" style="border-bottom:1px solid var(--gray-100);">
                    <div class="flex-grow-1">
                        <div class="fw-semibold" style="font-size:0.85rem;"><?= date('D, M d, Y', strtotime($dr['attendance_date'])) ?></div>
                        <div style="font-size:0.75rem;" class="d-flex gap-2 mt-1">
                            <span style="color:#10B981;" title="Present"><i class="fas fa-check-circle"></i> <?= $dr['present_count'] ?></span>
                            <span style="color:#EF4444;" title="Absent"><i class="fas fa-times-circle"></i> <?= $dr['absent_count'] ?></span>
                            <span style="color:#F59E0B;" title="Late"><i class="fas fa-clock"></i> <?= $dr['late_count'] ?></span>
                            <span style="color:#3B82F6;" title="Excused"><i class="fas fa-info-circle"></i> <?= $dr['excused_count'] ?></span>
                        </div>
                    </div>
                    <div class="d-flex gap-1">
                        <a href="<?= BASE_URL ?>/attendance.php?class_id=<?= $classId ?>&date=<?= $dr['attendance_date'] ?>&att_period=<?= $attPeriod ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-pen"></i></a>
                        <form method="POST" class="d-inline" onsubmit="return confirmForm(this, 'Delete attendance record for <?= date('M d, Y', strtotime($dr['attendance_date'])) ?>?', 'Delete Record')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_record">
                            <input type="hidden" name="class_id" value="<?= $classId ?>">
                            <input type="hidden" name="record_date" value="<?= $dr['attendance_date'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <span><i class="fas fa-edit me-2"></i>Mark Attendance</span>
                <form method="GET" class="d-flex align-items-center gap-2 mb-0">
                    <input type="hidden" name="class_id" value="<?= $classId ?>">
                    <input type="hidden" name="att_period" value="<?= $attPeriod ?>">
                    <input type="date" name="date" value="<?= $selectedDate ?>" class="form-control form-control-sm" style="width:170px;" onchange="this.form.submit()">
                </form>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <div>
                        <h6 class="mb-0 fw-bold"><?= date('l, F d, Y', strtotime($selectedDate)) ?></h6>
                        <small class="text-muted"><?= count($students) ?> students enrolled</small>
                    </div>
                    <div class="d-flex gap-1">
                        <?php foreach ($statusMeta as $sKey => $sMeta): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirmForm(this, 'Mark all students as <?= $sMeta['label'] ?>?', 'Mark All')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="mark_all">
                            <input type="hidden" name="class_id" value="<?= $classId ?>">
                            <input type="hidden" name="attendance_date" value="<?= $selectedDate ?>">
                            <input type="hidden" name="att_period" value="<?= $attPeriod ?>">
                            <input type="hidden" name="mark_status" value="<?= $sKey ?>">
                            <button type="submit" class="btn btn-sm" style="background:<?= $sMeta['bg'] ?>;color:<?= $sMeta['color'] ?>;font-size:0.75rem;font-weight:600;" title="Mark all <?= $sMeta['label'] ?>">
                                <i class="fas <?= $sMeta['icon'] ?> me-1"></i>All <?= $sMeta['label'] ?>
                            </button>
                        </form>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (empty($students)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-user-slash fa-2x mb-2 d-block"></i>No students enrolled in this class.
                </div>
                <?php else: ?>
                <form method="POST" id="attendanceForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_attendance">
                    <input type="hidden" name="class_id" value="<?= $classId ?>">
                    <input type="hidden" name="attendance_date" value="<?= $selectedDate ?>">
                    <input type="hidden" name="att_period" value="<?= $attPeriod ?>">

                    <div class="table-responsive">
                        <table class="table table-hover mb-0 att-table" style="font-size:0.85rem;">
                            <thead>
                                <tr style="background:var(--gray-50);">
                                    <th class="ps-3" style="width:40px;">#</th>
                                    <th>Student</th>
                                    <th class="text-center" style="width:320px;">Status</th>
                                    <th style="width:200px;">Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $i => $stu):
                                    $curStatus = $currentAttendance[$stu['id']]['status'] ?? 'present';
                                    $curRemarks = $currentAttendance[$stu['id']]['remarks'] ?? '';
                                ?>
                                <tr data-student="<?= $stu['id'] ?>">
                                    <td class="ps-3 text-muted align-middle"><?= $i + 1 ?></td>
                                    <td class="align-middle">
                                        <div class="fw-semibold"><?= e($stu['last_name'] . ', ' . $stu['first_name']) ?></div>
                                        <small class="text-muted">@<?= e($stu['username']) ?></small>
                                    </td>
                                    <td class="text-center align-middle">
                                        <div class="att-status-group d-inline-flex gap-1">
                                            <?php foreach ($statusMeta as $sKey => $sMeta): ?>
                                            <label class="att-status-btn" title="<?= $sMeta['label'] ?>">
                                                <input type="radio" name="status[<?= $stu['id'] ?>]" value="<?= $sKey ?>" <?= $curStatus === $sKey ? 'checked' : '' ?> class="d-none att-radio">
                                                <span class="att-status-label" data-color="<?= $sMeta['color'] ?>" data-bg="<?= $sMeta['bg'] ?>">
                                                    <i class="fas <?= $sMeta['icon'] ?>"></i>
                                                    <span class="d-none d-md-inline"><?= $sMeta['label'] ?></span>
                                                </span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td class="align-middle">
                                        <input type="text" name="remarks[<?= $stu['id'] ?>]" value="<?= e($curRemarks) ?>"
                                            class="form-control form-control-sm" placeholder="Optional" style="font-size:0.8rem;">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-primary-gradient">
                            <i class="fas fa-save me-1"></i>Save Attendance
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><span><i class="fas fa-chart-pie me-2"></i>Day Summary</span></div>
            <div class="card-body">
                <?php
                $dayCounts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
                foreach ($currentAttendance as $a) { if (isset($dayCounts[$a['status']])) $dayCounts[$a['status']]++; }
                $dayTotal = array_sum($dayCounts);
                ?>
                <?php foreach ($statusMeta as $sKey => $sMeta): ?>
                <div class="d-flex align-items-center gap-2 py-2" style="border-bottom:1px solid var(--gray-100);">
                    <div style="width:10px;height:10px;border-radius:50%;background:<?= $sMeta['color'] ?>;"></div>
                    <div class="flex-grow-1" style="font-size:0.85rem;"><i class="fas <?= $sMeta['icon'] ?> me-1" style="color:<?= $sMeta['color'] ?>"></i> <?= $sMeta['label'] ?></div>
                    <div class="fw-bold" style="font-size:0.85rem;"><?= $dayCounts[$sKey] ?></div>
                </div>
                <?php endforeach; ?>
                <div class="d-flex align-items-center gap-2 pt-2 mt-1">
                    <div style="width:10px;height:10px;border-radius:50%;background:var(--gray-400);"></div>
                    <div class="flex-grow-1 fw-semibold" style="font-size:0.85rem;">Total Recorded</div>
                    <div class="fw-bold" style="font-size:0.85rem;"><?= $dayTotal ?> / <?= count($students) ?></div>
                </div>
                <?php if ($dayTotal > 0): ?>
                <div class="d-flex rounded overflow-hidden mt-3" style="height:8px;">
                    <?php foreach ($statusMeta as $sKey => $sMeta): ?>
                    <?php $pct = $dayTotal > 0 ? ($dayCounts[$sKey] / $dayTotal * 100) : 0; ?>
                    <?php if ($pct > 0): ?>
                    <div style="width:<?= $pct ?>%;background:<?= $sMeta['color'] ?>;" title="<?= $sMeta['label'] ?>: <?= $dayCounts[$sKey] ?>"></div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><span><i class="fas fa-info-circle me-2"></i>Quick Info</span></div>
            <div class="card-body" style="font-size:0.82rem;">
                <div class="mb-2"><i class="fas fa-exclamation-triangle text-warning me-1"></i> Max absences allowed: <strong><?= $maxAbsences ?></strong></div>
                <div class="mb-2"><i class="fas fa-calendar me-1 text-muted"></i> Total recorded days: <strong><?= count($attendanceDates) ?></strong></div>
                <div class="text-muted mt-3" style="font-size:0.78rem;">
                    <i class="fas fa-lightbulb me-1"></i> Use the "Mark All" buttons to quickly set all students, then adjust individually as needed.
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>

<script>
document.querySelectorAll('.att-radio').forEach(radio => {
    function updateStyle(r) {
        const label = r.closest('.att-status-btn').querySelector('.att-status-label');
        const color = label.dataset.color;
        const bg = label.dataset.bg;
        const group = r.closest('.att-status-group');
        group.querySelectorAll('.att-status-label').forEach(l => {
            l.style.background = 'var(--gray-50)';
            l.style.color = 'var(--gray-400)';
            l.style.borderColor = 'var(--gray-200)';
        });
        label.style.background = bg;
        label.style.color = color;
        label.style.borderColor = color;
    }
    radio.addEventListener('change', function() { updateStyle(this); });
    if (radio.checked) updateStyle(radio);
});
</script>
