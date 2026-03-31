<?php
require_once __DIR__ . '/helpers/functions.php';
requireLogin();
$pdo  = getDB();
$user = currentUser();
$role = $user['role'];

header('Content-Type: application/json');

try { $pdo->query("SELECT 1 FROM kahoot_games LIMIT 1"); } catch (Exception $e) {
    jsonResponse(['error' => 'Please run kahoot-install.php first'], 500);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function normalizeKahootAnswer(string $value): string {
    $value = strtoupper(trim($value));
    return preg_replace('/\s+/', ' ', $value);
}

function buildWordScramblePayload(string $answer): array {
    $normalized = normalizeKahootAnswer($answer);
    $letters = [];
    $slots = [];

    if ($normalized !== '') {
        foreach (preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            if ($char === ' ') {
                $slots[] = 'space';
                continue;
            }
            $slots[] = 'letter';
            $letters[] = $char;
        }
        shuffle($letters);
    }

    return [
        'letters' => $letters,
        'slots' => $slots,
    ];
}


if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    switch ($action) {

        case 'check_pin':
            $pin = trim($_GET['pin'] ?? '');
            if (strlen($pin) !== 6) jsonResponse(['valid' => false, 'error' => 'Invalid PIN format']);

            $stmt = $pdo->prepare("SELECT g.id, g.title, g.game_mode, s.id as session_id, s.status as session_status
                FROM kahoot_games g
                JOIN kahoot_sessions s ON s.game_id = g.id
                WHERE g.game_pin = ? AND s.status IN ('lobby','playing')
                ORDER BY s.id DESC LIMIT 1");
            $stmt->execute([$pin]);
            $game = $stmt->fetch();

            if ($game) {
                $chk = $pdo->prepare("SELECT id FROM kahoot_participants WHERE session_id = ? AND user_id = ?");
                $chk->execute([$game['session_id'], $user['id']]);
                $already = $chk->fetch();

                jsonResponse([
                    'valid' => true,
                    'game_id' => $game['id'],
                    'session_id' => $game['session_id'],
                    'title' => $game['title'],
                    'game_mode' => $game['game_mode'],
                    'session_status' => $game['session_status'],
                    'already_joined' => (bool)$already
                ]);
            } else {
                jsonResponse(['valid' => false, 'error' => 'Game not found or not active']);
            }
            break;

        case 'lobby_state':
            $sessionId = intval($_GET['session_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT s.*, g.title, g.time_limit,
                (SELECT COUNT(*) FROM kahoot_questions WHERE game_id = g.id) as total_questions
                FROM kahoot_sessions s JOIN kahoot_games g ON s.game_id = g.id WHERE s.id = ?");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch();
            if (!$session) jsonResponse(['error' => 'Session not found'], 404);

            $parts = $pdo->prepare("SELECT kp.*, u.first_name, u.last_name, u.username
                FROM kahoot_participants kp JOIN users u ON kp.user_id = u.id
                WHERE kp.session_id = ? ORDER BY kp.joined_at DESC");
            $parts->execute([$sessionId]);

            jsonResponse([
                'status' => $session['status'],
                'current_question' => intval($session['current_question']),
                'total_questions' => intval($session['total_questions']),
                'participants' => $parts->fetchAll(),
                'participant_count' => $parts->rowCount()
            ]);
            break;

        case 'game_state':
            $sessionId = intval($_GET['session_id'] ?? 0);
            $participantId = intval($_GET['participant_id'] ?? 0);

            $stmt = $pdo->prepare("SELECT s.status, s.current_question, s.question_started_at,
                g.time_limit as game_time_limit, g.title, g.shuffle_choices,
                (SELECT COUNT(*) FROM kahoot_questions WHERE game_id = g.id) as total_questions,
                TIMESTAMPDIFF(SECOND, s.question_started_at, NOW()) as elapsed_seconds
                FROM kahoot_sessions s JOIN kahoot_games g ON s.game_id = g.id WHERE s.id = ?");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch();
            if (!$session) jsonResponse(['error' => 'Session not found'], 404);

            $result = [
                'status' => $session['status'],
                'current_question' => intval($session['current_question']),
                'total_questions' => intval($session['total_questions']),
                'title' => $session['title']
            ];

            if ($session['status'] === 'playing' && $session['current_question'] > 0) {
                $qStmt = $pdo->prepare("SELECT q.* FROM kahoot_questions q
                    WHERE q.game_id = (SELECT game_id FROM kahoot_sessions WHERE id = ?)
                    ORDER BY q.question_order ASC LIMIT 1 OFFSET ?");
                $offset = $session['current_question'] - 1;
                $qStmt->execute([$sessionId, $offset]);
                $question = $qStmt->fetch();

                if ($question) {
                    $questionType = $question['question_type'] ?? 'multiple_choice';
                    $timeLimit = $question['time_limit'] ?? $session['game_time_limit'];
                    $elapsed = intval($session['elapsed_seconds'] ?? 0);
                    $remaining = max(0, $timeLimit - $elapsed);

                    $questionPayload = [
                        'id' => $question['id'],
                        'text' => $question['question_text'],
                        'image' => $question['question_image'],
                        'question_type' => $questionType,
                        'points' => $question['points'],
                        'time_limit' => intval($timeLimit),
                        'time_remaining' => intval($remaining)
                    ];

                    if ($questionType === 'word_scramble') {
                        $aStmt = $pdo->prepare("SELECT answer_text FROM kahoot_answers WHERE session_id = ? AND participant_id = ? AND question_id = ?");
                        $aStmt->execute([$sessionId, $participantId, $question['id']]);
                        $existingAnswer = $aStmt->fetch();
                        $scramble = buildWordScramblePayload($question['correct_answer'] ?? '');

                        $questionPayload['scramble_letters'] = $scramble['letters'];
                        $questionPayload['answer_slots'] = $scramble['slots'];
                        $questionPayload['already_answered'] = (bool)$existingAnswer;
                        $questionPayload['answered_text'] = $existingAnswer['answer_text'] ?? '';
                    } else {
                        $cStmt = $pdo->prepare("SELECT id, choice_label, choice_text FROM kahoot_choices WHERE question_id = ? ORDER BY choice_label");
                        $cStmt->execute([$question['id']]);
                        $choices = $cStmt->fetchAll();

                        $aStmt = $pdo->prepare("SELECT choice_id FROM kahoot_answers WHERE session_id = ? AND participant_id = ? AND question_id = ?");
                        $aStmt->execute([$sessionId, $participantId, $question['id']]);
                        $existingAnswer = $aStmt->fetch();

                        $questionPayload['choices'] = $choices;
                        $questionPayload['already_answered'] = (bool)$existingAnswer;
                        $questionPayload['answered_choice'] = $existingAnswer ? intval($existingAnswer['choice_id']) : null;
                    }

                    $result['question'] = $questionPayload;
                }
            }

            if ($session['status'] === 'reviewing') {
                $qStmt = $pdo->prepare("SELECT q.* FROM kahoot_questions q
                    WHERE q.game_id = (SELECT game_id FROM kahoot_sessions WHERE id = ?)
                    ORDER BY q.question_order ASC LIMIT 1 OFFSET ?");
                $offset = $session['current_question'] - 1;
                $qStmt->execute([$sessionId, $offset]);
                $question = $qStmt->fetch();

                if ($question) {
                    $questionType = $question['question_type'] ?? 'multiple_choice';
                    if ($questionType === 'word_scramble') {
                        $statsStmt = $pdo->prepare("SELECT
                                SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct_count,
                                SUM(CASE WHEN is_correct = 0 THEN 1 ELSE 0 END) as wrong_count,
                                COUNT(*) as answered_count
                            FROM kahoot_answers
                            WHERE session_id = ? AND question_id = ?");
                        $statsStmt->execute([$sessionId, $question['id']]);
                        $stats = $statsStmt->fetch() ?: [];

                        $partCountStmt = $pdo->prepare("SELECT COUNT(*) FROM kahoot_participants WHERE session_id = ?");
                        $partCountStmt->execute([$sessionId]);
                        $participantCount = (int)$partCountStmt->fetchColumn();

                        $myAns = $pdo->prepare("SELECT ka.answer_text, ka.is_correct, ka.points_earned
                            FROM kahoot_answers ka WHERE ka.session_id = ? AND ka.participant_id = ? AND ka.question_id = ?");
                        $myAns->execute([$sessionId, $participantId, $question['id']]);

                        $answeredCount = (int)($stats['answered_count'] ?? 0);
                        $result['review'] = [
                            'question_type' => 'word_scramble',
                            'question_text' => $question['question_text'],
                            'correct_answer' => $question['correct_answer'],
                            'answer_stats' => [
                                'correct' => (int)($stats['correct_count'] ?? 0),
                                'wrong' => (int)($stats['wrong_count'] ?? 0),
                                'answered' => $answeredCount,
                                'unanswered' => max(0, $participantCount - $answeredCount),
                            ],
                            'my_answer' => $myAns->fetch() ?: null
                        ];
                    } else {
                        $cStmt = $pdo->prepare("SELECT id, choice_label, choice_text, is_correct FROM kahoot_choices WHERE question_id = ? ORDER BY choice_label");
                        $cStmt->execute([$question['id']]);

                        $distStmt = $pdo->prepare("SELECT c.choice_label, COUNT(a.id) as count
                            FROM kahoot_choices c LEFT JOIN kahoot_answers a ON a.choice_id = c.id AND a.session_id = ?
                            WHERE c.question_id = ? GROUP BY c.id, c.choice_label ORDER BY c.choice_label");
                        $distStmt->execute([$sessionId, $question['id']]);

                        $myAns = $pdo->prepare("SELECT ka.choice_id, ka.is_correct, ka.points_earned
                            FROM kahoot_answers ka WHERE ka.session_id = ? AND ka.participant_id = ? AND ka.question_id = ?");
                        $myAns->execute([$sessionId, $participantId, $question['id']]);

                        $result['review'] = [
                            'question_type' => 'multiple_choice',
                            'question_text' => $question['question_text'],
                            'choices' => $cStmt->fetchAll(),
                            'distribution' => $distStmt->fetchAll(),
                            'my_answer' => $myAns->fetch() ?: null
                        ];
                    }
                }
            }

            $lbStmt = $pdo->prepare("SELECT kp.id, kp.nickname, kp.score, kp.correct_count, kp.streak,
                u.first_name, u.last_name
                FROM kahoot_participants kp JOIN users u ON kp.user_id = u.id
                WHERE kp.session_id = ? ORDER BY kp.score DESC LIMIT 10");
            $lbStmt->execute([$sessionId]);
            $result['leaderboard'] = $lbStmt->fetchAll();

            if ($participantId) {
                $myScore = $pdo->prepare("SELECT score, correct_count, streak FROM kahoot_participants WHERE id = ?");
                $myScore->execute([$participantId]);
                $result['my_stats'] = $myScore->fetch();

                $rankStmt = $pdo->prepare("SELECT COUNT(*) + 1 as rank FROM kahoot_participants WHERE session_id = ? AND score > (SELECT score FROM kahoot_participants WHERE id = ?)");
                $rankStmt->execute([$sessionId, $participantId]);
                $result['my_rank'] = intval($rankStmt->fetchColumn());
            }

            jsonResponse($result);
            break;

        case 'question_results':
            $sessionId = intval($_GET['session_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT s.current_question, g.id as game_id
                FROM kahoot_sessions s JOIN kahoot_games g ON s.game_id = g.id WHERE s.id = ? AND s.host_id = ?");
            $stmt->execute([$sessionId, $user['id']]);
            $session = $stmt->fetch();
            if (!$session) jsonResponse(['error' => 'Access denied'], 403);

            $qStmt = $pdo->prepare("SELECT q.* FROM kahoot_questions q WHERE q.game_id = ? ORDER BY q.question_order ASC LIMIT 1 OFFSET ?");
            $offset = $session['current_question'] - 1;
            $qStmt->execute([$session['game_id'], $offset]);
            $q = $qStmt->fetch();

            if (!$q) jsonResponse(['error' => 'No question found']);

            $totalParticipants = $pdo->prepare("SELECT COUNT(*) FROM kahoot_participants WHERE session_id = ?");
            $totalParticipants->execute([$sessionId]);
            $totalP = $totalParticipants->fetchColumn();

            $answeredCount = $pdo->prepare("SELECT COUNT(*) FROM kahoot_answers WHERE session_id = ? AND question_id = ?");
            $answeredCount->execute([$sessionId, $q['id']]);
            $answered = $answeredCount->fetchColumn();
            $choices = [];
            $distribution = [];
            $answerStats = null;

            if (($q['question_type'] ?? 'multiple_choice') === 'word_scramble') {
                $statsStmt = $pdo->prepare("SELECT
                        SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct_count,
                        SUM(CASE WHEN is_correct = 0 THEN 1 ELSE 0 END) as wrong_count,
                        COUNT(*) as answered_count
                    FROM kahoot_answers
                    WHERE session_id = ? AND question_id = ?");
                $statsStmt->execute([$sessionId, $q['id']]);
                $stats = $statsStmt->fetch() ?: [];
                $answerStats = [
                    'correct' => (int)($stats['correct_count'] ?? 0),
                    'wrong' => (int)($stats['wrong_count'] ?? 0),
                    'answered' => (int)($stats['answered_count'] ?? 0),
                    'unanswered' => max(0, (int)$totalP - (int)($stats['answered_count'] ?? 0)),
                ];
            } else {
                $cStmt = $pdo->prepare("SELECT * FROM kahoot_choices WHERE question_id = ? ORDER BY choice_label");
                $cStmt->execute([$q['id']]);
                $choices = $cStmt->fetchAll();

                $distStmt = $pdo->prepare("SELECT c.choice_label, c.choice_text, c.is_correct, COUNT(a.id) as count
                    FROM kahoot_choices c LEFT JOIN kahoot_answers a ON a.choice_id = c.id AND a.session_id = ?
                    WHERE c.question_id = ? GROUP BY c.id ORDER BY c.choice_label");
                $distStmt->execute([$sessionId, $q['id']]);
                $distribution = $distStmt->fetchAll();
            }

            $lbStmt = $pdo->prepare("SELECT kp.id, kp.nickname, kp.score, kp.correct_count, kp.streak,
                u.first_name, u.last_name
                FROM kahoot_participants kp JOIN users u ON kp.user_id = u.id
                WHERE kp.session_id = ? ORDER BY kp.score DESC LIMIT 10");
            $lbStmt->execute([$sessionId]);

            jsonResponse([
                'question' => $q,
                'choices' => $choices,
                'distribution' => $distribution,
                'answer_stats' => $answerStats,
                'leaderboard' => $lbStmt->fetchAll(),
                'total_participants' => intval($totalP),
                'answered_count' => intval($answered)
            ]);
            break;

        case 'final_results':
            $sessionId = intval($_GET['session_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT s.*, g.title,
                (SELECT COUNT(*) FROM kahoot_questions WHERE game_id = g.id) as total_questions
                FROM kahoot_sessions s JOIN kahoot_games g ON s.game_id = g.id WHERE s.id = ?");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch();
            if (!$session) jsonResponse(['error' => 'Session not found'], 404);

            $participants = $pdo->prepare("SELECT kp.*, u.first_name, u.last_name, u.username,
                ROUND(kp.correct_count * 100.0 / NULLIF(?, 0), 1) as accuracy
                FROM kahoot_participants kp JOIN users u ON kp.user_id = u.id
                WHERE kp.session_id = ? ORDER BY kp.score DESC");
            $participants->execute([$session['total_questions'], $sessionId]);

            jsonResponse([
                'session' => $session,
                'participants' => $participants->fetchAll(),
                'total_questions' => intval($session['total_questions'])
            ]);
            break;

        case 'export_csv':
            $sessionId = intval($_GET['session_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT s.*, g.title FROM kahoot_sessions s JOIN kahoot_games g ON s.game_id = g.id WHERE s.id = ? AND s.host_id = ?");
            $stmt->execute([$sessionId, $user['id']]);
            $session = $stmt->fetch();
            if (!$session) { http_response_code(403); exit('Access denied'); }

            $totalQ = $pdo->prepare("SELECT COUNT(*) FROM kahoot_questions WHERE game_id = ?");
            $totalQ->execute([$session['game_id']]);
            $totalQuestions = $totalQ->fetchColumn();

            $participants = $pdo->prepare("SELECT kp.*, u.first_name, u.last_name, u.username
                FROM kahoot_participants kp JOIN users u ON kp.user_id = u.id
                WHERE kp.session_id = ? ORDER BY kp.score DESC");
            $participants->execute([$sessionId]);

            $filename = 'kahoot_' . preg_replace('/[^a-z0-9]/i', '_', $session['title']) . '_' . date('Y-m-d') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ISCC LMS - Kahoot Live Quiz Results']);
            fputcsv($out, ['Game:', $session['title']]);
            fputcsv($out, ['Date:', date('F j, Y g:i A', strtotime($session['started_at'] ?? $session['created_at']))]);
            fputcsv($out, []);
            fputcsv($out, ['Rank', 'Name', 'Username', 'Score', 'Correct', 'Total Questions', 'Accuracy %']);
            $rank = 0;
            foreach ($participants->fetchAll() as $p) {
                $rank++;
                $accuracy = $totalQuestions > 0 ? round($p['correct_count'] / $totalQuestions * 100, 1) : 0;
                fputcsv($out, [$rank, $p['first_name'] . ' ' . $p['last_name'], $p['username'], $p['score'], $p['correct_count'], $totalQuestions, $accuracy]);
            }
            fclose($out);
            exit;

        default:
            jsonResponse(['error' => 'Unknown action'], 400);
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) jsonResponse(['error' => 'Invalid CSRF token'], 403);

    switch ($action) {

        case 'join_game':
            $sessionId = intval($_POST['session_id'] ?? 0);
            $nickname = trim($_POST['nickname'] ?? ($user['first_name'] . ' ' . substr($user['last_name'], 0, 1) . '.'));

            $sess = $pdo->prepare("SELECT s.status FROM kahoot_sessions s WHERE s.id = ?");
            $sess->execute([$sessionId]);
            $session = $sess->fetch();
            if (!$session) jsonResponse(['error' => 'Session not found'], 404);
            if (!in_array($session['status'], ['lobby', 'playing'])) jsonResponse(['error' => 'Game is not accepting players'], 400);

            $chk = $pdo->prepare("SELECT id FROM kahoot_participants WHERE session_id = ? AND user_id = ?");
            $chk->execute([$sessionId, $user['id']]);
            $existing = $chk->fetch();

            if ($existing) {
                jsonResponse(['success' => true, 'participant_id' => $existing['id'], 'message' => 'Already joined']);
            }

            $stmt = $pdo->prepare("INSERT INTO kahoot_participants (session_id, user_id, nickname) VALUES (?, ?, ?)");
            $stmt->execute([$sessionId, $user['id'], $nickname]);
            $participantId = $pdo->lastInsertId();

            jsonResponse(['success' => true, 'participant_id' => intval($participantId)]);
            break;

        case 'submit_answer':
            $sessionId = intval($_POST['session_id'] ?? 0);
            $participantId = intval($_POST['participant_id'] ?? 0);
            $questionId = intval($_POST['question_id'] ?? 0);
            $choiceId = intval($_POST['choice_id'] ?? 0);
            $answerText = normalizeKahootAnswer($_POST['answer_text'] ?? '');
            $timeTaken = floatval($_POST['time_taken'] ?? 0);

            $pChk = $pdo->prepare("SELECT id FROM kahoot_participants WHERE id = ? AND user_id = ? AND session_id = ?");
            $pChk->execute([$participantId, $user['id'], $sessionId]);
            if (!$pChk->fetch()) jsonResponse(['error' => 'Invalid participant'], 403);

            $sess = $pdo->prepare("SELECT s.status, s.question_started_at, g.time_limit
                FROM kahoot_sessions s JOIN kahoot_games g ON s.game_id = g.id WHERE s.id = ?");
            $sess->execute([$sessionId]);
            $session = $sess->fetch();
            if (!$session || $session['status'] !== 'playing') jsonResponse(['error' => 'Game not in progress'], 400);

            $aChk = $pdo->prepare("SELECT id FROM kahoot_answers WHERE session_id = ? AND participant_id = ? AND question_id = ?");
            $aChk->execute([$sessionId, $participantId, $questionId]);
            if ($aChk->fetch()) jsonResponse(['error' => 'Already answered', 'already_answered' => true], 400);

            $qInfo = $pdo->prepare("SELECT q.points, q.time_limit as q_time_limit, q.question_type, q.correct_answer FROM kahoot_questions q WHERE q.id = ?");
            $qInfo->execute([$questionId]);
            $qData = $qInfo->fetch();
            if (!$qData) jsonResponse(['error' => 'Question not found'], 404);

            $timeLimit = $qData['q_time_limit'] ?? $session['time_limit'];
            $questionType = $qData['question_type'] ?? 'multiple_choice';
            $isCorrect = 0;
            $points = 0;

            if ($questionType === 'word_scramble') {
                if ($answerText === '') {
                    jsonResponse(['error' => 'Answer is required'], 400);
                }
                $isCorrect = normalizeKahootAnswer($qData['correct_answer'] ?? '') === $answerText ? 1 : 0;
            } else {
                $cChk = $pdo->prepare("SELECT is_correct FROM kahoot_choices WHERE id = ? AND question_id = ?");
                $cChk->execute([$choiceId, $questionId]);
                $choice = $cChk->fetch();
                if (!$choice) jsonResponse(['error' => 'Invalid choice'], 400);
                $isCorrect = $choice['is_correct'] ? 1 : 0;
                $answerText = '';
            }

            if ($isCorrect) {
                $basePoints = intval($qData['points']);
                $timeBonus = max(0, ($timeLimit - $timeTaken) / $timeLimit) * ($basePoints * 0.5);
                $points = intval($basePoints * 0.5 + $timeBonus);
            }

            $pdo->prepare("INSERT INTO kahoot_answers (session_id, participant_id, question_id, choice_id, answer_text, is_correct, points_earned, time_taken)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$sessionId, $participantId, $questionId, $questionType === 'word_scramble' ? null : $choiceId, $answerText !== '' ? $answerText : null, $isCorrect, $points, $timeTaken]);

            if ($isCorrect) {
                $pdo->prepare("UPDATE kahoot_participants SET score = score + ?, correct_count = correct_count + 1, streak = streak + 1 WHERE id = ?")
                    ->execute([$points, $participantId]);
            } else {
                $pdo->prepare("UPDATE kahoot_participants SET streak = 0 WHERE id = ?")
                    ->execute([$participantId]);
            }

            $updated = $pdo->prepare("SELECT score, correct_count, streak FROM kahoot_participants WHERE id = ?");
            $updated->execute([$participantId]);

            jsonResponse([
                'success' => true,
                'is_correct' => (bool)$isCorrect,
                'points_earned' => $points,
                'stats' => $updated->fetch()
            ]);
            break;

        case 'start_game':
            $sessionId = intval($_POST['session_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM kahoot_sessions WHERE id = ? AND host_id = ?");
            $stmt->execute([$sessionId, $user['id']]);
            $session = $stmt->fetch();
            if (!$session) jsonResponse(['error' => 'Access denied'], 403);
            if ($session['status'] !== 'lobby') jsonResponse(['error' => 'Game already started'], 400);

            $pdo->prepare("UPDATE kahoot_sessions SET status = 'playing', current_question = 1, question_started_at = NOW(), started_at = NOW() WHERE id = ?")
                ->execute([$sessionId]);

            jsonResponse(['success' => true]);
            break;

        case 'next_question':
            $sessionId = intval($_POST['session_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT s.*, g.id as game_id,
                (SELECT COUNT(*) FROM kahoot_questions WHERE game_id = g.id) as total_questions
                FROM kahoot_sessions s JOIN kahoot_games g ON s.game_id = g.id WHERE s.id = ? AND s.host_id = ?");
            $stmt->execute([$sessionId, $user['id']]);
            $session = $stmt->fetch();
            if (!$session) jsonResponse(['error' => 'Access denied'], 403);

            $nextQ = $session['current_question'] + 1;
            if ($nextQ > $session['total_questions']) {
                $pdo->prepare("UPDATE kahoot_sessions SET status = 'finished', ended_at = NOW() WHERE id = ?")
                    ->execute([$sessionId]);
                $pdo->prepare("UPDATE kahoot_games SET status = 'completed' WHERE id = ?")
                    ->execute([$session['game_id']]);
                jsonResponse(['success' => true, 'finished' => true]);
            } else {
                $pdo->prepare("UPDATE kahoot_sessions SET status = 'playing', current_question = ?, question_started_at = NOW() WHERE id = ?")
                    ->execute([$nextQ, $sessionId]);
                jsonResponse(['success' => true, 'finished' => false, 'current_question' => $nextQ]);
            }
            break;

        case 'show_review':
            $sessionId = intval($_POST['session_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE kahoot_sessions SET status = 'reviewing' WHERE id = ? AND host_id = ?");
            $stmt->execute([$sessionId, $user['id']]);
            jsonResponse(['success' => $stmt->rowCount() > 0]);
            break;

        case 'end_game':
            $sessionId = intval($_POST['session_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT game_id FROM kahoot_sessions WHERE id = ? AND host_id = ?");
            $stmt->execute([$sessionId, $user['id']]);
            $session = $stmt->fetch();
            if (!$session) jsonResponse(['error' => 'Access denied'], 403);

            $pdo->prepare("UPDATE kahoot_sessions SET status = 'finished', ended_at = NOW() WHERE id = ?")
                ->execute([$sessionId]);
            $pdo->prepare("UPDATE kahoot_games SET status = 'completed' WHERE id = ?")
                ->execute([$session['game_id']]);

            auditLog('kahoot_game_ended', "Session #$sessionId ended by host");
            jsonResponse(['success' => true]);
            break;

        case 'delete_game':
            $gameId = intval($_POST['game_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM kahoot_games WHERE id = ? AND created_by = ?");
            $stmt->execute([$gameId, $user['id']]);
            if ($stmt->rowCount()) {
                auditLog('kahoot_game_deleted', "Game #$gameId deleted");
                jsonResponse(['success' => true]);
            } else {
                jsonResponse(['error' => 'Game not found or access denied'], 404);
            }
            break;

        default:
            jsonResponse(['error' => 'Unknown action'], 400);
    }
}

jsonResponse(['error' => 'Invalid request'], 400);
