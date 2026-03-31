<?php
$pageTitle = 'Knowledge Tree';
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'student');
$pdo = getDB();
$user = currentUser();
$role = $user['role'];
$classId = intval($_GET['class_id'] ?? 0);

if (!$classId) {
    if ($role === 'instructor') {
        $breadcrumbPills = ['Teaching', 'Knowledge Tree'];
        $stmt = $pdo->prepare("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id WHERE tc.instructor_id = ? AND tc.is_active = 1 ORDER BY tc.program_code, tc.year_level");
        $stmt->execute([$user['id']]);
        $classes = $stmt->fetchAll();
    } else {
        $breadcrumbPills = ['My Learning', 'Knowledge Tree'];
        $stmt = $pdo->prepare("SELECT ce.class_id, tc.subject_name, tc.program_code, tc.year_level, s.section_name, tc.id FROM class_enrollments ce JOIN instructor_classes tc ON ce.class_id = tc.id JOIN sections s ON tc.section_id = s.id WHERE ce.student_id = ? AND tc.is_active = 1");
        $stmt->execute([$user['id']]);
        $classes = $stmt->fetchAll();
    }

    require_once __DIR__ . '/views/layouts/header.php';
    ?>
    <div class="row g-3">
        <?php if (empty($classes)): ?>
        <div class="col-12">
            <div class="empty-state">
                <svg viewBox="0 0 200 200" fill="none"><circle cx="100" cy="100" r="80" fill="#F1F5F9"/><text x="100" y="108" text-anchor="middle" fill="#94A3B8" font-size="40">🌳</text></svg>
                <h5>No Classes Available</h5>
                <p>You need to be assigned/enrolled in a class to view Knowledge Trees.</p>
            </div>
        </div>
        <?php else: ?>
        <?php foreach ($classes as $cls): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#D1FAE5,#A7F3D0);display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-sitemap" style="color:#065F46;font-size:1.1rem;"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold"><?= e($cls['subject_name'] ?? 'General') ?></h6>
                            <small class="text-muted"><?= e(PROGRAMS[$cls['program_code']] ?? $cls['program_code']) ?> • <?= e(YEAR_LEVELS[$cls['year_level']]) ?> • Section <?= e($cls['section_name']) ?></small>
                        </div>
                    </div>
                    <div class="mt-auto">
                        <a href="<?= BASE_URL ?>/knowledge-tree.php?class_id=<?= $cls['id'] ?? $cls['class_id'] ?>" class="btn btn-sm btn-primary-gradient w-100"><i class="fas fa-sitemap me-1"></i>View Tree</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
    require_once __DIR__ . '/views/layouts/footer.php';
    exit;
}

if ($role === 'instructor') {
    $cls = $pdo->prepare("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id WHERE tc.id = ? AND tc.instructor_id = ?");
    $cls->execute([$classId, $user['id']]);
} else {
    $cls = $pdo->prepare("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id JOIN class_enrollments ce ON ce.class_id = tc.id WHERE tc.id = ? AND ce.student_id = ?");
    $cls->execute([$classId, $user['id']]);
}
$class = $cls->fetch();
if (!$class) { flash('error', 'Access denied.'); redirect('/classes.php'); }

$breadcrumbPills = [PROGRAMS[$class['program_code']] ?? $class['program_code'], YEAR_LEVELS[$class['year_level']], 'Section ' . $class['section_name'], 'Knowledge Tree'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'instructor') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect("/knowledge-tree.php?class_id=$classId"); }
    $action = $_POST['action'] ?? '';

    if ($action === 'create_node') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $level = $_POST['level'] ?? 'beginner';
        $content = $_POST['content'] ?? '';
        $quizId = intval($_POST['quiz_id'] ?? 0) ?: null;

        if ($title && in_array($level, ['beginner', 'intermediate', 'advanced'])) {
            $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM knowledge_nodes WHERE class_id = ? AND level = ?");
            $maxOrder->execute([$classId, $level]);
            $nextOrder = $maxOrder->fetchColumn() + 1;

            $pdo->prepare("INSERT INTO knowledge_nodes (class_id, title, description, level, sort_order, content, quiz_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$classId, $title, $description, $level, $nextOrder, $content, $quizId]);
            auditLog('node_created', "Created knowledge level: $title");
            flash('success', 'Knowledge level created.');
        } else {
            flash('error', 'Invalid input.');
        }
    } elseif ($action === 'edit_node') {
        $nodeId = intval($_POST['node_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $level = $_POST['level'] ?? 'beginner';
        $content = $_POST['content'] ?? '';
        $quizId = intval($_POST['quiz_id'] ?? 0) ?: null;

        if ($nodeId && $title && in_array($level, ['beginner', 'intermediate', 'advanced'])) {
            $pdo->prepare("UPDATE knowledge_nodes SET title = ?, description = ?, level = ?, content = ?, quiz_id = ? WHERE id = ? AND class_id = ?")
                ->execute([$title, $description, $level, $content, $quizId, $nodeId, $classId]);
            auditLog('node_updated', "Updated knowledge level #$nodeId");
            flash('success', 'Level updated.');
        }
    } elseif ($action === 'delete_node') {
        $nodeId = intval($_POST['node_id'] ?? 0);
        if ($nodeId) {
            $pdo->prepare("DELETE FROM knowledge_nodes WHERE id = ? AND class_id = ?")->execute([$nodeId, $classId]);
            auditLog('node_deleted', "Deleted knowledge level #$nodeId");
            flash('success', 'Level deleted.');
        }
    } elseif ($action === 'reorder') {
        $nodeId = intval($_POST['node_id'] ?? 0);
        $direction = $_POST['direction'] ?? '';
        if ($nodeId && in_array($direction, ['up', 'down'])) {
            $node = $pdo->prepare("SELECT * FROM knowledge_nodes WHERE id = ? AND class_id = ?");
            $node->execute([$nodeId, $classId]);
            $node = $node->fetch();
            if ($node) {
                if ($direction === 'up') {
                    $swap = $pdo->prepare("SELECT * FROM knowledge_nodes WHERE class_id = ? AND level = ? AND sort_order < ? ORDER BY sort_order DESC LIMIT 1");
                    $swap->execute([$classId, $node['level'], $node['sort_order']]);
                } else {
                    $swap = $pdo->prepare("SELECT * FROM knowledge_nodes WHERE class_id = ? AND level = ? AND sort_order > ? ORDER BY sort_order ASC LIMIT 1");
                    $swap->execute([$classId, $node['level'], $node['sort_order']]);
                }
                $swapNode = $swap->fetch();
                if ($swapNode) {
                    $pdo->prepare("UPDATE knowledge_nodes SET sort_order = ? WHERE id = ?")->execute([$swapNode['sort_order'], $node['id']]);
                    $pdo->prepare("UPDATE knowledge_nodes SET sort_order = ? WHERE id = ?")->execute([$node['sort_order'], $swapNode['id']]);
                }
            }
        }
    }
    redirect("/knowledge-tree.php?class_id=$classId");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'student') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect("/knowledge-tree.php?class_id=$classId"); }
    $action = $_POST['action'] ?? '';

    if ($action === 'complete_node') {
        $nodeId = intval($_POST['node_id'] ?? 0);
        if ($nodeId) {
            $nodeCheck = $pdo->prepare("SELECT * FROM knowledge_nodes WHERE id = ? AND class_id = ?");
            $nodeCheck->execute([$nodeId, $classId]);
            $node = $nodeCheck->fetch();

            if ($node) {
                $canComplete = true;
                if ($node['quiz_id']) {
                    $quizAttempt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND student_id = ? AND score >= 60 ORDER BY id DESC LIMIT 1");
                    $quizAttempt->execute([$node['quiz_id'], $user['id']]);
                    if (!$quizAttempt->fetch()) {
                        $canComplete = false;
                        flash('error', 'You need to pass the linked quiz (60%+) to complete this level.');
                    }
                }

                if ($canComplete) {
                    $pdo->prepare("INSERT INTO knowledge_node_progress (node_id, student_id, completed, completed_at) VALUES (?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE completed = 1, completed_at = NOW()")
                        ->execute([$nodeId, $user['id']]);
                    auditLog('node_completed', "Student completed knowledge level #$nodeId");
                    flash('success', 'Level completed! Keep going!');

                    $totalCompleted = $pdo->prepare("SELECT COUNT(*) FROM knowledge_node_progress WHERE student_id = ? AND completed = 1");
                    $totalCompleted->execute([$user['id']]);
                    $completedCount = $totalCompleted->fetchColumn();

                    $nodeBadges = $pdo->query("SELECT * FROM badges WHERE badge_rule = 'nodes_completed' AND is_active = 1")->fetchAll();
                    foreach ($nodeBadges as $badge) {
                        if ($completedCount >= $badge['rule_value']) {
                            $pdo->prepare("INSERT IGNORE INTO badge_earns (badge_id, student_id) VALUES (?, ?)")->execute([$badge['id'], $user['id']]);
                        }
                    }

                    $beginnerNodes = $pdo->prepare("SELECT COUNT(*) FROM knowledge_nodes WHERE class_id = ? AND level = 'beginner'");
                    $beginnerNodes->execute([$classId]);
                    $totalBeginner = $beginnerNodes->fetchColumn();

                    $completedBeginner = $pdo->prepare("SELECT COUNT(*) FROM knowledge_node_progress knp JOIN knowledge_nodes kn ON knp.node_id = kn.id WHERE kn.class_id = ? AND kn.level = 'beginner' AND knp.student_id = ? AND knp.completed = 1");
                    $completedBeginner->execute([$classId, $user['id']]);
                    $doneBeginner = $completedBeginner->fetchColumn();

                    if ($totalBeginner > 0 && $doneBeginner >= $totalBeginner) {
                        $beginnerBadge = $pdo->query("SELECT id FROM badges WHERE badge_rule = 'beginner_complete' AND is_active = 1 LIMIT 1")->fetch();
                        if ($beginnerBadge) {
                            $pdo->prepare("INSERT IGNORE INTO badge_earns (badge_id, student_id) VALUES (?, ?)")->execute([$beginnerBadge['id'], $user['id']]);
                        }
                    }
                }
            }
        }
    }
    redirect("/knowledge-tree.php?class_id=$classId");
}

$viewNode = null;
if (isset($_GET['node'])) {
    $nodeId = intval($_GET['node']);
    $stmt = $pdo->prepare("SELECT * FROM knowledge_nodes WHERE id = ? AND class_id = ?");
    $stmt->execute([$nodeId, $classId]);
    $viewNode = $stmt->fetch();
}

$nodes = $pdo->prepare("SELECT kn.*, (SELECT COUNT(*) FROM knowledge_node_progress knp WHERE knp.node_id = kn.id AND knp.completed = 1) as completed_count FROM knowledge_nodes kn WHERE kn.class_id = ? ORDER BY kn.level, kn.sort_order");
$nodes->execute([$classId]);
$allNodes = $nodes->fetchAll();

$studentProgress = [];
if ($role === 'student') {
    $prog = $pdo->prepare("SELECT node_id FROM knowledge_node_progress WHERE student_id = ? AND completed = 1");
    $prog->execute([$user['id']]);
    $studentProgress = $prog->fetchAll(PDO::FETCH_COLUMN);
}

$nodesByLevel = ['beginner' => [], 'intermediate' => [], 'advanced' => []];
foreach ($allNodes as $n) {
    $nodesByLevel[$n['level']][] = $n;
}

$totalNodes = count($allNodes);
$completedNodes = count($studentProgress);

function isNodeUnlocked($node, $nodesByLevel, $studentProgress) {
    if ($node['level'] === 'beginner') return true;

    $prevLevel = ($node['level'] === 'intermediate') ? 'beginner' : 'intermediate';
    foreach ($nodesByLevel[$prevLevel] as $prevNode) {
        if (!in_array($prevNode['id'], $studentProgress)) {
            return false;
        }
    }
    return true;
}

$classQuizzes = [];
if ($role === 'instructor') {
    $stmt = $pdo->prepare("SELECT id, title FROM quizzes WHERE class_id = ? ORDER BY title");
    $stmt->execute([$classId]);
    $classQuizzes = $stmt->fetchAll();
}

require_once __DIR__ . '/views/layouts/header.php';
?>

<?php if ($viewNode): ?>
<a href="<?= BASE_URL ?>/knowledge-tree.php?class_id=<?= $classId ?>" class="btn btn-outline-secondary btn-sm mb-3"><i class="fas fa-arrow-left me-1"></i>Back to Tree</a>
<div class="card">
    <div class="card-header">
        <span>
            <span class="badge-role <?= $viewNode['level'] === 'beginner' ? 'instructor' : ($viewNode['level'] === 'intermediate' ? 'staff' : 'superadmin') ?>"><?= e(ucfirst($viewNode['level'])) ?></span>
            <span class="ms-2"><?= e($viewNode['title']) ?></span>
        </span>
    </div>
    <div class="card-body">
        <?php if ($viewNode['description']): ?>
        <p class="text-muted mb-3"><?= e($viewNode['description']) ?></p>
        <?php endif; ?>
        <div class="lesson-content"><?= $viewNode['content'] ?></div>

        <?php if ($viewNode['quiz_id']): ?>
        <div class="mt-4 p-3" style="background:var(--gray-50);border-radius:8px;">
            <i class="fas fa-question-circle text-warning me-2"></i>
            <strong>Linked Quiz:</strong>
            <a href="<?= BASE_URL ?>/quizzes.php?class_id=<?= $classId ?>&take=<?= $viewNode['quiz_id'] ?>" class="btn btn-sm btn-outline-warning ms-2">Take Quiz</a>
        </div>
        <?php endif; ?>

        <?php if ($role === 'student'): ?>
            <?php
            $isCompleted = in_array($viewNode['id'], $studentProgress);
            $isUnlocked = isNodeUnlocked($viewNode, $nodesByLevel, $studentProgress);
            ?>
            <?php if ($isCompleted): ?>
            <div class="alert mt-4" style="background:#D1FAE5;color:#065F46;border:none;border-radius:10px;">
                <i class="fas fa-check-circle me-1"></i> You've completed this level!
            </div>
            <?php elseif ($isUnlocked): ?>
            <form method="POST" class="mt-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="complete_node">
                <input type="hidden" name="node_id" value="<?= $viewNode['id'] ?>">
                <button type="submit" class="btn btn-primary-gradient">
                    <i class="fas fa-check me-1"></i>Mark as Complete
                </button>
                <?php if ($viewNode['quiz_id']): ?>
                <small class="text-muted d-block mt-2">Note: You must pass the linked quiz with 60%+ first.</small>
                <?php endif; ?>
            </form>
            <?php else: ?>
            <div class="alert mt-4" style="background:#FEE2E2;color:#991B1B;border:none;border-radius:10px;">
                <i class="fas fa-lock me-1"></i> Complete all levels in the previous tier to unlock this.
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<?php if ($role === 'instructor'): ?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <span class="text-muted" style="font-size:0.85rem;"><?= $totalNodes ?> levels total</span>
    </div>
    <button class="btn btn-primary-gradient" data-bs-toggle="modal" data-bs-target="#createNodeModal"><i class="fas fa-plus me-1"></i>New Level</button>
</div>
<?php else: ?>
<div class="mb-4">
    <div class="d-flex align-items-center gap-3">
        <div class="flex-grow-1">
            <div class="d-flex align-items-center justify-content-between mb-1">
                <span style="font-size:0.85rem;font-weight:600;">Progress</span>
                <span style="font-size:0.85rem;font-weight:700;color:var(--primary);"><?= $completedNodes ?>/<?= $totalNodes ?></span>
            </div>
            <div class="progress-bar-custom">
                <div class="bar" style="width:<?= $totalNodes > 0 ? round($completedNodes / $totalNodes * 100) : 0 ?>%"></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="knowledge-tree">
    <?php foreach (['beginner', 'intermediate', 'advanced'] as $level): ?>
    <div class="tree-level-header <?= $level ?>">
        <i class="fas fa-<?= $level === 'beginner' ? 'seedling' : ($level === 'intermediate' ? 'tree' : 'crown') ?> me-2"></i>
        <?= e(KNOWLEDGE_LEVELS[$level]) ?>
        <span style="font-size:0.7rem;opacity:0.7;margin-left:8px;">(<?= count($nodesByLevel[$level]) ?> levels)</span>
    </div>

    <?php if (empty($nodesByLevel[$level])): ?>
    <div class="text-center py-3 text-muted" style="font-size:0.85rem;">
        <i class="fas fa-inbox me-1"></i> No levels in this tier yet.
    </div>
    <?php endif; ?>

    <?php foreach ($nodesByLevel[$level] as $node): ?>
        <?php
        $isCompleted = in_array($node['id'], $studentProgress);
        $isUnlocked = ($role === 'instructor') ? true : isNodeUnlocked($node, $nodesByLevel, $studentProgress);
        $nodeClass = $isCompleted ? 'completed' : (!$isUnlocked ? 'locked' : '');
        ?>
        <div class="tree-node <?= $nodeClass ?>">
            <div class="node-icon">
                <?php if ($isCompleted): ?>
                <i class="fas fa-check"></i>
                <?php elseif (!$isUnlocked): ?>
                <i class="fas fa-lock"></i>
                <?php else: ?>
                <i class="fas fa-circle"></i>
                <?php endif; ?>
            </div>
            <div class="node-info flex-grow-1">
                <div class="node-title"><?= e($node['title']) ?></div>
                <div class="node-desc"><?= e($node['description']) ?></div>
                <?php if ($node['quiz_id']): ?>
                <span class="badge bg-warning text-dark mt-1" style="font-size:0.65rem;"><i class="fas fa-question-circle me-1"></i>Quiz Required</span>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-1">
                <?php if ($isUnlocked || $role === 'instructor'): ?>
                <a href="<?= BASE_URL ?>/knowledge-tree.php?class_id=<?= $classId ?>&node=<?= $node['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a>
                <?php endif; ?>
                <?php if ($role === 'instructor'): ?>
                <form method="POST" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reorder">
                    <input type="hidden" name="node_id" value="<?= $node['id'] ?>">
                    <input type="hidden" name="direction" value="up">
                    <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-up"></i></button>
                </form>
                <form method="POST" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reorder">
                    <input type="hidden" name="node_id" value="<?= $node['id'] ?>">
                    <input type="hidden" name="direction" value="down">
                    <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-down"></i></button>
                </form>
                <button class="btn btn-sm btn-outline-info" onclick="editNode(<?= htmlspecialchars(json_encode($node), ENT_QUOTES) ?>)"><i class="fas fa-edit"></i></button>
                <form method="POST" class="d-inline" onsubmit="return confirmForm(this, 'Delete this level? This action cannot be undone.', 'Delete Level')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_node">
                    <input type="hidden" name="node_id" value="<?= $node['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php endforeach; ?>
</div>

<?php if ($role === 'instructor'): ?>
<div class="modal fade" id="createNodeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_node">
                <div class="modal-header"><h5 class="modal-title">Create Knowledge Level</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label">Title</label><input type="text" name="title" class="form-control" required></div>
                        <div class="col-md-4">
                            <label class="form-label">Level</label>
                            <select name="level" class="form-select" required>
                                <option value="beginner">Beginner</option>
                                <option value="intermediate">Intermediate</option>
                                <option value="advanced">Advanced</option>
                            </select>
                        </div>
                        <div class="col-12"><label class="form-label">Description</label><input type="text" name="description" class="form-control" placeholder="Brief description..."></div>
                        <div class="col-12"><label class="form-label">Content</label><textarea name="content" class="form-control" rows="8" placeholder="Level content (HTML supported)..."></textarea></div>
                        <div class="col-12">
                            <label class="form-label">Link Quiz (Optional)</label>
                            <select name="quiz_id" class="form-select">
                                <option value="">No quiz linked</option>
                                <?php foreach ($classQuizzes as $q): ?>
                                <option value="<?= $q['id'] ?>"><?= e($q['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-gradient">Create Level</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editNodeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_node">
                <input type="hidden" name="node_id" id="editNodeId">
                <div class="modal-header"><h5 class="modal-title">Edit Knowledge Level</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label">Title</label><input type="text" name="title" id="editNodeTitle" class="form-control" required></div>
                        <div class="col-md-4">
                            <label class="form-label">Level</label>
                            <select name="level" id="editNodeLevel" class="form-select" required>
                                <option value="beginner">Beginner</option>
                                <option value="intermediate">Intermediate</option>
                                <option value="advanced">Advanced</option>
                            </select>
                        </div>
                        <div class="col-12"><label class="form-label">Description</label><input type="text" name="description" id="editNodeDesc" class="form-control"></div>
                        <div class="col-12"><label class="form-label">Content</label><textarea name="content" id="editNodeContent" class="form-control" rows="8"></textarea></div>
                        <div class="col-12">
                            <label class="form-label">Link Quiz (Optional)</label>
                            <select name="quiz_id" id="editNodeQuiz" class="form-select">
                                <option value="">No quiz linked</option>
                                <?php foreach ($classQuizzes as $q): ?>
                                <option value="<?= $q['id'] ?>"><?= e($q['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-gradient">Update Level</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editNode(node) {
    document.getElementById('editNodeId').value = node.id;
    document.getElementById('editNodeTitle').value = node.title;
    document.getElementById('editNodeLevel').value = node.level;
    document.getElementById('editNodeDesc').value = node.description || '';
    document.getElementById('editNodeContent').value = node.content || '';
    document.getElementById('editNodeQuiz').value = node.quiz_id || '';
    new bootstrap.Modal(document.getElementById('editNodeModal')).show();
}
</script>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
