<?php
require_once __DIR__ . '/../config.private.php';
secure_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']); exit;
}
if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']); exit;
}

$folder_key = trim($_POST['folder_key'] ?? '');
$color      = trim($_POST['color'] ?? '');
$icon       = trim($_POST['icon'] ?? '');

// Validate folder key (relative path within uploads)
if (empty($folder_key) || !preg_match('/^[a-zA-Z0-9_\-\/]+$/', $folder_key)) {
    echo json_encode(['success' => false, 'message' => 'Invalid folder']); exit;
}
// Validate color: must be hex or empty
if ($color && !preg_match('/^#[0-9a-fA-F]{3,6}$/', $color)) {
    echo json_encode(['success' => false, 'message' => 'Invalid color']); exit;
}
// Sanitize icon: max 8 bytes (covers most emoji)
$icon = mb_substr(strip_tags($icon), 0, 4);

$meta_file = DATA_DIR . '/folder_meta.json';
if (!file_exists(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
$meta = file_exists($meta_file) ? (json_decode(file_get_contents($meta_file), true) ?? []) : [];

if (empty($color) && empty($icon)) {
    unset($meta[$folder_key]);
} else {
    $meta[$folder_key] = ['color' => $color, 'icon' => $icon];
}

file_put_contents($meta_file, json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
echo json_encode(['success' => true]);
