<?php
$pageTitle = 'Sections';
require_once __DIR__ . '/helpers/functions.php';
requireRole('superadmin', 'staff');
$pdo = getDB();
$breadcrumbPills = ['BSIT', 'Sections'];

$filterYear = $_GET['year'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect('/sections.php'); }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $yr = intval($_POST['year_level'] ?? 0);
        $name = strtoupper(trim($_POST['section_name'] ?? ''));

        if (!isset(YEAR_LEVELS[$yr]) || empty($name)) {
            flash('error', 'Invalid input.');
        } else {
            $check = $pdo->prepare("SELECT id FROM sections WHERE program_code = 'BSIT' AND year_level = ? AND section_name = ?");
            $check->execute([$yr, $name]);
            if ($check->fetch()) {
                flash('error', 'Section already exists.');
            } else {
                $pdo->prepare("INSERT INTO sections (program_code, year_level, section_name) VALUES ('BSIT', ?, ?)")
                    ->execute([$yr, $name]);
                auditLog('section_created', "Created BSIT section $yr$name");
                flash('success', "Section $yr$name created successfully.");
            }
        }
    } elseif ($action === 'toggle') {
        $id = intval($_POST['section_id'] ?? 0);
        $sec = $pdo->prepare("SELECT is_active FROM sections WHERE id = ?");
        $sec->execute([$id]);
        $secRow = $sec->fetch();
        if ($secRow) {
            $newStatus = $secRow['is_active'] ? 0 : 1;
            $pdo->prepare("UPDATE sections SET is_active = ? WHERE id = ?")->execute([$newStatus, $id]);
            $actionLabel = $newStatus ? 'restored' : 'archived';
            auditLog('section_' . $actionLabel, ucfirst($actionLabel) . " section #$id");
            flash('success', 'Section ' . $actionLabel . '.');
        }
    }
    redirect('/sections.php' . ($filterYear ? "?year=$filterYear" : '') . (isset($_POST['current_tab']) && $_POST['current_tab'] === 'archived' ? ($filterYear ? '&' : '?') . 'tab=archived' : ''));
}

$activeTab = $_GET['tab'] ?? 'active';

$query = "SELECT s.*, (SELECT COUNT(*) FROM users u WHERE u.section_id = s.id AND u.role='student' AND u.is_active=1) as student_count FROM sections s WHERE s.program_code = 'BSIT'";
$params = [];
if ($filterYear) { $query .= " AND s.year_level = ?"; $params[] = intval($filterYear); }
$query .= " ORDER BY s.year_level, s.section_name";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$allSectionsList = $stmt->fetchAll();

$activeSections = array_filter($allSectionsList, fn($s) => $s['is_active'] == 1);
$archivedSections = array_filter($allSectionsList, fn($s) => $s['is_active'] == 0);
$sections = ($activeTab === 'archived') ? $archivedSections : $activeSections;

require_once __DIR__ . '/views/layouts/header.php';
?>

<div class="row g-3 mb-3 align-items-start sections-toolbar">
    <div class="col-lg-8">
        <div class="d-flex gap-2 flex-wrap sections-filter-row">
            <a href="<?= BASE_URL ?>/sections.php<?= $activeTab === 'archived' ? '?tab=archived' : '' ?>" class="btn btn-sm <?= !$filterYear ? 'btn-primary' : 'btn-outline-secondary' ?>">All</a>
            <?php foreach (YEAR_LEVELS as $val => $label): ?>
            <a href="<?= BASE_URL ?>/sections.php?year=<?= $val ?><?= $activeTab === 'archived' ? '&tab=archived' : '' ?>" class="btn btn-sm <?= $filterYear == $val ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="col-lg-4 text-end sections-toolbar-action">
        <button class="btn btn-primary-gradient sections-create-btn" data-bs-toggle="modal" data-bs-target="#createSectionModal"><i class="fas fa-plus me-1"></i>New Section</button>
    </div>
</div>

<ul class="nav nav-tabs mb-4 sections-tabs">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'active' ? 'active' : '' ?>" href="<?= BASE_URL ?>/sections.php?tab=active<?= $filterYear ? '&year=' . $filterYear : '' ?>">
            <i class="fas fa-check-circle me-1"></i>Active <span class="badge bg-primary ms-1"><?= count($activeSections) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'archived' ? 'active' : '' ?>" href="<?= BASE_URL ?>/sections.php?tab=archived<?= $filterYear ? '&year=' . $filterYear : '' ?>">
            <i class="fas fa-archive me-1"></i>Archived <span class="badge bg-secondary ms-1"><?= count($archivedSections) ?></span>
        </a>
    </li>
</ul>

<div class="card">
    <div class="card-body p-0">
        <div class="alert alert-info mb-0 rounded-0 border-0 border-bottom" style="font-size:0.82rem;">
            <i class="fas fa-info-circle me-1"></i>
            <strong>Section naming guide:</strong>
            <strong>1A</strong> = 1st Year Section A,
            <strong>1B</strong> = 1st Year Section B,
            <strong>2A</strong> = 2nd Year Section A,
            <strong>3A</strong> = 3rd Year Section A,
            <strong>4A</strong> = 4th Year Section A, and so on.
        </div>
        <div class="table-responsive sections-table-wrap">
            <table class="table table-modern mb-0 sections-table">
                <thead><tr><th>Display</th><th>Students</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (empty($sections)): ?>
                <tr class="sections-empty-row"><td colspan="4" class="text-center py-4 text-muted">No sections found.</td></tr>
                <?php endif; ?>
                <?php foreach ($sections as $sec): ?>
                <tr>
                    <td data-label="Display"><span class="badge bg-primary" style="font-size:0.9rem;"><?= e($sec['year_level'] . $sec['section_name']) ?></span></td>
                    <td data-label="Students"><?= $sec['student_count'] ?></td>
                    <td data-label="Status"><span class="badge-status <?= $sec['is_active'] ? 'active' : 'inactive' ?>"><?= $sec['is_active'] ? 'Active' : 'Archived' ?></span></td>
                    <td class="sections-table-actions" data-label="Actions">
                        <form method="POST" class="d-inline" onsubmit="return confirmForm(this, '<?= $sec['is_active'] ? 'Archive' : 'Restore' ?> this section?', '<?= $sec['is_active'] ? 'Archive' : 'Restore' ?>')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="section_id" value="<?= $sec['id'] ?>">
                            <input type="hidden" name="current_tab" value="<?= e($activeTab) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-<?= $sec['is_active'] ? 'warning' : 'success' ?>"><i class="fas fa-<?= $sec['is_active'] ? 'archive' : 'undo' ?> me-1"></i><?= $sec['is_active'] ? 'Archive' : 'Restore' ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="createSectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header"><h5 class="modal-title">Create New BSIT Section</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="alert alert-info" style="font-size:0.85rem;">
                        <i class="fas fa-info-circle me-1"></i> All sections are under BSIT. Section display: Year + Letter (e.g., 1A, 2B, 3C)
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Year Level</label>
                        <select name="year_level" class="form-select" required>
                            <option value="">Select...</option>
                            <?php foreach (YEAR_LEVELS as $val => $label): ?>
                            <option value="<?= $val ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Section Letter</label>
                        <input type="text" name="section_name" class="form-control" placeholder="e.g. A, B, C, D" required maxlength="10">
                        <small class="text-muted">Enter a letter (e.g., A, B, C). For Year 1, "A" creates section "1A".</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-gradient">Create Section</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
