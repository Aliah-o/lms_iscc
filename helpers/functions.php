<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

initSession();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function currentUser($forceReload = false) {
    if (!isLoggedIn()) return null;
    static $user = null;
    if ($forceReload) {
        $user = null;
    }
    if ($user === null) {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            session_destroy();
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    }
    return $user;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function requireMFA() {
    requireLogin();
    if (empty($_SESSION['mfa_verified'])) {
        header('Location: ' . BASE_URL . '/verify_mfa.php');
        exit;
    }
}

function requireRole(...$roles) {
    requireLogin();
    $user = currentUser();
    if (!in_array($user['role'], $roles)) {
        http_response_code(403);
        include __DIR__ . '/../views/errors/403.php';
        exit;
    }
}

function hasRole($role) {
    $user = currentUser();
    return $user && $user['role'] === $role;
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function normalizePersonName(string $name): string {
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    // Normalize whitespace and title-case each word (e.g. "john doe" => "John Doe").
    $name = preg_replace('/\s+/', ' ', $name);
    $name = mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

    return $name;
}

function redirect($url) {
    header('Location: ' . BASE_URL . $url);
    exit;
}

function flash($key, $value = null) {
    if ($value !== null) {
        $_SESSION['flash'][$key] = $value;
    } else {
        $val = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $val;
    }
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function auditLog($action, $details = '') {
    $pdo = getDB();
    $userId = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $timestamp = date('Y-m-d H:i:s');

    try {
        $prevHash = getLastAuditHash();
        $blockData = json_encode([
            'user_id' => $userId,
            'action' => $action,
            'details' => $details,
            'ip' => $ip,
            'timestamp' => $timestamp,
            'prev_hash' => $prevHash,
        ]);
        $blockHash = hash('sha256', $blockData . $prevHash);
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, created_at, prev_hash, block_hash) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $details, $ip, $timestamp, $prevHash, $blockHash]);
    } catch (Exception $e) {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $details, $ip, $timestamp]);
    }
}

function getSetting($key, $default = '') {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

define('PASSWORD_MIN_LENGTH', 8);
define('UPLOAD_IMAGE_MAX_SIZE', 5 * 1024 * 1024);
define('MODULE_UPLOAD_MAX_SIZE', 10 * 1024 * 1024);

function dbTableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function dbColumnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function ensureDirectory(string $path): bool {
    if (is_dir($path)) {
        return true;
    }
    return mkdir($path, 0755, true);
}

function ensureRuntimeSchema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!isInstalled()) {
        return;
    }

    try {
        $pdo = getDB();

        if (dbTableExists($pdo, 'lesson_attachments') && !dbColumnExists($pdo, 'lesson_attachments', 'file_path')) {
            $pdo->exec("ALTER TABLE lesson_attachments ADD COLUMN file_path VARCHAR(255) DEFAULT NULL AFTER file_name");
        }

        if (dbTableExists($pdo, 'users') && !dbColumnExists($pdo, 'users', 'profile_picture')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL AFTER email");
        }

    if (dbTableExists($pdo, 'users') && !dbColumnExists($pdo, 'users', 'theme_mode')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN theme_mode ENUM('light','dark','custom') DEFAULT 'light' AFTER profile_picture");
    }

    if (dbTableExists($pdo, 'users') && !dbColumnExists($pdo, 'users', 'theme_accent')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN theme_accent VARCHAR(7) DEFAULT '#4F46E5' AFTER theme_mode");
    }

    if (dbTableExists($pdo, 'users') && !dbColumnExists($pdo, 'users', 'theme_sidebar')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN theme_sidebar VARCHAR(7) DEFAULT '#0F172A' AFTER theme_accent");
    }

    if (dbTableExists($pdo, 'users') && !dbColumnExists($pdo, 'users', 'theme_navbar')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN theme_navbar VARCHAR(7) DEFAULT '#FFFFFF' AFTER theme_sidebar");
    }

    // Set explicit default light theme for all users (admin, instructor, staff, student)
    if (dbTableExists($pdo, 'users')) {
        $pdo->exec("UPDATE users SET theme_mode = 'light' WHERE theme_mode IS NULL OR theme_mode = ''");
    }

        if (dbTableExists($pdo, 'support_tickets')) {
            if (!dbColumnExists($pdo, 'support_tickets', 'requester_email')) {
                $pdo->exec("ALTER TABLE support_tickets ADD COLUMN requester_email VARCHAR(255) DEFAULT NULL AFTER user_id");
            }
            if (!dbColumnExists($pdo, 'support_tickets', 'account_lookup_status')) {
                $pdo->exec("ALTER TABLE support_tickets ADD COLUMN account_lookup_status ENUM('matched','not_found') DEFAULT 'matched' AFTER requester_email");
            }
            if (!dbColumnExists($pdo, 'support_tickets', 'request_source')) {
                $pdo->exec("ALTER TABLE support_tickets ADD COLUMN request_source VARCHAR(50) DEFAULT NULL AFTER account_lookup_status");
            }
        }

        if (dbTableExists($pdo, 'kahoot_questions')) {
            if (!dbColumnExists($pdo, 'kahoot_questions', 'question_type')) {
                $pdo->exec("ALTER TABLE kahoot_questions ADD COLUMN question_type ENUM('multiple_choice','word_scramble') DEFAULT 'multiple_choice' AFTER question_text");
            }
            if (!dbColumnExists($pdo, 'kahoot_questions', 'correct_answer')) {
                $pdo->exec("ALTER TABLE kahoot_questions ADD COLUMN correct_answer VARCHAR(255) DEFAULT NULL AFTER question_type");
            }
        }

        if (dbTableExists($pdo, 'kahoot_answers') && !dbColumnExists($pdo, 'kahoot_answers', 'answer_text')) {
            $pdo->exec("ALTER TABLE kahoot_answers ADD COLUMN answer_text VARCHAR(255) DEFAULT NULL AFTER choice_id");
        }

        if (dbTableExists($pdo, 'settings')) {
            $defaults = [
                'theme_mode' => 'light',
                'theme_sidebar' => '#0F172A',
                'theme_navbar' => '#FFFFFF',
            ];

            $stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
            foreach ($defaults as $key => $value) {
                $stmt->execute([$key, $value]);
            }
        }
    } catch (Exception $e) {
        // Runtime safeguards should never block the page from loading.
    }

    $uploadRoots = [
        __DIR__ . '/../uploads/modules',
        __DIR__ . '/../uploads/profile',
        __DIR__ . '/../uploads/subjects',
    ];

    foreach ($uploadRoots as $dir) {
        try {
            ensureDirectory($dir);
        } catch (Exception $e) {
            // Ignore directory creation failures here and let the upload action report them later.
        }
    }
}

function normalizeHexColor($value, string $default = '#4F46E5'): string {
    $value = trim((string)$value);
    if (preg_match('/^#?[A-Fa-f0-9]{6}$/', $value) !== 1) {
        return strtoupper($default);
    }
    $value = strtoupper($value);
    return $value[0] === '#' ? $value : '#' . $value;
}

function mixHexColor(string $base, string $target, float $ratio): string {
    $base = normalizeHexColor($base);
    $target = normalizeHexColor($target);
    $ratio = max(0, min(1, $ratio));

    $mixed = [];
    for ($i = 1; $i <= 5; $i += 2) {
        $from = hexdec(substr($base, $i, 2));
        $to = hexdec(substr($target, $i, 2));
        $mixed[] = str_pad(dechex((int)round($from + (($to - $from) * $ratio))), 2, '0', STR_PAD_LEFT);
    }

    return '#' . strtoupper(implode('', $mixed));
}

function hexToRgba(string $hex, float $alpha): string {
    $hex = normalizeHexColor($hex);
    $alpha = max(0, min(1, $alpha));
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    return "rgba($r, $g, $b, $alpha)";
}

function getContrastColor(string $hex, string $dark = '#0F172A', string $light = '#FFFFFF'): string {
    $hex = normalizeHexColor($hex);
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    $brightness = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
    return $brightness >= 150 ? $dark : $light;
}

function getSettingValues(array $defaults): array {
    try {
        $pdo = getDB();
        $keys = array_keys($defaults);
        if (empty($keys)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);

        $settings = $defaults;
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        return $settings;
    } catch (Exception $e) {
        return $defaults;
    }
}

function getAppSettings(): array {
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    ensureRuntimeSchema();

    $defaults = [
        'app_name' => 'Ilocos Sur Community College',
        'app_logo' => '',
        'theme_mode' => 'light',
        'theme_accent' => '#4F46E5',
        'theme_sidebar' => '#0F172A',
        'theme_navbar' => '#FFFFFF',
    ];

    $settings = getSettingValues($defaults);
    $settings['theme_mode'] = in_array($settings['theme_mode'], ['light', 'dark', 'custom'], true) ? $settings['theme_mode'] : 'light';
    $settings['theme_accent'] = normalizeHexColor($settings['theme_accent'], $defaults['theme_accent']);
    $settings['theme_sidebar'] = normalizeHexColor($settings['theme_sidebar'], $defaults['theme_sidebar']);
    $settings['theme_navbar'] = normalizeHexColor($settings['theme_navbar'], $defaults['theme_navbar']);

    return $settings;
}

function getAppName(): string {
    $settings = getAppSettings();
    return trim((string)$settings['app_name']) !== '' ? $settings['app_name'] : APP_NAME;
}

function getAppLogoUrl(): string {
    $settings = getAppSettings();
    $logo = trim((string)$settings['app_logo']);
    if ($logo === '') {
        return BASE_URL . '/assets/css/logo.png';
    }
    if (preg_match('#^(https?:)?//#i', $logo) === 1 || str_starts_with($logo, 'data:')) {
        return $logo;
    }
    return BASE_URL . '/' . ltrim($logo, '/');
}

function getThemeConfig(): array {
    $settings = getAppSettings();

    $user = null;
    if (isLoggedIn()) {
        $user = currentUser();
    }

    // Admin theme should be controlled from app settings only. User-level overrides are removed.

    $mode = $settings['theme_mode'];
    $accent = $settings['theme_accent'];
    $sidebar = $settings['theme_sidebar'];
    $navbar = $settings['theme_navbar'];

    $vars = [
        '--primary' => $accent,
        '--primary-light' => mixHexColor($accent, '#FFFFFF', 0.18),
        '--primary-dark' => mixHexColor($accent, '#000000', 0.18),
        '--primary-50' => hexToRgba($accent, 0.10),
        '--primary-100' => hexToRgba($accent, 0.18),
        '--sidebar-bg-start' => $sidebar,
        '--sidebar-bg-end' => mixHexColor($sidebar, '#000000', 0.18),
        '--navbar-bg' => $navbar,
        '--navbar-text' => getContrastColor($navbar),
        '--surface-bg' => '#FFFFFF',
        '--surface-muted' => '#F8FAFC',
        '--surface-border' => '#E2E8F0',
        '--body-bg' => '#F1F5F9',
        '--body-text' => '#1E293B',
        '--body-text-muted' => '#64748B',
        '--body-text-subtle' => '#94A3B8',
        '--body-heading' => '#0F172A',
    ];

    if ($mode === 'dark') {
        $vars = array_merge($vars, [
            '--sidebar-bg-start' => mixHexColor($sidebar, '#090808', 0.18),
            '--sidebar-bg-end' => mixHexColor($sidebar, '#131111', 0.34),
            '--navbar-bg' => mixHexColor($navbar, '#111827', 0.70),
            '--navbar-text' => '#F8FAFC',
            '--surface-bg' => '#111827',
            '--surface-muted' => '#172033',
            '--surface-border' => '#25324A',
            '--body-bg' => '#0B1220',
            '--body-text' => '#E2E8F0',
            '--body-text-muted' => '#CBD5E1',
            '--body-text-subtle' => '#94A3B8',
            '--body-heading' => '#F8FAFC',
            '--gray-50' => '#0F172A',
            '--gray-100' => '#172033',
            '--gray-200' => '#25324A',
            '--gray-300' => '#334155',
            '--gray-400' => '#64748B',
            '--gray-500' => '#94A3B8',
            '--gray-600' => '#CBD5E1',
            '--gray-700' => '#E2E8F0',
            '--gray-800' => '#F8FAFC',
            '--dark' => '#F8FAFC',
            '--darker' => '#020617',
        ]);
    }

    if ($mode === 'custom') {
        $vars['--sidebar-bg-end'] = mixHexColor($sidebar, '#020617', 0.24);
        $vars['--navbar-text'] = getContrastColor($navbar);
    }

    return [
        'mode' => $mode,
        'vars' => $vars,
    ];
}

function getUserInitials(array $user): string {
    $first = trim((string)($user['first_name'] ?? ''));
    $last = trim((string)($user['last_name'] ?? ''));
    $initials = strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
    return $initials !== '' ? $initials : 'U';
}

function normalizeStorageRelativePath($path): string {
    $path = trim(str_replace('\\', '/', (string)$path));
    if ($path === '') {
        return '';
    }

    if (preg_match('#/uploads/#i', $path, $matches, PREG_OFFSET_CAPTURE) === 1) {
        $path = substr($path, $matches[0][1] + 1);
    }

    $segments = [];
    foreach (explode('/', ltrim($path, '/')) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            continue;
        }
        $segments[] = $segment;
    }

    return implode('/', $segments);
}

function getStorageAbsolutePath($path): string {
    $relative = normalizeStorageRelativePath($path);
    if ($relative === '') {
        return '';
    }
    return dirname(__DIR__) . '/' . $relative;
}

function storageFileExists($path): bool {
    $absolutePath = getStorageAbsolutePath($path);
    return $absolutePath !== '' && is_file($absolutePath);
}

function storageUrl($path): string {
    $relative = normalizeStorageRelativePath($path);
    if ($relative === '') {
        return '';
    }
    $segments = array_map('rawurlencode', explode('/', $relative));
    return BASE_URL . '/' . implode('/', $segments);
}

function deleteStorageFile($path): bool {
    $relative = normalizeStorageRelativePath($path);
    if ($relative === '' || !str_starts_with($relative, 'uploads/')) {
        return false;
    }

    $absolutePath = getStorageAbsolutePath($relative);
    if ($absolutePath === '' || !is_file($absolutePath)) {
        return false;
    }

    return unlink($absolutePath);
}

function sanitizeUploadOriginalName(string $name): string {
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
    return $name !== '' ? $name : 'file';
}

function validateUploadedFile(array $file, array $allowedMimeMap, array $allowedExtensions, int $maxSize, bool $required = false): array {
    $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return ['ok' => !$required, 'error' => $required ? 'No file selected.' : null];
    }

    if ($errorCode !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form size limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        ];
        return ['ok' => false, 'error' => $errMap[$errorCode] ?? 'Upload error.'];
    }

    if (($file['size'] ?? 0) > $maxSize) {
        $limit = $maxSize >= 1048576 ? round($maxSize / 1048576) . 'MB' : round($maxSize / 1024) . 'KB';
        return ['ok' => false, 'error' => "File exceeds maximum size of $limit."];
    }

    $originalName = sanitizeUploadOriginalName((string)($file['name'] ?? ''));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        return ['ok' => false, 'error' => 'File type not allowed.'];
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['ok' => false, 'error' => 'Invalid upload payload.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file($tmpName);
    if (!isset($allowedMimeMap[$mimeType])) {
        return ['ok' => false, 'error' => 'File MIME type not allowed.'];
    }

    return [
        'ok' => true,
        'error' => null,
        'mime_type' => $mimeType,
        'extension' => $extension,
        'original_name' => $originalName,
        'size' => (int)($file['size'] ?? 0),
    ];
}

function storeUploadedFile(array $file, string $relativeDir, string $prefix, array $allowedMimeMap, array $allowedExtensions, int $maxSize): array {
    $validation = validateUploadedFile($file, $allowedMimeMap, $allowedExtensions, $maxSize, false);
    if (!$validation['ok']) {
        return $validation;
    }

    $relativeDir = trim(normalizeStorageRelativePath($relativeDir), '/');
    if ($relativeDir === '') {
        return ['ok' => false, 'error' => 'Invalid upload directory.'];
    }

    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
    if (!ensureDirectory($absoluteDir)) {
        return ['ok' => false, 'error' => 'Unable to prepare the upload directory.'];
    }

    $filename = sprintf(
        '%s_%s_%s.%s',
        preg_replace('/[^A-Za-z0-9_-]/', '_', $prefix),
        time(),
        bin2hex(random_bytes(6)),
        $validation['extension']
    );
    $relativePath = $relativeDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $absoluteDir . '/' . $filename)) {
        return ['ok' => false, 'error' => 'Failed to save the uploaded file.'];
    }

    return [
        'ok' => true,
        'error' => null,
        'relative_path' => $relativePath,
        'file_name' => $filename,
        'original_name' => $validation['original_name'],
        'mime_type' => $validation['mime_type'],
        'size' => $validation['size'],
    ];
}

function isImageMimeType(string $mimeType): bool {
    return str_starts_with($mimeType, 'image/');
}

function isPreviewableMimeType(string $mimeType): bool {
    return isImageMimeType($mimeType) || $mimeType === 'application/pdf';
}

function getLessonAttachmentRelativePath(array $attachment): string {
    $candidates = [];

    if (!empty($attachment['file_path'])) {
        $candidates[] = normalizeStorageRelativePath($attachment['file_path']);
    }
    if (!empty($attachment['file_name'])) {
        $candidates[] = 'uploads/modules/' . basename($attachment['file_name']);
        $candidates[] = 'uploads/lessons/' . basename($attachment['file_name']);
    }

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && storageFileExists($candidate)) {
            return $candidate;
        }
    }

    return $candidates[0] ?? '';
}

function getUserAvatarUrl(array $user): string {
    $relativePath = $user['profile_picture'] ?? '';
    if (storageFileExists($relativePath)) {
        return storageUrl($relativePath);
    }

    $initials = getUserInitials($user);
    $hash = crc32(strtolower(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))));
    $colors = ['#4F46E5', '#0EA5E9', '#10B981', '#F59E0B', '#EC4899', '#8B5CF6', '#F97316'];
    $firstColor = $colors[$hash % count($colors)];
    $secondColor = $colors[($hash + 3) % count($colors)];

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128">'
         . '<defs><linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%">'
         . '<stop offset="0%" stop-color="' . $firstColor . '"/>'
         . '<stop offset="100%" stop-color="' . $secondColor . '"/>'
         . '</linearGradient></defs>'
         . '<rect width="100%" height="100%" fill="url(#g)" rx="24" ry="24"/>'
         . '<text x="50%" y="58%" text-anchor="middle" dominant-baseline="central" font-family="Inter, system-ui, sans-serif" font-size="54" font-weight="700" fill="#ffffff">'
         . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8')
         . '</text></svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function getSubjectImageUrl(array $class): string {
    $relativePath = $class['subject_image'] ?? '';
    return storageFileExists($relativePath) ? storageUrl($relativePath) : '';
}

function getPasswordMinLength(): int {
    return PASSWORD_MIN_LENGTH;
}

function getLessonDebugSnapshotPath(int $lessonId): string {
    return dirname(__DIR__) . '/runtime/lesson-debug/lesson_' . max(0, $lessonId) . '.json';
}

function saveLessonDebugSnapshot(int $lessonId, array $payload): bool {
    $path = getLessonDebugSnapshotPath($lessonId);
    $dir = dirname($path);
    if (!ensureDirectory($dir)) {
        return false;
    }

    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }

    return file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) !== false;
}

function loadLessonDebugSnapshot(int $lessonId): ?array {
    $path = getLessonDebugSnapshotPath($lessonId);
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

ensureRuntimeSchema();


function loadCurriculum($forceReload = false) {
    static $curriculum = null;
    if ($curriculum === null || $forceReload) {
        $path = __DIR__ . '/../course.json';
        $json = file_get_contents($path);
        $data = json_decode($json, true);
        $curriculum = $data['BSIT_Curriculum'] ?? [];
    }
    return $curriculum;
}

function saveCurriculum($curriculum) {
    $path = __DIR__ . '/../course.json';
    $payload = [
        'BSIT_Curriculum' => $curriculum,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $written = file_put_contents($path, $json . PHP_EOL, LOCK_EX);
    if ($written === false) {
        return false;
    }

    loadCurriculum(true);
    return true;
}

function getCurriculumSubjects($yearLevel = null, $semester = null) {
    $curriculum = loadCurriculum();
    $yearMap = [1 => 'First_Year', 2 => 'Second_Year', 3 => 'Third_Year', 4 => 'Fourth_Year'];
    $semMap = ['First Semester' => 'First_Semester', 'Second Semester' => 'Second_Semester', 'Mid-Year' => 'Mid_Year'];
    $subjects = [];

    foreach ($yearMap as $yr => $yrKey) {
        if ($yearLevel !== null && $yr !== (int)$yearLevel) continue;
        if (!isset($curriculum[$yrKey])) continue;
        foreach ($curriculum[$yrKey] as $semKey => $subjs) {
            $semLabel = array_search($semKey, $semMap);
            if ($semLabel === false) continue;
            if ($semester !== null && $semLabel !== $semester) continue;
            foreach ($subjs as $subj) {
                $subj['year_level'] = $yr;
                $subj['semester'] = $semLabel;
                $subjects[] = $subj;
            }
        }
    }
    return $subjects;
}

function getSubjectByCode($code) {
    $subjects = getCurriculumSubjects();
    foreach ($subjects as $subj) {
        if (strcasecmp($subj['code'], $code) === 0) return $subj;
    }
    return null;
}

function getSubjectSemester($code) {
    $subj = getSubjectByCode($code);
    return $subj ? $subj['semester'] : null;
}

function getSubjectYearLevel($code) {
    $subj = getSubjectByCode($code);
    return $subj ? $subj['year_level'] : null;
}

function getSubjectPrerequisite($code) {
    $subj = getSubjectByCode($code);
    return $subj ? ($subj['prerequisite'] ?? 'None') : 'None';
}

function generateClassCode() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $pdo = getDB();
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $check = $pdo->prepare("SELECT id FROM instructor_classes WHERE class_code = ?");
        $check->execute([$code]);
    } while ($check->fetch());
    return $code;
}

function getSectionDisplay($yearLevel, $sectionName) {
    return $yearLevel . $sectionName;
}

function normalizeStudentId($value) {
    $value = strtoupper(trim((string)$value));
    return preg_replace('/\s+/', '', $value);
}

function isValidStudentId($value) {
    return preg_match('/^[A-Z]-\d{2}-\d{2,6}$/', normalizeStudentId($value)) === 1;
}

function hasCompletedPrerequisite($studentId, $prerequisite) {
    if (empty($prerequisite) || strtolower($prerequisite) === 'none') return true;

    $pdo = getDB();
    $prereqs = array_map('trim', explode(',', $prerequisite));

    foreach ($prereqs as $prereq) {
        $prereq = trim($prereq);
        if (empty($prereq) || strtolower($prereq) === 'none') continue;
        if (stripos($prereq, 'standing') !== false) continue;

        $stmt = $pdo->prepare("SELECT id FROM student_completed_subjects WHERE student_id = ? AND course_code = ?");
        $stmt->execute([$studentId, $prereq]);
        if (!$stmt->fetch()) return false;
    }
    return true;
}

function validateSubjectSemester($courseCode, $semester) {
    $subj = getSubjectByCode($courseCode);
    if (!$subj) return true;
    return $subj['semester'] === $semester;
}

function validateSubjectYearLevel($courseCode, $yearLevel) {
    $subj = getSubjectByCode($courseCode);
    if (!$subj) return true;
    return (int)$subj['year_level'] === (int)$yearLevel;
}

function addNotification($userId, $type, $message, $referenceId = null, $link = null) {
    $pdo = getDB();
    try {
        $pdo->prepare("INSERT INTO notifications (user_id, type, message, reference_id, link) VALUES (?, ?, ?, ?, ?)")
            ->execute([$userId, $type, $message, $referenceId, $link]);
    } catch (Exception $e) {
        // Fallback if link column doesn't exist yet
        $pdo->prepare("INSERT INTO notifications (user_id, type, message, reference_id) VALUES (?, ?, ?, ?)")
            ->execute([$userId, $type, $message, $referenceId]);
    }
}

function getUnreadNotificationCount($userId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

function getUnreadTicketNotificationCount($userId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND (type = 'ticket' OR type LIKE 'ticket\\_%')");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

function markTicketNotificationsRead($userId): void {
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0 AND (type = 'ticket' OR type LIKE 'ticket\\_%')");
        $stmt->execute([$userId]);
    } catch (Exception $e) {
        // Ignore notification cleanup failures so the page can still load.
    }
}

function getNotifications($userId, $limit = 20) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

function addForumKarma($userId, $points) {
    $pdo = getDB();
    try {
        $pdo->prepare("UPDATE users SET forum_karma = forum_karma + ? WHERE id = ?")->execute([$points, $userId]);
    } catch (Exception $e) {}
}

function getForumKarmaLabel($karma) {
    if ($karma >= 150) return ['label' => 'Elite', 'color' => '#EF4444', 'icon' => 'fa-crown'];
    if ($karma >= 50) return ['label' => 'Established', 'color' => '#F59E0B', 'icon' => 'fa-star'];
    if ($karma >= 10) return ['label' => 'Contributor', 'color' => '#10B981', 'icon' => 'fa-hands-helping'];
    return ['label' => 'Newcomer', 'color' => '#94A3B8', 'icon' => 'fa-seedling'];
}

function getLastGradeChainHash() {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT block_hash FROM grade_chain ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch();
    return $row ? $row['block_hash'] : 'GENESIS';
}

function addGradeToChain($studentId, $classId, $courseCode, $subjectName, $component, $gradingPeriod, $score, $recordedBy) {
    $pdo = getDB();
    $prevHash = getLastGradeChainHash();
    $timestamp = date('Y-m-d H:i:s');

    $blockData = json_encode([
        'student_id' => $studentId,
        'class_id' => $classId,
        'course_code' => $courseCode,
        'subject_name' => $subjectName,
        'component' => $component,
        'grading_period' => $gradingPeriod,
        'score' => $score,
        'recorded_by' => $recordedBy,
        'timestamp' => $timestamp,
        'prev_hash' => $prevHash,
    ]);

    $blockHash = hash('sha256', $blockData . $prevHash);

    $stmt = $pdo->prepare("INSERT INTO grade_chain (student_id, class_id, course_code, subject_name, component, grading_period, score, recorded_by, prev_hash, block_hash, block_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$studentId, $classId, $courseCode, $subjectName, $component, $gradingPeriod, $score, $recordedBy, $prevHash, $blockHash, $blockData]);

    return $blockHash;
}

function verifyGradeChain() {
    $pdo = getDB();
    $blocks = $pdo->query("SELECT * FROM grade_chain ORDER BY id ASC")->fetchAll();
    $results = ['valid' => true, 'total_blocks' => count($blocks), 'tampered_blocks' => [], 'verified_at' => date('Y-m-d H:i:s')];

    $expectedPrevHash = 'GENESIS';
    foreach ($blocks as $block) {
        if ($block['prev_hash'] !== $expectedPrevHash) {
            $results['valid'] = false;
            $results['tampered_blocks'][] = ['id' => $block['id'], 'reason' => 'Chain break: prev_hash mismatch', 'expected' => $expectedPrevHash, 'actual' => $block['prev_hash']];
        }

        $expectedHash = hash('sha256', $block['block_data'] . $block['prev_hash']);
        if ($block['block_hash'] !== $expectedHash) {
            $results['valid'] = false;
            $results['tampered_blocks'][] = ['id' => $block['id'], 'reason' => 'Data tampered: block_hash mismatch', 'expected' => $expectedHash, 'actual' => $block['block_hash']];
        }

        $expectedPrevHash = $block['block_hash'];
    }
    return $results;
}

function rollbackGradeChain($blockId) {
    $pdo = getDB();
    $block = $pdo->prepare("SELECT * FROM grade_chain WHERE id = ?");
    $block->execute([$blockId]);
    $tamperedBlock = $block->fetch();
    if (!$tamperedBlock) return false;

    $pdo->prepare("UPDATE grade_chain SET is_valid = 0 WHERE id >= ?")->execute([$blockId]);

    $prevBlock = $pdo->prepare("SELECT block_hash FROM grade_chain WHERE id < ? AND is_valid = 1 ORDER BY id DESC LIMIT 1");
    $prevBlock->execute([$blockId]);
    $prev = $prevBlock->fetch();
    $prevHash = $prev ? $prev['block_hash'] : 'GENESIS';

    $invalidBlocks = $pdo->prepare("SELECT * FROM grade_chain WHERE id >= ? AND is_valid = 0 ORDER BY id ASC");
    $invalidBlocks->execute([$blockId]);
    $reChained = 0;

    foreach ($invalidBlocks->fetchAll() as $inv) {
        $blockData = json_encode([
            'student_id' => $inv['student_id'],
            'class_id' => $inv['class_id'],
            'course_code' => $inv['course_code'],
            'subject_name' => $inv['subject_name'],
            'component' => $inv['component'],
            'grading_period' => $inv['grading_period'],
            'score' => $inv['score'],
            'recorded_by' => $inv['recorded_by'],
            'timestamp' => $inv['created_at'],
            'prev_hash' => $prevHash,
        ]);
        $newHash = hash('sha256', $blockData . $prevHash);
        $pdo->prepare("UPDATE grade_chain SET prev_hash = ?, block_hash = ?, block_data = ?, is_valid = 1 WHERE id = ?")
            ->execute([$prevHash, $newHash, $blockData, $inv['id']]);
        $prevHash = $newHash;
        $reChained++;
    }
    return $reChained;
}

function getLastAuditHash() {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT block_hash FROM audit_logs WHERE block_hash IS NOT NULL ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch();
    return $row ? $row['block_hash'] : 'GENESIS';
}

function auditLogChained($action, $details = '') {
    $pdo = getDB();
    $userId = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $prevHash = getLastAuditHash();
    $timestamp = date('Y-m-d H:i:s');

    $blockData = json_encode([
        'user_id' => $userId,
        'action' => $action,
        'details' => $details,
        'ip' => $ip,
        'timestamp' => $timestamp,
        'prev_hash' => $prevHash,
    ]);
    $blockHash = hash('sha256', $blockData . $prevHash);

    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, created_at, prev_hash, block_hash) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $action, $details, $ip, $timestamp, $prevHash, $blockHash]);
    return $blockHash;
}

function verifyAuditChain() {
    $pdo = getDB();
    $blocks = $pdo->query("SELECT * FROM audit_logs WHERE block_hash IS NOT NULL ORDER BY id ASC")->fetchAll();
    $results = ['valid' => true, 'total_blocks' => count($blocks), 'tampered_blocks' => []];

    $expectedPrevHash = 'GENESIS';
    foreach ($blocks as $block) {
        if ($block['prev_hash'] !== $expectedPrevHash) {
            $results['valid'] = false;
            $results['tampered_blocks'][] = ['id' => $block['id'], 'reason' => 'Chain break: prev_hash mismatch'];
        }
        $blockData = json_encode([
            'user_id' => $block['user_id'],
            'action' => $block['action'],
            'details' => $block['details'],
            'ip' => $block['ip_address'],
            'timestamp' => $block['created_at'],
            'prev_hash' => $block['prev_hash'],
        ]);
        $expectedHash = hash('sha256', $blockData . $block['prev_hash']);
        if ($block['block_hash'] !== $expectedHash) {
            $results['valid'] = false;
            $results['tampered_blocks'][] = ['id' => $block['id'], 'reason' => 'Data tampered: hash mismatch'];
        }
        $expectedPrevHash = $block['block_hash'];
    }
    return $results;
}

function rollbackAuditChain($blockId) {
    $pdo = getDB();
    $blocks = $pdo->prepare("SELECT * FROM audit_logs WHERE id >= ? AND block_hash IS NOT NULL ORDER BY id ASC");
    $blocks->execute([$blockId]);
    $allBlocks = $blocks->fetchAll();
    if (empty($allBlocks)) return 0;

    $prevBlock = $pdo->prepare("SELECT block_hash FROM audit_logs WHERE id < ? AND block_hash IS NOT NULL ORDER BY id DESC LIMIT 1");
    $prevBlock->execute([$blockId]);
    $prev = $prevBlock->fetch();
    $prevHash = $prev ? $prev['block_hash'] : 'GENESIS';

    $reChained = 0;
    foreach ($allBlocks as $b) {
        $blockData = json_encode([
            'user_id' => $b['user_id'],
            'action' => $b['action'],
            'details' => $b['details'],
            'ip' => $b['ip_address'],
            'timestamp' => $b['created_at'],
            'prev_hash' => $prevHash,
        ]);
        $newHash = hash('sha256', $blockData . $prevHash);
        $pdo->prepare("UPDATE audit_logs SET prev_hash = ?, block_hash = ? WHERE id = ?")
            ->execute([$prevHash, $newHash, $b['id']]);
        $prevHash = $newHash;
        $reChained++;
    }
    return $reChained;
}

function generateCertificate($studentId, $classId, $finalGrade, $issuedBy) {
    $pdo = getDB();

    $cls = $pdo->prepare("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id WHERE tc.id = ?");
    $cls->execute([$classId]);
    $classInfo = $cls->fetch();
    if (!$classInfo) return null;

    $stu = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stu->execute([$studentId]);
    $student = $stu->fetch();
    if (!$student) return null;

    $gradeStatus = $finalGrade >= 75 ? 'PASSED' : 'FAILED';
    $timestamp = date('Y-m-d H:i:s');
    $academicYear = date('Y') . '-' . (date('Y') + 1);

    $certData = json_encode([
        'student_id' => $studentId,
        'student_name' => $student['first_name'] . ' ' . $student['last_name'],
        'class_id' => $classId,
        'course_code' => $classInfo['course_code'],
        'subject_name' => $classInfo['subject_name'],
        'final_grade' => $finalGrade,
        'grade_status' => $gradeStatus,
        'issued_by' => $issuedBy,
        'timestamp' => $timestamp,
    ]);
    $certHash = hash('sha256', $certData . $timestamp . random_bytes(8));

    $pdo->prepare("INSERT INTO certificates (student_id, class_id, course_code, subject_name, final_grade, grade_status, certificate_hash, qr_data, issued_by, semester, academic_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $studentId,
            $classId,
            $classInfo['course_code'],
            $classInfo['subject_name'],
            $finalGrade,
            $gradeStatus,
            $certHash,
            $certData,
            $issuedBy,
            $classInfo['semester'],
            $academicYear
        ]);

    return $certHash;
}

function verifyCertificate($hash) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT c.*, u.first_name as student_fn, u.last_name as student_ln, u.username, i.first_name as issuer_fn, i.last_name as issuer_ln FROM certificates c JOIN users u ON c.student_id = u.id JOIN users i ON c.issued_by = i.id WHERE c.certificate_hash = ?");
    $stmt->execute([$hash]);
    return $stmt->fetch();
}


// ─── Attendance → Grades Auto-Sync (per-period) ───
function syncAttendanceToGrades($pdo, $classId, $updatedBy) {
    try {
        // Try period-aware query first (grading_period column exists)
        foreach (['midterm', 'final'] as $period) {
            $stmt = $pdo->prepare("SELECT student_id,
                SUM(status = 'present') as present_count,
                SUM(status = 'late') as late_count,
                COUNT(*) as total_days
                FROM attendance WHERE class_id = ? AND grading_period = ?
                GROUP BY student_id");
            $stmt->execute([$classId, $period]);
            $periodResults = $stmt->fetchAll();

            // Get all enrolled students for this class
            $enrolledStmt = $pdo->prepare("SELECT student_id FROM class_enrollments WHERE class_id = ?");
            $enrolledStmt->execute([$classId]);
            $enrolledIds = $enrolledStmt->fetchAll(PDO::FETCH_COLUMN);

            // Build lookup of students who DO have attendance this period
            $hasAttendance = [];
            foreach ($periodResults as $row) {
                $total = intval($row['total_days']);
                if ($total === 0) continue;
                $score = round(($row['present_count'] + $row['late_count']) / $total * 100, 2);
                $score = min(100, max(0, $score));
                $hasAttendance[$row['student_id']] = $score;

                $pdo->prepare("INSERT INTO grades (class_id, student_id, component, score, grading_period, updated_by)
                    VALUES (?, ?, 'attendance', ?, ?, ?)
                    ON DUPLICATE KEY UPDATE score = VALUES(score), updated_by = VALUES(updated_by), updated_at = NOW()")
                    ->execute([$classId, $row['student_id'], $score, $period, $updatedBy]);
            }

            // Clear attendance grade for students with NO records in this period
            foreach ($enrolledIds as $sid) {
                if (!isset($hasAttendance[$sid])) {
                    $pdo->prepare("DELETE FROM grades WHERE class_id = ? AND student_id = ? AND component = 'attendance' AND grading_period = ?")
                        ->execute([$classId, $sid, $period]);
                }
            }
        }
    } catch (Exception $e) {
        // Fallback: grading_period column may not exist yet - sync same score to both
        try {
            $stmt = $pdo->prepare("SELECT student_id,
                SUM(status = 'present') as present_count,
                SUM(status = 'late') as late_count,
                COUNT(*) as total_days
                FROM attendance WHERE class_id = ?
                GROUP BY student_id");
            $stmt->execute([$classId]);
            while ($row = $stmt->fetch()) {
                $total = intval($row['total_days']);
                if ($total === 0) continue;
                $score = round(($row['present_count'] + $row['late_count']) / $total * 100, 2);
                $score = min(100, max(0, $score));
                foreach (['midterm', 'final'] as $period) {
                    $pdo->prepare("INSERT INTO grades (class_id, student_id, component, score, grading_period, updated_by)
                        VALUES (?, ?, 'attendance', ?, ?, ?)
                        ON DUPLICATE KEY UPDATE score = VALUES(score), updated_by = VALUES(updated_by), updated_at = NOW()")
                        ->execute([$classId, $row['student_id'], $score, $period, $updatedBy]);
                }
            }
        } catch (Exception $e2) {}
    }
}

// ─── Graded Activities → Component Grades Auto-Sync ───
function syncActivityToGrades($pdo, $classId, $gradingPeriod, $updatedBy) {
    $typeToComponent = [
        'quiz' => 'quiz',
        'lab_activity' => 'activity',
        'exam' => 'exam',
    ];

    foreach ($typeToComponent as $actType => $component) {
        try {
            $stmt = $pdo->prepare("
                SELECT gas.student_id,
                       AVG(gas.score / ga.max_score * 100) as avg_pct
                FROM graded_activity_scores gas
                JOIN graded_activities ga ON gas.activity_id = ga.id
                WHERE ga.class_id = ? AND ga.activity_type = ? AND ga.grading_period = ?
                GROUP BY gas.student_id
            ");
            $stmt->execute([$classId, $actType, $gradingPeriod]);

            while ($row = $stmt->fetch()) {
                $score = min(100, max(0, round(floatval($row['avg_pct']), 2)));
                $pdo->prepare("INSERT INTO grades (class_id, student_id, component, score, grading_period, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE score = VALUES(score), updated_by = VALUES(updated_by), updated_at = NOW()")
                    ->execute([$classId, $row['student_id'], $component, $score, $gradingPeriod, $updatedBy]);
            }
        } catch (Exception $e) {
            // Silently fail
        }
    }
}

// ─── School Year Functions ───
function getActiveSchoolYear() {
    $pdo = getDB();
    try {
        $stmt = $pdo->query("SELECT * FROM school_years WHERE status = 'active' ORDER BY end_date DESC LIMIT 1");
        return $stmt->fetch() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function archiveExpiredSchoolYears() {
    $pdo = getDB();
    $today = date('Y-m-d');
    $archived = 0;

    try {
        $stmt = $pdo->prepare("SELECT id FROM school_years WHERE status = 'active' AND end_date < ?");
        $stmt->execute([$today]);
        $expired = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($expired as $syId) {
            $pdo->prepare("UPDATE school_years SET status = 'archived', updated_at = NOW() WHERE id = ?")->execute([$syId]);
            $pdo->prepare("UPDATE instructor_classes SET is_active = 0 WHERE school_year_id = ?")->execute([$syId]);
            $archived++;
        }

        if ($archived > 0) {
            try {
                auditLog('school_year_archived', "Auto-archived $archived expired school year(s)");
            } catch (Exception $e) {}
        }
    } catch (Exception $e) {
        // school_years table may not exist yet
    }

    return $archived;
}

// ─── BSIT-Only Subject Filter ───
function getBSITOnlySubjects($yearLevel = null, $semester = null) {
    $all = getCurriculumSubjects($yearLevel, $semester);
    // Exclude GE electives, PATHFIT, NSTP/ROTC, and general education courses
    $excludeCodes = ['GE1','GE2','GE3','GE4','GE5','GE6','GE7',
        'PATHFIT1','PATHFIT2','PATHFIT3','PATHFIT4',
        'NSTP1/ROTC1','NSTP2/ROTC2',
        'UNS','MATH','PCOM','STS','ICS','MAN','ARTAP','ETHICS','FIL'];
    return array_filter($all, function($subj) use ($excludeCodes) {
        return !in_array(strtoupper($subj['code']), $excludeCodes);
    });
}

// ─── Submission & Activity Helpers ───
define('SUBMISSION_MAX_SIZE', 25 * 1024 * 1024); // 25MB
define('SUBMISSION_ALLOWED_TYPES', [
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'text/plain' => 'txt',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
]);
define('SUBMISSION_ALLOWED_EXTS', ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt','jpg','jpeg','png']);

/**
 * Get the current status of an activity for display
 * Returns: 'not_open', 'open', 'overdue', 'closed'
 */
function getActivityStatus($activity) {
    $now = time();
    if (!empty($activity['open_date']) && $now < strtotime($activity['open_date'])) {
        return 'not_open';
    }
    if (!empty($activity['close_date']) && $now > strtotime($activity['close_date'])) {
        return 'closed';
    }
    if (!empty($activity['due_date']) && $now > strtotime($activity['due_date'])) {
        return 'overdue';
    }
    return 'open';
}

/**
 * Get status badge HTML for an activity
 */
function activityStatusBadge($activity) {
    $status = getActivityStatus($activity);
    return match($status) {
        'not_open' => '<span class="badge bg-secondary"><i class="fas fa-lock me-1"></i>Not Yet Open</span>',
        'open'     => '<span class="badge bg-success"><i class="fas fa-door-open me-1"></i>Open</span>',
        'overdue'  => '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i>Overdue</span>',
        'closed'   => '<span class="badge bg-danger"><i class="fas fa-ban me-1"></i>Closed</span>',
        default    => '<span class="badge bg-secondary">Unknown</span>',
    };
}

/**
 * Check if a student can currently submit to an activity
 */
function canStudentSubmit($activity, $existingSubmission = null) {
    if (!$activity['is_submittable']) return false;

    $status = getActivityStatus($activity);

    if ($status === 'not_open' || $status === 'closed') return false;

    if ($status === 'overdue' && !$activity['allow_late']) return false;

    // If already submitted and resubmission not allowed
    if ($existingSubmission && !$activity['allow_resubmit']) return false;

    return true;
}

/**
 * Compute late penalty for a submission
 * Returns the penalty amount (to subtract from max possible score percentage)
 */
function computeLatePenalty($activity, $submittedAt) {
    if (empty($activity['due_date'])) return 0;

    $due = strtotime($activity['due_date']);
    $submitted = strtotime($submittedAt);

    if ($submitted <= $due) return 0; // Not late

    $diffSeconds = $submitted - $due;

    if ($activity['late_penalty_interval'] === 'per_hour') {
        $units = ceil($diffSeconds / 3600);
    } else {
        $units = ceil($diffSeconds / 86400);
    }

    $penalty = $units * floatval($activity['late_penalty_amount']);

    // Apply cap if set
    if (!empty($activity['late_penalty_max'])) {
        $penalty = min($penalty, floatval($activity['late_penalty_max']));
    }

    return round($penalty, 2);
}

/**
 * Compute final score after late penalty
 * raw_score is the teacher's given score (0 to max_score)
 * Returns final score (0 to max_score)
 */
function computeFinalScore($activity, $rawScore, $latePenalty) {
    if ($latePenalty <= 0) return $rawScore;

    $maxScore = floatval($activity['max_score']);
    if ($maxScore <= 0) return $rawScore;

    if ($activity['late_penalty_type'] === 'percentage') {
        // Deduct percentage of max score
        $deduction = ($latePenalty / 100) * $maxScore;
    } else {
        // Fixed points deduction
        $deduction = $latePenalty;
    }

    return max(0, round($rawScore - $deduction, 2));
}

/**
 * Get late policy summary text for display
 */
function getLatePolicySummary($activity) {
    if (!$activity['is_submittable']) return '';
    if (empty($activity['due_date'])) return 'No deadline set';

    $parts = [];
    if ($activity['allow_late']) {
        $amt = number_format($activity['late_penalty_amount'], 0);
        $type = $activity['late_penalty_type'] === 'percentage' ? '%' : ' pts';
        $interval = $activity['late_penalty_interval'] === 'per_hour' ? 'per hour' : 'per day';
        $parts[] = "Late: -{$amt}{$type} {$interval}";
        if (!empty($activity['late_penalty_max'])) {
            $cap = number_format($activity['late_penalty_max'], 0);
            $capType = $activity['late_penalty_type'] === 'percentage' ? '%' : ' pts';
            $parts[] = "max {$cap}{$capType}";
        }
    } else {
        $parts[] = 'Late submissions not allowed';
    }
    return implode(', ', $parts);
}

/**
 * Validate uploaded file for submission
 * Returns ['ok' => bool, 'error' => string|null]
 */
function validateSubmissionFile($file) {
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file selected.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form size limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        ];
        return ['ok' => false, 'error' => $errMap[$file['error']] ?? 'Upload error.'];
    }

    // Size check (server-side enforcement)
    if ($file['size'] > SUBMISSION_MAX_SIZE) {
        return ['ok' => false, 'error' => 'File exceeds maximum size of 25MB.'];
    }

    // Extension check
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, SUBMISSION_ALLOWED_EXTS)) {
        return ['ok' => false, 'error' => 'File type not allowed. Accepted: ' . implode(', ', SUBMISSION_ALLOWED_EXTS)];
    }

    // MIME type check
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset(SUBMISSION_ALLOWED_TYPES[$mime])) {
        // Some MIME types vary; allow if extension is valid
        $safeExts = ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt','jpg','jpeg','png'];
        if (!in_array($ext, $safeExts)) {
            return ['ok' => false, 'error' => 'File MIME type not recognized as a safe document.'];
        }
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Store a submission file safely
 * Returns stored filename or false on failure
 */
function storeSubmissionFile($tmpPath, $originalName, $activityId, $studentId) {
    $uploadDir = __DIR__ . '/../uploads/submissions/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $safeFilename = 'sub_' . $activityId . '_' . $studentId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    if (move_uploaded_file($tmpPath, $uploadDir . $safeFilename)) {
        return $safeFilename;
    }
    return false;
}

/**
 * Sync submission scores to graded_activity_scores (for grade computation)
 */
function syncSubmissionToActivityScore($pdo, $activityId, $studentId, $finalScore, $gradedBy) {
    $pdo->prepare("INSERT INTO graded_activity_scores (activity_id, student_id, score, updated_by)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE score = VALUES(score), updated_by = VALUES(updated_by), updated_at = NOW()")
        ->execute([$activityId, $studentId, $finalScore, $gradedBy]);
}

function getGrowthData($studentId, $month = null, $year = null) {
    $pdo = getDB();
    if (!$month) $month = date('n');
    if (!$year) $year = date('Y');

    $stmt = $pdo->prepare("SELECT AVG(score) as avg_score FROM quiz_attempts WHERE student_id = ? AND MONTH(completed_at) = ? AND YEAR(completed_at) = ?");
    $stmt->execute([$studentId, $month, $year]);
    $current = $stmt->fetch();
    $currentAvg = $current['avg_score'] ? round($current['avg_score'], 1) : null;

    $prevMonth = $month - 1;
    $prevYear = $year;
    if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }

    $stmt->execute([$studentId, $prevMonth, $prevYear]);
    $prev = $stmt->fetch();
    $prevAvg = $prev['avg_score'] ? round($prev['avg_score'], 1) : null;

    $improvement = null;
    if ($currentAvg !== null && $prevAvg !== null && $prevAvg > 0) {
        $improvement = round(($currentAvg - $prevAvg) / $prevAvg * 100, 1);
    }

    return [
        'current_avg' => $currentAvg,
        'prev_avg' => $prevAvg,
        'improvement' => $improvement,
        'month' => $month,
        'year' => $year,
    ];
}
