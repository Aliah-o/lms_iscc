<?php
require_once __DIR__ . '/../../helpers/functions.php';
requireLogin();
$user = currentUser();
$role = $user['role'];
$pageTitle = $pageTitle ?? 'Dashboard';
$currentPage = basename($_SERVER['PHP_SELF']);
$appName = getAppName();
$appLogoUrl = getAppLogoUrl();
$themeConfig = getThemeConfig();
$avatarUrl = getUserAvatarUrl($user);
$userInitials = getUserInitials($user);
$themeCssVars = '';
foreach ($themeConfig['vars'] as $cssVar => $cssValue) {
    $themeCssVars .= $cssVar . ':' . $cssValue . ';';
}
$ticketNotificationCount = 0;
try {
    $ticketNotificationCount = (int)getUnreadTicketNotificationCount($user['id']);
} catch (Exception $e) {
    $ticketNotificationCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="ISCC Learning Management System">
    <title><?= e($pageTitle) ?> - <?= e($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?= e($appLogoUrl) ?>">
    <style>
        :root { <?= $themeCssVars ?> }
        body {
            background: var(--body-bg);
            color: var(--body-text);
        }
        body[data-theme="dark"] .btn-light,
        body[data-theme="dark"] .dropdown-menu,
        body[data-theme="dark"] .modal-content,
        body[data-theme="dark"] .form-control,
        body[data-theme="dark"] .form-select,
        body[data-theme="dark"] .input-group-text,
        body[data-theme="dark"] .page-link,
        body[data-theme="dark"] .table,
        body[data-theme="dark"] .nav-tabs .nav-link,
        body[data-theme="dark"] .nav-pills .nav-link,
        body[data-theme="dark"] .list-group-item {
            background-color: var(--surface-bg);
            color: var(--body-text);
            border-color: var(--surface-border);
        }
        body[data-theme="dark"] .btn-outline-secondary,
        body[data-theme="dark"] .btn-outline-primary,
        body[data-theme="dark"] .btn-outline-info,
        body[data-theme="dark"] .btn-outline-warning,
        body[data-theme="dark"] .btn-outline-success,
        body[data-theme="dark"] .btn-outline-danger {
            color: var(--body-text);
            border-color: var(--surface-border);
        }
        body[data-theme="dark"] .btn-light:hover,
        body[data-theme="dark"] .dropdown-item:hover,
        body[data-theme="dark"] .page-link:hover,
        body[data-theme="dark"] .nav-tabs .nav-link:hover {
            background-color: var(--surface-muted);
            color: var(--body-heading);
        }
    </style>
</head>
<body data-theme="<?= e($themeConfig['mode']) ?>">
<div class="app-wrapper">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon"><img src="<?= e($appLogoUrl) ?>" alt="<?= e($appName) ?> logo" style="width:100%;height:100%;object-fit:contain;border-radius:6px;"></div>
            <div class="brand-text"><?= e($appName) ?><small>Learning Management</small></div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-label">Main</div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
                    <i class="fas fa-th-large"></i> Dashboard
                </a>
            </div>

            <?php if ($role === 'superadmin'): ?>
            <div class="nav-label">Administration</div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/users.php" class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>">
                    <i class="fas fa-users-cog"></i> User Management
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/programs.php" class="nav-link <?= $currentPage === 'programs.php' ? 'active' : '' ?>">
                    <i class="fas fa-university"></i> Programs
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/sections.php" class="nav-link <?= $currentPage === 'sections.php' ? 'active' : '' ?>">
                    <i class="fas fa-layer-group"></i> Sections
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/badges.php" class="nav-link <?= $currentPage === 'badges.php' ? 'active' : '' ?>">
                    <i class="fas fa-award"></i> Badge Management
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/grades.php" class="nav-link <?= $currentPage === 'grades.php' ? 'active' : '' ?>">
                    <i class="fas fa-clipboard-check"></i> Grades
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/attendance.php" class="nav-link <?= $currentPage === 'attendance.php' ? 'active' : '' ?>">
                    <i class="fas fa-calendar-check"></i> Attendance
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/growth-tracker.php" class="nav-link <?= $currentPage === 'growth-tracker.php' ? 'active' : '' ?>">
                    <i class="fas fa-chart-line"></i> Growth Tracker
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/meetings.php" class="nav-link <?= $currentPage === 'meetings.php' ? 'active' : '' ?>">
                    <i class="fas fa-video"></i> Meetings
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/settings.php" class="nav-link <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/audit-logs.php" class="nav-link <?= $currentPage === 'audit-logs.php' ? 'active' : '' ?>">
                    <i class="fas fa-clipboard-list"></i> Audit Logs
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/blockchain.php" class="nav-link <?= $currentPage === 'blockchain.php' ? 'active' : '' ?>">
                    <i class="fas fa-shield-alt"></i> Security
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/kahoot-games.php" class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['kahoot-games.php','kahoot-create.php','kahoot-host.php','kahoot-results.php']) ? 'active' : '' ?>">
                    <i class="fas fa-gamepad"></i> Live Quiz
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/tickets.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'tickets.php' ? 'active' : '' ?>">
                    <i class="fas fa-ticket-alt"></i> Support Tickets
                    <?php if ($ticketNotificationCount > 0): ?>
                    <span class="badge bg-danger ms-1" style="font-size:0.65rem;"><?= $ticketNotificationCount ?></span>
                    <?php endif; ?>
                </a>
            </div>
            <div class="nav-label">Community</div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/forums.php" class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['forums.php','forum-thread.php','forum-create.php']) ? 'active' : '' ?>">
                    <i class="fas fa-comments"></i> Forums
                    <span class="badge bg-danger ms-1 forum-notif-badge" style="font-size:0.6rem;display:none;"></span>
                </a>
            </div>
            <?php endif; ?>

            <?php if ($role === 'staff'): ?>
            <div class="nav-label">Academic Management</div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/programs.php" class="nav-link <?= $currentPage === 'programs.php' ? 'active' : '' ?>">
                    <i class="fas fa-university"></i> Programs
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/sections.php" class="nav-link <?= $currentPage === 'sections.php' ? 'active' : '' ?>">
                    <i class="fas fa-layer-group"></i> Sections
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/assignments.php" class="nav-link <?= $currentPage === 'assignments.php' ? 'active' : '' ?>">
                    <i class="fas fa-user-check"></i> Assignments
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/users.php" class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>">
                    <i class="fas fa-users"></i> Users
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/growth-tracker.php" class="nav-link <?= $currentPage === 'growth-tracker.php' ? 'active' : '' ?>">
                    <i class="fas fa-chart-line"></i> Growth Tracker
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/audit-logs.php" class="nav-link <?= $currentPage === 'audit-logs.php' ? 'active' : '' ?>">
                    <i class="fas fa-clipboard-list"></i> Audit Logs
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/blockchain.php" class="nav-link <?= $currentPage === 'blockchain.php' ? 'active' : '' ?>">
                    <i class="fas fa-shield-alt"></i> Security
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/kahoot-games.php" class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['kahoot-games.php','kahoot-create.php','kahoot-host.php','kahoot-results.php']) ? 'active' : '' ?>">
                    <i class="fas fa-gamepad"></i> Live Quiz
                </a>
            </div>
            <div class="nav-label">Community</div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/forums.php" class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['forums.php','forum-thread.php','forum-create.php']) ? 'active' : '' ?>">
                    <i class="fas fa-comments"></i> Forums
                    <span class="badge bg-danger ms-1 forum-notif-badge" style="font-size:0.6rem;display:none;"></span>
                </a>
            </div>
            <?php endif; ?>

            <?php if ($role === 'instructor'): ?>
            <div class="nav-label">Teaching</div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/classes.php" class="nav-link <?= $currentPage === 'classes.php' ? 'active' : '' ?>">
                    <i class="fas fa-person-chalkboard"></i> My Classes
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/subjects.php" class="nav-link <?= $currentPage === 'subjects.php' ? 'active' : '' ?>">
                    <i class="fas fa-plus-circle"></i> Create Subject
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/join-class.php" class="nav-link <?= $currentPage === 'join-class.php' ? 'active' : '' ?>">
                    <i class="fas fa-user-check"></i> Join Requests
                    <?php
                    $pendingCount = 0;
                    try {
                        $pcStmt = getDB()->prepare("SELECT COUNT(*) FROM class_join_requests cjr JOIN instructor_classes tc ON cjr.class_id = tc.id WHERE tc.instructor_id = ? AND cjr.status = 'pending'");
                        $pcStmt->execute([$user['id']]);
                        $pendingCount = $pcStmt->fetchColumn();
                    } catch(Exception $e) {}
                    if ($pendingCount > 0): ?>
                    <span class="badge bg-danger ms-1" style="font-size:0.65rem;"><?= $pendingCount ?></span>
                    <?php endif; ?>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/lessons.php" class="nav-link <?= $currentPage === 'lessons.php' ? 'active' : '' ?>">
                    <i class="fas fa-book-open"></i> Lessons
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/knowledge-tree.php" class="nav-link <?= $currentPage === 'knowledge-tree.php' ? 'active' : '' ?>">
                    <i class="fas fa-sitemap"></i> Knowledge Tree
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/quizzes.php" class="nav-link <?= $currentPage === 'quizzes.php' ? 'active' : '' ?>">
                    <i class="fas fa-question-circle"></i> Quizzes
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/attendance.php" class="nav-link <?= $currentPage === 'attendance.php' ? 'active' : '' ?>">
                    <i class="fas fa-calendar-check"></i> Attendance
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/meetings.php" class="nav-link <?= $currentPage === 'meetings.php' ? 'active' : '' ?>">
                    <i class="fas fa-video"></i> Meetings
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/grades.php" class="nav-link <?= $currentPage === 'grades.php' ? 'active' : '' ?>">
                    <i class="fas fa-clipboard-check"></i> Grades
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/growth-tracker.php" class="nav-link <?= $currentPage === 'growth-tracker.php' ? 'active' : '' ?>">
                    <i class="fas fa-chart-line"></i> Growth Tracker
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/blockchain.php" class="nav-link <?= $currentPage === 'blockchain.php' ? 'active' : '' ?>">
                    <i class="fas fa-shield-alt"></i> Security
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/kahoot-games.php" class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['kahoot-games.php','kahoot-create.php','kahoot-host.php','kahoot-results.php']) ? 'active' : '' ?>">
                    <i class="fas fa-gamepad"></i> Live Quiz
                </a>
            </div>
            <div class="nav-label">Support</div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/tickets.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'tickets.php' ? 'active' : '' ?>">
                    <i class="fas fa-ticket-alt"></i> Support Tickets
                    <?php if ($ticketNotificationCount > 0): ?>
                    <span class="badge bg-danger ms-1" style="font-size:0.65rem;"><?= $ticketNotificationCount ?></span>
                    <?php endif; ?>
                </a>
            </div>
            <div class="nav-label">Community</div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/forums.php" class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['forums.php','forum-thread.php','forum-create.php']) ? 'active' : '' ?>">
                    <i class="fas fa-comments"></i> Forums
                    <span class="badge bg-danger ms-1 forum-notif-badge" style="font-size:0.6rem;display:none;"></span>
                </a>
            </div>
            <?php endif; ?>

            <?php if ($role === 'student'): ?>
            <div class="nav-label">My Learning</div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/classes.php" class="nav-link <?= $currentPage === 'classes.php' ? 'active' : '' ?>">
                    <i class="fas fa-book-reader"></i> My Classes
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/join-class.php" class="nav-link <?= $currentPage === 'join-class.php' ? 'active' : '' ?>">
                    <i class="fas fa-key"></i> Join a Class
                    <?php
                    $unreadNotifs = 0;
                    try { $unreadNotifs = getUnreadNotificationCount($user['id']); } catch(Exception $e) {}
                    if ($unreadNotifs > 0): ?>
                    <span class="badge bg-danger ms-1" style="font-size:0.65rem;"><?= $unreadNotifs ?></span>
                    <?php endif; ?>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/lessons.php" class="nav-link <?= $currentPage === 'lessons.php' ? 'active' : '' ?>">
                    <i class="fas fa-book-open"></i> Lessons
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/knowledge-tree.php" class="nav-link <?= $currentPage === 'knowledge-tree.php' ? 'active' : '' ?>">
                    <i class="fas fa-sitemap"></i> Knowledge Tree
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/quizzes.php" class="nav-link <?= $currentPage === 'quizzes.php' ? 'active' : '' ?>">
                    <i class="fas fa-question-circle"></i> Quizzes
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/meetings.php" class="nav-link <?= $currentPage === 'meetings.php' ? 'active' : '' ?>">
                    <i class="fas fa-video"></i> Meetings
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/grades.php" class="nav-link <?= $currentPage === 'grades.php' ? 'active' : '' ?>">
                    <i class="fas fa-clipboard-check"></i> My Grades
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/growth-tracker.php" class="nav-link <?= $currentPage === 'growth-tracker.php' ? 'active' : '' ?>">
                    <i class="fas fa-chart-line"></i> Growth Tracker
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/badges.php" class="nav-link <?= $currentPage === 'badges.php' ? 'active' : '' ?>">
                    <i class="fas fa-medal"></i> My Badges
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/kahoot-games.php" class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['kahoot-games.php','kahoot-play.php','kahoot-results.php']) ? 'active' : '' ?>">
                    <i class="fas fa-gamepad"></i> Live Quiz
                </a>
            </div>
            <div class="nav-label">Support</div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/tickets.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'tickets.php' ? 'active' : '' ?>">
                    <i class="fas fa-ticket-alt"></i> Support Tickets
                    <?php if ($ticketNotificationCount > 0): ?>
                    <span class="badge bg-danger ms-1" style="font-size:0.65rem;"><?= $ticketNotificationCount ?></span>
                    <?php endif; ?>
                </a>
            </div>
            <div class="nav-label">Community</div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/forums.php" class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['forums.php','forum-thread.php','forum-create.php']) ? 'active' : '' ?>">
                    <i class="fas fa-comments"></i> Forums
                    <span class="badge bg-danger ms-1 forum-notif-badge" style="font-size:0.6rem;display:none;"></span>
                </a>
            </div>
            <?php endif; ?>

            <div class="nav-label">Account</div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/profile.php" class="nav-link <?= $currentPage === 'profile.php' ? 'active' : '' ?>">
                    <i class="bi bi-person-circle"></i> My Profile
                </a>
            </div>
        </nav>
        <div class="sidebar-user">
            <a href="<?= BASE_URL ?>/profile.php" class="sidebar-user-link" title="Open profile">
                <?php if ($avatarUrl): ?>
                <img src="<?= e($avatarUrl) ?>" alt="<?= e($user['first_name']) ?>" class="user-avatar user-avatar-image">
                <?php else: ?>
                <div class="user-avatar"><?= e($userInitials) ?></div>
                <?php endif; ?>
                <div class="user-info">
                    <div class="name"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></div>
                    <div class="role-badge"><?= e(ROLES[$user['role']] ?? $user['role']) ?></div>
                </div>
            </a>
            <div class="sidebar-user-actions">
                <a href="<?= BASE_URL ?>/profile.php" class="text-white" title="Profile"><i class="bi bi-sliders2-vertical"></i></a>
                <a href="<?= BASE_URL ?>/logout.php" class="text-white" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
    </aside>

    <div class="main-content">
        <header class="topbar">
            <div class="context-header">
                <button class="btn-sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <h1 class="page-title"><?= e($pageTitle) ?></h1>
                <?php if (!empty($breadcrumbPills)): ?>
                <div class="breadcrumb-pills">
                    <?php foreach ($breadcrumbPills as $pill): ?>
                    <span class="pill"><?= e($pill) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="topbar-right d-flex align-items-center gap-3">
                <span class="text-muted d-none d-sm-inline" style="font-size:0.8rem;"><i class="far fa-calendar me-1"></i><?= date('M d, Y') ?></span>
                <div class="dropdown" id="notifDropdown">
                    <button class="btn btn-light btn-sm position-relative notif-bell-btn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" id="notifBellBtn">
                        <i class="fas fa-bell" style="font-size:1rem;"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notif-badge" id="notifBadge" style="font-size:0.6rem;display:none;">0</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow-lg notif-dropdown-menu" style="width:360px;max-height:440px;overflow:hidden;padding:0;border:0;border-radius:12px;">
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom" style="background:var(--gray-50);">
                            <h6 class="mb-0 fw-bold" style="font-size:0.85rem;"><i class="fas fa-bell me-2"></i>Notifications</h6>
                            <button class="btn btn-sm btn-link text-primary p-0" style="font-size:0.75rem;" onclick="markAllRead()" id="markAllBtn">Mark all read</button>
                        </div>
                        <div id="notifList" style="max-height:360px;overflow-y:auto;">
                            <div class="text-center py-4 text-muted" id="notifEmpty">
                                <i class="far fa-bell-slash fa-2x mb-2"></i>
                                <p class="mb-0" style="font-size:0.82rem;">No notifications yet</p>
                            </div>
                        </div>
                    </div>
                </div>
                <a href="<?= BASE_URL ?>/profile.php" class="topbar-profile">
                    <span class="topbar-profile-avatar">
                        <?php if ($avatarUrl): ?>
                        <img src="<?= e($avatarUrl) ?>" alt="<?= e($user['first_name']) ?>" class="topbar-profile-image">
                        <?php else: ?>
                        <?= e($userInitials) ?>
                        <?php endif; ?>
                    </span>
                    <span class="topbar-profile-meta d-none d-md-flex">
                        <strong><?= e($user['first_name']) ?></strong>
                        <small><?= e(ROLES[$user['role']] ?? $user['role']) ?></small>
                    </span>
                </a>
            </div>
        </header>
        <div class="page-content fade-in">
