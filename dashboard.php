<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/helpers/functions.php';
requireMFA();
$user = currentUser();
$pdo = getDB();
$role = $user['role'];

// Auto-archive expired school years on every dashboard load
try {
    archiveExpiredSchoolYears();
} catch (Exception $e) { /* school_years table may not exist yet */ }

if ($role === 'superadmin') {
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalStudents = $pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
    $totalInstructors = $pdo->query("SELECT COUNT(*) FROM users WHERE role='instructor'")->fetchColumn();
    $totalClasses = $pdo->query("SELECT COUNT(*) FROM instructor_classes")->fetchColumn();
    $totalSections = $pdo->query("SELECT COUNT(*) FROM sections")->fetchColumn();
    $recentLogs = $pdo->query("SELECT al.*, u.first_name, u.last_name FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id ORDER BY al.created_at DESC LIMIT 8")->fetchAll();
    $breadcrumbPills = ['System Overview'];
} elseif ($role === 'staff') {
    $totalStudents = $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND is_active=1")->fetchColumn();
    $totalInstructors = $pdo->query("SELECT COUNT(*) FROM users WHERE role='instructor' AND is_active=1")->fetchColumn();
    $totalSections = $pdo->query("SELECT COUNT(*) FROM sections WHERE is_active=1")->fetchColumn();
    $totalClasses = $pdo->query("SELECT COUNT(*) FROM instructor_classes WHERE is_active=1")->fetchColumn();
    $breadcrumbPills = ['Academic Management'];
} elseif ($role === 'instructor') {
    $stmt = $pdo->prepare("SELECT tc.*, s.section_name, tc.course_code, tc.class_code, tc.semester FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id WHERE tc.instructor_id = ? AND tc.is_active = 1");
    $stmt->execute([$user['id']]);
    $myClasses = $stmt->fetchAll();
    $totalStudentCount = 0;
    foreach ($myClasses as $cls) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM class_enrollments WHERE class_id = ?");
        $c->execute([$cls['id']]);
        $totalStudentCount += $c->fetchColumn();
    }
    $pendingRequests = $pdo->prepare("SELECT COUNT(*) FROM class_join_requests cjr JOIN instructor_classes tc ON cjr.class_id = tc.id WHERE tc.instructor_id = ? AND cjr.status = 'pending'");
    $pendingRequests->execute([$user['id']]);
    $pendingRequests = $pendingRequests->fetchColumn();
    $breadcrumbPills = ['My Classes'];
} else {
    $stmt = $pdo->prepare("SELECT ce.class_id, tc.subject_name, tc.subject_image, tc.program_code, tc.year_level, tc.course_code, tc.semester, tc.units, s.section_name, u.first_name as instructor_fn, u.last_name as instructor_ln FROM class_enrollments ce JOIN instructor_classes tc ON ce.class_id = tc.id JOIN sections s ON tc.section_id = s.id JOIN users u ON tc.instructor_id = u.id WHERE ce.student_id = ?");
    $stmt->execute([$user['id']]);
    $myClasses = $stmt->fetchAll();
    $growth = getGrowthData($user['id']);
    $badgeCount = $pdo->prepare("SELECT COUNT(*) FROM badge_earns WHERE student_id = ?");
    $badgeCount->execute([$user['id']]);
    $badgeCount = $badgeCount->fetchColumn();

    $totalNodes = 0;
    $completedNodes = 0;
    foreach ($myClasses as $cls) {
        $n = $pdo->prepare("SELECT COUNT(*) FROM knowledge_nodes WHERE class_id = ?");
        $n->execute([$cls['class_id']]);
        $totalNodes += $n->fetchColumn();
        $cn = $pdo->prepare("SELECT COUNT(*) FROM knowledge_node_progress knp JOIN knowledge_nodes kn ON knp.node_id = kn.id WHERE kn.class_id = ? AND knp.student_id = ? AND knp.completed = 1");
        $cn->execute([$cls['class_id'], $user['id']]);
        $completedNodes += $cn->fetchColumn();
    }
    $breadcrumbPills = ['My Learning', 'Progress This Month'];
}

require_once __DIR__ . '/views/layouts/header.php';
?>

<?php
$dashboardAvatarUrl = getUserAvatarUrl($user);
$dashboardInitials = getUserInitials($user);
?>
<div class="card dashboard-profile-card mb-4">
    <div class="card-body">
        <div class="dashboard-profile-shell dashboard-profile-stack justify-content-between">
            <div class="dashboard-profile-main dashboard-profile-stack">
                <?php if ($dashboardAvatarUrl): ?>
                <img src="<?= e($dashboardAvatarUrl) ?>" alt="<?= e($user['first_name']) ?>" class="dashboard-profile-avatar">
                <?php else: ?>
                <div class="dashboard-profile-avatar"><?= e($dashboardInitials) ?></div>
                <?php endif; ?>
                <div class="dashboard-profile-copy">
                    <div class="subject-chip mb-2"><i class="bi bi-person-badge"></i><?= e(ROLES[$user['role']] ?? ucfirst($user['role'])) ?></div>
                    <h4 class="mb-1" style="font-weight:800;letter-spacing:-0.04em;"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></h4>
                    <div class="text-muted" style="font-size:0.9rem;"><?= e($user['email'] ?: 'Add an email and profile photo from your account settings.') ?></div>
                </div>
            </div>
            <div class="dashboard-profile-actions d-flex gap-2 flex-wrap">
                <a href="<?= BASE_URL ?>/profile.php" class="btn btn-outline-primary"><i class="bi bi-sliders2-vertical me-2"></i>Edit Profile</a>
                <a href="<?= BASE_URL ?>/profile.php#security" class="btn btn-primary-gradient"><i class="bi bi-shield-lock me-2"></i>Change Password</a>
            </div>
        </div>
    </div>
</div>

<?php if ($role === 'superadmin'): ?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-primary-soft"><i class="fas fa-users"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $totalUsers ?></div><div class="stat-label">Total Users</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-info-soft"><i class="fas fa-user-graduate"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $totalStudents ?></div><div class="stat-label">Students</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-success-soft"><i class="fas fa-person-chalkboard"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $totalInstructors ?></div><div class="stat-label">Instructors</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-warning-soft"><i class="fas fa-layer-group"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $totalSections ?></div><div class="stat-label">Sections</div></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><span><i class="fas fa-clipboard-list me-2"></i>Recent Activity</span></div>
            <div class="card-body p-0">
                <div class="table-responsive dashboard-activity-wrap">
                    <table class="table table-modern mb-0 dashboard-activity-table">
                        <thead><tr><th>User</th><th>Action</th><th>Details</th><th>Time</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td class="fw-600" data-label="User"><?= e(($log['first_name'] ?? 'System') . ' ' . ($log['last_name'] ?? '')) ?></td>
                            <td data-label="Action"><span class="badge bg-light text-dark"><?= e($log['action']) ?></span></td>
                            <td class="text-muted" style="font-size:0.82rem;" data-label="Details"><?= e(substr($log['details'], 0, 60)) ?></td>
                            <td style="font-size:0.8rem;" data-label="Time"><?= date('M d, g:ia', strtotime($log['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><span><i class="fas fa-university me-2"></i>Program</span></div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div style="width:36px;height:36px;border-radius:8px;background:var(--primary-50);display:flex;align-items:center;justify-content:center;"><i class="fas fa-graduation-cap" style="color:var(--primary);font-size:0.85rem;"></i></div>
                    <div><div class="fw-bold" style="font-size:0.85rem;">BSIT</div><div style="font-size:0.72rem;color:var(--gray-500);">Bachelor of Science in Information Technology</div></div>
                </div>
                <div class="row g-2 text-center mt-2 dashboard-program-summary">
                    <div class="col-12 col-sm-4"><div style="background:var(--gray-50);border-radius:8px;padding:8px;"><div class="fw-bold" style="color:var(--primary);"><?= $totalSections ?></div><div style="font-size:0.7rem;color:var(--gray-500);">Sections</div></div></div>
                    <div class="col-12 col-sm-4"><div style="background:var(--gray-50);border-radius:8px;padding:8px;"><div class="fw-bold" style="color:var(--success);"><?= $totalStudents ?></div><div style="font-size:0.7rem;color:var(--gray-500);">Students</div></div></div>
                    <div class="col-12 col-sm-4"><div style="background:var(--gray-50);border-radius:8px;padding:8px;"><div class="fw-bold" style="color:var(--info);"><?= $totalInstructors ?></div><div style="font-size:0.7rem;color:var(--gray-500);">Instructors</div></div></div>
                </div>
                <a href="<?= BASE_URL ?>/programs.php" class="btn btn-outline-primary btn-sm w-100 mt-3"><i class="fas fa-university me-1"></i>View Curriculum</a>
            </div>
        </div>
    </div>
</div>

<?php elseif ($role === 'staff'): ?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-primary-soft"><i class="fas fa-user-graduate"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $totalStudents ?></div><div class="stat-label">Students</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-success-soft"><i class="fas fa-person-chalkboard"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $totalInstructors ?></div><div class="stat-label">Instructors</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-info-soft"><i class="fas fa-layer-group"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $totalSections ?></div><div class="stat-label">Sections</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-warning-soft"><i class="fas fa-chalkboard"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $totalClasses ?></div><div class="stat-label">Classes</div></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><span><i class="fas fa-bolt me-2"></i>Quick Actions</span></div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="<?= BASE_URL ?>/sections.php" class="btn btn-outline-primary text-start"><i class="fas fa-layer-group me-2"></i>Manage Sections</a>
                    <a href="<?= BASE_URL ?>/assignments.php" class="btn btn-outline-primary text-start"><i class="fas fa-user-check me-2"></i>Manage Assignments</a>
                    <a href="<?= BASE_URL ?>/users.php" class="btn btn-outline-primary text-start"><i class="fas fa-users me-2"></i>Manage Users</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><span><i class="fas fa-university me-2"></i>Program</span></div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:42px;height:42px;border-radius:10px;background:var(--primary-50);display:flex;align-items:center;justify-content:center;"><i class="fas fa-graduation-cap" style="color:var(--primary);"></i></div>
                    <div><div class="fw-bold">BSIT</div><div style="font-size:0.78rem;color:var(--gray-500);">Bachelor of Science in Information Technology</div></div>
                </div>
                <a href="<?= BASE_URL ?>/programs.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-book me-1"></i>View Curriculum</a>
            </div>
        </div>
    </div>
</div>

<?php elseif ($role === 'instructor'): ?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-primary-soft"><i class="fas fa-chalkboard"></i></div>
            <div class="stat-info"><div class="stat-value"><?= count($myClasses) ?></div><div class="stat-label">My Classes</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-info-soft"><i class="fas fa-user-graduate"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $totalStudentCount ?></div><div class="stat-label">Total Students</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-warning-soft"><i class="fas fa-user-clock"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $pendingRequests ?></div><div class="stat-label">Pending Requests</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-success-soft"><i class="fas fa-book-open"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?php
                    $lc = 0;
                    foreach ($myClasses as $c) {
                        $l = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE class_id = ?");
                        $l->execute([$c['id']]);
                        $lc += $l->fetchColumn();
                    }
                    echo $lc;
                ?></div>
                <div class="stat-label">Lessons Created</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($myClasses as $cls): ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <?php $subjectImageUrl = getSubjectImageUrl($cls); ?>
                <div class="dashboard-teaching-head">
                    <div class="dashboard-class-media" style="width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,var(--primary-50),var(--primary-100));display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">
                        <?php if ($subjectImageUrl): ?>
                        <img src="<?= e($subjectImageUrl) ?>" alt="<?= e($cls['subject_name']) ?>" style="width:100%;height:100%;object-fit:cover;">
                        <?php else: ?>
                        <i class="fas fa-person-chalkboard" style="color:var(--primary);"></i>
                        <?php endif; ?>
                    </div>
                    <div class="dashboard-class-copy">
                        <h6 class="mb-0 fw-bold dashboard-class-title"><?= e($cls['subject_name']) ?></h6>
                        <small class="text-muted dashboard-class-meta">
                            <span class="badge bg-primary me-1"><?= e($cls['course_code'] ?? '') ?></span>
                            BSIT <?= e(getSectionDisplay($cls['year_level'], $cls['section_name'])) ?>
                            <?php if (!empty($cls['semester'])): ?> • <?= e($cls['semester']) ?><?php endif; ?>
                            <?php if (!empty($cls['class_code'])): ?> • <code><?= e($cls['class_code']) ?></code><?php endif; ?>
                        </small>
                    </div>
                </div>
                <div class="dashboard-class-actions d-flex gap-2 flex-wrap">
                    <a href="<?= BASE_URL ?>/lessons.php?class_id=<?= $cls['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-book-open me-1"></i>Lessons</a>
                    <a href="<?= BASE_URL ?>/knowledge-tree.php?class_id=<?= $cls['id'] ?>" class="btn btn-sm btn-outline-success"><i class="fas fa-sitemap me-1"></i>Tree</a>
                    <a href="<?= BASE_URL ?>/quizzes.php?class_id=<?= $cls['id'] ?>" class="btn btn-sm btn-outline-warning"><i class="fas fa-question-circle me-1"></i>Quizzes</a>
                    <a href="<?= BASE_URL ?>/join-class.php?class_id=<?= $cls['id'] ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-user-check me-1"></i>Requests</a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php else: ?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-primary-soft"><i class="fas fa-book-reader"></i></div>
            <div class="stat-info"><div class="stat-value"><?= count($myClasses) ?></div><div class="stat-label">Enrolled Classes</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-success-soft"><i class="fas fa-chart-line"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $growth['current_avg'] !== null ? $growth['current_avg'] . '%' : 'N/A' ?></div><div class="stat-label">Avg Score This Month</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-warning-soft"><i class="fas fa-medal"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $badgeCount ?></div><div class="stat-label">Badges Earned</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-info-soft"><i class="fas fa-sitemap"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $completedNodes ?>/<?= $totalNodes ?></div><div class="stat-label">Tree Progress</div></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><span><i class="fas fa-book-reader me-2"></i>My Classes</span></div>
            <div class="card-body">
                <?php if (empty($myClasses)): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 200 200" fill="none"><circle cx="100" cy="100" r="80" fill="#F1F5F9"/><text x="100" y="108" text-anchor="middle" fill="#94A3B8" font-size="40">📚</text></svg>
                    <h5>No Classes Yet</h5>
                    <p>You haven't been enrolled in any classes yet.</p>
                </div>
                <?php else: ?>
                <?php foreach ($myClasses as $cls): ?>
                <?php $subjectImageUrl = getSubjectImageUrl($cls); ?>
                <div class="dashboard-class-list-item">
                    <div class="dashboard-class-media" style="width:48px;height:48px;border-radius:12px;background:var(--primary-50);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">
                        <?php if ($subjectImageUrl): ?>
                        <img src="<?= e($subjectImageUrl) ?>" alt="<?= e($cls['subject_name']) ?>" style="width:100%;height:100%;object-fit:cover;">
                        <?php else: ?>
                        <i class="fas fa-book" style="color:var(--primary);"></i>
                        <?php endif; ?>
                    </div>
                    <div class="dashboard-class-copy flex-grow-1">
                        <div class="fw-bold dashboard-class-title" style="font-size:0.9rem;"><?= e($cls['subject_name']) ?></div>
                        <div class="dashboard-class-meta" style="font-size:0.78rem;color:var(--gray-500);">
                            <span class="badge bg-primary me-1"><?= e($cls['course_code'] ?? '') ?></span>
                            BSIT <?= e(getSectionDisplay($cls['year_level'], $cls['section_name'])) ?>
                            <?php if (!empty($cls['semester'])): ?> • <?= e($cls['semester']) ?><?php endif; ?>
                            • <?= e($cls['instructor_fn'] . ' ' . $cls['instructor_ln']) ?>
                        </div>
                    </div>
                    <a href="<?= BASE_URL ?>/lessons.php?class_id=<?= $cls['class_id'] ?>" class="btn btn-sm btn-primary-gradient dashboard-class-link">View</a>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card growth-tracker">
            <div class="card-header"><span><i class="fas fa-chart-line me-2"></i>Growth Tracker</span></div>
            <div class="card-body text-center">
                <div class="growth-value"><?= $growth['current_avg'] !== null ? $growth['current_avg'] . '%' : '—' ?></div>
                <div class="text-muted mb-3" style="font-size:0.82rem;">Average Score This Month</div>
                <?php if ($growth['improvement'] !== null): ?>
                <div class="improvement-badge <?= $growth['improvement'] >= 0 ? 'positive' : 'negative' ?>">
                    <i class="fas fa-<?= $growth['improvement'] >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                    <?= abs($growth['improvement']) ?>% from last month
                </div>
                <?php else: ?>
                <div class="improvement-badge neutral"><i class="fas fa-minus"></i> No previous data</div>
                <?php endif; ?>
                <div class="mt-3 p-3" style="background:var(--gray-50);border-radius:8px;font-size:0.82rem;color:var(--gray-600);">
                    <?php
                    if ($growth['improvement'] !== null && $growth['improvement'] >= 10) echo "🌟 Amazing improvement! Keep up the great work!";
                    elseif ($growth['improvement'] !== null && $growth['improvement'] >= 0) echo "👍 Good progress! You're on the right track.";
                    elseif ($growth['improvement'] !== null) echo "💪 Don't give up! Review your lessons and try again.";
                    else echo "📚 Start taking quizzes to track your growth!";
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
