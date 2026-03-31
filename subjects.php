<?php
$pageTitle = 'My Subjects';
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'staff', 'admin');
$pdo = getDB();
$user = currentUser();
$breadcrumbPills = ['Teaching', 'Subjects'];

$curriculum = loadCurriculum(true);
$backSubjects = $curriculum['Back_Subjects'] ?? [];
$backSubjectCodes = array_column($backSubjects, 'code');

$allSubjects = getCurriculumSubjects();
$subjectImageMimeMap = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];
$subjectImageExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

$allSections = $pdo->query("SELECT * FROM sections WHERE is_active=1 AND program_code='BSIT' ORDER BY year_level, section_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect('/subjects.php'); }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_subject' || $action === 'update_subject') {
        $subjectId = ($action === 'update_subject') ? intval($_POST['subject_id'] ?? 0) : 0;
        $courseCode = strtoupper(trim($_POST['course_code'] ?? ''));
        $subjectName = trim($_POST['subject_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $units = trim($_POST['units'] ?? '3');
        $prerequisite = trim($_POST['prerequisite'] ?? 'None');
        $sectionId = intval($_POST['section_id'] ?? 0);
        $semester = trim($_POST['semester'] ?? '');
        $subjectType = trim($_POST['subject_type'] ?? 'regular');
        $isBackSubject = ($subjectType === 'back') || in_array($courseCode, $backSubjectCodes, true);
        $subjectImageValidation = ['ok' => true];

        $validationErrors = [];

        if (empty($courseCode)) $validationErrors[] = 'Course code is required.';
        if (empty($subjectName)) $validationErrors[] = 'Subject name is required.';
        if (empty($units)) $validationErrors[] = 'Units is required.';
        if (!$sectionId) $validationErrors[] = 'Class/Section is required.';
        if (!in_array($semester, SEMESTERS)) $validationErrors[] = 'Invalid semester.';

        $section = null;
        if ($sectionId) {
            $stmt = $pdo->prepare("SELECT * FROM sections WHERE id = ? AND is_active = 1");
            $stmt->execute([$sectionId]);
            $section = $stmt->fetch();
            if (!$section) $validationErrors[] = 'Invalid section.';
        }

        if (empty($validationErrors) && $section) {
            $currSubject = getSubjectByCode($courseCode);
            if (!$currSubject) {
                $validationErrors[] = "Subject \"$courseCode\" is not part of the BSIT curriculum. Only subjects from the program offered can be created.";
            } else {
                // Auto-fill from curriculum to prevent tampering
                $subjectName = $currSubject['subject'];
                $units = is_array($currSubject['units']) ? implode('/', $currSubject['units']) : $currSubject['units'];
                $prerequisite = $currSubject['prerequisite'];

                if (!validateSubjectSemester($courseCode, $semester)) {
                    $validationErrors[] = "Subject $courseCode belongs to \"{$currSubject['semester']}\" in the curriculum, not \"$semester\".";
                }
                if (!$isBackSubject && !validateSubjectYearLevel($courseCode, $section['year_level'])) {
                    $validationErrors[] = "Subject $courseCode belongs to Year {$currSubject['year_level']} in the curriculum, not Year {$section['year_level']}.";
                }
            }
        }

        if (empty($validationErrors) && $action === 'create_subject' && !empty($_FILES['subject_image']['name'])) {
            $subjectImageValidation = validateUploadedFile($_FILES['subject_image'], $subjectImageMimeMap, $subjectImageExtensions, UPLOAD_IMAGE_MAX_SIZE, false);
            if (!$subjectImageValidation['ok']) {
                $validationErrors[] = $subjectImageValidation['error'];
            }
        }

        if (!empty($validationErrors)) {
            flash('error', implode(' ', $validationErrors));
            redirect('/subjects.php');
        }

        if ($action === 'create_subject') {
            $classCode = generateClassCode();
            $storedSubjectImagePath = null;

            try {
                $pdo->beginTransaction();

                $fields = 'instructor_id, subject_name, course_code, description, units, prerequisite, section_id, semester, class_code, program_code, year_level';
                $placeholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, \'BSIT\', ?';
                $params = [
                    $user['id'],
                    $subjectName,
                    $courseCode,
                    $description,
                    $units,
                    $prerequisite ?: 'None',
                    $sectionId,
                    $semester,
                    $classCode,
                    $section['year_level']
                ];

                if ($hasIsBackField) {
                    $fields .= ', is_back_subject';
                    $placeholders .= ', ?';
                    $params[] = $isBackSubject ? 1 : 0;
                }

                $pdo->prepare("INSERT INTO instructor_classes ($fields) VALUES ($placeholders)")->execute($params);

                $classId = (int)$pdo->lastInsertId();
                if (!empty($_FILES['subject_image']['name'])) {
                    $storedImage = storeUploadedFile($_FILES['subject_image'], 'uploads/subjects', 'subject_' . $classId, $subjectImageMimeMap, $subjectImageExtensions, UPLOAD_IMAGE_MAX_SIZE);
                    if (!$storedImage['ok']) {
                        throw new RuntimeException($storedImage['error']);
                    }
                    $storedSubjectImagePath = $storedImage['relative_path'];

                    $pdo->prepare("UPDATE instructor_classes SET subject_image = ? WHERE id = ?")
                        ->execute([$storedSubjectImagePath, $classId]);
                }

                $pdo->commit();
                auditLog('subject_created', "Instructor created subject $courseCode - $subjectName (Class Code: $classCode)");
                flash('success', "Subject created successfully! Class Code: <strong>$classCode</strong>");
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (!empty($storedSubjectImagePath)) {
                    deleteStorageFile($storedSubjectImagePath);
                }
                flash('error', 'Unable to create the subject right now. Please try again.');
            }

            redirect('/subjects.php');
        }

        if ($action === 'update_subject') {
            if (!$subjectId) {
                flash('error', 'Invalid subject ID.');
                redirect('/subjects.php');
            }

            $check = $pdo->prepare("SELECT * FROM instructor_classes WHERE id = ? AND instructor_id = ?");
            $check->execute([$subjectId, $user['id']]);
            $existing = $check->fetch();
            if (!$existing) {
                flash('error', 'Subject not found.');
                redirect('/subjects.php');
            }

            $storedSubjectImagePath = null;
            if (!empty($_FILES['subject_image']['name'])) {
                $subjectImageValidation = validateUploadedFile($_FILES['subject_image'], $subjectImageMimeMap, $subjectImageExtensions, UPLOAD_IMAGE_MAX_SIZE, false);
                if (!$subjectImageValidation['ok']) {
                    flash('error', $subjectImageValidation['error']);
                    redirect('/subjects.php');
                }

                $storedImage = storeUploadedFile($_FILES['subject_image'], 'uploads/subjects', 'subject_' . $subjectId, $subjectImageMimeMap, $subjectImageExtensions, UPLOAD_IMAGE_MAX_SIZE);
                if (!$storedImage['ok']) {
                    flash('error', $storedImage['error']);
                    redirect('/subjects.php');
                }

                $storedSubjectImagePath = $storedImage['relative_path'];
            }

            $sql = "UPDATE instructor_classes SET subject_name = ?, course_code = ?, description = ?, units = ?, prerequisite = ?, section_id = ?, semester = ?, year_level = ?";
            $params = [$subjectName, $courseCode, $description, $units, $prerequisite ?: 'None', $sectionId, $semester, $section['year_level']];

            if ($hasIsBackField) {
                $sql .= ", is_back_subject = ?";
                $params[] = $isBackSubject ? 1 : 0;
            }

            if ($storedSubjectImagePath !== null) {
                $sql .= ", subject_image = ?";
                $params[] = $storedSubjectImagePath;
            }

            $sql .= " WHERE id = ? AND instructor_id = ?";
            $params[] = $subjectId;
            $params[] = $user['id'];

            $pdo->prepare($sql)->execute($params);

            if ($storedSubjectImagePath !== null && !empty($existing['subject_image'])) {
                deleteStorageFile($existing['subject_image']);
            }

            auditLog('subject_updated', "Instructor updated subject #$subjectId ($courseCode)");
            flash('success', 'Subject updated successfully.');
            redirect('/subjects.php');
        }

    } elseif ($action === 'delete_subject') {
        $subjectId = intval($_POST['subject_id'] ?? 0);
        $check = $pdo->prepare("SELECT id FROM instructor_classes WHERE id = ? AND instructor_id = ?");
        $check->execute([$subjectId, $user['id']]);
        if ($check->fetch()) {
            $pdo->prepare("DELETE FROM instructor_classes WHERE id = ?")->execute([$subjectId]);
            auditLog('subject_deleted', "Deleted subject #$subjectId");
            flash('success', 'Subject deleted.');
        }
        redirect('/subjects.php');

    } elseif ($action === 'add_back_subject' || $action === 'update_back_subject') {
        $selectedCurriculumCode = strtoupper(trim($_POST['back_curriculum_code'] ?? ''));
        $code = strtoupper(trim($_POST['back_code'] ?? ''));
        $name = trim($_POST['back_subject'] ?? '');
        $units = trim($_POST['back_units'] ?? '');
        $prerequisite = trim($_POST['back_prerequisite'] ?? 'None');
        $subjectIndex = intval($_POST['subject_index'] ?? -1);

        if ($selectedCurriculumCode !== '') {
            $curriculumSubject = getSubjectByCode($selectedCurriculumCode);
            if (!$curriculumSubject) {
                flash('error', 'Selected curriculum subject is not found in BSIT curriculum.');
                redirect('/subjects.php');
            }
            $code = strtoupper($curriculumSubject['code']);
            $name = $curriculumSubject['subject'];
            $units = is_array($curriculumSubject['units']) ? implode('/', $curriculumSubject['units']) : $curriculumSubject['units'];
            $prerequisite = $curriculumSubject['prerequisite'] ?? 'None';
        }

        if ($code === '' || $name === '' || $units === '') {
            flash('error', 'Back subject code, name, and units are required.');
            redirect('/subjects.php');
        }

        if (!isset($curriculum['Back_Subjects']) || !is_array($curriculum['Back_Subjects'])) {
            $curriculum['Back_Subjects'] = [];
        }

        // Prevent duplicate back subject code entries
        foreach ($curriculum['Back_Subjects'] as $idx => $backSubject) {
            if (strcasecmp($backSubject['code'] ?? '', $code) === 0 && ($action !== 'update_back_subject' || $idx !== $subjectIndex)) {
                flash('error', 'Back subject with this code already exists.');
                redirect('/subjects.php');
            }
        }

        $subjectData = [
            'code' => $code,
            'subject' => $name,
            'units' => $units,
            'prerequisite' => $prerequisite !== '' ? $prerequisite : 'None',
        ];

        if ($action === 'add_back_subject') {
            $curriculum['Back_Subjects'][] = $subjectData;
            auditLog('back_subject_added', "Back subject $code added");
            flash('success', 'Back subject added.');
        } else {
            if (!isset($curriculum['Back_Subjects'][$subjectIndex])) {
                flash('error', 'Back subject not found.');
                redirect('/subjects.php');
            }
            $curriculum['Back_Subjects'][$subjectIndex] = $subjectData;
            auditLog('back_subject_updated', "Back subject $code updated");
            flash('success', 'Back subject updated.');
        }

        saveCurriculum($curriculum);
        redirect('/subjects.php');

    } elseif ($action === 'delete_back_subject') {
        $subjectIndex = intval($_POST['subject_index'] ?? -1);
        if (!isset($curriculum['Back_Subjects'][$subjectIndex])) {
            flash('error', 'Back subject not found.');
            redirect('/subjects.php');
        }
        $deleted = $curriculum['Back_Subjects'][$subjectIndex]['code'] ?? 'Subject';
        array_splice($curriculum['Back_Subjects'], $subjectIndex, 1);
        saveCurriculum($curriculum);
        auditLog('back_subject_deleted', "Back subject $deleted deleted");
        flash('success', 'Back subject deleted.');
        redirect('/subjects.php');

    } elseif ($action === 'archive_subject') {
        $id = intval($_POST['subject_id'] ?? 0);
        $check = $pdo->prepare("SELECT id FROM instructor_classes WHERE id = ? AND instructor_id = ?");
        $check->execute([$id, $user['id']]);
        if ($check->fetch()) {
            $pdo->prepare("UPDATE instructor_classes SET is_active = 0 WHERE id = ?")->execute([$id]);
            auditLog('subject_archived', "Archived subject #$id");
            flash('success', 'Subject archived.');
        }
        redirect('/subjects.php?tab=archived');
    } elseif ($action === 'restore_subject') {
        $id = intval($_POST['subject_id'] ?? 0);
        $check = $pdo->prepare("SELECT id FROM instructor_classes WHERE id = ? AND instructor_id = ?");
        $check->execute([$id, $user['id']]);
        if ($check->fetch()) {
            $pdo->prepare("UPDATE instructor_classes SET is_active = 1 WHERE id = ?")->execute([$id]);
            auditLog('subject_restored', "Restored subject #$id");
            flash('success', 'Subject restored.');
        }
        redirect('/subjects.php');
    }
}

$hasIsBackField = false;
try {
    $hasIsBackField = (bool)$pdo->query("SHOW COLUMNS FROM instructor_classes LIKE 'is_back_subject'")->fetch();
} catch (Throwable $e) {
    $hasIsBackField = false;
}
$backSubjectField = $hasIsBackField ? 'tc.is_back_subject' : '0 as is_back_subject';

$stmt = $pdo->prepare("SELECT tc.*, s.section_name, s.year_level as sec_year, $backSubjectField,
    (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id = tc.id) as student_count,
    (SELECT COUNT(*) FROM class_join_requests cjr WHERE cjr.class_id = tc.id AND cjr.status = 'pending') as pending_count
    FROM instructor_classes tc
    JOIN sections s ON tc.section_id = s.id
    WHERE tc.instructor_id = ?
    ORDER BY tc.year_level, tc.semester, tc.subject_name");
$stmt->execute([$user['id']]);
$allSubjectsList = $stmt->fetchAll();
foreach ($allSubjectsList as &$subj) {
    if (!isset($subj['is_back_subject']) || $subj['is_back_subject'] === null) {
        $subj['is_back_subject'] = in_array($subj['course_code'], $backSubjectCodes, true) ? 1 : 0;
    }
    // fallback for older table lacking the column
    if (!$subj['is_back_subject'] && in_array($subj['course_code'], $backSubjectCodes, true)) {
        $subj['is_back_subject'] = 1;
    }
}
unset($subj);

$activeTab = $_GET['tab'] ?? 'active';
$activeSubjects = array_filter($allSubjectsList, fn($s) => $s['is_active'] == 1);
$archivedSubjects = array_filter($allSubjectsList, fn($s) => $s['is_active'] == 0);
$subjects = ($activeTab === 'archived') ? $archivedSubjects : $activeSubjects;

require_once __DIR__ . '/views/layouts/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">My Created Subjects</h5>
        <small class="text-muted">Create and manage your BSIT subjects. Students join using your unique class codes.</small>
    </div>
    <button class="btn btn-primary-gradient" data-bs-toggle="modal" data-bs-target="#createSubjectModal">
        <i class="fas fa-plus me-1"></i>Create Subject
    </button>
</div>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'active' ? 'active' : '' ?>" href="<?= BASE_URL ?>/subjects.php?tab=active">
            <i class="fas fa-check-circle me-1"></i>Active <span class="badge bg-primary ms-1"><?= count($activeSubjects) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'archived' ? 'active' : '' ?>" href="<?= BASE_URL ?>/subjects.php?tab=archived">
            <i class="fas fa-archive me-1"></i>Archived <span class="badge bg-secondary ms-1"><?= count($archivedSubjects) ?></span>
        </a>
    </li>
</ul>

<?php if (empty($subjects)): ?>
<div class="empty-state">
    <svg viewBox="0 0 200 200" fill="none"><circle cx="100" cy="100" r="80" fill="#F1F5F9"/><text x="100" y="108" text-anchor="middle" fill="#94A3B8" font-size="40">📖</text></svg>
    <h5>No Subjects Yet</h5>
    <p>Create your first subject to get started. Students will join using the generated class code.</p>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($subjects as $subj):
        $subjectImageUrl = getSubjectImageUrl($subj);
    ?>
    <div class="col-md-6 col-lg-4">
        <div class="card h-100 subject-card <?= !$subj['is_active'] ? 'opacity-50' : '' ?>">
            <div class="card-body d-flex flex-column">
                <div class="subject-cover">
                    <?php if ($subjectImageUrl): ?>
                    <img src="<?= e($subjectImageUrl) ?>" alt="<?= e($subj['subject_name']) ?>" loading="lazy">
                    <?php endif; ?>
                    <div class="subject-cover-fallback">
                        <small><?= e($subj['semester']) ?></small>
                        <strong><?= e($subj['course_code']) ?></strong>
                        <span style="font-size:0.9rem;opacity:0.88;"><?= e($subj['sec_year'] . $subj['section_name']) ?></span>
                    </div>
                </div>
                <div class="d-flex align-items-start justify-content-between mb-2">
                    <div>
                        <span class="subject-chip me-1 mb-1"><i class="bi bi-journal-bookmark"></i><?= e($subj['course_code']) ?></span>
                        <span class="badge bg-info mb-1"><?= e($subj['semester']) ?></span>
                        <?php if (!empty($subj['is_back_subject']) || in_array($subj['course_code'], $backSubjectCodes, true)): ?>
                            <span class="badge bg-danger mb-1">Back Subject</span>
                        <?php endif; ?>
                    </div>
                    <span class="badge-status <?= $subj['is_active'] ? 'active' : 'inactive' ?>"><?= $subj['is_active'] ? 'Active' : 'Archived' ?></span>
                </div>
                <h6 class="fw-bold mb-1"><?= e($subj['subject_name']) ?></h6>
                <small class="text-muted mb-2">
                    <?= e($subj['units']) ?> units &bull; Section <?= e($subj['sec_year'] . $subj['section_name']) ?> &bull; Prereq: <?= e($subj['prerequisite']) ?>
                </small>
                <?php if ($subj['description']): ?>
                <p class="text-muted mb-2" style="font-size:0.82rem;"><?= e(substr($subj['description'], 0, 80)) ?><?= strlen($subj['description']) > 80 ? '...' : '' ?></p>
                <?php endif; ?>

                <div class="mt-auto">
                    <div class="d-flex align-items-center justify-content-between mb-2 p-2" style="background:var(--gray-50);border-radius:8px;">
                        <div>
                            <span style="font-size:0.75rem;color:var(--gray-500);">CLASS CODE</span>
                            <div class="fw-bold" style="font-size:1.1rem;letter-spacing:2px;color:var(--primary);"><?= e($subj['class_code']) ?></div>
                        </div>
                        <button class="btn btn-sm btn-outline-primary" onclick="navigator.clipboard.writeText('<?= e($subj['class_code']) ?>').then(()=>showToast('Class code copied!','success'))">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                    <div class="d-flex gap-2 mb-2" style="font-size:0.82rem;">
                        <span><i class="fas fa-users me-1 text-primary"></i><?= $subj['student_count'] ?> enrolled</span>
                        <?php if ($subj['pending_count'] > 0): ?>
                        <span class="text-warning"><i class="fas fa-clock me-1"></i><?= $subj['pending_count'] ?> pending</span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?= BASE_URL ?>/lessons.php?class_id=<?= $subj['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-book-open me-1"></i>Lessons</a>
                        <?php if ($subj['pending_count'] > 0): ?>
                        <a href="<?= BASE_URL ?>/join-class.php?class_id=<?= $subj['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-bell me-1"></i>Requests</a>
                        <?php endif; ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary"
                            data-bs-toggle="modal"
                            data-bs-target="#createSubjectModal"
                            data-action="update_subject"
                            data-subject-id="<?= $subj['id'] ?>"
                            data-course-code="<?= e($subj['course_code']) ?>"
                            data-subject-name="<?= e($subj['subject_name']) ?>"
                            data-description="<?= e($subj['description']) ?>"
                            data-units="<?= e($subj['units']) ?>"
                            data-prerequisite="<?= e($subj['prerequisite']) ?>"
                            data-semester="<?= e($subj['semester']) ?>"
                            data-section-id="<?= $subj['section_id'] ?>"
                            data-year="<?= e($subj['sec_year']) ?>"
                            data-subject-type="<?= ($subj['is_back_subject'] ?? 0) ? 'back' : 'regular' ?>"
                        ><i class="fas fa-pen me-1"></i>Edit</button>
                        <form method="POST" class="d-inline" onsubmit="return confirmForm(this, 'Delete this subject? This will remove all associated class data.', 'Delete')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_subject">
                            <input type="hidden" name="subject_id" value="<?= $subj['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash me-1"></i>Delete</button>
                        </form>
                        <?php if ($subj['is_active']): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirmForm(this, 'Archive this subject? Students will no longer see it.', 'Archive')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="archive_subject">
                            <input type="hidden" name="subject_id" value="<?= $subj['id'] ?>">
                            <button class="btn btn-sm btn-outline-warning"><i class="fas fa-archive me-1"></i>Archive</button>
                        </form>
                        <?php else: ?>
                        <form method="POST" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="restore_subject">
                            <input type="hidden" name="subject_id" value="<?= $subj['id'] ?>">
                            <button class="btn btn-sm btn-outline-success"><i class="fas fa-undo me-1"></i>Restore</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-undo-alt me-2"></i>Back Subjects (Curriculum)</span>
        <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            data-bs-toggle="modal"
            data-bs-target="#backSubjectModal"
            data-action="add_back_subject"
        >
            <i class="fas fa-plus me-1"></i>Add Back Subject
        </button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th style="width:120px;">Code</th><th>Subject</th><th style="width:80px;">Units</th><th>Prerequisite</th><th style="width:150px;">Actions</th></tr></thead>
                <tbody>
                <?php if (empty($backSubjects)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">No back subjects added yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($backSubjects as $subjectIndex => $back): ?>
                        <tr>
                            <td><span class="badge bg-danger">Back</span> <?= e($back['code']) ?></td>
                            <td><?= e($back['subject']) ?></td>
                            <td class="text-center"><?= e(is_array($back['units']) ? implode('/', $back['units']) : $back['units']) ?></td>
                            <td class="text-muted"><?= e($back['prerequisite'] ?? 'None') ?></td>
                            <td>
                                <div class="d-flex gap-1 flex-wrap">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#backSubjectModal"
                                        data-action="update_back_subject"
                                        data-subject-index="<?= $subjectIndex ?>"
                                        data-code="<?= e($back['code']) ?>"
                                        data-subject="<?= e($back['subject']) ?>"
                                        data-units="<?= e(is_array($back['units']) ? implode('/', $back['units']) : $back['units']) ?>"
                                        data-prerequisite="<?= e($back['prerequisite'] ?? 'None') ?>"
                                    ><i class="fas fa-pen me-1"></i>Edit</button>
                                    <form method="POST" class="d-inline" onsubmit="return confirmForm(this, 'Delete <?= e($back['code']) ?> from back subjects?', 'Delete')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_back_subject">
                                        <input type="hidden" name="subject_index" value="<?= $subjectIndex ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash me-1"></i>Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="backSubjectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="backSubjectForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="backSubjectAction" value="add_back_subject">
                <input type="hidden" name="subject_index" id="backSubjectIndex" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="backSubjectTitle">Add Back Subject</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Choose from Curriculum (optional)</label>
                        <select name="back_curriculum_code" id="backCurriculumSelect" class="form-select">
                            <option value="">-- Select a curriculum subject --</option>
                            <?php
                            $yearMap = [1 => 'First Year', 2 => 'Second Year', 3 => 'Third Year', 4 => 'Fourth Year'];
                            foreach ($yearMap as $yr => $yrLabel):
                                $yrSubjects = getCurriculumSubjects($yr);
                                if (empty($yrSubjects)) continue;
                            ?>
                            <optgroup label="<?= e($yrLabel) ?>">
                                <?php foreach ($yrSubjects as $csub): ?>
                                <option value="<?= e($csub['code']) ?>"
                                        data-name="<?= e($csub['subject']) ?>"
                                        data-units="<?= e(is_array($csub['units']) ? implode('/', $csub['units']) : $csub['units']) ?>"
                                        data-prerequisite="<?= e($csub['prerequisite'] ?? 'None') ?>">
                                    <?= e($csub['code'] . ' - ' . $csub['subject'] . ' (' . $csub['semester'] . ')') ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Selecting a curriculum subject fills code, subject name, units and prerequisite automatically.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Code</label>
                        <input type="text" name="back_code" id="backSubjectCode" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subject</label>
                        <input type="text" name="back_subject" id="backSubjectName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Units</label>
                        <input type="text" name="back_units" id="backSubjectUnits" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Prerequisite</label>
                        <input type="text" name="back_prerequisite" id="backSubjectPrerequisite" class="form-control" placeholder="None">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-gradient" id="backSubjectSubmitBtn">Add Back Subject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="createSubjectModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="createSubjectForm" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="subjectFormAction" value="create_subject">
                <input type="hidden" name="subject_id" id="subjectFormId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Create New Subject</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info" style="font-size:0.85rem;">
                        <i class="fas fa-info-circle me-1"></i>
                        Select a subject from the <strong>BSIT curriculum</strong>. Only subjects offered in the program can be created.
                        A unique class code will be generated automatically.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Subject Type <span class="text-danger">*</span></label>
                        <select name="subject_type" id="subjectTypeSelect" class="form-select" required>
                            <option value="regular">Regular subject (year-level restricted)</option>
                            <option value="back">Back subject (cross-section, no year-level restriction)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Subject from Curriculum <span class="text-danger">*</span></label>
                        <select id="curriculumSelect" class="form-select" required>
                            <option value="">-- Select a subject from the BSIT curriculum --</option>
                            <?php
                            $yearMap = [1 => 'First Year', 2 => 'Second Year', 3 => 'Third Year', 4 => 'Fourth Year'];
                            foreach ($yearMap as $yr => $yrLabel):
                                $yrSubjects = getCurriculumSubjects($yr);
                                if (empty($yrSubjects)) continue;
                            ?>
                            <optgroup label="<?= $yrLabel ?>">
                                <?php foreach ($yrSubjects as $csub): ?>
                                <option value="<?= e($csub['code']) ?>"
                                    data-name="<?= e($csub['subject']) ?>"
                                    data-units="<?= e(is_array($csub['units']) ? implode('/', $csub['units']) : $csub['units']) ?>"
                                    data-prereq="<?= e($csub['prerequisite']) ?>"
                                    data-semester="<?= e($csub['semester']) ?>"
                                    data-year="<?= $csub['year_level'] ?>">
                                    <?= e($csub['code'] . ' - ' . $csub['subject'] . ' (' . $csub['semester'] . ')') ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Subject Name</label>
                            <input type="text" name="subject_name" id="subjectName" class="form-control" readonly style="background:#f8f9fa;cursor:not-allowed;" placeholder="Auto-filled from curriculum">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Course Code</label>
                            <input type="text" name="course_code" id="courseCode" class="form-control" readonly style="background:#f8f9fa;cursor:not-allowed;" placeholder="Auto-filled">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Prerequisite</label>
                            <input type="text" name="prerequisite" id="prerequisite" class="form-control" readonly style="background:#f8f9fa;cursor:not-allowed;" value="None">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Units</label>
                            <input type="text" name="units" id="units" class="form-control" readonly style="background:#f8f9fa;cursor:not-allowed;" placeholder="Auto-filled">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description (optional)</label>
                            <textarea id="description" name="description" class="form-control" rows="2" placeholder="Brief course description..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Subject Cover / Icon (optional)</label>
                            <input type="file" name="subject_image" id="subjectImageInput" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                            <div class="form-text">Upload a JPG, PNG, GIF, or WebP image up to 5MB.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Semester <span class="text-danger">*</span></label>
                            <select name="semester" id="semesterSelect" class="form-select" required>
                                <option value="">Select...</option>
                                <?php foreach (SEMESTERS as $sem): ?>
                                <option value="<?= e($sem) ?>"><?= e($sem) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Class/Section <span class="text-danger">*</span></label>
                            <select name="section_id" id="sectionSelect" class="form-select" required>
                                <option value="">Select...</option>
                                <?php foreach ($allSections as $sec): ?>
                                <option value="<?= $sec['id'] ?>" data-year="<?= $sec['year_level'] ?>">
                                    <?= e($sec['year_level'] . $sec['section_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Instructor</label>
                            <input type="text" class="form-control" value="<?= e($user['first_name'] . ' ' . $user['last_name']) ?>" disabled>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-gradient"><i class="fas fa-check me-1"></i>Create Subject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function setSectionVisibilityBySubject(optionYear) {
    const sectionSelect = document.getElementById('sectionSelect');
    const isBack = document.getElementById('subjectTypeSelect')?.value === 'back';

    Array.from(sectionSelect.options).forEach(o => {
        if (!o.value) {
            o.style.display = '';
            return;
        }
        if (isBack) {
            o.style.display = '';
            return;
        }
        o.style.display = (o.dataset.year === optionYear) ? '' : 'none';
        if (o.dataset.year !== optionYear && o.selected) o.selected = false;
    });
}

function setBackSubjectFromCurriculum(opt) {
    if (!opt || !opt.value) {
        document.getElementById('backSubjectCode').value = '';
        document.getElementById('backSubjectName').value = '';
        document.getElementById('backSubjectUnits').value = '';
        document.getElementById('backSubjectPrerequisite').value = 'None';
        return;
    }
    document.getElementById('backSubjectCode').value = opt.value;
    document.getElementById('backSubjectName').value = opt.dataset.name || '';
    document.getElementById('backSubjectUnits').value = opt.dataset.units || '';
    document.getElementById('backSubjectPrerequisite').value = opt.dataset.prerequisite || 'None';
}

function setCurriculumSelection(opt) {
    if (!opt || !opt.value) {
        document.getElementById('courseCode').value = '';
        document.getElementById('subjectName').value = '';
        document.getElementById('units').value = '';
        document.getElementById('prerequisite').value = 'None';
        document.getElementById('semesterSelect').selectedIndex = 0;
        setSectionVisibilityBySubject('');
        return;
    }

    document.getElementById('courseCode').value = opt.value;
    document.getElementById('subjectName').value = opt.dataset.name || '';
    document.getElementById('units').value = opt.dataset.units || '';
    document.getElementById('prerequisite').value = opt.dataset.prereq || 'None';

    const semSelect = document.getElementById('semesterSelect');
    Array.from(semSelect.options).forEach(o => {
        o.selected = (o.value === opt.dataset.semester);
    });

    setSectionVisibilityBySubject(opt.dataset.year || '');
}

document.getElementById('subjectTypeSelect')?.addEventListener('change', function() {
    const curriculumOpt = document.getElementById('curriculumSelect').selectedOptions[0];
    setCurriculumSelection(curriculumOpt);
});

const curriculumSelectElement = document.getElementById('curriculumSelect');
curriculumSelectElement?.addEventListener('change', function() {
    setCurriculumSelection(this.selectedOptions[0]);
});

const backCurriculumSelectElement = document.getElementById('backCurriculumSelect');
backCurriculumSelectElement?.addEventListener('change', function() {
    setBackSubjectFromCurriculum(this.selectedOptions[0]);
});

// Subject Modal: create/update handling
const subjectModal = document.getElementById('createSubjectModal');
if (subjectModal) {
    subjectModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const action = button?.dataset?.action || 'create_subject';
        const isEdit = action === 'update_subject';

        document.getElementById('subjectFormAction').value = action;
        document.getElementById('subjectFormId').value = button?.dataset?.subjectId || '0';
        document.getElementById('subjectTypeSelect').value = button?.dataset?.subjectType || 'regular';

        if (isEdit) {
            document.getElementById('createSubjectModal').querySelector('.modal-title').innerHTML = '<i class="fas fa-pen me-2"></i>Edit Subject';
            document.querySelector('#createSubjectModal .btn-primary-gradient').innerHTML = '<i class="fas fa-check me-1"></i>Update Subject';
            document.getElementById('courseCode').value = button.dataset.courseCode || '';
            document.getElementById('subjectName').value = button.dataset.subjectName || '';
            document.getElementById('description').value = button.dataset.description || '';
            document.getElementById('units').value = button.dataset.units || '';
            document.getElementById('prerequisite').value = button.dataset.prerequisite || 'None';
            document.getElementById('prerequisite').value = button.dataset.prerequisite || 'None';
            document.getElementById('semesterSelect').value = button.dataset.semester || '';
            document.getElementById('sectionSelect').value = button.dataset.sectionId || '';
            setSectionVisibilityBySubject(button.dataset.year || '');
        } else {
            document.getElementById('createSubjectModal').querySelector('.modal-title').innerHTML = '<i class="fas fa-plus-circle me-2"></i>Create New Subject';
            document.querySelector('#createSubjectModal .btn-primary-gradient').innerHTML = '<i class="fas fa-check me-1"></i>Create Subject';
            document.getElementById('createSubjectForm').reset();
            document.getElementById('subjectFormId').value = '0';
            document.getElementById('semesterSelect').selectedIndex = 0;
            setSectionVisibilityBySubject('');
            const backCurriculum = document.getElementById('backCurriculumSelect');
            if (backCurriculum) backCurriculum.value = '';
        }
    });
}

// Back subject modal
const backModal = document.getElementById('backSubjectModal');
if (backModal) {
    backModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const action = button?.dataset?.action || 'add_back_subject';
        const isEdit = action === 'update_back_subject';

        document.getElementById('backSubjectAction').value = action;
        document.getElementById('backSubjectIndex').value = button?.dataset?.subjectIndex || '';
        document.getElementById('backSubjectTitle').textContent = isEdit ? 'Edit Back Subject' : 'Add Back Subject';
        document.getElementById('backSubjectSubmitBtn').textContent = isEdit ? 'Update Back Subject' : 'Add Back Subject';

        const backSubjectCode = button?.dataset?.code || '';
        document.getElementById('backSubjectCode').value = backSubjectCode;
        document.getElementById('backSubjectName').value = button?.dataset?.subject || '';
        document.getElementById('backSubjectUnits').value = button?.dataset?.units || '';
        document.getElementById('backSubjectPrerequisite').value = button?.dataset?.prerequisite || 'None';

        const backCurriculum = document.getElementById('backCurriculumSelect');
        if (backCurriculum) {
            if (backCurriculum.querySelector(`option[value="${backSubjectCode}"]`)) {
                backCurriculum.value = backSubjectCode;
            } else {
                backCurriculum.value = '';
            }
        }
    });
}

document.getElementById('createSubjectForm')?.addEventListener('submit', function(e) {
    const currSel = document.getElementById('curriculumSelect');
    if (!currSel || !currSel.value) {
        e.preventDefault();
        if (typeof showToast === 'function') showToast('Please select a subject from the BSIT curriculum.', 'error');
        return false;
    }
});
</script>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
