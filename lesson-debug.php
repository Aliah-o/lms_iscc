<?php
$pageTitle = 'Lesson Debug';
require_once __DIR__ . '/helpers/functions.php';
requireRole('superadmin', 'instructor');

$pdo = getDB();
$user = currentUser();
$role = $user['role'];
$classId = (int)($_GET['class_id'] ?? 0);
$lessonId = (int)($_GET['view'] ?? ($_GET['lesson_id'] ?? 0));

if (!$classId || !$lessonId) {
    flash('error', 'Missing class or lesson ID.');
    redirect('/classes.php');
}

if ($role === 'instructor') {
    $cls = $pdo->prepare("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id WHERE tc.id = ? AND tc.instructor_id = ?");
    $cls->execute([$classId, $user['id']]);
} else {
    $cls = $pdo->prepare("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id WHERE tc.id = ?");
    $cls->execute([$classId]);
}
$class = $cls->fetch();

if (!$class) {
    flash('error', 'Access denied.');
    redirect('/classes.php');
}

$lessonStmt = $pdo->prepare("SELECT * FROM lessons WHERE id = ? AND class_id = ?");
$lessonStmt->execute([$lessonId, $classId]);
$lesson = $lessonStmt->fetch();

if (!$lesson) {
    flash('error', 'Lesson not found.');
    redirect('/lessons.php?class_id=' . $classId);
}

function debugFormatBytes($bytes): string {
    $bytes = (int)$bytes;
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

function debugJson($value): string {
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false ? $json : 'Unable to encode JSON.';
}

function cleanDebugVideoUrl($url): string {
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
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

function getEmbeddableDebugVideoUrl($url): string {
    $embedUrl = cleanDebugVideoUrl($url);
    if ($embedUrl === '') {
        return '';
    }
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

function debugDescribePath($absolutePath): array {
    return [
        'absolute_path' => $absolutePath,
        'exists' => $absolutePath !== '' && file_exists($absolutePath),
        'is_file' => $absolutePath !== '' && is_file($absolutePath),
        'is_dir' => $absolutePath !== '' && is_dir($absolutePath),
        'is_writable' => $absolutePath !== '' && is_writable($absolutePath),
    ];
}

$attachmentRows = [];
$existingAttachmentCount = 0;
$missingAttachmentCount = 0;

$attachmentStmt = $pdo->prepare("SELECT * FROM lesson_attachments WHERE lesson_id = ? ORDER BY created_at DESC, id DESC");
$attachmentStmt->execute([$lessonId]);
$rawAttachments = $attachmentStmt->fetchAll();

foreach ($rawAttachments as $attachment) {
    $resolvedPath = getLessonAttachmentRelativePath($attachment);
    $absolutePath = getStorageAbsolutePath($resolvedPath);
    $fileExists = $resolvedPath !== '' && storageFileExists($resolvedPath);
    if ($fileExists) {
        $existingAttachmentCount++;
    } else {
        $missingAttachmentCount++;
    }

    $attachmentRows[] = [
        'id' => (int)$attachment['id'],
        'original_name' => $attachment['original_name'],
        'file_name' => $attachment['file_name'],
        'file_path' => $attachment['file_path'],
        'resolved_path' => $resolvedPath,
        'absolute_path' => $absolutePath,
        'file_exists' => $fileExists,
        'file_type' => $attachment['file_type'],
        'file_size' => (int)$attachment['file_size'],
        'created_at' => $attachment['created_at'],
        'file_url' => $fileExists ? storageUrl($resolvedPath) : '',
    ];
}

$uploadDirRelative = 'uploads/modules';
$uploadDirAbsolute = getStorageAbsolutePath($uploadDirRelative);
$uploadDirInfo = debugDescribePath($uploadDirAbsolute);

$matchingLessonFiles = [];
if ($uploadDirInfo['is_dir']) {
    $pattern = $uploadDirAbsolute . '/module_' . $lessonId . '_*';
    foreach (glob($pattern) ?: [] as $path) {
        $matchingLessonFiles[] = [
            'name' => basename($path),
            'size' => filesize($path) ?: 0,
            'modified_at' => date('Y-m-d H:i:s', filemtime($path) ?: time()),
            'absolute_path' => $path,
            'url' => storageUrl($uploadDirRelative . '/' . basename($path)),
        ];
    }
    usort($matchingLessonFiles, fn($a, $b) => strcmp($b['modified_at'], $a['modified_at']));
}

$recentModuleFiles = [];
if ($uploadDirInfo['is_dir']) {
    foreach (glob($uploadDirAbsolute . '/module_*') ?: [] as $path) {
        $recentModuleFiles[] = [
            'name' => basename($path),
            'size' => filesize($path) ?: 0,
            'modified_at' => date('Y-m-d H:i:s', filemtime($path) ?: time()),
            'absolute_path' => $path,
        ];
    }
    usort($recentModuleFiles, fn($a, $b) => strcmp($b['modified_at'], $a['modified_at']));
    $recentModuleFiles = array_slice($recentModuleFiles, 0, 12);
}

$embedVideoUrl = getEmbeddableDebugVideoUrl($lesson['video_url'] ?? '');
$uploadTempDir = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
$uploadTempInfo = debugDescribePath($uploadTempDir);
$uploadDebugSnapshot = loadLessonDebugSnapshot($lessonId);
$diagnostics = [];

if (!$uploadDirInfo['exists']) {
    $diagnostics[] = ['type' => 'danger', 'title' => 'Upload directory is missing', 'body' => 'The folder uploads/modules does not exist, so files cannot be stored there.'];
} elseif (!$uploadDirInfo['is_writable']) {
    $diagnostics[] = ['type' => 'danger', 'title' => 'Upload directory is not writable', 'body' => 'The folder uploads/modules exists but PHP cannot write files into it.'];
}

if (count($attachmentRows) === 0) {
    $diagnostics[] = ['type' => 'danger', 'title' => 'No attachment database rows exist', 'body' => 'lesson_attachments has 0 rows for this lesson, so the lesson page has nothing to display.'];
}

if (count($attachmentRows) > 0 && $existingAttachmentCount === 0) {
    $diagnostics[] = ['type' => 'warning', 'title' => 'Attachment rows exist but files are missing', 'body' => 'The database has attachment records, but none of the resolved files exist in storage.'];
}

if (count($attachmentRows) === 0 && count($matchingLessonFiles) === 0) {
    $diagnostics[] = ['type' => 'info', 'title' => 'No saved module files were found for this lesson', 'body' => 'uploads/modules does not contain any module_' . $lessonId . '_* file. This usually means the upload never reached the server or was not persisted.'];
}

if (($lesson['video_url'] ?? '') !== '' && $embedVideoUrl === '') {
    $diagnostics[] = ['type' => 'warning', 'title' => 'Video URL cannot be embedded', 'body' => 'The saved video URL does not resolve to a valid YouTube, Vimeo, or Google Drive embed URL.'];
}

if (ini_get('file_uploads') !== '1') {
    $diagnostics[] = ['type' => 'danger', 'title' => 'PHP file uploads are disabled', 'body' => 'The php.ini setting file_uploads is disabled.'];
}

if (!$uploadTempInfo['exists'] || !$uploadTempInfo['is_writable']) {
    $diagnostics[] = ['type' => 'warning', 'title' => 'Temporary upload directory may not be writable', 'body' => 'PHP uses a temp folder before moving uploads, and this path looks unavailable or read-only.'];
}

$debugUrl = BASE_URL . '/lesson-debug.php?class_id=' . $classId . '&view=' . $lessonId;
$lessonUrl = BASE_URL . '/lessons.php?class_id=' . $classId . '&view=' . $lessonId;
$breadcrumbPills = ['BSIT', YEAR_LEVELS[$class['year_level']], 'Section ' . $class['section_name']];

require_once __DIR__ . '/views/layouts/header.php';
?>

<style>
.debug-card {
    border: 1px solid rgba(148, 163, 184, 0.14);
    border-radius: 20px;
    box-shadow: 0 14px 32px rgba(15, 23, 42, 0.06);
}
.debug-kpi {
    border-radius: 18px;
    background: linear-gradient(180deg, rgba(255,255,255,0.9), rgba(248,250,252,0.96));
    border: 1px solid rgba(148, 163, 184, 0.14);
    padding: 18px 20px;
    height: 100%;
}
.debug-kpi strong {
    display: block;
    font-size: 1.75rem;
    color: var(--body-heading);
    line-height: 1;
    margin-bottom: 8px;
}
.debug-kpi span {
    font-size: 0.82rem;
    color: var(--body-text-muted);
    font-weight: 600;
}
.debug-label {
    font-size: 0.74rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--body-text-muted);
}
.debug-pre {
    margin: 0;
    padding: 16px;
    border-radius: 16px;
    background: #0f172a;
    color: #e2e8f0;
    font-size: 0.82rem;
    line-height: 1.65;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
}
.debug-table td,
.debug-table th {
    vertical-align: top;
}
.debug-path {
    font-family: Consolas, 'SFMono-Regular', monospace;
    font-size: 0.82rem;
    word-break: break-all;
}
</style>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-4">
    <div>
        <div class="d-flex flex-wrap gap-2 mb-2">
            <?php foreach ($breadcrumbPills as $pill): ?>
            <span class="badge rounded-pill bg-light text-dark border"><?= e($pill) ?></span>
            <?php endforeach; ?>
        </div>
        <h3 class="mb-1 fw-bold">Lesson Diagnostics</h3>
        <p class="text-muted mb-0">Inspecting lesson #<?= (int)$lessonId ?> in class #<?= (int)$classId ?>.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= e($lessonUrl) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Lesson</a>
        <a href="<?= e($debugUrl) ?>" class="btn btn-outline-dark btn-sm"><i class="bi bi-arrow-clockwise me-1"></i>Refresh Debug</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="debug-kpi">
            <span>Attachment Rows</span>
            <strong><?= count($attachmentRows) ?></strong>
            <div class="text-muted small">Rows found in `lesson_attachments`</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="debug-kpi">
            <span>Existing Files</span>
            <strong><?= (int)$existingAttachmentCount ?></strong>
            <div class="text-muted small">Resolved files physically present</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="debug-kpi">
            <span>Lesson Files</span>
            <strong><?= count($matchingLessonFiles) ?></strong>
            <div class="text-muted small">`module_<?= (int)$lessonId ?>_*` matches in storage</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="debug-kpi">
            <span>Video Embed</span>
            <strong><?= $embedVideoUrl !== '' ? 'OK' : 'No' ?></strong>
            <div class="text-muted small"><?= ($lesson['video_url'] ?? '') !== '' ? 'Saved URL checked for iframe support' : 'No saved video URL' ?></div>
        </div>
    </div>
</div>

<div class="card debug-card mb-4">
    <div class="card-header bg-transparent border-0 pt-4 px-4">
        <div class="debug-label mb-2">Current Diagnosis</div>
        <h5 class="mb-0 fw-bold">What this page sees right now</h5>
    </div>
    <div class="card-body pt-3 px-4 pb-4">
        <?php foreach ($diagnostics as $item): ?>
        <div class="alert alert-<?= e($item['type']) ?> mb-3">
            <strong><?= e($item['title']) ?></strong><br>
            <span><?= e($item['body']) ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (empty($diagnostics)): ?>
        <div class="alert alert-success mb-0">
            <strong>No blocking issue was detected.</strong><br>
            <span>The lesson has attachment records and the expected files are present.</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($uploadDebugSnapshot): ?>
<div class="card debug-card mb-4">
    <div class="card-header bg-transparent border-0 pt-4 px-4">
        <div class="debug-label mb-2">Last Upload Attempt</div>
        <h5 class="mb-0 fw-bold">Captured request snapshot</h5>
    </div>
    <div class="card-body pt-3 px-4 pb-4">
        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="debug-kpi">
                    <span>Action</span>
                    <strong style="font-size:1.15rem;"><?= e(strtoupper((string)($uploadDebugSnapshot['action'] ?? 'n/a'))) ?></strong>
                    <div class="text-muted small"><?= e((string)($uploadDebugSnapshot['captured_at'] ?? '')) ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="debug-kpi">
                    <span>Client Selected</span>
                    <strong><?= (int)($uploadDebugSnapshot['client_attachment_count'] ?? 0) ?></strong>
                    <div class="text-muted small">Files seen in the browser before submit</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="debug-kpi">
                    <span>PHP Received</span>
                    <strong><?= (int)($uploadDebugSnapshot['request_file_count'] ?? 0) ?></strong>
                    <div class="text-muted small">Entries present in `$_FILES`</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="debug-kpi">
                    <span>Uploaded</span>
                    <strong><?= (int)($uploadDebugSnapshot['upload_summary']['uploaded'] ?? 0) ?></strong>
                    <div class="text-muted small">Files successfully persisted</div>
                </div>
            </div>
        </div>

        <?php if (!empty($uploadDebugSnapshot['client_attachment_names'])): ?>
        <div class="mb-3">
            <div class="debug-label mb-2">Client Attachment Names</div>
            <pre class="debug-pre"><?= e(debugJson($uploadDebugSnapshot['client_attachment_names'])) ?></pre>
        </div>
        <?php endif; ?>

        <div class="mb-3">
            <div class="debug-label mb-2">Upload Summary</div>
            <pre class="debug-pre"><?= e(debugJson($uploadDebugSnapshot['upload_summary'] ?? [])) ?></pre>
        </div>

        <div class="mb-0">
            <div class="debug-label mb-2">Raw Request Snapshot</div>
            <pre class="debug-pre"><?= e(debugJson($uploadDebugSnapshot)) ?></pre>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card debug-card mb-4">
            <div class="card-header bg-transparent border-0 pt-4 px-4">
                <div class="debug-label mb-2">Lesson Record</div>
                <h5 class="mb-0 fw-bold"><?= e($lesson['title']) ?></h5>
            </div>
            <div class="card-body px-4 pb-4">
                <div class="table-responsive">
                    <table class="table debug-table">
                        <tbody>
                            <tr><th style="width:180px;">Lesson ID</th><td><?= (int)$lesson['id'] ?></td></tr>
                            <tr><th>Class ID</th><td><?= (int)$lesson['class_id'] ?></td></tr>
                            <tr><th>Created At</th><td><?= e($lesson['created_at']) ?></td></tr>
                            <tr><th>Video URL</th><td class="debug-path"><?= e($lesson['video_url'] ?? '') ?: '<em>Empty</em>' ?></td></tr>
                            <tr><th>Embeddable URL</th><td class="debug-path"><?= e($embedVideoUrl) ?: '<em>Not embeddable</em>' ?></td></tr>
                            <tr><th>Link URL</th><td class="debug-path"><?= e($lesson['link_url'] ?? '') ?: '<em>Empty</em>' ?></td></tr>
                            <tr><th>Content Length</th><td><?= strlen((string)($lesson['content'] ?? '')) ?> characters</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">
                    <div class="debug-label mb-2">Raw Lesson JSON</div>
                    <pre class="debug-pre"><?= e(debugJson($lesson)) ?></pre>
                </div>
            </div>
        </div>

        <div class="card debug-card mb-4">
            <div class="card-header bg-transparent border-0 pt-4 px-4">
                <div class="debug-label mb-2">Attachment Rows</div>
                <h5 class="mb-0 fw-bold">Database and file resolution details</h5>
            </div>
            <div class="card-body px-4 pb-4">
                <?php if (!empty($attachmentRows)): ?>
                <div class="table-responsive">
                    <table class="table debug-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Original</th>
                                <th>Resolved Path</th>
                                <th>Status</th>
                                <th>Size</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attachmentRows as $row): ?>
                            <tr>
                                <td><?= (int)$row['id'] ?></td>
                                <td>
                                    <div class="fw-semibold"><?= e($row['original_name']) ?></div>
                                    <div class="text-muted small"><?= e($row['file_type']) ?></div>
                                </td>
                                <td class="debug-path">
                                    <div><?= e($row['resolved_path']) ?: 'No resolved path' ?></div>
                                    <div class="text-muted"><?= e($row['absolute_path']) ?: 'No absolute path' ?></div>
                                </td>
                                <td>
                                    <?php if ($row['file_exists']): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Exists</span>
                                    <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Missing</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(debugFormatBytes($row['file_size'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">
                    <div class="debug-label mb-2">Raw Attachment JSON</div>
                    <pre class="debug-pre"><?= e(debugJson($attachmentRows)) ?></pre>
                </div>
                <?php else: ?>
                <div class="alert alert-warning mb-0">No rows exist in `lesson_attachments` for lesson #<?= (int)$lessonId ?>.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card debug-card mb-4">
            <div class="card-header bg-transparent border-0 pt-4 px-4">
                <div class="debug-label mb-2">Storage Check</div>
                <h5 class="mb-0 fw-bold">Uploads folder and matching files</h5>
            </div>
            <div class="card-body px-4 pb-4">
                <div class="table-responsive mb-3">
                    <table class="table debug-table">
                        <tbody>
                            <tr><th style="width:180px;">Upload Folder</th><td class="debug-path"><?= e($uploadDirAbsolute) ?></td></tr>
                            <tr><th>Exists</th><td><?= $uploadDirInfo['exists'] ? 'Yes' : 'No' ?></td></tr>
                            <tr><th>Writable</th><td><?= $uploadDirInfo['is_writable'] ? 'Yes' : 'No' ?></td></tr>
                            <tr><th>Module Limit</th><td><?= e(debugFormatBytes(MODULE_UPLOAD_MAX_SIZE)) ?></td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="debug-label mb-2">Files matching this lesson</div>
                <?php if (!empty($matchingLessonFiles)): ?>
                <div class="list-group mb-4">
                    <?php foreach ($matchingLessonFiles as $file): ?>
                    <div class="list-group-item">
                        <div class="fw-semibold"><?= e($file['name']) ?></div>
                        <div class="small text-muted"><?= e(debugFormatBytes($file['size'])) ?> • <?= e($file['modified_at']) ?></div>
                        <div class="debug-path mt-1"><?= e($file['absolute_path']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="alert alert-light border mb-4">No `module_<?= (int)$lessonId ?>_*` file exists in `uploads/modules`.</div>
                <?php endif; ?>

                <div class="debug-label mb-2">Recent module files in storage</div>
                <?php if (!empty($recentModuleFiles)): ?>
                <div class="list-group">
                    <?php foreach ($recentModuleFiles as $file): ?>
                    <div class="list-group-item">
                        <div class="fw-semibold"><?= e($file['name']) ?></div>
                        <div class="small text-muted"><?= e(debugFormatBytes($file['size'])) ?> • <?= e($file['modified_at']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="alert alert-light border mb-0">No module upload files exist yet in the storage folder.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card debug-card mb-4">
            <div class="card-header bg-transparent border-0 pt-4 px-4">
                <div class="debug-label mb-2">Upload Environment</div>
                <h5 class="mb-0 fw-bold">Server-side upload settings</h5>
            </div>
            <div class="card-body px-4 pb-4">
                <div class="table-responsive">
                    <table class="table debug-table mb-0">
                        <tbody>
                            <tr><th style="width:180px;">file_uploads</th><td><?= e((string)ini_get('file_uploads')) ?></td></tr>
                            <tr><th>upload_max_filesize</th><td><?= e((string)ini_get('upload_max_filesize')) ?></td></tr>
                            <tr><th>post_max_size</th><td><?= e((string)ini_get('post_max_size')) ?></td></tr>
                            <tr><th>max_file_uploads</th><td><?= e((string)ini_get('max_file_uploads')) ?></td></tr>
                            <tr><th>upload_tmp_dir</th><td class="debug-path"><?= e((string)ini_get('upload_tmp_dir')) ?: '<em>PHP default temp dir</em>' ?></td></tr>
                            <tr><th>sys_get_temp_dir()</th><td class="debug-path"><?= e(sys_get_temp_dir()) ?></td></tr>
                            <tr><th>Temp Dir Writable</th><td><?= $uploadTempInfo['is_writable'] ? 'Yes' : 'No' ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card debug-card">
            <div class="card-header bg-transparent border-0 pt-4 px-4">
                <div class="debug-label mb-2">Quick Links</div>
                <h5 class="mb-0 fw-bold">Open the exact pages</h5>
            </div>
            <div class="card-body px-4 pb-4">
                <div class="mb-3">
                    <div class="small text-muted mb-1">Lesson Page</div>
                    <a href="<?= e($lessonUrl) ?>" class="debug-path"><?= e($lessonUrl) ?></a>
                </div>
                <div class="mb-0">
                    <div class="small text-muted mb-1">Debug Page</div>
                    <a href="<?= e($debugUrl) ?>" class="debug-path"><?= e($debugUrl) ?></a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
