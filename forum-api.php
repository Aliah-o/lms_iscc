<?php
require_once __DIR__ . '/helpers/functions.php';

if (!isLoggedIn()) {
    jsonResponse(['error' => 'Not authenticated'], 401);
}

$user = currentUser();
$pdo = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'notification_count') {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM forum_notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user['id']]);
        $forumCount = (int)$stmt->fetchColumn();

        $sysCount = 0;
        try {
            $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt2->execute([$user['id']]);
            $sysCount = (int)$stmt2->fetchColumn();
        } catch (Exception $e) {}

        jsonResponse(['count' => $forumCount + $sysCount]);
    } catch (Exception $e) {
        jsonResponse(['count' => 0]);
    }
}

if ($action === 'notifications') {
    try {
        $stmt = $pdo->prepare("
            SELECT fn.*, u.first_name, u.last_name,
                   ft.title as thread_title
            FROM forum_notifications fn
            JOIN users u ON fn.triggered_by = u.id
            LEFT JOIN forum_threads ft ON fn.thread_id = ft.id
            WHERE fn.user_id = ?
            ORDER BY fn.created_at DESC
            LIMIT 15
        ");
        $stmt->execute([$user['id']]);
        $notifs = $stmt->fetchAll();

        $items = [];
        foreach ($notifs as $n) {
            $items[] = [
                'id' => $n['id'],
                'source' => 'forum',
                'type' => $n['type'],
                'message' => $n['message'],
                'thread_id' => $n['thread_id'],
                'thread_title' => $n['thread_title'],
                'triggered_by_name' => $n['first_name'] . ' ' . $n['last_name'],
                'is_read' => (int)$n['is_read'],
                'time_ago' => timeAgo($n['created_at']),
                'created_at' => $n['created_at'],
            ];
        }

        try {
            $stmt2 = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
            $stmt2->execute([$user['id']]);
            $sysNotifs = $stmt2->fetchAll();
            foreach ($sysNotifs as $sn) {
                $link = $sn['link'] ?? '';
                if (empty($link)) {
                    if (in_array($sn['type'], ['join_approved', 'join_declined', 'join_request'])) {
                        $link = BASE_URL . '/join-class.php';
                    } elseif (strpos($sn['type'], 'ticket') !== false) {
                        $link = BASE_URL . '/tickets.php';
                    }
                }
                $items[] = [
                    'id' => $sn['id'],
                    'source' => 'system',
                    'type' => $sn['type'],
                    'message' => $sn['message'],
                    'thread_id' => null,
                    'thread_title' => null,
                    'triggered_by_name' => 'System',
                    'is_read' => (int)$sn['is_read'],
                    'time_ago' => timeAgo($sn['created_at']),
                    'created_at' => $sn['created_at'],
                    'link' => $link,
                ];
            }
        } catch (Exception $e) {}

        usort($items, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        $items = array_slice($items, 0, 20);

        jsonResponse(['notifications' => $items]);
    } catch (Exception $e) {
        jsonResponse(['notifications' => []]);
    }
}

if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $notifId = $_POST['notification_id'] ?? null;
    $source = $_POST['source'] ?? 'forum';
    try {
        if ($notifId === 'all') {
            $pdo->prepare("UPDATE forum_notifications SET is_read = 1 WHERE user_id = ?")->execute([$user['id']]);
            try {
                $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$user['id']]);
            } catch (Exception $e) {}
        } elseif ($notifId) {
            if ($source === 'system') {
                $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([(int)$notifId, $user['id']]);
            } else {
                $pdo->prepare("UPDATE forum_notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([(int)$notifId, $user['id']]);
            }
        }
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        jsonResponse(['success' => false], 500);
    }
}

if ($action === 'forum_badge') {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM forum_notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user['id']]);
        $count = (int)$stmt->fetchColumn();
        jsonResponse(['count' => $count]);
    } catch (Exception $e) {
        jsonResponse(['count' => 0]);
    }
}

function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . 'y ago';
    if ($diff->m > 0) return $diff->m . 'mo ago';
    if ($diff->d > 0) return $diff->d . 'd ago';
    if ($diff->h > 0) return $diff->h . 'h ago';
    if ($diff->i > 0) return $diff->i . 'm ago';
    return 'Just now';
}

jsonResponse(['error' => 'Invalid action'], 400);
