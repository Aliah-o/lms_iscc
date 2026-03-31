<?php
$pageTitle = 'Thread';
require_once __DIR__ . '/helpers/functions.php';
requireLogin();

$user = currentUser();
$pdo = getDB();
$role = $user['role'];
$isAdmin = in_array($role, ['superadmin', 'staff']);

$threadId = (int)($_GET['id'] ?? 0);
if ($threadId < 1) { redirect('/forums.php'); }

$stmt = $pdo->prepare("
    SELECT t.*, c.name as category_name, c.id as category_id,
           u.first_name, u.last_name, u.role as author_role, u.forum_karma, u.profile_picture
    FROM forum_threads t
    JOIN forum_categories c ON t.category_id = c.id
    JOIN users u ON t.created_by = u.id
    WHERE t.id = ? AND t.status != 'deleted'
");
$stmt->execute([$threadId]);
$thread = $stmt->fetch();

if (!$thread) {
    flash('error', 'Thread not found.');
    redirect('/forums.php');
}

if ($thread['status'] === 'hidden' && !$isAdmin) {
    flash('error', 'This thread is not available.');
    redirect('/forums.php');
}

$pdo->prepare("UPDATE forum_threads SET view_count = view_count + 1 WHERE id = ?")->execute([$threadId]);

$pageTitle = $thread['title'];
$breadcrumbPills = ['Forums', $thread['category_name']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Invalid CSRF token.');
        redirect("/forum-thread.php?id=$threadId");
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'reply') {
        if ($thread['is_locked'] && !$isAdmin) {
            flash('error', 'This thread is locked.');
            redirect("/forum-thread.php?id=$threadId");
        }
        $body = trim($_POST['body'] ?? '');
        $isAnon = isset($_POST['is_anonymous']) ? 1 : 0;
        $replyAnonName = null;
        if ($isAnon) {
            $rawReplyAnonName = trim($_POST['reply_anon_name'] ?? '');
            if ($rawReplyAnonName !== '') {
                if (stripos($rawReplyAnonName, 'ANON-') !== 0) {
                    $rawReplyAnonName = 'ANON-' . $rawReplyAnonName;
                }
                $namePart = substr($rawReplyAnonName, 5);
                if (strlen($namePart) >= 2 && strlen($namePart) <= 30 && preg_match('/^[a-zA-Z0-9_-]+$/', $namePart)) {
                    $dupCheck = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?)");
                    $dupCheck->execute([$rawReplyAnonName]);
                    if (!$dupCheck->fetch()) {
                        $replyAnonName = $rawReplyAnonName;
                    }
                }
            }
        }

        if ($body === '') {
            flash('error', 'Reply cannot be empty.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO forum_posts (thread_id, body, created_by, is_anonymous, anon_display_name) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$threadId, $body, $user['id'], $isAnon, $isAnon ? $replyAnonName : null]);
            $newPostId = $pdo->lastInsertId();

            if (!empty($_FILES['reply_attachments']['name'][0])) {
                $allowedExtensions = ['jpg','jpeg','png','gif','webp','bmp','svg',
                    'pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','rtf','odt','ods','odp',
                    'zip','rar','7z','mp4','mp3','mov','avi','wmv','flv','mkv'];
                $maxFileSize = 25 * 1024 * 1024;
                $uploadDir = __DIR__ . '/uploads/forums/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $fileCount = count($_FILES['reply_attachments']['name']);
                for ($i = 0; $i < min($fileCount, 5); $i++) {
                    if ($_FILES['reply_attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $origName = $_FILES['reply_attachments']['name'][$i];
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $size = $_FILES['reply_attachments']['size'][$i];
                    if (!in_array($ext, $allowedExtensions) || $size > $maxFileSize) continue;

                    $safeName = uniqid('forum_') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $fileType = $_FILES['reply_attachments']['type'][$i];

                    if (move_uploaded_file($_FILES['reply_attachments']['tmp_name'][$i], $uploadDir . $safeName)) {
                        $pdo->prepare("INSERT INTO forum_attachments (thread_id, post_id, file_name, original_name, file_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$threadId, $newPostId, $safeName, $origName, $fileType, $size, $user['id']]);
                    }
                }
            }

            $pdo->prepare("UPDATE forum_threads SET reply_count = reply_count + 1, last_reply_at = NOW(), last_reply_by = ? WHERE id = ?")
                ->execute([$user['id'], $threadId]);

            if ($thread['created_by'] != $user['id']) {
                try {
                    $displayName = $isAnon ? ($replyAnonName ?: 'Someone') : ($user['first_name'] . ' ' . $user['last_name']);
                    $pdo->prepare("INSERT INTO forum_notifications (user_id, type, thread_id, post_id, triggered_by, message) VALUES (?, 'reply', ?, ?, ?, ?)")
                        ->execute([$thread['created_by'], $threadId, $newPostId, $user['id'], $displayName . ' replied to your thread: \"' . mb_substr($thread['title'], 0, 50) . '\"']);
                } catch (Exception $e) {}
            }

            auditLog('forum_reply', "Replied to thread #$threadId");
            addForumKarma($user['id'], 2);
            flash('success', 'Reply posted successfully.');
        }
        redirect("/forum-thread.php?id=$threadId");
    }

    if ($action === 'edit_reply') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $body = trim($_POST['body'] ?? '');
        $post = $pdo->prepare("SELECT * FROM forum_posts WHERE id = ?");
        $post->execute([$postId]);
        $post = $post->fetch();

        if ($post && ($post['created_by'] == $user['id'] || $isAdmin) && $body !== '') {
            $pdo->prepare("UPDATE forum_posts SET body = ?, updated_at = NOW() WHERE id = ?")->execute([$body, $postId]);
            auditLog('forum_reply_edit', "Edited reply #$postId on thread #$threadId");
            flash('success', 'Reply updated.');
        }
        redirect("/forum-thread.php?id=$threadId");
    }

    if ($action === 'delete_reply') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $post = $pdo->prepare("SELECT * FROM forum_posts WHERE id = ?");
        $post->execute([$postId]);
        $post = $post->fetch();

        if ($post && ($post['created_by'] == $user['id'] || $isAdmin)) {
            $pdo->prepare("UPDATE forum_posts SET status = 'deleted' WHERE id = ?")->execute([$postId]);
            $pdo->prepare("UPDATE forum_threads SET reply_count = GREATEST(reply_count - 1, 0) WHERE id = ?")->execute([$threadId]);
            auditLog('forum_reply_delete', "Deleted reply #$postId on thread #$threadId");
            flash('success', 'Reply deleted.');
        }
        redirect("/forum-thread.php?id=$threadId");
    }

    if ($action === 'hide_reply' && $isAdmin) {
        $postId = (int)($_POST['post_id'] ?? 0);
        $pdo->prepare("UPDATE forum_posts SET status = 'hidden' WHERE id = ?")->execute([$postId]);
        auditLog('forum_reply_hide', "Hid reply #$postId");
        flash('success', 'Reply hidden.');
        redirect("/forum-thread.php?id=$threadId");
    }

    if ($action === 'report') {
        $reportThreadId = $_POST['report_thread_id'] ?? null;
        $reportPostId = $_POST['report_post_id'] ?? null;
        $reason = trim($_POST['reason'] ?? '');

        if ($reason !== '') {
            $stmt = $pdo->prepare("INSERT INTO forum_post_reports (thread_id, post_id, reported_by, reason) VALUES (?, ?, ?, ?)");
            $stmt->execute([$reportThreadId ?: null, $reportPostId ?: null, $user['id'], $reason]);
            auditLog('forum_report', "Reported content on thread #$threadId");
            flash('success', 'Report submitted. Thank you.');
        }
        redirect("/forum-thread.php?id=$threadId");
    }

    if ($action === 'like_thread') {
        $check = $pdo->prepare("SELECT id FROM forum_thread_likes WHERE thread_id = ? AND user_id = ?");
        $check->execute([$threadId, $user['id']]);
        if ($check->fetch()) {
            $pdo->prepare("DELETE FROM forum_thread_likes WHERE thread_id = ? AND user_id = ?")->execute([$threadId, $user['id']]);
        } else {
            $pdo->prepare("INSERT INTO forum_thread_likes (thread_id, user_id) VALUES (?, ?)")->execute([$threadId, $user['id']]);
            addForumKarma($thread['created_by'], 1);
            if ($thread['created_by'] != $user['id']) {
                try {
                    $displayName = $user['first_name'] . ' ' . $user['last_name'];
                    $pdo->prepare("INSERT INTO forum_notifications (user_id, type, thread_id, post_id, triggered_by, message) VALUES (?, 'like', ?, NULL, ?, ?)")
                        ->execute([$thread['created_by'], $threadId, $user['id'], $displayName . ' liked your thread: \"' . mb_substr($thread['title'], 0, 50) . '\"']);
                } catch (Exception $e) {}
            }
        }
        redirect("/forum-thread.php?id=$threadId");
    }
}

$replies = $pdo->prepare("
    SELECT p.*, u.first_name, u.last_name, u.role as author_role, u.forum_karma, u.profile_picture, p.is_anonymous, p.anon_display_name
    FROM forum_posts p
    JOIN users u ON p.created_by = u.id
    WHERE p.thread_id = ? AND p.status != 'deleted'
    ORDER BY p.created_at ASC
");
$replies->execute([$threadId]);
$replies = $replies->fetchAll();

$likeCount = $pdo->prepare("SELECT COUNT(*) FROM forum_thread_likes WHERE thread_id = ?");
$likeCount->execute([$threadId]);
$likeCount = $likeCount->fetchColumn();

$hasLiked = false;
$checkLike = $pdo->prepare("SELECT id FROM forum_thread_likes WHERE thread_id = ? AND user_id = ?");
$checkLike->execute([$threadId, $user['id']]);
$hasLiked = (bool)$checkLike->fetch();

$threadAttachments = [];
try {
    $attStmt = $pdo->prepare("SELECT * FROM forum_attachments WHERE thread_id = ? AND post_id IS NULL");
    $attStmt->execute([$threadId]);
    $threadAttachments = $attStmt->fetchAll();
} catch (Exception $e) {}

$replyAttachments = [];
try {
    $rattStmt = $pdo->prepare("SELECT * FROM forum_attachments WHERE thread_id = ? AND post_id IS NOT NULL");
    $rattStmt->execute([$threadId]);
    foreach ($rattStmt->fetchAll() as $ra) {
        $replyAttachments[$ra['post_id']][] = $ra;
    }
} catch (Exception $e) {}

require_once __DIR__ . '/views/layouts/header.php';
?>

<div class="mb-3">
    <a href="<?= BASE_URL ?>/forums.php?category=<?= $thread['category_id'] ?>" class="btn btn-sm btn-outline-primary">
        <i class="fas fa-arrow-left me-1"></i>Back to <?= e($thread['category_name']) ?>
    </a>
</div>

<div class="card mb-4 forum-thread-card">
    <div class="card-body">
        <div class="d-flex gap-3">
            <div class="flex-shrink-0">
                <?php if ($thread['is_anonymous']): ?>
                    <div class="avatar-circle avatar-lg bg-secondary"><i class="fas fa-user-secret"></i></div>
                <?php else:
                    $threadAvatar = getUserAvatarUrl($thread);
                    if ($threadAvatar): ?>
                        <img src="<?= e($threadAvatar) ?>" alt="<?= e($thread['first_name'] . ' ' . $thread['last_name']) ?>" class="avatar-circle avatar-lg" style="object-fit:cover;">
                    <?php else: ?>
                        <div class="avatar-circle avatar-lg bg-primary"><?= strtoupper(substr($thread['first_name'], 0, 1) . substr($thread['last_name'], 0, 1)) ?></div>
                    <?php endif;
                endif; ?>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h4 class="fw-bold mb-1" style="font-size:1.15rem;">
                            <?php if ($thread['is_pinned']): ?><span class="badge bg-warning text-dark me-1" style="font-size:0.65rem;"><i class="fas fa-thumbtack me-1"></i>Pinned</span><?php endif; ?>
                            <?php if ($thread['is_locked']): ?><span class="badge bg-secondary me-1" style="font-size:0.65rem;"><i class="fas fa-lock me-1"></i>Locked</span><?php endif; ?>
                            <?php if ($thread['status'] === 'hidden'): ?><span class="badge bg-warning text-dark me-1" style="font-size:0.65rem;"><i class="fas fa-eye-slash me-1"></i>Hidden</span><?php endif; ?>
                            <?= e($thread['title']) ?>
                        </h4>
                        <div class="d-flex flex-wrap gap-2 align-items-center" style="font-size:0.8rem;">
                            <span class="badge bg-light text-dark"><?= e($thread['category_name']) ?></span>
                            <span class="badge bg-dark" style="font-size:0.58rem;letter-spacing:0.5px;">OP</span>
                            <span class="text-muted">
                                Posted by <strong><?= $thread['is_anonymous'] ? ($thread['anon_display_name'] ? e($thread['anon_display_name']) : 'Anonymous') : e($thread['first_name'] . ' ' . $thread['last_name']) ?></strong>
                                <?php if (!$thread['is_anonymous']): ?>
                                    <span class="badge bg-<?= $thread['author_role'] === 'instructor' ? 'info' : ($thread['author_role'] === 'superadmin' ? 'danger' : ($thread['author_role'] === 'staff' ? 'warning' : 'primary')) ?>" style="font-size:0.6rem;"><?= ucfirst($thread['author_role']) ?></span>
                                <?php endif; ?>
                                <?php $karmaInfo = getForumKarmaLabel($thread['forum_karma']); ?>
                                <span class="badge" style="font-size:0.58rem;background:<?= $karmaInfo['color'] ?>;color:#fff;"><i class="fas <?= $karmaInfo['icon'] ?> me-1"></i><?= $karmaInfo['label'] ?></span>
                                <span class="text-muted" style="font-size:0.7rem;" title="Forum Karma"><i class="fas fa-fire me-1"></i><?= (int)$thread['forum_karma'] ?></span>
                            </span>
                            <span class="text-muted"><i class="far fa-clock me-1"></i><?= date('M d, Y g:ia', strtotime($thread['created_at'])) ?></span>
                        </div>
                    </div>
                    <div class="d-flex gap-1">
                        <?php if ($thread['created_by'] == $user['id'] || $isAdmin): ?>
                        <a href="<?= BASE_URL ?>/forum-create.php?edit=<?= $thread['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="forum-body mt-3 mb-3">
                    <?= nl2br(e($thread['body'])) ?>
                </div>

                <?php if (!empty($threadAttachments)): ?>
                <div class="forum-attachments mb-3">
                    <div class="fw-600 mb-2" style="font-size:0.8rem;"><i class="fas fa-paperclip me-1"></i>Attachments (<?= count($threadAttachments) ?>)</div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($threadAttachments as $att):
                            $ext = strtolower(pathinfo($att['original_name'], PATHINFO_EXTENSION));
                            $isImage = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg']);
                            $fileUrl = BASE_URL . '/uploads/forums/' . $att['file_name'];
                            $iconMap = ['pdf'=>'fa-file-pdf text-danger','doc'=>'fa-file-word text-primary','docx'=>'fa-file-word text-primary','xls'=>'fa-file-excel text-success','xlsx'=>'fa-file-excel text-success','ppt'=>'fa-file-powerpoint text-warning','pptx'=>'fa-file-powerpoint text-warning','zip'=>'fa-file-archive text-secondary','rar'=>'fa-file-archive text-secondary','txt'=>'fa-file-alt text-muted','csv'=>'fa-file-csv text-success'];
                            $icon = $iconMap[$ext] ?? 'fa-file text-muted';
                        ?>
                            <?php if ($isImage): ?>
                            <div class="forum-attachment-thumb" onclick="openLightbox('<?= $fileUrl ?>', '<?= e(addslashes($att['original_name'])) ?>')" style="cursor:pointer;">
                                <img src="<?= $fileUrl ?>" alt="<?= e($att['original_name']) ?>" class="rounded" style="max-height:320px;max-width:100%;object-fit:contain;border:1px solid var(--gray-200);">
                            </div>
                            <?php else: ?>
                            <a href="<?= $fileUrl ?>" target="_blank" download class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1" style="font-size:0.78rem;">
                                <i class="fas <?= $icon ?>"></i>
                                <span style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($att['original_name']) ?></span>
                                <small class="text-muted">(<?= round($att['file_size']/1024, 1) ?>KB)</small>
                            </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="d-flex flex-wrap gap-2 align-items-center pt-2 border-top">
                    <form method="POST" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="like_thread">
                        <button type="submit" class="btn btn-sm <?= $hasLiked ? 'btn-primary' : 'btn-outline-primary' ?>">
                            <i class="fas fa-heart me-1"></i><?= $likeCount ?> <?= $likeCount == 1 ? 'Like' : 'Likes' ?>
                        </button>
                    </form>
                    <span class="text-muted" style="font-size:0.78rem;"><i class="far fa-eye me-1"></i><?= $thread['view_count'] ?> views</span>
                    <span class="text-muted" style="font-size:0.78rem;"><i class="far fa-comment me-1"></i><?= $thread['reply_count'] ?> replies</span>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#reportModal" onclick="setReport(<?= $thread['id'] ?>, null)">
                        <i class="fas fa-flag me-1"></i>Report
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<h5 class="fw-bold mb-3"><i class="fas fa-comments me-2"></i>Replies (<?= count($replies) ?>)</h5>

<?php if (empty($replies)): ?>
<div class="card mb-4">
    <div class="card-body text-center py-4 text-muted">
        <i class="far fa-comment-dots fa-2x mb-2"></i>
        <p class="mb-0">No replies yet. Be the first to respond!</p>
    </div>
</div>
<?php endif; ?>

<?php foreach ($replies as $r): ?>
<div class="card mb-2 forum-reply-card <?= $r['status'] === 'hidden' ? 'opacity-50' : '' ?>" id="reply-<?= $r['id'] ?>" style="overflow:visible;">
    <div class="card-body py-3" style="overflow:visible;">
        <div class="d-flex gap-3">
            <div class="flex-shrink-0">
                <?php if ($r['is_anonymous']): ?>
                    <div class="avatar-circle bg-secondary" title="<?= $r['anon_display_name'] ? e($r['anon_display_name']) : 'Anonymous' ?>"><i class="fas fa-user-secret"></i></div>
                <?php else:
                    $replyAvatar = getUserAvatarUrl($r);
                    if ($replyAvatar): ?>
                        <img src="<?= e($replyAvatar) ?>" alt="<?= e($r['first_name'] . ' ' . $r['last_name']) ?>" class="avatar-circle" style="width:40px;height:40px;object-fit:cover;">
                    <?php else: ?>
                        <div class="avatar-circle bg-primary"><?= strtoupper(substr($r['first_name'], 0, 1) . substr($r['last_name'], 0, 1)) ?></div>
                    <?php endif;
                endif; ?>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-start">
                    <div style="font-size:0.82rem;" class="d-flex gap-2 align-items-center flex-wrap">
                        <strong><?= $r['is_anonymous'] ? ($r['anon_display_name'] ? e($r['anon_display_name']) : 'Anonymous') : e($r['first_name'] . ' ' . $r['last_name']) ?></strong>
                        <?php if ($r['created_by'] == $thread['created_by']): ?>
                            <span class="badge bg-dark" style="font-size:0.58rem;letter-spacing:0.5px;">OP</span>
                        <?php endif; ?>
                        <?php if (!$r['is_anonymous']): ?>
                            <span class="badge bg-<?= $r['author_role'] === 'instructor' ? 'info' : ($r['author_role'] === 'superadmin' ? 'danger' : ($r['author_role'] === 'staff' ? 'warning' : 'primary')) ?>" style="font-size:0.6rem;"><?= ucfirst($r['author_role']) ?></span>
                        <?php endif; ?>
                        <?php $rKarma = getForumKarmaLabel($r['forum_karma']); ?>
                        <span class="badge" style="font-size:0.55rem;background:<?= $rKarma['color'] ?>;color:#fff;"><i class="fas <?= $rKarma['icon'] ?> me-1"></i><?= $rKarma['label'] ?></span>
                        <span class="text-muted"><i class="far fa-clock me-1"></i><?= date('M d, Y g:ia', strtotime($r['created_at'])) ?></span>
                        <?php if ($r['updated_at'] !== $r['created_at']): ?>
                            <span class="text-muted fst-italic" style="font-size:0.7rem;">(edited)</span>
                        <?php endif; ?>
                        <?php if ($r['status'] === 'hidden'): ?>
                            <span class="badge bg-warning text-dark" style="font-size:0.6rem;">Hidden</span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-1">
                        <?php if ($r['created_by'] == $user['id'] || $isAdmin): ?>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light p-1" data-bs-toggle="dropdown" style="line-height:1;"><i class="fas fa-ellipsis-h" style="font-size:0.75rem;"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if ($r['created_by'] == $user['id'] || $isAdmin): ?>
                                <li><a class="dropdown-item" href="#" onclick="editReply(<?= $r['id'] ?>, <?= e(json_encode($r['body'])) ?>)"><i class="fas fa-edit me-2"></i>Edit</a></li>
                                <?php endif; ?>
                                <?php if ($isAdmin): ?>
                                <li>
                                    <form method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="hide_reply">
                                        <input type="hidden" name="post_id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="dropdown-item text-warning"><i class="fas fa-eye-slash me-2"></i>Hide</button>
                                    </form>
                                </li>
                                <?php endif; ?>
                                <li>
                                    <form method="POST" onsubmit="return confirmForm(this, 'Delete this reply?', 'Delete Reply')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_reply">
                                        <input type="hidden" name="post_id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="dropdown-item text-danger"><i class="fas fa-trash me-2"></i>Delete</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                        <?php else: ?>
                        <button class="btn btn-sm btn-light p-1" onclick="setReport(null, <?= $r['id'] ?>)" data-bs-toggle="modal" data-bs-target="#reportModal" style="line-height:1;">
                            <i class="fas fa-flag" style="font-size:0.7rem;"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="forum-body mt-2" style="font-size:0.88rem;">
                    <?= nl2br(e($r['body'])) ?>
                </div>
                <?php
                $rAtts = $replyAttachments[$r['id']] ?? [];
                if (!empty($rAtts)): ?>
                <div class="forum-attachments mt-2">
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($rAtts as $att):
                            $ext = strtolower(pathinfo($att['original_name'], PATHINFO_EXTENSION));
                            $isImage = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg']);
                            $fileUrl = BASE_URL . '/uploads/forums/' . $att['file_name'];
                            $iconMap = ['pdf'=>'fa-file-pdf text-danger','doc'=>'fa-file-word text-primary','docx'=>'fa-file-word text-primary','xls'=>'fa-file-excel text-success','xlsx'=>'fa-file-excel text-success','ppt'=>'fa-file-powerpoint text-warning','pptx'=>'fa-file-powerpoint text-warning','zip'=>'fa-file-archive text-secondary','rar'=>'fa-file-archive text-secondary','txt'=>'fa-file-alt text-muted','csv'=>'fa-file-csv text-success'];
                            $icon = $iconMap[$ext] ?? 'fa-file text-muted';
                        ?>
                            <?php if ($isImage): ?>
                            <div class="forum-attachment-thumb" onclick="openLightbox('<?= $fileUrl ?>', '<?= e(addslashes($att['original_name'])) ?>')" style="cursor:pointer;">
                                <img src="<?= $fileUrl ?>" alt="<?= e($att['original_name']) ?>" class="rounded" style="max-height:200px;max-width:100%;object-fit:contain;border:1px solid var(--gray-200);">
                            </div>
                            <?php else: ?>
                            <a href="<?= $fileUrl ?>" target="_blank" download class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1" style="font-size:0.72rem;">
                                <i class="fas <?= $icon ?>"></i>
                                <span style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($att['original_name']) ?></span>
                            </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php if (!$thread['is_locked'] || $isAdmin): ?>
<div class="card mt-3 mb-4" id="replyFormCard">
    <div class="card-header"><span><i class="fas fa-reply me-2"></i>Post a Reply</span></div>
    <div class="card-body">
        <form method="POST" id="replyForm" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reply" id="replyAction">
            <input type="hidden" name="post_id" value="" id="replyPostId">
            <div class="mb-3">
                <textarea name="body" id="replyBody" class="form-control" rows="4" required placeholder="Write your reply..." maxlength="5000"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label" style="font-size:0.82rem;"><i class="fas fa-paperclip me-1"></i>Attach files <small class="text-muted">(optional, max 5 files, 25MB each)</small></label>
                <input type="file" name="reply_attachments[]" multiple class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.svg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf,.odt,.ods,.odp,.zip,.rar,.7z,.mp4,.mp3,.mov,.avi,.wmv,.flv,.mkv">
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div class="form-check">
                        <input type="checkbox" name="is_anonymous" id="replyAnon" class="form-check-input" value="1">
                        <label for="replyAnon" class="form-check-label" style="font-size:0.85rem;">
                            <i class="fas fa-user-secret me-1"></i>Post as Anonymous
                        </label>
                    </div>
                    <div id="replyAnonNameWrap" style="display:none;">
                        <div class="input-group input-group-sm" style="max-width:250px;">
                            <span class="input-group-text fw-bold" style="font-size:0.78rem;">ANON-</span>
                            <input type="text" name="reply_anon_name" id="replyAnonNameInput" class="form-control form-control-sm" placeholder="Nickname..." maxlength="35">
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-light btn-sm d-none" id="cancelEditBtn" onclick="cancelEdit()">Cancel Edit</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="replySubmitBtn">
                        <i class="fas fa-paper-plane me-1"></i>Post Reply
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="alert alert-secondary mt-3 mb-4 text-center">
    <i class="fas fa-lock me-2"></i>This thread is locked. No new replies can be posted.
</div>
<?php endif; ?>

<div class="modal fade" id="reportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="report">
                <input type="hidden" name="report_thread_id" id="reportThreadId" value="">
                <input type="hidden" name="report_post_id" id="reportPostId" value="">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold"><i class="fas fa-flag text-danger me-2"></i>Report Content</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-600">Reason for reporting</label>
                        <textarea name="reason" class="form-control" rows="3" required placeholder="Describe why this content should be reviewed..." maxlength="1000"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-flag me-1"></i>Submit Report</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function setReport(threadId, postId) {
    document.getElementById('reportThreadId').value = threadId || '';
    document.getElementById('reportPostId').value = postId || '';
}

function editReply(postId, body) {
    document.getElementById('replyAction').value = 'edit_reply';
    document.getElementById('replyPostId').value = postId;
    document.getElementById('replyBody').value = body;
    document.getElementById('cancelEditBtn').classList.remove('d-none');
    document.getElementById('replySubmitBtn').innerHTML = '<i class="fas fa-save me-1"></i>Update Reply';
    document.getElementById('replyFormCard').scrollIntoView({ behavior: 'smooth' });
    document.getElementById('replyBody').focus();
}

function cancelEdit() {
    document.getElementById('replyAction').value = 'reply';
    document.getElementById('replyPostId').value = '';
    document.getElementById('replyBody').value = '';
    document.getElementById('cancelEditBtn').classList.add('d-none');
    document.getElementById('replySubmitBtn').innerHTML = '<i class="fas fa-paper-plane me-1"></i>Post Reply';
}

const replyAnonCheckbox = document.getElementById('replyAnon');
const replyAnonNameWrap = document.getElementById('replyAnonNameWrap');
const replyAnonNameInput = document.getElementById('replyAnonNameInput');
if (replyAnonCheckbox) {
    replyAnonCheckbox.addEventListener('change', function() {
        replyAnonNameWrap.style.display = this.checked ? 'block' : 'none';
    });
}

const replyForm = document.getElementById('replyForm');
if (replyForm) {
    replyForm.addEventListener('submit', function() {
        if (replyAnonCheckbox && replyAnonCheckbox.checked && replyAnonNameInput.value.trim()) {
            replyAnonNameInput.value = 'ANON-' + replyAnonNameInput.value.trim().replace(/^ANON-/i, '');
        }
    });
}

function openLightbox(src, name) {
    const modal = document.getElementById('imageLightbox');
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightboxCaption').textContent = name;
    document.getElementById('lightboxDownload').href = src;
    new bootstrap.Modal(modal).show();
}
</script>

<div class="modal fade" id="imageLightbox" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0" style="background:transparent;box-shadow:none;">
            <div class="modal-body p-0 text-center" style="position:relative;">
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" style="position:absolute;top:10px;right:10px;z-index:10;background-color:rgba(0,0,0,0.5);padding:10px;border-radius:50%;"></button>
                <img id="lightboxImg" src="" alt="" style="max-width:100%;max-height:85vh;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.3);">
                <div class="mt-3 d-flex align-items-center justify-content-center gap-3">
                    <span id="lightboxCaption" class="text-white fw-600" style="font-size:0.88rem;"></span>
                    <a id="lightboxDownload" href="" download class="btn btn-sm btn-light" style="border-radius:8px;"><i class="fas fa-download me-1"></i>Download</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
