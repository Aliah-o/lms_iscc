<?php
/**
 * Secure file download handler for activity submissions.
 * Only accessible by:
 * - The student who submitted the file
 * - The instructor of the class
 * - Superadmin
 */
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'superadmin', 'student');
$pdo = getDB();
$user = currentUser();
$role = $user['role'];

$submissionId = intval($_GET['id'] ?? 0);
$source = $_GET['source'] ?? 'current'; // 'current' or 'history'

if (!$submissionId) {
    http_response_code(404);
    exit('File not found.');
}

if ($source === 'history') {
    // Download from submission_history table
    $stmt = $pdo->prepare("SELECT sh.*, ga.class_id
        FROM submission_history sh
        JOIN graded_activities ga ON sh.activity_id = ga.id
        WHERE sh.id = ?");
    $stmt->execute([$submissionId]);
    $record = $stmt->fetch();
} else {
    // Download from activity_submissions table
    $stmt = $pdo->prepare("SELECT asub.*, ga.class_id
        FROM activity_submissions asub
        JOIN graded_activities ga ON asub.activity_id = ga.id
        WHERE asub.id = ?");
    $stmt->execute([$submissionId]);
    $record = $stmt->fetch();
}

if (!$record) {
    http_response_code(404);
    exit('File not found.');
}

// Authorization check
$classId = $record['class_id'];

if ($role === 'student') {
    // Students can only download their own submissions
    if ($record['student_id'] != $user['id']) {
        http_response_code(403);
        exit('Access denied.');
    }
    // Must be enrolled in the class
    $enrolled = $pdo->prepare("SELECT 1 FROM class_enrollments WHERE class_id = ? AND student_id = ?");
    $enrolled->execute([$classId, $user['id']]);
    if (!$enrolled->fetch()) {
        http_response_code(403);
        exit('Access denied.');
    }
} elseif ($role === 'instructor') {
    // Instructors can only download submissions from their own classes
    $ownsClass = $pdo->prepare("SELECT 1 FROM instructor_classes WHERE id = ? AND instructor_id = ?");
    $ownsClass->execute([$classId, $user['id']]);
    if (!$ownsClass->fetch()) {
        http_response_code(403);
        exit('Access denied.');
    }
}
// superadmin can download any

// Serve the file
$filePath = __DIR__ . '/uploads/submissions/' . $record['file_name'];

if (!file_exists($filePath)) {
    http_response_code(404);
    exit('File not found on server.');
}

// Prevent directory traversal
$realPath = realpath($filePath);
$uploadsDir = realpath(__DIR__ . '/uploads/submissions/');
if ($realPath === false || strpos($realPath, $uploadsDir) !== 0) {
    http_response_code(403);
    exit('Access denied.');
}

// Set headers for download
$contentType = $record['file_type'] ?? 'application/octet-stream';
$originalName = $record['original_name'] ?? 'download';

header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . addslashes($originalName) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;
