<?php
require_once 'config.private.php';
secure_session_start();

// Log aktivitas logout ke file
if (isset($_SESSION['username'])) {
    if (!file_exists(DATA_DIR)) {
        mkdir(DATA_DIR, 0750, true);
    }
    $log_line = date('Y-m-d H:i:s')
        . ' | LOGOUT'
        . ' | user:' . $_SESSION['username']
        . ' | ip:'   . ($_SESSION['ip'] ?? $_SERVER['REMOTE_ADDR'])
        . PHP_EOL;
    file_put_contents(DATA_DIR . '/activity.log', $log_line, FILE_APPEND | LOCK_EX);
}

// Hapus semua session
session_unset();
session_destroy();

// Redirect ke login
header('Location: index.php?message=loggedout');
exit;
