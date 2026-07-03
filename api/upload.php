<?php
require_once __DIR__ . '/../config.private.php';
secure_session_start();

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Deteksi POST body melebihi post_max_size:
// PHP mengosongkan $_POST & $_FILES total, jadi cek CONTENT_LENGTH manual
$content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($content_length > 0 && empty($_POST) && empty($_FILES)) {
    $post_max = ini_get('post_max_size');
    echo json_encode([
        'success' => false,
        'message' => "Upload melebihi batas server (post_max_size = {$post_max}). Coba file yang lebih kecil.",
    ]);
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

if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Check files were sent
if (!isset($_FILES['files']) || !is_array($_FILES['files']['name']) || $_FILES['files']['name'][0] === '') {
    echo json_encode(['success' => false, 'message' => 'No files uploaded']);
    exit;
}

// MIME types that are always blocked regardless of extension
$blocked_mimes = [
    'application/x-php', 'text/php', 'application/php', 'text/x-php',
    'application/x-httpd-php', 'application/x-httpd-php-source',
];

// Pesan error upload PHP yang manusiawi
function upload_err_msg($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'File melebihi batas ukuran server (upload_max_filesize)';
        case UPLOAD_ERR_PARTIAL:   return 'Upload terputus — file hanya terkirim sebagian, coba lagi';
        case UPLOAD_ERR_NO_FILE:   return 'Tidak ada file yang terkirim';
        case UPLOAD_ERR_NO_TMP_DIR:return 'Folder temp server tidak ada (hubungi admin)';
        case UPLOAD_ERR_CANT_WRITE:return 'Server gagal menulis file ke disk';
        case UPLOAD_ERR_EXTENSION: return 'Upload diblokir ekstensi PHP server';
        default:                   return 'Upload error (code ' . $code . ')';
    }
}

$results     = [];
$total_files = count($_FILES['files']['name']);

for ($i = 0; $i < $total_files; $i++) {
    $orig_name  = $_FILES['files']['name'][$i];
    $file_tmp   = $_FILES['files']['tmp_name'][$i];
    $file_size  = $_FILES['files']['size'][$i];
    $file_error = $_FILES['files']['error'][$i];

    if ($file_error !== UPLOAD_ERR_OK) {
        $results[] = ['success' => false, 'name' => $orig_name, 'message' => upload_err_msg($file_error)];
        continue;
    }

    if ($file_size > MAX_FILE_SIZE) {
        $max_mb = MAX_FILE_SIZE / 1024 / 1024;
        $results[] = ['success' => false, 'name' => $orig_name, 'message' => "File terlalu besar (max {$max_mb}MB)"];
        continue;
    }

    // Extension whitelist
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        $results[] = ['success' => false, 'name' => $orig_name, 'message' => "Tipe file .{$ext} tidak diizinkan"];
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
