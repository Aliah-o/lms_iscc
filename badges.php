<?php
$pageTitle = 'Badges';
require_once __DIR__ . '/helpers/functions.php';
requireRole('superadmin', 'student', 'instructor');
$pdo = getDB();
$user = currentUser();
$role = $user['role'];

if ($role === 'superadmin') {
    $breadcrumbPills = ['Administration', 'Badge Management'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect('/badges.php'); }
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $icon = trim($_POST['icon'] ?? 'fa-award');
            $badgeRule = trim($_POST['badge_rule'] ?? '');
            $ruleValue = intval($_POST['rule_value'] ?? 0);

            if ($name && $badgeRule) {
                $pdo->prepare("INSERT INTO badges (name, description, icon, badge_rule, rule_value) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$name, $description, $icon, $badgeRule, $ruleValue]);
                auditLog('badge_created', "Created badge: $name");
                flash('success', 'Badge created.');
            } else {
                flash('error', 'Name and rule are required.');
            }
        } elseif ($action === 'toggle') {
            $id = intval($_POST['badge_id'] ?? 0);
            $badge = $pdo->prepare("SELECT is_active FROM badges WHERE id = ?");
            $badge->execute([$id]);
            $badgeRow = $badge->fetch();
            if ($badgeRow) {
                $newStatus = $badgeRow['is_active'] ? 0 : 1;
                $pdo->prepare("UPDATE badges SET is_active = ? WHERE id = ?")->execute([$newStatus, $id]);
                $actionLabel = $newStatus ? 'restored' : 'archived';
                auditLog('badge_' . $actionLabel, ucfirst($actionLabel) . " badge #$id");
                flash('success', 'Badge ' . $actionLabel . '.');
            }
        } elseif ($action === 'edit') {
            $id = intval($_POST['badge_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $icon = trim($_POST['icon'] ?? 'fa-award');
            $badgeRule = trim($_POST['badge_rule'] ?? '');
            $ruleValue = intval($_POST['rule_value'] ?? 0);

            if ($id && $name) {
                $pdo->prepare("UPDATE badges SET name = ?, description = ?, icon = ?, badge_rule = ?, rule_value = ? WHERE id = ?")
                    ->execute([$name, $description, $icon, $badgeRule, $ruleValue, $id]);
                auditLog('badge_updated', "Updated badge #$id");
                flash('success', 'Badge updated.');
            }
        }
        redirect('/badges.php');
    }

    $badges = $pdo->query("SELECT b.*, (SELECT COUNT(*) FROM badge_earns be WHERE be.badge_id = b.id) as earned_count FROM badges b ORDER BY b.id")->fetchAll();

    $activeTab = $_GET['tab'] ?? 'active';
    $activeBadges = array_filter($badges, fn($b) => $b['is_active'] == 1);
    $archivedBadges = array_filter($badges, fn($b) => $b['is_active'] == 0);
    $displayBadges = ($activeTab === 'archived') ? $archivedBadges : $activeBadges;

    require_once __DIR__ . '/views/layouts/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <span class="text-muted" style="font-size:0.85rem;"><?= count($badges) ?> badges defined</span>
        </div>
        <button class="btn btn-primary-gradient" data-bs-toggle="modal" data-bs-target="#createBadgeModal"><i class="fas fa-plus me-1"></i>New Badge</button>
    </div>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'active' ? 'active' : '' ?>" href="<?= BASE_URL ?>/badges.php?tab=active">
                <i class="fas fa-check-circle me-1"></i>Active <span class="badge bg-primary ms-1"><?= count($activeBadges) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'archived' ? 'active' : '' ?>" href="<?= BASE_URL ?>/badges.php?tab=archived">
                <i class="fas fa-archive me-1"></i>Archived <span class="badge bg-secondary ms-1"><?= count($archivedBadges) ?></span>
            </a>
        </li>
    </ul>

    <div class="row g-3">
        <?php foreach ($displayBadges as $badge): ?>
        <div class="col-sm-6 col-md-4 col-lg-3">
            <div class="badge-card <?= $badge['is_active'] ? 'earned' : 'locked' ?>">
                <div class="badge-icon"><i class="fas <?= e($badge['icon']) ?>"></i></div>
                <div class="badge-name"><?= e($badge['name']) ?></div>
                <div class="badge-desc"><?= e($badge['description']) ?></div>
                <div class="mt-2" style="font-size:0.72rem;color:var(--gray-500);">
                    Rule: <?= e($badge['badge_rule']) ?> (<?= $badge['rule_value'] ?>)
                </div>
                <div class="mt-1" style="font-size:0.72rem;color:var(--primary);font-weight:600;">
                    <?= $badge['earned_count'] ?> student(s) earned
                </div>
                <div class="mt-2 d-flex gap-1 justify-content-center">
                    <button class="btn btn-sm btn-outline-info" onclick="editBadge(<?= htmlspecialchars(json_encode($badge), ENT_QUOTES) ?>)"><i class="fas fa-edit"></i></button>
                    <form method="POST" class="d-inline" onsubmit="return confirmForm(this, '<?= $badge['is_active'] ? 'Archive' : 'Restore' ?> this badge?', '<?= $badge['is_active'] ? 'Archive' : 'Restore' ?>')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="badge_id" value="<?= $badge['id'] ?>">
                        <button class="btn btn-sm btn-outline-<?= $badge['is_active'] ? 'warning' : 'success' ?>"><i class="fas fa-<?= $badge['is_active'] ? 'archive' : 'undo' ?> me-1"></i><?= $badge['is_active'] ? 'Archive' : 'Restore' ?></button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="modal fade" id="createBadgeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header"><h5 class="modal-title">Create Badge</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-3"><label class="form-label">Name</label><input type="text" name="name" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
                        <div class="mb-3">
                            <label class="form-label">Icon (FontAwesome class)</label>
                            <input type="text" name="icon" class="form-control" value="fa-award" placeholder="e.g. fa-star, fa-bolt, fa-medal">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Badge Rule</label>
                            <select name="badge_rule" class="form-select" required>
                                <option value="first_quiz">First Quiz Completed</option>
                                <option value="beginner_complete">Completed Beginner Level</option>
                                <option value="perfect_score">Perfect Score</option>
                                <option value="nodes_completed">Completed X Nodes</option>
                                <option value="growth_percent">Improved by X%</option>
                                <option value="quiz_participation">Live Quiz Participation (X games)</option>
                                <option value="ticket_created">Support Tickets Created (X tickets)</option>
                                <option value="forum_posts">Forum Activity (X posts/replies)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Rule Value</label>
                            <input type="number" name="rule_value" class="form-control" value="1" min="0">
                            <small class="text-muted">For nodes_completed: number of nodes; for growth_percent: % improvement; for quiz_participation: games played; for ticket_created: tickets made; for forum_posts: total posts/replies; for others: typically 1 or 100.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-gradient">Create Badge</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editBadgeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="badge_id" id="ebId">
                    <div class="modal-header"><h5 class="modal-title">Edit Badge</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-3"><label class="form-label">Name</label><input type="text" name="name" id="ebName" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label">Description</label><textarea name="description" id="ebDesc" class="form-control" rows="2"></textarea></div>
                        <div class="mb-3"><label class="form-label">Icon</label><input type="text" name="icon" id="ebIcon" class="form-control"></div>
                        <div class="mb-3">
                            <label class="form-label">Badge Rule</label>
                            <select name="badge_rule" id="ebRule" class="form-select" required>
                                <option value="first_quiz">First Quiz Completed</option>
                                <option value="beginner_complete">Completed Beginner Level</option>
                                <option value="perfect_score">Perfect Score</option>
                                <option value="nodes_completed">Completed X Nodes</option>
                                <option value="growth_percent">Improved by X%</option>
                                <option value="quiz_participation">Live Quiz Participation (X games)</option>
                                <option value="ticket_created">Support Tickets Created (X tickets)</option>
                                <option value="forum_posts">Forum Activity (X posts/replies)</option>
                            </select>
                        </div>
                        <div class="mb-3"><label class="form-label">Rule Value</label><input type="number" name="rule_value" id="ebValue" class="form-control" min="0"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-gradient">Update Badge</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function editBadge(badge) {
        document.getElementById('ebId').value = badge.id;
        document.getElementById('ebName').value = badge.name;
        document.getElementById('ebDesc').value = badge.description || '';
        document.getElementById('ebIcon').value = badge.icon;
        document.getElementById('ebRule').value = badge.badge_rule;
        document.getElementById('ebValue').value = badge.rule_value;
        new bootstrap.Modal(document.getElementById('editBadgeModal')).show();
    }
    </script>

<?php } elseif ($role === 'instructor') {
    $breadcrumbPills = ['Teaching', 'Student Badges'];

    $stmt = $pdo->prepare("SELECT DISTINCT u.id, u.first_name, u.last_name, u.username, u.program_code, u.year_level
        FROM users u
        JOIN class_enrollments ce ON ce.student_id = u.id
        JOIN instructor_classes tc ON tc.id = ce.class_id
        WHERE tc.instructor_id = ? AND tc.is_active = 1 AND u.is_active = 1
        ORDER BY u.last_name, u.first_name");
    $stmt->execute([$user['id']]);
    $myStudents = $stmt->fetchAll();

    $allBadges = $pdo->query("SELECT * FROM badges WHERE is_active = 1 ORDER BY id")->fetchAll();

    $studentBadges = [];
    foreach ($myStudents as $s) {
        $earned = $pdo->prepare("SELECT badge_id FROM badge_earns WHERE student_id = ?");
        $earned->execute([$s['id']]);
        $studentBadges[$s['id']] = $earned->fetchAll(PDO::FETCH_COLUMN);
    }

    require_once __DIR__ . '/views/layouts/header.php';
    ?>
    <?php if (empty($myStudents)): ?>
    <div class="empty-state">
        <svg viewBox="0 0 200 200" fill="none"><circle cx="100" cy="100" r="80" fill="#F1F5F9"/><text x="100" y="108" text-anchor="middle" fill="#94A3B8" font-size="40">🏅</text></svg>
        <h5>No Students</h5>
        <p>You don't have any enrolled students yet.</p>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-header"><span><i class="fas fa-medal me-2"></i>Student Badges Overview</span></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-modern mb-0">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Program</th>
                            <?php foreach ($allBadges as $b): ?>
                            <th class="text-center" style="min-width:60px;" title="<?= e($b['name']) ?>"><i class="fas <?= e($b['icon']) ?>"></i></th>
                            <?php endforeach; ?>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myStudents as $s): ?>
                        <tr>
                            <td class="fw-bold"><?= e($s['last_name'] . ', ' . $s['first_name']) ?></td>
                            <td><span class="badge-role student"><?= e($s['program_code'] ?? '—') ?></span></td>
                            <?php foreach ($allBadges as $b): ?>
                            <td class="text-center">
                                <?php if (in_array($b['id'], $studentBadges[$s['id']] ?? [])): ?>
                                <i class="fas <?= e($b['icon']) ?>" style="color:var(--accent);font-size:1.1rem;" title="<?= e($b['name']) ?>"></i>
                                <?php else: ?>
                                <i class="fas fa-lock" style="color:var(--gray-300);font-size:0.8rem;"></i>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="fw-bold text-center"><?= count($studentBadges[$s['id']] ?? []) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

<?php } else {
    $breadcrumbPills = ['My Learning', 'My Badges'];

    $allBadges = $pdo->query("SELECT * FROM badges WHERE is_active = 1 ORDER BY id")->fetchAll();

    $earnedBadgeIds = $pdo->prepare("SELECT badge_id, earned_at FROM badge_earns WHERE student_id = ?");
    $earnedBadgeIds->execute([$user['id']]);
    $earnedMap = [];
    while ($row = $earnedBadgeIds->fetch()) {
        $earnedMap[$row['badge_id']] = $row['earned_at'];
    }

    require_once __DIR__ . '/views/layouts/header.php';
    ?>
    <div class="mb-4 text-center">
        <h5 class="fw-bold"><?= count($earnedMap) ?> / <?= count($allBadges) ?> Badges Earned</h5>
        <div class="progress-bar-custom mx-auto" style="max-width:400px;">
            <div class="bar" style="width:<?= count($allBadges) > 0 ? round(count($earnedMap) / count($allBadges) * 100) : 0 ?>%"></div>
        </div>
    </div>

    <div class="row g-3">
        <?php foreach ($allBadges as $badge): ?>
        <?php $isEarned = isset($earnedMap[$badge['id']]); ?>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="badge-card <?= $isEarned ? 'earned' : 'locked' ?>">
                <div class="badge-icon"><i class="fas <?= e($badge['icon']) ?>"></i></div>
                <div class="badge-name"><?= e($badge['name']) ?></div>
                <div class="badge-desc"><?= e($badge['description']) ?></div>
                <?php if ($isEarned): ?>
                <div class="mt-2" style="font-size:0.7rem;color:var(--success);font-weight:600;">
                    <i class="fas fa-check-circle me-1"></i>Earned <?= formatDate($earnedMap[$badge['id']]) ?>
                </div>
                <?php else: ?>
                <div class="mt-2" style="font-size:0.7rem;color:var(--gray-400);font-weight:600;">
                    <i class="fas fa-lock me-1"></i>Locked
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($allBadges)): ?>
    <div class="empty-state">
        <svg viewBox="0 0 200 200" fill="none"><circle cx="100" cy="100" r="80" fill="#F1F5F9"/><text x="100" y="108" text-anchor="middle" fill="#94A3B8" font-size="40">🏅</text></svg>
        <h5>No Badges Available</h5>
        <p>Badges haven't been set up yet. Check back later!</p>
    </div>
    <?php endif; ?>
<?php } ?>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
