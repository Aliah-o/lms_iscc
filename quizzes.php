<?php
$pageTitle = 'Quizzes';
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'student');
$pdo = getDB();
$user = currentUser();
$role = $user['role'];
$classId = intval($_GET['class_id'] ?? 0);

// ─── Auto-ensure quiz settings columns exist ───
try {
    $pdo->query("SELECT deadline FROM quizzes LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE quizzes ADD COLUMN deadline DATETIME DEFAULT NULL");
        $pdo->exec("ALTER TABLE quizzes ADD COLUMN max_attempts INT DEFAULT 0");
        $pdo->exec("ALTER TABLE quizzes ADD COLUMN time_limit INT DEFAULT 0");
    } catch (Exception $e2) {}
}

if (!$classId) {
    if ($role === 'instructor') {
        $breadcrumbPills = ['Teaching', 'Quizzes'];
        $stmt = $pdo->prepare("SELECT tc.*, s.section_name FROM instructor_classes tc JOIN sections s ON tc.section_id = s.id WHERE tc.instructor_id = ? AND tc.is_active = 1 ORDER BY tc.program_code, tc.year_level");
        $stmt->execute([$user['id']]);
        $classes = $stmt->fetchAll();
    } else {
        $breadcrumbPills = ['My Learning', 'Quizzes'];
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
                <svg viewBox="0 0 200 200" fill="none"><circle cx="100" cy="100" r="80" fill="#F1F5F9"/><text x="100" y="108" text-anchor="middle" fill="#94A3B8" font-size="40">📝</text></svg>
                <h5>No Classes Available</h5>
                <p>You need to be assigned/enrolled in a class to access Quizzes.</p>
            </div>
        </div>
        <?php else: ?>
        <?php foreach ($classes as $cls): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#FEF3C7,#FDE68A);display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-question-circle" style="color:#92400E;font-size:1.1rem;"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold"><?= e($cls['subject_name'] ?? 'General') ?></h6>
                            <small class="text-muted"><?= e(PROGRAMS[$cls['program_code']] ?? $cls['program_code']) ?> • <?= e(YEAR_LEVELS[$cls['year_level']]) ?> • Section <?= e($cls['section_name']) ?></small>
                        </div>
                    </div>
                    <div class="mt-auto">
                        <a href="<?= BASE_URL ?>/quizzes.php?class_id=<?= $cls['id'] ?? $cls['class_id'] ?>" class="btn btn-sm btn-primary-gradient w-100"><i class="fas fa-question-circle me-1"></i>View Quizzes</a>
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

$breadcrumbPills = [PROGRAMS[$class['program_code']] ?? $class['program_code'], YEAR_LEVELS[$class['year_level']], 'Section ' . $class['section_name'], 'Quizzes'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'instructor') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect("/quizzes.php?class_id=$classId"); }
    $action = $_POST['action'] ?? '';

    if ($action === 'create_quiz') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $quizType = $_POST['quiz_type'] ?? 'multiple_choice';
        $deadline = trim($_POST['deadline'] ?? '') ?: null;
        $maxAttempts = intval($_POST['max_attempts'] ?? 0);
        $timeLimit = intval($_POST['time_limit'] ?? 0);

        if ($title && $quizType === 'multiple_choice') {
            $pdo->prepare("INSERT INTO quizzes (class_id, title, description, quiz_type, deadline, max_attempts, time_limit) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$classId, $title, $description, $quizType, $deadline, $maxAttempts, $timeLimit]);
            $newQuizId = $pdo->lastInsertId();
            auditLog('quiz_created', "Created quiz: $title");
            flash('success', 'Quiz created. Now add questions.');
            redirect("/quizzes.php?class_id=$classId&manage=$newQuizId");
        } else {
            flash('error', 'Word Scramble creation has moved to Live Quiz.');
        }
    } elseif ($action === 'add_mc_question') {
        $quizId = intval($_POST['quiz_id'] ?? 0);
        $qText = trim($_POST['question_text'] ?? '');
        $optA = trim($_POST['option_a'] ?? '');
        $optB = trim($_POST['option_b'] ?? '');
        $optC = trim($_POST['option_c'] ?? '');
        $optD = trim($_POST['option_d'] ?? '');
        $correct = trim($_POST['correct_answer'] ?? '');

        if ($quizId && $qText && $correct) {
            $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM quiz_questions WHERE quiz_id = ?");
            $maxOrder->execute([$quizId]);
            $pdo->prepare("INSERT INTO quiz_questions (quiz_id, question_text, question_type, option_a, option_b, option_c, option_d, correct_answer, sort_order) VALUES (?, ?, 'multiple_choice', ?, ?, ?, ?, ?, ?)")
                ->execute([$quizId, $qText, $optA, $optB, $optC, $optD, $correct, $maxOrder->fetchColumn() + 1]);
            flash('success', 'Question added.');
        }
        redirect("/quizzes.php?class_id=$classId&manage=$quizId");
    } elseif ($action === 'add_ws_question') {
        $quizId = intval($_POST['quiz_id'] ?? 0);
        $qText = trim($_POST['question_text'] ?? '');
        $correctAnswer = strtoupper(trim($_POST['correct_answer'] ?? ''));

        if ($quizId && $qText && $correctAnswer) {
            $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM quiz_questions WHERE quiz_id = ?");
            $maxOrder->execute([$quizId]);
            $pdo->prepare("INSERT INTO quiz_questions (quiz_id, question_text, question_type, correct_answer, sort_order) VALUES (?, ?, 'word_scramble', ?, ?)")
                ->execute([$quizId, $qText, $correctAnswer, $maxOrder->fetchColumn() + 1]);
            flash('success', 'Word Scramble question added.');
        }
        redirect("/quizzes.php?class_id=$classId&manage=$quizId");
    } elseif ($action === 'delete_question') {
        $qId = intval($_POST['question_id'] ?? 0);
        $quizId = intval($_POST['quiz_id'] ?? 0);
        $pdo->prepare("DELETE FROM quiz_questions WHERE id = ?")->execute([$qId]);
        flash('success', 'Question deleted.');
        redirect("/quizzes.php?class_id=$classId&manage=$quizId");
    } elseif ($action === 'delete_quiz') {
        $quizId = intval($_POST['quiz_id'] ?? 0);
        $pdo->prepare("DELETE FROM quizzes WHERE id = ? AND class_id = ?")->execute([$quizId, $classId]);
        flash('success', 'Quiz deleted.');
    } elseif ($action === 'toggle_publish') {
        $quizId = intval($_POST['quiz_id'] ?? 0);
        $pdo->prepare("UPDATE quizzes SET is_published = NOT is_published WHERE id = ? AND class_id = ?")->execute([$quizId, $classId]);
        flash('success', 'Quiz publish status toggled.');
    } elseif ($action === 'update_quiz_settings') {
        $quizId = intval($_POST['quiz_id'] ?? 0);
        $deadline = trim($_POST['deadline'] ?? '') ?: null;
        $maxAttempts = intval($_POST['max_attempts'] ?? 0);
        $timeLimit = intval($_POST['time_limit'] ?? 0);
        $pdo->prepare("UPDATE quizzes SET deadline = ?, max_attempts = ?, time_limit = ? WHERE id = ? AND class_id = ?")
            ->execute([$deadline, $maxAttempts, $timeLimit, $quizId, $classId]);
        flash('success', 'Quiz settings updated.');
        redirect("/quizzes.php?class_id=$classId&manage=$quizId");
    }

    if (!isset($_POST['quiz_id']) || $action === 'delete_quiz' || $action === 'toggle_publish' || $action === 'create_quiz') {
        redirect("/quizzes.php?class_id=$classId");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'student') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect("/quizzes.php?class_id=$classId"); }
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_quiz') {
        $quizId = intval($_POST['quiz_id'] ?? 0);
        $quiz = $pdo->prepare("SELECT * FROM quizzes WHERE id = ? AND class_id = ? AND is_published = 1");
        $quiz->execute([$quizId, $classId]);
        $quiz = $quiz->fetch();

        if ($quiz) {
            // Check deadline
            if ($quiz['deadline'] && strtotime($quiz['deadline']) < time()) {
                flash('error', 'This quiz deadline has passed.');
                redirect("/quizzes.php?class_id=$classId");
            }
            // Check max attempts
            if ($quiz['max_attempts'] > 0) {
                $attemptCount = $pdo->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ? AND student_id = ?");
                $attemptCount->execute([$quizId, $user['id']]);
                if ($attemptCount->fetchColumn() >= $quiz['max_attempts']) {
                    flash('error', 'You have reached the maximum number of attempts for this quiz.');
                    redirect("/quizzes.php?class_id=$classId");
                }
            }

            $questions = $pdo->prepare("SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order");
            $questions->execute([$quizId]);
            $questions = $questions->fetchAll();

            $totalItems = count($questions);
            $correctItems = 0;

            $pdo->prepare("INSERT INTO quiz_attempts (quiz_id, student_id, score, total_items, correct_items) VALUES (?, ?, 0, ?, 0)")
                ->execute([$quizId, $user['id'], $totalItems]);
            $attemptId = $pdo->lastInsertId();

            foreach ($questions as $q) {
                $answer = strtoupper(trim($_POST['answer_' . $q['id']] ?? ''));
                $correct = strtoupper(trim($q['correct_answer']));
                $isCorrect = ($answer === $correct) ? 1 : 0;
                if ($isCorrect) $correctItems++;

                $pdo->prepare("INSERT INTO quiz_answers (attempt_id, question_id, student_answer, is_correct) VALUES (?, ?, ?, ?)")
                    ->execute([$attemptId, $q['id'], $answer, $isCorrect]);
            }

            $score = $totalItems > 0 ? round(($correctItems / $totalItems) * 100, 2) : 0;
            $pdo->prepare("UPDATE quiz_attempts SET score = ?, correct_items = ?, completed_at = NOW() WHERE id = ?")
                ->execute([$score, $correctItems, $attemptId]);

            auditLog('quiz_completed', "Completed quiz #$quizId with score $score%");

            $firstQuizBadge = $pdo->query("SELECT id FROM badges WHERE badge_rule = 'first_quiz' AND is_active = 1 LIMIT 1")->fetch();
            if ($firstQuizBadge) {
                $pdo->prepare("INSERT IGNORE INTO badge_earns (badge_id, student_id) VALUES (?, ?)")->execute([$firstQuizBadge['id'], $user['id']]);
            }

            if ($score == 100) {
                $perfectBadge = $pdo->query("SELECT id FROM badges WHERE badge_rule = 'perfect_score' AND is_active = 1 LIMIT 1")->fetch();
                if ($perfectBadge) {
                    $pdo->prepare("INSERT IGNORE INTO badge_earns (badge_id, student_id) VALUES (?, ?)")->execute([$perfectBadge['id'], $user['id']]);
                }
            }

            $growth = getGrowthData($user['id']);
            if ($growth['improvement'] !== null && $growth['improvement'] >= 10) {
                $growthBadge = $pdo->query("SELECT id FROM badges WHERE badge_rule = 'growth_percent' AND is_active = 1 LIMIT 1")->fetch();
                if ($growthBadge) {
                    $pdo->prepare("INSERT IGNORE INTO badge_earns (badge_id, student_id) VALUES (?, ?)")->execute([$growthBadge['id'], $user['id']]);
                }
            }

            flash('success', "Quiz completed! Score: $score% ($correctItems/$totalItems)");
            redirect("/quizzes.php?class_id=$classId&result=$attemptId");
        }
    }
    redirect("/quizzes.php?class_id=$classId");
}

$quizResult = null;
$resultQuestions = [];
if (isset($_GET['result'])) {
    $attemptId = intval($_GET['result']);
    $quizResult = $pdo->prepare("SELECT qa.*, q.title as quiz_title, q.quiz_type FROM quiz_attempts qa JOIN quizzes q ON qa.quiz_id = q.id WHERE qa.id = ? AND qa.student_id = ?");
    $quizResult->execute([$attemptId, $user['id']]);
    $quizResult = $quizResult->fetch();
    if ($quizResult) {
        $resultQuestions = $pdo->prepare("SELECT qans.*, qq.question_text, qq.question_type, qq.option_a, qq.option_b, qq.option_c, qq.option_d, qq.correct_answer FROM quiz_answers qans JOIN quiz_questions qq ON qans.question_id = qq.id WHERE qans.attempt_id = ? ORDER BY qq.sort_order");
        $resultQuestions->execute([$attemptId]);
        $resultQuestions = $resultQuestions->fetchAll();
    }
}

$takeQuiz = null;
$takeQuestions = [];
$studentAttemptCount = 0;
if (isset($_GET['take']) && $role === 'student') {
    $quizId = intval($_GET['take']);
    $takeQuiz = $pdo->prepare("SELECT * FROM quizzes WHERE id = ? AND class_id = ? AND is_published = 1");
    $takeQuiz->execute([$quizId, $classId]);
    $takeQuiz = $takeQuiz->fetch();
    if ($takeQuiz) {
        // Check deadline
        if ($takeQuiz['deadline'] && strtotime($takeQuiz['deadline']) < time()) {
            flash('error', 'This quiz deadline has passed.');
            $takeQuiz = null;
        }
        // Check max attempts
        if ($takeQuiz) {
            $ac = $pdo->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ? AND student_id = ?");
            $ac->execute([$quizId, $user['id']]);
            $studentAttemptCount = $ac->fetchColumn();
            if ($takeQuiz['max_attempts'] > 0 && $studentAttemptCount >= $takeQuiz['max_attempts']) {
                flash('error', 'You have reached the maximum number of attempts for this quiz.');
                $takeQuiz = null;
            }
        }
        if ($takeQuiz) {
            $stmt = $pdo->prepare("SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order");
            $stmt->execute([$quizId]);
            $takeQuestions = $stmt->fetchAll();
        }
    }
}

$manageQuiz = null;
$manageQuestions = [];
if (isset($_GET['manage']) && $role === 'instructor') {
    $quizId = intval($_GET['manage']);
    $manageQuiz = $pdo->prepare("SELECT * FROM quizzes WHERE id = ? AND class_id = ?");
    $manageQuiz->execute([$quizId, $classId]);
    $manageQuiz = $manageQuiz->fetch();
    if ($manageQuiz) {
        $stmt = $pdo->prepare("SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order");
        $stmt->execute([$quizId]);
        $manageQuestions = $stmt->fetchAll();
    }
}

$quizzes = $pdo->prepare("SELECT q.*, (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) as question_count, (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id) as attempt_count FROM quizzes q WHERE q.class_id = ? ORDER BY q.created_at DESC");
$quizzes->execute([$classId]);
$quizzes = $quizzes->fetchAll();

require_once __DIR__ . '/views/layouts/header.php';
?>

<?php if ($quizResult): ?>
<a href="<?= BASE_URL ?>/quizzes.php?class_id=<?= $classId ?>" class="btn btn-outline-secondary btn-sm mb-3"><i class="fas fa-arrow-left me-1"></i>Back to Quizzes</a>
<div class="card mb-4">
    <div class="card-body text-center py-4">
        <h4 class="fw-bold mb-2"><?= e($quizResult['quiz_title']) ?></h4>
        <div style="font-size:3rem;font-weight:800;<?= $quizResult['score'] >= 60 ? 'color:var(--success)' : 'color:var(--danger)' ?>;margin:16px 0;">
            <?= $quizResult['score'] ?>%
        </div>
        <div class="text-muted mb-3"><?= $quizResult['correct_items'] ?> / <?= $quizResult['total_items'] ?> correct</div>
        <?php if ($quizResult['score'] >= 60): ?>
        <div class="improvement-badge positive"><i class="fas fa-check-circle"></i> Passed!</div>
        <?php else: ?>
        <div class="improvement-badge negative"><i class="fas fa-times-circle"></i> Try Again</div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><span><i class="fas fa-list me-2"></i>Answer Review</span></div>
    <div class="card-body">
        <?php foreach ($resultQuestions as $i => $rq): ?>
        <div class="p-3 mb-3" style="background:<?= $rq['is_correct'] ? '#F0FDF4' : '#FEF2F2' ?>;border-radius:10px;border-left:4px solid <?= $rq['is_correct'] ? 'var(--success)' : 'var(--danger)' ?>;">
            <div class="fw-bold mb-2">Q<?= $i + 1 ?>: <?= e($rq['question_text']) ?></div>
            <?php if ($rq['question_type'] === 'multiple_choice'): ?>
            <div style="font-size:0.85rem;">
                <div>Your answer: <strong><?= e($rq['student_answer']) ?> - <?= e($rq['option_' . strtolower($rq['student_answer'])] ?? '') ?></strong></div>
                <?php if (!$rq['is_correct']): ?>
                <div class="text-success">Correct: <strong><?= e($rq['correct_answer']) ?> - <?= e($rq['option_' . strtolower($rq['correct_answer'])] ?? '') ?></strong></div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div style="font-size:0.85rem;">
                <div>Your answer: <strong><?= e($rq['student_answer']) ?></strong></div>
                <?php if (!$rq['is_correct']): ?>
                <div class="text-success">Correct: <strong><?= e($rq['correct_answer']) ?></strong></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php elseif ($takeQuiz && !empty($takeQuestions)): ?>
<a href="<?= BASE_URL ?>/quizzes.php?class_id=<?= $classId ?>" class="btn btn-outline-secondary btn-sm mb-3"><i class="fas fa-arrow-left me-1"></i>Back to Quizzes</a>
<div class="card mb-4">
    <div class="card-header">
        <span><i class="fas fa-pencil-alt me-2"></i><?= e($takeQuiz['title']) ?></span>
        <div class="d-flex align-items-center gap-2">
            <?php if ($takeQuiz['time_limit'] > 0): ?>
            <span class="badge bg-danger" id="quizTimer" style="font-size:0.9rem;"><i class="fas fa-clock me-1"></i><span id="timerDisplay">--:--</span></span>
            <?php endif; ?>
            <span class="badge bg-info"><?= count($takeQuestions) ?> Questions</span>
            <?php if ($takeQuiz['max_attempts'] > 0): ?>
            <span class="badge bg-warning">Attempt <?= $studentAttemptCount + 1 ?>/<?= $takeQuiz['max_attempts'] ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if ($takeQuiz['deadline']): ?>
        <div class="alert alert-warning py-2 mb-3" style="font-size:0.85rem;"><i class="fas fa-calendar-times me-2"></i>Deadline: <strong><?= date('M d, Y h:i A', strtotime($takeQuiz['deadline'])) ?></strong></div>
        <?php endif; ?>
        <p class="text-muted mb-4"><?= e($takeQuiz['description']) ?></p>
        <form method="POST" id="quizForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="submit_quiz">
            <input type="hidden" name="quiz_id" value="<?= $takeQuiz['id'] ?>">

            <?php foreach ($takeQuestions as $qi => $q): ?>
            <div class="p-4 mb-4" style="background:var(--gray-50);border-radius:12px;" id="question-<?= $q['id'] ?>">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span style="width:32px;height:32px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.85rem;"><?= $qi + 1 ?></span>
                    <span class="badge bg-<?= $q['question_type'] === 'word_scramble' ? 'warning' : 'primary' ?>"><?= $q['question_type'] === 'word_scramble' ? 'Word Scramble' : 'Multiple Choice' ?></span>
                </div>
                <h6 class="fw-bold mb-3"><?= e($q['question_text']) ?></h6>

                <?php if ($q['question_type'] === 'multiple_choice'): ?>
                <div class="row g-2">
                    <?php foreach (['A' => $q['option_a'], 'B' => $q['option_b'], 'C' => $q['option_c'], 'D' => $q['option_d']] as $letter => $opt): ?>
                    <?php if ($opt): ?>
                    <div class="col-md-6">
                        <label class="d-flex align-items-center gap-2 p-3" style="background:#fff;border:2px solid var(--gray-200);border-radius:10px;cursor:pointer;transition:all 0.2s;">
                            <input type="radio" name="answer_<?= $q['id'] ?>" value="<?= $letter ?>" required style="display:none;" onchange="this.closest('label').style.borderColor='var(--primary)';this.closest('label').style.background='var(--primary-50)';">
                            <span style="width:28px;height:28px;border-radius:50%;background:var(--gray-100);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem;"><?= $letter ?></span>
                            <span><?= e($opt) ?></span>
                        </label>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="word-scramble-container" data-qid="<?= $q['id'] ?>" data-answer="<?= e($q['correct_answer']) ?>">
                    <input type="hidden" name="answer_<?= $q['id'] ?>" id="wsAnswer_<?= $q['id'] ?>" value="">
                    <div class="answer-boxes" id="wsBoxes_<?= $q['id'] ?>"></div>
                    <div class="letter-tiles" id="wsTiles_<?= $q['id'] ?>"></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div class="text-center mt-4">
                <button type="button" class="btn btn-primary-gradient btn-lg" onclick="confirmAction('Submit your answers? This cannot be undone.', 'Submit Quiz').then(ok => { if(ok) this.closest('form').submit(); })">
                    <i class="fas fa-paper-plane me-2"></i>Submit Quiz
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.word-scramble-container').forEach(function(container) {
        const qid = container.dataset.qid;
        const answer = container.dataset.answer.replace(/\s+/g, '');
        const answerWithSpaces = container.dataset.answer;
        const boxesEl = document.getElementById('wsBoxes_' + qid);
        const tilesEl = document.getElementById('wsTiles_' + qid);
        const hiddenInput = document.getElementById('wsAnswer_' + qid);

        let letterSlots = [];
        let tiles = [];

        for (let i = 0; i < answerWithSpaces.length; i++) {
            if (answerWithSpaces[i] === ' ') {
                const spacer = document.createElement('div');
                spacer.className = 'answer-box space-box';
                spacer.textContent = '';
                boxesEl.appendChild(spacer);
                letterSlots.push({ el: spacer, isSpace: true, letter: '' });
            } else {
                const box = document.createElement('div');
                box.className = 'answer-box';
                box.addEventListener('click', function() {
                    const idx = letterSlots.indexOf(letterSlots.find(s => s.el === box));
                    if (box.textContent) {
                        const tileIdx = tiles.findIndex(t => t.usedBySlot === idx);
                        if (tileIdx !== -1) {
                            tiles[tileIdx].el.classList.remove('used');
                            tiles[tileIdx].usedBySlot = -1;
                        }
                        box.textContent = '';
                        box.classList.remove('filled');
                        updateHidden();
                    }
                });
                boxesEl.appendChild(box);
                letterSlots.push({ el: box, isSpace: false, letter: '' });
            }
        }

        const letters = answer.split('');
        for (let i = letters.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [letters[i], letters[j]] = [letters[j], letters[i]];
        }

        letters.forEach(function(letter, i) {
            const tile = document.createElement('div');
            tile.className = 'letter-tile';
            tile.textContent = letter;
            tile.addEventListener('click', function() {
                if (tile.classList.contains('used')) return;
                const slotIdx = letterSlots.findIndex(s => !s.isSpace && !s.el.textContent);
                if (slotIdx === -1) return;
                letterSlots[slotIdx].el.textContent = letter;
                letterSlots[slotIdx].el.classList.add('filled');
                tile.classList.add('used');
                tiles[i].usedBySlot = slotIdx;
                updateHidden();
            });
            tilesEl.appendChild(tile);
            tiles.push({ el: tile, letter: letter, usedBySlot: -1 });
        });

        function updateHidden() {
            let result = '';
            letterSlots.forEach(function(s) {
                if (s.isSpace) {
                    result += ' ';
                } else {
                    result += s.el.textContent || '';
                }
            });
            hiddenInput.value = result.replace(/\s+/g, '').toUpperCase();
        }
    });
});
</script>

<?php elseif ($manageQuiz): ?>
<a href="<?= BASE_URL ?>/quizzes.php?class_id=<?= $classId ?>" class="btn btn-outline-secondary btn-sm mb-3"><i class="fas fa-arrow-left me-1"></i>Back to Quizzes</a>
<?php if ($manageQuiz['quiz_type'] !== 'multiple_choice'): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="fas fa-puzzle-piece me-2"></i>Word Scramble now belongs under Live Quiz. Existing quizzes here still work, but create new scramble activities in Live Quiz instead.</span>
    <a href="<?= BASE_URL ?>/kahoot-games.php" class="btn btn-sm btn-outline-dark"><i class="fas fa-gamepad me-1"></i>Open Live Quiz</a>
</div>
<?php endif; ?>
<div class="card mb-4">
    <div class="card-header">
        <span><i class="fas fa-cog me-2"></i><?= e($manageQuiz['title']) ?></span>
        <span class="badge bg-<?= $manageQuiz['quiz_type'] === 'word_scramble' ? 'warning' : ($manageQuiz['quiz_type'] === 'mixed' ? 'info' : 'primary') ?>"><?= e(ucwords(str_replace('_', ' ', $manageQuiz['quiz_type']))) ?></span>
    </div>
    <div class="card-body">
        <p class="text-muted"><?= e($manageQuiz['description']) ?></p>
        <h6 class="fw-bold mt-4 mb-3"><?= count($manageQuestions) ?> Question(s)</h6>

        <?php foreach ($manageQuestions as $i => $mq): ?>
        <div class="p-3 mb-3" style="background:var(--gray-50);border-radius:10px;border-left:4px solid <?= $mq['question_type'] === 'word_scramble' ? 'var(--warning)' : 'var(--primary)' ?>;">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="badge bg-<?= $mq['question_type'] === 'word_scramble' ? 'warning' : 'primary' ?> mb-1"><?= $mq['question_type'] === 'word_scramble' ? 'WS' : 'MC' ?></span>
                    <div class="fw-bold">Q<?= $i + 1 ?>: <?= e($mq['question_text']) ?></div>
                    <?php if ($mq['question_type'] === 'multiple_choice'): ?>
                    <div style="font-size:0.82rem;color:var(--gray-500);margin-top:4px;">
                        A: <?= e($mq['option_a']) ?> | B: <?= e($mq['option_b']) ?> | C: <?= e($mq['option_c']) ?> | D: <?= e($mq['option_d']) ?>
                        <br>Correct: <strong><?= e($mq['correct_answer']) ?></strong>
                    </div>
                    <?php else: ?>
                    <div style="font-size:0.82rem;color:var(--gray-500);margin-top:4px;">Answer: <strong><?= e($mq['correct_answer']) ?></strong></div>
                    <?php endif; ?>
                </div>
                <form method="POST" onsubmit="return confirmForm(this, 'Delete this question? This action cannot be undone.', 'Delete Question')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_question">
                    <input type="hidden" name="question_id" value="<?= $mq['id'] ?>">
                    <input type="hidden" name="quiz_id" value="<?= $manageQuiz['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($manageQuestions)): ?>
        <div class="text-center py-4 text-muted"><i class="fas fa-inbox me-1"></i> No questions yet.</div>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><span><i class="fas fa-sliders-h me-2"></i>Quiz Settings</span></div>
    <div class="card-body">
        <form method="POST" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_quiz_settings">
            <input type="hidden" name="quiz_id" value="<?= $manageQuiz['id'] ?>">
            <div class="col-md-4">
                <label class="form-label fw-bold"><i class="fas fa-calendar-times me-1 text-danger"></i>Deadline</label>
                <input type="datetime-local" name="deadline" class="form-control" value="<?= $manageQuiz['deadline'] ? date('Y-m-d\TH:i', strtotime($manageQuiz['deadline'])) : '' ?>">
                <div class="form-text">Leave empty for no deadline</div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold"><i class="fas fa-redo me-1 text-warning"></i>Max Attempts</label>
                <input type="number" name="max_attempts" class="form-control" min="0" value="<?= intval($manageQuiz['max_attempts'] ?? 0) ?>">
                <div class="form-text">0 = unlimited attempts</div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold"><i class="fas fa-clock me-1 text-info"></i>Time Limit (minutes)</label>
                <input type="number" name="time_limit" class="form-control" min="0" value="<?= intval($manageQuiz['time_limit'] ?? 0) ?>">
                <div class="form-text">0 = no time limit</div>
            </div>
            <div class="col-12"><button type="submit" class="btn btn-primary-gradient"><i class="fas fa-save me-1"></i>Save Settings</button></div>
        </form>
    </div>
</div>

<?php if ($manageQuiz['quiz_type'] !== 'word_scramble'): ?>
<div class="card mb-4">
    <div class="card-header"><span><i class="fas fa-plus me-2"></i>Add Multiple Choice Question</span></div>
    <div class="card-body">
        <form method="POST" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_mc_question">
            <input type="hidden" name="quiz_id" value="<?= $manageQuiz['id'] ?>">
            <div class="col-12"><label class="form-label">Question</label><input type="text" name="question_text" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Option A</label><input type="text" name="option_a" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Option B</label><input type="text" name="option_b" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Option C</label><input type="text" name="option_c" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Option D</label><input type="text" name="option_d" class="form-control"></div>
            <div class="col-md-4">
                <label class="form-label">Correct Answer</label>
                <select name="correct_answer" class="form-select" required>
                    <option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option>
                </select>
            </div>
            <div class="col-12"><button type="submit" class="btn btn-primary-gradient"><i class="fas fa-plus me-1"></i>Add Question</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($manageQuiz['quiz_type'] !== 'multiple_choice'): ?>
<div class="card mb-4">
    <div class="card-header"><span><i class="fas fa-puzzle-piece me-2"></i>Add Word Scramble Question</span></div>
    <div class="card-body">
        <form method="POST" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_ws_question">
            <input type="hidden" name="quiz_id" value="<?= $manageQuiz['id'] ?>">
            <div class="col-md-8"><label class="form-label">Question / Prompt</label><input type="text" name="question_text" class="form-control" required placeholder="e.g., The process of gaining new skills"></div>
            <div class="col-md-4"><label class="form-label">Correct Answer (word/phrase)</label><input type="text" name="correct_answer" class="form-control" required placeholder="e.g., LEARNING"></div>
            <div class="col-12"><button type="submit" class="btn btn-primary-gradient"><i class="fas fa-plus me-1"></i>Add Word Scramble</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<?php if ($role === 'instructor'): ?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="text-muted" style="font-size:0.85rem;"><?= count($quizzes) ?> quizzes</span>
        <a href="<?= BASE_URL ?>/kahoot-games.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-puzzle-piece me-1"></i>Word Scramble in Live Quiz</a>
    </div>
    <button class="btn btn-primary-gradient" data-bs-toggle="modal" data-bs-target="#createQuizModal"><i class="fas fa-plus me-1"></i>New Quiz</button>
</div>
<?php endif; ?>

<?php if (empty($quizzes)): ?>
<div class="empty-state">
    <svg viewBox="0 0 200 200" fill="none"><circle cx="100" cy="100" r="80" fill="#F1F5F9"/><text x="100" y="108" text-anchor="middle" fill="#94A3B8" font-size="40">📝</text></svg>
    <h5>No Quizzes Yet</h5>
    <p><?= $role === 'instructor' ? 'Create your first quiz to get started.' : 'No quizzes have been posted yet.' ?></p>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($quizzes as $quiz): ?>
    <?php if ($role === 'student' && !$quiz['is_published']) continue; ?>
    <div class="col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,<?= $quiz['quiz_type'] === 'word_scramble' ? '#FEF3C7,#FDE68A' : ($quiz['quiz_type'] === 'mixed' ? '#CFFAFE,#A5F3FC' : '#E0E7FF,#C7D2FE') ?>);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-<?= $quiz['quiz_type'] === 'word_scramble' ? 'puzzle-piece' : ($quiz['quiz_type'] === 'mixed' ? 'random' : 'clipboard-list') ?>" style="color:<?= $quiz['quiz_type'] === 'word_scramble' ? '#92400E' : ($quiz['quiz_type'] === 'mixed' ? '#0E7490' : '#4338CA') ?>;"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 fw-bold"><?= e($quiz['title']) ?></h6>
                        <small class="text-muted"><?= $quiz['question_count'] ?> questions • <?= $quiz['attempt_count'] ?> attempts</small>
                    </div>
                </div>
                <p class="text-muted" style="font-size:0.82rem;"><?= e($quiz['description']) ?></p>
                <div class="d-flex flex-wrap gap-1 mb-2" style="flex:1;">
                    <?php if ($quiz['deadline']): ?>
                    <small class="badge bg-<?= strtotime($quiz['deadline']) < time() ? 'danger' : 'warning' ?> bg-opacity-10 text-<?= strtotime($quiz['deadline']) < time() ? 'danger' : 'warning' ?>" style="font-weight:500;"><i class="fas fa-calendar-times me-1"></i><?= date('M d, Y h:i A', strtotime($quiz['deadline'])) ?></small>
                    <?php endif; ?>
                    <?php if ($quiz['max_attempts'] > 0): ?>
                    <small class="badge bg-info bg-opacity-10 text-info" style="font-weight:500;"><i class="fas fa-redo me-1"></i><?= $quiz['max_attempts'] ?> attempt<?= $quiz['max_attempts'] > 1 ? 's' : '' ?></small>
                    <?php endif; ?>
                    <?php if ($quiz['time_limit'] > 0): ?>
                    <small class="badge bg-primary bg-opacity-10 text-primary" style="font-weight:500;"><i class="fas fa-clock me-1"></i><?= $quiz['time_limit'] ?> min</small>
                    <?php endif; ?>
                </div>
                <div class="d-flex align-items-center justify-content-between mt-auto gap-2 flex-wrap">
                    <div>
                        <span class="badge bg-<?= $quiz['quiz_type'] === 'word_scramble' ? 'warning' : ($quiz['quiz_type'] === 'mixed' ? 'info' : 'primary') ?>"><?= e(ucwords(str_replace('_', ' ', $quiz['quiz_type']))) ?></span>
                        <?php if (!$quiz['is_published']): ?><span class="badge bg-secondary">Draft</span><?php endif; ?>
                    </div>
                    <div class="d-flex gap-1">
                        <?php if ($role === 'instructor'): ?>
                        <a href="<?= BASE_URL ?>/quizzes.php?class_id=<?= $classId ?>&manage=<?= $quiz['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-cog"></i></a>
                        <form method="POST" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_publish">
                            <input type="hidden" name="quiz_id" value="<?= $quiz['id'] ?>">
                            <button class="btn btn-sm btn-outline-<?= $quiz['is_published'] ? 'warning' : 'success' ?>" title="<?= $quiz['is_published'] ? 'Unpublish' : 'Publish' ?>"><i class="fas fa-<?= $quiz['is_published'] ? 'eye-slash' : 'eye' ?>"></i></button>
                        </form>
                        <form method="POST" class="d-inline" onsubmit="return confirmForm(this, 'Delete this quiz and all its questions? This action cannot be undone.', 'Delete Quiz')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_quiz">
                            <input type="hidden" name="quiz_id" value="<?= $quiz['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php else: ?>
                        <?php
                        $sAttempts = $pdo->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ? AND student_id = ?");
                        $sAttempts->execute([$quiz['id'], $user['id']]);
                        $sAttemptCount = $sAttempts->fetchColumn();
                        $deadlinePassed = $quiz['deadline'] && strtotime($quiz['deadline']) < time();
                        $attemptsExhausted = $quiz['max_attempts'] > 0 && $sAttemptCount >= $quiz['max_attempts'];
                        ?>
                        <?php if ($quiz['question_count'] > 0 && !$deadlinePassed && !$attemptsExhausted): ?>
                        <a href="<?= BASE_URL ?>/quizzes.php?class_id=<?= $classId ?>&take=<?= $quiz['id'] ?>" class="btn btn-sm btn-primary-gradient"><i class="fas fa-play me-1"></i>Take Quiz</a>
                        <?php elseif ($deadlinePassed): ?>
                        <span class="btn btn-sm btn-outline-secondary disabled"><i class="fas fa-lock me-1"></i>Expired</span>
                        <?php elseif ($attemptsExhausted): ?>
                        <span class="btn btn-sm btn-outline-secondary disabled"><i class="fas fa-ban me-1"></i>Max Attempts</span>
                        <?php endif; ?>
                        <?php
                        $lastAttempt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND student_id = ? ORDER BY id DESC LIMIT 1");
                        $lastAttempt->execute([$quiz['id'], $user['id']]);
                        $la = $lastAttempt->fetch();
                        if ($la): ?>
                        <a href="<?= BASE_URL ?>/quizzes.php?class_id=<?= $classId ?>&result=<?= $la['id'] ?>" class="btn btn-sm btn-outline-info" title="View last result"><i class="fas fa-chart-bar"></i></a>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($role === 'instructor'): ?>
<div class="modal fade" id="createQuizModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_quiz">
                <div class="modal-header"><h5 class="modal-title">Create New Quiz</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Title</label><input type="text" name="title" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
                    <div class="mb-3">
                        <label class="form-label">Quiz Type</label>
                        <select name="quiz_type" class="form-select" required>
                            <option value="multiple_choice">Multiple Choice</option>
                        </select>
                        <div class="form-text"><i class="fas fa-info-circle me-1"></i>Word Scramble has moved to <a href="<?= BASE_URL ?>/kahoot-games.php">Live Quiz</a>.</div>
                    </div>
                    <hr class="my-2">
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-calendar-times me-1 text-danger"></i>Deadline <small class="text-muted">(optional)</small></label>
                        <input type="datetime-local" name="deadline" class="form-control">
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label"><i class="fas fa-redo me-1 text-warning"></i>Max Attempts</label>
                            <input type="number" name="max_attempts" class="form-control" min="0" value="0">
                            <div class="form-text">0 = unlimited</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label"><i class="fas fa-clock me-1 text-info"></i>Time Limit (min)</label>
                            <input type="number" name="time_limit" class="form-control" min="0" value="0">
                            <div class="form-text">0 = no limit</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-gradient">Create Quiz</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($takeQuiz && !empty($takeQuestions) && ($takeQuiz['time_limit'] ?? 0) > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const timeLimit = <?= intval($takeQuiz['time_limit']) ?> * 60;
    let timeLeft = timeLimit;
    const timerEl = document.getElementById('timerDisplay');
    const timerBadge = document.getElementById('quizTimer');
    const form = document.getElementById('quizForm');

    function updateTimer() {
        const mins = Math.floor(timeLeft / 60);
        const secs = timeLeft % 60;
        timerEl.textContent = String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');

        if (timeLeft <= 60) {
            timerBadge.style.animation = 'pulse 1s infinite';
        }

        if (timeLeft <= 0) {
            clearInterval(timerInterval);
            timerEl.textContent = '00:00';
            alert('Time is up! Your quiz will be submitted automatically.');
            form.submit();
            return;
        }
        timeLeft--;
    }

    updateTimer();
    const timerInterval = setInterval(updateTimer, 1000);
});
</script>
<style>
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
</style>
<?php endif; ?>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
