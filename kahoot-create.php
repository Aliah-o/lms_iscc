<?php
$pageTitle = 'Create Live Quiz Game';
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'superadmin');
$pdo = getDB();
$user = currentUser();
$role = $user['role'];
$breadcrumbPills = ['Teaching', 'Live Quiz', 'Create'];

try { $pdo->query("SELECT 1 FROM kahoot_games LIMIT 1"); } catch (Exception $e) {
    flash('error', 'Run kahoot-install.php first.'); redirect('/dashboard.php');
}

$gameId = intval($_GET['id'] ?? 0);
$game = null;
$questions = [];
$requestedQuestionType = in_array($_GET['question_type'] ?? '', ['multiple_choice', 'word_scramble'], true)
    ? $_GET['question_type']
    : 'multiple_choice';

if ($gameId) {
    $stmt = $pdo->prepare("SELECT * FROM kahoot_games WHERE id = ? AND created_by = ?");
    $stmt->execute([$gameId, $user['id']]);
    $game = $stmt->fetch();
    if (!$game) { flash('error', 'Game not found.'); redirect('/kahoot-games.php'); }
    $pageTitle = 'Edit: ' . $game['title'];

    $qStmt = $pdo->prepare("SELECT * FROM kahoot_questions WHERE game_id = ? ORDER BY question_order");
    $qStmt->execute([$gameId]);
    $questions = $qStmt->fetchAll();

    foreach ($questions as &$q) {
        $cStmt = $pdo->prepare("SELECT * FROM kahoot_choices WHERE question_id = ? ORDER BY choice_label");
        $cStmt->execute([$q['id']]);
        $q['choices'] = $cStmt->fetchAll();
    }
    unset($q);
}

if ($role === 'superadmin') {
    $classes = $pdo->query("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id WHERE tc.is_active = 1 ORDER BY tc.subject_name")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id WHERE tc.instructor_id = ? AND tc.is_active = 1 ORDER BY tc.subject_name");
    $stmt->execute([$user['id']]);
    $classes = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect('/kahoot-games.php'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'save_game') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $classId = intval($_POST['class_id'] ?? 0) ?: null;
        $gameMode = in_array($_POST['game_mode'] ?? '', ['live', 'practice']) ? $_POST['game_mode'] : 'live';
        $starterQuestionType = in_array($_POST['starter_question_type'] ?? '', ['multiple_choice', 'word_scramble'], true)
            ? $_POST['starter_question_type']
            : 'multiple_choice';
        $timeLimit = intval($_POST['time_limit'] ?? 20);
        if (!in_array($timeLimit, [5, 10, 15, 20, 30, 60, 90, 120])) $timeLimit = 20;
        $shuffleQ = isset($_POST['shuffle_questions']) ? 1 : 0;
        $shuffleC = isset($_POST['shuffle_choices']) ? 1 : 0;
        $showLB = isset($_POST['show_leaderboard']) ? 1 : 0;

        if (empty($title)) { flash('error', 'Game title is required.'); redirect('/kahoot-create.php' . ($gameId ? "?id=$gameId" : '')); }

        if ($gameId) {
            $pdo->prepare("UPDATE kahoot_games SET title=?, description=?, class_id=?, game_mode=?, time_limit=?, shuffle_questions=?, shuffle_choices=?, show_leaderboard=? WHERE id=? AND created_by=?")
                ->execute([$title, $description, $classId, $gameMode, $timeLimit, $shuffleQ, $shuffleC, $showLB, $gameId, $user['id']]);
            flash('success', 'Game settings updated!');
        } else {
            $pdo->prepare("INSERT INTO kahoot_games (title, description, class_id, created_by, game_mode, time_limit, status, shuffle_questions, shuffle_choices, show_leaderboard) VALUES (?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?)")
                ->execute([$title, $description, $classId, $user['id'], $gameMode, $timeLimit, $shuffleQ, $shuffleC, $showLB]);
            $gameId = $pdo->lastInsertId();
            auditLog('kahoot_game_created', "Game #$gameId: $title");
            flash('success', 'Game created! Now add questions.');
        }
        redirect('/kahoot-create.php?id=' . $gameId . '&question_type=' . $starterQuestionType);
    }

    if ($action === 'save_question' && $gameId) {
        $questionId = intval($_POST['question_id'] ?? 0);
        $questionText = trim($_POST['question_text'] ?? '');
        $questionType = in_array($_POST['question_type'] ?? '', ['multiple_choice', 'word_scramble'], true) ? $_POST['question_type'] : 'multiple_choice';
        $points = intval($_POST['points'] ?? 1000);
        $qTimeLimit = intval($_POST['q_time_limit'] ?? 0) ?: null;
        $correctAnswer = $_POST['correct_answer'] ?? 'A';
        $correctAnswerText = preg_replace('/\s+/', ' ', strtoupper(trim($_POST['correct_answer_text'] ?? '')));
        $choiceTexts = $_POST['choices'] ?? [];

        if (empty($questionText)) { flash('error', 'Question text is required.'); redirect("/kahoot-create.php?id=$gameId"); }
        if ($questionType === 'word_scramble') {
            if ($correctAnswerText === '') {
                flash('error', 'A correct answer is required for word scramble questions.');
                redirect("/kahoot-create.php?id=$gameId");
            }
        } else {
            $filledChoices = array_filter(array_map('trim', $choiceTexts), static fn($value) => $value !== '');
            if (count($filledChoices) < 2) {
                flash('error', 'At least 2 choices required.');
                redirect("/kahoot-create.php?id=$gameId");
            }
            $correctAnswerText = null;
        }

        $imagePath = null;
        if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (in_array($_FILES['question_image']['type'], $allowedTypes) && $_FILES['question_image']['size'] <= 5 * 1024 * 1024) {
                $ext = pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION);
                $fileName = 'kq_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $uploadDir = __DIR__ . '/uploads/kahoot/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                if (move_uploaded_file($_FILES['question_image']['tmp_name'], $uploadDir . $fileName)) {
                    $imagePath = 'uploads/kahoot/' . $fileName;
                }
            }
        }

        if ($questionId) {
            if ($imagePath) {
                $pdo->prepare("UPDATE kahoot_questions SET question_text=?, question_type=?, correct_answer=?, question_image=?, points=?, time_limit=? WHERE id=? AND game_id=?")
                    ->execute([$questionText, $questionType, $correctAnswerText, $imagePath, $points, $qTimeLimit, $questionId, $gameId]);
            } else {
                if (isset($_POST['remove_image'])) {
                    $pdo->prepare("UPDATE kahoot_questions SET question_text=?, question_type=?, correct_answer=?, question_image=NULL, points=?, time_limit=? WHERE id=? AND game_id=?")
                        ->execute([$questionText, $questionType, $correctAnswerText, $points, $qTimeLimit, $questionId, $gameId]);
                } else {
                    $pdo->prepare("UPDATE kahoot_questions SET question_text=?, question_type=?, correct_answer=?, points=?, time_limit=? WHERE id=? AND game_id=?")
                        ->execute([$questionText, $questionType, $correctAnswerText, $points, $qTimeLimit, $questionId, $gameId]);
                }
            }
            $pdo->prepare("DELETE FROM kahoot_choices WHERE question_id = ?")->execute([$questionId]);
            $targetQId = $questionId;
        } else {
            $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(question_order), 0) + 1 FROM kahoot_questions WHERE game_id = ?");
            $maxOrder->execute([$gameId]);
            $nextOrder = $maxOrder->fetchColumn();

            $pdo->prepare("INSERT INTO kahoot_questions (game_id, question_text, question_type, correct_answer, question_image, question_order, points, time_limit) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$gameId, $questionText, $questionType, $correctAnswerText, $imagePath, $nextOrder, $points, $qTimeLimit]);
            $targetQId = $pdo->lastInsertId();
        }

        if ($questionType === 'multiple_choice') {
            foreach (['A', 'B', 'C', 'D'] as $label) {
                $choiceText = trim($choiceTexts[$label] ?? '');
                if (empty($choiceText)) continue;
                $isCorrect = ($label === $correctAnswer) ? 1 : 0;
                $pdo->prepare("INSERT INTO kahoot_choices (question_id, choice_label, choice_text, is_correct) VALUES (?, ?, ?, ?)")
                    ->execute([$targetQId, $label, $choiceText, $isCorrect]);
            }
        }

        $pdo->prepare("UPDATE kahoot_games SET status = 'ready' WHERE id = ? AND status = 'draft'")->execute([$gameId]);

        flash('success', $questionId ? 'Question updated!' : 'Question added!');
        redirect("/kahoot-create.php?id=$gameId");
    }

    if ($action === 'delete_question' && $gameId) {
        $questionId = intval($_POST['question_id'] ?? 0);
        $pdo->prepare("DELETE FROM kahoot_questions WHERE id = ? AND game_id = ?")->execute([$questionId, $gameId]);
        $remaining = $pdo->prepare("SELECT id FROM kahoot_questions WHERE game_id = ? ORDER BY question_order");
        $remaining->execute([$gameId]);
        $order = 1;
        foreach ($remaining->fetchAll() as $r) {
            $pdo->prepare("UPDATE kahoot_questions SET question_order = ? WHERE id = ?")->execute([$order++, $r['id']]);
        }
        $countQ = $pdo->prepare("SELECT COUNT(*) FROM kahoot_questions WHERE game_id = ?");
        $countQ->execute([$gameId]);
        if ($countQ->fetchColumn() == 0) {
            $pdo->prepare("UPDATE kahoot_games SET status = 'draft' WHERE id = ?")->execute([$gameId]);
        }
        flash('success', 'Question deleted.');
        redirect("/kahoot-create.php?id=$gameId");
    }

    if ($action === 'reorder_questions' && $gameId) {
        $order = $_POST['order'] ?? [];
        foreach ($order as $pos => $qId) {
            $pdo->prepare("UPDATE kahoot_questions SET question_order = ? WHERE id = ? AND game_id = ?")
                ->execute([$pos + 1, intval($qId), $gameId]);
        }
        jsonResponse(['success' => true]);
    }
}

$editQuestion = null;
if (isset($_GET['edit_q'])) {
    $eqId = intval($_GET['edit_q']);
    foreach ($questions as $q) {
        if ($q['id'] == $eqId) { $editQuestion = $q; break; }
    }
}
$editQuestionType = $editQuestion['question_type'] ?? $requestedQuestionType;

require_once __DIR__ . '/views/layouts/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= BASE_URL ?>/kahoot-games.php" class="btn btn-light"><i class="fas fa-arrow-left"></i></a>
    <div>
        <h4 class="fw-bold mb-0"><?= $game ? 'Edit Game' : 'Create New Game' ?></h4>
        <p class="text-muted mb-0" style="font-size:0.85rem;">
            <?= $game ? e($game['title']) : 'Set up your live quiz or word scramble game and add questions' ?>
        </p>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card kahoot-settings-card">
            <div class="card-header"><span><i class="fas fa-cog me-2"></i>Game Settings</span></div>
            <div class="card-body">
                <form method="POST" id="gameSettingsForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_game">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Game Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" value="<?= e($game['title'] ?? '') ?>" placeholder="e.g. Data Structures Quiz" required maxlength="255">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Optional description"><?= e($game['description'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Class (Optional)</label>
                        <select name="class_id" class="form-select">
                            <option value="">All classes (public)</option>
                            <?php foreach ($classes as $cls): ?>
                            <option value="<?= $cls['id'] ?>" <?= ($game['class_id'] ?? '') == $cls['id'] ? 'selected' : '' ?>>
                                <?= e($cls['course_code'] . ' - ' . $cls['subject_name'] . ' (' . $cls['section_name'] . ')') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Game Mode</label>
                            <select name="game_mode" class="form-select">
                                <option value="live" <?= ($game['game_mode'] ?? 'live') === 'live' ? 'selected' : '' ?>>
                                    🔴 Live Mode
                                </option>
                                <option value="practice" <?= ($game['game_mode'] ?? '') === 'practice' ? 'selected' : '' ?>>
                                    🟢 Practice Mode
                                </option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Time per Question</label>
                            <select name="time_limit" class="form-select">
                                <?php foreach ([5,10,15,20,30,60,90,120] as $t): ?>
                                <option value="<?= $t ?>" <?= ($game['time_limit'] ?? 20) == $t ? 'selected' : '' ?>><?= $t ?>s</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Start With</label>
                        <select name="starter_question_type" class="form-select">
                            <option value="multiple_choice" <?= $requestedQuestionType === 'multiple_choice' ? 'selected' : '' ?>>Multiple Choice</option>
                            <option value="word_scramble" <?= $requestedQuestionType === 'word_scramble' ? 'selected' : '' ?>>Word Scramble</option>
                        </select>
                        <div class="form-text">After saving the game, the question builder will open with this type selected.</div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check form-switch mb-2">
                            <input type="checkbox" class="form-check-input" name="shuffle_questions" id="shuffleQ" <?= ($game['shuffle_questions'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="shuffleQ">Shuffle question order</label>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input type="checkbox" class="form-check-input" name="shuffle_choices" id="shuffleC" <?= ($game['shuffle_choices'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="shuffleC">Shuffle choice order</label>
                        </div>
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input" name="show_leaderboard" id="showLB" <?= ($game['show_leaderboard'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="showLB">Show live leaderboard</label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-kahoot-primary w-100">
                        <i class="fas fa-save me-1"></i><?= $game ? 'Update Settings' : 'Create Game' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <?php if ($gameId): ?>
        <div class="card kahoot-question-form-card mb-4">
            <div class="card-header">
                <span>
                    <i class="fas fa-<?= $editQuestion ? 'edit' : 'plus' ?> me-2"></i>
                    <?= $editQuestion ? 'Edit Question #' . array_search($editQuestion, $questions) + 1 : 'Add Question' ?>
                </span>
                <?php if ($editQuestion): ?>
                <a href="<?= BASE_URL ?>/kahoot-create.php?id=<?= $gameId ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_question">
                    <?php if ($editQuestion): ?>
                    <input type="hidden" name="question_id" value="<?= $editQuestion['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Question Text <span class="text-danger">*</span></label>
                        <textarea name="question_text" class="form-control" rows="3" required placeholder="Type your question here..."><?= e($editQuestion['question_text'] ?? '') ?></textarea>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label class="form-label fw-bold">Image (Optional)</label>
                            <input type="file" name="question_image" class="form-control" accept="image/*">
                            <?php if ($editQuestion && $editQuestion['question_image']): ?>
                            <div class="mt-2 d-flex align-items-center gap-2">
                                <img src="<?= BASE_URL ?>/<?= e($editQuestion['question_image']) ?>" style="height:40px;border-radius:4px;">
                                <label class="form-check-label" style="font-size:0.8rem;">
                                    <input type="checkbox" name="remove_image" class="form-check-input me-1">Remove
                                </label>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Question Type</label>
                            <select name="question_type" class="form-select" id="questionTypeSelect">
                                <option value="multiple_choice" <?= $editQuestionType === 'multiple_choice' ? 'selected' : '' ?>>Multiple Choice</option>
                                <option value="word_scramble" <?= $editQuestionType === 'word_scramble' ? 'selected' : '' ?>>Word Scramble</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Points</label>
                            <input type="number" name="points" class="form-control" value="<?= $editQuestion['points'] ?? 1000 ?>" min="100" max="5000" step="100">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Time (s)</label>
                            <input type="number" name="q_time_limit" class="form-control" value="<?= $editQuestion['time_limit'] ?? '' ?>" min="5" max="120" placeholder="Default">
                        </div>
                    </div>

                    <div id="mcQuestionFields">
                    <label class="form-label fw-bold">Answer Choices</label>
                    <div class="row g-2 mb-3">
                        <?php
                        $choiceColors = ['A' => '#e21b3c', 'B' => '#1368ce', 'C' => '#d89e00', 'D' => '#26890c'];
                        $choiceIcons = ['A' => 'fa-diamond', 'B' => 'fa-circle', 'C' => 'fa-square', 'D' => 'fa-star'];
                        $existingChoices = [];
                        $existingCorrect = 'A';
                        if ($editQuestion && !empty($editQuestion['choices'])) {
                            foreach ($editQuestion['choices'] as $c) {
                                $existingChoices[$c['choice_label']] = $c['choice_text'];
                                if ($c['is_correct']) $existingCorrect = $c['choice_label'];
                            }
                        }
                        foreach (['A', 'B', 'C', 'D'] as $label):
                        ?>
                        <div class="col-md-6">
                            <div class="kahoot-choice-input" style="border-left: 4px solid <?= $choiceColors[$label] ?>;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="form-check">
                                        <input type="radio" name="correct_answer" value="<?= $label ?>" class="form-check-input"
                                            <?= ($existingCorrect === $label) ? 'checked' : '' ?> id="correct_<?= $label ?>">
                                    </div>
                                    <i class="fas <?= $choiceIcons[$label] ?>" style="color:<?= $choiceColors[$label] ?>;font-size:0.9rem;"></i>
                                    <input type="text" name="choices[<?= $label ?>]" class="form-control form-control-sm"
                                        placeholder="Choice <?= $label ?>" value="<?= e($existingChoices[$label] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-muted mb-3" style="font-size:0.78rem;"><i class="fas fa-info-circle me-1"></i>Select the radio button next to the correct answer. At least choices A and B are required.</p>
                    </div>

                    <div id="wsQuestionFields" style="display:none;">
                        <label class="form-label fw-bold">Correct Word / Phrase</label>
                        <input type="text" name="correct_answer_text" class="form-control mb-2" value="<?= e($editQuestion['correct_answer'] ?? '') ?>" placeholder="e.g. LEARNING or DATA STRUCTURES">
                        <p class="text-muted mb-3" style="font-size:0.78rem;"><i class="fas fa-info-circle me-1"></i>The player will see scrambled letters and must rebuild this answer during the live game.</p>
                    </div>

                    <button type="submit" class="btn btn-kahoot-primary">
                        <i class="fas fa-<?= $editQuestion ? 'save' : 'plus' ?> me-1"></i>
                        <?= $editQuestion ? 'Update Question' : 'Add Question' ?>
                    </button>
                </form>
            </div>
        </div>

        <?php if (!empty($questions)): ?>
        <div class="card">
            <div class="card-header">
                <span><i class="fas fa-list-ol me-2"></i>Questions (<?= count($questions) ?>)</span>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush" id="questionList">
                    <?php foreach ($questions as $idx => $q): ?>
                    <div class="list-group-item kahoot-question-item" data-question-id="<?= $q['id'] ?>">
                        <div class="d-flex align-items-start gap-3">
                            <div class="kahoot-q-number"><?= $idx + 1 ?></div>
                            <div class="flex-grow-1">
                                <div class="fw-bold mb-1" style="font-size:0.9rem;"><?= e($q['question_text']) ?></div>
                                <div class="d-flex flex-wrap gap-1 mb-1">
                                    <span class="badge <?= ($q['question_type'] ?? 'multiple_choice') === 'word_scramble' ? 'bg-warning text-dark' : 'bg-primary' ?>">
                                        <?= ($q['question_type'] ?? 'multiple_choice') === 'word_scramble' ? 'Word Scramble' : 'Multiple Choice' ?>
                                    </span>
                                </div>
                                <?php if (($q['question_type'] ?? 'multiple_choice') === 'word_scramble'): ?>
                                <div class="text-muted" style="font-size:0.82rem;">
                                    Answer: <strong><?= e($q['correct_answer']) ?></strong>
                                </div>
                                <?php else: ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($q['choices'] as $c): ?>
                                    <span class="badge <?= $c['is_correct'] ? 'bg-success' : 'bg-light text-dark border' ?>" style="font-size:0.72rem;">
                                        <?= $c['choice_label'] ?>. <?= e(substr($c['choice_text'], 0, 30)) ?>
                                        <?= $c['is_correct'] ? ' ✓' : '' ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <div class="d-flex gap-2 mt-1 text-muted" style="font-size:0.75rem;">
                                    <span><i class="fas fa-star me-1"></i><?= $q['points'] ?> pts</span>
                                    <span><i class="fas fa-clock me-1"></i><?= $q['time_limit'] ?? $game['time_limit'] ?>s</span>
                                    <?php if ($q['question_image']): ?><span><i class="fas fa-image me-1"></i>Image</span><?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex gap-1">
                                <a href="<?= BASE_URL ?>/kahoot-create.php?id=<?= $gameId ?>&edit_q=<?= $q['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return confirmForm(this, 'Delete this question?', 'Delete')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_question">
                                    <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-puzzle-piece fa-2x mb-3 d-block"></i>
                <h5 class="fw-bold">Save Game Settings First</h5>
                <p>Choose <strong>Start With</strong> on the left, then click "Create Game" to open the builder with either Multiple Choice or Word Scramble selected.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const questionTypeSelect = document.getElementById('questionTypeSelect');
const mcQuestionFields = document.getElementById('mcQuestionFields');
const wsQuestionFields = document.getElementById('wsQuestionFields');

function syncKahootQuestionType() {
    if (!questionTypeSelect || !mcQuestionFields || !wsQuestionFields) return;
    const isWordScramble = questionTypeSelect.value === 'word_scramble';
    mcQuestionFields.style.display = isWordScramble ? 'none' : '';
    wsQuestionFields.style.display = isWordScramble ? '' : 'none';
}

questionTypeSelect?.addEventListener('change', syncKahootQuestionType);
syncKahootQuestionType();
</script>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
