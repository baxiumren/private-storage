<?php
require_once __DIR__ . '/../config.private.php';
secure_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']); exit;
}
if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']); exit;
}

$current_folder = $_POST['current_folder'] ?? '';
$items_json     = $_POST['items'] ?? '[]';
$pattern        = trim($_POST['pattern'] ?? '');
$start_num      = max(1, (int)($_POST['start_num'] ?? 1));
$padding        = max(1, min(6, (int)($_POST['padding'] ?? 3)));

$base_path = validate_upload_path($current_folder, UPLOAD_DIR);
if ($base_path === false) { echo json_encode(['success' => false, 'message' => 'Invalid folder']); exit; }

if (empty($pattern) || !preg_match('/^[a-zA-Z0-9_\- ]+$/', $pattern)) {
    echo json_encode(['success' => false, 'message' => 'Invalid pattern — only letters, numbers, spaces, _ or -']); exit;
}

$items = json_decode($items_json, true);
if (!is_array($items) || empty($items)) {
    echo json_encode(['success' => false, 'message' => 'No items selected']); exit;
}

$real_base = realpath(UPLOAD_DIR);
$results   = [];
$n         = $start_num;

foreach ($items as $item) {
    $old_name = $item['name'] ?? '';
    if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $old_name) || $old_name === '.' || $old_name === '..') {
        $results[] = ['name' => $old_name, 'success' => false, 'message' => 'Invalid name'];
        continue;
    }
    $old_path = $base_path . '/' . $old_name;
    $real_old = realpath($old_path);
    if (!$real_old || strpos($real_old, $real_base) !== 0) {
        $results[] = ['name' => $old_name, 'success' => false, 'message' => 'Not found'];
        continue;
    }
    $ext      = is_file($real_old) ? ('.' . strtolower(pathinfo($old_name, PATHINFO_EXTENSION))) : '';
    $num_str  = str_pad($n, $padding, '0', STR_PAD_LEFT);
    $new_name = str_replace(' ', '_', $pattern) . '_' . $num_str . $ext;
    $new_path = $base_path . '/' . $new_name;

    if (file_exists($new_path) && realpath($new_path) !== $real_old) {
        $results[] = ['name' => $old_name, 'success' => false, 'message' => $new_name . ' already exists'];
        $n++; continue;
    }
    if (rename($real_old, $new_path)) {
        $results[] = ['name' => $old_name, 'success' => true, 'new_name' => $new_name];
        $n++;
    } else {
        $results[] = ['name' => $old_name, 'success' => false, 'message' => 'Rename failed'];
    }
}

$succeeded = count(array_filter($results, fn($r) => $r['success']));
log_activity('BATCH_RENAME', $_SESSION['username'], "Renamed {$succeeded} items with pattern '{$pattern}' in " . ($current_folder ?: 'root'));
echo json_encode(['success' => true, 'results' => $results, 'renamed' => $succeeded]);
