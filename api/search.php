<?php
require_once __DIR__ . '/../config.private.php';
secure_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']); exit;
}

$query = trim($_GET['q'] ?? '');
if (mb_strlen($query) < 2) {
    echo json_encode(['success' => false, 'message' => 'Query must be at least 2 characters']); exit;
}

$base    = realpath(UPLOAD_DIR);
$results = [];

try {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $pathname = str_replace('\\', '/', $item->getPathname());
        if (strpos($pathname, '/.trash') !== false) continue;

        $name = $item->getFilename();
        if (stripos($name, $query) === false) continue;

        $rel    = ltrim(str_replace(str_replace('\\', '/', $base), '', $pathname), '/');
        $parts  = explode('/', $rel);
        array_pop($parts);
        $folder = implode('/', $parts);
        $is_dir = $item->isDir();

        $results[] = [
            'name'     => $name,
            'folder'   => $folder,
            'is_dir'   => $is_dir,
            'size'     => $is_dir ? -1 : $item->getSize(),
            'ext'      => $is_dir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            'modified' => $item->getMTime(),
        ];

        if (count($results) >= 150) break;
    }
} catch (Exception $e) {}

usort($results, fn($a, $b) => $b['modified'] - $a['modified']);
echo json_encode(['success' => true, 'results' => $results, 'query' => htmlspecialchars($query)]);
