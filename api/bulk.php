<?php
require_once __DIR__ . '/../config.private.php';
secure_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']); exit;
}
if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid token']); exit;
}

$action         = $_POST['action']         ?? '';
$current_folder = $_POST['current_folder'] ?? '';
$items_raw      = $_POST['items']          ?? '[]';
$items          = json_decode($items_raw, true);

if (!is_array($items) || empty($items)) {
    echo json_encode(['success' => false, 'message' => 'No items selected']); exit;
}

$base_path = validate_upload_path($current_folder, UPLOAD_DIR);
if (!$base_path) {
    echo json_encode(['success' => false, 'message' => 'Invalid folder']); exit;
}

// Validate each item name
foreach ($items as $item) {
    if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $item['name'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid item name: ' . ($item['name'] ?? '')]); exit;
    }
}

function deleteRecursive($path) {
    if (!file_exists($path)) return true;
    if (!is_dir($path))      return unlink($path);
    foreach (scandir($path) as $f) {
        if ($f === '.' || $f === '..') continue;
        deleteRecursive($path . DIRECTORY_SEPARATOR . $f);
    }
    return rmdir($path);
}

$success_count = 0;
$fail_count    = 0;
$errors        = [];

switch ($action) {

    case 'delete_bulk':
        foreach ($items as $item) {
            $path = realpath($base_path . '/' . $item['name']);
            $real_base = realpath(UPLOAD_DIR);
            if (!$path || strpos($path, $real_base) !== 0) { $fail_count++; continue; }
            if (deleteRecursive($path)) { $success_count++; }
            else { $fail_count++; $errors[] = $item['name']; }
        }
        log_activity('BULK_DELETE', $_SESSION['username'], "Deleted {$success_count} items in " . ($current_folder ?: 'root'));
        echo json_encode([
            'success' => $success_count > 0,
            'message' => "Deleted {$success_count} items" . ($fail_count ? ", {$fail_count} failed" : ''),
            'errors'  => $errors,
        ]);
        break;

    case 'move_bulk':
        $to_folder = $_POST['to_folder'] ?? '';
        $dest_dir  = validate_upload_path($to_folder, UPLOAD_DIR);
        if (!$dest_dir) { echo json_encode(['success' => false, 'message' => 'Invalid destination']); exit; }

        foreach ($items as $item) {
            $src  = realpath($base_path . '/' . $item['name']);
            $dest = $dest_dir . '/' . $item['name'];
            $real_base = realpath(UPLOAD_DIR);
            if (!$src || strpos($src, $real_base) !== 0) { $fail_count++; continue; }
            if (file_exists($dest)) { $fail_count++; $errors[] = $item['name'] . ' (already exists)'; continue; }
            if (rename($src, $dest)) { $success_count++; }
            else { $fail_count++; $errors[] = $item['name']; }
        }
        log_activity('BULK_MOVE', $_SESSION['username'], "Moved {$success_count} items to " . ($to_folder ?: 'root'));
        echo json_encode([
            'success' => $success_count > 0,
            'message' => "Moved {$success_count} items" . ($fail_count ? ", {$fail_count} failed" : ''),
            'errors'  => $errors,
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
