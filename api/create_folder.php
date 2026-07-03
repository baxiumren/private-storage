<?php
require_once __DIR__ . '/../config.private.php';
secure_session_start();

// Auth check
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// CSRF check
if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid request token']);
    exit;
}

$folder_name    = trim($_POST['folder_name'] ?? '');
$current_folder = $_POST['current_folder'] ?? '';

if (empty($folder_name)) {
    echo json_encode(['success' => false, 'message' => 'Folder name cannot be empty']);
    exit;
}

// Validate folder name (letters, numbers, underscore, dash only)
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $folder_name)) {
    echo json_encode(['success' => false, 'message' => 'Invalid folder name — use letters, numbers, _ or -']);
    exit;
}

// Validate parent path is within uploads/
$parent_path = validate_upload_path($current_folder, UPLOAD_DIR);
if ($parent_path === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid folder path']);
    exit;
}

$full_path = $parent_path . '/' . $folder_name;

if (file_exists($full_path)) {
    echo json_encode(['success' => false, 'message' => "Folder '{$folder_name}' already exists"]);
    exit;
}

if (mkdir($full_path, 0755, true)) {
    log_activity('CREATE_FOLDER', $_SESSION['username'], $folder_name . ' in ' . ($current_folder ?: 'root'));
    echo json_encode(['success' => true, 'message' => 'Folder created successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to create folder — check server permissions']);
}
