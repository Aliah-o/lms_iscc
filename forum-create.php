<?php
$pageTitle = 'New Thread';
require_once __DIR__ . '/helpers/functions.php';
requireLogin();

$user = currentUser();
$pdo = getDB();
$role = $user['role'];
$isAdmin = in_array($role, ['superadmin', 'staff']);

$editId = (int)($_GET['edit'] ?? 0);
$thread = null;

if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM forum_threads WHERE id = ? AND status != 'deleted'");
    $stmt->execute([$editId]);
    $thread = $stmt->fetch();

    if (!$thread) {
        flash('error', 'Thread not found.');
        redirect('/forums.php');
    }
    if ($thread['created_by'] != $user['id'] && !$isAdmin) {
        flash('error', 'You do not have permission to edit this thread.');
        redirect('/forums.php');
    }
    $pageTitle = 'Edit Thread';
}

$categories = $pdo->query("SELECT * FROM forum_categories WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll();

if (empty($categories)) {
    // Auto-create a default category to allow thread creation for new installations.
    $pdo->prepare("INSERT INTO forum_categories (name, description, sort_order, is_active, created_by) VALUES (?, ?, 1, 1, ?)")
        ->execute(['General Discussion', 'Default category created automatically.', $user['id']]);
    $categories = $pdo->query("SELECT * FROM forum_categories WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll();
    flash('info', 'A default forum category has been created so you can start posting threads.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Invalid CSRF token.');
        redirect('/forum-create.php' . ($editId ? "?edit=$editId" : ''));
    }

    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $isAnon = isset($_POST['is_anonymous']) ? 1 : 0;
    $anonDisplayName = null;

    if ($isAnon) {
        $rawAnonName = trim($_POST['anon_display_name'] ?? '');
        if ($rawAnonName !== '') {
            if (stripos($rawAnonName, 'ANON-') !== 0) {
                $rawAnonName = 'ANON-' . $rawAnonName;
            }
            $anonDisplayName = $rawAnonName;
        }
    }

    $errors = [];
    if ($title === '' || strlen($title) < 3) $errors[] = 'Title must be at least 3 characters.';
    if (strlen($title) > 300) $errors[] = 'Title cannot exceed 300 characters.';
    if ($body === '' || strlen($body) < 5) $errors[] = 'Body must be at least 5 characters.';
    if (strlen($body) > 10000) $errors[] = 'Body cannot exceed 10,000 characters.';
    if ($categoryId < 1) $errors[] = 'Please select a category.';

    if ($categoryId > 0) {
        $catCheck = $pdo->prepare("SELECT id FROM forum_categories WHERE id = ? AND is_active = 1");
        $catCheck->execute([$categoryId]);
        if (!$catCheck->fetch()) $errors[] = 'Invalid category.';
    }

    if ($isAnon && $anonDisplayName !== null) {
        $namePart = substr($anonDisplayName, 5);
        if (strlen($namePart) < 2 || strlen($namePart) > 30) {
            $errors[] = 'Anonymous name must be between 2-30 characters after ANON-.';
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $namePart)) {
            $errors[] = 'Anonymous name can only contain letters, numbers, hyphens and underscores.';
        }
        $dupCheck = $pdo->prepare("SELECT id FROM users WHERE LOWER(CONCAT(first_name, ' ', last_name)) = LOWER(?) OR LOWER(username) = LOWER(?)");
        $dupCheck->execute([$anonDisplayName, $anonDisplayName]);
        if ($dupCheck->fetch()) {
            $errors[] = 'This anonymous name conflicts with an existing user. Please choose a different name.';
        }
        $dupAnonThread = $pdo->prepare("SELECT id FROM forum_threads WHERE LOWER(anon_display_name) = LOWER(?) AND created_by != ?");
        $dupAnonThread->execute([$anonDisplayName, $user['id']]);
        if ($dupAnonThread->fetch()) {
            $errors[] = 'This anonymous name is already taken by another user. Please choose a different name.';
        }
        $dupAnonPost = $pdo->prepare("SELECT id FROM forum_posts WHERE LOWER(anon_display_name) = LOWER(?) AND created_by != ?");
        $dupAnonPost->execute([$anonDisplayName, $user['id']]);
        if ($dupAnonPost->fetch()) {
            $errors[] = 'This anonymous name is already taken by another user. Please choose a different name.';
        }
    }

    $allowedExtensions = ['jpg','jpeg','png','gif','webp','bmp','svg',
        'pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','rtf','odt','ods','odp',
        'zip','rar','7z','mp4','mp3','mov','avi','wmv','flv','mkv'];
    $maxFileSize = 25 * 1024 * 1024;
    $uploadedFiles = [];

    if (!empty($_FILES['attachments']['name'][0])) {
        $fileCount = count($_FILES['attachments']['name']);
        if ($fileCount > 5) $errors[] = 'Maximum 5 files allowed.';

        for ($i = 0; $i < min($fileCount, 5); $i++) {
            if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $origName = $_FILES['attachments']['name'][$i];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $size = $_FILES['attachments']['size'][$i];

            if (!in_array($ext, $allowedExtensions)) {
                $errors[] = "File '$origName' has a disallowed extension.";
            }
            if ($size > $maxFileSize) {
                $errors[] = "File '$origName' exceeds 25MB limit.";
            }
        }
    }

    if (!empty($errors)) {
        flash('error', implode(' ', $errors));
    } else {
        if ($editId && $thread) {
            $stmt = $pdo->prepare("UPDATE forum_threads SET title = ?, body = ?, category_id = ?, is_anonymous = ?, anon_display_name = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$title, $body, $categoryId, $isAnon, $isAnon ? $anonDisplayName : null, $editId]);

            if (!empty($_FILES['attachments']['name'][0])) {
                $uploadDir = __DIR__ . '/uploads/forums/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $fileCount = count($_FILES['attachments']['name']);
                for ($i = 0; $i < min($fileCount, 5); $i++) {
                    if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $origName = $_FILES['attachments']['name'][$i];
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $safeName = uniqid('forum_') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $fileType = $_FILES['attachments']['type'][$i];
                    $fileSize = $_FILES['attachments']['size'][$i];

                    if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $uploadDir . $safeName)) {
                        $pdo->prepare("INSERT INTO forum_attachments (thread_id, post_id, file_name, original_name, file_type, file_size, uploaded_by) VALUES (?, NULL, ?, ?, ?, ?, ?)")
                            ->execute([$editId, $safeName, $origName, $fileType, $fileSize, $user['id']]);
                    }
                }
            }

            auditLog('forum_thread_edit', "Edited thread #$editId: $title");
            flash('success', 'Thread updated successfully.');
            redirect("/forum-thread.php?id=$editId");
        } else {
            $stmt = $pdo->prepare("INSERT INTO forum_threads (category_id, title, body, created_by, is_anonymous, anon_display_name, last_reply_at, last_reply_by) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->execute([$categoryId, $title, $body, $user['id'], $isAnon, $isAnon ? $anonDisplayName : null, $user['id']]);
            $newId = $pdo->lastInsertId();

            if (!empty($_FILES['attachments']['name'][0])) {
                $uploadDir = __DIR__ . '/uploads/forums/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $fileCount = count($_FILES['attachments']['name']);
                for ($i = 0; $i < min($fileCount, 5); $i++) {
                    if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $origName = $_FILES['attachments']['name'][$i];
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $safeName = uniqid('forum_') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $fileType = $_FILES['attachments']['type'][$i];
                    $fileSize = $_FILES['attachments']['size'][$i];

                    if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $uploadDir . $safeName)) {
                        $pdo->prepare("INSERT INTO forum_attachments (thread_id, post_id, file_name, original_name, file_type, file_size, uploaded_by) VALUES (?, NULL, ?, ?, ?, ?, ?)")
                            ->execute([$newId, $safeName, $origName, $fileType, $fileSize, $user['id']]);
                    }
                }
            }

            auditLog('forum_thread_create', "Created thread #$newId: $title");
            addForumKarma($user['id'], 5);
            flash('success', 'Thread created successfully.');
            redirect("/forum-thread.php?id=$newId");
        }
    }
}

$breadcrumbPills = ['Forums', $editId ? 'Edit Thread' : 'New Thread'];
$preselectedCategory = $thread ? $thread['category_id'] : ((int)($_GET['category'] ?? 0));

$existingAttachments = [];
if ($editId) {
    $attStmt = $pdo->prepare("SELECT * FROM forum_attachments WHERE thread_id = ? AND post_id IS NULL");
    $attStmt->execute([$editId]);
    $existingAttachments = $attStmt->fetchAll();
}

require_once __DIR__ . '/views/layouts/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="<?= BASE_URL ?>/forums.php" class="forum-breadcrumb-link">
        <i class="fas fa-comments me-1"></i>Forums
    </a>
    <i class="fas fa-chevron-right text-muted" style="font-size:0.6rem;"></i>
    <span class="fw-600 text-muted" style="font-size:0.85rem;"><?= $editId ? 'Edit Thread' : 'New Thread' ?></span>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="forum-create-header mb-4">
            <div class="d-flex align-items-center gap-3">
                <div class="forum-create-icon">
                    <i class="fas fa-<?= $editId ? 'edit' : 'pen-fancy' ?>"></i>
                </div>
                <div>
                    <h4 class="fw-800 mb-0" style="letter-spacing:-0.3px;"><?= $editId ? 'Edit Thread' : 'Start a New Discussion' ?></h4>
                    <p class="text-muted mb-0" style="font-size:0.82rem;"><?= $editId ? 'Update your thread details below' : 'Share your thoughts, questions, or ideas with the community' ?></p>
                </div>
            </div>
        </div>

        <div class="forum-create-card">
            <form method="POST" enctype="multipart/form-data" id="threadForm">
                <?= csrf_field() ?>

                <div class="forum-form-section">
                    <label class="forum-form-label">
                        <i class="fas fa-th-large me-2 text-primary"></i>Category
                        <span class="text-danger">*</span>
                    </label>
                    <div class="forum-category-selector">
                        <?php foreach ($categories as $idx => $cat): 
                            $catColors = ['#4F46E5','#0EA5E9','#10B981','#F59E0B','#EF4444','#8B5CF6','#EC4899','#14B8A6','#F97316','#6366F1'];
                            $color = $catColors[$idx % count($catColors)];
                            $isSelected = ($preselectedCategory == $cat['id']);
                        ?>
                        <label class="forum-cat-radio <?= $isSelected ? 'selected' : '' ?>" style="--cat-color: <?= $color ?>;">
                            <input type="radio" name="category_id" value="<?= $cat['id'] ?>" <?= $isSelected ? 'checked' : '' ?> required>
                            <div class="forum-cat-radio-dot" style="background: <?= $color ?>;"></div>
                            <span class="forum-cat-radio-name"><?= e($cat['name']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="forum-form-section">
                    <label for="title" class="forum-form-label">
                        <i class="fas fa-heading me-2 text-primary"></i>Title
                        <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="title" id="title" class="forum-form-input" required
                           maxlength="300" minlength="3"
                           value="<?= e($_POST['title'] ?? ($thread['title'] ?? '')) ?>"
                           placeholder="Write a clear, descriptive title...">
                    <div class="d-flex justify-content-between mt-1">
                        <span class="forum-form-hint">A good title helps others find your thread</span>
                        <span class="forum-char-count" id="titleCount">0/300</span>
                    </div>
                </div>

                <div class="forum-form-section">
                    <label for="body" class="forum-form-label">
                        <i class="fas fa-align-left me-2 text-primary"></i>Content
                        <span class="text-danger">*</span>
                    </label>
                    <textarea name="body" id="body" class="forum-form-textarea" rows="10" required
                              maxlength="10000" minlength="5"
                              placeholder="Share your thoughts, ask a question, or start a discussion..."><?= e($_POST['body'] ?? ($thread['body'] ?? '')) ?></textarea>
                    <div class="d-flex justify-content-between mt-1">
                        <span class="forum-form-hint">Be detailed — it helps the community give better responses</span>
                        <span class="forum-char-count" id="bodyCount">0/10,000</span>
                    </div>
                </div>

                <div class="forum-form-section">
                    <label class="forum-form-label">
                        <i class="fas fa-paperclip me-2 text-primary"></i>Attachments
                        <span class="text-muted fw-normal" style="font-size:0.75rem; margin-left:4px;">(optional)</span>
                    </label>
                    <div class="forum-upload-zone" id="uploadZone">
                        <input type="file" name="attachments[]" multiple id="fileInput" class="forum-upload-input"
                               accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.svg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf,.odt,.ods,.odp,.zip,.rar,.7z,.mp4,.mp3,.mov,.avi,.wmv,.flv,.mkv">
                        <div class="forum-upload-content">
                            <div class="forum-upload-icon"><i class="fas fa-cloud-arrow-up"></i></div>
                            <p class="fw-600 mb-1" style="font-size:0.88rem;">Click to upload or drag files here</p>
                            <p class="text-muted mb-0" style="font-size:0.75rem;">Images, documents (PDF, Word, Excel, PPT), archives, media — Max 5 files, 25MB each</p>
                        </div>
                    </div>
                    <div id="filePreview" class="d-flex flex-wrap gap-2 mt-2"></div>
                    <?php if (!empty($existingAttachments)): ?>
                    <div class="mt-2">
                        <small class="fw-600 text-muted">Current attachments:</small>
                        <div class="d-flex flex-wrap gap-2 mt-1">
                            <?php foreach ($existingAttachments as $att): ?>
                            <span class="forum-existing-file">
                                <i class="fas fa-file me-1"></i><?= e($att['original_name']) ?>
                                <small class="text-muted">(<?= round($att['file_size']/1024, 1) ?>KB)</small>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="forum-form-section">
                    <div class="forum-identity-card">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-3">
                                <div class="forum-identity-avatar" id="identityAvatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div>
                                    <div class="fw-600" style="font-size:0.85rem;" id="identityLabel">Posting as</div>
                                    <div class="fw-700" id="identityName" style="font-size:0.95rem;"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></div>
                                </div>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" name="is_anonymous" id="is_anonymous" class="form-check-input" value="1"
                                    <?= ($_POST['is_anonymous'] ?? ($thread['is_anonymous'] ?? 0)) ? 'checked' : '' ?>
                                    style="width:3em; height:1.5em; cursor:pointer;">
                                <label for="is_anonymous" class="form-check-label fw-600" style="font-size:0.82rem; cursor:pointer;">Anonymous</label>
                            </div>
                        </div>
                        <div class="mt-3" id="anonNameSection" style="display:none;">
                            <label class="form-label fw-600" style="font-size:0.82rem;"><i class="fas fa-mask me-1"></i>Anonymous Display Name <small class="text-muted">(optional)</small></label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold" style="font-size:0.85rem; background:var(--gray-100);">ANON-</span>
                                <input type="text" name="anon_display_name" id="anonNameInput" class="form-control" placeholder="e.g. Gimbal, Shadow, Ninja..." maxlength="35" value="<?= e(isset($_POST['anon_display_name']) ? preg_replace('/^ANON-/i', '', $_POST['anon_display_name']) : (isset($thread['anon_display_name']) ? preg_replace('/^ANON-/i', '', $thread['anon_display_name']) : '')) ?>">
                            </div>
                            <small class="text-muted">Create a custom anonymous identity. Letters, numbers, hyphens and underscores only. Cannot match existing usernames.</small>
                        </div>
                    </div>
                </div>

                <div class="forum-form-actions">
                    <a href="<?= BASE_URL ?>/forums.php" class="forum-btn-cancel">
                        <i class="fas fa-times me-1"></i>Cancel
                    </a>
                    <button type="submit" class="forum-btn-submit">
                        <i class="fas fa-<?= $editId ? 'save' : 'paper-plane' ?> me-1"></i><?= $editId ? 'Update Thread' : 'Publish Thread' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const anonCheckbox = document.getElementById('is_anonymous');
const identityName = document.getElementById('identityName');
const identityAvatar = document.getElementById('identityAvatar');
const identityLabel = document.getElementById('identityLabel');
const anonNameSection = document.getElementById('anonNameSection');
const anonNameInput = document.getElementById('anonNameInput');
const realName = <?= json_encode($user['first_name'] . ' ' . $user['last_name']) ?>;

function updateIdentity() {
    if (anonCheckbox.checked) {
        const customName = anonNameInput.value.trim();
        identityName.textContent = customName ? 'ANON-' + customName : 'Anonymous';
        identityAvatar.innerHTML = '<i class="fas fa-user-secret"></i>';
        identityAvatar.style.background = 'var(--gray-400)';
        identityLabel.textContent = 'Posting anonymously';
        anonNameSection.style.display = 'block';
    } else {
        identityName.textContent = realName;
        identityAvatar.innerHTML = '<i class="fas fa-user"></i>';
        identityAvatar.style.background = '';
        identityLabel.textContent = 'Posting as';
        anonNameSection.style.display = 'none';
    }
}
anonCheckbox.addEventListener('change', updateIdentity);
anonNameInput.addEventListener('input', updateIdentity);
updateIdentity();

document.getElementById('threadForm').addEventListener('submit', function() {
    if (anonCheckbox.checked && anonNameInput.value.trim()) {
        anonNameInput.value = 'ANON-' + anonNameInput.value.trim().replace(/^ANON-/i, '');
    }
});

const titleInput = document.getElementById('title');
const bodyInput = document.getElementById('body');
const titleCount = document.getElementById('titleCount');
const bodyCount = document.getElementById('bodyCount');

function updateCounts() {
    titleCount.textContent = titleInput.value.length + '/300';
    bodyCount.textContent = bodyInput.value.length.toLocaleString() + '/10,000';
    titleCount.style.color = titleInput.value.length > 280 ? 'var(--danger)' : 'var(--gray-400)';
    bodyCount.style.color = bodyInput.value.length > 9500 ? 'var(--danger)' : 'var(--gray-400)';
}
titleInput.addEventListener('input', updateCounts);
bodyInput.addEventListener('input', updateCounts);
updateCounts();

const fileInput = document.getElementById('fileInput');
const filePreview = document.getElementById('filePreview');
const uploadZone = document.getElementById('uploadZone');

fileInput.addEventListener('change', function() {
    filePreview.innerHTML = '';
    const files = this.files;
    const iconMap = {pdf:'fa-file-pdf text-danger', doc:'fa-file-word text-primary', docx:'fa-file-word text-primary',
        xls:'fa-file-excel text-success', xlsx:'fa-file-excel text-success', ppt:'fa-file-powerpoint text-warning',
        pptx:'fa-file-powerpoint text-warning', zip:'fa-file-zipper text-secondary', rar:'fa-file-zipper text-secondary'};

    for (let i = 0; i < Math.min(files.length, 5); i++) {
        const f = files[i];
        const ext = f.name.split('.').pop().toLowerCase();
        const isImg = ['jpg','jpeg','png','gif','webp','bmp','svg'].includes(ext);
        const div = document.createElement('div');
        div.className = 'forum-file-chip';

        if (isImg) {
            const reader = new FileReader();
            reader.onload = e => { div.innerHTML = '<img src="'+e.target.result+'" style="width:28px;height:28px;border-radius:6px;object-fit:cover;" class="me-2">' + '<span>'+f.name+'</span><small class="text-muted ms-1">('+Math.round(f.size/1024)+'KB)</small>'; };
            reader.readAsDataURL(f);
        } else {
            const ic = iconMap[ext] || 'fa-file text-muted';
            div.innerHTML = '<i class="fas '+ic+' me-2"></i><span>'+f.name+'</span><small class="text-muted ms-1">('+Math.round(f.size/1024)+'KB)</small>';
        }
        filePreview.appendChild(div);
    }
    if (files.length > 5) {
        const warn = document.createElement('div');
        warn.className = 'text-danger small mt-1 w-100';
        warn.textContent = 'Only the first 5 files will be uploaded.';
        filePreview.appendChild(warn);
    }
});

document.querySelectorAll('.forum-cat-radio input[type=radio]').forEach(radio => {
    radio.addEventListener('change', () => {
        document.querySelectorAll('.forum-cat-radio').forEach(l => l.classList.remove('selected'));
        radio.closest('.forum-cat-radio').classList.add('selected');
    });
});
</script>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
