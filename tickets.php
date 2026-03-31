<?php
$pageTitle = 'Support Tickets';
require_once __DIR__ . '/helpers/functions.php';
requireLogin();
$pdo = getDB();
$user = currentUser();
$role = $user['role'];
markTicketNotificationsRead($user['id']);

try { $pdo->query("SELECT 1 FROM support_tickets LIMIT 1"); } catch (Exception $e) {
    flash('error', 'Please run ticket-install.php first.');
    redirect('/dashboard.php');
}

$breadcrumbPills = $role === 'student' ? ['My Learning', 'Support'] : ($role === 'instructor' ? ['Teaching', 'Support'] : ['Administration', 'Tickets']);

function generateTicketNumber() {
    return 'TKT-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function getTicketRequesterEmail(array $ticket): string {
    $requesterEmail = trim((string)($ticket['requester_email'] ?? ''));
    if ($requesterEmail !== '') {
        return $requesterEmail;
    }
    return trim((string)($ticket['email'] ?? ''));
}

function isPublicRecoveryTicket(array $ticket): bool {
    return ($ticket['request_source'] ?? '') === 'forgot_password' && ($ticket['account_lookup_status'] ?? '') === 'not_found';
}

function getTicketSubmittedByLabel(array $ticket): string {
    if (isPublicRecoveryTicket($ticket)) {
        return 'Public Request';
    }

    $name = trim((string)($ticket['first_name'] ?? '') . ' ' . (string)($ticket['last_name'] ?? ''));
    return $name !== '' ? $name : 'Unknown User';
}

function getTicketSubmittedByInitials(array $ticket): string {
    if (isPublicRecoveryTicket($ticket)) {
        return 'PR';
    }

    $first = strtoupper(substr((string)($ticket['first_name'] ?? ''), 0, 1));
    $last = strtoupper(substr((string)($ticket['last_name'] ?? ''), 0, 1));
    $initials = trim($first . $last);
    return $initials !== '' ? $initials : 'U';
}

function getTicketSubmittedByRoleLabel(array $ticket): string {
    if (isPublicRecoveryTicket($ticket)) {
        return 'Account Not Found';
    }

    return ucfirst((string)($ticket['user_role'] ?? 'user'));
}

function getTicketLookupStatusLabel(array $ticket): string {
    return ($ticket['account_lookup_status'] ?? 'matched') === 'not_found'
        ? 'Account not found'
        : 'Matched active account';
}

function isMatchedRecoveryTicket(array $ticket): bool {
    return ($ticket['request_source'] ?? '') === 'forgot_password'
        && ($ticket['account_lookup_status'] ?? 'matched') === 'matched';
}

function generateTemporaryPassword(int $length = 12): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $maxIndex = strlen($alphabet) - 1;
    $password = '';

    for ($i = 0; $i < max($length, getPasswordMinLength()); $i++) {
        $password .= $alphabet[random_int(0, $maxIndex)];
    }

    return $password;
}

function buildAbsoluteAppUrl(string $path = '/'): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(BASE_URL, '/');
    $path = '/' . ltrim($path, '/');
    return $scheme . '://' . $host . $base . $path;
}

function buildPasswordEmailDraft(array $ticket, string $temporaryPassword): array {
    $recipientEmail = getTicketRequesterEmail($ticket);
    $displayName = trim((string)($ticket['first_name'] ?? '') . ' ' . (string)($ticket['last_name'] ?? ''));
    if ($displayName === '') {
        $displayName = trim((string)($ticket['username'] ?? '')) ?: 'User';
    }

    $subject = 'ISCC LMS Temporary Password';
    $bodyLines = [
        'Hello ' . $displayName . ',',
        '',
        'A new temporary password has been created for your ISCC LMS account.',
        '',
        'Your new password is: ' . $temporaryPassword,
        'Username: ' . trim((string)($ticket['username'] ?? '')),
        'Login page: ' . buildAbsoluteAppUrl('/login.php'),
        '',
        'Please log in and change this password immediately from your profile settings.',
        '',
        'If you did not request this reset, please reply to this email.',
        '',
        'Regards,',
        'ISCC LMS Support',
    ];
    $body = implode("\r\n", $bodyLines);

    return [
        'subject' => $subject,
        'body' => $body,
        'gmail_url' => 'https://mail.google.com/mail/?view=cm&fs=1&tf=1'
            . '&to=' . rawurlencode($recipientEmail)
            . '&su=' . rawurlencode($subject)
            . '&body=' . rawurlencode($body),
        'mailto_url' => 'mailto:' . $recipientEmail
            . '?subject=' . rawurlencode($subject)
            . '&body=' . rawurlencode($body),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { flash('error', 'Invalid token.'); redirect('/tickets.php'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'create_ticket') {
        if ($role === 'superadmin') {
            flash('error', 'Superadmins manage tickets and cannot create new support tickets from this page.');
            redirect('/tickets.php');
        }

        $subject = trim($_POST['subject'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = $_POST['category'] ?? 'general';
        $priority = $_POST['priority'] ?? 'medium';

        $validCategories = ['bug','feature','account','grades','classes','quiz','general'];
        $validPriorities = ['low','medium','high','critical'];

        if (empty($subject) || empty($description)) {
            flash('error', 'Subject and description are required.');
            redirect('/tickets.php');
        }

        if (!in_array($category, $validCategories)) $category = 'general';
        if (!in_array($priority, $validPriorities)) $priority = 'medium';

        $ticketNumber = generateTicketNumber();

        $stmt = $pdo->prepare("INSERT INTO support_tickets (ticket_number, user_id, category, priority, subject, description) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$ticketNumber, $user['id'], $category, $priority, $subject, $description]);
        $ticketId = $pdo->lastInsertId();

        if (!empty($_FILES['attachment']['name'])) {
            $file = $_FILES['attachment'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','pdf','doc','docx','txt','zip'];

            if (in_array($ext, $allowed) && $file['size'] <= 5 * 1024 * 1024) {
                $fileName = $ticketNumber . '_' . time() . '.' . $ext;
                $filePath = 'uploads/tickets/' . $fileName;
                if (move_uploaded_file($file['tmp_name'], __DIR__ . '/' . $filePath)) {
                    $pdo->prepare("INSERT INTO ticket_attachments (ticket_id, file_name, file_path, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$ticketId, $file['name'], $filePath, $file['size'], $user['id']]);
                }
            }
        }

        $admins = $pdo->query("SELECT id FROM users WHERE role = 'superadmin' AND is_active = 1")->fetchAll();
        foreach ($admins as $admin) {
            addNotification($admin['id'], 'ticket', 'New support ticket: ' . $subject, $ticketId);
        }

        auditLog('ticket_created', "Ticket $ticketNumber created: $subject");
        flash('success', "Ticket $ticketNumber created successfully!");
        redirect('/tickets.php');
    }

    if ($action === 'reply_ticket') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');

        if (empty($message)) { flash('error', 'Reply message is required.'); redirect('/tickets.php?view=' . $ticketId); }

        if ($role === 'superadmin') {
            $ticket = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ?");
        } else {
            $ticket = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ? AND user_id = " . $user['id']);
        }
        $ticket->execute([$ticketId]);
        $ticket = $ticket->fetch();

        if (!$ticket) { flash('error', 'Ticket not found.'); redirect('/tickets.php'); }

        $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message) VALUES (?, ?, ?)")
            ->execute([$ticketId, $user['id'], $message]);

        $pdo->prepare("UPDATE support_tickets SET updated_at = NOW() WHERE id = ?")->execute([$ticketId]);

        if ($role === 'superadmin') {
            addNotification($ticket['user_id'], 'ticket', 'Your ticket "' . $ticket['subject'] . '" has a new reply', $ticketId);
        } else {
            $admins = $pdo->query("SELECT id FROM users WHERE role = 'superadmin' AND is_active = 1")->fetchAll();
            foreach ($admins as $admin) {
                addNotification($admin['id'], 'ticket', 'Reply on ticket: ' . $ticket['subject'], $ticketId);
            }
        }

        auditLog('ticket_reply', "Reply on ticket #{$ticket['ticket_number']}");
        flash('success', 'Reply sent successfully!');
        redirect('/tickets.php?view=' . $ticketId);
    }

    if ($action === 'update_status' && $role === 'superadmin') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $resolutionNotes = trim($_POST['resolution_notes'] ?? '');

        $validStatuses = ['open','in_progress','resolved','closed'];
        if (!in_array($status, $validStatuses)) { flash('error', 'Invalid status.'); redirect('/tickets.php'); }

        $ticket = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ?");
        $ticket->execute([$ticketId]);
        $ticket = $ticket->fetch();
        if (!$ticket) { flash('error', 'Ticket not found.'); redirect('/tickets.php'); }

        $updates = ['status' => $status];
        $sql = "UPDATE support_tickets SET status = ?, assigned_to = ?";
        $params = [$status, $user['id']];

        if ($status === 'resolved') {
            $sql .= ", resolution_notes = ?, resolved_at = NOW()";
            $params[] = $resolutionNotes;
        }
        if ($status === 'closed') {
            $sql .= ", closed_at = NOW()";
        }

        $sql .= " WHERE id = ?";
        $params[] = $ticketId;

        $pdo->prepare($sql)->execute($params);

        $statusLabels = ['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];
        addNotification($ticket['user_id'], 'ticket', 'Your ticket "' . $ticket['subject'] . '" status changed to ' . $statusLabels[$status], $ticketId);

        if (!empty($resolutionNotes)) {
            $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, is_internal) VALUES (?, ?, ?, 0)")
                ->execute([$ticketId, $user['id'], '[Status changed to ' . $statusLabels[$status] . '] ' . $resolutionNotes]);
        }

        auditLog('ticket_status_update', "Ticket #{$ticket['ticket_number']} status changed to $status");
        flash('success', 'Ticket status updated!');
        redirect('/tickets.php?view=' . $ticketId);
    }

    if ($action === 'email_ticket_password' && $role === 'superadmin') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);

        $ticketStmt = $pdo->prepare("SELECT t.*, u.first_name, u.last_name, u.username, u.email, u.role as user_role
            FROM support_tickets t
            JOIN users u ON t.user_id = u.id
            WHERE t.id = ? AND u.is_active = 1");
        $ticketStmt->execute([$ticketId]);
        $ticket = $ticketStmt->fetch();

        if (!$ticket) {
            flash('error', 'Ticket not found.');
            redirect('/tickets.php');
        }

        $requesterEmail = getTicketRequesterEmail($ticket);
        if (!isMatchedRecoveryTicket($ticket)) {
            flash('error', 'A password email can only be created for forgot-password tickets that match an active LMS account.');
            redirect('/tickets.php?view=' . $ticketId);
        }
        if ($ticket['status'] === 'closed') {
            flash('error', 'Closed tickets cannot generate a new password email.');
            redirect('/tickets.php?view=' . $ticketId);
        }
        if ($requesterEmail === '' || filter_var($requesterEmail, FILTER_VALIDATE_EMAIL) === false) {
            flash('error', 'This ticket does not have a valid requester email address.');
            redirect('/tickets.php?view=' . $ticketId);
        }

        $temporaryPassword = generateTemporaryPassword();
        $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
        $updateUser = $pdo->prepare("UPDATE users SET password = ?, plain_password = NULL WHERE id = ? AND is_active = 1");
        $updateUser->execute([$passwordHash, $ticket['user_id']]);

        if ($updateUser->rowCount() < 1) {
            flash('error', 'The matched account could not be updated. Please try again.');
            redirect('/tickets.php?view=' . $ticketId);
        }

        $pdo->prepare("UPDATE support_tickets SET assigned_to = ?, updated_at = NOW(), status = CASE WHEN status = 'open' THEN 'in_progress' ELSE status END WHERE id = ?")
            ->execute([$user['id'], $ticketId]);

        $draft = buildPasswordEmailDraft($ticket, $temporaryPassword);
        auditLog('ticket_password_email_prepared', "Prepared temporary password email for ticket #{$ticket['ticket_number']} and user #{$ticket['user_id']}");

        header('Location: ' . $draft['gmail_url']);
        exit;
    }
}

$viewTicketId = intval($_GET['view'] ?? 0);

if ($viewTicketId > 0) {
    if ($role === 'superadmin') {
        $stmt = $pdo->prepare("SELECT t.*, u.first_name, u.last_name, u.username, u.email, u.role as user_role, a.first_name as assigned_fn, a.last_name as assigned_ln
            FROM support_tickets t
            JOIN users u ON t.user_id = u.id
            LEFT JOIN users a ON t.assigned_to = a.id
            WHERE t.id = ?");
    } else {
        $stmt = $pdo->prepare("SELECT t.*, u.first_name, u.last_name, u.username, u.email, u.role as user_role, a.first_name as assigned_fn, a.last_name as assigned_ln
            FROM support_tickets t
            JOIN users u ON t.user_id = u.id
            LEFT JOIN users a ON t.assigned_to = a.id
            WHERE t.id = ? AND t.user_id = " . $user['id']);
    }
    $stmt->execute([$viewTicketId]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        flash('error', 'Ticket not found.');
        redirect('/tickets.php');
    }

    $replies = $pdo->prepare("SELECT r.*, u.first_name, u.last_name, u.role as user_role
        FROM ticket_replies r
        JOIN users u ON r.user_id = u.id
        WHERE r.ticket_id = ?
        ORDER BY r.created_at ASC");
    $replies->execute([$viewTicketId]);
    $replies = $replies->fetchAll();

    $attachments = $pdo->prepare("SELECT * FROM ticket_attachments WHERE ticket_id = ?");
    $attachments->execute([$viewTicketId]);
    $attachments = $attachments->fetchAll();
}

$filterStatus = $_GET['status'] ?? '';
$filterCategory = $_GET['category'] ?? '';
$filterPriority = $_GET['priority'] ?? '';

if (!$viewTicketId) {
    $sql = "SELECT t.*, u.first_name, u.last_name, u.username FROM support_tickets t JOIN users u ON t.user_id = u.id WHERE 1=1";
    $params = [];

    if ($role !== 'superadmin') {
        $sql .= " AND t.user_id = ?";
        $params[] = $user['id'];
    }
    if ($filterStatus) { $sql .= " AND t.status = ?"; $params[] = $filterStatus; }
    if ($filterCategory) { $sql .= " AND t.category = ?"; $params[] = $filterCategory; }
    if ($filterPriority) { $sql .= " AND t.priority = ?"; $params[] = $filterPriority; }

    $sql .= " ORDER BY t.created_at DESC";
    $ticketList = $pdo->prepare($sql);
    $ticketList->execute($params);
    $tickets = $ticketList->fetchAll();

    if ($role === 'superadmin') {
        $openCount = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'open'")->fetchColumn();
        $inProgressCount = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'in_progress'")->fetchColumn();
        $resolvedCount = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'resolved'")->fetchColumn();
        $totalCount = $pdo->query("SELECT COUNT(*) FROM support_tickets")->fetchColumn();
    } else {
        $openCount = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE user_id = ? AND status = 'open'");
        $openCount->execute([$user['id']]);
        $openCount = $openCount->fetchColumn();
        $totalCount = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE user_id = ?");
        $totalCount->execute([$user['id']]);
        $totalCount = $totalCount->fetchColumn();
        $inProgressCount = 0;
        $resolvedCount = 0;
    }
}

require_once __DIR__ . '/views/layouts/header.php';
?>

<?php if ($viewTicketId && $ticket): ?>

<div class="mb-3">
    <a href="<?= BASE_URL ?>/tickets.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back to Tickets</a>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-secondary me-2"><?= e($ticket['ticket_number']) ?></span>
                    <span class="fw-bold"><?= e($ticket['subject']) ?></span>
                </div>
                <?php
                $statusColors = ['open' => 'warning', 'in_progress' => 'info', 'resolved' => 'success', 'closed' => 'secondary'];
                $statusLabels = ['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];
                $priorityColors = ['low' => 'success', 'medium' => 'warning', 'high' => 'danger', 'critical' => 'dark'];
                $categoryIcons = ['bug' => 'fa-bug', 'feature' => 'fa-lightbulb', 'account' => 'fa-user', 'grades' => 'fa-clipboard-check', 'classes' => 'fa-chalkboard', 'quiz' => 'fa-question-circle', 'general' => 'fa-tag'];
                ?>
                <span class="badge bg-<?= $statusColors[$ticket['status']] ?? 'secondary' ?>"><?= $statusLabels[$ticket['status']] ?? $ticket['status'] ?></span>
            </div>
            <div class="card-body">
                <div class="p-3 mb-3" style="background:var(--gray-50);border-radius:10px;">
                    <div class="d-flex align-items-start gap-3">
                        <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-dark));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:0.85rem;">
                            <?= e(getTicketSubmittedByInitials($ticket)) ?>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-bold" style="font-size:0.9rem;"><?= e(getTicketSubmittedByLabel($ticket)) ?>
                                <span class="badge bg-light text-dark ms-1" style="font-size:0.7rem;"><?= e(getTicketSubmittedByRoleLabel($ticket)) ?></span>
                            </div>
                            <?php if (getTicketRequesterEmail($ticket) !== ''): ?>
                            <div class="text-muted" style="font-size:0.78rem;"><i class="fas fa-envelope me-1"></i><?= e(getTicketRequesterEmail($ticket)) ?></div>
                            <?php endif; ?>
                            <div class="text-muted" style="font-size:0.78rem;"><i class="far fa-clock me-1"></i><?= date('M d, Y g:ia', strtotime($ticket['created_at'])) ?></div>
                            <div class="mt-2" style="font-size:0.88rem;line-height:1.6;white-space:pre-wrap;"><?= e($ticket['description']) ?></div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($attachments)): ?>
                <div class="mb-3">
                    <h6 class="fw-bold" style="font-size:0.85rem;"><i class="fas fa-paperclip me-1"></i>Attachments</h6>
                    <?php foreach ($attachments as $att): ?>
                    <a href="<?= BASE_URL ?>/<?= e($att['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1 mb-1">
                        <i class="fas fa-file me-1"></i><?= e($att['file_name']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($replies)): ?>
                <h6 class="fw-bold mb-3" style="font-size:0.85rem;"><i class="fas fa-comments me-1"></i>Replies (<?= count($replies) ?>)</h6>
                <?php foreach ($replies as $reply): ?>
                <div class="d-flex gap-3 mb-3 p-3" style="background:<?= $reply['user_role'] === 'superadmin' ? '#f0fdf4' : 'var(--gray-50)' ?>;border-radius:10px;border-left:3px solid <?= $reply['user_role'] === 'superadmin' ? 'var(--success)' : 'var(--primary)' ?>;">
                    <div style="width:36px;height:36px;border-radius:50%;background:<?= $reply['user_role'] === 'superadmin' ? 'var(--success)' : 'var(--primary)' ?>;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:0.75rem;flex-shrink:0;">
                        <?= strtoupper(substr($reply['first_name'], 0, 1) . substr($reply['last_name'], 0, 1)) ?>
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold" style="font-size:0.85rem;"><?= e($reply['first_name'] . ' ' . $reply['last_name']) ?>
                                <?php if ($reply['user_role'] === 'superadmin'): ?>
                                <span class="badge bg-success ms-1" style="font-size:0.65rem;">Admin</span>
                                <?php endif; ?>
                            </span>
                            <span class="text-muted" style="font-size:0.72rem;"><?= date('M d, Y g:ia', strtotime($reply['created_at'])) ?></span>
                        </div>
                        <div style="font-size:0.85rem;line-height:1.5;white-space:pre-wrap;"><?= e($reply['message']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($ticket['status'] !== 'closed'): ?>
                <form method="POST" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reply_ticket">
                    <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold" style="font-size:0.85rem;">Write a Reply</label>
                        <textarea name="message" class="form-control" rows="3" placeholder="Type your reply..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Send Reply</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header"><span class="fw-bold"><i class="fas fa-info-circle me-2"></i>Ticket Details</span></div>
            <div class="card-body">
                <table class="table table-sm mb-0" style="font-size:0.85rem;">
                    <tr><td class="text-muted">Ticket #</td><td class="fw-bold"><?= e($ticket['ticket_number']) ?></td></tr>
                    <tr><td class="text-muted">Category</td><td><i class="fas <?= $categoryIcons[$ticket['category']] ?? 'fa-tag' ?> me-1"></i><?= ucfirst($ticket['category']) ?></td></tr>
                    <tr><td class="text-muted">Priority</td><td><span class="badge bg-<?= $priorityColors[$ticket['priority']] ?? 'secondary' ?>"><?= ucfirst($ticket['priority']) ?></span></td></tr>
                    <tr><td class="text-muted">Status</td><td><span class="badge bg-<?= $statusColors[$ticket['status']] ?? 'secondary' ?>"><?= $statusLabels[$ticket['status']] ?? $ticket['status'] ?></span></td></tr>
                    <?php if (getTicketRequesterEmail($ticket) !== ''): ?>
                    <tr><td class="text-muted">Requester Email</td><td><a href="mailto:<?= e(getTicketRequesterEmail($ticket)) ?>"><?= e(getTicketRequesterEmail($ticket)) ?></a></td></tr>
                    <?php endif; ?>
                    <?php if (($ticket['request_source'] ?? '') === 'forgot_password'): ?>
                    <tr><td class="text-muted">Lookup Status</td><td><?= e(getTicketLookupStatusLabel($ticket)) ?></td></tr>
                    <?php endif; ?>
                    <tr><td class="text-muted">Created</td><td><?= date('M d, Y', strtotime($ticket['created_at'])) ?></td></tr>
                    <?php if ($ticket['assigned_fn']): ?>
                    <tr><td class="text-muted">Assigned To</td><td><?= e($ticket['assigned_fn'] . ' ' . $ticket['assigned_ln']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($ticket['resolved_at']): ?>
                    <tr><td class="text-muted">Resolved</td><td><?= date('M d, Y', strtotime($ticket['resolved_at'])) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($ticket['resolution_notes']): ?>
                    <tr><td class="text-muted">Resolution</td><td style="white-space:pre-wrap;"><?= e($ticket['resolution_notes']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <?php if ($role === 'superadmin' && $ticket['status'] !== 'closed'): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header"><span class="fw-bold"><i class="fas fa-tools me-2"></i>Admin Actions</span></div>
            <div class="card-body">
                <?php if (($ticket['request_source'] ?? '') === 'forgot_password'): ?>
                <div class="p-3 mb-3 rounded-3" style="background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.16);">
                    <div class="fw-bold mb-1" style="font-size:0.88rem;"><i class="fas fa-envelope-open-text me-2 text-primary"></i>Password Recovery Email</div>
                    <?php if (isMatchedRecoveryTicket($ticket) && getTicketRequesterEmail($ticket) !== ''): ?>
                    <p class="text-muted mb-3" style="font-size:0.8rem;line-height:1.55;">This generates a fresh temporary password, saves it securely to the matched LMS account, and opens a ready-to-send Gmail draft for <?= e(getTicketRequesterEmail($ticket)) ?>.</p>
                    <form method="POST" class="mb-0" onsubmit="return confirm('Generate a new temporary password for this account and open the Gmail draft? The old password will stop working immediately.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="email_ticket_password">
                        <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                        <button type="submit" class="btn btn-primary w-100 mb-2"><i class="fas fa-paper-plane me-1"></i>Email Temporary Password</button>
                    </form>
                    <div class="text-muted" style="font-size:0.75rem;">Template includes the new password, username, login link, and a reminder to change it after login.</div>
                    <?php else: ?>
                    <p class="text-muted mb-0" style="font-size:0.8rem;line-height:1.55;">This requester email does not match an active LMS account, so a temporary password email cannot be generated from this ticket.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold" style="font-size:0.85rem;">Update Status</label>
                        <select name="status" class="form-select" required>
                            <option value="open" <?= $ticket['status'] === 'open' ? 'selected' : '' ?>>Open</option>
                            <option value="in_progress" <?= $ticket['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="resolved" <?= $ticket['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                            <option value="closed" <?= $ticket['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" style="font-size:0.85rem;">Resolution Notes</label>
                        <textarea name="resolution_notes" class="form-control" rows="3" placeholder="Describe the resolution or action taken..."><?= e($ticket['resolution_notes'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-success w-100"><i class="fas fa-save me-1"></i>Update Ticket</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>

<?php if ($role === 'superadmin'): ?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-warning-soft"><i class="fas fa-exclamation-circle"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $openCount ?></div><div class="stat-label">Open Tickets</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-info-soft"><i class="fas fa-spinner"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $inProgressCount ?></div><div class="stat-label">In Progress</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-success-soft"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $resolvedCount ?></div><div class="stat-label">Resolved</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-primary-soft"><i class="fas fa-ticket-alt"></i></div>
            <div class="stat-info"><div class="stat-value"><?= $totalCount ?></div><div class="stat-label">Total Tickets</div></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/tickets.php" class="btn btn-sm <?= !$filterStatus ? 'btn-primary' : 'btn-outline-primary' ?>">All</a>
        <a href="<?= BASE_URL ?>/tickets.php?status=open" class="btn btn-sm <?= $filterStatus === 'open' ? 'btn-warning' : 'btn-outline-warning' ?>">Open</a>
        <a href="<?= BASE_URL ?>/tickets.php?status=in_progress" class="btn btn-sm <?= $filterStatus === 'in_progress' ? 'btn-info' : 'btn-outline-info' ?>">In Progress</a>
        <a href="<?= BASE_URL ?>/tickets.php?status=resolved" class="btn btn-sm <?= $filterStatus === 'resolved' ? 'btn-success' : 'btn-outline-success' ?>">Resolved</a>
        <a href="<?= BASE_URL ?>/tickets.php?status=closed" class="btn btn-sm <?= $filterStatus === 'closed' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Closed</a>
    </div>
    <?php if ($role !== 'superadmin'): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTicketModal">
        <i class="fas fa-plus me-1"></i>New Ticket
    </button>
    <?php endif; ?>
</div>

<?php if (empty($tickets)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="fas fa-ticket-alt fa-3x text-muted mb-3 d-block"></i>
        <h5 class="fw-bold text-muted">No Tickets Found</h5>
        <p class="text-muted"><?= in_array($role, ['student','instructor']) ? 'Need help? Create a support ticket!' : 'No support tickets to show.' ?></p>
        <?php if ($role !== 'superadmin'): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTicketModal">
            <i class="fas fa-plus me-1"></i>Create Ticket
        </button>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-modern mb-0">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Subject</th>
                        <?php if ($role === 'superadmin'): ?><th>Submitted By</th><?php endif; ?>
                        <th>Category</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $statusColors = ['open' => 'warning', 'in_progress' => 'info', 'resolved' => 'success', 'closed' => 'secondary'];
                    $statusLabels = ['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];
                    $priorityColors = ['low' => 'success', 'medium' => 'warning', 'high' => 'danger', 'critical' => 'dark'];
                    $categoryIcons = ['bug' => 'fa-bug text-danger', 'feature' => 'fa-lightbulb text-warning', 'account' => 'fa-user text-info', 'grades' => 'fa-clipboard-check text-success', 'classes' => 'fa-chalkboard text-primary', 'quiz' => 'fa-question-circle text-purple', 'general' => 'fa-tag text-secondary'];
                    foreach ($tickets as $t):
                    ?>
                    <tr>
                        <td><span class="badge bg-light text-dark" style="font-size:0.75rem;"><?= e($t['ticket_number']) ?></span></td>
                        <td class="fw-600"><?= e(substr($t['subject'], 0, 50)) ?><?= strlen($t['subject']) > 50 ? '...' : '' ?></td>
                        <?php if ($role === 'superadmin'): ?>
                        <td style="font-size:0.82rem;">
                            <div><?= e(getTicketSubmittedByLabel($t)) ?></div>
                            <?php if (getTicketRequesterEmail($t) !== ''): ?>
                            <div class="text-muted" style="font-size:0.72rem;"><?= e(getTicketRequesterEmail($t)) ?></div>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td><i class="fas <?= $categoryIcons[$t['category']] ?? 'fa-tag text-secondary' ?> me-1"></i><span style="font-size:0.82rem;"><?= ucfirst($t['category']) ?></span></td>
                        <td><span class="badge bg-<?= $priorityColors[$t['priority']] ?? 'secondary' ?>"><?= ucfirst($t['priority']) ?></span></td>
                        <td><span class="badge bg-<?= $statusColors[$t['status']] ?? 'secondary' ?>"><?= $statusLabels[$t['status']] ?? $t['status'] ?></span></td>
                        <td style="font-size:0.8rem;"><?= date('M d, Y', strtotime($t['created_at'])) ?></td>
                        <td><a href="<?= BASE_URL ?>/tickets.php?view=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye me-1"></i>View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($role !== 'superadmin'): ?>
<div class="modal fade" id="createTicketModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0" style="background:linear-gradient(135deg,#e74c3c,#c0392b);color:#fff;">
                <h5 class="modal-title"><i class="fas fa-ticket-alt me-2"></i>Create Support Ticket</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_ticket">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-bold">Subject <span class="text-danger">*</span></label>
                            <input type="text" name="subject" class="form-control" placeholder="Brief description of your issue" required maxlength="255">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Category</label>
                            <select name="category" class="form-select">
                                <option value="bug">🐛 Bug Report</option>
                                <option value="feature">💡 Feature Request</option>
                                <option value="account">👤 Account Issue</option>
                                <option value="grades">📋 Grades Issue</option>
                                <option value="classes">🏫 Classes Issue</option>
                                <option value="quiz">❓ Quiz Issue</option>
                                <option value="general" selected>🏷️ General</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Priority</label>
                            <select name="priority" class="form-select">
                                <option value="low">🟢 Low</option>
                                <option value="medium" selected>🟡 Medium</option>
                                <option value="high">🔴 High</option>
                                <option value="critical">⚫ Critical</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-bold">Attachment (optional)</label>
                            <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.zip">
                            <div class="form-text">Max 5MB. Allowed: jpg, png, gif, pdf, doc, docx, txt, zip</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Description <span class="text-danger">*</span></label>
                            <textarea name="description" class="form-control" rows="5" placeholder="Describe your issue in detail. Include steps to reproduce if it's a bug." required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-paper-plane me-1"></i>Submit Ticket</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/views/layouts/footer.php'; ?>
