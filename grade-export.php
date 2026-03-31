<?php
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'superadmin', 'student');
$pdo  = getDB();
$user = currentUser();
$role = $user['role'];

$type      = $_GET['type'] ?? 'print';
$mode      = $_GET['mode'] ?? 'class';
$classId   = intval($_GET['class_id'] ?? 0);
$studentId = intval($_GET['student_id'] ?? 0);
$period    = $_GET['period'] ?? 'both';
if (!in_array($period, ['midterm', 'final', 'both'])) $period = 'both';
if (!in_array($type, ['csv', 'print'])) $type = 'print';
if (!in_array($mode, ['class', 'student'])) $mode = 'class';

if ($role === 'student') {
    $studentId = $user['id'];
    $mode = 'student';
}

if (!$classId) { flash('error', 'No class selected.'); redirect('/grades.php'); }

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
    $chk = $pdo->prepare("SELECT tc.*, s.section_name, u.first_name as instructor_fn, u.last_name as instructor_ln
        FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id JOIN users u ON tc.instructor_id = u.id WHERE tc.id = ?");
    $chk->execute([$classId]);
    $classInfo = $chk->fetch();
}

if (!$classInfo) { flash('error', 'Class not found or access denied.'); redirect('/grades.php'); }

$globalWeights = [
    'attendance' => floatval(getSetting('weight_attendance', '10')),
    'activity'   => floatval(getSetting('weight_activity', '20')),
    'quiz'       => floatval(getSetting('weight_quiz', '30')),
    'project'    => floatval(getSetting('weight_project', '0')),
    'exam'       => floatval(getSetting('weight_exam', '40')),
];
$passingGrade = floatval(getSetting('passing_grade', '75'));

$cwStmt = $pdo->prepare("SELECT component, weight FROM class_grade_weights WHERE class_id = ?");
$cwStmt->execute([$classId]);
$overrides = $cwStmt->fetchAll(PDO::FETCH_KEY_PAIR);
if (!empty($overrides)) {
    $classWeights = [
        'attendance' => floatval($overrides['attendance'] ?? $globalWeights['attendance']),
        'activity'   => floatval($overrides['activity'] ?? $globalWeights['activity']),
        'quiz'       => floatval($overrides['quiz'] ?? $globalWeights['quiz']),
        'project'    => floatval($overrides['project'] ?? $globalWeights['project']),
        'exam'       => floatval($overrides['exam'] ?? $globalWeights['exam']),
    ];
} else {
    $classWeights = $globalWeights;
}

$componentMeta = [
    'attendance' => ['label' => 'Attendance', 'icon' => 'fa-calendar-check', 'color' => '#10B981'],
    'activity'   => ['label' => 'Activity',   'icon' => 'fa-tasks',          'color' => '#3B82F6'],
    'quiz'       => ['label' => 'Quiz',       'icon' => 'fa-question-circle','color' => '#F59E0B'],
    'project'    => ['label' => 'Project',    'icon' => 'fa-project-diagram','color' => '#8B5CF6'],
    'exam'       => ['label' => 'Exam',       'icon' => 'fa-file-alt',      'color' => '#EF4444'],
];
$activeComponents = [];
foreach ($componentMeta as $key => $meta) {
    if ($classWeights[$key] > 0) {
        $activeComponents[$key] = array_merge($meta, ['weight' => $classWeights[$key]]);
    }
}

if ($mode === 'student' && $studentId) {
    $stmt = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.username, u.program_code
        FROM class_enrollments ce JOIN users u ON ce.student_id = u.id
        WHERE ce.class_id = ? AND u.id = ?");
    $stmt->execute([$classId, $studentId]);
    $students = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.username, u.program_code
        FROM class_enrollments ce JOIN users u ON ce.student_id = u.id
        WHERE ce.class_id = ? ORDER BY u.last_name, u.first_name");
    $stmt->execute([$classId]);
    $students = $stmt->fetchAll();
}

$periods = ($period === 'both') ? ['midterm', 'final'] : [$period];
$allGrades = [];
foreach ($periods as $p) {
    $gStmt = $pdo->prepare("SELECT student_id, component, score FROM grades WHERE class_id = ? AND grading_period = ?");
    $gStmt->execute([$classId, $p]);
    while ($g = $gStmt->fetch()) {
        $allGrades[$p][$g['student_id']][$g['component']] = floatval($g['score']);
    }
}

function calcWeightedAvg($studentGrades, $activeComponents) {
    $total = 0;
    foreach ($activeComponents as $key => $comp) {
        $score = floatval($studentGrades[$key] ?? 0);
        $total += ($score * $comp['weight'] / 100);
    }
    return $total;
}

$classLabel = ($classInfo['course_code'] ?? '') . ' - ' . ($classInfo['subject_name'] ?? '');
$sectionLabel = ($classInfo['year_level'] ?? '') . ($classInfo['section_name'] ?? '');
$instructorLabel = ($role === 'instructor')
    ? $user['first_name'] . ' ' . $user['last_name']
    : (($classInfo['instructor_fn'] ?? '') . ' ' . ($classInfo['instructor_ln'] ?? ''));

if ($type === 'csv') {
    $filename = 'grades_';
    if ($mode === 'student' && !empty($students)) {
        $stu = $students[0];
        $filename .= strtolower(str_replace(' ', '_', $stu['last_name'] . '_' . $stu['first_name']));
    } else {
        $filename .= strtolower(str_replace(' ', '_', $classInfo['course_code'] . '_' . $sectionLabel));
    }
    $filename .= '_' . ($period === 'both' ? 'all' : $period) . '_' . date('Y-m-d');
    $filename = preg_replace('/[^a-z0-9_\-]/i', '_', $filename) . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    fputcsv($out, ['ISCC Learning Management System - Grade Report']);
    fputcsv($out, ['Class:', $classLabel]);
    fputcsv($out, ['Section:', $sectionLabel]);
    fputcsv($out, ['Instructor:', $instructorLabel]);
    fputcsv($out, ['Passing Grade:', $passingGrade . '%']);
    fputcsv($out, ['Exported:', date('F j, Y g:i A')]);
    fputcsv($out, []);

    if ($mode === 'student' && !empty($students)) {
        $stu = $students[0];
        fputcsv($out, ['Student:', $stu['last_name'] . ', ' . $stu['first_name']]);
        fputcsv($out, ['Username:', $stu['username']]);
        fputcsv($out, []);

        foreach ($periods as $p) {
            fputcsv($out, [strtoupper($p) . ' GRADES']);
            fputcsv($out, ['Component', 'Weight (%)', 'Score', 'Weighted Score', 'Status']);
            $grades = $allGrades[$p][$stu['id']] ?? [];
            $totalWeighted = 0;
            foreach ($activeComponents as $key => $comp) {
                $score = floatval($grades[$key] ?? 0);
                $weighted = $score * $comp['weight'] / 100;
                $totalWeighted += $weighted;
                $status = $score >= $passingGrade ? 'Passed' : 'Failed';
                fputcsv($out, [$comp['label'], number_format($comp['weight'], 2), number_format($score, 2), number_format($weighted, 2), $status]);
            }
            fputcsv($out, ['WEIGHTED AVERAGE', '', '', number_format($totalWeighted, 2), $totalWeighted >= $passingGrade ? 'PASSED' : 'FAILED']);
            fputcsv($out, []);
        }
    } else {
        foreach ($periods as $p) {
            fputcsv($out, [strtoupper($p) . ' GRADES']);
            $header = ['#', 'Last Name', 'First Name', 'Username'];
            foreach ($activeComponents as $key => $comp) {
                $header[] = $comp['label'] . ' (' . number_format($comp['weight'], 1) . '%)';
            }
            $header[] = 'Weighted Avg';
            $header[] = 'Status';
            fputcsv($out, $header);

            foreach ($students as $idx => $stu) {
                $grades = $allGrades[$p][$stu['id']] ?? [];
                $row = [$idx + 1, $stu['last_name'], $stu['first_name'], $stu['username']];
                foreach ($activeComponents as $key => $comp) {
                    $row[] = number_format(floatval($grades[$key] ?? 0), 2);
                }
                $avg = calcWeightedAvg($grades, $activeComponents);
                $row[] = number_format($avg, 2);
                $row[] = $avg >= $passingGrade ? 'Passed' : 'Failed';
                fputcsv($out, $row);
            }

            if (!empty($students)) {
                $passCount = 0; $totalAvg = 0;
                foreach ($students as $stu) {
                    $grades = $allGrades[$p][$stu['id']] ?? [];
                    $avg = calcWeightedAvg($grades, $activeComponents);
                    $totalAvg += $avg;
                    if ($avg >= $passingGrade) $passCount++;
                }
                fputcsv($out, []);
                fputcsv($out, ['', 'SUMMARY', '', '', 'Total Students:', count($students), 'Passed:', $passCount, 'Failed:', count($students) - $passCount, 'Class Average:', number_format($totalAvg / count($students), 2)]);
            }
            fputcsv($out, []);
        }
    }

    fclose($out);
    exit;
}

$schoolName = getSetting('school_name', 'ISCC Learning Management System');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grade Report - <?= e($classLabel) ?><?= $mode === 'student' && !empty($students) ? ' - ' . e($students[0]['last_name'] . ', ' . $students[0]['first_name']) : '' ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        body { font-family: 'Inter', sans-serif; color: #1e293b; background: #fff; font-size: 11pt; }

        .print-container { max-width: 1000px; margin: 0 auto; padding: 30px; }

        .report-header { text-align: center; margin-bottom: 24px; border-bottom: 3px solid #1e40af; padding-bottom: 16px; }
        .report-header .school-name { font-size: 18pt; font-weight: 700; color: #1e40af; text-transform: uppercase; letter-spacing: 1px; }
        .report-header .report-title { font-size: 14pt; font-weight: 600; color: #334155; margin-top: 4px; }
        .report-header .report-subtitle { font-size: 9pt; color: #64748b; margin-top: 2px; }

        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 32px; margin-bottom: 20px; font-size: 10pt; }
        .info-grid .info-item { display: flex; gap: 8px; }
        .info-grid .info-label { font-weight: 600; color: #64748b; min-width: 100px; }
        .info-grid .info-value { font-weight: 500; color: #1e293b; }

        .weight-legend { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 9pt; }
        .weight-chip { display: inline-flex; align-items: center; gap: 4px; }
        .weight-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }

        .grade-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 10pt; }
        .grade-table th { background: #1e40af; color: #fff; padding: 8px 10px; text-align: center; font-weight: 600; font-size: 9pt; text-transform: uppercase; letter-spacing: 0.5px; }
        .grade-table th:first-child, .grade-table th:nth-child(2) { text-align: left; }
        .grade-table td { padding: 6px 10px; border-bottom: 1px solid #e2e8f0; text-align: center; }
        .grade-table td:first-child, .grade-table td:nth-child(2) { text-align: left; }
        .grade-table tbody tr:nth-child(even) { background: #f8fafc; }
        .grade-table tbody tr:hover { background: #eff6ff; }
        .grade-table .student-name { font-weight: 600; }
        .grade-table .student-username { font-size: 8pt; color: #94a3b8; }

        .passed { color: #059669; font-weight: 600; }
        .failed { color: #dc2626; font-weight: 600; }
        .avg-cell { font-weight: 700; font-size: 11pt; }

        .summary-bar { display: flex; gap: 24px; padding: 12px 16px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; margin-bottom: 20px; }
        .summary-item { text-align: center; }
        .summary-item .s-value { font-size: 18pt; font-weight: 700; color: #1e40af; }
        .summary-item .s-label { font-size: 8pt; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }

        .student-card { border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        .student-card .student-header { display: flex; align-items: center; gap: 16px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #e2e8f0; }
        .student-card .student-avatar { width: 48px; height: 48px; border-radius: 50%; background: #1e40af; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 16pt; font-weight: 700; }
        .student-card .student-info h3 { font-size: 13pt; font-weight: 700; margin-bottom: 2px; }
        .student-card .student-info p { font-size: 9pt; color: #64748b; }

        .component-row { display: flex; align-items: center; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
        .component-row:last-child { border-bottom: none; }
        .component-label { flex: 1; font-weight: 500; display: flex; align-items: center; gap: 6px; }
        .component-weight { width: 70px; text-align: center; color: #64748b; font-size: 9pt; }
        .component-score { width: 70px; text-align: center; font-weight: 600; }
        .component-weighted { width: 90px; text-align: center; font-weight: 500; color: #64748b; }

        .total-row { display: flex; align-items: center; padding: 12px 0; border-top: 2px solid #1e40af; margin-top: 8px; }
        .total-row .component-label { font-weight: 700; font-size: 12pt; }
        .total-row .component-score { font-size: 14pt; }

        .grade-badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-weight: 700; font-size: 10pt; }
        .grade-badge.pass { background: #dcfce7; color: #059669; }
        .grade-badge.fail { background: #fee2e2; color: #dc2626; }

        .period-heading { font-size: 12pt; font-weight: 700; color: #1e40af; margin: 20px 0 10px; padding: 6px 12px; background: #eff6ff; border-left: 4px solid #1e40af; border-radius: 0 6px 6px 0; }

        .report-footer { text-align: center; margin-top: 30px; padding-top: 16px; border-top: 2px solid #e2e8f0; font-size: 8pt; color: #94a3b8; }
        .signature-area { display: flex; justify-content: space-between; margin-top: 50px; padding: 0 40px; }
        .signature-box { text-align: center; width: 200px; }
        .signature-line { border-top: 1px solid #1e293b; margin-bottom: 4px; }
        .signature-label { font-size: 9pt; color: #64748b; }
        .signature-name { font-size: 10pt; font-weight: 600; }

        .print-toolbar { position: fixed; top: 0; left: 0; right: 0; background: #1e293b; padding: 10px 20px; display: flex; align-items: center; gap: 12px; z-index: 1000; box-shadow: 0 2px 10px rgba(0,0,0,.2); }
        .print-toolbar .btn { padding: 8px 20px; border: none; border-radius: 6px; font-size: 10pt; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
        .btn-print { background: #3b82f6; color: #fff; }
        .btn-print:hover { background: #2563eb; }
        .btn-csv { background: #10b981; color: #fff; }
        .btn-csv:hover { background: #059669; }
        .btn-back { background: #475569; color: #fff; }
        .btn-back:hover { background: #334155; }
        .toolbar-title { color: #fff; font-weight: 600; font-size: 11pt; margin-left: auto; }

        body { padding-top: 55px; }

        @media print {
            body { padding-top: 0; }
            .print-toolbar { display: none !important; }
            .print-container { padding: 0; max-width: none; }
            .grade-table th { background: #1e40af !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .grade-table tbody tr:nth-child(even) { background: #f8fafc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .passed { color: #059669 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .failed { color: #dc2626 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .grade-badge.pass { background: #dcfce7 !important; color: #059669 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .grade-badge.fail { background: #fee2e2 !important; color: #dc2626 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .summary-bar { background: #f0f9ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .period-heading { background: #eff6ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .weight-legend { background: #f8fafc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .student-card { page-break-inside: avoid; }
            @page { margin: 15mm; }
        }
    </style>
</head>
<body>

<div class="print-toolbar">
    <a href="<?= BASE_URL ?>/grades.php?class_id=<?= $classId ?>" class="btn btn-back">
        <i class="fas fa-arrow-left"></i> Back
    </a>
    <button class="btn btn-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print
    </button>
    <a href="<?= BASE_URL ?>/grade-export.php?type=csv&mode=<?= e($mode) ?>&class_id=<?= $classId ?><?= $studentId ? '&student_id=' . $studentId : '' ?>&period=<?= e($period) ?>" class="btn btn-csv">
        <i class="fas fa-file-csv"></i> Download CSV
    </a>
    <span class="toolbar-title">
        <i class="fas fa-graduation-cap"></i>
        Grade Report — <?= e($classLabel) ?>
        <?php if ($mode === 'student' && !empty($students)): ?>
         — <?= e($students[0]['last_name'] . ', ' . $students[0]['first_name']) ?>
        <?php endif; ?>
    </span>
</div>

<div class="print-container">
    <div class="report-header">
        <div class="school-name"><?= e($schoolName) ?></div>
        <div class="report-title">
            <?php if ($mode === 'student'): ?>
                Student Grade Report
            <?php else: ?>
                Class Grade Sheet
            <?php endif; ?>
        </div>
        <div class="report-subtitle">Generated on <?= date('F j, Y \a\t g:i A') ?></div>
    </div>

    <div class="info-grid">
        <div class="info-item"><span class="info-label">Class:</span><span class="info-value"><?= e($classLabel) ?></span></div>
        <div class="info-item"><span class="info-label">Section:</span><span class="info-value"><?= e($sectionLabel) ?></span></div>
        <div class="info-item"><span class="info-label">Instructor:</span><span class="info-value"><?= e($instructorLabel) ?></span></div>
        <div class="info-item"><span class="info-label">Period:</span><span class="info-value"><?= $period === 'both' ? 'Midterm & Final' : ucfirst($period) ?></span></div>
        <div class="info-item"><span class="info-label">Passing Grade:</span><span class="info-value"><?= $passingGrade ?>%</span></div>
        <?php if ($mode === 'student' && !empty($students)): ?>
        <div class="info-item"><span class="info-label">Student:</span><span class="info-value"><?= e($students[0]['last_name'] . ', ' . $students[0]['first_name']) ?></span></div>
        <div class="info-item"><span class="info-label">Username:</span><span class="info-value"><?= e($students[0]['username']) ?></span></div>
        <?php endif; ?>
    </div>

    <div class="weight-legend">
        <strong style="color:#475569;">Weights:</strong>
        <?php foreach ($activeComponents as $key => $comp): ?>
        <span class="weight-chip">
            <span class="weight-dot" style="background:<?= $comp['color'] ?>"></span>
            <?= e($comp['label']) ?>: <?= number_format($comp['weight'], 1) ?>%
        </span>
        <?php endforeach; ?>
    </div>

<?php if ($mode === 'student' && !empty($students)): ?>
    <?php $stu = $students[0]; ?>

    <?php foreach ($periods as $p): ?>
    <div class="period-heading"><i class="fas fa-calendar-alt" style="margin-right:6px;"></i><?= ucfirst($p) ?> Term</div>

    <div class="student-card">
        <div class="student-header">
            <div class="student-avatar"><?= strtoupper(substr($stu['first_name'], 0, 1)) ?></div>
            <div class="student-info">
                <h3><?= e($stu['last_name'] . ', ' . $stu['first_name']) ?></h3>
                <p><?= e($stu['username']) ?> &bull; <?= e($stu['program_code'] ?? '') ?></p>
            </div>
        </div>

        <?php
        $grades = $allGrades[$p][$stu['id']] ?? [];
        $totalWeighted = 0;
        ?>

        <div class="component-row" style="font-weight:700;font-size:9pt;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;border-bottom:2px solid #e2e8f0;">
            <div class="component-label">Component</div>
            <div class="component-weight">Weight</div>
            <div class="component-score">Score</div>
            <div class="component-weighted">Weighted</div>
            <div style="width:70px;text-align:center;">Status</div>
        </div>

        <?php foreach ($activeComponents as $key => $comp):
            $score = floatval($grades[$key] ?? 0);
            $weighted = $score * $comp['weight'] / 100;
            $totalWeighted += $weighted;
        ?>
        <div class="component-row">
            <div class="component-label">
                <span class="weight-dot" style="background:<?= $comp['color'] ?>"></span>
                <?= e($comp['label']) ?>
            </div>
            <div class="component-weight"><?= number_format($comp['weight'], 1) ?>%</div>
            <div class="component-score"><?= number_format($score, 2) ?></div>
            <div class="component-weighted"><?= number_format($weighted, 2) ?></div>
            <div style="width:70px;text-align:center;">
                <span class="<?= $score >= $passingGrade ? 'passed' : 'failed' ?>" style="font-size:9pt;">
                    <?= $score >= $passingGrade ? 'Pass' : 'Fail' ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="total-row">
            <div class="component-label">Weighted Average</div>
            <div class="component-weight"></div>
            <div class="component-score avg-cell <?= $totalWeighted >= $passingGrade ? 'passed' : 'failed' ?>">
                <?= number_format($totalWeighted, 2) ?>
            </div>
            <div class="component-weighted"></div>
            <div style="width:70px;text-align:center;">
                <span class="grade-badge <?= $totalWeighted >= $passingGrade ? 'pass' : 'fail' ?>">
                    <?= $totalWeighted >= $passingGrade ? 'PASSED' : 'FAILED' ?>
                </span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="signature-area">
        <div class="signature-box">
            <div class="signature-line"></div>
            <div class="signature-name"><?= e($instructorLabel) ?></div>
            <div class="signature-label">Class Instructor</div>
        </div>
        <div class="signature-box">
            <div class="signature-line"></div>
            <div class="signature-name">&nbsp;</div>
            <div class="signature-label">Academic Coordinator</div>
        </div>
    </div>

<?php else: ?>

    <?php foreach ($periods as $p): ?>
    <div class="period-heading"><i class="fas fa-calendar-alt" style="margin-right:6px;"></i><?= ucfirst($p) ?> Term</div>

    <?php if (empty($students)): ?>
        <p style="text-align:center;color:#94a3b8;padding:30px;">No students enrolled in this class.</p>
    <?php else: ?>

    <?php
    $passCount = 0; $failCount = 0; $totalAvg = 0; $highest = 0; $lowest = 100;
    foreach ($students as $stu) {
        $grades = $allGrades[$p][$stu['id']] ?? [];
        $avg = calcWeightedAvg($grades, $activeComponents);
        $totalAvg += $avg;
        if ($avg >= $passingGrade) $passCount++; else $failCount++;
        if ($avg > $highest) $highest = $avg;
        if ($avg < $lowest) $lowest = $avg;
    }
    $classAvg = count($students) > 0 ? $totalAvg / count($students) : 0;
    ?>

    <div class="summary-bar">
        <div class="summary-item">
            <div class="s-value"><?= count($students) ?></div>
            <div class="s-label">Students</div>
        </div>
        <div class="summary-item">
            <div class="s-value passed"><?= $passCount ?></div>
            <div class="s-label">Passed</div>
        </div>
        <div class="summary-item">
            <div class="s-value failed"><?= $failCount ?></div>
            <div class="s-label">Failed</div>
        </div>
        <div class="summary-item">
            <div class="s-value"><?= number_format($classAvg, 2) ?></div>
            <div class="s-label">Class Average</div>
        </div>
        <div class="summary-item">
            <div class="s-value"><?= number_format($highest, 2) ?></div>
            <div class="s-label">Highest</div>
        </div>
        <div class="summary-item">
            <div class="s-value"><?= number_format($lowest, 2) ?></div>
            <div class="s-label">Lowest</div>
        </div>
    </div>

    <table class="grade-table">
        <thead>
            <tr>
                <th style="width:36px;">#</th>
                <th>Student Name</th>
                <?php foreach ($activeComponents as $key => $comp): ?>
                <th>
                    <?= e($comp['label']) ?>
                    <div style="font-size:7pt;font-weight:400;opacity:.8;"><?= number_format($comp['weight'], 1) ?>%</div>
                </th>
                <?php endforeach; ?>
                <th>Weighted Avg</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($students as $idx => $stu):
                $grades = $allGrades[$p][$stu['id']] ?? [];
                $avg = calcWeightedAvg($grades, $activeComponents);
            ?>
            <tr>
                <td><?= $idx + 1 ?></td>
                <td>
                    <div class="student-name"><?= e($stu['last_name'] . ', ' . $stu['first_name']) ?></div>
                    <div class="student-username"><?= e($stu['username']) ?></div>
                </td>
                <?php foreach ($activeComponents as $key => $comp): ?>
                <td><?= number_format(floatval($grades[$key] ?? 0), 2) ?></td>
                <?php endforeach; ?>
                <td class="avg-cell <?= $avg >= $passingGrade ? 'passed' : 'failed' ?>">
                    <?= number_format($avg, 2) ?>
                </td>
                <td>
                    <span class="grade-badge <?= $avg >= $passingGrade ? 'pass' : 'fail' ?>">
                        <?= $avg >= $passingGrade ? 'Passed' : 'Failed' ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <?php endforeach; ?>

    <div class="signature-area">
        <div class="signature-box">
            <div class="signature-line"></div>
            <div class="signature-name"><?= e($instructorLabel) ?></div>
            <div class="signature-label">Class Instructor</div>
        </div>
        <div class="signature-box">
            <div class="signature-line"></div>
            <div class="signature-name">&nbsp;</div>
            <div class="signature-label">Academic Coordinator</div>
        </div>
    </div>

<?php endif; ?>

    <div class="report-footer">
        <p><?= e($schoolName) ?> &bull; Grade Report &bull; Generated <?= date('F j, Y g:i A') ?></p>
        <p style="margin-top:2px;">This is a system-generated document. Verify grades through the official LMS portal.</p>
    </div>
</div>

</body>
</html>
<?php exit; ?>
