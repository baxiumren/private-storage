<?php
require_once __DIR__ . '/../config.private.php';
secure_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403); exit;
}
if (!csrf_verify($_GET['csrf_token'] ?? '')) {
    http_response_code(403); echo 'Invalid token'; exit;
}
if (!class_exists('ZipArchive')) {
    http_response_code(500); echo 'ZipArchive not available on this server'; exit;
}

$folder     = $_GET['folder']      ?? '';
$sub_folder = $_GET['sub_folder']  ?? '';

// Validate the target folder
$target_path = validate_upload_path(
    ($folder !== '' ? $folder . '/' : '') . $sub_folder,
    UPLOAD_DIR
);

if (!$target_path || !is_dir($target_path)) {
    http_response_code(404); echo 'Folder not found'; exit;
}

$zip_name = basename($target_path) . '_' . date('Ymd_His') . '.zip';
$tmp_file = tempnam(sys_get_temp_dir(), 'ps_zip_');

$zip = new ZipArchive();
if ($zip->open($tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500); echo 'Cannot create ZIP'; exit;
}

// Add files recursively
$base_len = strlen($target_path) + 1;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($target_path, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($it as $file) {
    if (!$file->isFile()) continue;
    $local_path = substr($file->getPathname(), $base_len);
    $zip->addFile($file->getPathname(), $local_path);
}
$zip->close();

log_activity('ZIP_DOWNLOAD', $_SESSION['username'], $sub_folder ?: $folder);

// Stream to browser
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip_name . '"');
header('Content-Length: ' . filesize($tmp_file));
header('Cache-Control: no-cache');
readfile($tmp_file);
unlink($tmp_file);
