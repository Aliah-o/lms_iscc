<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';

$hash = trim($_GET['hash'] ?? '');
$certificate = null;
$error = '';

if ($hash) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT c.*, 
            u.first_name as student_fn, u.last_name as student_ln, u.username as student_uname,
            i.first_name as issuer_fn, i.last_name as issuer_ln, i.role as issuer_role
            FROM certificates c 
            JOIN users u ON c.student_id = u.id 
            JOIN users i ON c.issued_by = i.id 
            WHERE c.certificate_hash = ?");
        $stmt->execute([$hash]);
        $certificate = $stmt->fetch();
        if (!$certificate) {
            $error = 'not_found';
        }
    } catch (Exception $e) {
        $error = 'system_error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Verification - ISCC LMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .verify-container { max-width: 700px; width: 100%; }
        .verify-card { background: #fff; border-radius: 20px; box-shadow: 0 25px 60px rgba(0,0,0,.2); overflow: hidden; }
        .verify-header { padding: 2rem 2rem 1rem; text-align: center; }
        .verify-body { padding: 0 2rem 2rem; }
        .verify-footer { background: #f8f9fa; padding: 1rem 2rem; text-align: center; font-size: 0.8rem; color: #6c757d; }
        .status-badge { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1.25rem; border-radius: 50px; font-weight: 700; font-size: 1rem; }
        .status-valid { background: #D1FAE5; color: #065F46; }
        .status-revoked { background: #FEE2E2; color: #991B1B; }
        .status-notfound { background: #FEF3C7; color: #92400E; }
        .cert-detail { display: flex; justify-content: space-between; padding: 0.75rem 0; border-bottom: 1px solid #f0f0f0; font-size: 0.9rem; }
        .cert-detail:last-child { border-bottom: none; }
        .cert-label { color: #6c757d; font-weight: 500; }
        .cert-value { font-weight: 600; color: #1f2937; text-align: right; }
        .hash-display { background: #f3f4f6; border-radius: 10px; padding: 0.75rem; font-family: 'Courier New', monospace; font-size: 0.72rem; word-break: break-all; color: #374151; text-align: center; }
        .grade-circle { width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 800; margin: 0 auto 1rem; }
        .grade-passed { background: linear-gradient(135deg, #D1FAE5, #A7F3D0); color: #065F46; border: 3px solid #10B981; }
        .grade-failed { background: linear-gradient(135deg, #FEE2E2, #FCA5A5); color: #991B1B; border: 3px solid #EF4444; }
        .search-box { background: #fff; border-radius: 16px; padding: 2rem; box-shadow: 0 25px 60px rgba(0,0,0,.2); max-width: 500px; margin: 0 auto; }
        .qr-container { text-align: center; margin: 1rem 0; }
        .qr-container img { border-radius: 10px; border: 3px solid #e5e7eb; }
        .blockchain-visual {
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            margin: 1rem 0; padding: 0.75rem; background: #EFF6FF; border-radius: 10px; font-size: 0.8rem;
        }
        .block-node { background: #3B82F6; color: #fff; padding: 0.25rem 0.5rem; border-radius: 6px; font-size: 0.7rem; font-weight: 600; }
        .chain-arrow { color: #3B82F6; }
    </style>
</head>
<body>
<div class="verify-container">
    <?php if (!$hash): ?>
    <div class="search-box text-center">
        <div class="mb-3">
            <img src="<?= BASE_URL ?>/assets/css/logo.png" alt="ISCC" style="width:60px;height:60px;border-radius:12px;" onerror="this.style.display='none'">
        </div>
        <h4 class="fw-bold mb-1">Certificate Verification</h4>
        <p class="text-muted mb-4" style="font-size:0.9rem;">ISCC Learning Management System</p>
        <form method="GET">
            <div class="mb-3">
                <input type="text" name="hash" class="form-control form-control-lg" placeholder="Enter certificate hash..." required style="border-radius:12px;text-align:center;font-family:monospace;font-size:0.85rem;">
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100" style="border-radius:12px;background:linear-gradient(135deg,#667eea,#764ba2);border:none;">
                <i class="fas fa-search me-2"></i>Verify Certificate
            </button>
        </form>
        <div class="mt-3" style="font-size:0.8rem;color:#6c757d;">
            <i class="fas fa-qrcode me-1"></i>Or scan the QR code on the certificate
        </div>
    </div>

    <?php elseif ($error === 'not_found'): ?>
    <div class="verify-card">
        <div class="verify-header">
            <div class="status-badge status-notfound mb-3">
                <i class="fas fa-exclamation-triangle"></i> CERTIFICATE NOT FOUND
            </div>
            <h5 class="fw-bold">Verification Failed</h5>
            <p class="text-muted" style="font-size:0.9rem;">No certificate matches the provided hash. This may indicate:</p>
        </div>
        <div class="verify-body">
            <div class="alert alert-warning" style="border-radius:10px;">
                <ul class="mb-0" style="font-size:0.85rem;">
                    <li>The certificate hash was entered incorrectly</li>
                    <li>The certificate has been revoked and removed</li>
                    <li>This is a fraudulent certificate</li>
                </ul>
            </div>
            <div class="hash-display mb-3"><?= htmlspecialchars($hash) ?></div>
            <a href="<?= BASE_URL ?>/verify.php" class="btn btn-outline-primary w-100" style="border-radius:10px;"><i class="fas fa-arrow-left me-2"></i>Try Again</a>
        </div>
        <div class="verify-footer">
            <i class="fas fa-shield-alt me-1"></i>ISCC LMS Security Verification System
        </div>
    </div>

    <?php elseif ($error === 'system_error'): ?>
    <div class="verify-card">
        <div class="verify-header">
            <div class="status-badge status-notfound mb-3"><i class="fas fa-cog"></i> SYSTEM ERROR</div>
            <p class="text-muted">Unable to verify at this time. Please try again later.</p>
        </div>
        <div class="verify-body">
            <a href="<?= BASE_URL ?>/verify.php" class="btn btn-outline-primary w-100" style="border-radius:10px;"><i class="fas fa-arrow-left me-2"></i>Try Again</a>
        </div>
    </div>

    <?php elseif ($certificate): ?>
    <div class="verify-card">
        <div class="verify-header">
            <?php if ($certificate['is_valid']): ?>
            <div class="status-badge status-valid mb-3">
                <i class="fas fa-check-circle"></i> VERIFIED AUTHENTIC
            </div>
            <?php else: ?>
            <div class="status-badge status-revoked mb-3">
                <i class="fas fa-ban"></i> CERTIFICATE REVOKED
            </div>
            <?php endif; ?>

            <h5 class="fw-bold mb-0">ISCC LMS Certificate</h5>
            <p class="text-muted mb-3" style="font-size:0.85rem;">Security-Verified Academic Record</p>

            <div class="grade-circle <?= $certificate['grade_status'] === 'PASSED' ? 'grade-passed' : 'grade-failed' ?>">
                <?= number_format($certificate['final_grade'], 1) ?>
            </div>
            <span class="badge bg-<?= $certificate['grade_status'] === 'PASSED' ? 'success' : 'danger' ?> fs-6">
                <?= $certificate['grade_status'] ?>
            </span>
        </div>

        <div class="verify-body">
            <?php if (!$certificate['is_valid']): ?>
            <div class="alert alert-danger" style="border-radius:10px;font-size:0.85rem;">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>This certificate has been revoked</strong> and is no longer valid. Contact ISCC administration for details.
            </div>
            <?php endif; ?>

            <div class="cert-detail"><span class="cert-label">Student Name</span><span class="cert-value"><?= htmlspecialchars($certificate['student_fn'] . ' ' . $certificate['student_ln']) ?></span></div>
            <div class="cert-detail"><span class="cert-label">Username</span><span class="cert-value"><?= htmlspecialchars($certificate['student_uname']) ?></span></div>
            <div class="cert-detail"><span class="cert-label">Course Code</span><span class="cert-value"><?= htmlspecialchars($certificate['course_code']) ?></span></div>
            <div class="cert-detail"><span class="cert-label">Subject</span><span class="cert-value"><?= htmlspecialchars($certificate['subject_name']) ?></span></div>
            <div class="cert-detail"><span class="cert-label">Final Grade</span><span class="cert-value" style="color:<?= $certificate['final_grade'] >= 75 ? '#10B981' : '#EF4444' ?>;font-size:1.1rem;"><?= number_format($certificate['final_grade'], 2) ?></span></div>
            <div class="cert-detail"><span class="cert-label">Status</span><span class="cert-value"><span class="badge bg-<?= $certificate['grade_status'] === 'PASSED' ? 'success' : 'danger' ?>"><?= $certificate['grade_status'] ?></span></span></div>
            <div class="cert-detail"><span class="cert-label">Semester</span><span class="cert-value"><?= htmlspecialchars($certificate['semester'] ?: 'N/A') ?></span></div>
            <div class="cert-detail"><span class="cert-label">Academic Year</span><span class="cert-value"><?= htmlspecialchars($certificate['academic_year'] ?: 'N/A') ?></span></div>
            <div class="cert-detail"><span class="cert-label">Issued By</span><span class="cert-value"><?= htmlspecialchars($certificate['issuer_fn'] . ' ' . $certificate['issuer_ln']) ?><br><small class="text-muted"><?= htmlspecialchars(ucfirst($certificate['issuer_role'])) ?></small></span></div>
            <div class="cert-detail"><span class="cert-label">Date Issued</span><span class="cert-value"><?= date('F d, Y g:i A', strtotime($certificate['issued_at'])) ?></span></div>

            <div class="blockchain-visual">
                <span class="block-node"><i class="fas fa-cube me-1"></i>Genesis</span>
                <i class="fas fa-arrow-right chain-arrow"></i>
                <span class="block-node"><i class="fas fa-cube me-1"></i>...</span>
                <i class="fas fa-arrow-right chain-arrow"></i>
                <span class="block-node" style="background:#10B981;"><i class="fas fa-certificate me-1"></i>This Certificate</span>
            </div>

            <div class="text-center mt-3 mb-2">
                <small class="text-muted fw-bold">Certificate Hash (SHA-256)</small>
            </div>
            <div class="hash-display"><?= htmlspecialchars($certificate['certificate_hash']) ?></div>

            <div class="qr-container mt-3">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode(rtrim(BASE_URL, '/') . '/verify.php?hash=' . $certificate['certificate_hash']) ?>" alt="QR Verification Code">
                <div class="mt-2" style="font-size:0.75rem;color:#6c757d;">Scan to re-verify this certificate</div>
            </div>

            <a href="<?= BASE_URL ?>/verify.php" class="btn btn-outline-primary w-100 mt-3" style="border-radius:10px;">
                <i class="fas fa-arrow-left me-2"></i>Verify Another Certificate
            </a>
        </div>

        <div class="verify-footer">
            <i class="fas fa-shield-alt me-1"></i>Verified by ISCC LMS Security System &bull; <?= date('M d, Y g:i:sa') ?>
        </div>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
