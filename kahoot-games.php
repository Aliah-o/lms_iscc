<?php
$pageTitle = 'Live Quiz & Word Scramble';
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'superadmin', 'student');
$pdo = getDB();
$user = currentUser();
$role = $user['role'];
$breadcrumbPills = [$role === 'student' ? 'My Learning' : 'Teaching', 'Live Quiz'];

try { $pdo->query("SELECT 1 FROM kahoot_games LIMIT 1"); } catch (Exception $e) {
    flash('error', 'Please run kahoot-install.php first.');
    redirect('/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role !== 'student') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect('/kahoot-games.php'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'start_session') {
        $gameId = intval($_POST['game_id'] ?? 0);
        $g = $pdo->prepare("SELECT * FROM kahoot_games WHERE id = ? AND created_by = ?");
        $g->execute([$gameId, $user['id']]);
        $game = $g->fetch();

        if (!$game) { flash('error', 'Game not found.'); redirect('/kahoot-games.php'); }

        $qCount = $pdo->prepare("SELECT COUNT(*) FROM kahoot_questions WHERE game_id = ?");
        $qCount->execute([$gameId]);
        if ($qCount->fetchColumn() == 0) { flash('error', 'Add questions before starting.'); redirect('/kahoot-create.php?id=' . $gameId); }

        do {
            $pin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $chk = $pdo->prepare("SELECT id FROM kahoot_games WHERE game_pin = ?");
            $chk->execute([$pin]);
        } while ($chk->fetch());

        $pdo->prepare("UPDATE kahoot_sessions SET status = 'finished', ended_at = NOW() WHERE game_id = ? AND status IN ('lobby','playing','reviewing')")
            ->execute([$gameId]);

        $pdo->prepare("UPDATE kahoot_games SET game_pin = ?, status = 'live' WHERE id = ?")
            ->execute([$pin, $gameId]);

        $pdo->prepare("INSERT INTO kahoot_sessions (game_id, host_id, status) VALUES (?, ?, 'lobby')")
            ->execute([$gameId, $user['id']]);
        $sessionId = $pdo->lastInsertId();

        auditLog('kahoot_session_started', "Game #$gameId started, PIN: $pin, Session #$sessionId");
        redirect('/kahoot-host.php?session_id=' . $sessionId);
    }

    if ($action === 'duplicate_game') {
        $gameId = intval($_POST['game_id'] ?? 0);
        $g = $pdo->prepare("SELECT * FROM kahoot_games WHERE id = ? AND created_by = ?");
        $g->execute([$gameId, $user['id']]);
        $game = $g->fetch();

        if ($game) {
            $pdo->prepare("INSERT INTO kahoot_games (title, description, class_id, created_by, game_mode, time_limit, status, shuffle_questions, shuffle_choices, show_leaderboard)
                VALUES (?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?)")
                ->execute([$game['title'] . ' (Copy)', $game['description'], $game['class_id'], $user['id'], $game['game_mode'], $game['time_limit'], $game['shuffle_questions'], $game['shuffle_choices'], $game['show_leaderboard']]);
            $newGameId = $pdo->lastInsertId();

            $questions = $pdo->prepare("SELECT * FROM kahoot_questions WHERE game_id = ? ORDER BY question_order");
            $questions->execute([$gameId]);
            foreach ($questions->fetchAll() as $q) {
                $pdo->prepare("INSERT INTO kahoot_questions (game_id, question_text, question_type, correct_answer, question_image, question_order, points, time_limit)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$newGameId, $q['question_text'], $q['question_type'] ?? 'multiple_choice', $q['correct_answer'] ?? null, $q['question_image'], $q['question_order'], $q['points'], $q['time_limit']]);
                $newQId = $pdo->lastInsertId();

                $choices = $pdo->prepare("SELECT * FROM kahoot_choices WHERE question_id = ?");
                $choices->execute([$q['id']]);
                foreach ($choices->fetchAll() as $c) {
                    $pdo->prepare("INSERT INTO kahoot_choices (question_id, choice_label, choice_text, is_correct) VALUES (?, ?, ?, ?)")
                        ->execute([$newQId, $c['choice_label'], $c['choice_text'], $c['is_correct']]);
                }
            }
            flash('success', 'Game duplicated successfully!');
        }
        redirect('/kahoot-games.php');
    }

    if ($action === 'delete_game') {
        $gameId = intval($_POST['game_id'] ?? 0);
        $pdo->prepare("DELETE FROM kahoot_games WHERE id = ? AND created_by = ?")->execute([$gameId, $user['id']]);
        flash('success', 'Game deleted.');
        redirect('/kahoot-games.php');
    }
}

if ($role === 'student') {
    $games = $pdo->prepare("SELECT g.*, u.first_name as instructor_fn, u.last_name as instructor_ln,
        (SELECT COUNT(*) FROM kahoot_questions WHERE game_id = g.id) as question_count,
        (SELECT COUNT(*) FROM kahoot_sessions WHERE game_id = g.id) as session_count
        FROM kahoot_games g
        JOIN users u ON g.created_by = u.id
        WHERE g.status IN ('ready','live','completed')
        AND (g.class_id IN (SELECT class_id FROM class_enrollments WHERE student_id = ?) OR g.class_id IS NULL)
        ORDER BY g.created_at DESC");
    $games->execute([$user['id']]);
} else {
    $games = $pdo->prepare("SELECT g.*,
        (SELECT COUNT(*) FROM kahoot_questions WHERE game_id = g.id) as question_count,
        (SELECT COUNT(*) FROM kahoot_sessions WHERE game_id = g.id) as session_count,
        (SELECT COUNT(*) FROM kahoot_sessions WHERE game_id = g.id AND status IN ('lobby','playing')) as active_sessions
        FROM kahoot_games g
        WHERE g.created_by = ?
        ORDER BY g.created_at DESC");
    $games->execute([$user['id']]);
}
$games = $games->fetchAll();

$classes = [];
if ($role !== 'student') {
    if ($role === 'superadmin') {
        $classes = $pdo->query("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id WHERE tc.is_active = 1 ORDER BY tc.subject_name")->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id WHERE tc.instructor_id = ? AND tc.is_active = 1 ORDER BY tc.subject_name");
        $stmt->execute([$user['id']]);
        $classes = $stmt->fetchAll();
    }
}

$totalGames = count($games);
$liveGames = count(array_filter($games, fn($g) => ($g['active_sessions'] ?? 0) > 0));
$totalPlayers = 0;
try {
    $ps = $pdo->prepare("SELECT COUNT(DISTINCT kp.user_id) FROM kahoot_participants kp
        JOIN kahoot_sessions ks ON kp.session_id = ks.id
        JOIN kahoot_games g ON ks.game_id = g.id WHERE g.created_by = ?");
    $ps->execute([$user['id']]);
    $totalPlayers = $ps->fetchColumn();
} catch (Exception $e) {}

require_once __DIR__ . '/views/layouts/header.php';
?>

<div class="kahoot-hero">
    <div class="kahoot-hero-content">
        <div>
            <h2 class="fw-bold mb-1" style="font-size:1.6rem;">
                <i class="fas fa-gamepad me-2"></i>
                <?= $role === 'student' ? 'Join a Live Quiz' : 'Live Quiz & Word Scramble' ?>
            </h2>
            <p class="mb-0 opacity-75">
                <?= $role === 'student' ? 'Enter a Game PIN to join a live quiz or play practice games' : 'Create live quiz and word scramble games for your students' ?>
                <button class="btn btn-sm btn-outline-light ms-2" data-bs-toggle="modal" data-bs-target="#howItWorksModal"><i class="fas fa-question-circle me-1"></i>How it Works</button>
            </p>
        </div>
        <?php if ($role !== 'student'): ?>
        <div class="d-flex gap-3 align-items-center">
            <div class="kahoot-stat-box">
                <div class="stat-value"><?= $totalGames ?></div>
                <div class="stat-label">Games</div>
            </div>
            <div class="kahoot-stat-box">
                <div class="stat-value text-success"><?= $liveGames ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="kahoot-stat-box">
                <div class="stat-value text-info"><?= $totalPlayers ?></div>
                <div class="stat-label">Players</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($role === 'student'): ?>
<div class="row justify-content-center mb-4">
    <div class="col-md-6">
        <div class="card kahoot-join-card">
            <div class="card-body text-center p-4">
                <div class="kahoot-pin-icon mb-3">
                    <i class="fas fa-key"></i>
                </div>
                <h4 class="fw-bold mb-2">Enter Game PIN</h4>
                <p class="text-muted mb-3">Ask your instructor for the 6-digit PIN</p>
                <form id="joinPinForm" class="d-flex gap-2 justify-content-center">
                    <input type="text" class="form-control kahoot-pin-input" id="gamePinInput" maxlength="6" placeholder="000000" pattern="[0-9]{6}" autocomplete="off" style="max-width:200px;text-align:center;font-size:1.5rem;font-weight:700;letter-spacing:8px;">
                    <button type="submit" class="btn btn-kahoot-primary btn-lg">
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </form>
                <div id="pinFeedback" class="mt-2" style="font-size:0.85rem;"></div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($games)): ?>
<h5 class="fw-bold mb-3"><i class="fas fa-list me-2"></i>Available Practice Games</h5>
<div class="row g-3">
    <?php foreach ($games as $game): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card kahoot-game-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="badge <?= $game['game_mode'] === 'live' ? 'bg-danger' : 'bg-info' ?>"><?= ucfirst($game['game_mode']) ?></span>
                    <span class="text-muted" style="font-size:0.75rem;"><?= $game['question_count'] ?> Q</span>
                </div>
                <h6 class="fw-bold mb-1"><?= e($game['title']) ?></h6>
                <p class="text-muted mb-2" style="font-size:0.8rem;">by <?= e($game['instructor_fn'] . ' ' . $game['instructor_ln']) ?></p>
                <?php if ($game['game_mode'] === 'practice' && $game['game_pin']): ?>
                <a href="<?= BASE_URL ?>/kahoot-play.php?pin=<?= e($game['game_pin']) ?>" class="btn btn-sm btn-kahoot-primary w-100">
                    <i class="fas fa-play me-1"></i>Play Now
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
document.getElementById('joinPinForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const pin = document.getElementById('gamePinInput').value.trim();
    const feedback = document.getElementById('pinFeedback');

    if (pin.length !== 6) {
        feedback.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Enter a 6-digit PIN</span>';
        return;
    }

    feedback.innerHTML = '<span class="text-muted"><i class="fas fa-spinner fa-spin me-1"></i>Checking...</span>';

    fetch(BASE_URL + '/kahoot-api.php?action=check_pin&pin=' + pin)
        .then(r => r.json())
        .then(data => {
            if (data.valid) {
                feedback.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Found: ' + data.title + '</span>';
                setTimeout(() => {
                    window.location.href = BASE_URL + '/kahoot-play.php?pin=' + pin;
                }, 500);
            } else {
                feedback.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>' + (data.error || 'Game not found') + '</span>';
            }
        })
        .catch(() => {
            feedback.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Connection error</span>';
        });
});
</script>

<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="fw-bold mb-0"><i class="fas fa-list me-2"></i>My Games</h5>
    <a href="<?= BASE_URL ?>/kahoot-create.php" class="btn btn-kahoot-primary">
        <i class="fas fa-plus me-1"></i>Create New Game
    </a>
</div>

<?php if (empty($games)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="fas fa-gamepad fa-3x text-muted mb-3 d-block"></i>
        <h5 class="fw-bold text-muted">No Games Yet</h5>
        <p class="text-muted">Create your first interactive quiz game!</p>
        <a href="<?= BASE_URL ?>/kahoot-create.php" class="btn btn-kahoot-primary"><i class="fas fa-plus me-1"></i>Create Game</a>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($games as $game):
        $statusColors = ['draft' => 'secondary', 'ready' => 'primary', 'live' => 'danger', 'completed' => 'success', 'archived' => 'dark'];
        $statusIcons = ['draft' => 'fa-pencil-alt', 'ready' => 'fa-check', 'live' => 'fa-broadcast-tower', 'completed' => 'fa-flag-checkered', 'archived' => 'fa-archive'];
    ?>
    <div class="col-md-6 col-lg-4">
        <div class="card kahoot-game-card h-100 <?= ($game['active_sessions'] ?? 0) > 0 ? 'kahoot-card-live' : '' ?>">
            <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <span class="badge bg-<?= $statusColors[$game['status']] ?? 'secondary' ?> me-1">
                            <i class="fas <?= $statusIcons[$game['status']] ?? 'fa-circle' ?> me-1"></i><?= ucfirst($game['status']) ?>
                        </span>
                        <span class="badge bg-<?= $game['game_mode'] === 'live' ? 'warning text-dark' : 'info' ?>">
                            <?= $game['game_mode'] === 'live' ? 'Live' : 'Practice' ?>
                        </span>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>/kahoot-create.php?id=<?= $game['id'] ?>"><i class="fas fa-edit me-2"></i>Edit</a></li>
                            <li>
                                <form method="POST" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="duplicate_game">
                                    <input type="hidden" name="game_id" value="<?= $game['id'] ?>">
                                    <button type="submit" class="dropdown-item"><i class="fas fa-copy me-2"></i>Duplicate</button>
                                </form>
                            </li>
                            <?php if ($game['session_count'] > 0): ?>
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>/kahoot-results.php?game_id=<?= $game['id'] ?>"><i class="fas fa-chart-bar me-2"></i>Results</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" onsubmit="return confirmForm(this, 'Delete this game? All questions and results will be lost.', 'Delete Game')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_game">
                                    <input type="hidden" name="game_id" value="<?= $game['id'] ?>">
                                    <button type="submit" class="dropdown-item text-danger"><i class="fas fa-trash me-2"></i>Delete</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </div>

                <h6 class="fw-bold mb-1"><?= e($game['title']) ?></h6>
                <?php if ($game['description']): ?>
                <p class="text-muted mb-2" style="font-size:0.8rem;"><?= e(substr($game['description'], 0, 80)) ?></p>
                <?php endif; ?>

                <div class="d-flex gap-3 text-muted mb-3 mt-auto" style="font-size:0.8rem;">
                    <span><i class="fas fa-question-circle me-1"></i><?= $game['question_count'] ?> questions</span>
                    <span><i class="fas fa-clock me-1"></i><?= $game['time_limit'] ?>s</span>
                    <span><i class="fas fa-history me-1"></i><?= $game['session_count'] ?> sessions</span>
                </div>

                <?php if ($game['game_pin'] && ($game['active_sessions'] ?? 0) > 0): ?>
                <div class="kahoot-pin-display mb-2">
                    <span class="pin-label">PIN:</span>
                    <span class="pin-value"><?= e($game['game_pin']) ?></span>
                </div>
                <?php endif; ?>

                <div class="d-flex gap-2">
                    <?php if (($game['active_sessions'] ?? 0) > 0): ?>
                    <?php
                        $activeSession = $pdo->prepare("SELECT id FROM kahoot_sessions WHERE game_id = ? AND status IN ('lobby','playing','reviewing') ORDER BY id DESC LIMIT 1");
                        $activeSession->execute([$game['id']]);
                        $activeSess = $activeSession->fetch();
                    ?>
                    <a href="<?= BASE_URL ?>/kahoot-host.php?session_id=<?= $activeSess['id'] ?>" class="btn btn-sm btn-danger flex-fill">
                        <i class="fas fa-broadcast-tower me-1"></i>Live Dashboard
                    </a>
                    <?php elseif ($game['question_count'] > 0): ?>
                    <form method="POST" class="flex-fill">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="start_session">
                        <input type="hidden" name="game_id" value="<?= $game['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-kahoot-primary w-100">
                            <i class="fas fa-play me-1"></i>Start Game
                        </button>
                    </form>
                    <?php else: ?>
                    <a href="<?= BASE_URL ?>/kahoot-create.php?id=<?= $game['id'] ?>" class="btn btn-sm btn-outline-primary flex-fill">
                        <i class="fas fa-plus me-1"></i>Add Questions
                    </a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/kahoot-create.php?id=<?= $game['id'] ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-edit"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<div class="modal fade" id="howItWorksModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;">
                <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i>How Live Quiz Works</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <?php if ($role === 'student'): ?>
                <div class="text-center mb-4">
                    <div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#6d28d9);display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px;">
                        <i class="fas fa-user-graduate fa-lg text-white"></i>
                    </div>
                    <h5 class="fw-bold">Student Guide</h5>
                    <p class="text-muted">Follow these steps to join and play a live quiz game</p>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="p-3 rounded-3" style="background:#f8f5ff;border-left:4px solid #7c3aed;">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge rounded-pill" style="background:#7c3aed;width:28px;height:28px;display:flex;align-items:center;justify-content:center;">1</span>
                                <h6 class="fw-bold mb-0">Get the Game PIN</h6>
                            </div>
                            <p class="text-muted mb-0" style="font-size:0.85rem;">Your instructor will display a 6-digit PIN on screen when the game starts. Write it down or remember it.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 rounded-3" style="background:#f0fdf4;border-left:4px solid #22c55e;">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge rounded-pill bg-success" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;">2</span>
                                <h6 class="fw-bold mb-0">Enter the PIN</h6>
                            </div>
                            <p class="text-muted mb-0" style="font-size:0.85rem;">Enter the 6-digit PIN in the "Enter Game PIN" box above. The system will verify and connect you to the game.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 rounded-3" style="background:#fef3c7;border-left:4px solid #f59e0b;">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge rounded-pill bg-warning text-dark" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;">3</span>
                                <h6 class="fw-bold mb-0">Wait in Lobby</h6>
                            </div>
                            <p class="text-muted mb-0" style="font-size:0.85rem;">Once joined, you'll wait in the lobby until the instructor starts the game. Your nickname will be shown on screen.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 rounded-3" style="background:#fef2f2;border-left:4px solid #ef4444;">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge rounded-pill bg-danger" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;">4</span>
                                <h6 class="fw-bold mb-0">Answer Questions!</h6>
                            </div>
                            <p class="text-muted mb-0" style="font-size:0.85rem;">When each question appears, select the correct answer as fast as you can. Faster answers earn more points!</p>
                        </div>
                    </div>
                </div>

                <div class="mt-4 p-3 rounded-3" style="background:var(--gray-50);">
                    <h6 class="fw-bold mb-2"><i class="fas fa-trophy text-warning me-2"></i>Scoring Tips</h6>
                    <ul class="mb-0" style="font-size:0.85rem;">
                        <li><strong>Speed matters</strong> — The faster you answer correctly, the more points you earn (up to 1000 per question)</li>
                        <li><strong>Streaks</strong> — Answer multiple questions correctly in a row to build a streak bonus</li>
                        <li><strong>Leaderboard</strong> — Your ranking is shown after each question. Try to reach the top!</li>
                        <li><strong>No penalty</strong> — Wrong answers don't cost points, so always try to answer</li>
                    </ul>
                </div>

                <?php else: ?>
                <div class="text-center mb-4">
                    <div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#6d28d9);display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px;">
                        <i class="fas fa-chalkboard-instructor fa-lg text-white"></i>
                    </div>
                    <h5 class="fw-bold">Instructor / Admin Guide</h5>
                    <p class="text-muted">How to create, host, and manage live quiz games</p>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="p-3 rounded-3" style="background:#f8f5ff;border-left:4px solid #7c3aed;">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge rounded-pill" style="background:#7c3aed;width:28px;height:28px;display:flex;align-items:center;justify-content:center;">1</span>
                                <h6 class="fw-bold mb-0">Create a Game</h6>
                            </div>
                            <p class="text-muted mb-0" style="font-size:0.85rem;">Click "Create New Game" to set up a quiz. Add a title, description, choose a class, and set the time limit per question.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 rounded-3" style="background:#f0fdf4;border-left:4px solid #22c55e;">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge rounded-pill bg-success" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;">2</span>
                                <h6 class="fw-bold mb-0">Add Questions</h6>
                            </div>
                            <p class="text-muted mb-0" style="font-size:0.85rem;">Add multiple-choice questions (A, B, C, D) with images. Mark the correct answer and set point values for each question.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 rounded-3" style="background:#fef3c7;border-left:4px solid #f59e0b;">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge rounded-pill bg-warning text-dark" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;">3</span>
                                <h6 class="fw-bold mb-0">Start the Game</h6>
                            </div>
                            <p class="text-muted mb-0" style="font-size:0.85rem;">Click "Start Game" to generate a unique 6-digit PIN. Share the PIN with students and wait for them to join in the lobby.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 rounded-3" style="background:#fef2f2;border-left:4px solid #ef4444;">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge rounded-pill bg-danger" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;">4</span>
                                <h6 class="fw-bold mb-0">Host & Review</h6>
                            </div>
                            <p class="text-muted mb-0" style="font-size:0.85rem;">Control the game flow from the host dashboard. Advance questions, view live results, and see the final leaderboard.</p>
                        </div>
                    </div>
                </div>

                <div class="mt-4 p-3 rounded-3" style="background:var(--gray-50);">
                    <h6 class="fw-bold mb-2"><i class="fas fa-cog text-primary me-2"></i>Game Options</h6>
                    <ul class="mb-0" style="font-size:0.85rem;">
                        <li><strong>Game Mode</strong> — Choose "Live" for real-time play or "Practice" for self-paced student practice</li>
                        <li><strong>Shuffle</strong> — Enable question and/or choice shuffling to prevent copying</li>
                        <li><strong>Time Limit</strong> — Set a default time per question (can be overridden per question)</li>
                        <li><strong>Duplicate</strong> — Easily copy existing games to create new versions</li>
                        <li><strong>Results</strong> — View detailed analytics and per-student performance after each session</li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Got it!</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
