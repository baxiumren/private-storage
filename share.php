<?php
require_once 'config.private.php';

$token = preg_replace('/[^a-f0-9]/', '', $_GET['t'] ?? '');
if (empty($token)) { http_response_code(404); exit('Not found'); }

$share_file = DATA_DIR . '/share_links.json';
if (!file_exists($share_file)) { http_response_code(404); exit('Not found'); }

$shares = json_decode(file_get_contents($share_file), true) ?? [];
if (!isset($shares[$token])) { http_response_code(404); exit('Link not found or already expired'); }

$share = $shares[$token];
if ($share['expires'] < time()) {
    unset($shares[$token]);
    file_put_contents($share_file, json_encode($shares, JSON_PRETTY_PRINT), LOCK_EX);
    http_response_code(410);
    exit('This share link has expired');
}

$base_path = validate_upload_path($share['folder'], UPLOAD_DIR);
if ($base_path === false) { http_response_code(404); exit('Not found'); }

$real_path = realpath($base_path . '/' . $share['file']);
$real_base = realpath(UPLOAD_DIR);
if (!$real_path || strpos($real_path, $real_base) !== 0 || !is_file($real_path)) {
    http_response_code(404); exit('File not found');
}

$ext = strtolower(pathinfo($real_path, PATHINFO_EXTENSION));
$mime_map = [
    'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
    'webp'=>'image/webp','bmp'=>'image/bmp','svg'=>'image/svg+xml',
    'pdf'=>'application/pdf','mp4'=>'video/mp4','webm'=>'video/webm',
    'mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg',
    'txt'=>'text/plain','json'=>'application/json','xml'=>'application/xml',
    'csv'=>'text/csv',
];
$mime = $mime_map[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . addslashes(basename($real_path)) . '"');
header('Content-Length: ' . filesize($real_path));
header('Cache-Control: public, max-age=3600');
readfile($real_path);
exit;
