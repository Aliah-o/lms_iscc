<?php
$pageTitle = 'My Classes';
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'student');
$pdo = getDB();
$user = currentUser();
$role = $user['role'];

if ($role === 'instructor') {
    $breadcrumbPills = ['Teaching'];
    $stmt = $pdo->prepare("SELECT tc.*, s.section_name,
        (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id = tc.id) as student_count,
        (SELECT COUNT(*) FROM class_join_requests cjr WHERE cjr.class_id = tc.id AND cjr.status = 'pending') as pending_count
        FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id
        WHERE tc.instructor_id = ? AND tc.is_active = 1
        ORDER BY tc.year_level, tc.semester, tc.subject_name");
    $stmt->execute([$user['id']]);
    $classes = $stmt->fetchAll();
} else {
    $breadcrumbPills = ['My Learning'];
    $stmt = $pdo->prepare("SELECT ce.class_id, tc.subject_name, tc.subject_image, tc.course_code, tc.units, tc.semester, tc.year_level, tc.class_code,
        s.section_name, u.first_name as instructor_fn, u.last_name as instructor_ln
        FROM class_enrollments ce
        JOIN instructor_classes tc ON ce.class_id = tc.id
        JOIN sections s ON tc.section_id = s.id
        JOIN users u ON tc.instructor_id = u.id
        WHERE ce.student_id = ? AND tc.is_active = 1
        ORDER BY tc.year_level, tc.semester");
    $stmt->execute([$user['id']]);
    $classes = $stmt->fetchAll();
}

require_once __DIR__ . '/views/layouts/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <small class="text-muted">BSIT &bull; <?= $role === 'instructor' ? 'Your teaching assignments' : 'Your enrolled classes' ?></small>
    </div>
    <?php if ($role === 'instructor'): ?>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/subjects.php" class="btn btn-primary-gradient"><i class="fas fa-plus me-1"></i>Create Subject</a>
        <a href="<?= BASE_URL ?>/join-class.php" class="btn btn-outline-warning"><i class="fas fa-bell me-1"></i>Join Requests</a>
    </div>
    <?php else: ?>
    <a href="<?= BASE_URL ?>/join-class.php" class="btn btn-primary-gradient"><i class="fas fa-key me-1"></i>Join a Class</a>
    <?php endif; ?>
</div>

<?php if (empty($classes)): ?>
<div class="empty-state">
    <svg viewBox="0 0 200 200" fill="none"><circle cx="100" cy="100" r="80" fill="#F1F5F9"/><text x="100" y="108" text-anchor="middle" fill="#94A3B8" font-size="40">📚</text></svg>
    <h5>No Classes Found</h5>
    <p><?= $role === 'instructor' ? 'Create your first subject from the Subjects page.' : 'Use a class code to join a class.' ?></p>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($classes as $cls): ?>
    <div class="col-md-6 col-lg-4">
        <?php $subjectImageUrl = getSubjectImageUrl($cls); ?>
        <div class="card h-100 subject-card">
            <div class="card-body d-flex flex-column">
                <div class="subject-cover">
                    <?php if ($subjectImageUrl): ?>
                    <img src="<?= e($subjectImageUrl) ?>" alt="<?= e($cls['subject_name']) ?>" loading="lazy">
                    <?php endif; ?>
                    <div class="subject-cover-fallback">
                        <small><?= e($cls['semester'] ?? 'Subject') ?></small>
                        <strong><?= e($cls['course_code'] ?? 'BSIT') ?></strong>
                        <span style="font-size:0.9rem;opacity:0.88;"><?= e($cls['year_level'] . ($cls['section_name'] ?? '')) ?></span>
                    </div>
                </div>
                <div class="d-flex align-items-start justify-content-between mb-2">
                    <div>
                        <span class="subject-chip"><?= e($cls['course_code'] ?? '') ?></span>
                        <span class="badge bg-info"><?= e($cls['semester'] ?? '') ?></span>
                    </div>
                    <span class="badge bg-light text-dark"><?= e($cls['year_level'] . ($cls['section_name'] ?? '')) ?></span>
                </div>
                <h6 class="mb-1 fw-bold"><?= e($cls['subject_name'] ?? 'General') ?></h6>
                <small class="text-muted mb-2">
                    BSIT &bull; <?= e($cls['units'] ?? '') ?> units
                </small>
                <?php if ($role === 'instructor'): ?>
                <div class="mb-2 d-flex align-items-center justify-content-between p-2" style="background:var(--gray-50);border-radius:8px;">
                    <div>
                        <span style="font-size:0.7rem;color:var(--gray-500);">CLASS CODE</span>
                        <div class="fw-bold" style="letter-spacing:2px;color:var(--primary);"><?= e($cls['class_code'] ?? '') ?></div>
                    </div>
                    <button class="btn btn-sm btn-outline-primary" onclick="navigator.clipboard.writeText('<?= e($cls['class_code'] ?? '') ?>').then(()=>showToast('Copied!','success'))">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
                <div class="mb-2" style="font-size:0.82rem;color:var(--gray-500);">
                    <i class="fas fa-users me-1"></i><?= $cls['student_count'] ?> enrolled
                    <?php if ($cls['pending_count'] > 0): ?>
                    <span class="text-warning ms-2"><i class="fas fa-clock me-1"></i><?= $cls['pending_count'] ?> pending</span>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="mb-2" style="font-size:0.82rem;color:var(--gray-500);">
                    <i class="fas fa-user me-1"></i>Instructor: <?= e($cls['instructor_fn'] . ' ' . $cls['instructor_ln']) ?>
                </div>
                <?php endif; ?>
                <div class="mt-auto d-flex gap-2 flex-wrap">
                    <?php $cid = $cls['id'] ?? $cls['class_id']; ?>
                    <a href="<?= BASE_URL ?>/lessons.php?class_id=<?= $cid ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-book-open me-1"></i>Lessons</a>
                    <a href="<?= BASE_URL ?>/knowledge-tree.php?class_id=<?= $cid ?>" class="btn btn-sm btn-outline-success"><i class="fas fa-sitemap me-1"></i>Tree</a>
                    <a href="<?= BASE_URL ?>/quizzes.php?class_id=<?= $cid ?>" class="btn btn-sm btn-outline-warning"><i class="fas fa-question-circle me-1"></i>Quiz</a>
                    <a href="<?= BASE_URL ?>/grades.php?class_id=<?= $cid ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-clipboard-check me-1"></i>Grades</a>
                    <?php if ($role === 'instructor'): ?>
                    <a href="<?= BASE_URL ?>/attendance.php?class_id=<?= $cid ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-calendar-check me-1"></i>Attendance</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
