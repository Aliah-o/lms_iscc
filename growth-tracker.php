<?php
$pageTitle = 'Growth Tracker';
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'student', 'staff', 'superadmin');
$pdo = getDB();
$user = currentUser();
$role = $user['role'];

if ($role === 'student') {
    $breadcrumbPills = ['My Learning', 'Progress This Month'];

    $growth = getGrowthData($user['id']);

    $monthlyData = [];
    for ($i = 5; $i >= 0; $i--) {
        $m = date('n', strtotime("-$i months"));
        $y = date('Y', strtotime("-$i months"));
        $stmt = $pdo->prepare("SELECT AVG(score) as avg_score, COUNT(*) as quiz_count FROM quiz_attempts WHERE student_id = ? AND MONTH(completed_at) = ? AND YEAR(completed_at) = ?");
        $stmt->execute([$user['id'], $m, $y]);
        $row = $stmt->fetch();
        $monthlyData[] = [
            'label' => date('M Y', strtotime("-$i months")),
            'avg' => $row['avg_score'] ? round($row['avg_score'], 1) : 0,
            'count' => $row['quiz_count'],
        ];
    }

    $recentAttempts = $pdo->prepare("SELECT qa.*, q.title as quiz_title FROM quiz_attempts qa JOIN quizzes q ON qa.quiz_id = q.id WHERE qa.student_id = ? ORDER BY qa.completed_at DESC LIMIT 10");
    $recentAttempts->execute([$user['id']]);
    $recentAttempts = $recentAttempts->fetchAll();

} else {
    if ($role === 'instructor') {
        $breadcrumbPills = ['Teaching', 'Growth Tracker'];
    } elseif ($role === 'staff') {
        $breadcrumbPills = ['Academic Management', 'Growth Tracker'];
    } else {
        $breadcrumbPills = ['Administration', 'Growth Tracker'];
    }

    $selectedStudent = null;
    $growth = null;
    $monthlyData = [];
    $recentAttempts = [];

    if ($role === 'instructor') {
        $stmt = $pdo->prepare("SELECT DISTINCT u.id, u.first_name, u.last_name, u.username, u.program_code, u.year_level 
            FROM users u 
            JOIN class_enrollments ce ON ce.student_id = u.id 
            JOIN instructor_classes tc ON tc.id = ce.class_id 
            WHERE tc.instructor_id = ? AND tc.is_active = 1 AND u.is_active = 1 
            ORDER BY u.last_name, u.first_name");
        $stmt->execute([$user['id']]);
        $myStudents = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query("SELECT id, first_name, last_name, username, program_code, year_level FROM users WHERE role = 'student' AND is_active = 1 ORDER BY last_name, first_name");
        $myStudents = $stmt->fetchAll();
    }

    $selectedStudentId = intval($_GET['student_id'] ?? 0);
    if ($selectedStudentId) {
        if ($role === 'instructor') {
            $check = $pdo->prepare("SELECT DISTINCT u.* FROM users u JOIN class_enrollments ce ON ce.student_id = u.id JOIN instructor_classes tc ON tc.id = ce.class_id WHERE u.id = ? AND tc.instructor_id = ? AND tc.is_active = 1");
            $check->execute([$selectedStudentId, $user['id']]);
        } else {
            $check = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student' AND is_active = 1");
            $check->execute([$selectedStudentId]);
        }
        $selectedStudent = $check->fetch();

        if ($selectedStudent) {
            $growth = getGrowthData($selectedStudentId);

            for ($i = 5; $i >= 0; $i--) {
                $m = date('n', strtotime("-$i months"));
                $y = date('Y', strtotime("-$i months"));
                $stmt = $pdo->prepare("SELECT AVG(score) as avg_score, COUNT(*) as quiz_count FROM quiz_attempts WHERE student_id = ? AND MONTH(completed_at) = ? AND YEAR(completed_at) = ?");
                $stmt->execute([$selectedStudentId, $m, $y]);
                $row = $stmt->fetch();
                $monthlyData[] = [
                    'label' => date('M Y', strtotime("-$i months")),
                    'avg' => $row['avg_score'] ? round($row['avg_score'], 1) : 0,
                    'count' => $row['quiz_count'],
                ];
            }

            $recentAttempts = $pdo->prepare("SELECT qa.*, q.title as quiz_title FROM quiz_attempts qa JOIN quizzes q ON qa.quiz_id = q.id WHERE qa.student_id = ? ORDER BY qa.completed_at DESC LIMIT 10");
            $recentAttempts->execute([$selectedStudentId]);
            $recentAttempts = $recentAttempts->fetchAll();
        }
    }
}

require_once __DIR__ . '/views/layouts/header.php';
?>

<?php if ($role !== 'student'): ?>
<div class="card mb-4">
    <div class="card-header"><span><i class="fas fa-search me-2"></i>Select Student</span></div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="class_id" value="">
            <div class="col-md-8">
                <select name="student_id" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Select a student --</option>
                    <?php foreach ($myStudents as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $selectedStudentId === $s['id'] ? 'selected' : '' ?>>
                        <?= e($s['last_name'] . ', ' . $s['first_name']) ?> (<?= e($s['username']) ?>) — <?= e($s['program_code']) ?> <?= e(YEAR_LEVELS[$s['year_level']] ?? '') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary-gradient w-100"><i class="fas fa-chart-line me-1"></i>View Growth</button>
            </div>
        </form>
    </div>
</div>

<?php if (!$selectedStudent): ?>
<div class="empty-state">
    <svg viewBox="0 0 200 200" fill="none"><circle cx="100" cy="100" r="80" fill="#F1F5F9"/><text x="100" y="108" text-anchor="middle" fill="#94A3B8" font-size="40">📊</text></svg>
    <h5>Select a Student</h5>
    <p><?= $role === 'instructor' ? 'Choose a student from your classes to view their growth data.' : 'Choose any active student to view their growth data.' ?></p>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (($role === 'student') || ($role === 'instructor' && $selectedStudent)): ?>
<?php
$displayName = ($role === 'student') ? ($user['first_name'] . ' ' . $user['last_name']) : ($selectedStudent['first_name'] . ' ' . $selectedStudent['last_name']);
?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-icon bg-primary-soft"><i class="fas fa-chart-line"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= $growth['current_avg'] !== null ? $growth['current_avg'] . '%' : 'N/A' ?></div>
                <div class="stat-label">Average This Month</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-icon <?= ($growth['improvement'] !== null && $growth['improvement'] >= 0) ? 'bg-success-soft' : 'bg-danger-soft' ?>">
                <i class="fas fa-<?= ($growth['improvement'] !== null && $growth['improvement'] >= 0) ? 'arrow-up' : 'arrow-down' ?>"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value">
                    <?php if ($growth['improvement'] !== null): ?>
                    <?= $growth['improvement'] >= 0 ? '+' : '' ?><?= $growth['improvement'] ?>%
                    <?php else: ?>
                    N/A
                    <?php endif; ?>
                </div>
                <div class="stat-label">Improvement</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-icon bg-info-soft"><i class="fas fa-clipboard-check"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= $growth['prev_avg'] !== null ? $growth['prev_avg'] . '%' : 'N/A' ?></div>
                <div class="stat-label">Last Month Average</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><span><i class="fas fa-chart-bar me-2"></i>Monthly Performance — <?= e($displayName) ?></span></div>
            <div class="card-body">
                <div class="row g-2">
                    <?php $maxAvg = max(array_column($monthlyData, 'avg') ?: [1]); ?>
                    <?php foreach ($monthlyData as $md): ?>
                    <div class="col text-center">
                        <div style="height:160px;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;">
                            <div style="font-size:0.7rem;font-weight:700;color:var(--primary);margin-bottom:4px;">
                                <?= $md['avg'] > 0 ? $md['avg'] . '%' : '' ?>
                            </div>
                            <div style="width:100%;max-width:48px;height:<?= $maxAvg > 0 ? max(round($md['avg'] / $maxAvg * 120), 4) : 4 ?>px;background:linear-gradient(180deg,var(--primary),var(--primary-light));border-radius:6px 6px 0 0;transition:height 0.6s ease;"></div>
                        </div>
                        <div style="font-size:0.68rem;color:var(--gray-500);margin-top:4px;font-weight:600;"><?= $md['label'] ?></div>
                        <div style="font-size:0.6rem;color:var(--gray-400);"><?= $md['count'] ?> quiz<?= $md['count'] !== 1 ? 'es' : '' ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card growth-tracker">
            <div class="card-header"><span><i class="fas fa-lightbulb me-2"></i>Motivation</span></div>
            <div class="card-body text-center">
                <div class="growth-value"><?= $growth['current_avg'] !== null ? $growth['current_avg'] . '%' : '—' ?></div>
                <div class="text-muted mb-3" style="font-size:0.82rem;">Current Score</div>

                <?php if ($growth['improvement'] !== null): ?>
                <div class="improvement-badge <?= $growth['improvement'] >= 0 ? 'positive' : 'negative' ?>">
                    <i class="fas fa-<?= $growth['improvement'] >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                    <?= abs($growth['improvement']) ?>% from last month
                </div>
                <?php else: ?>
                <div class="improvement-badge neutral"><i class="fas fa-minus"></i> No previous data</div>
                <?php endif; ?>

                <div class="mt-3 p-3" style="background:var(--gray-50);border-radius:8px;font-size:0.88rem;color:var(--gray-700);">
                    <?php
                    if ($growth['improvement'] !== null && $growth['improvement'] >= 20) echo "🌟 Outstanding improvement! You're a star learner!";
                    elseif ($growth['improvement'] !== null && $growth['improvement'] >= 10) echo "🎯 Great progress! Keep pushing for excellence!";
                    elseif ($growth['improvement'] !== null && $growth['improvement'] >= 0) echo "👍 Steady progress! Every step counts.";
                    elseif ($growth['improvement'] !== null) echo "💪 Keep trying! Review your lessons and practice more.";
                    else echo "📚 Take some quizzes to start tracking your growth!";
                    ?>
                </div>

                <?php if ($growth['current_avg'] !== null): ?>
                <div class="mt-3">
                    <div class="d-flex justify-content-between mb-1" style="font-size:0.78rem;">
                        <span>Performance</span>
                        <span class="fw-bold"><?= $growth['current_avg'] ?>%</span>
                    </div>
                    <div class="progress-bar-custom">
                        <div class="bar" style="width:<?= min($growth['current_avg'], 100) ?>%;background:<?= $growth['current_avg'] >= 75 ? 'linear-gradient(90deg,var(--success),#34D399)' : ($growth['current_avg'] >= 50 ? 'linear-gradient(90deg,var(--warning),#FBBF24)' : 'linear-gradient(90deg,var(--danger),#F87171)') ?>;"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header"><span><i class="fas fa-history me-2"></i>Recent Quiz Attempts</span></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-modern mb-0">
                <thead><tr><th>Quiz</th><th>Score</th><th>Correct</th><th>Date</th></tr></thead>
                <tbody>
                <?php if (empty($recentAttempts)): ?>
                <tr><td colspan="4" class="text-center py-4 text-muted">No quiz attempts yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentAttempts as $ra): ?>
                <tr>
                    <td class="fw-bold"><?= e($ra['quiz_title']) ?></td>
                    <td>
                        <span class="badge bg-<?= $ra['score'] >= 75 ? 'success' : ($ra['score'] >= 50 ? 'warning' : 'danger') ?>" style="font-size:0.82rem;"><?= $ra['score'] ?>%</span>
                    </td>
                    <td><?= $ra['correct_items'] ?>/<?= $ra['total_items'] ?></td>
                    <td style="font-size:0.82rem;color:var(--gray-500);"><?= date('M d, Y g:ia', strtotime($ra['completed_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
