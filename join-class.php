<?php
$pageTitle = 'Join Class';
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'student');
$pdo = getDB();
$user = currentUser();
$role = $user['role'];

if ($role === 'instructor') {
    $pageTitle = 'Join Requests';
    $breadcrumbPills = ['Teaching', 'Pending Requests'];

    $classId = intval($_GET['class_id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect('/join-class.php' . ($classId ? "?class_id=$classId" : '')); }

        $action = $_POST['action'] ?? '';
        $requestId = intval($_POST['request_id'] ?? 0);

        if (in_array($action, ['approve', 'decline']) && $requestId) {
            $stmt = $pdo->prepare("SELECT cjr.*, tc.instructor_id, tc.course_code, tc.prerequisite, tc.subject_name, tc.class_code, tc.year_level as class_year
                FROM class_join_requests cjr
                JOIN instructor_classes tc ON cjr.class_id = tc.id
                WHERE cjr.id = ? AND tc.instructor_id = ?");
            $stmt->execute([$requestId, $user['id']]);
            $request = $stmt->fetch();

            if ($request) {
                if ($action === 'approve') {
                    $prereq = $request['prerequisite'];
                    if (!hasCompletedPrerequisite($request['student_id'], $prereq)) {
                        flash('error', "Cannot approve: Student has not completed prerequisite ({$prereq}).");
                    } else {
                        $student = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                        $student->execute([$request['student_id']]);
                        $studentInfo = $student->fetch();

                        $pdo->prepare("UPDATE class_join_requests SET status = 'approved', updated_at = NOW() WHERE id = ?")->execute([$requestId]);
                        $pdo->prepare("INSERT IGNORE INTO class_enrollments (class_id, student_id) VALUES (?, ?)")
                            ->execute([$request['class_id'], $request['student_id']]);

                        addNotification($request['student_id'], 'join_approved',
                            "Your request to join \"{$request['subject_name']}\" (Code: {$request['class_code']}) has been approved!",
                            $request['class_id']);

                        auditLog('join_request_approved', "Approved student #{$request['student_id']} for class #{$request['class_id']}");
                        flash('success', 'Student approved and enrolled successfully.');
                    }
                } else {
                    $note = trim($_POST['instructor_note'] ?? '');
                    $pdo->prepare("UPDATE class_join_requests SET status = 'declined', instructor_note = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$note, $requestId]);

                    $declineMsg = "Your request to join \"{$request['subject_name']}\" (Code: {$request['class_code']}) has been declined.";
                    if ($note) $declineMsg .= " Reason: $note";
                    addNotification($request['student_id'], 'join_declined', $declineMsg, $request['class_id']);

                    auditLog('join_request_declined', "Declined student #{$request['student_id']} for class #{$request['class_id']}");
                    flash('success', 'Request declined.');
                }
            }
        }
        redirect('/join-class.php' . ($classId ? "?class_id=$classId" : ''));
    }

    $query = "SELECT cjr.*, tc.subject_name, tc.course_code, tc.class_code, tc.prerequisite, tc.year_level as class_year, tc.semester,
                s.section_name, s.year_level as sec_year,
                u.first_name, u.last_name, u.username, u.year_level as student_year, u.section_id as student_section_id
            FROM class_join_requests cjr
            JOIN instructor_classes tc ON cjr.class_id = tc.id
            JOIN users u ON cjr.student_id = u.id
            JOIN sections s ON tc.section_id = s.id
            WHERE tc.instructor_id = ?";
    $params = [$user['id']];

    if ($classId) {
        $query .= " AND cjr.class_id = ?";
        $params[] = $classId;
    }
    $query .= " ORDER BY FIELD(cjr.status, 'pending', 'approved', 'declined'), cjr.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $requests = $stmt->fetchAll();

    $pending = array_filter($requests, fn($r) => $r['status'] === 'pending');
    $processed = array_filter($requests, fn($r) => $r['status'] !== 'pending');

    require_once __DIR__ . '/views/layouts/header.php';
    ?>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h5 class="mb-0">Join Requests</h5>
            <small class="text-muted">Review and manage student join requests for your subjects.</small>
        </div>
        <a href="<?= BASE_URL ?>/subjects.php" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-1"></i>Back to Subjects</a>
    </div>

    <?php if (count($pending) > 0): ?>
    <div class="card mb-4">
        <div class="card-header bg-warning bg-opacity-10">
            <span><i class="fas fa-clock me-2 text-warning"></i>Pending Requests (<?= count($pending) ?>)</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-modern mb-0">
                    <thead><tr><th>Student</th><th>Subject</th><th>Section</th><th>Semester</th><th>Prerequisite</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($pending as $req):
                        $prereqMet = hasCompletedPrerequisite($req['student_id'], $req['prerequisite']);
                        $yearMatch = (int)$req['student_year'] === (int)$req['class_year'];
                    ?>
                    <tr>
                        <td>
                            <div class="fw-bold"><?= e($req['first_name'] . ' ' . $req['last_name']) ?></div>
                            <small class="text-muted">@<?= e($req['username']) ?> &bull; Year <?= e($req['student_year']) ?></small>
                        </td>
                        <td>
                            <span class="badge bg-primary"><?= e($req['course_code']) ?></span>
                            <div style="font-size:0.82rem;"><?= e($req['subject_name']) ?></div>
                        </td>
                        <td><?= e($req['sec_year'] . $req['section_name']) ?></td>
                        <td><?= e($req['semester']) ?></td>
                        <td>
                            <?php if (strtolower($req['prerequisite']) === 'none'): ?>
                                <span class="badge bg-light text-dark">None</span>
                            <?php elseif ($prereqMet): ?>
                                <span class="badge bg-success"><i class="fas fa-check me-1"></i><?= e($req['prerequisite']) ?></span>
                            <?php else: ?>
                                <span class="badge bg-danger"><i class="fas fa-times me-1"></i><?= e($req['prerequisite']) ?> (Not met)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$yearMatch): ?>
                                <span class="badge bg-danger">Year mismatch</span>
                            <?php elseif (!$prereqMet && strtolower($req['prerequisite']) !== 'none'): ?>
                                <span class="badge bg-warning text-dark">Prereq not met</span>
                            <?php else: ?>
                                <span class="badge bg-info">Ready</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <form method="POST" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                    <button class="btn btn-sm btn-success" title="Approve"><i class="fas fa-check"></i></button>
                                </form>
                                <form method="POST" class="d-inline" onsubmit="return handleDecline(this)">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="decline">
                                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                    <input type="hidden" name="instructor_note" value="">
                                    <button class="btn btn-sm btn-danger" title="Decline"><i class="fas fa-times"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-success mb-4"><i class="fas fa-check-circle me-2"></i>No pending requests.</div>
    <?php endif; ?>

    <?php if (count($processed) > 0): ?>
    <div class="card">
        <div class="card-header"><span><i class="fas fa-history me-2"></i>Processed Requests</span></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-modern mb-0">
                    <thead><tr><th>Student</th><th>Subject</th><th>Status</th><th>Date</th><th>Note</th></tr></thead>
                    <tbody>
                    <?php foreach ($processed as $req): ?>
                    <tr>
                        <td class="fw-bold"><?= e($req['first_name'] . ' ' . $req['last_name']) ?></td>
                        <td><?= e($req['course_code']) ?> - <?= e($req['subject_name']) ?></td>
                        <td>
                            <span class="badge bg-<?= $req['status'] === 'approved' ? 'success' : 'danger' ?>">
                                <?= ucfirst(e($req['status'])) ?>
                            </span>
                        </td>
                        <td style="font-size:0.82rem;"><?= formatDate($req['updated_at']) ?></td>
                        <td style="font-size:0.82rem;"><?= e($req['instructor_note'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    function handleDecline(form) {
        const note = prompt('Reason for declining (optional):');
        if (note === null) return false;
        form.querySelector('[name="instructor_note"]').value = note;
        return true;
    }
    </script>

<?php
} else {
    $pageTitle = 'Join a Class';
    $breadcrumbPills = ['My Learning', 'Join Class'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect('/join-class.php'); }

        $action = $_POST['action'] ?? '';

        if ($action === 'join_class') {
            $classCode = strtoupper(trim($_POST['class_code'] ?? ''));

            if (empty($classCode)) {
                flash('error', 'Please enter a class code.');
            } else {
                $stmt = $pdo->prepare("SELECT tc.*, s.section_name, s.year_level as sec_year, u.first_name as instructor_fn, u.last_name as instructor_ln
                    FROM instructor_classes tc
                    JOIN sections s ON tc.section_id = s.id
                    JOIN users u ON tc.instructor_id = u.id
                    WHERE tc.class_code = ? AND tc.is_active = 1");
                $stmt->execute([$classCode]);
                $class = $stmt->fetch();

                if (!$class) {
                    flash('error', 'Invalid class code. Please check and try again.');
                } else {
                    $enrolled = $pdo->prepare("SELECT id FROM class_enrollments WHERE class_id = ? AND student_id = ?");
                    $enrolled->execute([$class['id'], $user['id']]);
                    if ($enrolled->fetch()) {
                        flash('error', 'You are already enrolled in this class.');
                    } else {
                        $existing = $pdo->prepare("SELECT id, status FROM class_join_requests WHERE class_id = ? AND student_id = ?");
                        $existing->execute([$class['id'], $user['id']]);
                        $existingReq = $existing->fetch();

                        if ($existingReq && $existingReq['status'] === 'pending') {
                            flash('error', 'You already have a pending join request for this class.');
                        } elseif ($existingReq && $existingReq['status'] === 'declined') {
                            flash('error', 'Your previous request for this class was declined. Please contact the instructor.');
                        } else {
                            $pdo->prepare("INSERT INTO class_join_requests (class_id, student_id, status) VALUES (?, ?, 'pending')")
                                ->execute([$class['id'], $user['id']]);

                                addNotification($class['instructor_id'], 'join_request',
                                    "{$user['first_name']} {$user['last_name']} wants to join \"{$class['subject_name']}\" ({$class['class_code']}).",
                                    $class['id']);

                                auditLog('join_request_sent', "Student #{$user['id']} requested to join class #{$class['id']}");
                                flash('success', "Join request sent for <strong>{$class['subject_name']}</strong> ({$class['course_code']}). Waiting for instructor approval.");
                        }
                    }
                }
            }
            redirect('/join-class.php');
        }
    }

    $stmt = $pdo->prepare("SELECT cjr.*, tc.subject_name, tc.course_code, tc.class_code, tc.semester,
            s.section_name, s.year_level as sec_year,
            u.first_name as instructor_fn, u.last_name as instructor_ln
        FROM class_join_requests cjr
        JOIN instructor_classes tc ON cjr.class_id = tc.id
        JOIN sections s ON tc.section_id = s.id
        JOIN users u ON tc.instructor_id = u.id
        WHERE cjr.student_id = ?
        ORDER BY FIELD(cjr.status, 'pending', 'approved', 'declined'), cjr.created_at DESC");
    $stmt->execute([$user['id']]);
    $myRequests = $stmt->fetchAll();

    $notifications = getNotifications($user['id'], 10);
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$user['id']]);

    require_once __DIR__ . '/views/layouts/header.php';
    ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><span><i class="fas fa-key me-2"></i>Enter Class Code</span></div>
                <div class="card-body">
                    <p class="text-muted" style="font-size:0.85rem;">Enter the unique class code provided by your instructor to request enrollment.</p>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="join_class">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Class Code</label>
                            <input type="text" name="class_code" class="form-control form-control-lg text-center" style="letter-spacing:4px;font-weight:bold;font-size:1.3rem;" maxlength="10" placeholder="XXXXXX" required>
                        </div>
                        <button type="submit" class="btn btn-primary-gradient w-100"><i class="fas fa-paper-plane me-1"></i>Send Join Request</button>
                    </form>
                    <div class="mt-3 p-2" style="background:var(--gray-50);border-radius:8px;font-size:0.8rem;color:var(--gray-500);">
                        <i class="fas fa-info-circle me-1"></i>Your instructor will review your request. You'll be notified once approved or declined.
                    </div>
                </div>
            </div>

            <?php if (!empty($notifications)): ?>
            <div class="card mt-3">
                <div class="card-header"><span><i class="fas fa-bell me-2"></i>Notifications</span></div>
                <div class="card-body p-0">
                    <?php foreach (array_slice($notifications, 0, 5) as $notif): ?>
                    <div class="d-flex align-items-start gap-2 p-3" style="border-bottom:1px solid var(--gray-100);">
                        <i class="fas fa-<?= $notif['type'] === 'join_approved' ? 'check-circle text-success' : ($notif['type'] === 'join_declined' ? 'times-circle text-danger' : 'bell text-info') ?> mt-1"></i>
                        <div>
                            <div style="font-size:0.85rem;"><?= e($notif['message']) ?></div>
                            <small class="text-muted"><?= formatDate($notif['created_at']) ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><span><i class="fas fa-list me-2"></i>My Join Requests</span></div>
                <div class="card-body p-0">
                    <?php if (empty($myRequests)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-inbox" style="font-size:2rem;"></i>
                        <p class="mt-2 mb-0">No join requests yet. Enter a class code to get started.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-modern mb-0">
                            <thead><tr><th>Subject</th><th>Section</th><th>Instructor</th><th>Status</th><th>Date</th></tr></thead>
                            <tbody>
                            <?php foreach ($myRequests as $req): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary"><?= e($req['course_code']) ?></span>
                                    <div style="font-size:0.82rem;"><?= e($req['subject_name']) ?></div>
                                    <small class="text-muted">Code: <?= e($req['class_code']) ?></small>
                                </td>
                                <td><?= e($req['sec_year'] . $req['section_name']) ?></td>
                                <td style="font-size:0.85rem;"><?= e($req['instructor_fn'] . ' ' . $req['instructor_ln']) ?></td>
                                <td>
                                    <?php if ($req['status'] === 'pending'): ?>
                                        <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending</span>
                                    <?php elseif ($req['status'] === 'approved'): ?>
                                        <span class="badge bg-success"><i class="fas fa-check me-1"></i>Approved</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><i class="fas fa-times me-1"></i>Declined</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:0.82rem;"><?= formatDate($req['created_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php } ?>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
