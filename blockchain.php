<?php
$pageTitle = 'Security';
require_once __DIR__ . '/helpers/functions.php';
requireRole('superadmin', 'staff', 'instructor');
$pdo = getDB();
$user = currentUser();
$role = $user['role'];
$breadcrumbPills = ['Security'];

if (isset($_GET['ajax']) && $_GET['ajax'] === 'students') {
    header('Content-Type: application/json');
    $cid = intval($_GET['class_id'] ?? 0);
    if ($cid) {
        $s = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.username FROM class_enrollments ce JOIN users u ON ce.student_id = u.id WHERE ce.class_id = ? ORDER BY u.last_name, u.first_name");
        $s->execute([$cid]);
        echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
    } else {
        echo '[]';
    }
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS grade_chain (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        class_id INT NOT NULL,
        course_code VARCHAR(20) DEFAULT '',
        subject_name VARCHAR(200) DEFAULT '',
        component VARCHAR(50),
        grading_period VARCHAR(20),
        score DECIMAL(6,2),
        recorded_by INT,
        prev_hash VARCHAR(64) DEFAULT 'GENESIS',
        block_hash VARCHAR(64),
        block_data TEXT,
        is_valid TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS certificates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        class_id INT NOT NULL,
        course_code VARCHAR(20),
        subject_name VARCHAR(200),
        final_grade DECIMAL(6,2),
        grade_status VARCHAR(10),
        certificate_hash VARCHAR(64) UNIQUE,
        qr_data TEXT,
        issued_by INT,
        semester VARCHAR(20) DEFAULT '',
        academic_year VARCHAR(20) DEFAULT '',
        issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_valid TINYINT(1) DEFAULT 1
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chain_snapshots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        chain_type ENUM('grade','audit') NOT NULL,
        snapshot_data LONGTEXT,
        snapshot_hash VARCHAR(64),
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid CSRF token.'); redirect('/blockchain.php'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'rollback_grade_chain') {
        requireRole('superadmin');
        $blockId = intval($_POST['block_id'] ?? 0);
        if ($blockId) {
            $count = rollbackGradeChain($blockId);
            auditLogChained('blockchain_rollback', "Rolled back grade chain from block #$blockId. $count blocks re-chained.");
            flash('success', "Grade chain rollback successful. $count blocks re-chained with corrected hashes.");
        } else {
            flash('error', 'Invalid block ID.');
        }
        redirect('/blockchain.php?tab=grades');
    }

    elseif ($action === 'rollback_audit_chain') {
        requireRole('superadmin');
        $blockId = intval($_POST['block_id'] ?? 0);
        if ($blockId) {
            $count = rollbackAuditChain($blockId);
            flash('success', "Audit chain rollback successful. $count blocks re-chained.");
        } else {
            flash('error', 'Invalid block ID.');
        }
        redirect('/blockchain.php?tab=audit');
    }

    elseif ($action === 'issue_certificate') {
        $studentId = intval($_POST['student_id'] ?? 0);
        $classId = intval($_POST['class_id'] ?? 0);
        $finalGrade = floatval($_POST['final_grade'] ?? 0);

        if ($studentId && $classId && $finalGrade > 0) {
            $existing = $pdo->prepare("SELECT id FROM certificates WHERE student_id = ? AND class_id = ?");
            $existing->execute([$studentId, $classId]);
            if ($existing->fetch()) {
                flash('error', 'Certificate already issued for this student/class.');
            } else {
                $hash = generateCertificate($studentId, $classId, $finalGrade, $user['id']);
                if ($hash) {
                    auditLogChained('certificate_issued', "Certificate issued for student #$studentId, class #$classId. Hash: $hash");
                    flash('success', "Certificate issued. Hash: <code>$hash</code>");
                } else {
                    flash('error', 'Failed to issue certificate. Check student/class info.');
                }
            }
        } else {
            flash('error', 'Missing required fields.');
        }
        redirect('/blockchain.php?tab=certificates');
    }

    elseif ($action === 'revoke_certificate') {
        requireRole('superadmin');
        $certId = intval($_POST['cert_id'] ?? 0);
        if ($certId) {
            $pdo->prepare("UPDATE certificates SET is_valid = 0 WHERE id = ?")->execute([$certId]);
            auditLogChained('certificate_revoked', "Certificate #$certId revoked.");
            flash('success', 'Certificate revoked.');
        }
        redirect('/blockchain.php?tab=certificates');
    }

    elseif ($action === 'snapshot_chain') {
        requireRole('superadmin');
        $chainType = $_POST['chain_type'] ?? 'grade';
        if ($chainType === 'grade') {
            $data = $pdo->query("SELECT * FROM grade_chain ORDER BY id ASC")->fetchAll();
        } else {
            $data = $pdo->query("SELECT * FROM audit_logs WHERE block_hash IS NOT NULL ORDER BY id ASC")->fetchAll();
        }
        $jsonData = json_encode($data);
        $snapHash = hash('sha256', $jsonData);
        $pdo->prepare("INSERT INTO chain_snapshots (chain_type, snapshot_data, snapshot_hash, created_by) VALUES (?, ?, ?, ?)")
            ->execute([$chainType, $jsonData, $snapHash, $user['id']]);
        auditLogChained('chain_snapshot', "Created $chainType chain snapshot. Hash: $snapHash");
        flash('success', ucfirst($chainType) . " chain snapshot saved. Hash: <code>$snapHash</code>");
        redirect('/blockchain.php?tab=' . ($chainType === 'grade' ? 'grades' : 'audit'));
    }

    redirect('/blockchain.php');
}

$tab = $_GET['tab'] ?? 'overview';

$gradeChainCount = $pdo->query("SELECT COUNT(*) FROM grade_chain")->fetchColumn();
$auditChainCount = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE block_hash IS NOT NULL")->fetchColumn();
$certCount = $pdo->query("SELECT COUNT(*) FROM certificates WHERE is_valid = 1")->fetchColumn();

$gradeVerification = ['valid' => true, 'total_blocks' => 0, 'tampered_blocks' => []];
$auditVerification = ['valid' => true, 'total_blocks' => 0, 'tampered_blocks' => []];

if ($gradeChainCount > 0) {
    $gradeVerification = verifyGradeChain();
}
if ($auditChainCount > 0) {
    $auditVerification = verifyAuditChain();
}

$recentGradeBlocks = $pdo->query("SELECT gc.*, u.first_name as student_fn, u.last_name as student_ln, r.first_name as recorder_fn, r.last_name as recorder_ln
    FROM grade_chain gc
    LEFT JOIN users u ON gc.student_id = u.id
    LEFT JOIN users r ON gc.recorded_by = r.id
    ORDER BY gc.id DESC LIMIT 50")->fetchAll();

if ($role === 'instructor') {
    $instructorClasses = $pdo->prepare("SELECT tc.*, s.section_name,
        (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id = tc.id) as student_count
        FROM instructor_classes tc
        JOIN sections s ON tc.section_id = s.id
        WHERE tc.instructor_id = ? AND tc.is_active = 1
        ORDER BY tc.subject_name");
    $instructorClasses->execute([$user['id']]);
    $instructorClasses = $instructorClasses->fetchAll();
} else {
    $instructorClasses = $pdo->query("SELECT tc.*, s.section_name, u.first_name as instructor_fn, u.last_name as instructor_ln,
        (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id = tc.id) as student_count
        FROM instructor_classes tc
        JOIN sections s ON tc.section_id = s.id
        JOIN users u ON tc.instructor_id = u.id
        WHERE tc.is_active = 1
        ORDER BY tc.subject_name")->fetchAll();
}

$certificates = $pdo->query("SELECT c.*, u.first_name as student_fn, u.last_name as student_ln, u.username as student_uname, i.first_name as issuer_fn, i.last_name as issuer_ln
    FROM certificates c
    JOIN users u ON c.student_id = u.id
    JOIN users i ON c.issued_by = i.id
    ORDER BY c.issued_at DESC LIMIT 100")->fetchAll();

$snapshots = $pdo->query("SELECT cs.*, u.first_name, u.last_name FROM chain_snapshots cs LEFT JOIN users u ON cs.created_by = u.id ORDER BY cs.created_at DESC LIMIT 20")->fetchAll();

require_once __DIR__ . '/views/layouts/header.php';
?>

<style>
.chain-status-card {
    border-radius: 16px;
    padding: 1.5rem;
    border: 1px solid var(--gray-200);
    transition: all .3s;
}
.chain-status-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,.08); }
.chain-status-card.valid { border-left: 4px solid #10B981; }
.chain-status-card.tampered { border-left: 4px solid #EF4444; background: linear-gradient(135deg, #FEF2F2, #FFF); }
.chain-block {
    padding: 0.75rem; border-radius: 10px; background: var(--gray-50); border: 1px solid var(--gray-100);
    transition: all .2s; font-size: 0.85rem;
}
.chain-block:hover { background: #fff; border-color: var(--primary); }
.chain-block.invalid { background: #FEF2F2; border-color: #FCA5A5; }
.hash-text { font-family: 'Courier New', monospace; font-size: 0.72rem; color: var(--gray-500); word-break: break-all; }
.nav-chain .nav-link { border-radius: 10px; font-weight: 500; font-size: 0.9rem; padding: 0.5rem 1rem; }
.nav-chain .nav-link.active { background: var(--primary); color: #fff; }
.cert-card { border-radius: 12px; border: 2px solid var(--gray-200); transition: all .2s; }
.cert-card:hover { border-color: var(--primary); }
.cert-card.revoked { opacity: 0.5; border-color: #EF4444; }
.qr-box { display: inline-block; padding: 8px; background: #fff; border-radius: 8px; border: 2px solid var(--gray-200); cursor: pointer; transition: all .2s; }
.qr-box:hover { border-color: var(--primary); transform: scale(1.08); box-shadow: 0 4px 12px rgba(0,0,0,.12); }
</style>

<ul class="nav nav-chain nav-pills mb-4 gap-2 flex-wrap">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'overview' ? 'active' : '' ?>" href="<?= BASE_URL ?>/blockchain.php?tab=overview">
            <i class="fas fa-shield-alt me-1"></i>Overview
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'grades' ? 'active' : '' ?>" href="<?= BASE_URL ?>/blockchain.php?tab=grades">
            <i class="fas fa-link me-1"></i>Grade Chain
            <?php if (!$gradeVerification['valid']): ?>
            <span class="badge bg-danger ms-1"><i class="fas fa-exclamation-triangle"></i></span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'audit' ? 'active' : '' ?>" href="<?= BASE_URL ?>/blockchain.php?tab=audit">
            <i class="fas fa-file-shield me-1"></i>Audit Chain
            <?php if (!$auditVerification['valid']): ?>
            <span class="badge bg-danger ms-1"><i class="fas fa-exclamation-triangle"></i></span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'certificates' ? 'active' : '' ?>" href="<?= BASE_URL ?>/blockchain.php?tab=certificates">
            <i class="fas fa-certificate me-1"></i>Certificates
        </a>
    </li>
    <?php if ($role === 'superadmin'): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'snapshots' ? 'active' : '' ?>" href="<?= BASE_URL ?>/blockchain.php?tab=snapshots">
            <i class="fas fa-camera me-1"></i>Snapshots
        </a>
    </li>
    <?php endif; ?>
</ul>

<?php if ($tab === 'overview'): ?>
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="chain-status-card <?= $gradeVerification['valid'] ? 'valid' : 'tampered' ?>">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div style="width:50px;height:50px;border-radius:14px;background:<?= $gradeVerification['valid'] ? 'linear-gradient(135deg,#D1FAE5,#A7F3D0)' : 'linear-gradient(135deg,#FEE2E2,#FCA5A5)' ?>;display:flex;align-items:center;justify-content:center;">
                    <i class="fas <?= $gradeVerification['valid'] ? 'fa-check-circle text-success' : 'fa-exclamation-triangle text-danger' ?> fa-lg"></i>
                </div>
                <div>
                    <h6 class="mb-0 fw-bold">Grade Chain</h6>
                    <small class="<?= $gradeVerification['valid'] ? 'text-success' : 'text-danger' ?> fw-bold">
                        <?= $gradeVerification['valid'] ? 'VERIFIED' : 'TAMPERED (' . count($gradeVerification['tampered_blocks']) . ' blocks)' ?>
                    </small>
                </div>
            </div>
            <div class="d-flex gap-3" style="font-size:0.85rem;">
                <div><span class="text-muted">Total Blocks:</span> <strong><?= $gradeVerification['total_blocks'] ?></strong></div>
                <div><span class="text-muted">Status:</span>
                    <span class="badge bg-<?= $gradeVerification['valid'] ? 'success' : 'danger' ?>">
                        <?= $gradeVerification['valid'] ? 'Intact' : 'Compromised' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="chain-status-card <?= $auditVerification['valid'] ? 'valid' : 'tampered' ?>">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div style="width:50px;height:50px;border-radius:14px;background:<?= $auditVerification['valid'] ? 'linear-gradient(135deg,#D1FAE5,#A7F3D0)' : 'linear-gradient(135deg,#FEE2E2,#FCA5A5)' ?>;display:flex;align-items:center;justify-content:center;">
                    <i class="fas <?= $auditVerification['valid'] ? 'fa-check-circle text-success' : 'fa-exclamation-triangle text-danger' ?> fa-lg"></i>
                </div>
                <div>
                    <h6 class="mb-0 fw-bold">Audit Chain</h6>
                    <small class="<?= $auditVerification['valid'] ? 'text-success' : 'text-danger' ?> fw-bold">
                        <?= $auditVerification['valid'] ? 'VERIFIED' : 'TAMPERED (' . count($auditVerification['tampered_blocks']) . ' blocks)' ?>
                    </small>
                </div>
            </div>
            <div class="d-flex gap-3" style="font-size:0.85rem;">
                <div><span class="text-muted">Total Blocks:</span> <strong><?= $auditVerification['total_blocks'] ?></strong></div>
                <div><span class="text-muted">Status:</span>
                    <span class="badge bg-<?= $auditVerification['valid'] ? 'success' : 'danger' ?>">
                        <?= $auditVerification['valid'] ? 'Intact' : 'Compromised' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="chain-status-card valid">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div style="width:50px;height:50px;border-radius:14px;background:linear-gradient(135deg,#DBEAFE,#93C5FD);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-certificate text-primary fa-lg"></i>
                </div>
                <div>
                    <h6 class="mb-0 fw-bold">Certificates</h6>
                    <small class="text-primary fw-bold"><?= $certCount ?> ACTIVE</small>
                </div>
            </div>
            <div class="d-flex gap-3" style="font-size:0.85rem;">
                <div><span class="text-muted">Issued:</span> <strong><?= $certCount ?></strong></div>
                <div>
                    <a href="<?= BASE_URL ?>/blockchain.php?tab=certificates" class="text-primary"><i class="fas fa-arrow-right me-1"></i>Manage</a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <span><i class="fas fa-shield-alt me-2"></i>Security Summary</span>
        <span class="text-muted" style="font-size:0.78rem;">Last verified: <?= date('M d, Y g:i:sa') ?></span>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-6">
                <h6 class="fw-bold mb-3"><i class="fas fa-link me-2 text-primary"></i>How Security Integrity Works</h6>
                <div style="font-size:0.85rem; color: var(--gray-600);">
                    <p><i class="fas fa-cube text-primary me-1"></i> Each grade entry and audit log is stored as a <strong>block</strong> with a SHA-256 hash.</p>
                    <p><i class="fas fa-link text-primary me-1"></i> Each block links to the previous block's hash, forming a <strong>chain</strong>.</p>
                    <p><i class="fas fa-search text-primary me-1"></i> If any block is modified, the hash changes and <strong>breaks the chain</strong>.</p>
                    <p class="mb-0"><i class="fas fa-undo text-primary me-1"></i> Tampered blocks can be <strong>rolled back</strong> and re-chained by an admin.</p>
                </div>
            </div>
            <div class="col-md-6">
                <h6 class="fw-bold mb-3"><i class="fas fa-chart-bar me-2 text-primary"></i>Chain Statistics</h6>
                <table class="table table-sm" style="font-size:0.85rem;">
                    <tr><td class="text-muted">Grade Chain Blocks</td><td class="fw-bold"><?= number_format($gradeChainCount) ?></td></tr>
                    <tr><td class="text-muted">Audit Chain Blocks</td><td class="fw-bold"><?= number_format($auditChainCount) ?></td></tr>
                    <tr><td class="text-muted">Certificates Issued</td><td class="fw-bold"><?= number_format($certCount) ?></td></tr>
                    <tr>
                        <td class="text-muted">Overall Integrity</td>
                        <td>
                            <?php if ($gradeVerification['valid'] && $auditVerification['valid']): ?>
                            <span class="badge bg-success"><i class="fas fa-check me-1"></i>All Chains Intact</span>
                            <?php else: ?>
                            <span class="badge bg-danger"><i class="fas fa-times me-1"></i>Tampering Detected</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr><td class="text-muted">Chain Snapshots</td><td class="fw-bold"><?= count($snapshots) ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (!$gradeVerification['valid'] || !$auditVerification['valid']): ?>
<div class="alert alert-danger d-flex align-items-center gap-3" style="border-radius:12px;">
    <i class="fas fa-exclamation-triangle fa-2x"></i>
    <div>
        <h6 class="mb-1 fw-bold">Tampering Detected!</h6>
        <p class="mb-0" style="font-size:0.85rem;">
            <?php if (!$gradeVerification['valid']): ?>
            Grade chain has <?= count($gradeVerification['tampered_blocks']) ?> compromised block(s).
            <?php endif; ?>
            <?php if (!$auditVerification['valid']): ?>
            Audit chain has <?= count($auditVerification['tampered_blocks']) ?> compromised block(s).
            <?php endif; ?>
            <?php if ($role === 'superadmin'): ?>
            <a href="<?= BASE_URL ?>/blockchain.php?tab=grades" class="text-danger fw-bold">View &amp; Rollback &rarr;</a>
            <?php endif; ?>
        </p>
    </div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'grades'): ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-link me-2"></i>Grade Hash Chain</span>
                <div class="d-flex gap-2">
                    <span class="badge bg-<?= $gradeVerification['valid'] ? 'success' : 'danger' ?> fs-6">
                        <i class="fas <?= $gradeVerification['valid'] ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> me-1"></i>
                        <?= $gradeVerification['valid'] ? 'Chain Intact' : count($gradeVerification['tampered_blocks']) . ' Tampered' ?>
                    </span>
                    <?php if ($role === 'superadmin'): ?>
                    <form method="POST" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="snapshot_chain">
                        <input type="hidden" name="chain_type" value="grade">
                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Save chain snapshot"><i class="fas fa-camera"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($recentGradeBlocks)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-cube fa-2x mb-2 d-block"></i>
                    No grade blocks recorded yet. Grades will appear here when instructors save grade entries.
                </div>
                <?php else: ?>

                <?php if (!$gradeVerification['valid']): ?>
                <div class="alert alert-danger mb-3" style="border-radius:10px;">
                    <h6 class="fw-bold mb-2"><i class="fas fa-bug me-2"></i>Tampering Detected in Grade Chain</h6>
                    <div style="font-size:0.85rem;">
                        <?php foreach ($gradeVerification['tampered_blocks'] as $tb): ?>
                        <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded" style="background:rgba(255,255,255,0.6);">
                            <span class="badge bg-danger">Block #<?= $tb['id'] ?></span>
                            <span><?= e($tb['reason']) ?></span>
                            <?php if ($role === 'superadmin'): ?>
                            <form method="POST" class="ms-auto" onsubmit="return confirm('Rollback grade chain from block #<?= $tb['id'] ?>? This will re-compute hashes for this block and all subsequent blocks.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="rollback_grade_chain">
                                <input type="hidden" name="block_id" value="<?= $tb['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-undo me-1"></i>Rollback</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="d-flex flex-column gap-2">
                    <?php
                    $tamperedIds = array_column($gradeVerification['tampered_blocks'], 'id');
                    foreach ($recentGradeBlocks as $block):
                        $isTampered = in_array($block['id'], $tamperedIds);
                    ?>
                    <div class="chain-block <?= $isTampered ? 'invalid' : '' ?> <?= !$block['is_valid'] ? 'invalid' : '' ?>">
                        <div class="d-flex align-items-start justify-content-between">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-<?= $isTampered ? 'danger' : ($block['is_valid'] ? 'primary' : 'warning') ?>">#<?= $block['id'] ?></span>
                                <strong><?= e($block['student_fn'] . ' ' . $block['student_ln']) ?></strong>
                                <span class="text-muted">&bull;</span>
                                <span><?= e($block['subject_name']) ?></span>
                                <span class="badge bg-light text-dark"><?= e(ucfirst($block['component'])) ?></span>
                                <span class="badge bg-info"><?= e(ucfirst($block['grading_period'])) ?></span>
                                <span class="fw-bold" style="color:var(--primary);"><?= number_format($block['score'], 2) ?></span>
                            </div>
                            <div style="font-size:0.72rem;color:var(--gray-400);">
                                <?= date('M d, Y g:ia', strtotime($block['created_at'])) ?>
                            </div>
                        </div>
                        <div class="d-flex gap-4 mt-1">
                            <div class="hash-text"><i class="fas fa-arrow-left me-1" style="font-size:0.6rem;"></i>prev: <?= e(substr($block['prev_hash'], 0, 16)) ?>...</div>
                            <div class="hash-text"><i class="fas fa-cube me-1" style="font-size:0.6rem;"></i>hash: <?= e(substr($block['block_hash'], 0, 16)) ?>...</div>
                            <div class="hash-text"><i class="fas fa-user me-1" style="font-size:0.6rem;"></i>by: <?= e($block['recorder_fn'] . ' ' . $block['recorder_ln']) ?></div>
                        </div>
                        <?php if ($isTampered): ?>
                        <div class="mt-1"><span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>TAMPERED</span></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><span><i class="fas fa-info-circle me-2"></i>Grade Chain Info</span></div>
            <div class="card-body" style="font-size:0.85rem; color: var(--gray-600);">
                <p><strong>Total Blocks:</strong> <?= $gradeVerification['total_blocks'] ?></p>
                <p><strong>Status:</strong>
                    <span class="badge bg-<?= $gradeVerification['valid'] ? 'success' : 'danger' ?>">
                        <?= $gradeVerification['valid'] ? 'Intact' : 'Tampered' ?>
                    </span>
                </p>
                <p><strong>Algorithm:</strong> SHA-256</p>
                <p class="mb-0"><strong>Genesis Hash:</strong> <code>GENESIS</code></p>
            </div>
        </div>

        <?php if ($role === 'superadmin' && !$gradeVerification['valid']): ?>
        <div class="card border-danger">
            <div class="card-header bg-danger text-white"><span><i class="fas fa-undo me-2"></i>Bulk Rollback</span></div>
            <div class="card-body">
                <p style="font-size:0.85rem;">Rollback all tampered grade chain blocks at once. This re-chains from the first tampered block.</p>
                <?php $firstTampered = $gradeVerification['tampered_blocks'][0]['id'] ?? 0; ?>
                <?php if ($firstTampered): ?>
                <form method="POST" onsubmit="return confirm('Rollback ALL tampered blocks from #<?= $firstTampered ?>? This is irreversible.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="rollback_grade_chain">
                    <input type="hidden" name="block_id" value="<?= $firstTampered ?>">
                    <button type="submit" class="btn btn-danger w-100"><i class="fas fa-undo me-1"></i>Rollback from Block #<?= $firstTampered ?></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'audit'): ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-file-shield me-2"></i>Audit Log Hash Chain</span>
                <div class="d-flex gap-2">
                    <span class="badge bg-<?= $auditVerification['valid'] ? 'success' : 'danger' ?> fs-6">
                        <i class="fas <?= $auditVerification['valid'] ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> me-1"></i>
                        <?= $auditVerification['valid'] ? 'Chain Intact' : count($auditVerification['tampered_blocks']) . ' Tampered' ?>
                    </span>
                    <?php if ($role === 'superadmin'): ?>
                    <form method="POST" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="snapshot_chain">
                        <input type="hidden" name="chain_type" value="audit">
                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Save chain snapshot"><i class="fas fa-camera"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php
                $auditBlocks = $pdo->query("SELECT al.*, u.first_name, u.last_name, u.username FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id WHERE al.block_hash IS NOT NULL ORDER BY al.id DESC LIMIT 50")->fetchAll();
                ?>
                <?php if (empty($auditBlocks)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-clipboard-list fa-2x mb-2 d-block"></i>
                    No chained audit entries yet. Use <code>auditLogChained()</code> to create secured audit entries.
                </div>
                <?php else: ?>

                <?php if (!$auditVerification['valid']): ?>
                <div class="alert alert-danger mb-3" style="border-radius:10px;">
                    <h6 class="fw-bold mb-2"><i class="fas fa-bug me-2"></i>Tampering Detected in Audit Chain</h6>
                    <div style="font-size:0.85rem;">
                        <?php foreach ($auditVerification['tampered_blocks'] as $tb): ?>
                        <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded" style="background:rgba(255,255,255,0.6);">
                            <span class="badge bg-danger">Block #<?= $tb['id'] ?></span>
                            <span><?= e($tb['reason']) ?></span>
                            <?php if ($role === 'superadmin'): ?>
                            <form method="POST" class="ms-auto" onsubmit="return confirm('Rollback audit chain from block #<?= $tb['id'] ?>?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="rollback_audit_chain">
                                <input type="hidden" name="block_id" value="<?= $tb['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-undo me-1"></i>Rollback</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="d-flex flex-column gap-2">
                    <?php
                    $auditTamperedIds = array_column($auditVerification['tampered_blocks'], 'id');
                    foreach ($auditBlocks as $ab):
                        $isAuditTampered = in_array($ab['id'], $auditTamperedIds);
                    ?>
                    <div class="chain-block <?= $isAuditTampered ? 'invalid' : '' ?>">
                        <div class="d-flex align-items-start justify-content-between">
                            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                <span class="badge bg-<?= $isAuditTampered ? 'danger' : 'secondary' ?>">#<?= $ab['id'] ?></span>
                                <strong style="font-size:0.82rem;"><?= e($ab['first_name'] . ' ' . $ab['last_name']) ?></strong>
                                <span class="badge bg-info"><?= e($ab['action']) ?></span>
                                <span class="text-muted" style="font-size:0.78rem;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($ab['details']) ?></span>
                            </div>
                            <div style="font-size:0.72rem;color:var(--gray-400);white-space:nowrap;">
                                <?= date('M d, Y g:ia', strtotime($ab['created_at'])) ?>
                            </div>
                        </div>
                        <div class="d-flex gap-4 mt-1">
                            <div class="hash-text"><i class="fas fa-arrow-left me-1" style="font-size:0.6rem;"></i>prev: <?= e(substr($ab['prev_hash'], 0, 16)) ?>...</div>
                            <div class="hash-text"><i class="fas fa-cube me-1" style="font-size:0.6rem;"></i>hash: <?= e(substr($ab['block_hash'], 0, 16)) ?>...</div>
                        </div>
                        <?php if ($isAuditTampered): ?>
                        <div class="mt-1"><span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>TAMPERED</span></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><span><i class="fas fa-info-circle me-2"></i>Audit Chain Info</span></div>
            <div class="card-body" style="font-size:0.85rem;color:var(--gray-600);">
                <p><strong>Total Blocks:</strong> <?= $auditVerification['total_blocks'] ?></p>
                <p><strong>Status:</strong>
                    <span class="badge bg-<?= $auditVerification['valid'] ? 'success' : 'danger' ?>">
                        <?= $auditVerification['valid'] ? 'Intact' : 'Tampered' ?>
                    </span>
                </p>
                <p><strong>Algorithm:</strong> SHA-256</p>
                <p class="mb-0"><strong>Non-Chained Logs:</strong>
                    <?= $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE block_hash IS NULL")->fetchColumn() ?> entries (legacy)
                </p>
            </div>
        </div>

        <?php if ($role === 'superadmin' && !$auditVerification['valid']): ?>
        <div class="card border-danger">
            <div class="card-header bg-danger text-white"><span><i class="fas fa-undo me-2"></i>Bulk Rollback</span></div>
            <div class="card-body">
                <p style="font-size:0.85rem;">Rollback all tampered audit chain blocks at once.</p>
                <?php $firstAuditTampered = $auditVerification['tampered_blocks'][0]['id'] ?? 0; ?>
                <?php if ($firstAuditTampered): ?>
                <form method="POST" onsubmit="return confirm('Rollback ALL tampered audit blocks from #<?= $firstAuditTampered ?>?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="rollback_audit_chain">
                    <input type="hidden" name="block_id" value="<?= $firstAuditTampered ?>">
                    <button type="submit" class="btn btn-danger w-100"><i class="fas fa-undo me-1"></i>Rollback from Block #<?= $firstAuditTampered ?></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'certificates'): ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header"><span><i class="fas fa-plus-circle me-2"></i>Issue Certificate</span></div>
            <div class="card-body">
                <form method="POST" id="certForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="issue_certificate">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Select Class</label>
                            <select name="class_id" id="certClassSelect" class="form-select" required>
                                <option value="">Choose a class...</option>
                                <?php foreach ($instructorClasses as $tc): ?>
                                <option value="<?= $tc['id'] ?>" data-name="<?= e($tc['subject_name']) ?>">
                                    <?= e($tc['subject_name']) ?> - <?= e($tc['section_name']) ?>
                                    <?php if (isset($tc['instructor_fn'])): ?> (<?= e($tc['instructor_fn'] . ' ' . $tc['instructor_ln']) ?>)<?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Select Student</label>
                            <select name="student_id" id="certStudentSelect" class="form-select" required>
                                <option value="">Select class first...</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Final Grade</label>
                            <input type="number" name="final_grade" class="form-control" min="0" max="100" step="0.01" required placeholder="0.00">
                        </div>
                        <div class="col-md-8 d-flex align-items-end">
                            <button type="button" class="btn btn-primary-gradient" id="issueCertBtn">
                                <i class="fas fa-certificate me-1"></i>Issue Certificate
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span><i class="fas fa-certificate me-2"></i>Issued Certificates</span>
                <span class="text-muted" style="font-size:0.82rem;"><?= count($certificates) ?> total</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($certificates)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-certificate fa-2x mb-2 d-block"></i>
                    No certificates issued yet.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-modern mb-0">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Subject</th>
                                <th>Grade</th>
                                <th>Hash</th>
                                <th>QR</th>
                                <th>Issued</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($certificates as $cert): ?>
                            <tr class="<?= !$cert['is_valid'] ? 'table-danger' : '' ?>">
                                <td>
                                    <div class="fw-bold" style="font-size:0.85rem;"><?= e($cert['student_fn'] . ' ' . $cert['student_ln']) ?></div>
                                    <div style="font-size:0.72rem;color:var(--gray-400);"><?= e($cert['student_uname']) ?></div>
                                </td>
                                <td style="font-size:0.85rem;"><?= e($cert['subject_name']) ?><br><small class="text-muted"><?= e($cert['course_code']) ?></small></td>
                                <td>
                                    <span class="fw-bold" style="font-size:0.95rem;color:<?= $cert['final_grade'] >= 75 ? 'var(--success)' : 'var(--danger, #EF4444)' ?>;">
                                        <?= number_format($cert['final_grade'], 2) ?>
                                    </span>
                                    <br><span class="badge bg-<?= $cert['grade_status'] === 'PASSED' ? 'success' : 'danger' ?>" style="font-size:0.65rem;"><?= $cert['grade_status'] ?></span>
                                </td>
                                <td>
                                    <span class="hash-text" title="<?= e($cert['certificate_hash']) ?>"><?= e(substr($cert['certificate_hash'], 0, 12)) ?>...</span>
                                    <br>
                                    <a href="<?= BASE_URL ?>/verify.php?hash=<?= urlencode($cert['certificate_hash']) ?>" target="_blank" class="text-primary" style="font-size:0.72rem;">
                                        <i class="fas fa-external-link-alt me-1"></i>Verify
                                    </a>
                                </td>
                                <td>
                                    <div class="qr-box" onclick="showQrPopup('<?= e($cert['student_fn'] . ' ' . $cert['student_ln']) ?>', '<?= e($cert['subject_name']) ?>', '<?= e($cert['certificate_hash']) ?>')" title="Click to enlarge">
                                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?= urlencode(rtrim(BASE_URL, '/') . '/verify.php?hash=' . $cert['certificate_hash']) ?>" alt="QR" style="width:60px;height:60px;">
                                    </div>
                                </td>
                                <td style="font-size:0.78rem;"><?= date('M d, Y', strtotime($cert['issued_at'])) ?><br><small class="text-muted"><?= e($cert['issuer_fn'] . ' ' . $cert['issuer_ln']) ?></small></td>
                                <td>
                                    <?php if ($cert['is_valid']): ?>
                                        <?php if ($role === 'superadmin'): ?>
                                        <form method="POST" onsubmit="return confirm('Revoke this certificate?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="revoke_certificate">
                                            <input type="hidden" name="cert_id" value="<?= $cert['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-ban"></i></button>
                                        </form>
                                        <?php else: ?>
                                        <span class="badge bg-success">Active</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                    <span class="badge bg-danger">Revoked</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><span><i class="fas fa-qrcode me-2"></i>Verify a Certificate</span></div>
            <div class="card-body">
                <form action="<?= BASE_URL ?>/verify.php" method="GET" target="_blank">
                    <div class="mb-3">
                        <label class="form-label">Enter Certificate Hash</label>
                        <input type="text" name="hash" class="form-control" placeholder="SHA-256 hash..." required>
                    </div>
                    <button type="submit" class="btn btn-primary-gradient w-100"><i class="fas fa-search me-1"></i>Verify Certificate</button>
                </form>
                <hr>
                <p style="font-size:0.82rem;color:var(--gray-500);">
                    <i class="fas fa-info-circle me-1"></i>
                    Students and external parties can scan the QR code or visit the verification page to confirm certificate authenticity.
                </p>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('certClassSelect').addEventListener('change', function() {
    const classId = this.value;
    const studentSelect = document.getElementById('certStudentSelect');
    studentSelect.innerHTML = '<option value="">Loading...</option>';
    if (!classId) { studentSelect.innerHTML = '<option value="">Select class first...</option>'; return; }

    fetch('<?= BASE_URL ?>/blockchain.php?ajax=students&class_id=' + classId)
        .then(r => r.json())
        .then(data => {
            studentSelect.innerHTML = '<option value="">Choose student...</option>';
            data.forEach(s => {
                studentSelect.innerHTML += '<option value="'+s.id+'">'+s.last_name+', '+s.first_name+' ('+s.username+')</option>';
            });
        })
        .catch(() => { studentSelect.innerHTML = '<option value="">Error loading students</option>'; });
});
</script>

<?php elseif ($tab === 'snapshots' && $role === 'superadmin'): ?>
<div class="card">
    <div class="card-header">
        <span><i class="fas fa-camera me-2"></i>Chain Snapshots</span>
        <span class="text-muted" style="font-size:0.82rem;"><?= count($snapshots) ?> snapshots</span>
    </div>
    <div class="card-body">
        <?php if (empty($snapshots)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-camera fa-2x mb-2 d-block"></i>
            No snapshots created yet. Use the camera icon on Grade Chain or Audit Chain tabs to create a snapshot.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-modern mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Chain Type</th>
                        <th>Snapshot Hash</th>
                        <th>Created By</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($snapshots as $snap): ?>
                <tr>
                    <td class="text-muted"><?= $snap['id'] ?></td>
                    <td><span class="badge bg-<?= $snap['chain_type'] === 'grade' ? 'primary' : 'info' ?>"><?= ucfirst($snap['chain_type']) ?></span></td>
                    <td class="hash-text"><?= e($snap['snapshot_hash']) ?></td>
                    <td style="font-size:0.85rem;"><?= e($snap['first_name'] . ' ' . $snap['last_name']) ?></td>
                    <td style="font-size:0.82rem;"><?= date('M d, Y g:i:sa', strtotime($snap['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="qrPopupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:20px;overflow:hidden;border:none;box-shadow:0 25px 60px rgba(0,0,0,.2);">
            <div class="modal-body text-center p-4">
                <div class="mb-3">
                    <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#DBEAFE,#93C5FD);display:flex;align-items:center;justify-content:center;margin:0 auto;">
                        <i class="fas fa-qrcode fa-xl" style="color:#3B82F6;"></i>
                    </div>
                </div>
                <h6 class="fw-bold mb-0" id="qrStudentName"></h6>
                <small class="text-muted" id="qrSubjectName"></small>
                <div class="my-3 p-3 rounded" style="background:#f8f9fa;display:inline-block;border-radius:16px !important;">
                    <img id="qrPopupImg" src="" alt="QR Code" style="width:200px;height:200px;border-radius:8px;">
                </div>
                <div class="hash-text mb-2" id="qrHashDisplay" style="font-size:0.7rem;"></div>
                <p style="font-size:0.78rem;color:#6c757d;margin-bottom:0;"><i class="fas fa-mobile-alt me-1"></i>Point your phone camera at the QR code to verify</p>
            </div>
            <div class="modal-footer justify-content-center gap-2 border-0 pt-0 pb-4">
                <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal" style="border-radius:10px;">
                    <i class="fas fa-times me-1"></i>Close
                </button>
                <a id="qrVerifyLink" href="#" target="_blank" class="btn btn-primary-gradient px-3" style="border-radius:10px;">
                    <i class="fas fa-external-link-alt me-1"></i>Open Verification
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function showQrPopup(studentName, subjectName, hash) {
    document.getElementById('qrStudentName').textContent = studentName;
    document.getElementById('qrSubjectName').textContent = subjectName;
    document.getElementById('qrHashDisplay').textContent = hash;
    const verifyUrl = '<?= rtrim(BASE_URL, '/') ?>/verify.php?hash=' + encodeURIComponent(hash);
    document.getElementById('qrPopupImg').src = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' + encodeURIComponent(verifyUrl);
    document.getElementById('qrVerifyLink').href = verifyUrl;
    new bootstrap.Modal(document.getElementById('qrPopupModal')).show();
}
</script>

<div class="modal fade" id="issueCertModal" tabindex="-1" aria-labelledby="issueCertModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;border:none;box-shadow:0 20px 50px rgba(0,0,0,.15);">
            <div class="modal-body text-center p-4">
                <div style="width:70px;height:70px;border-radius:50%;background:linear-gradient(135deg,#DBEAFE,#93C5FD);display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                    <i class="fas fa-certificate fa-2x" style="color:#3B82F6;"></i>
                </div>
                <h5 class="fw-bold mb-2">Issue Certificate?</h5>
                <p class="text-muted mb-1" style="font-size:0.9rem;">This creates a <strong>security-verified</strong> record that will be permanently hashed and stored on the grade chain.</p>
                <div class="d-flex align-items-center justify-content-center gap-2 my-3 p-2 rounded" style="background:#F0FDF4;font-size:0.82rem;">
                    <i class="fas fa-link text-success"></i>
                    <span class="text-success fw-bold">SHA-256 Hash &bull; QR Code &bull; Public Verification</span>
                </div>
                <p class="text-muted mb-0" style="font-size:0.8rem;"><i class="fas fa-info-circle me-1"></i>Once issued, the certificate can be verified by anyone via QR code or hash lookup.</p>
            </div>
            <div class="modal-footer justify-content-center gap-2 border-0 pt-0 pb-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal" style="border-radius:10px;">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary-gradient px-4" id="confirmIssueCert" style="border-radius:10px;">
                    <i class="fas fa-check me-1"></i>Yes, Issue Certificate
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const issueBtn = document.getElementById('issueCertBtn');
    const confirmBtn = document.getElementById('confirmIssueCert');
    const certForm = document.getElementById('certForm');
    if (issueBtn && certForm) {
        issueBtn.addEventListener('click', function() {
            if (!certForm.checkValidity()) { certForm.reportValidity(); return; }
            new bootstrap.Modal(document.getElementById('issueCertModal')).show();
        });
    }
    if (confirmBtn && certForm) {
        confirmBtn.addEventListener('click', function() { certForm.submit(); });
    }
});
</script>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
