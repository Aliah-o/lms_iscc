<?php
$pageTitle = 'Meetings';
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'superadmin', 'student');
$pdo = getDB();
$user = currentUser();
$role = $user['role'];
$isStudent = ($role === 'student');
$breadcrumbPills = [$isStudent ? 'My Learning' : ($role === 'superadmin' ? 'Administration' : 'Teaching'), 'Meetings'];

try {
    $pdo->query("SELECT 1 FROM meetings LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS meetings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        meeting_link VARCHAR(500) NOT NULL,
        platform ENUM('zoom','gmeet','teams','other') NOT NULL DEFAULT 'other',
        description TEXT DEFAULT NULL,
        meeting_date DATE DEFAULT NULL,
        start_time TIME DEFAULT NULL,
        end_time TIME DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_by INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (class_id) REFERENCES instructor_classes(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
}

$classId = intval($_GET['class_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isStudent) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect('/meetings.php'); }

    $action = $_POST['action'] ?? '';
    $postClassId = intval($_POST['class_id'] ?? $classId);

    if ($postClassId && $role === 'instructor') {
        $check = $pdo->prepare("SELECT id FROM instructor_classes WHERE id = ? AND instructor_id = ?");
        $check->execute([$postClassId, $user['id']]);
        if (!$check->fetch()) { flash('error', 'Access denied.'); redirect('/meetings.php'); }
    }

    if ($action === 'create_meeting' && $postClassId) {
        $title = trim($_POST['title'] ?? '');
        $link = trim($_POST['meeting_link'] ?? '');
        $platform = $_POST['platform'] ?? 'other';
        $description = trim($_POST['description'] ?? '');
        $meetingDate = $_POST['meeting_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';

        if (!$title || !$link) {
            flash('error', 'Title and meeting link are required.');
            redirect("/meetings.php?class_id=$postClassId");
        }

        if (!filter_var($link, FILTER_VALIDATE_URL)) {
            flash('error', 'Please enter a valid URL for the meeting link.');
            redirect("/meetings.php?class_id=$postClassId");
        }

        if (!in_array($platform, ['zoom', 'gmeet', 'teams', 'other'])) $platform = 'other';

        if ($platform === 'other') {
            if (strpos($link, 'zoom.us') !== false) $platform = 'zoom';
            elseif (strpos($link, 'meet.google') !== false) $platform = 'gmeet';
            elseif (strpos($link, 'teams.microsoft') !== false || strpos($link, 'teams.live') !== false) $platform = 'teams';
        }

        $pdo->prepare("INSERT INTO meetings (class_id, title, meeting_link, platform, description, meeting_date, start_time, end_time, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $postClassId, $title, $link, $platform,
                $description ?: null,
                $meetingDate ?: null,
                $startTime ?: null,
                $endTime ?: null,
                $user['id']
            ]);
        auditLog('meeting_created', "Created meeting '$title' for class #$postClassId");
        flash('success', "Meeting \"$title\" created successfully.");
        redirect("/meetings.php?class_id=$postClassId");
    }

    elseif ($action === 'update_meeting') {
        $meetingId = intval($_POST['meeting_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $link = trim($_POST['meeting_link'] ?? '');
        $platform = $_POST['platform'] ?? 'other';
        $description = trim($_POST['description'] ?? '');
        $meetingDate = $_POST['meeting_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';

        if (!$meetingId || !$title || !$link) {
            flash('error', 'Title and meeting link are required.');
            redirect("/meetings.php?class_id=$postClassId");
        }

        if (!filter_var($link, FILTER_VALIDATE_URL)) {
            flash('error', 'Please enter a valid URL.');
            redirect("/meetings.php?class_id=$postClassId");
        }
        if (!in_array($platform, ['zoom', 'gmeet', 'teams', 'other'])) $platform = 'other';

        if ($platform === 'other') {
            if (strpos($link, 'zoom.us') !== false) $platform = 'zoom';
            elseif (strpos($link, 'meet.google') !== false) $platform = 'gmeet';
            elseif (strpos($link, 'teams.microsoft') !== false || strpos($link, 'teams.live') !== false) $platform = 'teams';
        }

        $ownerCheck = $pdo->prepare("SELECT m.id FROM meetings m JOIN instructor_classes tc ON m.class_id = tc.id WHERE m.id = ? AND (tc.instructor_id = ? OR ? = 'superadmin')");
        $ownerCheck->execute([$meetingId, $user['id'], $role]);
        if (!$ownerCheck->fetch()) { flash('error', 'Access denied.'); redirect('/meetings.php'); }

        $pdo->prepare("UPDATE meetings SET title = ?, meeting_link = ?, platform = ?, description = ?, meeting_date = ?, start_time = ?, end_time = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$title, $link, $platform, $description ?: null, $meetingDate ?: null, $startTime ?: null, $endTime ?: null, $meetingId]);
        auditLog('meeting_updated', "Updated meeting #$meetingId '$title'");
        flash('success', "Meeting updated successfully.");
        redirect("/meetings.php?class_id=$postClassId");
    }

    elseif ($action === 'toggle_meeting') {
        $meetingId = intval($_POST['meeting_id'] ?? 0);
        $ownerCheck = $pdo->prepare("SELECT m.id, m.is_active FROM meetings m JOIN instructor_classes tc ON m.class_id = tc.id WHERE m.id = ? AND (tc.instructor_id = ? OR ? = 'superadmin')");
        $ownerCheck->execute([$meetingId, $user['id'], $role]);
        $meeting = $ownerCheck->fetch();
        if (!$meeting) { flash('error', 'Access denied.'); redirect('/meetings.php'); }

        $newStatus = $meeting['is_active'] ? 0 : 1;
        $pdo->prepare("UPDATE meetings SET is_active = ? WHERE id = ?")->execute([$newStatus, $meetingId]);
        auditLog('meeting_toggled', "Meeting #$meetingId " . ($newStatus ? 'restored' : 'archived'));
        flash('success', 'Meeting ' . ($newStatus ? 'restored' : 'archived') . '.');
        redirect("/meetings.php?class_id=$postClassId");
    }

    elseif ($action === 'delete_meeting') {
        $meetingId = intval($_POST['meeting_id'] ?? 0);
        $ownerCheck = $pdo->prepare("SELECT m.id FROM meetings m JOIN instructor_classes tc ON m.class_id = tc.id WHERE m.id = ? AND (tc.instructor_id = ? OR ? = 'superadmin')");
        $ownerCheck->execute([$meetingId, $user['id'], $role]);
        if (!$ownerCheck->fetch()) { flash('error', 'Access denied.'); redirect('/meetings.php'); }

        $pdo->prepare("DELETE FROM meetings WHERE id = ?")->execute([$meetingId]);
        auditLog('meeting_deleted', "Deleted meeting #$meetingId");
        flash('success', 'Meeting deleted.');
        redirect("/meetings.php?class_id=$postClassId");
    }
}

$platformMeta = [
    'zoom'  => ['label' => 'Zoom',           'icon' => 'fa-video',         'color' => '#2D8CFF', 'bg' => '#E8F4FF'],
    'gmeet' => ['label' => 'Google Meet',     'icon' => 'fa-video',         'color' => '#00897B', 'bg' => '#E0F2F1'],
    'teams' => ['label' => 'Microsoft Teams', 'icon' => 'fa-users',         'color' => '#6264A7', 'bg' => '#ECEDF8'],
    'other' => ['label' => 'Other',           'icon' => 'fa-link',          'color' => '#6B7280', 'bg' => '#F3F4F6'],
];

if ($isStudent) {
    $stmt = $pdo->prepare("SELECT tc.*, s.section_name, u.first_name as instructor_fn, u.last_name as instructor_ln,
        (SELECT COUNT(*) FROM meetings m WHERE m.class_id = tc.id AND m.is_active = 1) as meeting_count
        FROM class_enrollments ce
        JOIN instructor_classes tc ON ce.class_id = tc.id
        JOIN sections s ON tc.section_id = s.id
        JOIN users u ON tc.instructor_id = u.id
        WHERE ce.student_id = ? AND tc.is_active = 1
        ORDER BY tc.program_code, tc.year_level");
    $stmt->execute([$user['id']]);
    $classes = $stmt->fetchAll();
} elseif ($role === 'superadmin') {
    $classes = $pdo->query("SELECT tc.*, u.first_name as instructor_fn, u.last_name as instructor_ln, s.section_name,
        (SELECT COUNT(*) FROM meetings m WHERE m.class_id = tc.id) as meeting_count
        FROM instructor_classes tc
        JOIN users u ON tc.instructor_id = u.id
        JOIN sections s ON tc.section_id = s.id
        WHERE tc.is_active = 1
        ORDER BY tc.program_code, tc.year_level, s.section_name")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT tc.*, s.section_name,
        (SELECT COUNT(*) FROM meetings m WHERE m.class_id = tc.id) as meeting_count
        FROM instructor_classes tc
        JOIN sections s ON tc.section_id = s.id
        WHERE tc.instructor_id = ? AND tc.is_active = 1
        ORDER BY tc.program_code, tc.year_level");
    $stmt->execute([$user['id']]);
    $classes = $stmt->fetchAll();
}

$meetings = [];
$classInfo = null;

if ($classId) {
    if ($isStudent) {
        $chk = $pdo->prepare("SELECT tc.*, s.section_name, u.first_name as instructor_fn, u.last_name as instructor_ln
            FROM class_enrollments ce
            JOIN instructor_classes tc ON ce.class_id = tc.id
            JOIN sections s ON tc.section_id = s.id
            JOIN users u ON tc.instructor_id = u.id
            WHERE ce.student_id = ? AND tc.id = ? AND tc.is_active = 1");
        $chk->execute([$user['id'], $classId]);
        $classInfo = $chk->fetch();
    } elseif ($role === 'instructor') {
        $chk = $pdo->prepare("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id WHERE tc.id = ? AND tc.instructor_id = ?");
        $chk->execute([$classId, $user['id']]);
        $classInfo = $chk->fetch();
    } else {
        $chk = $pdo->prepare("SELECT tc.*, s.section_name, u.first_name as instructor_fn, u.last_name as instructor_ln FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id JOIN users u ON tc.instructor_id = u.id WHERE tc.id = ?");
        $chk->execute([$classId]);
        $classInfo = $chk->fetch();
    }

    if ($classInfo) {
        if ($isStudent) {
            $mStmt = $pdo->prepare("SELECT m.*, u.first_name as creator_fn, u.last_name as creator_ln
                FROM meetings m JOIN users u ON m.created_by = u.id
                WHERE m.class_id = ? AND m.is_active = 1
                ORDER BY CASE WHEN m.meeting_date IS NULL THEN 1 ELSE 0 END, m.meeting_date DESC, m.created_at DESC");
        } else {
            $mStmt = $pdo->prepare("SELECT m.*, u.first_name as creator_fn, u.last_name as creator_ln
                FROM meetings m JOIN users u ON m.created_by = u.id
                WHERE m.class_id = ?
                ORDER BY CASE WHEN m.meeting_date IS NULL THEN 1 ELSE 0 END, m.meeting_date DESC, m.created_at DESC");
        }
        $mStmt->execute([$classId]);
        $meetings = $mStmt->fetchAll();
    } else {
        $classId = 0;
    }
}

require_once __DIR__ . '/views/layouts/header.php';
?>

<?php if (!$classId): ?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <span><i class="fas fa-video me-2"></i><?= $isStudent ? 'Select a Class to View Meetings' : 'Select a Class to Manage Meetings' ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($classes)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                    <?php if ($isStudent): ?>
                    You are not enrolled in any classes yet.
                    <?php else: ?>
                    No classes found. <?= $role === 'instructor' ? 'You have not been assigned to any classes yet.' : '' ?>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($classes as $cls): ?>
                    <div class="col-md-6 col-lg-4">
                        <a href="<?= BASE_URL ?>/meetings.php?class_id=<?= $cls['id'] ?>" class="text-decoration-none">
                            <div class="card h-100 border" style="transition:all .2s;cursor:pointer;" onmouseover="this.style.borderColor='var(--primary)';this.style.transform='translateY(-2px)'" onmouseout="this.style.borderColor='';this.style.transform=''">
                                <div class="card-body">
                                    <div class="d-flex align-items-center gap-3 mb-2">
                                        <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#E8F4FF,#BFDBFE);display:flex;align-items:center;justify-content:center;">
                                            <i class="fas fa-video" style="color:#2D8CFF;"></i>
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
                                    <?php if (isset($cls['instructor_fn'])): ?>
                                    <div style="font-size:0.8rem;color:var(--gray-500);"><i class="fas fa-user me-1"></i><?= e($cls['instructor_fn'] . ' ' . $cls['instructor_ln']) ?></div>
                                    <?php endif; ?>
                                    <div class="mt-2 d-flex align-items-center gap-2">
                                        <span style="font-size:0.8rem;color:var(--gray-500);"><i class="fas fa-video me-1"></i><?= $cls['meeting_count'] ?> meeting<?= $cls['meeting_count'] != 1 ? 's' : '' ?></span>
                                    </div>
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
        <a href="<?= BASE_URL ?>/meetings.php" class="btn btn-sm btn-outline-secondary me-2"><i class="fas fa-arrow-left me-1"></i>Back</a>
        <span class="fw-bold" style="font-size:1.05rem;"><?= e($classInfo['subject_name'] ?? 'General') ?></span>
        <span class="text-muted ms-2" style="font-size:0.85rem;">
            <?= e(PROGRAMS[$classInfo['program_code']] ?? '') ?> &bull; <?= e(YEAR_LEVELS[$classInfo['year_level']] ?? '') ?> &bull; Sec. <?= e($classInfo['section_name']) ?>
            <?php if (isset($classInfo['instructor_fn'])): ?>
            &bull; <i class="fas fa-user ms-1 me-1"></i><?= e($classInfo['instructor_fn'] . ' ' . $classInfo['instructor_ln']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php if (!$isStudent): ?>
    <button class="btn btn-primary-gradient btn-sm" data-bs-toggle="modal" data-bs-target="#meetingModal" onclick="resetMeetingForm()">
        <i class="fas fa-plus me-1"></i>New Meeting
    </button>
    <?php endif; ?>
</div>

<?php if (empty($meetings)): ?>
<div class="card">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-video-slash fa-3x mb-3 d-block" style="opacity:0.3;"></i>
        <h6 class="fw-bold mb-1">No Meetings Yet</h6>
        <p class="mb-0" style="font-size:0.85rem;">
            <?= $isStudent ? 'Your instructor has not posted any meetings yet.' : 'Click "New Meeting" to add a Zoom, Google Meet, or other meeting link.' ?>
        </p>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($meetings as $mtg):
        $pm = $platformMeta[$mtg['platform']] ?? $platformMeta['other'];
        $isUpcoming = $mtg['meeting_date'] && strtotime($mtg['meeting_date']) >= strtotime('today');
        $isPast = $mtg['meeting_date'] && strtotime($mtg['meeting_date']) < strtotime('today');
        $isToday = $mtg['meeting_date'] && $mtg['meeting_date'] === date('Y-m-d');
    ?>
    <div class="col-md-6 col-lg-4">
        <div class="card meeting-card h-100 <?= !$mtg['is_active'] ? 'meeting-inactive' : '' ?> <?= $isToday ? 'meeting-today' : '' ?>">
            <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="meeting-platform-badge" style="background:<?= $pm['bg'] ?>;color:<?= $pm['color'] ?>;">
                        <i class="fas <?= $pm['icon'] ?> me-1"></i><?= $pm['label'] ?>
                    </span>
                    <div class="d-flex align-items-center gap-1">
                        <?php if ($isToday): ?>
                        <span class="badge bg-success" style="font-size:0.7rem;"><i class="fas fa-circle me-1" style="font-size:0.5rem;"></i>Today</span>
                        <?php elseif ($isPast): ?>
                        <span class="badge bg-secondary" style="font-size:0.7rem;">Past</span>
                        <?php endif; ?>
                        <?php if (!$mtg['is_active']): ?>
                        <span class="badge bg-warning text-dark" style="font-size:0.7rem;">Inactive</span>
                        <?php endif; ?>
                    </div>
                </div>

                <h6 class="fw-bold mb-1" style="font-size:0.95rem;"><?= e($mtg['title']) ?></h6>

                <?php if ($mtg['description']): ?>
                <p class="text-muted mb-2" style="font-size:0.82rem;line-height:1.5;"><?= e($mtg['description']) ?></p>
                <?php endif; ?>

                <?php if ($mtg['meeting_date']): ?>
                <div class="d-flex align-items-center gap-3 mb-2" style="font-size:0.8rem;color:var(--gray-500);">
                    <span><i class="far fa-calendar me-1"></i><?= date('D, M d, Y', strtotime($mtg['meeting_date'])) ?></span>
                    <?php if ($mtg['start_time']): ?>
                    <span><i class="far fa-clock me-1"></i><?= date('g:i A', strtotime($mtg['start_time'])) ?><?= $mtg['end_time'] ? ' - ' . date('g:i A', strtotime($mtg['end_time'])) : '' ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="mt-auto pt-2">
                    <?php if ($mtg['is_active']): ?>
                    <a href="<?= e($mtg['meeting_link']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm w-100 meeting-join-btn" style="background:<?= $pm['color'] ?>;color:#fff;">
                        <i class="fas fa-external-link-alt me-1"></i>Join Meeting
                    </a>
                    <?php else: ?>
                    <button class="btn btn-sm btn-secondary w-100" disabled><i class="fas fa-archive me-1"></i>Meeting Archived</button>
                    <?php endif; ?>

                    <?php if (!$isStudent): ?>
                    <div class="d-flex gap-1 mt-2">
                        <button class="btn btn-sm btn-outline-primary flex-fill" onclick='editMeeting(<?= json_encode([
                            "id" => $mtg["id"], "title" => $mtg["title"], "meeting_link" => $mtg["meeting_link"],
                            "platform" => $mtg["platform"], "description" => $mtg["description"],
                            "meeting_date" => $mtg["meeting_date"], "start_time" => $mtg["start_time"], "end_time" => $mtg["end_time"]
                        ]) ?>)'>
                            <i class="fas fa-pen me-1"></i>Edit
                        </button>
                        <form method="POST" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_meeting">
                            <input type="hidden" name="class_id" value="<?= $classId ?>">
                            <input type="hidden" name="meeting_id" value="<?= $mtg['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $mtg['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $mtg['is_active'] ? 'Archive' : 'Restore' ?>">
                                <i class="fas <?= $mtg['is_active'] ? 'fa-archive' : 'fa-undo' ?>"></i>
                            </button>
                        </form>
                        <form method="POST" class="d-inline" onsubmit="return confirmForm(this, 'Delete this meeting permanently?', 'Delete Meeting')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_meeting">
                            <input type="hidden" name="class_id" value="<?= $classId ?>">
                            <input type="hidden" name="meeting_id" value="<?= $mtg['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <div class="mt-2 text-muted" style="font-size:0.72rem;">
                        <i class="fas fa-user me-1"></i>Posted by <?= e($mtg['creator_fn'] . ' ' . $mtg['creator_ln']) ?>
                        &bull; <?= date('M d, Y g:i A', strtotime($mtg['created_at'])) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$isStudent): ?>
<div class="modal fade" id="meetingModal" tabindex="-1" aria-labelledby="meetingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" id="meetingForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="meetingAction" value="create_meeting">
                <input type="hidden" name="class_id" value="<?= $classId ?>">
                <input type="hidden" name="meeting_id" id="meetingId" value="">

                <div class="modal-header border-0 pb-1">
                    <h5 class="modal-title fw-bold" id="meetingModalLabel"><i class="fas fa-video me-2" style="color:var(--primary);"></i>New Meeting</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-2">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:0.85rem;">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="mtgTitle" class="form-control" placeholder="e.g. Weekly Class Discussion" required maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:0.85rem;">Meeting Link <span class="text-danger">*</span></label>
                        <input type="url" name="meeting_link" id="mtgLink" class="form-control" placeholder="https://zoom.us/j/... or https://meet.google.com/..." required maxlength="500">
                        <div class="form-text" style="font-size:0.75rem;">Paste the Zoom, Google Meet, or Teams invite link</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:0.85rem;">Platform</label>
                        <select name="platform" id="mtgPlatform" class="form-select">
                            <option value="zoom">Zoom</option>
                            <option value="gmeet">Google Meet</option>
                            <option value="teams">Microsoft Teams</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:0.85rem;">Description</label>
                        <textarea name="description" id="mtgDesc" class="form-control" rows="2" placeholder="Optional details about the meeting..." maxlength="1000"></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:0.85rem;">Date</label>
                            <input type="date" name="meeting_date" id="mtgDate" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:0.85rem;">Start Time</label>
                            <input type="time" name="start_time" id="mtgStart" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:0.85rem;">End Time</label>
                            <input type="time" name="end_time" id="mtgEnd" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>Cancel</button>
                    <button type="submit" class="btn btn-primary-gradient" id="meetingSubmitBtn"><i class="fas fa-save me-1"></i>Create Meeting</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>

<?php if (!$isStudent && $classId): ?>
<script>
function resetMeetingForm() {
    document.getElementById('meetingAction').value = 'create_meeting';
    document.getElementById('meetingId').value = '';
    document.getElementById('meetingModalLabel').innerHTML = '<i class="fas fa-video me-2" style="color:var(--primary);"></i>New Meeting';
    document.getElementById('meetingSubmitBtn').innerHTML = '<i class="fas fa-save me-1"></i>Create Meeting';
    document.getElementById('mtgTitle').value = '';
    document.getElementById('mtgLink').value = '';
    document.getElementById('mtgPlatform').value = 'zoom';
    document.getElementById('mtgDesc').value = '';
    document.getElementById('mtgDate').value = '';
    document.getElementById('mtgStart').value = '';
    document.getElementById('mtgEnd').value = '';
}

function editMeeting(data) {
    document.getElementById('meetingAction').value = 'update_meeting';
    document.getElementById('meetingId').value = data.id;
    document.getElementById('meetingModalLabel').innerHTML = '<i class="fas fa-pen me-2" style="color:var(--primary);"></i>Edit Meeting';
    document.getElementById('meetingSubmitBtn').innerHTML = '<i class="fas fa-save me-1"></i>Update Meeting';
    document.getElementById('mtgTitle').value = data.title || '';
    document.getElementById('mtgLink').value = data.meeting_link || '';
    document.getElementById('mtgPlatform').value = data.platform || 'other';
    document.getElementById('mtgDesc').value = data.description || '';
    document.getElementById('mtgDate').value = data.meeting_date || '';
    document.getElementById('mtgStart').value = data.start_time || '';
    document.getElementById('mtgEnd').value = data.end_time || '';
    new bootstrap.Modal(document.getElementById('meetingModal')).show();
}

document.getElementById('mtgLink')?.addEventListener('input', function() {
    const url = this.value.toLowerCase();
    const sel = document.getElementById('mtgPlatform');
    if (url.includes('zoom.us')) sel.value = 'zoom';
    else if (url.includes('meet.google')) sel.value = 'gmeet';
    else if (url.includes('teams.microsoft') || url.includes('teams.live')) sel.value = 'teams';
});
</script>
<?php endif; ?>
