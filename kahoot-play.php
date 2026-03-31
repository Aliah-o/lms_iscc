<?php
require_once 'auth.php';
require_user_auth();

require_once __DIR__ . '/helpers/functions.php';
requireLogin();
$pdo = getDB();
$user = currentUser();

try { $pdo->query("SELECT 1 FROM kahoot_games LIMIT 1"); } catch (Exception $e) {
    flash('error', 'Live Quiz not installed.'); redirect('/dashboard.php');
}

$pin = trim($_GET['pin'] ?? '');
$sessionId = intval($_GET['session_id'] ?? 0);
$game = null;
$session = null;
$participantId = 0;

if ($pin) {
    $stmt = $pdo->prepare("SELECT g.*, s.id as session_id, s.status as session_status
        FROM kahoot_games g
        JOIN kahoot_sessions s ON s.game_id = g.id
        WHERE g.game_pin = ? AND s.status IN ('lobby','playing','reviewing')
        ORDER BY s.id DESC LIMIT 1");
    $stmt->execute([$pin]);
    $game = $stmt->fetch();
    if ($game) {
        $sessionId = $game['session_id'];
    }
}

if ($sessionId) {
    $sessStmt = $pdo->prepare("SELECT s.*, g.title, g.time_limit, g.game_pin, g.show_leaderboard, g.game_mode,
        (SELECT COUNT(*) FROM kahoot_questions WHERE game_id = g.id) as total_questions
        FROM kahoot_sessions s JOIN kahoot_games g ON s.game_id = g.id WHERE s.id = ?");
    $sessStmt->execute([$sessionId]);
    $session = $sessStmt->fetch();

    if ($session) {
        $pChk = $pdo->prepare("SELECT id FROM kahoot_participants WHERE session_id = ? AND user_id = ?");
        $pChk->execute([$sessionId, $user['id']]);
        $existing = $pChk->fetch();
        if ($existing) {
            $participantId = $existing['id'];
        }
    }
}

$pageTitle = $session ? 'Playing: ' . $session['title'] : 'Join Game';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= e($pageTitle) ?> - ISCC LMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');
        body { font-family: 'Inter','Outfit', sans-serif; background: #1a0533; color: #fff; min-height: 100vh; overflow-x: hidden; }

        .join-screen { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; text-align: center; }
        .join-logo { font-size: 2.5rem; font-weight: 900; background: linear-gradient(135deg, #8bfad5, #30d0c6, #486bec); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 8px; }
        .join-subtitle { color: rgba(255,255,255,0.6); margin-bottom: 30px; }
        .join-card { background: rgba(255,255,255,0.08); backdrop-filter: blur(20px); border-radius: 24px; padding: 40px; max-width: 420px; width: 100%; border: 1px solid rgba(255,255,255,0.1); }
        .join-pin-input { background: rgba(255,255,255,0.1); border: 2px solid rgba(255,255,255,0.2); color: #fff; font-size: 2rem; font-weight: 800; text-align: center; letter-spacing: 10px; padding: 16px; border-radius: 16px; }
        .join-pin-input:focus { border-color: #7c3aed; box-shadow: 0 0 0 4px rgba(124,58,237,0.3); background: rgba(255,255,255,0.15); color: #fff; }
        .join-pin-input::placeholder { color: rgba(255,255,255,0.3); letter-spacing: 6px; font-size: 1.2rem; }
        .join-name-input { background: rgba(255,255,255,0.1); border: 2px solid rgba(255,255,255,0.2); color: #fff; font-size: 1.1rem; text-align: center; padding: 12px; border-radius: 12px; }
        .join-name-input:focus { border-color: #7c3aed; box-shadow: 0 0 0 4px rgba(124,58,237,0.3); background: rgba(255,255,255,0.15); color: #fff; }
        .btn-join { background: linear-gradient(135deg, #7c3aed, #a855f7); color: #fff; font-weight: 700; font-size: 1.2rem; padding: 14px; border: none; border-radius: 14px; width: 100%; transition: all 0.3s; }
        .btn-join:hover { background: linear-gradient(135deg, #6d28d9, #9333ea); transform: translateY(-2px); color: #fff; }
        .btn-join:disabled { opacity: 0.5; transform: none; }
        .join-feedback { margin-top: 12px; font-size: 0.9rem; min-height: 24px; }

        .waiting-screen { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; text-align: center; }
        .waiting-dots { display: flex; gap: 8px; margin: 20px auto; }
        .waiting-dots span { width: 16px; height: 16px; border-radius: 50%; background: #7c3aed; animation: bounce 1.4s infinite; }
        .waiting-dots span:nth-child(2) { animation-delay: 0.2s; }
        .waiting-dots span:nth-child(3) { animation-delay: 0.4s; }

        .game-topbar { padding: 12px 20px; display: flex; align-items: center; justify-content: space-between; background: rgba(0,0,0,0.3); }
        .game-topbar .score-display { font-size: 1.1rem; font-weight: 700; }
        .game-topbar .streak-display { font-size: 0.85rem; color: #f59e0b; }

        .question-area { text-align: center; padding: 30px 20px 20px; }
        .question-timer { width: 80px; height: 80px; border-radius: 50%; border: 4px solid #7c3aed; display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 800; margin: 0 auto 16px; transition: all 0.3s; }
        .question-timer.urgent { border-color: #ef4444; color: #ef4444; animation: heartbeat 0.6s ease-in-out infinite; box-shadow: 0 0 20px rgba(239,68,68,0.5), 0 0 40px rgba(239,68,68,0.2); }
        .question-timer.critical { border-color: #ef4444; color: #ef4444; animation: heartbeat 0.35s ease-in-out infinite; box-shadow: 0 0 30px rgba(239,68,68,0.7), 0 0 60px rgba(239,68,68,0.3); font-size: 2.4rem; }
        .question-progress { font-size: 0.85rem; color: rgba(255,255,255,0.5); margin-bottom: 8px; }
        .question-text { font-size: 1.3rem; font-weight: 700; max-width: 700px; margin: 0 auto 16px; line-height: 1.4; }
        .question-image { max-width: 300px; max-height: 200px; border-radius: 12px; margin-bottom: 16px; }

        .choices-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 0 16px 20px; max-width: 700px; margin: 0 auto; }
        .choice-btn { border: none; padding: 24px 16px; border-radius: 12px; font-size: 1.05rem; font-weight: 600; color: #fff; cursor: pointer; transition: all 0.15s; display: flex; align-items: center; gap: 12px; justify-content: center; min-height: 80px; position: relative; overflow: hidden; }
        .scramble-panel { max-width: 700px; margin: 0 auto; padding: 0 16px 20px; }
        .scramble-status { text-align: center; color: rgba(255,255,255,0.7); font-size: 0.95rem; margin-bottom: 14px; }
        .scramble-slots { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; margin-bottom: 18px; }
        .scramble-slot { width: 48px; height: 56px; border-radius: 12px; border: 2px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.08); color: #fff; font-size: 1.2rem; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
        .scramble-slot.filled { border-color: #a78bfa; background: rgba(167,139,250,0.18); }
        .scramble-slot.space { width: 18px; border: none; background: transparent; box-shadow: none; cursor: default; }
        .scramble-tiles { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }
        .scramble-tile { min-width: 48px; height: 48px; padding: 0 12px; border-radius: 12px; border: none; background: linear-gradient(135deg, #7c3aed, #a855f7); color: #fff; font-size: 1.05rem; font-weight: 800; cursor: pointer; box-shadow: 0 8px 18px rgba(124,58,237,0.25); }
        .scramble-tile.used { opacity: 0.35; box-shadow: none; }
        .scramble-controls { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-top: 18px; }
        .scramble-submit { min-width: 220px; }

        body.timer-urgent { animation: bgPulseWarn 1.2s ease-in-out infinite; }
        body.timer-critical { animation: bgPulseCrit 0.7s ease-in-out infinite; }
        body.timer-urgent .choice-btn:not(:disabled):not(.selected) { animation: choiceShakeSubtle 2s ease-in-out infinite; }
        body.timer-critical .choice-btn:not(:disabled):not(.selected) { animation: choiceShake 0.4s ease-in-out infinite; }
        body.timer-critical .choice-btn:not(:disabled):not(.selected):nth-child(2) { animation-delay: 0.1s; }
        body.timer-critical .choice-btn:not(:disabled):not(.selected):nth-child(3) { animation-delay: 0.2s; }
        body.timer-critical .choice-btn:not(:disabled):not(.selected):nth-child(4) { animation-delay: 0.15s; }

        .danger-vignette { position: fixed; top: 0; left: 0; right: 0; bottom: 0; pointer-events: none; z-index: 50; opacity: 0; transition: opacity 0.4s; }
        .danger-vignette.active { opacity: 1; }
        .danger-vignette.level-1 { box-shadow: inset 0 0 80px rgba(239,68,68,0.15); }
        .danger-vignette.level-2 { box-shadow: inset 0 0 120px rgba(239,68,68,0.3); animation: vignetteFlash 0.8s ease-in-out infinite; }

        .hurry-banner { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0); font-size: 2.5rem; font-weight: 900; color: #ef4444; text-shadow: 0 0 20px rgba(239,68,68,0.6), 0 0 40px rgba(239,68,68,0.3); z-index: 55; pointer-events: none; opacity: 0; transition: none; }
        .hurry-banner.show { animation: hurryPop 0.8s ease-out forwards; }
        .choice-btn:active { transform: scale(0.97); }
        .choice-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .choice-btn .choice-icon { font-size: 1.2rem; }
        .choice-btn.choice-a { background: #e21b3c; }
        .choice-btn.choice-b { background: #1368ce; }
        .choice-btn.choice-c { background: #d89e00; }
        .choice-btn.choice-d { background: #26890c; }
        .choice-btn.selected { box-shadow: 0 0 0 4px #fff, 0 0 20px rgba(255,255,255,0.3); transform: scale(0.97); }
        .choice-btn.correct-reveal { box-shadow: 0 0 0 4px #10b981, 0 0 30px rgba(16,185,129,0.4); }
        .choice-btn.wrong-reveal { opacity: 0.3; }

        .result-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 100; animation: fadeIn 0.3s; }
        .result-overlay.correct-bg { background: linear-gradient(135deg, #059669, #10b981); }
        .result-overlay.wrong-bg { background: linear-gradient(135deg, #dc2626, #ef4444); }
        .result-icon { font-size: 5rem; margin-bottom: 16px; }
        .result-text { font-size: 2rem; font-weight: 800; }
        .result-points { font-size: 1.2rem; margin-top: 8px; opacity: 0.8; }

        .position-bar { padding: 16px 20px; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: space-between; }
        .position-rank { font-size: 1.5rem; font-weight: 800; }
        .position-score { font-size: 1.1rem; font-weight: 600; color: #a78bfa; }

        .final-screen { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 30px 20px; }
        .final-rank { font-size: 5rem; font-weight: 900; background: linear-gradient(135deg, #fbbf24, #f59e0b); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; }
        .final-score { font-size: 2rem; font-weight: 700; margin: 8px 0; }
        .final-stats { display: flex; gap: 20px; margin: 20px auto; }
        .final-stat { text-align: center; }
        .final-stat-value { font-size: 1.5rem; font-weight: 700; }
        .final-stat-label { font-size: 0.8rem; color: rgba(255,255,255,0.6); }

        .mini-lb { max-width: 400px; width: 100%; margin: 20px auto; }
        .mini-lb-item { display: flex; align-items: center; gap: 12px; padding: 10px 16px; background: rgba(255,255,255,0.05); border-radius: 10px; margin-bottom: 6px; }
        .mini-lb-item.me { background: rgba(124,58,237,0.3); border: 1px solid rgba(124,58,237,0.5); }
        .mini-lb-rank { font-weight: 800; width: 28px; }
        .mini-lb-name { flex: 1; font-weight: 500; }
        .mini-lb-score { font-weight: 700; color: #a78bfa; }

        @keyframes bounce { 0%,80%,100% { transform: translateY(0); } 40% { transform: translateY(-12px); } }
        @keyframes fadeIn { 0% { opacity: 0; } 100% { opacity: 1; } }
        @keyframes scoreUp { 0% { transform: translateY(0); } 50% { transform: translateY(-10px); } 100% { transform: translateY(0); } }

        @keyframes heartbeat {
            0% { transform: scale(1); }
            15% { transform: scale(1.15); }
            30% { transform: scale(1); }
            45% { transform: scale(1.1); }
            60% { transform: scale(1); }
            100% { transform: scale(1); }
        }

        @keyframes bgPulseWarn {
            0%, 100% { background-color: #1a0533; }
            50% { background-color: #2a0a1a; }
        }
        @keyframes bgPulseCrit {
            0%, 100% { background-color: #1a0533; }
            50% { background-color: #3d0a0a; }
        }

        @keyframes choiceShakeSubtle {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-2px); }
            75% { transform: translateX(2px); }
        }
        @keyframes choiceShake {
            0%, 100% { transform: translateX(0) scale(1); }
            20% { transform: translateX(-3px) scale(1.02); }
            40% { transform: translateX(3px) scale(0.98); }
            60% { transform: translateX(-2px) scale(1.01); }
            80% { transform: translateX(2px) scale(0.99); }
        }

        @keyframes vignetteFlash {
            0%, 100% { box-shadow: inset 0 0 120px rgba(239,68,68,0.2); }
            50% { box-shadow: inset 0 0 150px rgba(239,68,68,0.45); }
        }

        @keyframes hurryPop {
            0% { transform: translate(-50%, -50%) scale(0); opacity: 0; }
            30% { transform: translate(-50%, -50%) scale(1.3); opacity: 1; }
            60% { transform: translate(-50%, -50%) scale(1); opacity: 1; }
            100% { transform: translate(-50%, -50%) scale(1.5); opacity: 0; }
        }

        @media (max-width: 576px) {
            .question-text { font-size: 1.1rem; }
            .choice-btn { padding: 18px 12px; font-size: 0.95rem; min-height: 65px; }
            .choices-grid { gap: 8px; }
            .scramble-slot { width: 42px; height: 48px; font-size: 1rem; }
            .scramble-tile { min-width: 42px; height: 44px; font-size: 0.95rem; }
        }
    </style>
</head>
<body>

<?php if (!$session): ?>
<div class="join-screen" id="joinScreen">
    <div class="join-logo"><i class="fas fa-gamepad"></i> ISCC Quiz</div>
    <div class="join-subtitle">Enter the Game PIN shown on the screen</div>
    <div class="join-card">
        <form id="joinForm">
            <input type="text" class="form-control join-pin-input mb-3" id="pinInput" maxlength="6" placeholder="PIN" value="<?= e($pin) ?>" autocomplete="off" inputmode="numeric">
            <input type="text" class="form-control join-name-input mb-3" id="nicknameInput" placeholder="Your nickname (optional)" value="<?= e($user['first_name'] . ' ' . substr($user['last_name'], 0, 1) . '.') ?>" maxlength="50">
            <button type="submit" class="btn-join" id="joinBtn">
                <i class="fas fa-play me-2"></i>Join Game
            </button>
            <div class="join-feedback" id="joinFeedback"></div>
        </form>
    </div>
    <a href="<?= BASE_URL ?>/kahoot-games.php" class="btn btn-link text-muted mt-3" style="text-decoration:none;"><i class="fas fa-arrow-left me-1"></i>Back</a>
</div>

<script>
const BASE_URL = '<?= BASE_URL ?>';
const CSRF = '<?= csrf_token() ?>';
const USER_ID = <?= $user['id'] ?>;

document.getElementById('joinForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const pin = document.getElementById('pinInput').value.trim();
    const nickname = document.getElementById('nicknameInput').value.trim();
    const feedback = document.getElementById('joinFeedback');
    const btn = document.getElementById('joinBtn');

    if (pin.length !== 6) { feedback.innerHTML = '<span style="color:#ef4444;">Enter a 6-digit PIN</span>'; return; }
    btn.disabled = true;
    feedback.innerHTML = '<span style="color:#a78bfa;"><i class="fas fa-spinner fa-spin me-1"></i>Finding game...</span>';

    fetch(BASE_URL + '/kahoot-api.php?action=check_pin&pin=' + pin)
        .then(r => r.json())
        .then(data => {
            if (!data.valid) {
                feedback.innerHTML = '<span style="color:#ef4444;"><i class="fas fa-times-circle me-1"></i>' + (data.error || 'Game not found') + '</span>';
                btn.disabled = false;
                return;
            }
            feedback.innerHTML = '<span style="color:#10b981;"><i class="fas fa-check-circle me-1"></i>Joining ' + data.title + '...</span>';
            fetch(BASE_URL + '/kahoot-api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=join_game&session_id=' + data.session_id + '&nickname=' + encodeURIComponent(nickname) + '&csrf_token=' + CSRF
            }).then(r => r.json()).then(jd => {
                if (jd.success) {
                    window.location.href = BASE_URL + '/kahoot-play.php?session_id=' + data.session_id + '&pin=' + pin;
                } else {
                    feedback.innerHTML = '<span style="color:#ef4444;">' + (jd.error || 'Failed to join') + '</span>';
                    btn.disabled = false;
                }
            });
        })
        .catch(() => { feedback.innerHTML = '<span style="color:#ef4444;">Connection error</span>'; btn.disabled = false; });
});
</script>

<?php else: ?>

<div class="waiting-screen" id="waitingScreen" style="display:<?= $session['status'] === 'lobby' ? 'flex' : 'none' ?>;">
    <i class="fas fa-gamepad" style="font-size:3rem;color:#7c3aed;margin-bottom:16px;"></i>
    <h2 class="fw-bold mb-1"><?= e($session['title']) ?></h2>
    <p class="text-muted">Waiting for the host to start...</p>
    <div class="waiting-dots"><span></span><span></span><span></span></div>
    <p class="text-muted mt-3" style="font-size:0.85rem;">You're in! Get ready.</p>
</div>

<div class="danger-vignette" id="dangerVignette"></div>
<div class="hurry-banner" id="hurryBanner">⚡ HURRY UP! ⚡</div>

<div id="gamePlayView" style="display:<?= $session['status'] !== 'lobby' && $session['status'] !== 'finished' ? 'block' : 'none' ?>;">
    <div class="game-topbar">
        <div>
            <div class="score-display"><i class="fas fa-star me-1" style="color:#fbbf24;"></i><span id="myScore">0</span></div>
            <div class="streak-display" id="streakDisplay" style="display:none;"><i class="fas fa-fire me-1"></i><span id="myStreak">0</span> streak</div>
        </div>
        <div class="question-progress">Q<span id="qNum">1</span>/<span id="qTotal"><?= $session['total_questions'] ?></span></div>
        <div>
            <span class="badge bg-dark" style="font-size:0.75rem;" id="myRankBadge"># --</span>
        </div>
    </div>

    <div class="question-area">
        <div class="question-timer" id="timer"><span id="timerVal">--</span></div>
        <div id="qImageArea"></div>
        <div class="question-text" id="qText">Loading question...</div>
    </div>

    <div class="choices-grid" id="choicesGrid"></div>
    <div class="scramble-panel" id="scramblePanel" style="display:none;"></div>

    <div class="position-bar" id="positionBar" style="display:none;">
        <div>
            <div class="position-rank">Rank #<span id="myRank">--</span></div>
        </div>
        <div class="position-score"><span id="myTotalScore">0</span> pts</div>
    </div>
</div>

<div class="result-overlay" id="resultOverlay" style="display:none;">
    <div class="result-icon" id="resultIcon"></div>
    <div class="result-text" id="resultText"></div>
    <div class="result-points" id="resultPoints"></div>
</div>

<div class="waiting-screen" id="answerWaitScreen" style="display:none;">
    <i class="fas fa-clock" style="font-size:2.5rem;color:#7c3aed;margin-bottom:12px;"></i>
    <h4 class="fw-bold">Answer Submitted!</h4>
    <p class="text-muted">Waiting for results...</p>
    <div class="waiting-dots"><span></span><span></span><span></span></div>
</div>

<div class="final-screen" id="finalScreen" style="display:<?= $session['status'] === 'finished' ? 'flex' : 'none' ?>;">
    <h2 class="fw-bold mb-2"><i class="fas fa-trophy me-2" style="color:#fbbf24;"></i>Game Over!</h2>
    <div class="final-rank" id="finalRank">#--</div>
    <div class="final-score"><span id="finalScore">0</span> points</div>
    <div class="final-stats">
        <div class="final-stat">
            <div class="final-stat-value" id="finalCorrect">0</div>
            <div class="final-stat-label">Correct</div>
        </div>
        <div class="final-stat">
            <div class="final-stat-value" id="finalTotal"><?= $session['total_questions'] ?></div>
            <div class="final-stat-label">Questions</div>
        </div>
        <div class="final-stat">
            <div class="final-stat-value" id="finalAccuracy">0%</div>
            <div class="final-stat-label">Accuracy</div>
        </div>
    </div>
    <div class="mini-lb" id="finalLB"></div>
    <a href="<?= BASE_URL ?>/kahoot-games.php" class="btn btn-link text-muted mt-3" style="text-decoration:none;">
        <i class="fas fa-arrow-left me-1"></i>Back to Games
    </a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const BASE_URL = '<?= BASE_URL ?>';
const CSRF = '<?= csrf_token() ?>';
const SESSION_ID = <?= $sessionId ?>;
const TOTAL_Q = <?= $session['total_questions'] ?>;
let PARTICIPANT_ID = <?= $participantId ?: 0 ?>;
let currentState = '<?= $session['status'] ?>';
let currentQuestionId = 0;
let hasAnswered = false;
let timerInterval = null;
let questionStartTime = null;
let clientTimerInterval = null;
let clientTimeRemaining = null;
let scrambleState = null;

if (!PARTICIPANT_ID && SESSION_ID) {
    fetch(BASE_URL + '/kahoot-api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=join_game&session_id=' + SESSION_ID + '&nickname=<?= e(urlencode($user['first_name'] . ' ' . substr($user['last_name'], 0, 1) . '.')) ?>&csrf_token=' + CSRF
    }).then(r => r.json()).then(d => { if (d.participant_id) PARTICIPANT_ID = d.participant_id; });
}

function poll() {
    if (!PARTICIPANT_ID) return;
    fetch(BASE_URL + '/kahoot-api.php?action=game_state&session_id=' + SESSION_ID + '&participant_id=' + PARTICIPANT_ID)
        .then(r => r.json())
        .then(handleState)
        .catch(() => {});
}

function handleState(data) {
    if (data.my_stats) {
        document.getElementById('myScore').textContent = (data.my_stats.score || 0).toLocaleString();
        document.getElementById('myTotalScore').textContent = (data.my_stats.score || 0).toLocaleString();
        const streak = data.my_stats.streak || 0;
        document.getElementById('myStreak').textContent = streak;
        document.getElementById('streakDisplay').style.display = streak > 1 ? '' : 'none';
    }
    if (data.my_rank) {
        document.getElementById('myRank').textContent = data.my_rank;
        document.getElementById('myRankBadge').textContent = '#' + data.my_rank;
    }

    if (data.leaderboard) updateMiniLB(data.leaderboard);

    const newState = data.status;

    if (newState === 'lobby') {
        document.getElementById('waitingScreen').style.display = 'flex';
        document.getElementById('gamePlayView').style.display = 'none';
    }
    else if (newState === 'playing' && data.question) {
        document.getElementById('waitingScreen').style.display = 'none';
        document.getElementById('answerWaitScreen').style.display = 'none';
        document.getElementById('resultOverlay').style.display = 'none';

        const q = data.question;

        if (q.id !== currentQuestionId) {
            currentQuestionId = q.id;
            hasAnswered = q.already_answered;
            questionStartTime = Date.now() - ((q.time_limit - q.time_remaining) * 1000);
            clientTimeRemaining = q.time_remaining;
            showQuestion(q, data);
            startClientTimer();
        } else {
            clientTimeRemaining = q.time_remaining;
        }

        if (!hasAnswered && clientTimeRemaining <= 0) {
            disableChoices();
            hasAnswered = true;
            stopClientTimer();
            clearUrgencyEffects();
            document.getElementById('answerWaitScreen').style.display = 'flex';
            document.getElementById('gamePlayView').style.display = 'none';
        }

        if (hasAnswered && q.already_answered) {
            document.getElementById('gamePlayView').style.display = 'none';
            document.getElementById('answerWaitScreen').style.display = 'flex';
        }
    }
    else if (newState === 'reviewing' && data.review) {
        document.getElementById('answerWaitScreen').style.display = 'none';
        document.getElementById('gamePlayView').style.display = 'none';
        clearUrgencyEffects();
        stopClientTimer();

        const myAns = data.review.my_answer;
        const overlay = document.getElementById('resultOverlay');
        overlay.style.display = 'flex';

        if (myAns && myAns.is_correct) {
            overlay.className = 'result-overlay correct-bg';
            document.getElementById('resultIcon').textContent = '🎉';
            document.getElementById('resultText').textContent = 'Correct!';
            document.getElementById('resultPoints').textContent = '+' + (myAns.points_earned || 0) + ' points';
        } else if (myAns) {
            overlay.className = 'result-overlay wrong-bg';
            document.getElementById('resultIcon').textContent = '😞';
            document.getElementById('resultText').textContent = 'Wrong!';
            document.getElementById('resultPoints').textContent = '+0 points';
        } else {
            overlay.className = 'result-overlay wrong-bg';
            document.getElementById('resultIcon').textContent = '⏰';
            document.getElementById('resultText').textContent = 'Time\'s Up!';
            document.getElementById('resultPoints').textContent = 'No answer submitted';
        }
    }
    else if (newState === 'finished') {
        showFinalResults(data);
    }

    currentState = newState;
}

function showQuestion(q, data) {
    document.getElementById('gamePlayView').style.display = 'block';
    document.getElementById('resultOverlay').style.display = 'none';
    document.getElementById('answerWaitScreen').style.display = 'none';

    document.getElementById('qNum').textContent = data.current_question;
    document.getElementById('qTotal').textContent = data.total_questions;
    document.getElementById('qText').textContent = q.text;
    document.getElementById('timerVal').textContent = Math.max(0, q.time_remaining);

    if (q.image) {
        document.getElementById('qImageArea').innerHTML = '<img src="' + BASE_URL + '/' + q.image + '" class="question-image">';
    } else {
        document.getElementById('qImageArea').innerHTML = '';
    }

    const grid = document.getElementById('choicesGrid');
    const scramblePanel = document.getElementById('scramblePanel');
    const classes = { A: 'choice-a', B: 'choice-b', C: 'choice-c', D: 'choice-d' };
    const icons = { A: 'fa-diamond', B: 'fa-circle', C: 'fa-square', D: 'fa-star' };

    if ((q.question_type || 'multiple_choice') === 'word_scramble') {
        grid.style.display = 'none';
        grid.innerHTML = '';
        scramblePanel.style.display = 'block';
        renderWordScramble(q);
    } else {
        scramblePanel.style.display = 'none';
        scramblePanel.innerHTML = '';
        scrambleState = null;
        grid.style.display = 'grid';
        grid.innerHTML = '';
        q.choices.forEach(c => {
            const btn = document.createElement('button');
            btn.className = 'choice-btn ' + (classes[c.choice_label] || '');
            btn.disabled = hasAnswered;
            if (q.answered_choice && q.answered_choice === c.id) btn.classList.add('selected');
            btn.innerHTML = '<span class="choice-icon"><i class="fas ' + (icons[c.choice_label] || 'fa-circle') + '"></i></span> ' + escHtml(c.choice_text);
            btn.onclick = () => submitChoiceAnswer(c.id, q.id, btn);
            grid.appendChild(btn);
        });
    }

    document.getElementById('timer').classList.remove('urgent', 'critical');
    document.getElementById('positionBar').style.display = 'none';
    clearUrgencyEffects();
}

function normalizeWordScrambleValue(value) {
    return (value || '').toUpperCase().replace(/\s+/g, ' ').trim();
}

function renderWordScramble(q) {
    const letters = Array.isArray(q.scramble_letters) ? q.scramble_letters.slice() : [];
    const slots = Array.isArray(q.answer_slots) ? q.answer_slots.slice() : [];
    scrambleState = {
        questionId: q.id,
        tiles: letters.map((char, index) => ({ id: index, char, used: false })),
        slots: slots.map(slotType => slotType === 'space'
            ? { type: 'space' }
            : { type: 'letter', char: '', tileId: null })
    };

    const answeredText = normalizeWordScrambleValue(q.answered_text || '');
    if (answeredText) {
        prefillWordScramble(answeredText);
    }

    drawWordScramble();
}

function prefillWordScramble(answerText) {
    if (!scrambleState) return;
    let answerIndex = 0;
    const chars = Array.from(answerText);

    scrambleState.slots.forEach(slot => {
        if (slot.type === 'space') {
            while (chars[answerIndex] === ' ') answerIndex++;
            return;
        }

        while (chars[answerIndex] === ' ') answerIndex++;
        const char = chars[answerIndex++] || '';
        if (!char) return;

        const tile = scrambleState.tiles.find(candidate => !candidate.used && candidate.char === char);
        if (!tile) return;
        tile.used = true;
        slot.char = tile.char;
        slot.tileId = tile.id;
    });
}

function drawWordScramble() {
    const panel = document.getElementById('scramblePanel');
    if (!panel || !scrambleState) return;

    const slotsHtml = scrambleState.slots.map((slot, index) => {
        if (slot.type === 'space') {
            return '<button type="button" class="scramble-slot space" disabled aria-hidden="true"></button>';
        }
        const filledClass = slot.char ? ' filled' : '';
        const disabledAttr = hasAnswered ? ' disabled' : '';
        return '<button type="button" class="scramble-slot' + filledClass + '" onclick="removeScrambleLetter(' + index + ')"' + disabledAttr + '>' + escHtml(slot.char || '') + '</button>';
    }).join('');

    const tilesHtml = scrambleState.tiles.map(tile => {
        const usedClass = tile.used ? ' used' : '';
        const disabledAttr = tile.used || hasAnswered ? ' disabled' : '';
        return '<button type="button" class="scramble-tile' + usedClass + '" onclick="placeScrambleLetter(' + tile.id + ')"' + disabledAttr + '>' + escHtml(tile.char) + '</button>';
    }).join('');

    const currentAnswer = getWordScrambleAnswer();
    const isComplete = scrambleState.slots.every(slot => slot.type === 'space' || slot.char);

    panel.innerHTML =
        '<div class="scramble-status">Build the correct word or phrase from the scrambled letters.</div>' +
        '<div class="scramble-slots">' + slotsHtml + '</div>' +
        '<div class="scramble-tiles">' + tilesHtml + '</div>' +
        '<div class="scramble-controls">' +
            '<button type="button" class="btn btn-outline-light" onclick="clearScrambleAnswer()" ' + (hasAnswered ? 'disabled' : '') + '>Clear</button>' +
            '<button type="button" class="btn-join scramble-submit" onclick="submitWordScrambleAnswer(' + scrambleState.questionId + ')" ' + (!isComplete || hasAnswered ? 'disabled' : '') + '><i class="fas fa-paper-plane me-2"></i>Submit Answer</button>' +
        '</div>' +
        (currentAnswer ? '<div class="scramble-status mt-2">Current answer: <strong>' + escHtml(currentAnswer) + '</strong></div>' : '');
}

function placeScrambleLetter(tileId) {
    if (hasAnswered || !scrambleState) return;
    const tile = scrambleState.tiles.find(candidate => candidate.id === tileId);
    const slot = scrambleState.slots.find(candidate => candidate.type === 'letter' && !candidate.char);
    if (!tile || tile.used || !slot) return;

    tile.used = true;
    slot.char = tile.char;
    slot.tileId = tile.id;
    drawWordScramble();
}

function removeScrambleLetter(slotIndex) {
    if (hasAnswered || !scrambleState) return;
    const slot = scrambleState.slots[slotIndex];
    if (!slot || slot.type !== 'letter' || !slot.char) return;

    const tile = scrambleState.tiles.find(candidate => candidate.id === slot.tileId);
    if (tile) {
        tile.used = false;
    }
    slot.char = '';
    slot.tileId = null;
    drawWordScramble();
}

function clearScrambleAnswer() {
    if (hasAnswered || !scrambleState) return;
    scrambleState.tiles.forEach(tile => { tile.used = false; });
    scrambleState.slots.forEach(slot => {
        if (slot.type === 'letter') {
            slot.char = '';
            slot.tileId = null;
        }
    });
    drawWordScramble();
}

function getWordScrambleAnswer() {
    if (!scrambleState) return '';
    return scrambleState.slots
        .map(slot => slot.type === 'space' ? ' ' : (slot.char || ''))
        .join('')
        .replace(/\s+/g, ' ')
        .trim();
}

function startClientTimer() {
    stopClientTimer();
    updateTimerDisplay();
    clientTimerInterval = setInterval(() => {
        if (clientTimeRemaining > 0) {
            clientTimeRemaining--;
        }
        updateTimerDisplay();
        if (clientTimeRemaining <= 0 && !hasAnswered) {
            stopClientTimer();
            disableChoices();
            hasAnswered = true;
            document.getElementById('answerWaitScreen').style.display = 'flex';
            document.getElementById('gamePlayView').style.display = 'none';
        }
    }, 1000);
}

function stopClientTimer() {
    if (clientTimerInterval) { clearInterval(clientTimerInterval); clientTimerInterval = null; }
}

let hurryShown = false;

function updateTimerDisplay() {
    const display = Math.max(0, clientTimeRemaining);
    document.getElementById('timerVal').textContent = display;
    const timer = document.getElementById('timer');
    const body = document.body;
    const vignette = document.getElementById('dangerVignette');
    const hurry = document.getElementById('hurryBanner');

    timer.classList.remove('urgent', 'critical');
    body.classList.remove('timer-urgent', 'timer-critical');
    vignette.classList.remove('active', 'level-1', 'level-2');

    if (hasAnswered) return;

    if (display <= 3 && display > 0) {
        timer.classList.add('critical');
        body.classList.add('timer-critical');
        vignette.classList.add('active', 'level-2');
    } else if (display <= 5) {
        timer.classList.add('urgent');
        body.classList.add('timer-urgent');
        vignette.classList.add('active', 'level-1');
        if (!hurryShown) {
            hurryShown = true;
            hurry.classList.add('show');
            setTimeout(() => hurry.classList.remove('show'), 900);
        }
    }
}

function clearUrgencyEffects() {
    document.getElementById('timer').classList.remove('urgent', 'critical');
    document.body.classList.remove('timer-urgent', 'timer-critical');
    document.getElementById('dangerVignette').classList.remove('active', 'level-1', 'level-2');
    hurryShown = false;
}

function submitChoiceAnswer(choiceId, questionId, buttonEl) {
    if (hasAnswered) return;
    hasAnswered = true;
    clearUrgencyEffects();
    stopClientTimer();

    const btns = document.querySelectorAll('.choice-btn');
    btns.forEach(b => b.disabled = true);
    buttonEl?.classList.add('selected');

    const timeTaken = ((Date.now() - questionStartTime) / 1000).toFixed(2);

    fetch(BASE_URL + '/kahoot-api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=submit_answer&session_id=' + SESSION_ID + '&participant_id=' + PARTICIPANT_ID +
              '&question_id=' + questionId + '&choice_id=' + choiceId + '&time_taken=' + timeTaken + '&csrf_token=' + CSRF
    }).then(r => r.json()).then(data => {
        if (data.already_answered) return;

        setTimeout(() => {
            document.getElementById('gamePlayView').style.display = 'none';
            document.getElementById('answerWaitScreen').style.display = 'flex';
        }, 300);

        if (data.stats) {
            document.getElementById('myScore').textContent = (data.stats.score || 0).toLocaleString();
            document.getElementById('myTotalScore').textContent = (data.stats.score || 0).toLocaleString();
        }
    }).catch(() => {});
}

function submitWordScrambleAnswer(questionId) {
    if (hasAnswered || !scrambleState) return;
    const answerText = getWordScrambleAnswer();
    const isComplete = scrambleState.slots.every(slot => slot.type === 'space' || slot.char);
    if (!answerText || !isComplete) return;

    hasAnswered = true;
    clearUrgencyEffects();
    stopClientTimer();
    disableChoices();

    const timeTaken = ((Date.now() - questionStartTime) / 1000).toFixed(2);

    fetch(BASE_URL + '/kahoot-api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=submit_answer&session_id=' + SESSION_ID + '&participant_id=' + PARTICIPANT_ID +
              '&question_id=' + questionId + '&answer_text=' + encodeURIComponent(answerText) + '&time_taken=' + timeTaken + '&csrf_token=' + CSRF
    }).then(r => r.json()).then(data => {
        if (data.already_answered) return;

        setTimeout(() => {
            document.getElementById('gamePlayView').style.display = 'none';
            document.getElementById('answerWaitScreen').style.display = 'flex';
        }, 300);

        if (data.stats) {
            document.getElementById('myScore').textContent = (data.stats.score || 0).toLocaleString();
            document.getElementById('myTotalScore').textContent = (data.stats.score || 0).toLocaleString();
        }
    }).catch(() => {});
}

function disableChoices() {
    document.querySelectorAll('.choice-btn, .scramble-slot, .scramble-tile, #scramblePanel .btn, #scramblePanel .btn-join').forEach(b => b.disabled = true);
}

function showFinalResults(data) {
    document.getElementById('waitingScreen').style.display = 'none';
    document.getElementById('gamePlayView').style.display = 'none';
    document.getElementById('answerWaitScreen').style.display = 'none';
    document.getElementById('resultOverlay').style.display = 'none';
    document.getElementById('finalScreen').style.display = 'flex';

    if (data.my_rank) document.getElementById('finalRank').textContent = '#' + data.my_rank;
    if (data.my_stats) {
        document.getElementById('finalScore').textContent = (data.my_stats.score || 0).toLocaleString();
        document.getElementById('finalCorrect').textContent = data.my_stats.correct_count || 0;
        const acc = TOTAL_Q > 0 ? Math.round((data.my_stats.correct_count || 0) / TOTAL_Q * 100) : 0;
        document.getElementById('finalAccuracy').textContent = acc + '%';
    }

    if (data.leaderboard) updateMiniLB(data.leaderboard, true);
}

function updateMiniLB(items, isFinal) {
    const container = document.getElementById(isFinal ? 'finalLB' : 'finalLB');
    if (!container) return;
    let html = '';
    items.forEach((p, i) => {
        const isMe = false;
        const name = p.nickname || (p.first_name + ' ' + p.last_name.charAt(0) + '.');
        html += '<div class="mini-lb-item ' + (p.id == PARTICIPANT_ID ? 'me' : '') + '">';
        html += '<div class="mini-lb-rank">' + (i + 1) + '</div>';
        html += '<div class="mini-lb-name">' + escHtml(name) + '</div>';
        html += '<div class="mini-lb-score">' + (p.score || 0).toLocaleString() + '</div>';
        html += '</div>';
    });
    container.innerHTML = html;
}

function escHtml(str) { const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }

setInterval(poll, 2000);
poll();
</script>
<?php endif; ?>

</body>
</html>
