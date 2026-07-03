<?php
require_once __DIR__ . '/../config.private.php';
secure_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']); exit;
}
if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']); exit;
}

$action     = $_POST['action'] ?? '';
$share_file = DATA_DIR . '/share_links.json';

function get_shares() {
    global $share_file;
    if (!file_exists($share_file)) return [];
    return json_decode(file_get_contents($share_file), true) ?? [];
}
function save_shares($data) {
    global $share_file;
    if (!file_exists(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
    file_put_contents($share_file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}
function share_base_url() {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path  = dirname($_SERVER['REQUEST_URI'] ?? '/');
    // Endpoint ini ada di /api — share.php publik ada satu level di atasnya (root app)
    $path  = preg_replace('~/api/?$~', '', str_replace('\\', '/', $path));
    return rtrim($proto . '://' . $host . $path, '/');
}

switch ($action) {

    case 'create':
        $file_name     = $_POST['file_name'] ?? '';
        $folder        = $_POST['current_folder'] ?? '';
        $expires_hours = max(1, min(720, (int)($_POST['expires_hours'] ?? 24)));

        if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $file_name) || $file_name === '.' || $file_name === '..') {
            echo json_encode(['success' => false, 'message' => 'Invalid file name']); exit;
        }
        $base_path = validate_upload_path($folder, UPLOAD_DIR);
        if ($base_path === false) { echo json_encode(['success' => false, 'message' => 'Invalid folder']); exit; }

        $real_path = realpath($base_path . '/' . $file_name);
        $real_base = realpath(UPLOAD_DIR);
        if (!$real_path || strpos($real_path, $real_base) !== 0 || is_dir($real_path)) {
            echo json_encode(['success' => false, 'message' => 'File not found']); exit;
        }

        $token   = bin2hex(random_bytes(16));
        $expires = time() + ($expires_hours * 3600);

        $shares = get_shares();
        // Prune expired
        $shares = array_filter($shares, fn($s) => ($s['expires'] ?? 0) > time());
        $shares[$token] = [
            'file'       => $file_name,
            'folder'     => $folder,
            'expires'    => $expires,
            'created_at' => time(),
        ];
        save_shares($shares);
        log_activity('CREATE_SHARE', $_SESSION['username'], $file_name . ' expires in ' . $expires_hours . 'h');

        $url = share_base_url() . '/share.php?t=' . $token;
        echo json_encode(['success' => true, 'url' => $url, 'expires_fmt' => date('Y-m-d H:i', $expires), 'token' => $token]);
        break;

    case 'list':
        $shares = get_shares();
        $out = [];
        foreach ($shares as $token => $s) {
            if (($s['expires'] ?? 0) <= time()) continue;
            $out[] = ['token' => $token, 'file' => $s['file'], 'folder' => $s['folder'],
                      'expires_fmt' => date('Y-m-d H:i', $s['expires'])];
        }
        echo json_encode(['success' => true, 'shares' => $out]);
        break;

    case 'delete':
        $token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
        $shares = get_shares();
        unset($shares[$token]);
        save_shares($shares);
        log_activity('DELETE_SHARE', $_SESSION['username'], 'Token: ' . substr($token, 0, 8) . '…');
        echo json_encode(['success' => true, 'message' => 'Share link deleted']);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
