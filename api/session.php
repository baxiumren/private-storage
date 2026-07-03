<?php
require_once __DIR__ . '/../config.private.php';
secure_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}
if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$_SESSION['login_time'] = time();
$minutes = ceil($session_timeout / 60);
log_activity('SESSION_EXTEND', $_SESSION['username'], "Extended for {$minutes} minutes");
echo json_encode(['success' => true, 'message' => "Session extended! {$minutes} more minutes."]);
