<?php
require_once __DIR__ . '/helpers/functions.php';
requireRole('instructor', 'superadmin');
$pdo = getDB();
$user = currentUser();

$sessionId = intval($_GET['session_id'] ?? 0);
if (!$sessionId) { flash('error', 'No session ID.'); redirect('/kahoot-games.php'); }

$stmt = $pdo->prepare("SELECT s.*, g.title, g.time_limit, g.game_pin, g.show_leaderboard, g.game_mode,
    (SELECT COUNT(*) FROM kahoot_questions WHERE game_id = g.id) as total_questions,
    (SELECT COUNT(*) FROM kahoot_participants WHERE session_id = s.id) as participant_count
    FROM kahoot_sessions s JOIN kahoot_games g ON s.game_id = g.id
    WHERE s.id = ? AND s.host_id = ?");
$stmt->execute([$sessionId, $user['id']]);
$session = $stmt->fetch();

if (!$session) { flash('error', 'Session not found or access denied.'); redirect('/kahoot-games.php'); }

$pageTitle = 'Host: ' . $session['title'];
$breadcrumbPills = ['Teaching', 'Live Quiz', 'Host'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - ISCC LMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background: #0f0f23; color: #fff; min-height: 100vh; overflow-x: hidden; }

        .host-topbar { background: rgba(255,255,255,0.05); backdrop-filter: blur(10px); padding: 12px 24px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .host-topbar .game-title { font-size: 1.1rem; font-weight: 700; }
        .host-topbar .btn-group .btn { font-size: 0.85rem; }

        .host-container { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }

        .lobby-section { text-align: center; padding: 60px 20px; }
        .pin-display { font-size: 4rem; font-weight: 800; letter-spacing: 12px; color: #fff; background: linear-gradient(135deg, #7c3aed, #a78bfa); padding: 30px 60px; border-radius: 20px; display: inline-block; margin: 20px 0; box-shadow: 0 10px 40px rgba(124,58,237,0.3); }
        .pin-label { font-size: 1.2rem; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 3px; font-weight: 600; }
        .join-url { font-size: 0.9rem; color: rgba(255,255,255,0.5); margin-top: 10px; }
        .player-count { font-size: 3rem; font-weight: 800; color: #10b981; margin-top: 30px; }
        .player-grid { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin-top: 20px; max-width: 800px; margin-left: auto; margin-right: auto; }
        .player-chip { background: rgba(255,255,255,0.1); padding: 6px 14px; border-radius: 20px; font-size: 0.85rem; animation: popIn 0.3s ease; }

        .question-section { text-align: center; }
        .q-progress { font-size: 0.9rem; color: rgba(255,255,255,0.6); margin-bottom: 10px; }
        .q-text { font-size: 1.8rem; font-weight: 700; max-width: 800px; margin: 0 auto 30px; line-height: 1.3; }
        .q-image { max-width: 400px; max-height: 250px; border-radius: 12px; margin-bottom: 20px; }
        .timer-circle { width: 120px; height: 120px; border-radius: 50%; background: rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; font-size: 2.5rem; font-weight: 800; margin: 0 auto 30px; border: 4px solid #7c3aed; position: relative; }
        .timer-circle.urgent { border-color: #ef4444; color: #ef4444; animation: pulse 0.5s ease infinite; }
        .answer-count { font-size: 1rem; color: rgba(255,255,255,0.6); margin-top: 10px; }

        .dist-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; max-width: 700px; margin: 20px auto; }
        .dist-bar { padding: 16px 20px; border-radius: 12px; display: flex; align-items: center; gap: 12px; font-weight: 600; color: #fff; min-height: 60px; transition: all 0.3s; }
        .dist-bar .dist-icon { font-size: 1.2rem; width: 30px; text-align: center; }
        .dist-bar .dist-text { flex: 1; text-align: left; font-size: 0.9rem; }
        .dist-bar .dist-count { font-size: 1.4rem; font-weight: 800; }
        .dist-bar.correct { box-shadow: 0 0 0 3px #10b981, 0 0 20px rgba(16,185,129,0.3); }
        .dist-a { background: #e21b3c; }
        .dist-b { background: #1368ce; }
        .dist-c { background: #d89e00; }
        .dist-d { background: #26890c; }
        .scramble-review-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; max-width: 700px; margin: 20px auto; }
        .scramble-review-card { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); border-radius: 14px; padding: 18px 16px; text-align: center; }
        .scramble-review-card strong { display: block; font-size: 2rem; font-weight: 800; margin-bottom: 6px; }
        .scramble-answer-pill { display: inline-flex; align-items: center; gap: 8px; margin-top: 18px; padding: 10px 16px; border-radius: 999px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); font-weight: 600; }

        .leaderboard { max-width: 600px; margin: 0 auto; }
        .lb-item { display: flex; align-items: center; gap: 16px; padding: 12px 20px; background: rgba(255,255,255,0.05); border-radius: 12px; margin-bottom: 8px; animation: slideUp 0.3s ease; }
        .lb-rank { font-size: 1.4rem; font-weight: 800; width: 40px; text-align: center; }
        .lb-rank.gold { color: #fbbf24; }
        .lb-rank.silver { color: #94a3b8; }
        .lb-rank.bronze { color: #cd7f32; }
        .lb-name { flex: 1; font-weight: 600; }
        .lb-score { font-size: 1.2rem; font-weight: 700; color: #a78bfa; }
        .lb-streak { font-size: 0.8rem; color: #f59e0b; }

        .podium { display: flex; align-items: flex-end; justify-content: center; gap: 12px; margin: 40px auto; max-width: 500px; }
        .podium-item { text-align: center; border-radius: 12px 12px 0 0; padding: 20px 16px; min-width: 120px; }
        .podium-1 { background: linear-gradient(to top, #fbbf24, #f59e0b); height: 200px; }
        .podium-2 { background: linear-gradient(to top, #94a3b8, #64748b); height: 150px; }
        .podium-3 { background: linear-gradient(to top, #cd7f32, #a0522d); height: 120px; }
        .podium-name { font-weight: 700; font-size: 0.9rem; margin-top: 8px; }
        .podium-score { font-size: 1.2rem; font-weight: 800; }
        .podium-medal { font-size: 2rem; }

        @keyframes popIn { 0% { transform: scale(0); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
        @keyframes slideUp { 0% { transform: translateY(20px); opacity: 0; } 100% { transform: translateY(0); opacity: 1; } }
        @keyframes pulse { 0%,100% { transform: scale(1); } 50% { transform: scale(1.05); } }

        .btn-kahoot { background: linear-gradient(135deg, #7c3aed, #6d28d9); color: #fff; border: none; padding: 12px 36px; font-size: 1.1rem; font-weight: 700; border-radius: 12px; }
        .btn-kahoot:hover { background: linear-gradient(135deg, #6d28d9, #5b21b6); color: #fff; }
        .btn-danger-glow { box-shadow: 0 0 20px rgba(239,68,68,0.3); }
    </style>
</head>
<body>

<div class="host-topbar">
    <div class="d-flex align-items-center gap-3">
        <a href="<?= BASE_URL ?>/kahoot-games.php" class="btn btn-sm btn-outline-light"><i class="fas fa-arrow-left"></i></a>
        <span class="game-title"><i class="fas fa-gamepad me-2"></i><?= e($session['title']) ?></span>
        <span class="badge bg-info" id="sessionStatusBadge"><?= ucfirst($session['status']) ?></span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-muted" style="font-size:0.8rem;" id="playerCountTop">
            <i class="fas fa-users me-1"></i><span id="playerNumTop"><?= $session['participant_count'] ?></span> players
        </span>
        <button class="btn btn-sm btn-danger btn-danger-glow" id="endGameBtn" onclick="endGame()">
            <i class="fas fa-stop me-1"></i>End Game
        </button>
    </div>
</div>

<div class="host-container">
    <div id="lobbyView" style="display:<?= $session['status'] === 'lobby' ? 'block' : 'none' ?>;">
        <div class="lobby-section">
            <div class="pin-label">Game PIN</div>
            <div class="pin-display"><?= e($session['game_pin']) ?></div>
            <div class="join-url">Join at: <strong><?= $_SERVER['HTTP_HOST'] ?><?= BASE_URL ?>/kahoot-play.php</strong></div>

            <div class="player-count"><span id="playerCount">0</span></div>
            <div class="text-muted mb-3">players joined</div>

            <div class="player-grid" id="playerGrid"></div>

            <button class="btn btn-kahoot mt-4" id="startGameBtn" onclick="startGame()" disabled>
                <i class="fas fa-play me-2"></i>Start Game
            </button>
        </div>
    </div>

    <div id="questionView" style="display:none;">
        <div class="question-section">
            <div class="q-progress">Question <span id="qNum">1</span> of <span id="qTotal"><?= $session['total_questions'] ?></span></div>
            <div class="timer-circle" id="timerCircle"><span id="timerVal">20</span></div>
            <div id="qImage"></div>
            <div class="q-text" id="qText">Loading...</div>
            <div class="answer-count">
                <i class="fas fa-check-circle me-1"></i><span id="answeredCount">0</span> / <span id="totalPlayers">0</span> answered
            </div>

            <div class="dist-grid" id="distGrid" style="display:none;"></div>

            <div class="mt-4">
                <button class="btn btn-kahoot" id="showResultsBtn" onclick="showReview()" style="display:none;">
                    <i class="fas fa-chart-bar me-2"></i>Show Results
                </button>
                <button class="btn btn-kahoot" id="nextQuestionBtn" onclick="nextQuestion()" style="display:none;">
                    <i class="fas fa-arrow-right me-2"></i>Next Question
                </button>
            </div>
        </div>
    </div>

    <div id="leaderboardView" style="display:none;">
        <h3 class="text-center fw-bold mb-4"><i class="fas fa-trophy me-2" style="color:#fbbf24;"></i>Leaderboard</h3>
        <div class="leaderboard" id="leaderboardList"></div>
    </div>

    <div id="finishedView" style="display:none;">
        <div class="text-center">
            <h2 class="fw-bold mb-1"><i class="fas fa-trophy me-2" style="color:#fbbf24;"></i>Game Over!</h2>
            <p class="text-muted mb-4">Final Results</p>
            <div class="podium" id="podium"></div>
            <div class="leaderboard mt-4" id="finalLeaderboard"></div>
            <div class="mt-4 d-flex gap-2 justify-content-center">
                <a href="<?= BASE_URL ?>/kahoot-api.php?action=export_csv&session_id=<?= $sessionId ?>" class="btn btn-outline-success">
                    <i class="fas fa-file-csv me-1"></i>Export CSV
                </a>
                <a href="<?= BASE_URL ?>/kahoot-results.php?session_id=<?= $sessionId ?>" class="btn btn-outline-info">
                    <i class="fas fa-chart-bar me-1"></i>Detailed Results
                </a>
                <a href="<?= BASE_URL ?>/kahoot-games.php" class="btn btn-outline-light">
                    <i class="fas fa-home me-1"></i>Back to Games
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const BASE_URL = '<?= BASE_URL ?>';
const CSRF = '<?= csrf_token() ?>';
const SESSION_ID = <?= $sessionId ?>;
const TOTAL_QUESTIONS = <?= $session['total_questions'] ?>;
const TIME_LIMIT = <?= $session['time_limit'] ?>;

let currentState = '<?= $session['status'] ?>';
let timerInterval = null;
let pollInterval = null;
let currentTimer = TIME_LIMIT;

function poll() {
    if (currentState === 'lobby') {
        fetch(BASE_URL + '/kahoot-api.php?action=lobby_state&session_id=' + SESSION_ID)
            .then(r => r.json())
            .then(data => {
                if (data.status === 'playing') {
                    currentState = 'playing';
                    showQuestionView();
                    pollQuestionResults();
                    return;
                }
                updateLobby(data);
            })
            .catch(() => {});
    } else if (currentState === 'playing' || currentState === 'reviewing') {
        pollQuestionResults();
    }
}

function updateLobby(data) {
    const count = data.participant_count || 0;
    document.getElementById('playerCount').textContent = count;
    document.getElementById('playerNumTop').textContent = count;
    document.getElementById('startGameBtn').disabled = count < 1;

    const grid = document.getElementById('playerGrid');
    grid.innerHTML = '';
    (data.participants || []).forEach(p => {
        const chip = document.createElement('div');
        chip.className = 'player-chip';
        chip.textContent = p.nickname || (p.first_name + ' ' + p.last_name.charAt(0) + '.');
        grid.appendChild(chip);
    });
}

function startGame() {
    fetch(BASE_URL + '/kahoot-api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=start_game&session_id=' + SESSION_ID + '&csrf_token=' + CSRF
    }).then(r => r.json()).then(data => {
        if (data.success) {
            currentState = 'playing';
            showQuestionView();
            loadQuestion();
        }
    });
}

function showQuestionView() {
    document.getElementById('lobbyView').style.display = 'none';
    document.getElementById('questionView').style.display = 'block';
    document.getElementById('leaderboardView').style.display = 'none';
    document.getElementById('finishedView').style.display = 'none';
    document.getElementById('sessionStatusBadge').textContent = 'Playing';
    document.getElementById('sessionStatusBadge').className = 'badge bg-danger';
}

function loadQuestion() {
    fetch(BASE_URL + '/kahoot-api.php?action=question_results&session_id=' + SESSION_ID)
        .then(r => r.json())
        .then(data => {
            if (data.error) return;
            const q = data.question;
            document.getElementById('qNum').textContent = q.question_order || '?';
            document.getElementById('qTotal').textContent = TOTAL_QUESTIONS;
            document.getElementById('qText').textContent = q.question_text;
            document.getElementById('totalPlayers').textContent = data.total_participants;
            document.getElementById('answeredCount').textContent = data.answered_count;

            if (q.question_image) {
                document.getElementById('qImage').innerHTML = '<img src="' + BASE_URL + '/' + q.question_image + '" class="q-image">';
            } else {
                document.getElementById('qImage').innerHTML = '';
            }
            renderQuestionResults(data);

            const timeLimit = q.time_limit || TIME_LIMIT;
            startTimer(timeLimit);

            document.getElementById('showResultsBtn').style.display = 'none';
            document.getElementById('nextQuestionBtn').style.display = 'none';
            document.getElementById('distGrid').style.display = 'none';
        });
}

function startTimer(seconds) {
    clearInterval(timerInterval);
    currentTimer = seconds;
    const circle = document.getElementById('timerCircle');
    const val = document.getElementById('timerVal');

    timerInterval = setInterval(() => {
        currentTimer--;
        val.textContent = Math.max(0, currentTimer);

        if (currentTimer <= 5) {
            circle.classList.add('urgent');
        } else {
            circle.classList.remove('urgent');
        }

        if (currentTimer <= 0) {
            clearInterval(timerInterval);
            showReview();
        }
    }, 1000);
}

let autoAdvanceTimeout = null;

function showReview(manual) {
    fetch(BASE_URL + '/kahoot-api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=show_review&session_id=' + SESSION_ID + '&csrf_token=' + CSRF
    }).then(r => r.json()).then(data => {
        currentState = 'reviewing';
        clearInterval(timerInterval);
        document.getElementById('showResultsBtn').style.display = 'none';
        document.getElementById('distGrid').style.display = 'grid';
        document.getElementById('leaderboardView').style.display = 'block';

        const qNum = parseInt(document.getElementById('qNum').textContent);
        if (qNum >= TOTAL_QUESTIONS) {
            document.getElementById('nextQuestionBtn').textContent = '  Finish Game';
            document.getElementById('nextQuestionBtn').innerHTML = '<i class="fas fa-flag-checkered me-2"></i>Finish Game';
        }
        document.getElementById('nextQuestionBtn').style.display = 'inline-block';

        pollQuestionResults();

        if (autoAdvanceTimeout) clearTimeout(autoAdvanceTimeout);
        autoAdvanceTimeout = setTimeout(() => {
            if (currentState === 'reviewing') {
                nextQuestion();
            }
        }, 5000);
    });
}

function nextQuestion() {
    if (autoAdvanceTimeout) { clearTimeout(autoAdvanceTimeout); autoAdvanceTimeout = null; }
    fetch(BASE_URL + '/kahoot-api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=next_question&session_id=' + SESSION_ID + '&csrf_token=' + CSRF
    }).then(r => r.json()).then(data => {
        if (data.finished) {
            currentState = 'finished';
            showFinished();
        } else {
            currentState = 'playing';
            document.getElementById('leaderboardView').style.display = 'none';
            loadQuestion();
        }
    });
}

function pollQuestionResults() {
    fetch(BASE_URL + '/kahoot-api.php?action=question_results&session_id=' + SESSION_ID)
        .then(r => r.json())
        .then(data => {
            if (data.error) return;
            document.getElementById('answeredCount').textContent = data.answered_count;
            document.getElementById('totalPlayers').textContent = data.total_participants;
            document.getElementById('playerNumTop').textContent = data.total_participants;

            if (currentState === 'reviewing') {
                renderQuestionResults(data);
            }

            if (data.answered_count >= data.total_participants && currentTimer > 0 && currentState === 'playing') {
                clearInterval(timerInterval);
                showReview();
            }

            updateLeaderboard(data.leaderboard || [], 'leaderboardList');
        });
}

function renderQuestionResults(data) {
    const container = document.getElementById('distGrid');
    const question = data.question || {};

    if ((question.question_type || 'multiple_choice') === 'word_scramble') {
        const stats = data.answer_stats || {
            correct: 0,
            wrong: 0,
            unanswered: Math.max(0, (data.total_participants || 0) - (data.answered_count || 0))
        };
        container.className = 'scramble-review-grid';
        container.innerHTML =
            '<div class="scramble-review-card"><strong style="color:#10b981;">' + (stats.correct || 0) + '</strong>Correct</div>' +
            '<div class="scramble-review-card"><strong style="color:#f59e0b;">' + (stats.wrong || 0) + '</strong>Wrong</div>' +
            '<div class="scramble-review-card"><strong style="color:#94a3b8;">' + (stats.unanswered || 0) + '</strong>No Answer</div>' +
            '<div style="grid-column:1 / -1;text-align:center;"><span class="scramble-answer-pill"><i class="fas fa-puzzle-piece"></i>Correct Answer: ' + escHtml(question.correct_answer || '') + '</span></div>';
        return;
    }

    container.className = 'dist-grid';
    let gridHtml = '';
    const labels = ['A','B','C','D'];
    const classes = ['dist-a','dist-b','dist-c','dist-d'];
    const icons = ['fa-diamond','fa-circle','fa-square','fa-star'];
    (data.distribution || []).forEach((d, i) => {
        const correct = d.is_correct ? ' correct' : '';
        gridHtml += '<div class="dist-bar ' + classes[i] + correct + '">';
        gridHtml += '<div class="dist-icon"><i class="fas ' + icons[i] + '"></i></div>';
        gridHtml += '<div class="dist-text">' + escHtml(d.choice_text || labels[i]) + '</div>';
        gridHtml += '<div class="dist-count">' + (d.count || 0) + '</div>';
        gridHtml += '</div>';
    });
    container.innerHTML = gridHtml;
}

function updateLeaderboard(items, containerId) {
    const container = document.getElementById(containerId);
    let html = '';
    items.forEach((p, i) => {
        const rankClass = i === 0 ? 'gold' : (i === 1 ? 'silver' : (i === 2 ? 'bronze' : ''));
        const name = p.nickname || (p.first_name + ' ' + p.last_name.charAt(0) + '.');
        html += '<div class="lb-item" style="animation-delay:' + (i * 0.05) + 's">';
        html += '<div class="lb-rank ' + rankClass + '">' + (i + 1) + '</div>';
        html += '<div class="lb-name">' + escHtml(name) + '</div>';
        if (p.streak > 1) html += '<div class="lb-streak"><i class="fas fa-fire"></i> ' + p.streak + '</div>';
        html += '<div class="lb-score">' + (p.score || 0).toLocaleString() + '</div>';
        html += '</div>';
    });
    container.innerHTML = html;
}

function showFinished() {
    document.getElementById('lobbyView').style.display = 'none';
    document.getElementById('questionView').style.display = 'none';
    document.getElementById('leaderboardView').style.display = 'none';
    document.getElementById('finishedView').style.display = 'block';
    document.getElementById('sessionStatusBadge').textContent = 'Finished';
    document.getElementById('sessionStatusBadge').className = 'badge bg-success';

    fetch(BASE_URL + '/kahoot-api.php?action=final_results&session_id=' + SESSION_ID)
        .then(r => r.json())
        .then(data => {
            const parts = data.participants || [];

            const podium = document.getElementById('podium');
            const medals = ['🥇','🥈','🥉'];
            const pClasses = ['podium-1','podium-2','podium-3'];
            const order = [1, 0, 2];
            let podiumHtml = '';
            order.forEach(idx => {
                if (parts[idx]) {
                    const p = parts[idx];
                    const name = p.nickname || (p.first_name + ' ' + p.last_name.charAt(0) + '.');
                    podiumHtml += '<div class="podium-item ' + pClasses[idx] + '">';
                    podiumHtml += '<div class="podium-medal">' + medals[idx] + '</div>';
                    podiumHtml += '<div class="podium-name">' + escHtml(name) + '</div>';
                    podiumHtml += '<div class="podium-score">' + (p.score || 0).toLocaleString() + '</div>';
                    podiumHtml += '</div>';
                }
            });
            podium.innerHTML = podiumHtml;

            updateLeaderboard(parts, 'finalLeaderboard');
        });
}

function endGame() {
    if (!confirm('End this game now? All progress will be saved.')) return;
    fetch(BASE_URL + '/kahoot-api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=end_game&session_id=' + SESSION_ID + '&csrf_token=' + CSRF
    }).then(r => r.json()).then(data => {
        if (data.success) {
            currentState = 'finished';
            showFinished();
        }
    });
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

if (currentState === 'playing' || currentState === 'reviewing') {
    showQuestionView();
    loadQuestion();
    if (currentState === 'reviewing') {
        document.getElementById('distGrid').style.display = 'grid';
        document.getElementById('leaderboardView').style.display = 'block';
        document.getElementById('nextQuestionBtn').style.display = 'inline-block';
    }
} else if (currentState === 'finished') {
    showFinished();
}

pollInterval = setInterval(poll, 2000);
poll();
</script>
</body>
</html>
