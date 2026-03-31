<?php
$pageTitle = 'Program - BSIT';
require_once __DIR__ . '/helpers/functions.php';
requireRole('superadmin', 'staff');
$pdo = getDB();
$breadcrumbPills = ['Academic'];
$yearMap = [1 => 'First_Year', 2 => 'Second_Year', 3 => 'Third_Year', 4 => 'Fourth_Year'];
$semMap = ['First_Semester' => 'First Semester', 'Second_Semester' => 'Second Semester', 'Mid_Year' => 'Mid-Year'];

$normalizeCurriculumUnits = function($units) {
    $units = trim((string)$units);
    if ($units === '') {
        return '';
    }
    if (ctype_digit($units)) {
        return (int)$units;
    }
    if (is_numeric($units)) {
        return $units + 0;
    }
    return $units;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Invalid token.');
        redirect('/programs.php');
    }

    $action = $_POST['action'] ?? '';
    $yearKey = trim($_POST['year_key'] ?? '');
    $semKey = trim($_POST['semester_key'] ?? '');
    $subjectIndex = isset($_POST['subject_index']) ? (int)$_POST['subject_index'] : -1;
    $allowedYearKeys = array_values($yearMap);
    $allowedSemKeys = array_keys($semMap);

    if (!in_array($yearKey, $allowedYearKeys, true) || !in_array($semKey, $allowedSemKeys, true)) {
        flash('error', 'Invalid curriculum location.');
        redirect('/programs.php');
    }

    $curriculum = loadCurriculum(true);
    if (!isset($curriculum[$yearKey])) {
        $curriculum[$yearKey] = [];
    }
    if (!isset($curriculum[$yearKey][$semKey]) || !is_array($curriculum[$yearKey][$semKey])) {
        $curriculum[$yearKey][$semKey] = [];
    }

    if ($action === 'add_subject' || $action === 'update_subject') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $subjectName = trim($_POST['subject'] ?? '');
        $units = $normalizeCurriculumUnits($_POST['units'] ?? '');
        $prerequisite = trim($_POST['prerequisite'] ?? '');

        if ($code === '' || $subjectName === '' || $units === '') {
            flash('error', 'Code, subject, and units are required.');
            redirect('/programs.php');
        }

        $subjectData = [
            'code' => $code,
            'subject' => $subjectName,
            'units' => $units,
            'prerequisite' => $prerequisite !== '' ? $prerequisite : 'None',
        ];

        if ($action === 'add_subject') {
            $curriculum[$yearKey][$semKey][] = $subjectData;
            $message = 'Curriculum subject added.';
            $logAction = 'curriculum_subject_added';
        } else {
            if (!isset($curriculum[$yearKey][$semKey][$subjectIndex])) {
                flash('error', 'Subject not found.');
                redirect('/programs.php');
            }
            $curriculum[$yearKey][$semKey][$subjectIndex] = $subjectData;
            $message = 'Curriculum subject updated.';
            $logAction = 'curriculum_subject_updated';
        }

        if (!saveCurriculum($curriculum)) {
            flash('error', 'Unable to save curriculum changes.');
            redirect('/programs.php');
        }

        auditLog($logAction, "$code in $yearKey / $semKey");
        flash('success', $message);
        redirect('/programs.php');
    }

    if ($action === 'delete_subject') {
        if (!isset($curriculum[$yearKey][$semKey][$subjectIndex])) {
            flash('error', 'Subject not found.');
            redirect('/programs.php');
        }

        $deletedCode = $curriculum[$yearKey][$semKey][$subjectIndex]['code'] ?? 'Unknown';
        array_splice($curriculum[$yearKey][$semKey], $subjectIndex, 1);

        if (!saveCurriculum($curriculum)) {
            flash('error', 'Unable to save curriculum changes.');
            redirect('/programs.php');
        }

        auditLog('curriculum_subject_deleted', "$deletedCode removed from $yearKey / $semKey");
        flash('success', 'Curriculum subject deleted.');
        redirect('/programs.php');
    }

    flash('error', 'Unsupported curriculum action.');
    redirect('/programs.php');
}

$totalSections = $pdo->query("SELECT COUNT(*) FROM sections WHERE program_code = 'BSIT' AND is_active = 1")->fetchColumn();
$totalStudents = $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND program_code = 'BSIT' AND is_active = 1")->fetchColumn();
$totalInstructors = $pdo->query("SELECT COUNT(DISTINCT instructor_id) FROM instructor_classes WHERE program_code = 'BSIT' AND is_active = 1")->fetchColumn();
$totalClasses = $pdo->query("SELECT COUNT(*) FROM instructor_classes WHERE program_code = 'BSIT' AND is_active = 1")->fetchColumn();

$curriculum = loadCurriculum(true);

require_once __DIR__ . '/views/layouts/header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-primary-soft"><i class="fas fa-graduation-cap"></i></div>
            <div class="stat-info"><div class="stat-value">BSIT</div><div class="stat-label">Program</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-success-soft"><i class="fas fa-user-graduate"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $totalStudents ?></div><div class="stat-label">Students</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-info-soft"><i class="fas fa-chalkboard-instructor"></i></div>
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

<div class="card mb-4">
    <div class="card-header">
        <span><i class="fas fa-university me-2"></i>Bachelor of Science in Information Technology</span>
    </div>
    <div class="card-body">
        <div class="row g-2 text-center mb-3 program-summary-grid">
            <div class="col-6 col-md-3">
                <div style="background:var(--gray-50);border-radius:8px;padding:12px;">
                    <div class="fw-bold text-primary"><?= $totalClasses ?></div>
                    <div style="font-size:0.75rem;color:var(--gray-500);">Active Classes</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div style="background:var(--gray-50);border-radius:8px;padding:12px;">
                    <div class="fw-bold text-success"><?= $totalStudents ?></div>
                    <div style="font-size:0.75rem;color:var(--gray-500);">Enrolled Students</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div style="background:var(--gray-50);border-radius:8px;padding:12px;">
                    <div class="fw-bold text-info"><?= $totalInstructors ?></div>
                    <div style="font-size:0.75rem;color:var(--gray-500);">Active Instructors</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div style="background:var(--gray-50);border-radius:8px;padding:12px;">
                    <div class="fw-bold text-warning"><?= $totalSections ?></div>
                    <div style="font-size:0.75rem;color:var(--gray-500);">Sections (1A-4C)</div>
                </div>
            </div>
        </div>
        <a href="<?= BASE_URL ?>/sections.php" class="btn btn-outline-primary btn-sm btn-md-lg program-summary-action"><i class="fas fa-layer-group me-1"></i>Manage Sections</a>
    </div>
</div>

<h5 class="mb-3"><i class="fas fa-book me-2 text-primary"></i>BSIT Curriculum (from course.json)</h5>

<?php foreach ($yearMap as $yr => $yrKey):
    if (!isset($curriculum[$yrKey])) continue;
?>
<div class="card mb-3">
    <div class="card-header"><span><i class="fas fa-calendar-alt me-2"></i><?= e(YEAR_LEVELS[$yr]) ?></span></div>
    <div class="card-body p-0">
        <?php foreach ($curriculum[$yrKey] as $semKey => $subjects):
            $semLabel = $semMap[$semKey] ?? $semKey;
        ?>
        <div class="px-3 py-2 d-flex justify-content-between align-items-center gap-2 program-semester-bar" style="background:var(--gray-50);border-bottom:1px solid var(--gray-100);">
            <strong style="font-size:0.85rem;"><?= e($semLabel) ?></strong>
            <button
                type="button"
                class="btn btn-sm btn-outline-primary btn-sm btn-md-lg curriculum-modal-btn program-semester-action"
                data-bs-toggle="modal"
                data-bs-target="#curriculumModal"
                data-action="add_subject"
                data-year-key="<?= e($yrKey) ?>"
                data-semester-key="<?= e($semKey) ?>"
                data-semester-label="<?= e($semLabel) ?>"
                data-year-label="<?= e(YEAR_LEVELS[$yr]) ?>"
            >
                <i class="fas fa-plus me-1"></i>Add Subject
            </button>
        </div>
        <div class="table-responsive program-curriculum-wrap">
            <table class="table table-sm mb-0 program-curriculum-table">
                <thead><tr><th style="width:100px;">Code</th><th>Subject</th><th style="width:80px;">Units</th><th>Prerequisite</th><th style="width:150px;">Actions</th></tr></thead>
                <tbody>
                <?php if (empty($subjects)): ?>
                <tr class="program-curriculum-empty-row">
                    <td colspan="5" class="text-center text-muted py-3">No subjects added for this semester yet.</td>
                </tr>
                <?php endif; ?>
                <?php foreach ($subjects as $subjectIndex => $subj): ?>
                <tr>
                    <td data-label="Code"><span class="badge bg-primary"><?= e($subj['code']) ?></span></td>
                    <td style="font-size:0.85rem;" data-label="Subject"><?= e($subj['subject']) ?></td>
                    <td class="text-center" data-label="Units"><?= e(is_array($subj['units']) ? implode('/', $subj['units']) : $subj['units']) ?></td>
                    <td class="text-muted" style="font-size:0.82rem;" data-label="Prerequisite"><?= e($subj['prerequisite']) ?></td>
                    <td class="program-curriculum-actions" data-label="Actions">
                        <div class="d-flex gap-1 flex-wrap">
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary curriculum-modal-btn"
                                data-bs-toggle="modal"
                                data-bs-target="#curriculumModal"
                                data-action="update_subject"
                                data-subject-index="<?= $subjectIndex ?>"
                                data-year-key="<?= e($yrKey) ?>"
                                data-semester-key="<?= e($semKey) ?>"
                                data-semester-label="<?= e($semLabel) ?>"
                                data-year-label="<?= e(YEAR_LEVELS[$yr]) ?>"
                                data-code="<?= e($subj['code']) ?>"
                                data-subject="<?= e($subj['subject']) ?>"
                                data-units="<?= e(is_array($subj['units']) ? implode('/', $subj['units']) : $subj['units']) ?>"
                                data-prerequisite="<?= e($subj['prerequisite']) ?>"
                            >
                                <i class="fas fa-pen me-1"></i>Edit
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirmForm(this, 'Delete <?= e($subj['code']) ?> from the curriculum?', 'Delete')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_subject">
                                <input type="hidden" name="year_key" value="<?= e($yrKey) ?>">
                                <input type="hidden" name="semester_key" value="<?= e($semKey) ?>">
                                <input type="hidden" name="subject_index" value="<?= $subjectIndex ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash me-1"></i>Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<div class="modal fade" id="curriculumModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="curriculumAction" value="add_subject">
                <input type="hidden" name="year_key" id="curriculumYearKey" value="">
                <input type="hidden" name="semester_key" id="curriculumSemesterKey" value="">
                <input type="hidden" name="subject_index" id="curriculumSubjectIndex" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="curriculumModalTitle">Add Curriculum Subject</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border mb-3" style="font-size:0.82rem;">
                        <strong id="curriculumModalContext">Curriculum entry</strong>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Code</label>
                            <input type="text" name="code" id="curriculumCode" class="form-control" maxlength="50" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Subject</label>
                            <input type="text" name="subject" id="curriculumSubject" class="form-control" maxlength="255" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Units</label>
                            <input type="text" name="units" id="curriculumUnits" class="form-control" maxlength="20" placeholder="3 or 2/1" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Prerequisite</label>
                            <input type="text" name="prerequisite" id="curriculumPrerequisite" class="form-control" maxlength="255" placeholder="None">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm btn-md-lg" id="curriculumSubmitBtn">Save Subject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('curriculumModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const action = button.dataset.action || 'add_subject';
    const isEdit = action === 'update_subject';

    document.getElementById('curriculumAction').value = action;
    document.getElementById('curriculumYearKey').value = button.dataset.yearKey || '';
    document.getElementById('curriculumSemesterKey').value = button.dataset.semesterKey || '';
    document.getElementById('curriculumSubjectIndex').value = button.dataset.subjectIndex || '';
    document.getElementById('curriculumCode').value = button.dataset.code || '';
    document.getElementById('curriculumSubject').value = button.dataset.subject || '';
    document.getElementById('curriculumUnits').value = button.dataset.units || '';
    document.getElementById('curriculumPrerequisite').value = button.dataset.prerequisite || 'None';
    document.getElementById('curriculumModalTitle').textContent = isEdit ? 'Edit Curriculum Subject' : 'Add Curriculum Subject';
    document.getElementById('curriculumSubmitBtn').textContent = isEdit ? 'Update Subject' : 'Add Subject';
    document.getElementById('curriculumModalContext').textContent = `${button.dataset.yearLabel || ''} • ${button.dataset.semesterLabel || ''}`;
});
</script>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
