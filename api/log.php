<?php
require_once __DIR__ . '/../config.private.php';
secure_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']); exit;
}
if (!csrf_verify($_GET['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid token']); exit;
}

$log_file = DATA_DIR . '/activity.log';
if (!file_exists($log_file)) {
    echo json_encode(['success' => true, 'entries' => [], 'message' => 'Log is empty']);
    exit;
}

// Read last 100 lines
$lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$lines = array_reverse(array_slice($lines, -100));

$entries = [];
foreach ($lines as $line) {
    $entries[] = htmlspecialchars($line);
}

echo json_encode(['success' => true, 'entries' => $entries]);
