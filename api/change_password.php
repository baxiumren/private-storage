<?php
require_once __DIR__ . '/../config.private.php';
secure_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']); exit;
}
if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid token']); exit;
}

$username    = $_SESSION['username'];
$current_pw  = $_POST['current_password'] ?? '';
$new_pw      = $_POST['new_password']     ?? '';
$confirm_pw  = $_POST['confirm_password'] ?? '';

// Verify current password
if (!isset($valid_users[$username]) || !password_verify($current_pw, $valid_users[$username])) {
    echo json_encode(['success' => false, 'message' => 'Current password is incorrect']); exit;
}

// Validate new password
if (strlen($new_pw) < 8) {
    echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters']); exit;
}
if ($new_pw !== $confirm_pw) {
    echo json_encode(['success' => false, 'message' => 'New passwords do not match']); exit;
}
if ($new_pw === $current_pw) {
    echo json_encode(['success' => false, 'message' => 'New password must be different from current']); exit;
}

// Generate new hash and update config.private.php
$new_hash   = password_hash($new_pw, PASSWORD_BCRYPT);
$config_file = __DIR__ . '/../config.private.php';
$config      = file_get_contents($config_file);

// Replace the hash for this username
// Use callback to avoid $ in hash being treated as backreference
$pattern = "/(['\"]" . preg_quote($username, '/') . "['\"]\\s*=>\\s*['\"])[^'\"]+(['\"])/";
$replaced = preg_replace_callback($pattern, function($m) use ($new_hash) {
    return $m[1] . $new_hash . $m[2];
}, $config, 1, $count);

if (!$count || $replaced === null) {
    echo json_encode(['success' => false, 'message' => 'Failed to locate user in config']); exit;
}

if (file_put_contents($config_file, $replaced) === false) {
    echo json_encode(['success' => false, 'message' => 'Failed to write config file']); exit;
}

log_activity('CHANGE_PASSWORD', $username, 'Password changed successfully');
echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
