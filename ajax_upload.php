<?php
session_start();
require_once 'config.private.php';

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

// Validate upload directory path (must be within uploads/)
$current_folder = $_POST['current_folder'] ?? '';
$upload_dir = validate_upload_path($current_folder, UPLOAD_DIR);

if ($upload_dir === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid folder path']);
    exit;
}

// Ensure directory exists
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Check files were sent
if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
    echo json_encode(['success' => false, 'message' => 'No files uploaded']);
    exit;
}

// MIME types that are always blocked regardless of extension
$blocked_mimes = [
    'application/x-php', 'text/php', 'application/php', 'text/x-php',
    'application/x-httpd-php', 'application/x-httpd-php-source',
];

$results     = [];
$total_files = count($_FILES['files']['name']);

for ($i = 0; $i < $total_files; $i++) {
    $orig_name  = $_FILES['files']['name'][$i];
    $file_tmp   = $_FILES['files']['tmp_name'][$i];
    $file_size  = $_FILES['files']['size'][$i];
    $file_error = $_FILES['files']['error'][$i];

    // Upload error
    if ($file_error !== UPLOAD_ERR_OK) {
        $results[] = ['success' => false, 'name' => $orig_name, 'message' => 'Upload error code: ' . $file_error];
        continue;
    }

    // File size check
    if ($file_size > MAX_FILE_SIZE) {
        $max_mb = MAX_FILE_SIZE / 1024 / 1024;
        $results[] = ['success' => false, 'name' => $orig_name, 'message' => "File too large (max {$max_mb}MB)"];
        continue;
    }

    // Extension whitelist
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        $results[] = ['success' => false, 'name' => $orig_name, 'message' => "File type .{$ext} not allowed"];
        continue;
    }

    // MIME type check (block dangerous MIME types)
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file_tmp);
        if (in_array($mime, $blocked_mimes, true)) {
            $results[] = ['success' => false, 'name' => $orig_name, 'message' => 'Dangerous file type detected'];
            continue;
        }
    }

    // Sanitize filename: keep only safe characters
    $safe_name = basename($orig_name);
    $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $safe_name);
    // Make sure extension matches after sanitize
    $safe_name = pathinfo($safe_name, PATHINFO_FILENAME) . '.' . $ext;

    if (empty($safe_name) || $safe_name === '.' || $safe_name === '..') {
        $results[] = ['success' => false, 'name' => $orig_name, 'message' => 'Invalid filename'];
        continue;
    }

    // Prevent overwriting: add _N counter if file exists
    $base_name    = $safe_name;
    $counter      = 1;
    $target_path  = $upload_dir . '/' . $safe_name;

    while (file_exists($target_path)) {
        $info        = pathinfo($base_name);
        $safe_name   = $info['filename'] . '_' . $counter . '.' . $info['extension'];
        $target_path = $upload_dir . '/' . $safe_name;
        $counter++;
    }

    // Move file
    if (move_uploaded_file($file_tmp, $target_path)) {
        log_activity('UPLOAD', $_SESSION['username'], $safe_name . ' → ' . ($current_folder ?: 'root'));
        $results[] = [
            'success'  => true,
            'name'     => $orig_name,
            'saved_as' => $safe_name,
            'size'     => $file_size,
        ];
    } else {
        $results[] = ['success' => false, 'name' => $orig_name, 'message' => 'Failed to save file'];
    }
}

echo json_encode([
    'success'  => true,
    'total'    => $total_files,
    'uploaded' => count(array_filter($results, fn($r) => $r['success'])),
    'failed'   => count(array_filter($results, fn($r) => !$r['success'])),
    'results'  => $results,
]);
