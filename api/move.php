<?php
require_once __DIR__ . '/../config.private.php';
secure_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']); exit;
}
if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid token']); exit;
}

$item_name     = $_POST['item_name']     ?? '';
$from_folder   = $_POST['from_folder']   ?? '';
$to_folder     = $_POST['to_folder']     ?? '';

if (empty($item_name) || !preg_match('/^[a-zA-Z0-9_.\-]+$/', $item_name)) {
    echo json_encode(['success' => false, 'message' => 'Invalid item name']); exit;
}

$src_dir  = validate_upload_path($from_folder, UPLOAD_DIR);
$dest_dir = validate_upload_path($to_folder,   UPLOAD_DIR);

if (!$src_dir || !$dest_dir) {
    echo json_encode(['success' => false, 'message' => 'Invalid path']); exit;
}

$src_path  = $src_dir  . '/' . $item_name;
$dest_path = $dest_dir . '/' . $item_name;

if (!file_exists($src_path)) {
    echo json_encode(['success' => false, 'message' => 'Source not found']); exit;
}
if (file_exists($dest_path)) {
    echo json_encode(['success' => false, 'message' => 'An item with that name already exists in destination']); exit;
}
if (realpath($src_path) === realpath($dest_dir)) {
    echo json_encode(['success' => false, 'message' => 'Cannot move into itself']); exit;
}

if (rename($src_path, $dest_path)) {
    log_activity('MOVE', $_SESSION['username'], $item_name . ' → ' . ($to_folder ?: 'root'));
    echo json_encode(['success' => true, 'message' => 'Moved successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to move item']);
}
