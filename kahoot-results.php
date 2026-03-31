<?php
require_once __DIR__ . '/helpers/functions.php';
requireLogin();
$pdo = getDB();
$user = currentUser();

try { $pdo->query("SELECT 1 FROM kahoot_games LIMIT 1"); } catch (Exception $e) {
    flash('error', 'Live Quiz not installed.'); redirect('/dashboard.php');
}

$sessionId = intval($_GET['session_id'] ?? 0);
$gameId    = intval($_GET['game_id'] ?? 0);

if ($gameId && !$sessionId) {
    $stmt = $pdo->prepare("SELECT id FROM kahoot_sessions WHERE game_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$gameId]);
    $row = $stmt->fetch();
    if ($row) $sessionId = $row['id'];
}

if (!$sessionId) {
    flash('error', 'No session specified.');
    redirect('/kahoot-games.php');
}

$stmt = $pdo->prepare("SELECT s.*, g.title, g.description, g.class_id, g.created_by, g.time_limit, g.game_mode,
    (SELECT COUNT(*) FROM kahoot_questions WHERE game_id = g.id) as total_questions
    FROM kahoot_sessions s
    JOIN kahoot_games g ON s.game_id = g.id
    WHERE s.id = ?");
$stmt->execute([$sessionId]);
$session = $stmt->fetch();

if (!$session) {
    flash('error', 'Session not found.');
    redirect('/kahoot-games.php');
}

$isHost = ($session['created_by'] == $user['id'] || hasRole('superadmin'));
$isStudent = hasRole('student');

if ($isStudent && !$isHost) {
    $pChk = $pdo->prepare("SELECT id FROM kahoot_participants WHERE session_id = ? AND user_id = ?");
    $pChk->execute([$sessionId, $user['id']]);
    if (!$pChk->fetch()) {
        flash('error', 'You did not participate in this session.');
        redirect('/kahoot-games.php');
    }
}

$partStmt = $pdo->prepare("SELECT p.*, u.first_name, u.last_name, u.email
    FROM kahoot_participants p
    LEFT JOIN users u ON p.user_id = u.id
    WHERE p.session_id = ?
    ORDER BY p.score DESC, p.correct_count DESC");
$partStmt->execute([$sessionId]);
$participants = $partStmt->fetchAll();

$qStmt = $pdo->prepare("SELECT q.* FROM kahoot_questions q WHERE q.game_id = ? ORDER BY q.question_order");
$qStmt->execute([$session['game_id']]);
$questions = $qStmt->fetchAll();

$choiceStmt = $pdo->prepare("SELECT c.* FROM kahoot_choices c
    JOIN kahoot_questions q ON c.question_id = q.id
    WHERE q.game_id = ? ORDER BY c.choice_label");
$choiceStmt->execute([$session['game_id']]);
$allChoices = [];
foreach ($choiceStmt->fetchAll() as $ch) {
    $allChoices[$ch['question_id']][] = $ch;
}

$ansStmt = $pdo->prepare("SELECT a.question_id, a.choice_id, COUNT(*) as cnt, AVG(a.time_taken) as avg_time,
    SUM(a.is_correct) as correct_cnt
    FROM kahoot_answers a WHERE a.session_id = ? GROUP BY a.question_id, a.choice_id");
$ansStmt->execute([$sessionId]);
$answerDist = [];
foreach ($ansStmt->fetchAll() as $ad) {
    $answerDist[$ad['question_id']][$ad['choice_id']] = $ad;
}

$qAccStmt = $pdo->prepare("SELECT question_id, SUM(is_correct) as correct, COUNT(*) as total, AVG(time_taken) as avg_time
    FROM kahoot_answers WHERE session_id = ? GROUP BY question_id");
$qAccStmt->execute([$sessionId]);
$qAccuracy = [];
foreach ($qAccStmt->fetchAll() as $row) {
    $qAccuracy[$row['question_id']] = $row;
}

$myAnswers = [];
if ($isStudent) {
    $myP = $pdo->prepare("SELECT id FROM kahoot_participants WHERE session_id = ? AND user_id = ?");
    $myP->execute([$sessionId, $user['id']]);
    $myPart = $myP->fetch();
    if ($myPart) {
        $maStmt = $pdo->prepare("SELECT * FROM kahoot_answers WHERE session_id = ? AND participant_id = ?");
        $maStmt->execute([$sessionId, $myPart['id']]);
        foreach ($maStmt->fetchAll() as $ma) { $myAnswers[$ma['question_id']] = $ma; }
    }
}

$totalParticipants = count($participants);
$avgScore = $totalParticipants > 0 ? round(array_sum(array_column($participants, 'score')) / $totalParticipants) : 0;
$avgCorrect = $totalParticipants > 0 ? round(array_sum(array_column($participants, 'correct_count')) / $totalParticipants, 1) : 0;

$pageTitle = 'Quiz Results: ' . $session['title'];
$breadcrumbPills = ['Live Quiz', 'Results'];
require_once __DIR__ . '/views/layouts/header.php';

$choiceColors = ['A' => '#e21b3c', 'B' => '#1368ce', 'C' => '#d89e00', 'D' => '#26890c'];
$choiceIcons  = ['A' => 'fa-diamond', 'B' => 'fa-circle', 'C' => 'fa-square', 'D' => 'fa-star'];
?>

<style>
.kr-hero { background: linear-gradient(135deg, #1a0533, #2d1b69); border-radius: 16px; padding: 28px; color: #fff; margin-bottom: 24px; }
.kr-hero h3 { margin: 0 0 4px; font-weight: 700; }
.kr-hero-sub { color: rgba(255,255,255,0.6); font-size: 0.9rem; }
.kr-stats-row { display: flex; gap: 16px; margin-top: 16px; flex-wrap: wrap; }
.kr-stat-card { background: rgba(255,255,255,0.1); border-radius: 12px; padding: 14px 20px; min-width: 120px; text-align: center; }
.kr-stat-val { font-size: 1.6rem; font-weight: 800; }
.kr-stat-lbl { font-size: 0.75rem; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 0.05em; }
.kr-section { margin-bottom: 24px; }
.kr-section-title { font-weight: 700; font-size: 1.1rem; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
.kr-podium { display: flex; align-items: flex-end; gap: 16px; justify-content: center; margin-bottom: 20px; }
.kr-podium-item { text-align: center; padding: 16px; border-radius: 12px; width: 140px; }
.kr-podium-1 { background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #000; order: 2; min-height: 160px; }
.kr-podium-2 { background: linear-gradient(135deg, #94a3b8, #cbd5e1); color: #1e293b; order: 1; min-height: 130px; }
.kr-podium-3 { background: linear-gradient(135deg, #d97706, #b45309); color: #fff; order: 3; min-height: 110px; }
.kr-podium-rank { font-size: 1.5rem; font-weight: 900; }
.kr-podium-name { font-weight: 600; font-size: 0.9rem; margin: 4px 0; }
.kr-podium-score { font-size: 0.8rem; opacity: 0.8; }
.kr-lb-table { border-radius: 12px; overflow: hidden; }
.kr-lb-table th { background: #f1f5f9; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.03em; }
.kr-lb-row-me { background: rgba(124, 58, 237, 0.08) !important; border-left: 3px solid #7c3aed; }
.kr-q-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
.kr-q-header { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
.kr-q-number { background: #7c3aed; color: #fff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; }
.kr-q-text { font-weight: 600; flex: 1; }
.kr-q-accuracy { font-size: 0.85rem; font-weight: 600; }
.kr-q-accuracy.good { color: #059669; }
.kr-q-accuracy.ok { color: #d97706; }
.kr-q-accuracy.bad { color: #dc2626; }
.kr-choice-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; border-radius: 8px; padding: 8px 12px; background: #f8fafc; }
.kr-choice-label { font-weight: 700; color: #fff; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; flex-shrink: 0; }
.kr-choice-text { flex: 1; font-weight: 500; font-size: 0.9rem; }
.kr-choice-bar-fill { height: 20px; border-radius: 4px; transition: width 0.4s; min-width: 2px; }
.kr-choice-cnt { font-weight: 600; font-size: 0.85rem; color: #64748b; width: 30px; text-align: right; }
.kr-choice-bar.is-correct { background: #ecfdf5; border: 1px solid #a7f3d0; }
.kr-choice-bar.my-pick { box-shadow: inset 0 0 0 2px #7c3aed; }
.kr-choice-bar.my-pick.my-wrong { box-shadow: inset 0 0 0 2px #ef4444; }
.kr-q-meta { display: flex; gap: 16px; margin-top: 8px; font-size: 0.8rem; color: #94a3b8; }
.kr-scramble-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 12px; }
.kr-scramble-stat { border-radius: 10px; padding: 14px 12px; text-align: center; background: #f8fafc; border: 1px solid #e2e8f0; }
.kr-scramble-stat strong { display: block; font-size: 1.5rem; font-weight: 800; margin-bottom: 4px; }
.kr-answer-note { display: inline-flex; align-items: center; gap: 8px; margin-top: 8px; padding: 8px 12px; border-radius: 999px; background: #f8fafc; border: 1px solid #e2e8f0; font-size: 0.85rem; font-weight: 600; }
</style>

<div class="kr-hero">
    <h3><i class="fas fa-trophy me-2" style="color:#fbbf24;"></i><?= e($session['title']) ?></h3>
    <div class="kr-hero-sub">
        <?= $session['game_mode'] === 'live' ? 'Live Game' : 'Practice' ?>
        <?php if ($session['started_at']): ?> &bull; <?= date('M j, Y g:i A', strtotime($session['started_at'])) ?><?php endif; ?>
        <?php if ($session['ended_at']): ?> — <?= date('g:i A', strtotime($session['ended_at'])) ?><?php endif; ?>
    </div>
    <div class="kr-stats-row">
        <div class="kr-stat-card">
            <div class="kr-stat-val"><?= $totalParticipants ?></div>
            <div class="kr-stat-lbl">Players</div>
        </div>
        <div class="kr-stat-card">
            <div class="kr-stat-val"><?= count($questions) ?></div>
            <div class="kr-stat-lbl">Questions</div>
        </div>
        <div class="kr-stat-card">
            <div class="kr-stat-val"><?= number_format($avgScore) ?></div>
            <div class="kr-stat-lbl">Avg Score</div>
        </div>
        <div class="kr-stat-card">
            <div class="kr-stat-val"><?= $avgCorrect ?>/<?= count($questions) ?></div>
            <div class="kr-stat-lbl">Avg Correct</div>
        </div>
    </div>
</div>

<?php if ($totalParticipants >= 3): ?>
<div class="kr-section">
    <div class="kr-podium">
        <?php foreach ([1, 0, 2] as $idx):
            if (!isset($participants[$idx])) continue;
            $p = $participants[$idx];
            $r = $idx + 1;
            $name = $p['nickname'] ?: ($p['first_name'] . ' ' . substr($p['last_name'] ?? '', 0, 1) . '.');
        ?>
        <div class="kr-podium-item kr-podium-<?= $r ?>">
            <div class="kr-podium-rank"><?= $r === 1 ? '🥇' : ($r === 2 ? '🥈' : '🥉') ?></div>
            <div class="kr-podium-name"><?= e($name) ?></div>
            <div class="kr-podium-score"><?= number_format($p['score']) ?> pts</div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="kr-section">
    <div class="kr-section-title"><i class="fas fa-ranking-star text-primary"></i> Leaderboard</div>
    <div class="table-responsive kr-lb-table">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width:50px;">Rank</th>
                    <th>Player</th>
                    <th class="text-center">Score</th>
                    <th class="text-center">Correct</th>
                    <th class="text-center">Accuracy</th>
                    <th class="text-center">Streak</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($participants as $i => $p):
                $name = $p['nickname'] ?: ($p['first_name'] . ' ' . substr($p['last_name'] ?? '', 0, 1) . '.');
                $acc = count($questions) > 0 ? round($p['correct_count'] / count($questions) * 100) : 0;
                $isMe = ($p['user_id'] == $user['id']);
            ?>
                <tr class="<?= $isMe ? 'kr-lb-row-me' : '' ?>">
                    <td class="text-center fw-bold"><?= $i + 1 ?></td>
                    <td>
                        <span class="fw-semibold"><?= e($name) ?></span>
                        <?php if ($isMe): ?><span class="badge bg-primary bg-opacity-25 text-primary ms-1" style="font-size:0.7rem;">You</span><?php endif; ?>
                        <?php if ($isHost && $p['email']): ?><div class="text-muted" style="font-size:0.75rem;"><?= e($p['email']) ?></div><?php endif; ?>
                    </td>
                    <td class="text-center fw-bold"><?= number_format($p['score']) ?></td>
                    <td class="text-center"><?= $p['correct_count'] ?>/<?= count($questions) ?></td>
                    <td class="text-center"><?= $acc ?>%</td>
                    <td class="text-center">
                        <?php if ($p['streak'] >= 3): ?>
                            <span class="text-warning"><i class="fas fa-fire"></i> <?= $p['streak'] ?></span>
                        <?php else: ?>
                            <?= $p['streak'] ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="kr-section">
    <div class="kr-section-title"><i class="fas fa-chart-bar text-success"></i> Question Breakdown</div>

    <?php foreach ($questions as $qi => $q):
        $qId = $q['id'];
        $choices = $allChoices[$qId] ?? [];
        $dist = $answerDist[$qId] ?? [];
        $qacc = $qAccuracy[$qId] ?? null;
        $accPct = $qacc && $qacc['total'] > 0 ? round(($qacc['correct'] / $qacc['total']) * 100) : 0;
        $accClass = $accPct >= 70 ? 'good' : ($accPct >= 40 ? 'ok' : 'bad');
        $totalAnswered = $qacc['total'] ?? 0;
        $myAns = $myAnswers[$qId] ?? null;
    ?>
    <div class="kr-q-card">
        <div class="kr-q-header">
            <div class="kr-q-number"><?= $qi + 1 ?></div>
            <div class="kr-q-text"><?= e($q['question_text']) ?></div>
            <div class="kr-q-accuracy <?= $accClass ?>"><i class="fas fa-bullseye me-1"></i><?= $accPct ?>%</div>
        </div>

        <?php if ($q['question_image']): ?>
            <img src="<?= BASE_URL . '/' . e($q['question_image']) ?>" style="max-width:200px;border-radius:8px;margin-bottom:12px;">
        <?php endif; ?>

        <?php if (($q['question_type'] ?? 'multiple_choice') === 'word_scramble'):
            $correctCount = (int)($qacc['correct'] ?? 0);
            $wrongCount = max(0, $totalAnswered - $correctCount);
            $unansweredCount = max(0, $totalParticipants - $totalAnswered);
        ?>
        <div class="kr-scramble-grid">
            <div class="kr-scramble-stat"><strong style="color:#059669;"><?= $correctCount ?></strong>Correct</div>
            <div class="kr-scramble-stat"><strong style="color:#D97706;"><?= $wrongCount ?></strong>Wrong</div>
            <div class="kr-scramble-stat"><strong style="color:#64748B;"><?= $unansweredCount ?></strong>No Answer</div>
        </div>

        <div class="kr-answer-note">
            <i class="fas fa-puzzle-piece text-warning"></i>
            Correct Answer: <strong><?= e($q['correct_answer']) ?></strong>
        </div>

        <?php if ($myAns): ?>
        <div class="kr-answer-note ms-2">
            <i class="fas fa-user text-primary"></i>
            Your answer: <strong><?= e($myAns['answer_text'] ?: 'No answer') ?></strong>
            <?php if ($myAns['is_correct']): ?><span class="text-success"><i class="fas fa-check-circle"></i></span><?php else: ?><span class="text-danger"><i class="fas fa-times-circle"></i></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <?php foreach ($choices as $ch):
            $cnt = $dist[$ch['id']]['cnt'] ?? 0;
            $pct = $totalAnswered > 0 ? round($cnt / $totalAnswered * 100) : 0;
            $isCorrect = $ch['is_correct'];
            $isMyPick = ($myAns && $myAns['choice_id'] == $ch['id']);
            $barClasses = 'kr-choice-bar';
            if ($isCorrect) $barClasses .= ' is-correct';
            if ($isMyPick && $isCorrect) $barClasses .= ' my-pick';
            if ($isMyPick && !$isCorrect) $barClasses .= ' my-pick my-wrong';
        ?>
        <div class="<?= $barClasses ?>">
            <div class="kr-choice-label" style="background:<?= $choiceColors[$ch['choice_label']] ?>;">
                <i class="fas <?= $choiceIcons[$ch['choice_label']] ?>"></i>
            </div>
            <div class="kr-choice-text">
                <?= e($ch['choice_text']) ?>
                <?php if ($isCorrect): ?><i class="fas fa-check-circle text-success ms-1"></i><?php endif; ?>
                <?php if ($isMyPick): ?><span class="badge bg-primary bg-opacity-25 text-primary ms-1" style="font-size:0.65rem;">Your answer</span><?php endif; ?>
            </div>
            <div style="width:100px;">
                <div style="background:#e2e8f0;border-radius:4px;overflow:hidden;">
                    <div class="kr-choice-bar-fill" style="width:<?= $pct ?>%;background:<?= $choiceColors[$ch['choice_label']] ?>;"></div>
                </div>
            </div>
            <div class="kr-choice-cnt"><?= $cnt ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <div class="kr-q-meta">
            <span><i class="fas fa-users me-1"></i><?= $totalAnswered ?>/<?= $totalParticipants ?> answered</span>
            <?php if ($qacc): ?>
            <span><i class="fas fa-clock me-1"></i>Avg <?= round($qacc['avg_time'], 1) ?>s</span>
            <?php endif; ?>
            <span><i class="fas fa-coins me-1"></i><?= $q['points'] ?> pts</span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($isHost): ?>
<div class="text-center mb-4">
    <a href="<?= BASE_URL ?>/kahoot-api.php?action=export_csv&session_id=<?= $sessionId ?>" class="btn btn-outline-primary">
        <i class="fas fa-download me-1"></i>Export CSV
    </a>
    <a href="<?= BASE_URL ?>/kahoot-games.php" class="btn btn-outline-secondary ms-2">
        <i class="fas fa-arrow-left me-1"></i>Back to Games
    </a>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
