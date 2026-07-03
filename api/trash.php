<?php
require_once __DIR__ . '/../config.private.php';
secure_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']); exit;
}
if (!csrf_verify($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']); exit;
}

$action    = $_POST['action'] ?? $_GET['action'] ?? '';
$trash_dir = UPLOAD_DIR . '/.trash';
$meta_file = DATA_DIR . '/trash_meta.json';

function trash_get_meta() {
    global $meta_file;
    if (!file_exists($meta_file)) return [];
    return json_decode(file_get_contents($meta_file), true) ?? [];
}
function trash_save_meta($meta) {
    global $meta_file;
    if (!file_exists(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
    file_put_contents($meta_file, json_encode(array_values($meta), JSON_PRETTY_PRINT), LOCK_EX);
}
function trash_delete_recursive($path) {
    if (!file_exists($path)) return true;
    if (!is_dir($path)) return unlink($path);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) $f->isDir() ? rmdir($f) : unlink($f);
    return rmdir($path);
}

if (!file_exists($trash_dir)) mkdir($trash_dir, 0755, true);

switch ($action) {

    case 'move_to_trash':
        $item_name      = $_POST['item_name'] ?? '';
        $current_folder = $_POST['current_folder'] ?? '';

        if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $item_name) || $item_name === '.' || $item_name === '..') {
            echo json_encode(['success' => false, 'message' => 'Invalid name']); exit;
        }
        $base_path = validate_upload_path($current_folder, UPLOAD_DIR);
        if ($base_path === false) { echo json_encode(['success' => false, 'message' => 'Invalid folder']); exit; }

        $src_path  = $base_path . '/' . $item_name;
        $real_src  = realpath($src_path);
        $real_base = realpath(UPLOAD_DIR);
        if (!$real_src || strpos($real_src, $real_base) !== 0) {
            echo json_encode(['success' => false, 'message' => 'Item not found']); exit;
        }

        $trash_name = 'trash_' . time() . '_' . $item_name;
        if (rename($real_src, $trash_dir . '/' . $trash_name)) {
            $meta   = trash_get_meta();
            $meta[] = [
                'id'              => uniqid(),
                'original_name'   => $item_name,
                'trash_name'      => $trash_name,
                'original_folder' => $current_folder,
                'is_dir'          => is_dir($trash_dir . '/' . $trash_name),
                'deleted_at'      => time(),
            ];
            trash_save_meta($meta);
            log_activity('TRASH', $_SESSION['username'], $item_name . ' from ' . ($current_folder ?: 'root'));
            echo json_encode(['success' => true, 'message' => 'Moved to trash']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to move to trash']);
        }
        break;

    case 'list':
        $meta = trash_get_meta();
        foreach ($meta as &$item) {
            $p = $trash_dir . '/' . $item['trash_name'];
            $item['size'] = file_exists($p) ? (is_dir($p) ? -1 : filesize($p)) : 0;
            $item['deleted_at_fmt'] = date('Y-m-d H:i', $item['deleted_at']);
        }
        echo json_encode(['success' => true, 'items' => $meta]);
        break;

    case 'restore':
        $trash_name = $_POST['trash_name'] ?? '';
        $meta = trash_get_meta();
        $found = null;
        foreach ($meta as $item) { if ($item['trash_name'] === $trash_name) { $found = $item; break; } }
        if (!$found) { echo json_encode(['success' => false, 'message' => 'Not found in trash']); exit; }

        $src = $trash_dir . '/' . $found['trash_name'];
        if (!file_exists($src)) { echo json_encode(['success' => false, 'message' => 'Trash file missing']); exit; }

        $dest_base = validate_upload_path($found['original_folder'], UPLOAD_DIR);
        if ($dest_base === false) $dest_base = realpath(UPLOAD_DIR);
        if (!file_exists($dest_base)) mkdir($dest_base, 0755, true);

        $dest = $dest_base . '/' . $found['original_name'];
        if (file_exists($dest)) {
            $info = pathinfo($found['original_name']);
            $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
            $dest = $dest_base . '/' . $info['filename'] . '_restored' . $ext;
        }
        if (rename($src, $dest)) {
            trash_save_meta(array_filter($meta, fn($i) => $i['trash_name'] !== $trash_name));
            log_activity('RESTORE', $_SESSION['username'], $found['original_name']);
            echo json_encode(['success' => true, 'message' => 'Restored to ' . ($found['original_folder'] ?: 'root')]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to restore']);
        }
        break;

    case 'delete_permanent':
        $trash_name = $_POST['trash_name'] ?? '';
        $meta = trash_get_meta();
        $found = null;
        foreach ($meta as $item) { if ($item['trash_name'] === $trash_name) { $found = $item; break; } }
        if (!$found) { echo json_encode(['success' => false, 'message' => 'Not found']); exit; }

        trash_delete_recursive($trash_dir . '/' . $found['trash_name']);
        trash_save_meta(array_filter($meta, fn($i) => $i['trash_name'] !== $trash_name));
        log_activity('DELETE_PERMANENT', $_SESSION['username'], $found['original_name']);
        echo json_encode(['success' => true, 'message' => 'Permanently deleted']);
        break;

    case 'empty_trash':
        $meta = trash_get_meta();
        foreach ($meta as $item) trash_delete_recursive($trash_dir . '/' . $item['trash_name']);
        trash_save_meta([]);
        log_activity('EMPTY_TRASH', $_SESSION['username'], count($meta) . ' items');
        echo json_encode(['success' => true, 'message' => count($meta) . ' items permanently deleted']);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
