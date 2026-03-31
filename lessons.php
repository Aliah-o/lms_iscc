<?php
$pageTitle = 'Lessons';
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'student');
$pdo = getDB();
$user = currentUser();
$role = $user['role'];
$classId = intval($_GET['class_id'] ?? 0);

if (!$classId) { redirect('/classes.php'); }

if ($role === 'instructor') {
    $cls = $pdo->prepare("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id WHERE tc.id = ? AND tc.instructor_id = ?");
    $cls->execute([$classId, $user['id']]);
} else {
    $cls = $pdo->prepare("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id JOIN class_enrollments ce ON ce.class_id = tc.id WHERE tc.id = ? AND ce.student_id = ?");
    $cls->execute([$classId, $user['id']]);
}
$class = $cls->fetch();
if (!$class) { flash('error', 'Access denied.'); redirect('/classes.php'); }

$breadcrumbPills = ['BSIT', YEAR_LEVELS[$class['year_level']], 'Section ' . $class['section_name']];

// ─── Auto-create lesson_attachments table if missing ───
try {
    $pdo->query("SELECT 1 FROM lesson_attachments LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS lesson_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lesson_id INT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) DEFAULT NULL,
        original_name VARCHAR(255) NOT NULL,
        file_type VARCHAR(100) NOT NULL,
        file_size BIGINT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
}

// ─── Ensure lessons table has required columns ───
try {
    $pdo->query("SELECT video_url FROM lessons LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE lessons ADD COLUMN video_url VARCHAR(500) DEFAULT NULL AFTER content");
        $pdo->exec("ALTER TABLE lessons ADD COLUMN link_url VARCHAR(500) DEFAULT NULL AFTER video_url");
        $pdo->exec("ALTER TABLE lessons ADD COLUMN link_title VARCHAR(200) DEFAULT NULL AFTER link_url");
    } catch (Exception $e2) {}
}

$allowedMimeMap = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'image/svg+xml' => 'svg',
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'text/plain' => 'txt',
    'text/csv' => 'csv',
    'application/zip' => 'zip',
    'application/x-rar-compressed' => 'rar',
    'application/vnd.rar' => 'rar',
];
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'rar'];
$maxFileSize = MODULE_UPLOAD_MAX_SIZE;
$maxFiles = 5;

function buildAttachmentUpload(array $files, int $index): array {
    return [
        'name' => $files['name'][$index] ?? '',
        'type' => $files['type'][$index] ?? '',
        'tmp_name' => $files['tmp_name'][$index] ?? '',
        'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$index] ?? 0,
    ];
}

function summarizeAttachmentRequestFiles(array $files): array {
    $summary = [];
    if (empty($files['name']) || !is_array($files['name'])) {
        return $summary;
    }

    foreach (array_keys($files['name']) as $i) {
        $summary[] = [
            'name' => $files['name'][$i] ?? '',
            'type' => $files['type'][$i] ?? '',
            'size' => (int)($files['size'][$i] ?? 0),
            'error' => (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'tmp_name' => $files['tmp_name'][$i] ?? '',
        ];
    }

    return $summary;
}

function recordLessonUploadDebug(int $lessonId, int $classId, string $action, array $context): void {
    $moduleDir = getStorageAbsolutePath('uploads/modules');
    $matchingFiles = [];
    if ($moduleDir !== '' && is_dir($moduleDir)) {
        foreach (glob($moduleDir . '/module_' . $lessonId . '_*') ?: [] as $path) {
            $matchingFiles[] = [
                'name' => basename($path),
                'size' => is_file($path) ? (int)filesize($path) : 0,
                'modified_at' => is_file($path) ? date('Y-m-d H:i:s', (int)filemtime($path)) : null,
                'absolute_path' => $path,
            ];
        }
    }

    saveLessonDebugSnapshot($lessonId, array_merge([
        'captured_at' => date('Y-m-d H:i:s'),
        'action' => $action,
        'class_id' => $classId,
        'lesson_id' => $lessonId,
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'upload_dir' => [
            'relative' => 'uploads/modules',
            'absolute' => $moduleDir,
            'exists' => $moduleDir !== '' && is_dir($moduleDir),
            'writable' => $moduleDir !== '' && is_writable($moduleDir),
        ],
        'matching_files_after_request' => $matchingFiles,
    ], $context));
}

function saveLessonAttachments(PDO $pdo, array $files, int $lessonId, array $allowedMimeMap, array $allowedExtensions, int $maxFileSize, int $maxFiles, int $startingCount = 0): array {
    $uploadedCount = 0;
    $errors = [];

    if (empty($files['name']) || !is_array($files['name'])) {
        return ['uploaded' => 0, 'errors' => []];
    }

    foreach (array_keys($files['name']) as $i) {
        if (($startingCount + $uploadedCount) >= $maxFiles) {
            break;
        }

        $file = buildAttachmentUpload($files, (int)$i);
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $stored = storeUploadedFile($file, 'uploads/modules', 'module_' . $lessonId, $allowedMimeMap, $allowedExtensions, $maxFileSize);
        if (!$stored['ok']) {
            $errors[] = $stored['error'];
            continue;
        }

        $pdo->prepare("INSERT INTO lesson_attachments (lesson_id, file_name, file_path, original_name, file_type, file_size) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([
                $lessonId,
                $stored['file_name'],
                $stored['relative_path'],
                $stored['original_name'],
                $stored['mime_type'],
                $stored['size'],
            ]);
        $uploadedCount++;
    }

    return ['uploaded' => $uploadedCount, 'errors' => $errors];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'instructor') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect("/lessons.php?class_id=$classId"); }
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $content = $_POST['content'] ?? '';
        $videoUrl = trim($_POST['video_url'] ?? '');
        $linkUrl = trim($_POST['link_url'] ?? '');
        $linkTitle = trim($_POST['link_title'] ?? '');
        $clientAttachmentCount = (int)($_POST['client_attachment_count'] ?? 0);
        $clientAttachmentNames = trim($_POST['client_attachment_names'] ?? '');
        $requestFileSummary = summarizeAttachmentRequestFiles($_FILES['attachments'] ?? []);

        if ($title) {
            if ($videoUrl) {
                $videoUrl = cleanVideoUrl($videoUrl);
            }

            $pdo->prepare("INSERT INTO lessons (class_id, title, content, video_url, link_url, link_title, sort_order) VALUES (?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(l2.sort_order),0)+1 FROM lessons l2 WHERE l2.class_id = ?))")
                ->execute([$classId, $title, $content, $videoUrl ?: null, $linkUrl ?: null, $linkTitle ?: null, $classId]);
            $lessonId = $pdo->lastInsertId();
            $uploadSummary = ['uploaded' => 0, 'errors' => []];

            if (!empty($_FILES['attachments']['name'][0])) {
                $uploadSummary = saveLessonAttachments($pdo, $_FILES['attachments'], (int)$lessonId, $allowedMimeMap, $allowedExtensions, $maxFileSize, $maxFiles);
                if (!empty($uploadSummary['errors']) && $uploadSummary['uploaded'] === 0) {
                    flash('error', $uploadSummary['errors'][0]);
                }
            } elseif ($clientAttachmentCount > 0) {
                flash('error', 'Attachments were selected in the browser, but the server did not receive any files. Please refresh the page and try again.');
            }

            recordLessonUploadDebug((int)$lessonId, $classId, 'create', [
                'title' => $title,
                'client_attachment_count' => $clientAttachmentCount,
                'client_attachment_names' => $clientAttachmentNames !== '' ? explode(' | ', $clientAttachmentNames) : [],
                'request_files' => $requestFileSummary,
                'request_file_count' => count($requestFileSummary),
                'upload_summary' => $uploadSummary,
                'post_video_url' => $videoUrl,
                'post_link_url' => $linkUrl,
            ]);

            auditLog('lesson_created', "Created lesson: $title (class #$classId)");
            flash('success', 'Lesson created successfully.');
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['lesson_id'] ?? 0);
        $atts = $pdo->prepare("SELECT file_name, file_path FROM lesson_attachments WHERE lesson_id = ?");
        $atts->execute([$id]);
        foreach ($atts->fetchAll() as $att) {
            deleteStorageFile(getLessonAttachmentRelativePath($att));
        }
        $pdo->prepare("DELETE FROM lesson_attachments WHERE lesson_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM lessons WHERE id = ? AND class_id = ?")->execute([$id, $classId]);
        flash('success', 'Lesson deleted.');
    } elseif ($action === 'delete_attachment') {
        $attId = intval($_POST['attachment_id'] ?? 0);
        $att = $pdo->prepare("SELECT la.* FROM lesson_attachments la JOIN lessons l ON la.lesson_id = l.id WHERE la.id = ? AND l.class_id = ?");
        $att->execute([$attId, $classId]);
        $attachment = $att->fetch();
        if ($attachment) {
            deleteStorageFile(getLessonAttachmentRelativePath($attachment));
            $pdo->prepare("DELETE FROM lesson_attachments WHERE id = ?")->execute([$attId]);
            flash('success', 'Attachment deleted.');
        }
    } elseif ($action === 'add_attachments') {
        $lessonId = intval($_POST['lesson_id'] ?? 0);
        $clientAttachmentCount = (int)($_POST['client_attachment_count'] ?? 0);
        $clientAttachmentNames = trim($_POST['client_attachment_names'] ?? '');
        $requestFileSummary = summarizeAttachmentRequestFiles($_FILES['attachments'] ?? []);
        $lesson = $pdo->prepare("SELECT * FROM lessons WHERE id = ? AND class_id = ?");
        $lesson->execute([$lessonId, $classId]);
        $lessonRow = $lesson->fetch();
        if ($lessonRow && !empty($_FILES['attachments']['name'][0])) {
            $existingCount = $pdo->prepare("SELECT COUNT(*) FROM lesson_attachments WHERE lesson_id = ?");
            $existingCount->execute([$lessonId]);
            $currentCount = $existingCount->fetchColumn();

            $uploadSummary = saveLessonAttachments($pdo, $_FILES['attachments'], $lessonId, $allowedMimeMap, $allowedExtensions, $maxFileSize, $maxFiles, (int)$currentCount);
            if ($uploadSummary['uploaded'] > 0) {
                flash('success', $uploadSummary['uploaded'] . " attachment(s) added.");
            } else {
                flash('error', $uploadSummary['errors'][0] ?? 'No valid files were uploaded.');
            }
            recordLessonUploadDebug($lessonId, $classId, 'add_attachments', [
                'client_attachment_count' => $clientAttachmentCount,
                'client_attachment_names' => $clientAttachmentNames !== '' ? explode(' | ', $clientAttachmentNames) : [],
                'request_files' => $requestFileSummary,
                'request_file_count' => count($requestFileSummary),
                'upload_summary' => $uploadSummary,
            ]);
        } elseif ($lessonRow && $clientAttachmentCount > 0) {
            flash('error', 'Attachments were selected in the browser, but the server did not receive any files. Please refresh the page and try again.');
            recordLessonUploadDebug($lessonId, $classId, 'add_attachments', [
                'client_attachment_count' => $clientAttachmentCount,
                'client_attachment_names' => $clientAttachmentNames !== '' ? explode(' | ', $clientAttachmentNames) : [],
                'request_files' => $requestFileSummary,
                'request_file_count' => count($requestFileSummary),
                'upload_summary' => ['uploaded' => 0, 'errors' => ['Server received no files.']],
            ]);
        }
    }
    redirect("/lessons.php?class_id=$classId" . (isset($_POST['return_view']) ? '&view=' . intval($_POST['return_view']) : ''));
}

function cleanVideoUrl($url) {
    if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }
    if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
    }
    if (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $m)) {
        return 'https://drive.google.com/file/d/' . $m[1] . '/preview';
    }
    return $url;
}

function getEmbeddableVideoUrl($url) {
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }

    $embedUrl = cleanVideoUrl($url);
    if (preg_match('#^https://www\.youtube\.com/embed/[a-zA-Z0-9_-]{11}$#', $embedUrl)) {
        return $embedUrl;
    }
    if (preg_match('#^https://player\.vimeo\.com/video/\d+$#', $embedUrl)) {
        return $embedUrl;
    }
    if (preg_match('#^https://drive\.google\.com/file/d/[a-zA-Z0-9_-]+/preview$#', $embedUrl)) {
        return $embedUrl;
    }

    return '';
}

function formatFileSize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function getFileIcon($type) {
    if (isImageMimeType($type)) return 'fa-file-image text-success';
    if ($type === 'application/pdf') return 'fa-file-pdf text-danger';
    if (str_contains($type, 'word')) return 'fa-file-word text-primary';
    if (str_contains($type, 'excel') || str_contains($type, 'spreadsheet')) return 'fa-file-excel text-success';
    if (str_contains($type, 'powerpoint') || str_contains($type, 'presentation')) return 'fa-file-powerpoint text-warning';
    if (str_contains($type, 'zip') || str_contains($type, 'rar')) return 'fa-file-archive text-secondary';
    return 'fa-file text-muted';
}

$lessons = $pdo->prepare("SELECT * FROM lessons WHERE class_id = ? ORDER BY sort_order DESC, created_at DESC, id DESC");
$lessons->execute([$classId]);
$lessons = $lessons->fetchAll();

$viewLesson = null;
$viewAttachments = [];
$viewEmbedVideoUrl = '';
if (isset($_GET['view'])) {
    $vid = intval($_GET['view']);
    $stmt = $pdo->prepare("SELECT * FROM lessons WHERE id = ? AND class_id = ?");
    $stmt->execute([$vid, $classId]);
    $viewLesson = $stmt->fetch();
    if ($viewLesson) {
        $viewEmbedVideoUrl = getEmbeddableVideoUrl($viewLesson['video_url'] ?? '');
        try {
            $astmt = $pdo->prepare("SELECT * FROM lesson_attachments WHERE lesson_id = ? ORDER BY created_at");
            $astmt->execute([$viewLesson['id']]);
            $viewAttachments = $astmt->fetchAll();
            foreach ($viewAttachments as &$attachment) {
                $attachment['resolved_path'] = getLessonAttachmentRelativePath($attachment);
                $attachment['file_exists'] = $attachment['resolved_path'] !== '' && storageFileExists($attachment['resolved_path']);
                $attachment['file_url'] = $attachment['file_exists'] ? storageUrl($attachment['resolved_path']) : '';
                if ($attachment['file_exists'] && empty($attachment['file_path']) && !empty($attachment['resolved_path'])) {
                    $pdo->prepare("UPDATE lesson_attachments SET file_path = ? WHERE id = ?")->execute([$attachment['resolved_path'], $attachment['id']]);
                    $attachment['file_path'] = $attachment['resolved_path'];
                }
            }
            unset($attachment);
        } catch (Exception $e) {
            $viewAttachments = [];
        }
    }
}

require_once __DIR__ . '/views/layouts/header.php';
?>

<?php if ($viewLesson): ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="<?= BASE_URL ?>/lessons.php?class_id=<?= $classId ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back to Lessons</a>
    <?php if ($role === 'instructor'): ?>
    <a href="<?= BASE_URL ?>/lesson-debug.php?class_id=<?= $classId ?>&view=<?= (int)$viewLesson['id'] ?>" class="btn btn-outline-dark btn-sm"><i class="bi bi-bug me-1"></i>Debug Lesson</a>
    <?php endif; ?>
</div>
<div class="card mb-3">
    <div class="card-header">
        <span><i class="fas fa-book-open me-2"></i><?= e($viewLesson['title']) ?></span>
        <small class="text-muted"><?= formatDate($viewLesson['created_at']) ?></small>
    </div>
    <div class="card-body">
        <?php if ($viewLesson['content']): ?>
        <div class="lesson-content mb-3"><?= $viewLesson['content'] ?></div>
        <?php endif; ?>

        <?php if ($viewLesson['video_url']): ?>
        <div class="mb-3">
            <h6 class="fw-bold"><i class="fas fa-video me-2 text-danger"></i>Video</h6>
            <?php if ($viewEmbedVideoUrl): ?>
            <div class="ratio ratio-16x9" style="max-width:720px;border-radius:12px;overflow:hidden;border:1px solid var(--gray-100);">
                <iframe src="<?= e($viewEmbedVideoUrl) ?>" allowfullscreen allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>
            </div>
            <?php else: ?>
            <a href="<?= e($viewLesson['video_url']) ?>" target="_blank" rel="noopener" class="d-inline-flex align-items-center gap-2 p-3" style="background:var(--gray-50);border-radius:10px;text-decoration:none;border:1px solid var(--gray-100);">
                <i class="fas fa-external-link-alt text-danger"></i>
                <span>Open video link</span>
            </a>
            <small class="d-block text-muted mt-2">This video URL cannot be embedded, so it will open in a new tab instead.</small>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($viewLesson['link_url']): ?>
        <div class="mb-3">
            <h6 class="fw-bold"><i class="fas fa-link me-2 text-info"></i>Resource Link</h6>
            <a href="<?= e($viewLesson['link_url']) ?>" target="_blank" rel="noopener" class="d-inline-flex align-items-center gap-2 p-3" style="background:var(--gray-50);border-radius:10px;text-decoration:none;border:1px solid var(--gray-100);">
                <i class="fas fa-external-link-alt text-primary"></i>
                <span><?= e($viewLesson['link_title'] ?: $viewLesson['link_url']) ?></span>
            </a>
        </div>
        <?php endif; ?>

        <div class="mb-3">
            <h6 class="fw-bold"><i class="fas fa-paperclip me-2 text-warning"></i>Attachments<?= !empty($viewAttachments) ? ' (' . count($viewAttachments) . ')' : '' ?></h6>
            <?php if (!empty($viewAttachments)): ?>
            <div class="row g-2">
                <?php foreach ($viewAttachments as $att): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="d-flex align-items-center gap-3 p-3" style="background:var(--gray-50);border-radius:10px;border:1px solid var(--gray-100);">
                        <?php if (!empty($att['file_exists']) && isImageMimeType($att['file_type'])): ?>
                        <button
                            type="button"
                            class="btn p-0 border-0 bg-transparent lesson-attachment-preview-trigger"
                            data-file-url="<?= e($att['file_url']) ?>"
                            data-file-type="<?= e($att['file_type']) ?>"
                            data-file-name="<?= e($att['original_name']) ?>"
                            title="Preview attachment"
                        >
                            <img src="<?= e($att['file_url']) ?>" alt="<?= e($att['original_name']) ?>" style="width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid var(--gray-200);" loading="lazy">
                        </button>
                        <?php else: ?>
                        <div style="width:48px;height:48px;border-radius:8px;background:white;display:flex;align-items:center;justify-content:center;border:1px solid var(--gray-200);">
                            <i class="fas <?= getFileIcon($att['file_type']) ?>" style="font-size:1.3rem;"></i>
                        </div>
                        <?php endif; ?>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-600 text-truncate" style="font-size:0.85rem;"><?= e($att['original_name']) ?></div>
                            <small class="text-muted d-block"><?= formatFileSize($att['file_size']) ?></small>
                            <?php if (!empty($att['file_exists'])): ?>
                            <small class="text-success"><i class="fas fa-check-circle me-1"></i>Available</small>
                            <?php else: ?>
                            <small class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>File missing from storage</small>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-1">
                            <?php if (!empty($att['file_exists'])): ?>
                            <a href="<?= e($att['file_url']) ?>" download="<?= e($att['original_name']) ?>" class="btn btn-sm btn-outline-primary" title="Download"><i class="fas fa-download"></i></a>
                            <?php if (isPreviewableMimeType($att['file_type'])): ?>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-info lesson-attachment-preview-trigger"
                                data-file-url="<?= e($att['file_url']) ?>"
                                data-file-type="<?= e($att['file_type']) ?>"
                                data-file-name="<?= e($att['original_name']) ?>"
                                title="Preview"
                            ><i class="fas fa-eye"></i></button>
                            <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Preview unavailable" disabled><i class="fas fa-eye-slash"></i></button>
                            <?php endif; ?>
                            <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Download unavailable" disabled><i class="fas fa-download"></i></button>
                            <?php endif; ?>
                            <?php if ($role === 'instructor'): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this attachment?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_attachment">
                                <input type="hidden" name="attachment_id" value="<?= $att['id'] ?>">
                                <input type="hidden" name="return_view" value="<?= $viewLesson['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="p-3" style="background:var(--gray-50);border-radius:10px;border:1px solid var(--gray-100);">
                <span class="text-muted">No attachments have been uploaded for this lesson yet.</span>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($role === 'instructor'): ?>
        <hr class="my-3">
        <div class="mb-3">
            <h6 class="fw-bold"><i class="fas fa-plus-circle me-2 text-success"></i>Add More Attachments <small class="fw-normal text-muted">(max <?= $maxFiles ?> total, 10MB each)</small></h6>
            <form method="POST" enctype="multipart/form-data" class="d-flex gap-2 align-items-end flex-wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_attachments">
                <input type="hidden" name="lesson_id" value="<?= $viewLesson['id'] ?>">
                <input type="hidden" name="return_view" value="<?= $viewLesson['id'] ?>">
                <input type="hidden" name="client_attachment_count" id="lessonAddAttachmentCount" value="0">
                <input type="hidden" name="client_attachment_names" id="lessonAddAttachmentNames" value="">
                <div class="flex-grow-1">
                    <input type="file" name="attachments[]" id="lessonAddAttachmentInput" class="form-control" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar">
                </div>
                <button type="submit" class="btn btn-primary-gradient"><i class="fas fa-upload me-1"></i>Upload</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="lessonAttachmentPreviewModal" tabindex="-1" aria-labelledby="lessonAttachmentPreviewLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="lessonAttachmentPreviewLabel">Attachment Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="lessonAttachmentPreviewEmpty" class="text-center text-muted py-5">
                    <i class="bi bi-file-earmark-text d-block mb-3" style="font-size:2rem;"></i>
                    <div>Select an attachment to preview.</div>
                </div>
                <div id="lessonAttachmentPreviewImageWrap" class="d-none text-center">
                    <img id="lessonAttachmentPreviewImage" src="" alt="" class="img-fluid rounded shadow-sm" style="max-height:75vh;">
                </div>
                <div id="lessonAttachmentPreviewPdfWrap" class="d-none">
                    <iframe id="lessonAttachmentPreviewPdf" src="" title="Attachment preview" style="width:100%;height:75vh;border:0;border-radius:12px;"></iframe>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <div class="text-muted small" id="lessonAttachmentPreviewMeta">Preview attachments without leaving this page.</div>
                <a href="#" id="lessonAttachmentPreviewDownload" class="btn btn-primary-gradient" download><i class="fas fa-download me-1"></i>Download</a>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<?php if ($role === 'instructor'): ?>
<div class="mb-4">
    <button class="btn btn-primary-gradient" data-bs-toggle="modal" data-bs-target="#createLessonModal"><i class="fas fa-plus me-1"></i>New Lesson</button>
</div>
<?php endif; ?>

<?php if (empty($lessons)): ?>
<div class="empty-state">
    <svg viewBox="0 0 200 200" fill="none"><circle cx="100" cy="100" r="80" fill="#F1F5F9"/><text x="100" y="108" text-anchor="middle" fill="#94A3B8" font-size="40">📖</text></svg>
    <h5>No Lessons Yet</h5>
    <p><?= $role === 'instructor' ? 'Create your first lesson to get started.' : 'No lessons have been posted yet.' ?></p>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($lessons as $i => $lesson):
        $attCount = 0;
        try {
            $ac = $pdo->prepare("SELECT COUNT(*) FROM lesson_attachments WHERE lesson_id = ?");
            $ac->execute([$lesson['id']]);
            $attCount = $ac->fetchColumn();
        } catch (Exception $e) {}
    ?>
    <div class="col-12">
        <div class="card">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--primary-50),var(--primary-100));display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <span style="font-weight:800;color:var(--primary);"><?= $i + 1 ?></span>
                </div>
                <div class="flex-grow-1">
                    <h6 class="mb-0 fw-bold"><?= e($lesson['title']) ?></h6>
                    <div class="d-flex gap-2 flex-wrap mt-1">
                        <small class="text-muted"><?= formatDate($lesson['created_at']) ?></small>
                        <?php if ($lesson['video_url']): ?>
                        <span class="badge bg-danger bg-opacity-10 text-danger" style="font-size:0.7rem;"><i class="fas fa-video me-1"></i>Video</span>
                        <?php endif; ?>
                        <?php if ($lesson['link_url']): ?>
                        <span class="badge bg-info bg-opacity-10 text-info" style="font-size:0.7rem;"><i class="fas fa-link me-1"></i>Link</span>
                        <?php endif; ?>
                        <?php if ($attCount > 0): ?>
                        <span class="badge bg-warning bg-opacity-10 text-warning" style="font-size:0.7rem;"><i class="fas fa-paperclip me-1"></i><?= $attCount ?> file<?= $attCount > 1 ? 's' : '' ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= BASE_URL ?>/lessons.php?class_id=<?= $classId ?>&view=<?= $lesson['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye me-1"></i>View</a>
                    <?php if ($role === 'instructor'): ?>
                    <form method="POST" class="d-inline" onsubmit="return confirmForm(this, 'Delete this lesson? This action cannot be undone.', 'Delete Lesson')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="lesson_id" value="<?= $lesson['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($role === 'instructor'): ?>
<div class="modal fade lesson-creator-modal" id="createLessonModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered lesson-creator-dialog">
        <div class="modal-content lesson-creator-shell">
            <form method="POST" enctype="multipart/form-data" id="createLessonForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <textarea name="content" id="lessonContentInput" class="d-none"></textarea>
                <input type="hidden" name="client_attachment_count" id="lessonClientAttachmentCount" value="0">
                <input type="hidden" name="client_attachment_names" id="lessonClientAttachmentNames" value="">
                <input type="file" id="lessonAttachmentInput" name="attachments[]" class="d-none" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar">
                <input type="file" id="lessonAttachmentPicker" class="d-none" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar">
                <input type="file" id="lessonEditorImageInput" class="d-none" accept="image/*">

                <div class="modal-header lesson-modal-header">
                    <div>
                        <span class="lesson-modal-eyebrow">Lesson Composer</span>
                        <h5 class="modal-title">Create New Lesson</h5>
                        <p>Build a clean, modern module with formatted content, attachments, and media.</p>
                    </div>
                    <button type="button" class="btn-close lesson-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body lesson-modal-body">
                    <div class="lesson-composer-grid">
                        <div class="lesson-composer-main">
                            <section class="lesson-panel lesson-panel-soft">
                                <div class="lesson-section-title">
                                    <div>
                                        <h6>Lesson Title</h6>
                                        <p>Keep it clear and specific so students know what this module covers.</p>
                                    </div>
                                </div>
                                <input type="text" name="title" id="lessonTitleInput" class="form-control lesson-title-input" required placeholder="e.g. Introduction to Data Structures" autocomplete="off">
                            </section>

                            <section class="lesson-panel lesson-editor-panel">
                                <div class="lesson-section-title lesson-section-title-spaced">
                                    <div>
                                        <h6>Module Editor</h6>
                                        <p>Write rich content, switch to markdown, or review the final preview before publishing.</p>
                                    </div>
                                    <div class="lesson-editor-mode-switch" role="tablist" aria-label="Editor mode toggle">
                                        <button type="button" class="lesson-mode-btn active" data-editor-mode="rich">Rich</button>
                                        <button type="button" class="lesson-mode-btn" data-editor-mode="markdown">Markdown</button>
                                        <button type="button" class="lesson-mode-btn" data-editor-mode="preview">Preview</button>
                                    </div>
                                </div>

                                <div class="lesson-editor-toolbar" id="lessonEditorToolbar">
                                    <div class="lesson-toolbar-group">
                                        <button type="button" class="lesson-toolbar-btn" data-editor-action="bold" title="Bold"><i class="bi bi-type-bold"></i></button>
                                        <button type="button" class="lesson-toolbar-btn" data-editor-action="italic" title="Italic"><i class="bi bi-type-italic"></i></button>
                                    </div>
                                    <div class="lesson-toolbar-group">
                                        <button type="button" class="lesson-toolbar-btn" data-editor-action="heading" data-level="h1" title="Heading 1"><i class="bi bi-type-h1"></i></button>
                                        <button type="button" class="lesson-toolbar-btn" data-editor-action="heading" data-level="h2" title="Heading 2"><i class="bi bi-type-h2"></i></button>
                                        <button type="button" class="lesson-toolbar-btn" data-editor-action="quote" title="Quote"><i class="bi bi-blockquote-left"></i></button>
                                        <button type="button" class="lesson-toolbar-btn" data-editor-action="code" title="Code Block"><i class="bi bi-code-square"></i></button>
                                    </div>
                                    <div class="lesson-toolbar-group">
                                        <button type="button" class="lesson-toolbar-btn" data-editor-action="unorderedList" title="Bullet List"><i class="bi bi-list-ul"></i></button>
                                        <button type="button" class="lesson-toolbar-btn" data-editor-action="orderedList" title="Numbered List"><i class="bi bi-list-ol"></i></button>
                                        <button type="button" class="lesson-toolbar-btn" data-editor-action="table" title="Table"><i class="bi bi-table"></i></button>
                                        <button type="button" class="lesson-toolbar-btn" data-editor-action="divider" title="Divider"><i class="bi bi-dash-lg"></i></button>
                                    </div>
                                    <div class="lesson-toolbar-group">
                                        <button type="button" class="lesson-toolbar-btn" data-editor-action="link" title="Insert Link"><i class="bi bi-link-45deg"></i></button>
                                        <button type="button" class="lesson-toolbar-btn" data-editor-action="image" title="Insert Image"><i class="bi bi-image"></i></button>
                                    </div>
                                </div>

                                <div class="lesson-editor-stage">
                                    <div id="lessonRichEditor" class="lesson-rich-editor" contenteditable="true" data-placeholder="Write your module here…"></div>
                                    <textarea id="lessonMarkdownEditor" class="lesson-markdown-editor d-none" placeholder="Write your module here…"></textarea>
                                    <div id="lessonPreviewPane" class="lesson-preview-pane d-none">
                                        <div class="lesson-preview-content"></div>
                                    </div>
                                </div>

                                <div class="lesson-editor-footer">
                                    <span><i class="bi bi-lightbulb me-2"></i>Tip: use headings, callouts, and dividers to structure long lessons.</span>
                                    <span id="lessonDraftState"><i class="bi bi-cloud-check me-2"></i>Draft autosaves locally</span>
                                </div>
                            </section>
                        </div>

                        <aside class="lesson-composer-side">
                            <section class="lesson-panel lesson-panel-soft lesson-attachments-panel">
                                <div class="lesson-section-title">
                                    <div>
                                        <h6>Attachments</h6>
                                        <p>(images, documents, PDFs - max <?= (int)$maxFiles ?> files, <?= (int)round($maxFileSize / 1048576) ?>MB each)</p>
                                    </div>
                                </div>

                                <div class="lesson-dropzone" id="lessonAttachmentDropzone" tabindex="0" role="button">
                                    <div class="lesson-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                                    <strong>Drag &amp; drop files here</strong>
                                    <span>or click to browse from your device</span>
                                </div>

                                <div class="lesson-file-list" id="lessonAttachmentPreviewList"></div>
                                <div class="lesson-support-text">Supported: JPG, PNG, GIF, WebP, SVG, PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT, CSV, ZIP, RAR</div>
                            </section>

                            <section class="lesson-panel lesson-panel-soft">
                                <div class="lesson-section-title">
                                    <div>
                                        <h6><i class="bi bi-play-btn me-2"></i>Video Link</h6>
                                        <p>Optional. Paste a YouTube, Vimeo, or Google Drive link for embed support.</p>
                                    </div>
                                </div>
                                <div class="lesson-input-shell">
                                    <i class="bi bi-camera-video"></i>
                                    <input type="url" name="video_url" id="lessonVideoInput" class="form-control" placeholder="https://www.youtube.com/watch?v=...">
                                </div>
                            </section>

                            <section class="lesson-panel lesson-panel-soft">
                                <div class="lesson-section-title">
                                    <div>
                                        <h6><i class="bi bi-link-45deg me-2"></i>Reference Link</h6>
                                        <p>Optional supporting material such as slides, docs, or reading links.</p>
                                    </div>
                                </div>
                                <div class="lesson-side-stack">
                                    <div class="lesson-input-shell">
                                        <i class="bi bi-bookmark-star"></i>
                                        <input type="text" name="link_title" id="lessonLinkTitleInput" class="form-control" placeholder="Link title (e.g. Course Slides)">
                                    </div>
                                    <div class="lesson-input-shell">
                                        <i class="bi bi-globe"></i>
                                        <input type="url" name="link_url" id="lessonLinkUrlInput" class="form-control" placeholder="https://...">
                                    </div>
                                </div>
                            </section>
                        </aside>
                    </div>
                </div>

                <div class="modal-footer lesson-modal-footer">
                    <button type="button" class="btn lesson-btn-ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn lesson-btn-primary"><i class="bi bi-stars me-2"></i>Create Lesson</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
window.lessonComposerConfig = {
    draftKey: 'lesson-draft-<?= (int)$classId ?>',
    maxFiles: <?= (int)$maxFiles ?>,
    maxFileSize: <?= (int)$maxFileSize ?>
};

(function() {
    const addAttachmentInput = document.getElementById('lessonAddAttachmentInput');
    const addAttachmentCount = document.getElementById('lessonAddAttachmentCount');
    const addAttachmentNames = document.getElementById('lessonAddAttachmentNames');
    if (!addAttachmentInput || !addAttachmentCount || !addAttachmentNames) {
        return;
    }

    addAttachmentInput.addEventListener('change', function() {
        const files = Array.from(addAttachmentInput.files || []);
        addAttachmentCount.value = String(files.length);
        addAttachmentNames.value = files.map(file => file.name).join(' | ');
    });
})();

document.addEventListener('DOMContentLoaded', function() {
    const modalElement = document.getElementById('lessonAttachmentPreviewModal');
    if (!modalElement || !window.bootstrap || !window.bootstrap.Modal) {
        return;
    }

    const modal = new bootstrap.Modal(modalElement);
    const title = document.getElementById('lessonAttachmentPreviewLabel');
    const meta = document.getElementById('lessonAttachmentPreviewMeta');
    const download = document.getElementById('lessonAttachmentPreviewDownload');
    const emptyState = document.getElementById('lessonAttachmentPreviewEmpty');
    const imageWrap = document.getElementById('lessonAttachmentPreviewImageWrap');
    const image = document.getElementById('lessonAttachmentPreviewImage');
    const pdfWrap = document.getElementById('lessonAttachmentPreviewPdfWrap');
    const pdf = document.getElementById('lessonAttachmentPreviewPdf');

    function resetPreview() {
        emptyState.classList.remove('d-none');
        imageWrap.classList.add('d-none');
        pdfWrap.classList.add('d-none');
        image.src = '';
        image.alt = '';
        pdf.src = '';
        download.href = '#';
        download.removeAttribute('download');
        meta.textContent = 'Preview attachments without leaving this page.';
        title.textContent = 'Attachment Preview';
    }

    document.querySelectorAll('.lesson-attachment-preview-trigger').forEach(trigger => {
        trigger.addEventListener('click', function() {
            const fileUrl = this.dataset.fileUrl || '';
            const fileType = this.dataset.fileType || '';
            const fileName = this.dataset.fileName || 'Attachment Preview';

            resetPreview();
            title.textContent = fileName;
            meta.textContent = fileType || 'Attachment';
            download.href = fileUrl;
            download.setAttribute('download', fileName);

            if (fileType.startsWith('image/')) {
                emptyState.classList.add('d-none');
                imageWrap.classList.remove('d-none');
                image.src = fileUrl;
                image.alt = fileName;
            } else if (fileType === 'application/pdf') {
                emptyState.classList.add('d-none');
                pdfWrap.classList.remove('d-none');
                pdf.src = fileUrl;
            }

            modal.show();
        });
    });

    modalElement.addEventListener('hidden.bs.modal', resetPreview);
});
</script>
<script src="<?= BASE_URL ?>/assets/js/lesson-composer.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/lesson-composer.js') ?>"></script>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
