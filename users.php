<?php
$pageTitle = 'User Management';
require_once __DIR__ . '/helpers/functions.php';
requireRole('superadmin', 'staff');
$pdo = getDB();
$user = currentUser();
$isSuperadmin = $user['role'] === 'superadmin';
$breadcrumbPills = ['Users'];
$singletonRoles = ['superadmin'];
$manageableRoles = $isSuperadmin ? ['superadmin', 'staff', 'instructor', 'student'] : ['instructor', 'student'];
$creatableRoles = $isSuperadmin ? ['staff', 'instructor', 'student'] : ['instructor', 'student'];
$quotedManageableRoles = implode(', ', array_map(static fn(string $role): string => $pdo->quote($role), $manageableRoles));
$countUsersByRole = function(string $role, bool $activeOnly = false, ?int $excludeId = null) use ($pdo): int {
    $sql = "SELECT COUNT(*) FROM users WHERE role = ?";
    $params = [$role];

    if ($activeOnly) {
        $sql .= " AND is_active = 1";
    }

    if ($excludeId !== null) {
        $sql .= " AND id <> ?";
        $params[] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect('/users.php'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $fn = normalizePersonName($_POST['first_name'] ?? '');
        $ln = normalizePersonName($_POST['last_name'] ?? '');
        $role = $_POST['role'] ?? '';
        $studentIdNo = normalizeStudentId($_POST['student_id_no'] ?? '');

        $allowedRoles = $creatableRoles;

        if (empty($username) || strlen($username) > 50 || empty($password) || empty($fn) || empty($ln) || !in_array($role, $allowedRoles)) {
            flash('error', 'Invalid input. Username is required and must be 50 characters or fewer.');
        } elseif (strlen($password) < getPasswordMinLength()) {
            flash('error', 'Password must be at least ' . getPasswordMinLength() . ' characters.');
        } elseif (in_array($role, $singletonRoles, true) && $countUsersByRole($role) > 0) {
            flash('error', 'Only one ' . $role . ' account is allowed.');
        } elseif ($role === 'student' && !isValidStudentId($studentIdNo)) {
            flash('error', 'Student ID must use format "C-22-1234" (capital letter, 2-digit year, 2-6 digits).');
        } else {
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $check->execute([$username]);
            if ($check->fetch()) {
                flash('error', 'Username already taken.');
            } else {
                if ($role !== 'student') {
                    $studentIdNo = null;
                } else {
                    $studentIdCheck = $pdo->prepare("SELECT id FROM users WHERE student_id_no = ?");
                    $studentIdCheck->execute([$studentIdNo]);
                    if ($studentIdCheck->fetch()) {
                        flash('error', 'Student ID already assigned to another student.');
                        redirect('/users.php');
                    }
                }

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (username, password, plain_password, email, first_name, last_name, role, student_id_no) VALUES (?, ?, NULL, ?, ?, ?, ?, ?)")
                    ->execute([$username, $hash, $email, $fn, $ln, $role, $studentIdNo]);
                auditLog('user_created', "Created user $username ($role)" . ($studentIdNo ? " with student ID $studentIdNo" : ''));
                flash('success', 'User created successfully.');
            }
        }
    } elseif ($action === 'save_student_id') {
        $id = intval($_POST['user_id'] ?? 0);
        $studentIdNo = normalizeStudentId($_POST['student_id_no'] ?? '');

        if (!$id || !isValidStudentId($studentIdNo)) {
            flash('error', 'Student ID must use format "A-22-12345" (capital letter, 2-digit year, 2-7 digits).');
        } else {
            $targetStmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ?");
            $targetStmt->execute([$id]);
            $target = $targetStmt->fetch();

            if (!$target || $target['role'] !== 'student') {
                flash('error', 'Student not found.');
            } else {
                $duplicateStmt = $pdo->prepare("SELECT id FROM users WHERE student_id_no = ? AND id <> ?");
                $duplicateStmt->execute([$studentIdNo, $id]);

                if ($duplicateStmt->fetch()) {
                    flash('error', 'Student ID already assigned to another student.');
                } else {
                    $pdo->prepare("UPDATE users SET student_id_no = ? WHERE id = ? AND role = 'student'")
                        ->execute([$studentIdNo, $id]);
                    auditLog('student_id_updated', "Updated student ID for {$target['username']} to $studentIdNo");
                    flash('success', 'Student ID saved successfully.');
                }
            }
        }
    } elseif ($action === 'archive') {
        $id = intval($_POST['user_id'] ?? 0);
        if ($id !== $user['id']) {
            $targetStmt = $pdo->prepare("SELECT id, role, is_active FROM users WHERE id = ?");
            $targetStmt->execute([$id]);
            $target = $targetStmt->fetch();

            if (!$target) {
                flash('error', 'User not found.');
            } elseif (!in_array($target['role'], $manageableRoles, true)) {
                flash('error', 'You do not have permission to manage that user.');
            } elseif (in_array($target['role'], $singletonRoles, true) && $countUsersByRole($target['role'], true) <= 1) {
                flash('error', 'You must keep one active ' . $target['role'] . ' account.');
            } else {
                $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$id]);
                auditLog('user_archived', "Archived user #$id");
                flash('success', 'User archived.');
            }
        }
    } elseif ($action === 'restore') {
        $id = intval($_POST['user_id'] ?? 0);
        if ($id !== $user['id']) {
            $targetStmt = $pdo->prepare("SELECT id, role, is_active FROM users WHERE id = ?");
            $targetStmt->execute([$id]);
            $target = $targetStmt->fetch();

            if (!$target) {
                flash('error', 'User not found.');
            } elseif (!in_array($target['role'], $manageableRoles, true)) {
                flash('error', 'You do not have permission to manage that user.');
            } elseif (in_array($target['role'], $singletonRoles, true) && $countUsersByRole($target['role'], true, $id) >= 1) {
                flash('error', 'Only one active ' . $target['role'] . ' account is allowed.');
            } else {
                $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$id]);
                auditLog('user_restored', "Restored user #$id");
                flash('success', 'User restored.');
            }
        }
    } elseif ($action === 'reset_password' && $user['role'] === 'superadmin') {
        $id = intval($_POST['user_id'] ?? 0);
        $newPw = $_POST['new_password'] ?? '';
        if (empty($newPw) || strlen($newPw) < getPasswordMinLength()) {
            flash('error', 'Password must be at least ' . getPasswordMinLength() . ' characters.');
        } elseif ($id) {
            $hash = password_hash($newPw, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ?, plain_password = NULL WHERE id = ?")->execute([$hash, $id]);
            auditLog('password_reset', "Reset password for user #$id");
            flash('success', 'Password reset successfully.');
        }
    }
    redirect('/users.php');
}

$filterRole = $_GET['role'] ?? '';
$filterRole = in_array($filterRole, $manageableRoles, true) ? $filterRole : '';
$search = trim($_GET['search'] ?? '');
$activeTab = $_GET['tab'] ?? 'active';
$sort = $_GET['sort'] ?? 'az';
$sort = in_array($sort, ['az', 'za'], true) ? $sort : 'az';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;
$offset = ($page - 1) * $perPage;

$countQuery = "SELECT COUNT(*) FROM users WHERE role IN ($quotedManageableRoles)";
$query = "SELECT * FROM users WHERE role IN ($quotedManageableRoles)";
$params = [];

$countActiveQuery = "SELECT COUNT(*) FROM users WHERE is_active = 1 AND role IN ($quotedManageableRoles)";
$countArchivedQuery = "SELECT COUNT(*) FROM users WHERE is_active = 0 AND role IN ($quotedManageableRoles)";
$countActiveParams = [];
$countArchivedParams = [];

// Tab filter: active vs archived
if ($activeTab === 'archived') {
    $query .= " AND is_active = 0";
    $countQuery .= " AND is_active = 0";
} else {
    $query .= " AND is_active = 1";
    $countQuery .= " AND is_active = 1";
}

if ($filterRole) {
    $query .= " AND role = ?";
    $countQuery .= " AND role = ?";
    $countActiveQuery .= " AND role = ?";
    $countArchivedQuery .= " AND role = ?";
    $params[] = $filterRole;
    $countActiveParams[] = $filterRole;
    $countArchivedParams[] = $filterRole;
}

if ($search !== '') {
    $searchClause = " AND (first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR student_id_no LIKE ? OR email LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ? OR CONCAT(last_name, ', ', first_name) LIKE ?)";
    $query .= $searchClause;
    $countQuery .= $searchClause;
    $countActiveQuery .= $searchClause;
    $countArchivedQuery .= $searchClause;

    $searchParam = "%$search%";
    $searchParams = [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam];
    $params = array_merge($params, $searchParams);
    $countActiveParams = array_merge($countActiveParams, $searchParams);
    $countArchivedParams = array_merge($countArchivedParams, $searchParams);
}

$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalUsers = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalUsers / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sortDirection = $sort === 'za' ? 'DESC' : 'ASC';
$query .= " ORDER BY last_name $sortDirection, first_name $sortDirection, role ASC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();

$stmtActive = $pdo->prepare($countActiveQuery);
$stmtActive->execute($countActiveParams);
$totalActiveUsers = (int)$stmtActive->fetchColumn();

$stmtArchived = $pdo->prepare($countArchivedQuery);
$stmtArchived->execute($countArchivedParams);
$totalArchivedUsers = (int)$stmtArchived->fetchColumn();
$availableCreateRoles = $creatableRoles;
$showingStart = $totalUsers > 0 ? $offset + 1 : 0;
$showingEnd = $totalUsers > 0 ? min($offset + count($users), $totalUsers) : 0;

$buildUsersUrl = function(array $overrides = []) use ($filterRole, $search, $activeTab, $sort, $page) {
    $queryParams = [
        'role' => $filterRole ?: null,
        'search' => $search !== '' ? $search : null,
        'tab' => $activeTab !== 'active' ? $activeTab : null,
        'sort' => $sort !== 'az' ? $sort : null,
        'page' => $page > 1 ? $page : null,
    ];

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '' || $value === false) {
            unset($queryParams[$key]);
        } else {
            $queryParams[$key] = $value;
        }
    }

    $queryString = http_build_query(array_filter($queryParams, static fn($value) => $value !== null && $value !== ''));
    return BASE_URL . '/users.php' . ($queryString ? '?' . $queryString : '');
};

require_once __DIR__ . '/views/layouts/header.php';
?>

<?php
$renderPagination = function(string $extraClass = '') use ($totalPages, $page, $buildUsersUrl) {
    if ($totalPages <= 1) {
        return;
    }
    $navClass = trim($extraClass);
    $classes = 'users-pagination pagination pagination-sm justify-content-center mb-0';
    ?>
    <nav class="<?= e($navClass) ?>">
        <ul class="<?= e($classes) ?>">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $buildUsersUrl(['page' => max(1, $page - 1)]) ?>"><i class="fas fa-chevron-left" style="font-size:0.7rem;"></i></a></li>
            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            if ($start > 1): ?><li class="page-item"><a class="page-link" href="<?= $buildUsersUrl(['page' => 1]) ?>">1</a></li><?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; endif;
            for ($p = $start; $p <= $end; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= $buildUsersUrl(['page' => $p]) ?>"><?= $p ?></a></li>
            <?php endfor;
            if ($end < $totalPages): if ($end < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="<?= $buildUsersUrl(['page' => $totalPages]) ?>"><?= $totalPages ?></a></li><?php endif; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $buildUsersUrl(['page' => min($totalPages, $page + 1)]) ?>"><i class="fas fa-chevron-right" style="font-size:0.7rem;"></i></a></li>
        </ul>
    </nav>
    <?php
};
?>

<div class="users-page">
<div class="users-toolbar d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="users-toolbar-filters d-flex gap-2 flex-wrap align-items-center">
        <a href="<?= $buildUsersUrl(['role' => null, 'page' => null]) ?>" class="btn btn-sm btn-md-lg <?= !$filterRole ? 'btn-primary' : 'btn-outline-secondary' ?>">All</a>
        <?php
        $rolesFilter = $manageableRoles;
        foreach ($rolesFilter as $r): ?>
        <a href="<?= $buildUsersUrl(['role' => $r, 'page' => null]) ?>" class="btn btn-sm btn-md-lg <?= $filterRole === $r ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= e(ucfirst($r)) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="users-toolbar-actions d-flex gap-2 align-items-center">
        <form method="GET" class="users-search-form d-flex gap-2 align-items-center">
            <?php if ($filterRole): ?><input type="hidden" name="role" value="<?= e($filterRole) ?>"><?php endif; ?>
            <?php if ($activeTab === 'archived'): ?><input type="hidden" name="tab" value="archived"><?php endif; ?>
            <?php if ($sort !== 'az'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
            <div class="users-search-group input-group input-group-sm">
                <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted" style="font-size:0.78rem;"></i></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="Search users..." value="<?= e($search) ?>" style="font-size:0.82rem;">
                <?php if ($search): ?><a href="<?= $buildUsersUrl(['search' => null, 'page' => null]) ?>" class="btn btn-outline-secondary btn-sm btn-md-lg" title="Clear"><i class="fas fa-times"></i></a><?php endif; ?>
            </div>
        </form>
        <form method="GET" class="users-sort-form d-flex gap-2 align-items-center">
            <?php if ($filterRole): ?><input type="hidden" name="role" value="<?= e($filterRole) ?>"><?php endif; ?>
            <?php if ($activeTab === 'archived'): ?><input type="hidden" name="tab" value="archived"><?php endif; ?>
            <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?= e($search) ?>"><?php endif; ?>
            <select name="sort" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="az" <?= $sort === 'az' ? 'selected' : '' ?>>Alphabetical A-Z</option>
                <option value="za" <?= $sort === 'za' ? 'selected' : '' ?>>Alphabetical Z-A</option>
            </select>
        </form>
        <button class="btn btn-primary-gradient btn-sm btn-md-lg" data-bs-toggle="modal" data-bs-target="#createUserModal"><i class="fas fa-user-plus me-1"></i>New User</button>
    </div>
</div>

<div class="users-tabs-wrap mb-3">
    <ul class="nav nav-tabs users-tabs mb-0">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'active' ? 'active' : '' ?>" href="<?= $buildUsersUrl(['tab' => null, 'page' => null]) ?>">
                <i class="fas fa-check-circle me-1"></i>Active <span class="badge bg-primary ms-1"><?= $totalActiveUsers ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'archived' ? 'active' : '' ?>" href="<?= $buildUsersUrl(['tab' => 'archived', 'page' => null]) ?>">
                <i class="fas fa-archive me-1"></i>Archived <span class="badge bg-secondary ms-1"><?= $totalArchivedUsers ?></span>
            </a>
        </li>
    </ul>
</div>

<div class="users-summary d-flex justify-content-between align-items-center mb-2">
    <small class="text-muted"><?= $totalUsers ?> user<?= $totalUsers !== 1 ? 's' : '' ?> found<?= $search ? ' for "' . e($search) . '"' : '' ?><?php if ($totalUsers > 0): ?> • Showing <?= $showingStart ?>-<?= $showingEnd ?><?php endif; ?></small>
    <?php if ($totalPages > 1): ?>
    <small class="text-muted">Page <?= $page ?> of <?= $totalPages ?></small>
    <?php endif; ?>
</div>

<?php $renderPagination('users-pagination-nav users-pagination-nav-top mb-3'); ?>

<div class="card">
    <div class="card-body p-0">
        <div class="users-table-wrap table-responsive">
            <table class="users-table table table-modern mb-0">
                <thead><tr><th>Name</th><th>Username</th><th>Student ID</th><th class="d-none d-md-table-cell">Email</th><th>Role</th><th>Program / Section</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (empty($users)): ?>
                <tr class="users-empty-row">
                    <td colspan="8" class="text-center py-4 text-muted">No users found for the current filters.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td class="fw-bold" data-label="Name"><?= e($u['last_name'] . ', ' . $u['first_name']) ?></td>
                    <td data-label="Username"><?= e($u['username']) ?></td>
                    <td data-label="Student ID">
                        <?php if ($u['role'] === 'student'): ?>
                            <?php if (!empty($u['student_id_no'])): ?>
                                <code><?= e($u['student_id_no']) ?></code>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Missing ID</span>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="text-muted d-none d-md-table-cell" style="font-size:0.82rem;" data-label="Email"><?= e($u['email']) ?></td>
                    <td data-label="Role"><span class="badge-role <?= e($u['role']) ?>"><?= e(ucfirst($u['role'])) ?></span></td>
                    <td data-label="Program / Section"><?php
                        if ($u['program_code']) {
                            echo '<span class="badge bg-primary me-1">' . e($u['program_code']) . '</span>';
                            if ($u['year_level'] && $u['role'] === 'student') {
                                $secStmt = $pdo->prepare("SELECT section_name FROM sections WHERE id = ?");
                                $secStmt->execute([$u['section_id'] ?? 0]);
                                $secRow = $secStmt->fetch();
                                echo '<span class="badge bg-info">' . e(getSectionDisplay($u['year_level'], $secRow['section_name'] ?? '')) . '</span>';
                                if (!empty($u['semester'])) echo ' <small class="text-muted">' . e($u['semester']) . '</small>';
                            }
                        } else {
                            echo '—';
                        }
                    ?></td>
                    <td data-label="Status"><span class="badge-status <?= $u['is_active'] ? 'active' : 'inactive' ?>"><?= $u['is_active'] ? 'Active' : 'Archived' ?></span></td>
                    <td class="users-table-actions table-hover" data-label="Actions">
                        <div class="users-row-actions d-flex gap-1 flex-wrap align-items-center">
                        <?php if ($user['role'] === 'superadmin' && !empty($u['plain_password'])): ?>
                        <button class="btn btn-sm btn-md-lg btn-outline-secondary view-pw-btn" type="button" title="View Password" data-pw="<?= e($u['plain_password'] ?? '') ?>" data-uname="<?= e($u['username']) ?>">
                            <i class="fas fa-eye" style="font-size:0.75rem;"></i>
                        </button>
                        <?php endif; ?>
                        <?php if ($user['role'] === 'superadmin'): ?>
                        <button class="btn btn-sm btn-md-lg btn-outline-warning" type="button" title="Reset Password" data-bs-toggle="modal" data-bs-target="#resetPwModal" data-uid="<?= $u['id'] ?>" data-uname="<?= e($u['username']) ?>">
                            <i class="fas fa-key" style="font-size:0.75rem;"></i>
                        </button>
                        <?php endif; ?>
                        <?php if ($u['role'] === 'student'): ?>
                        <button class="btn btn-sm btn-md-lg btn-outline-primary" type="button" title="<?= !empty($u['student_id_no']) ? 'Edit Student ID' : 'Set Student ID' ?>" data-bs-toggle="modal" data-bs-target="#studentIdModal" data-uid="<?= $u['id'] ?>" data-name="<?= e($u['last_name'] . ', ' . $u['first_name']) ?>" data-uname="<?= e($u['username']) ?>" data-studentid="<?= e($u['student_id_no'] ?? '') ?>">
                            <i class="fas fa-id-card me-1" style="font-size:0.75rem;"></i><?= !empty($u['student_id_no']) ? 'Edit ID' : 'Set ID' ?>
                        </button>
                        <?php endif; ?>
                        <?php if ($u['id'] !== $user['id']): ?>
                            <?php if ($u['is_active']): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirmForm(this, 'Archive this user? They will no longer be able to log in.', 'Archive')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="archive">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button class="btn btn-sm btn-md-lg btn-outline-warning"><i class="fas fa-archive me-1"></i>Archive</button>
                            </form>
                            <?php else: ?>
                            <form method="POST" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button class="btn btn-sm btn-md-lg btn-outline-success"><i class="fas fa-undo me-1"></i>Restore</button>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
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

<?php $renderPagination('mt-3'); ?>
</div>

<?php if (!empty($availableCreateRoles)): ?>
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header"><h5 class="modal-title">Create New User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6 col-md-4"><label class="form-label text-black">First Name</label><input type="text" name="first_name" class="form-control" required></div>
                        <div class="col-6 col-md-4"><label class="form-label text-black">Last Name</label><input type="text" name="last_name" class="form-control" required></div>
                        <div class="col-12 col-md-8"><label class="form-label text-black">Username</label><input type="text" name="username" class="form-control" required maxlength="50"></div>
                        <div class="col-12 col-md-8"><label class="form-label text-black">Password</label><input type="password" name="password" class="form-control" required minlength="<?= getPasswordMinLength() ?>"></div>
                        <div class="col-12 col-md-8"><label class="form-label text-black">Email</label><input type="email" name="email" class="form-control"></div>
                        <div class="col-12 col-md-8">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select" required id="createUserRole">
                                <?php foreach ($availableCreateRoles as $roleOption): ?>
                                <option value="<?= e($roleOption) ?>"><?= e(ucfirst($roleOption)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-8 d-none" id="createStudentIdWrap">
                            <label class="form-label">Student ID</label>
                            <input type="text" name="student_id_no" id="createStudentIdInput" class="form-control" placeholder="C-22-1234" pattern="[A-Z]-[0-9]{2}-[0-9]{2,6}" maxlength="11" style="text-transform:uppercase;">
                            <small class="text-muted">Format: C-22-1234 (capital letter, 2-digit year, 2-6 digits).</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-gradient">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="studentIdModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_student_id">
                <input type="hidden" name="user_id" id="studentIdUserId" value="">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="fas fa-id-card me-2"></i>Student ID</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2" style="font-size:0.85rem;">
                        Student: <strong id="studentIdStudentName"></strong>
                        <div class="text-muted" id="studentIdUsername"></div>
                    </div>
                    <label class="form-label fw-bold">Student ID</label>
                    <input type="text" name="student_id_no" id="studentIdInput" class="form-control" required pattern="[A-Z]-[0-9]{2}-[0-9]{2,6}" maxlength="11" placeholder="C-22-1234" style="text-transform:uppercase;">
                    <small class="text-muted">Format: C-22-1234 (capital letter, 2-digit year, 2-6 digits).</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-sm btn-md-lg" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm btn-md-lg"><i class="fas fa-save me-1"></i>Save ID</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const createUserRole = document.getElementById('createUserRole');
const createStudentIdWrap = document.getElementById('createStudentIdWrap');
const createStudentIdInput = document.getElementById('createStudentIdInput');
const createUserModal = document.getElementById('createUserModal');
const studentIdModal = document.getElementById('studentIdModal');
const studentIdInput = document.getElementById('studentIdInput');

function normalizeStudentIdInput(input) {
    if (!input) return;
    input.value = input.value.toUpperCase().replace(/\s+/g, '');
}

function syncCreateStudentIdField() {
    if (!createUserRole || !createStudentIdWrap || !createStudentIdInput) return;
    const isStudent = createUserRole.value === 'student';
    createStudentIdWrap.classList.toggle('d-none', !isStudent);
    createStudentIdInput.required = isStudent;
    if (!isStudent) {
        createStudentIdInput.value = '';
    }
}

createUserRole?.addEventListener('change', syncCreateStudentIdField);
createStudentIdInput?.addEventListener('input', function() {
    normalizeStudentIdInput(this);
});
studentIdInput?.addEventListener('input', function() {
    normalizeStudentIdInput(this);
});
createUserModal?.addEventListener('shown.bs.modal', syncCreateStudentIdField);
createUserModal?.addEventListener('hidden.bs.modal', syncCreateStudentIdField);
studentIdModal?.addEventListener('show.bs.modal', function(e) {
    const btn = e.relatedTarget;
    if (!btn) return;
    document.getElementById('studentIdUserId').value = btn.dataset.uid;
    document.getElementById('studentIdStudentName').textContent = btn.dataset.name;
    document.getElementById('studentIdUsername').textContent = btn.dataset.uname;
    studentIdInput.value = (btn.dataset.studentid || '').toUpperCase();
});
syncCreateStudentIdField();
</script>

<?php if ($user['role'] === 'superadmin'): ?>
<!-- Reset Password Modal -->
<div class="modal fade" id="resetPwModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetPwUserId" value="">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="fas fa-key me-2"></i>Reset Password</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2" style="font-size:0.85rem;">
                        User: <strong id="resetPwUsername"></strong>
                    </div>
                    <label class="form-label fw-bold">New Password</label>
                    <div class="input-group">
                        <input type="password" name="new_password" id="resetPwInput" class="form-control" required minlength="<?= getPasswordMinLength() ?>" placeholder="Min <?= getPasswordMinLength() ?> characters">
                        <button type="button" class="btn btn-outline-secondary" id="generatePwBtn" title="Generate random password"><i class="fas fa-random"></i></button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-sm btn-md-lg" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm btn-md-lg"><i class="fas fa-save me-1"></i>Reset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// View Password popover
document.querySelectorAll('.view-pw-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const pw = this.dataset.pw || '(not stored)';
        const uname = this.dataset.uname;
        // Remove any existing popover
        document.querySelectorAll('.pw-popover').forEach(p => p.remove());
        const pop = document.createElement('div');
        pop.className = 'pw-popover';
        pop.style.cssText = 'position:absolute;z-index:9999;background:#fff;border:1px solid #E2E8F0;border-radius:8px;padding:10px 14px;box-shadow:0 4px 12px rgba(0,0,0,0.12);font-size:0.82rem;min-width:180px;';
        pop.innerHTML = `<div class="d-flex justify-content-between align-items-center mb-1"><strong style="font-size:0.78rem;color:var(--gray-500);">${uname}</strong><button class="btn btn-sm btn-md-lg p-0 border-0 text-muted pw-pop-close" style="font-size:0.7rem;"><i class="fas fa-times"></i></button></div><div class="d-flex align-items-center gap-2"><code style="font-size:0.95rem;background:#F8FAFC;padding:4px 8px;border-radius:4px;letter-spacing:0.5px;user-select:all;">${pw.replace(/</g,'&lt;')}</code><button class="btn btn-sm btn-md-lg p-0 border-0 text-primary pw-copy-btn" title="Copy"><i class="fas fa-copy"></i></button></div>`;
        document.body.appendChild(pop);
        const rect = this.getBoundingClientRect();
        pop.style.top = (rect.bottom + window.scrollY + 6) + 'px';
        pop.style.left = (rect.left + window.scrollX - 40) + 'px';
        pop.querySelector('.pw-pop-close').addEventListener('click', () => pop.remove());
        pop.querySelector('.pw-copy-btn').addEventListener('click', () => {
            navigator.clipboard.writeText(pw).then(() => {
                pop.querySelector('.pw-copy-btn').innerHTML = '<i class="fas fa-check text-success"></i>';
                setTimeout(() => pop.remove(), 1000);
            });
        });
        setTimeout(() => { if (pop.parentNode) pop.remove(); }, 8000);
        document.addEventListener('click', function dismiss(e) {
            if (!pop.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
                pop.remove();
                document.removeEventListener('click', dismiss);
            }
        });
    });
});

// Reset Password modal - populate user info
document.getElementById('resetPwModal').addEventListener('show.bs.modal', function(e) {
    const btn = e.relatedTarget;
    document.getElementById('resetPwUserId').value = btn.dataset.uid;
    document.getElementById('resetPwUsername').textContent = btn.dataset.uname;
    document.getElementById('resetPwInput').value = '';
});

// Generate random password
document.getElementById('generatePwBtn').addEventListener('click', function() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$';
    let pw = '';
    for (let i = 0; i < 10; i++) pw += chars.charAt(Math.floor(Math.random() * chars.length));
    document.getElementById('resetPwInput').value = pw;
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
