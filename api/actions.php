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

$action         = $_POST['action'] ?? '';
$current_folder = $_POST['current_folder'] ?? '';

// Validate base path — must be within uploads/
$base_path = validate_upload_path($current_folder, UPLOAD_DIR);
if ($base_path === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid folder path']);
    exit;
}

function sendResponse($success, $message, $data = []) {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

// Validate a bare filename (no path separators)
function validate_filename($name) {
    return preg_match('/^[a-zA-Z0-9_.\-]+$/', $name) && $name !== '.' && $name !== '..';
}

switch ($action) {

    // ============================================================
    // 1. RENAME
    // ============================================================
    case 'rename':
        $old_name = $_POST['old_name'] ?? '';
        $new_name = $_POST['new_name'] ?? '';

        if (empty($old_name) || empty($new_name)) {
            sendResponse(false, 'Name cannot be empty');
        }

        if (!validate_filename($old_name)) sendResponse(false, 'Invalid source name');
        if (!validate_filename($new_name)) sendResponse(false, 'Invalid destination name — use letters, numbers, dot, _ or -');

        $old_path = $base_path . '/' . $old_name;
        $new_path = $base_path . '/' . $new_name;

        // Confirm both paths stay within uploads/
        $real_old  = realpath($old_path);
        $real_base = realpath(UPLOAD_DIR);
        if (!$real_old || strpos($real_old, $real_base) !== 0) {
            sendResponse(false, 'File/folder not found');
        }

        if (file_exists($new_path)) sendResponse(false, 'Name already exists');

        if (rename($old_path, $new_path)) {
            log_activity('RENAME', $_SESSION['username'], $old_name . ' → ' . $new_name);
            sendResponse(true, 'Renamed successfully');
        } else {
            sendResponse(false, 'Failed to rename');
        }
        break;

    // ============================================================
    // 2. DELETE FILE
    // ============================================================
    case 'delete_file':
        $file_name = $_POST['file_name'] ?? '';

        if (empty($file_name)) sendResponse(false, 'File name cannot be empty');
        if (!validate_filename($file_name)) sendResponse(false, 'Invalid file name');

        $file_path = $base_path . '/' . $file_name;
        $real_path = realpath($file_path);
        $real_base = realpath(UPLOAD_DIR);

        if (!$real_path || strpos($real_path, $real_base) !== 0) {
            sendResponse(false, 'File not found');
        }

        if (is_dir($real_path)) sendResponse(false, 'This is a folder — use delete_folder action');

        if (unlink($real_path)) {
            log_activity('DELETE_FILE', $_SESSION['username'], $file_name . ' in ' . ($current_folder ?: 'root'));
            sendResponse(true, 'File deleted successfully');
        } else {
            sendResponse(false, 'Failed to delete file');
        }
        break;

    // ============================================================
    // 3. DELETE FOLDER (recursive)
    // ============================================================
    case 'delete_folder':
        $folder_name = $_POST['folder_name'] ?? '';

        if (empty($folder_name)) sendResponse(false, 'Folder name cannot be empty');
        if (!validate_filename($folder_name)) sendResponse(false, 'Invalid folder name');

        $folder_path = $base_path . '/' . $folder_name;
        $real_path   = realpath($folder_path);
        $real_base   = realpath(UPLOAD_DIR);

        if (!$real_path || strpos($real_path, $real_base) !== 0) {
            sendResponse(false, 'Folder not found');
        }

        if (!is_dir($real_path)) sendResponse(false, 'This is a file — use delete_file action');

        function deleteFolderRecursive($dir) {
            if (!file_exists($dir)) return true;
            if (!is_dir($dir))      return unlink($dir);

            foreach (scandir($dir) as $item) {
                if ($item === '.' || $item === '..') continue;
                if (!deleteFolderRecursive($dir . DIRECTORY_SEPARATOR . $item)) return false;
            }

            return rmdir($dir);
        }

        if (deleteFolderRecursive($real_path)) {
            log_activity('DELETE_FOLDER', $_SESSION['username'], $folder_name . ' in ' . ($current_folder ?: 'root'));
            sendResponse(true, 'Folder deleted successfully');
        } else {
            sendResponse(false, 'Failed to delete folder');
        }
        break;

    // ============================================================
    // 4. COPY FILE (duplicate in same folder)
    // ============================================================
    case 'copy_file':
        $file_name = $_POST['file_name'] ?? '';

        if (empty($file_name)) sendResponse(false, 'File name cannot be empty');
        if (!validate_filename($file_name)) sendResponse(false, 'Invalid file name');

        $src_path  = $base_path . '/' . $file_name;
        $real_src  = realpath($src_path);
        $real_base = realpath(UPLOAD_DIR);

        if (!$real_src || strpos($real_src, $real_base) !== 0) sendResponse(false, 'File not found');
        if (is_dir($real_src)) sendResponse(false, 'Cannot duplicate a folder');

        $info      = pathinfo($file_name);
        $ext       = isset($info['extension']) ? '.' . $info['extension'] : '';
        $copy_name = $info['filename'] . '_copy' . $ext;
        $dest_path = $base_path . '/' . $copy_name;
        $counter   = 2;
        while (file_exists($dest_path)) {
            $copy_name = $info['filename'] . '_copy' . $counter . $ext;
            $dest_path = $base_path . '/' . $copy_name;
            $counter++;
        }

        if (copy($real_src, $dest_path)) {
            log_activity('COPY_FILE', $_SESSION['username'], $file_name . ' → ' . $copy_name . ' in ' . ($current_folder ?: 'root'));
            sendResponse(true, 'Duplicated as ' . $copy_name);
        } else {
            sendResponse(false, 'Failed to duplicate file');
        }
        break;

    default:
        sendResponse(false, 'Unknown action');
        break;
}
