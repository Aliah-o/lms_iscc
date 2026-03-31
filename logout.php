<?php
require_once __DIR__ . '/helpers/functions.php';
auditLog('user_logout', 'User logged out');
session_destroy();
header('Location: ' . BASE_URL . '/login.php');
exit;
