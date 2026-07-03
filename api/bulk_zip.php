<?php
require_once __DIR__ . '/../config.private.php';
secure_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403); exit('Not logged in');
}
if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403); exit('Invalid CSRF token');
}

$current_folder = $_POST['current_folder'] ?? '';
$items_json     = $_POST['items'] ?? '[]';

$base_path = validate_upload_path($current_folder, UPLOAD_DIR);
if ($base_path === false) { http_response_code(400); exit('Invalid folder path'); }

$items = json_decode($items_json, true);
if (!is_array($items) || empty($items)) { http_response_code(400); exit('No items'); }

if (!class_exists('ZipArchive')) { http_response_code(500); exit('ZipArchive not available'); }

$zip_name = 'download_' . date('Ymd_His') . '.zip';
$zip_tmp  = sys_get_temp_dir() . '/' . $zip_name;

$zip = new ZipArchive();
if ($zip->open($zip_tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500); exit('Failed to create ZIP');
}

$added = 0;
$real_base = realpath(UPLOAD_DIR);

foreach ($items as $item) {
    $name = $item['name'] ?? '';
    if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $name) || $name === '.' || $name === '..') continue;

    $item_path = $base_path . '/' . $name;
    $real_path = realpath($item_path);
    if (!$real_path || strpos($real_path, $real_base) !== 0) continue;

    if (is_file($real_path)) {
        $zip->addFile($real_path, $name);
        $added++;
    } elseif (is_dir($real_path)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real_path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $rel = $name . '/' . str_replace('\\', '/', str_replace($real_path . DIRECTORY_SEPARATOR, '', $f->getPathname()));
                $zip->addFile($f->getPathname(), $rel);
                $added++;
            }
        }
    }
}

$zip->close();

if ($added === 0 || !file_exists($zip_tmp)) {
    http_response_code(400); exit('No files could be zipped');
}

log_activity('BULK_ZIP', $_SESSION['username'], $added . ' items from ' . ($current_folder ?: 'root'));

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip_name . '"');
header('Content-Length: ' . filesize($zip_tmp));
header('Cache-Control: no-cache, no-store');
readfile($zip_tmp);
unlink($zip_tmp);
exit;
