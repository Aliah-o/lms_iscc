<?php
$pageTitle = 'Assignments';
require_once __DIR__ . '/helpers/functions.php';
requireRole('staff');
$pdo = getDB();
$breadcrumbPills = ['Staff', 'Assignments'];
$studentSort = $_GET['student_sort'] ?? 'az';
$studentSort = in_array($studentSort, ['az', 'za'], true) ? $studentSort : 'az';
$studentSortDirection = $studentSort === 'za' ? 'DESC' : 'ASC';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect('/assignments.php'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'assign_student') {
        $studentId = intval($_POST['student_id'] ?? 0);
        $yr = intval($_POST['year_level'] ?? 0);
        $secId = intval($_POST['section_id'] ?? 0);
        $semester = trim($_POST['semester'] ?? '');

        if ($studentId && isset(YEAR_LEVELS[$yr]) && $secId) {
            $pdo->prepare("UPDATE users SET program_code = 'BSIT', year_level = ?, section_id = ?, semester = ? WHERE id = ? AND role = 'student'")
                ->execute([$yr, $secId, $semester, $studentId]);
            $pdo->prepare("DELETE FROM student_assignments WHERE student_id = ?")->execute([$studentId]);
            $pdo->prepare("INSERT INTO student_assignments (student_id, program_code, year_level, section_id, semester) VALUES ('BSIT', ?, ?, ?, ?)")
                ->execute([$studentId, $yr, $secId, $semester]);
            auditLog('student_assigned', "Student #$studentId assigned to BSIT Year $yr Section #$secId ($semester)");
            flash('success', 'Student assigned successfully.');
        }
    } elseif ($action === 'assign_instructor') {
        $instructorId = intval($_POST['instructor_id'] ?? 0);
        $yr = intval($_POST['year_level'] ?? 0);
        $secId = intval($_POST['section_id'] ?? 0);
        $courseCode = trim($_POST['course_code'] ?? '');
        $semester = trim($_POST['semester'] ?? '');
        $subject = '';
        $units = 0;
        $prerequisite = '';
        $description = '';

        if ($courseCode) {
            $subjectInfo = getSubjectByCode($courseCode);
            if ($subjectInfo) {
                $subject = $subjectInfo['subject'];
                $units = is_array($subjectInfo['units']) ? array_sum($subjectInfo['units']) : intval($subjectInfo['units']);
                $prerequisite = $subjectInfo['prerequisite'] ?? '';
                $description = $subjectInfo['subject'];
            }
        }
        if (empty($subject)) $subject = trim($_POST['subject_name'] ?? 'General');

        if ($instructorId && isset(YEAR_LEVELS[$yr]) && $secId) {
            $check = $pdo->prepare("SELECT id FROM instructor_classes WHERE instructor_id = ? AND course_code = ? AND section_id = ? AND semester = ?");
            $check->execute([$instructorId, $courseCode, $secId, $semester]);
            if ($check->fetch()) {
                flash('error', 'Instructor already assigned to this subject/section.');
            } else {
                $classCode = generateClassCode();
                $pdo->prepare("INSERT INTO instructor_classes (instructor_id, program_code, year_level, section_id, subject_name, course_code, description, units, prerequisite, semester, class_code) VALUES (?, 'BSIT', ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$instructorId, $yr, $secId, $subject, $courseCode, $description, $units, $prerequisite, $semester, $classCode]);
                auditLog('instructor_assigned', "Instructor #$instructorId assigned to $courseCode ($subject) BSIT Year $yr Section #$secId");
                flash('success', 'Instructor assigned successfully. Class code: ' . $classCode);
            }
        }
    } elseif ($action === 'enroll_student') {
        $classId = intval($_POST['class_id'] ?? 0);
        $studentId = intval($_POST['student_id'] ?? 0);
        if ($classId && $studentId) {
            $pdo->prepare("INSERT IGNORE INTO class_enrollments (class_id, student_id) VALUES (?, ?)")
                ->execute([$classId, $studentId]);
            auditLog('student_enrolled', "Student #$studentId enrolled in class #$classId");
            flash('success', 'Student enrolled in class.');
        }
    }
    redirect('/assignments.php');
}

$students = $pdo->query("SELECT * FROM users WHERE role='student' AND is_active=1 ORDER BY last_name $studentSortDirection, first_name $studentSortDirection")->fetchAll();
$instructors = $pdo->query("SELECT * FROM users WHERE role='instructor' AND is_active=1 ORDER BY last_name, first_name")->fetchAll();
$allSections = $pdo->query("SELECT * FROM sections WHERE is_active=1 AND program_code='BSIT' ORDER BY year_level, section_name")->fetchAll();
$classes = $pdo->query("SELECT tc.*, u.first_name, u.last_name, s.section_name, tc.course_code, tc.class_code, tc.semester FROM instructor_classes tc JOIN users u ON tc.instructor_id = u.id JOIN sections s ON tc.section_id = s.id WHERE tc.is_active=1 ORDER BY tc.year_level, tc.course_code")->fetchAll();

require_once __DIR__ . '/views/layouts/header.php';
?>

<ul class="nav nav-pills mb-4 gap-2" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="pill" href="#tabStudents"><i class="fas fa-user-graduate me-1"></i>Student Assignments</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#tabInstructors"><i class="fas fa-chalkboard-instructor me-1"></i>Instructor Loads</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#tabEnroll"><i class="fas fa-user-check me-1"></i>Enroll Students</a></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tabStudents">
        <div class="d-flex justify-content-end mb-3">
            <form method="GET" class="d-flex align-items-center gap-2">
                <label class="form-label mb-0" for="studentSort" style="font-size:0.82rem;">Student Sort</label>
                <select name="student_sort" id="studentSort" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:160px;">
                    <option value="az" <?= $studentSort === 'az' ? 'selected' : '' ?>>Alphabetical A-Z</option>
                    <option value="za" <?= $studentSort === 'za' ? 'selected' : '' ?>>Alphabetical Z-A</option>
                </select>
            </form>
        </div>
        <div class="card mb-3">
            <div class="card-header"><span>Assign Student to BSIT Year/Section</span></div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="assign_student">
                    <div class="col-md-3">
                        <label class="form-label">Student</label>
                        <select name="student_id" class="form-select" required>
                            <option value="">Select student...</option>
                            <?php foreach ($students as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['last_name'] . ', ' . $s['first_name']) ?> (<?= e($s['student_id_no'] ?: $s['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Year</label>
                        <select name="year_level" class="form-select" required id="saYear">
                            <option value="">Select...</option>
                            <?php foreach (YEAR_LEVELS as $v => $l): ?>
                            <option value="<?= $v ?>"><?= e($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Section</label>
                        <select name="section_id" class="form-select" required id="saSection">
                            <option value="">Select...</option>
                            <?php foreach ($allSections as $sec): ?>
                            <option value="<?= $sec['id'] ?>" data-yr="<?= $sec['year_level'] ?>"><?= e(getSectionDisplay($sec['year_level'], $sec['section_name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Semester</label>
                        <select name="semester" class="form-select" required>
                            <option value="">Select...</option>
                            <?php foreach (SEMESTERS as $sem): ?>
                            <option value="<?= e($sem) ?>"><?= e($sem) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary-gradient w-100"><i class="fas fa-check me-1"></i>Assign</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><span>Current Student Assignments</span></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern mb-0">
                        <thead><tr><th>Student</th><th>Student ID</th><th>Program</th><th>Section</th><th>Semester</th></tr></thead>
                        <tbody>
                        <?php
                        $assigned = $pdo->query("SELECT u.*, s.section_name FROM users u LEFT JOIN sections s ON u.section_id = s.id WHERE u.role='student' AND u.is_active=1 AND u.program_code IS NOT NULL ORDER BY u.last_name $studentSortDirection, u.first_name $studentSortDirection, u.year_level, s.section_name LIMIT 50")->fetchAll();
                        foreach ($assigned as $a): ?>
                        <tr>
                            <td class="fw-bold"><?= e($a['last_name'] . ', ' . $a['first_name']) ?></td>
                            <td><?= !empty($a['student_id_no']) ? '<code>' . e($a['student_id_no']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                            <td><span class="badge bg-primary">BSIT</span></td>
                            <td><span class="badge bg-info"><?= e(getSectionDisplay($a['year_level'], $a['section_name'] ?? '')) ?></span></td>
                            <td><?= e($a['semester'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tabInstructors">
        <div class="card mb-3">
            <div class="card-header"><span>Assign Instructor Load (BSIT Curriculum)</span></div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="assign_instructor">
                    <div class="col-md-2">
                        <label class="form-label">Instructor</label>
                        <select name="instructor_id" class="form-select" required>
                            <option value="">Select...</option>
                            <?php foreach ($instructors as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= e($t['last_name'] . ', ' . $t['first_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Year Level</label>
                        <select name="year_level" class="form-select" required id="tlYear">
                            <option value="">Select...</option>
                            <?php foreach (YEAR_LEVELS as $v => $l): ?>
                            <option value="<?= $v ?>"><?= e($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Semester</label>
                        <select name="semester" class="form-select" required id="tlSemester">
                            <option value="">Select...</option>
                            <?php foreach (SEMESTERS as $sem): ?>
                            <option value="<?= e($sem) ?>"><?= e($sem) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Subject</label>
                        <select name="course_code" class="form-select" required id="tlSubject">
                            <option value="">Select year & semester first...</option>
                        </select>
                        <input type="hidden" name="subject_name" id="tlSubjectName">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Section</label>
                        <select name="section_id" class="form-select" required id="tlSection">
                            <option value="">Select...</option>
                            <?php foreach ($allSections as $sec): ?>
                            <option value="<?= $sec['id'] ?>" data-yr="<?= $sec['year_level'] ?>"><?= e(getSectionDisplay($sec['year_level'], $sec['section_name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary-gradient w-100"><i class="fas fa-check me-1"></i>Assign</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><span>Current Instructor Classes</span></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern mb-0">
                        <thead><tr><th>Instructor</th><th>Code</th><th>Subject</th><th>Section</th><th>Semester</th><th>Class Code</th></tr></thead>
                        <tbody>
                        <?php foreach ($classes as $c): ?>
                        <tr>
                            <td class="fw-bold"><?= e($c['last_name'] . ', ' . $c['first_name']) ?></td>
                            <td><span class="badge bg-primary"><?= e($c['course_code'] ?? '') ?></span></td>
                            <td><?= e($c['subject_name']) ?></td>
                            <td><span class="badge bg-info"><?= e(getSectionDisplay($c['year_level'], $c['section_name'])) ?></span></td>
                            <td style="font-size:0.82rem;"><?= e($c['semester'] ?? '') ?></td>
                            <td><code><?= e($c['class_code'] ?? '') ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tabEnroll">
        <div class="card">
            <div class="card-header"><span>Enroll Student into a Instructor Class</span></div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="enroll_student">
                    <div class="col-md-4">
                        <label class="form-label">Student</label>
                        <select name="student_id" class="form-select" required>
                            <option value="">Select student...</option>
                            <?php foreach ($students as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['last_name'] . ', ' . $s['first_name']) ?> (<?= e($s['student_id_no'] ?: $s['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Class</label>
                        <select name="class_id" class="form-select" required>
                            <option value="">Select class...</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e(($c['course_code'] ?? '') . ' ' . $c['subject_name'] . ' - ' . $c['first_name'] . ' ' . $c['last_name'] . ' (' . getSectionDisplay($c['year_level'], $c['section_name']) . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary-gradient w-100"><i class="fas fa-user-plus me-1"></i>Enroll</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const curriculum = <?= json_encode(loadCurriculum()) ?>;
const yearMap = {1:'First_Year',2:'Second_Year',3:'Third_Year',4:'Fourth_Year'};
const semMap = {'First Semester':'First_Semester','Second Semester':'Second_Semester','Mid-Year':'Mid_Year'};

function updateSubjects() {
    const yr = document.getElementById('tlYear')?.value;
    const sem = document.getElementById('tlSemester')?.value;
    const sel = document.getElementById('tlSubject');
    if (!sel) return;
    sel.innerHTML = '<option value="">Select...</option>';
    if (!yr || !sem) return;
    const yrKey = yearMap[yr];
    const semKey = semMap[sem];
    if (curriculum[yrKey] && curriculum[yrKey][semKey]) {
        curriculum[yrKey][semKey].forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.code;
            opt.textContent = s.code + ' - ' + s.subject;
            sel.appendChild(opt);
        });
    }
}

function filterSections(yearSelect, sectionSelect) {
    const yr = document.getElementById(yearSelect)?.value;
    const sel = document.getElementById(sectionSelect);
    if (!sel) return;
    Array.from(sel.options).forEach(o => {
        if (!o.value) return;
        const show = !yr || o.dataset.yr === yr;
        o.style.display = show ? '' : 'none';
        if (!show && o.selected) o.selected = false;
    });
}

document.getElementById('tlYear')?.addEventListener('change', () => { updateSubjects(); filterSections('tlYear', 'tlSection'); });
document.getElementById('tlSemester')?.addEventListener('change', updateSubjects);
document.getElementById('saYear')?.addEventListener('change', () => filterSections('saYear', 'saSection'));
</script>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
