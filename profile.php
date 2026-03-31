<?php
$pageTitle = 'My Profile';
require_once __DIR__ . '/helpers/functions.php';
requireLogin();

$pdo = getDB();
$user = currentUser();
$breadcrumbPills = ['Account', 'Profile'];

$avatarMimeMap = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];
$avatarExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Invalid token.');
        redirect('/profile.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $firstName = normalizePersonName($_POST['first_name'] ?? '');
        $lastName = normalizePersonName($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $errors = [];
        $profilePicture = $user['profile_picture'] ?? null;

        if ($firstName === '') {
            $errors[] = 'First name is required.';
        }
        if ($lastName === '') {
            $errors[] = 'Last name is required.';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (!empty($_FILES['profile_picture']['name'])) {
            $stored = storeUploadedFile($_FILES['profile_picture'], 'uploads/profile', 'profile_' . $user['id'], $avatarMimeMap, $avatarExtensions, UPLOAD_IMAGE_MAX_SIZE);
            if (!$stored['ok']) {
                $errors[] = $stored['error'];
            } else {
                if (!empty($profilePicture) && $profilePicture !== $stored['relative_path']) {
                    deleteStorageFile($profilePicture);
                }
                $profilePicture = $stored['relative_path'];
            }
        }

        if (empty($errors)) {
            $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, profile_picture = ? WHERE id = ?")
                ->execute([$firstName, $lastName, $email !== '' ? $email : null, $profilePicture, $user['id']]);
            auditLog('profile_updated', 'Updated personal profile details');
            currentUser(true);
            flash('success', 'Profile updated successfully.');
        } else {
            flash('error', implode(' ', $errors));
        }

        redirect('/profile.php');
    }

    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $errors = [];

        if (!password_verify($currentPassword, $user['password'])) {
            $errors[] = 'Current password is incorrect.';
        }
        if (strlen($newPassword) < getPasswordMinLength()) {
            $errors[] = 'New password must be at least ' . getPasswordMinLength() . ' characters.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation do not match.';
        }
        if ($currentPassword !== '' && $currentPassword === $newPassword) {
            $errors[] = 'Choose a different password from your current one.';
        }

        if (empty($errors)) {
            $pdo->prepare("UPDATE users SET password = ?, plain_password = NULL WHERE id = ?")
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
            auditLog('password_changed', 'Changed account password');
            currentUser(true);
            flash('success', 'Password changed successfully.');
        } else {
            flash('error', implode(' ', $errors));
        }

        redirect('/profile.php');
    }
}

$user = currentUser(true);
$avatarUrl = getUserAvatarUrl($user);
$userInitials = getUserInitials($user);
$sectionLabel = 'Not assigned yet';

if (!empty($user['section_id'])) {
    $sectionStmt = $pdo->prepare("SELECT year_level, section_name FROM sections WHERE id = ?");
    $sectionStmt->execute([$user['section_id']]);
    $section = $sectionStmt->fetch();
    if ($section) {
        $sectionLabel = getSectionDisplay($section['year_level'], $section['section_name']);
    }
}

require_once __DIR__ . '/views/layouts/header.php';
?>

<div class="profile-shell">
    <section class="profile-hero">
        <div class="profile-hero-grid">
            <div>
                <div class="profile-identity">
                    <?php if ($avatarUrl): ?>
                    <img src="<?= e($avatarUrl) ?>" alt="<?= e($user['first_name']) ?>" class="profile-avatar" id="profileAvatarPreview">
                    <?php else: ?>
                    <div class="profile-avatar" id="profileAvatarPreview"><?= e($userInitials) ?></div>
                    <?php endif; ?>
                    <div class="profile-copy">
                        <span class="subject-chip mb-2"><i class="bi bi-stars"></i><?= e(ROLES[$user['role']] ?? ucfirst($user['role'])) ?></span>
                        <h2 class="profile-name mb-1"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></h2>
                        <p class="profile-summary mb-0 text-muted">
                            Manage your personal details, profile photo, and account password from one place.
                        </p>
                    </div>
                </div>

                <div class="profile-stat-grid">
                    <div class="profile-stat">
                        <span class="profile-stat-label">Username</span>
                        <strong><?= e($user['username']) ?></strong>
                    </div>
                    <div class="profile-stat">
                        <span class="profile-stat-label">Email</span>
                        <strong><?= e($user['email'] ?: 'Not set yet') ?></strong>
                    </div>
                    <?php if ($user['role'] === 'student'): ?>
                    <div class="profile-stat">
                        <span class="profile-stat-label">Student ID</span>
                        <strong><?= e($user['student_id_no'] ?: 'Pending') ?></strong>
                    </div>
                    <div class="profile-stat">
                        <span class="profile-stat-label">Section</span>
                        <strong><?= e($sectionLabel) ?></strong>
                    </div>
                    <?php else: ?>
                    <div class="profile-stat">
                        <span class="profile-stat-label">Program</span>
                        <strong><?= e($user['program_code'] ?: 'BSIT') ?></strong>
                    </div>
                    <div class="profile-stat">
                        <span class="profile-stat-label">Member Since</span>
                        <strong><?= e(formatDate($user['created_at'])) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="profile-hero-actions">
                <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-primary-gradient"><i class="bi bi-grid"></i><span>Back to Dashboard</span></a>
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('profilePictureInput').click()"><i class="bi bi-camera"></i><span>Change Avatar</span></button>
            </div>
        </div>
    </section>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><span><i class="bi bi-person-badge me-2"></i>Profile Details</span></div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" class="row g-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">First Name</label>
                            <input type="text" name="first_name" class="form-control" value="<?= e($user['first_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Last Name</label>
                            <input type="text" name="last_name" class="form-control" value="<?= e($user['last_name']) ?>" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Email Address</label>
                            <input type="email" name="email" class="form-control" value="<?= e($user['email']) ?>" placeholder="name@example.com">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Role</label>
                            <input type="text" class="form-control" value="<?= e(ROLES[$user['role']] ?? ucfirst($user['role'])) ?>" disabled>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Profile Picture</label>
                            <input type="file" name="profile_picture" id="profilePictureInput" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                            <div class="form-text">Allowed formats: JPG, PNG, GIF, or WebP. Maximum size: 5MB.</div>
                        </div>
                        <div class="col-12">
                            <div class="p-3 rounded-4 border" style="background:var(--gray-50);border-color:var(--gray-100) !important;">
                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                    <span class="subject-chip"><i class="bi bi-shield-check"></i>Secure Update</span>
                                    <span class="text-muted" style="font-size:0.88rem;">Profile changes are saved immediately and reflected in the navbar after refresh.</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary-gradient"><i class="bi bi-save me-2"></i>Save Profile</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-4" id="security">
                <div class="card-header"><span><i class="bi bi-shield-lock me-2"></i>Change Password</span></div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="change_password">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">New Password</label>
                            <input type="password" name="new_password" class="form-control" minlength="<?= getPasswordMinLength() ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="<?= getPasswordMinLength() ?>" required>
                        </div>
                        <div class="col-12">
                            <div class="p-3 rounded-4 border" style="background:var(--gray-50);border-color:var(--gray-100) !important;font-size:0.88rem;">
                                <div class="fw-semibold mb-1">Password rules</div>
                                <div class="text-muted">Use at least <?= getPasswordMinLength() ?> characters. Your current password is always required for verification.</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-key me-2"></i>Update Password</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><span><i class="bi bi-info-circle me-2"></i>Account Snapshot</span></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between py-2" style="border-bottom:1px solid var(--gray-100);font-size:0.9rem;">
                        <span class="text-muted">Account Status</span>
                        <strong><?= $user['is_active'] ? 'Active' : 'Archived' ?></strong>
                    </div>
                    <div class="d-flex justify-content-between py-2" style="border-bottom:1px solid var(--gray-100);font-size:0.9rem;">
                        <span class="text-muted">Program</span>
                        <strong><?= e($user['program_code'] ?: 'BSIT') ?></strong>
                    </div>
                    <div class="d-flex justify-content-between py-2" style="border-bottom:1px solid var(--gray-100);font-size:0.9rem;">
                        <span class="text-muted">Semester</span>
                        <strong><?= e($user['semester'] ?: 'Not assigned') ?></strong>
                    </div>
                    <div class="d-flex justify-content-between py-2" style="font-size:0.9rem;">
                        <span class="text-muted">Last Updated</span>
                        <strong><?= e(formatDate($user['updated_at'])) ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('profilePictureInput')?.addEventListener('change', function() {
    const file = this.files && this.files[0];
    const preview = document.getElementById('profileAvatarPreview');
    if (!file || !preview || !file.type.startsWith('image/')) {
        return;
    }

    const objectUrl = URL.createObjectURL(file);
    if (preview.tagName === 'IMG') {
        preview.src = objectUrl;
        return;
    }

    const img = document.createElement('img');
    img.src = objectUrl;
    img.alt = 'Profile preview';
    img.className = 'profile-avatar';
    img.id = 'profileAvatarPreview';
    preview.replaceWith(img);
});
</script>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
