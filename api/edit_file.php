<?php
require_once __DIR__ . '/../config.private.php';
secure_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']); exit;
}
if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']); exit;
}

$current_folder = $_POST['current_folder'] ?? '';
$file_name      = $_POST['file_name'] ?? '';
$content        = $_POST['content'] ?? '';

$base_path = validate_upload_path($current_folder, UPLOAD_DIR);
if ($base_path === false) { echo json_encode(['success' => false, 'message' => 'Invalid folder']); exit; }

if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $file_name) || $file_name === '.' || $file_name === '..') {
    echo json_encode(['success' => false, 'message' => 'Invalid file name']); exit;
}

$ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
$editable = ['txt', 'md', 'log', 'json', 'xml', 'csv', 'html', 'css', 'js', 'php'];
if (!in_array($ext, $editable, true)) {
    echo json_encode(['success' => false, 'message' => 'File type not editable']); exit;
}

$file_path = $base_path . '/' . $file_name;
$real_path = realpath($file_path);
$real_base = realpath(UPLOAD_DIR);

if (!$real_path || strpos($real_path, $real_base) !== 0 || is_dir($real_path)) {
    echo json_encode(['success' => false, 'message' => 'File not found']); exit;
}

if (file_put_contents($real_path, $content, LOCK_EX) !== false) {
    log_activity('EDIT_FILE', $_SESSION['username'], $file_name . ' in ' . ($current_folder ?: 'root'));
    echo json_encode(['success' => true, 'message' => 'Saved!']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save file']);
}
