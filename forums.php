<?php
$pageTitle = 'Forums';
require_once __DIR__ . '/helpers/functions.php';
requireLogin();

$user = currentUser();
$pdo = getDB();
$role = $user['role'];
$isAdmin = in_array($role, ['superadmin', 'staff']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Invalid CSRF token.');
        redirect('/forums.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_category' && $isAdmin) {
        $name = trim($_POST['category_name'] ?? '');
        $desc = trim($_POST['category_description'] ?? '');
        if ($name === '') {
            flash('error', 'Category name is required.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO forum_categories (name, description, created_by) VALUES (?, ?, ?)");
            $stmt->execute([$name, $desc, $user['id']]);
            auditLog('forum_category_create', "Created category: $name");
            flash('success', 'Category created successfully.');
        }
        redirect('/forums.php');
    }

    if ($action === 'edit_category' && $isAdmin) {
        $catId = (int)($_POST['category_id'] ?? 0);
        $name = trim($_POST['category_name'] ?? '');
        $desc = trim($_POST['category_description'] ?? '');
        if ($name === '' || $catId < 1) {
            flash('error', 'Invalid input.');
        } else {
            $stmt = $pdo->prepare("UPDATE forum_categories SET name = ?, description = ? WHERE id = ?");
            $stmt->execute([$name, $desc, $catId]);
            auditLog('forum_category_edit', "Edited category #$catId: $name");
            flash('success', 'Category updated.');
        }
        redirect('/forums.php');
    }

    if ($action === 'delete_category' && $isAdmin) {
        $catId = (int)($_POST['category_id'] ?? 0);
        if ($catId > 0) {
            $stmt = $pdo->prepare("DELETE FROM forum_categories WHERE id = ?");
            $stmt->execute([$catId]);
            auditLog('forum_category_delete', "Deleted category #$catId");
            flash('success', 'Category deleted.');
        }
        redirect('/forums.php');
    }

    if ($action === 'pin_thread' && $isAdmin) {
        $tid = (int)($_POST['thread_id'] ?? 0);
        $pdo->prepare("UPDATE forum_threads SET is_pinned = NOT is_pinned WHERE id = ?")->execute([$tid]);
        auditLog('forum_thread_pin', "Toggled pin on thread #$tid");
        flash('success', 'Thread pin toggled.');
        redirect('/forums.php' . (isset($_GET['category']) ? '?category=' . (int)$_GET['category'] : ''));
    }

    if ($action === 'lock_thread' && $isAdmin) {
        $tid = (int)($_POST['thread_id'] ?? 0);
        $pdo->prepare("UPDATE forum_threads SET is_locked = NOT is_locked WHERE id = ?")->execute([$tid]);
        auditLog('forum_thread_lock', "Toggled lock on thread #$tid");
        flash('success', 'Thread lock toggled.');
        redirect('/forums.php' . (isset($_GET['category']) ? '?category=' . (int)$_GET['category'] : ''));
    }

    if ($action === 'hide_thread' && $isAdmin) {
        $tid = (int)($_POST['thread_id'] ?? 0);
        $pdo->prepare("UPDATE forum_threads SET status = 'hidden' WHERE id = ?")->execute([$tid]);
        auditLog('forum_thread_hide', "Hid thread #$tid");
        flash('success', 'Thread hidden.');
        redirect('/forums.php' . (isset($_GET['category']) ? '?category=' . (int)$_GET['category'] : ''));
    }

    if ($action === 'delete_thread') {
        $tid = (int)($_POST['thread_id'] ?? 0);
        $thread = $pdo->prepare("SELECT * FROM forum_threads WHERE id = ?");
        $thread->execute([$tid]);
        $thread = $thread->fetch();
        if ($thread && ($isAdmin || $thread['created_by'] == $user['id'])) {
            $pdo->prepare("UPDATE forum_threads SET status = 'deleted' WHERE id = ?")->execute([$tid]);
            auditLog('forum_thread_delete', "Deleted thread #$tid");
            flash('success', 'Thread deleted.');
        }
        redirect('/forums.php' . (isset($_GET['category']) ? '?category=' . (int)$_GET['category'] : ''));
    }
}

$categories = $pdo->query("SELECT * FROM forum_categories WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll();
$categoryId = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$search = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'latest';

$where = ["t.status = 'active'"];
$params = [];

if ($categoryId > 0) {
    $where[] = "t.category_id = ?";
    $params[] = $categoryId;
}
if ($search !== '') {
    $where[] = "(t.title LIKE ? OR t.body LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereClause = implode(' AND ', $where);

switch ($sort) {
    case 'replies':
        $orderBy = "t.is_pinned DESC, t.reply_count DESC, t.created_at DESC";
        break;
    case 'oldest':
        $orderBy = "t.is_pinned DESC, t.created_at ASC";
        break;
    default:
        $orderBy = "t.is_pinned DESC, t.last_reply_at DESC, t.created_at DESC";
        break;
}

$stmt = $pdo->prepare("
    SELECT t.*, c.name as category_name,
           u.first_name, u.last_name, u.role as author_role, u.forum_karma, u.profile_picture
    FROM forum_threads t
    JOIN forum_categories c ON t.category_id = c.id
    JOIN users u ON t.created_by = u.id
    WHERE $whereClause
    ORDER BY $orderBy
");
$stmt->execute($params);
$threads = $stmt->fetchAll();

$catStats = [];
foreach ($categories as $cat) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM forum_threads WHERE category_id = ? AND status = 'active'");
    $s->execute([$cat['id']]);
    $catStats[$cat['id']] = $s->fetchColumn();
}

$breadcrumbPills = ['Community'];
if ($categoryId > 0) {
    foreach ($categories as $cat) {
        if ($cat['id'] == $categoryId) {
            $breadcrumbPills[] = $cat['name'];
            break;
        }
    }
}

require_once __DIR__ . '/views/layouts/header.php';

$catColors = ['#4F46E5','#0EA5E9','#10B981','#F59E0B','#EF4444','#8B5CF6','#EC4899','#14B8A6','#F97316','#6366F1'];
$catIcons  = ['fa-comments','fa-lightbulb','fa-book','fa-bullhorn','fa-question-circle','fa-code','fa-graduation-cap','fa-star','fa-flag','fa-globe'];

$totalThreads = array_sum($catStats);
$totalReplies = $pdo->query("SELECT COALESCE(SUM(reply_count),0) FROM forum_threads WHERE status='active'")->fetchColumn();
$totalUsers   = $pdo->query("SELECT COUNT(DISTINCT created_by) FROM forum_threads WHERE status='active'")->fetchColumn();
?>

<div class="forum-hero mb-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h2 class="fw-800 mb-1" style="letter-spacing:-0.5px;">Community Forum</h2>
            <p class="text-muted mb-0" style="font-size:0.9rem;">Join the conversation, share ideas, and connect with the community</p>
        </div>
        <a href="<?= BASE_URL ?>/forum-create.php<?= $categoryId ? '?category=' . $categoryId : '' ?>" class="btn btn-primary px-4 py-2 fw-600" style="border-radius:10px;">
            <i class="fas fa-pen-to-square me-2"></i>New Thread
        </a>
    </div>
    <div class="row g-3 mt-3">
        <div class="col-auto">
            <div class="forum-stat-chip">
                <i class="fas fa-layer-group"></i>
                <span><strong><?= count($categories) ?></strong> Categories</span>
            </div>
        </div>
        <div class="col-auto">
            <div class="forum-stat-chip">
                <i class="fas fa-message"></i>
                <span><strong><?= $totalThreads ?></strong> Threads</span>
            </div>
        </div>
        <div class="col-auto">
            <div class="forum-stat-chip">
                <i class="fas fa-reply-all"></i>
                <span><strong><?= $totalReplies ?></strong> Replies</span>
            </div>
        </div>
        <div class="col-auto">
            <div class="forum-stat-chip">
                <i class="fas fa-users"></i>
                <span><strong><?= $totalUsers ?></strong> Contributors</span>
            </div>
        </div>
    </div>
</div>

<?php if ($categoryId === 0): ?>
<div class="mb-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-700 mb-0"><i class="fas fa-th-large me-2 text-primary"></i>Browse Categories</h5>
        <?php if ($isAdmin): ?>
        <button class="btn btn-sm btn-outline-primary" style="border-radius:8px;" data-bs-toggle="modal" data-bs-target="#categoryModal" onclick="resetCategoryModal()">
            <i class="fas fa-plus me-1"></i>Add Category
        </button>
        <?php endif; ?>
    </div>
    <div class="row g-3">
        <?php foreach ($categories as $idx => $cat): 
            $color = $catColors[$idx % count($catColors)];
            $icon  = $catIcons[$idx % count($catIcons)];
            $count = $catStats[$cat['id']] ?? 0;
        ?>
        <div class="col-md-6 col-lg-4">
            <a href="<?= BASE_URL ?>/forums.php?category=<?= $cat['id'] ?>" class="text-decoration-none">
                <div class="forum-category-card" style="--cat-color: <?= $color ?>;">
                    <?php if ($isAdmin): ?>
                    <div class="dropdown" style="position:absolute; top:12px; right:12px; z-index:5;" onclick="event.preventDefault(); event.stopPropagation();">
                        <button class="btn btn-sm p-1 text-muted" data-bs-toggle="dropdown" style="line-height:1;background:none;border:none;"><i class="fas fa-ellipsis-v" style="font-size:0.7rem;"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="border-radius:10px; min-width:140px;">
                            <li><a class="dropdown-item py-2" href="#" onclick="editCategory(<?= $cat['id'] ?>, <?= e(json_encode($cat['name'])) ?>, <?= e(json_encode($cat['description'] ?? '')) ?>)"><i class="fas fa-edit me-2 text-primary"></i>Edit</a></li>
                            <li>
                                <form method="POST" onsubmit="return confirmForm(this, 'Delete this category and all its threads?', 'Delete Category')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                    <button type="submit" class="dropdown-item py-2 text-danger"><i class="fas fa-trash me-2"></i>Delete</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex align-items-start gap-3">
                        <div class="forum-cat-icon" style="background: <?= $color ?>15; color: <?= $color ?>;">
                            <i class="fas <?= $icon ?>"></i>
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <h6 class="fw-700 mb-1 text-dark" style="font-size:0.88rem;"><?= e($cat['name']) ?></h6>
                            <?php if ($cat['description']): ?>
                            <p class="text-muted mb-0" style="font-size:0.75rem; line-height:1.4;"><?= e(mb_strimwidth($cat['description'], 0, 70, '...')) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-3 mt-3 pt-2" style="border-top: 1px solid var(--gray-100);">
                        <span class="forum-cat-stat"><i class="fas fa-message me-1"></i><?= $count ?> thread<?= $count !== 1 ? 's' : '' ?></span>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<?php
    $activeCat = null;
    $activeCatIdx = 0;
    foreach ($categories as $idx => $cat) {
        if ($cat['id'] == $categoryId) { $activeCat = $cat; $activeCatIdx = $idx; break; }
    }
?>
<?php if ($activeCat): ?>
<div class="forum-active-category mb-4" style="--cat-color: <?= $catColors[$activeCatIdx % count($catColors)] ?>;">
    <div class="d-flex align-items-center gap-3">
        <a href="<?= BASE_URL ?>/forums.php" class="btn btn-sm btn-light" style="border-radius:8px;">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div class="forum-cat-icon" style="background: <?= $catColors[$activeCatIdx % count($catColors)] ?>15; color: <?= $catColors[$activeCatIdx % count($catColors)] ?>;">
            <i class="fas <?= $catIcons[$activeCatIdx % count($catIcons)] ?>"></i>
        </div>
        <div class="flex-grow-1">
            <h5 class="fw-700 mb-0" style="font-size:1rem;"><?= e($activeCat['name']) ?></h5>
            <?php if ($activeCat['description']): ?>
            <p class="text-muted mb-0" style="font-size:0.78rem;"><?= e($activeCat['description']) ?></p>
            <?php endif; ?>
        </div>
        <span class="forum-cat-stat-pill"><?= $catStats[$categoryId] ?? 0 ?> threads</span>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="forum-threads-section">
    <div class="forum-toolbar mb-3">
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <h6 class="fw-700 mb-0 me-2" style="font-size:0.88rem;">
                    <i class="fas fa-stream me-1 text-primary"></i>
                    <?= $categoryId > 0 ? 'Threads' : 'Recent Discussions' ?>
                </h6>
                <div class="forum-sort-tabs">
                    <a href="?<?= $categoryId ? "category=$categoryId&" : '' ?>sort=latest" class="forum-sort-tab <?= $sort === 'latest' ? 'active' : '' ?>">
                        <i class="fas fa-clock me-1"></i>Latest
                    </a>
                    <a href="?<?= $categoryId ? "category=$categoryId&" : '' ?>sort=replies" class="forum-sort-tab <?= $sort === 'replies' ? 'active' : '' ?>">
                        <i class="fas fa-fire me-1"></i>Popular
                    </a>
                    <a href="?<?= $categoryId ? "category=$categoryId&" : '' ?>sort=oldest" class="forum-sort-tab <?= $sort === 'oldest' ? 'active' : '' ?>">
                        <i class="fas fa-history me-1"></i>Oldest
                    </a>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <form method="GET" class="forum-search-box">
                    <?php if ($categoryId): ?><input type="hidden" name="category" value="<?= $categoryId ?>"><?php endif; ?>
                    <i class="fas fa-search forum-search-icon"></i>
                    <input type="text" name="search" class="form-control" placeholder="Search threads..." value="<?= e($search) ?>">
                </form>
                <a href="<?= BASE_URL ?>/forum-create.php<?= $categoryId ? '?category=' . $categoryId : '' ?>" class="btn btn-primary btn-sm fw-600 d-flex align-items-center gap-1" style="border-radius:8px; white-space:nowrap; padding:6px 14px;">
                    <i class="fas fa-plus"></i><span class="d-none d-sm-inline">New Thread</span>
                </a>
            </div>
        </div>
    </div>

    <?php if (empty($threads)): ?>
    <div class="forum-empty-state">
        <div class="forum-empty-icon">
            <i class="far fa-comment-dots"></i>
        </div>
        <h5 class="fw-700 mb-2">No threads yet</h5>
        <p class="text-muted mb-3" style="font-size:0.88rem;">Start the conversation — be the first to post in this community!</p>
        <a href="<?= BASE_URL ?>/forum-create.php<?= $categoryId ? '?category=' . $categoryId : '' ?>" class="btn btn-primary px-4" style="border-radius:10px;">
            <i class="fas fa-pen me-1"></i>Create First Thread
        </a>
    </div>
    <?php else: ?>
    <div class="forum-thread-list">
        <?php foreach ($threads as $t): ?>
        <div class="forum-thread-row <?= $t['is_pinned'] ? 'pinned' : '' ?>">
            <div class="d-flex gap-3">
                <div class="flex-shrink-0 forum-avatar-col">
                    <?php if ($t['is_anonymous']): ?>
                        <div class="avatar-circle bg-gradient" style="background: linear-gradient(135deg, var(--gray-400), var(--gray-500));"><i class="fas fa-user-secret"></i></div>
                    <?php else:
                        $threadAvatar = getUserAvatarUrl($t);
                        if ($threadAvatar): ?>
                            <img src="<?= e($threadAvatar) ?>" alt="<?= e($t['first_name'] . ' ' . $t['last_name']) ?>" class="avatar-circle" style="object-fit:cover;">
                        <?php else: ?>
                            <div class="avatar-circle" style="background: linear-gradient(135deg, <?= $catColors[crc32($t['first_name']) % count($catColors)] ?>, <?= $catColors[(crc32($t['last_name'])+3) % count($catColors)] ?>);">
                                <?= strtoupper(substr($t['first_name'], 0, 1) . substr($t['last_name'], 0, 1)) ?>
                            </div>
                        <?php endif;
                    endif; ?>
                </div>

                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <div class="min-w-0">
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                <?php if ($t['is_pinned']): ?>
                                    <span class="forum-badge-pin"><i class="fas fa-thumbtack me-1"></i>Pinned</span>
                                <?php endif; ?>
                                <?php if ($t['is_locked']): ?>
                                    <span class="forum-badge-lock"><i class="fas fa-lock me-1"></i>Locked</span>
                                <?php endif; ?>
                                <a href="<?= BASE_URL ?>/forum-thread.php?id=<?= $t['id'] ?>" class="forum-thread-link">
                                    <?= e($t['title']) ?>
                                </a>
                            </div>
                            <div class="forum-thread-meta">
                                <a href="<?= BASE_URL ?>/forums.php?category=<?= $t['category_id'] ?>" class="forum-cat-label" style="--cat-c: <?= $catColors[($t['category_id'] - 1) % count($catColors)] ?>;">
                                    <?= e($t['category_name']) ?>
                                </a>
                                <span class="forum-meta-sep">&middot;</span>
                                <span><?= $t['is_anonymous'] ? '<i class="fas fa-user-secret me-1"></i>' . ($t['anon_display_name'] ? e($t['anon_display_name']) : 'Anonymous') : e($t['first_name'] . ' ' . $t['last_name']) ?></span>
                                <?php $tKarma = getForumKarmaLabel($t['forum_karma']); ?>
                                <span class="badge" style="font-size:0.5rem;background:<?= $tKarma['color'] ?>;color:#fff;vertical-align:middle;"><i class="fas <?= $tKarma['icon'] ?> me-1"></i><?= $tKarma['label'] ?></span>
                                <span class="forum-meta-sep">&middot;</span>
                                <span><i class="far fa-clock me-1"></i><?= date('M d, Y g:ia', strtotime($t['created_at'])) ?></span>
                            </div>
                        </div>

                        <?php if ($isAdmin || $t['created_by'] == $user['id']): ?>
                        <div class="dropdown flex-shrink-0">
                            <button class="forum-action-btn" data-bs-toggle="dropdown"><i class="fas fa-ellipsis"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="border-radius:10px; min-width:160px;">
                                <?php if ($t['created_by'] == $user['id'] || $isAdmin): ?>
                                <li><a class="dropdown-item py-2" href="<?= BASE_URL ?>/forum-create.php?edit=<?= $t['id'] ?>"><i class="fas fa-edit me-2 text-primary"></i>Edit</a></li>
                                <?php endif; ?>
                                <?php if ($isAdmin): ?>
                                <li>
                                    <form method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="pin_thread">
                                        <input type="hidden" name="thread_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="dropdown-item py-2"><i class="fas fa-thumbtack me-2 text-warning"></i><?= $t['is_pinned'] ? 'Unpin' : 'Pin' ?></button>
                                    </form>
                                </li>
                                <li>
                                    <form method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="lock_thread">
                                        <input type="hidden" name="thread_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="dropdown-item py-2"><i class="fas fa-lock me-2 text-secondary"></i><?= $t['is_locked'] ? 'Unlock' : 'Lock' ?></button>
                                    </form>
                                </li>
                                <li>
                                    <form method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="hide_thread">
                                        <input type="hidden" name="thread_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="dropdown-item py-2"><i class="fas fa-eye-slash me-2 text-warning"></i>Hide</button>
                                    </form>
                                </li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="POST" onsubmit="return confirmForm(this,'Delete this thread permanently?','Delete Thread')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_thread">
                                        <input type="hidden" name="thread_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="dropdown-item py-2 text-danger"><i class="fas fa-trash me-2"></i>Delete</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="forum-thread-stats flex-shrink-0 d-none d-lg-flex">
                    <div class="forum-stat-box" title="Replies">
                        <span class="forum-stat-num"><?= $t['reply_count'] ?></span>
                        <span class="forum-stat-label"><i class="far fa-comment"></i></span>
                    </div>
                    <div class="forum-stat-box" title="Views">
                        <span class="forum-stat-num"><?= $t['view_count'] ?></span>
                        <span class="forum-stat-label"><i class="far fa-eye"></i></span>
                    </div>
                </div>
            </div>

            <div class="d-flex d-lg-none gap-3 mt-2 ps-0 ps-md-5 ms-0 ms-md-3">
                <span class="forum-mobile-stat"><i class="far fa-comment me-1"></i><?= $t['reply_count'] ?></span>
                <span class="forum-mobile-stat"><i class="far fa-eye me-1"></i><?= $t['view_count'] ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <form method="POST" id="categoryForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="catAction" value="create_category">
                <input type="hidden" name="category_id" id="catId" value="">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="catModalTitle"><i class="fas fa-folder-plus me-2 text-primary"></i>New Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-3">
                    <div class="mb-3">
                        <label class="form-label fw-600" style="font-size:0.85rem;">Category Name</label>
                        <input type="text" name="category_name" id="catName" class="form-control" required maxlength="200" placeholder="e.g. General Discussion" style="border-radius:10px;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-600" style="font-size:0.85rem;">Description <small class="text-muted">(optional)</small></label>
                        <textarea name="category_description" id="catDesc" class="form-control" rows="3" maxlength="500" placeholder="Briefly describe the category..." style="border-radius:10px;"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" style="border-radius:10px;">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" style="border-radius:10px;"><i class="fas fa-check me-1"></i>Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function resetCategoryModal() {
    document.getElementById('catAction').value = 'create_category';
    document.getElementById('catId').value = '';
    document.getElementById('catName').value = '';
    document.getElementById('catDesc').value = '';
    document.getElementById('catModalTitle').innerHTML = '<i class="fas fa-folder-plus me-2 text-primary"></i>New Category';
}
function editCategory(id, name, desc) {
    document.getElementById('catAction').value = 'edit_category';
    document.getElementById('catId').value = id;
    document.getElementById('catName').value = name;
    document.getElementById('catDesc').value = desc;
    document.getElementById('catModalTitle').innerHTML = '<i class="fas fa-edit me-2 text-primary"></i>Edit Category';
    new bootstrap.Modal(document.getElementById('categoryModal')).show();
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
