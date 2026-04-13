<?php
session_start();
require_once 'config.private.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) { header('Location: index.php'); exit; }
if ($_SESSION['ip'] !== $_SERVER['REMOTE_ADDR'] || $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
    session_destroy(); header('Location: index.php?reason=hijack'); exit;
}
if (time() - $_SESSION['login_time'] > $session_timeout) {
    session_destroy(); header('Location: index.php?reason=timeout'); exit;
}

$csrf_token = csrf_token();
if (!file_exists(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

$current_folder = trim(str_replace(["\0",'\\'], ['','/'], $_GET['folder'] ?? ''), '/');
$upload_base    = realpath(UPLOAD_DIR);
$scan_dir       = validate_upload_path($current_folder, UPLOAD_DIR);
if ($scan_dir === false) { $scan_dir = $upload_base; $current_folder = ''; }

$file_list = []; $folder_list = [];
foreach (scandir($scan_dir) as $file) {
    if ($file === '.' || $file === '..') continue;
    $path = $scan_dir . '/' . $file; $is_dir = is_dir($path);
    $item = ['name'=>$file,'is_dir'=>$is_dir,'size'=>$is_dir?'-':filesize($path),'modified'=>filemtime($path),'ext'=>$is_dir?'':strtolower(pathinfo($file,PATHINFO_EXTENSION)),'count'=>$is_dir?count(array_diff(scandir($path),['.','..'])):0];
    if ($is_dir) $folder_list[] = $item; else $file_list[] = $item;
}
usort($folder_list, fn($a,$b)=>strcasecmp($a['name'],$b['name']));
usort($file_list,   fn($a,$b)=>strcasecmp($a['name'],$b['name']));
$all_items = array_merge($folder_list, $file_list);

function format_size($b) {
    if ($b==='-') return '-';
    $u=['B','KB','MB','GB']; $i=0;
    while($b>=1024&&$i<3){$b/=1024;$i++;} return round($b,2).' '.$u[$i];
}
function get_file_icon($ext,$is_dir) {
    if($is_dir)return'📁';
    $m=['jpg'=>'🖼️','jpeg'=>'🖼️','png'=>'🖼️','gif'=>'🖼️','bmp'=>'🖼️','webp'=>'🖼️','svg'=>'🖼️',
        'pdf'=>'📕','doc'=>'📄','docx'=>'📄','txt'=>'📝','rtf'=>'📝','md'=>'📝',
        'zip'=>'📦','rar'=>'📦','7z'=>'📦','tar'=>'📦','gz'=>'📦',
        'mp3'=>'🎵','wav'=>'🎵','flac'=>'🎵','m4a'=>'🎵','ogg'=>'🎵',
        'mp4'=>'🎬','avi'=>'🎬','mkv'=>'🎬','mov'=>'🎬','wmv'=>'🎬','webm'=>'🎬',
        'json'=>'📋','xml'=>'📋','csv'=>'📊','xls'=>'📊','xlsx'=>'📊'];
    return $m[$ext]??'📄';
}

$relative_path = str_replace($upload_base,'',$scan_dir);
$breadcrumbs   = array_filter(explode('/',trim($relative_path,'/')));
$total_size    = array_sum(array_map(fn($f)=>$f['size']==='-'?0:$f['size'], $file_list));

// Storage used (recursive)
function get_dir_size($dir) {
    if (!is_dir($dir)) return 0; $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f)
        if ($f->isFile()) $size += $f->getSize();
    return $size;
}
$storage_used = get_dir_size(UPLOAD_DIR);

// All folders for move modal
function get_all_folders($base) {
    $list = [['path'=>'','label'=>'📁 Root /']];
    if (!is_dir($base)) return $list;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
        if (!$item->isDir()) continue;
        $rel   = str_replace(['\\','//'],['/',''],str_replace($base,'',$item->getPathname()));
        $rel   = ltrim($rel,'/');
        $depth = substr_count($rel,'/');
        $list[] = ['path'=>$rel,'label'=>str_repeat('  ',$depth).'📁 '.$item->getFilename()];
    }
    return $list;
}
$all_folders_json = json_encode(get_all_folders($upload_base));

// Folder meta (colors/icons)
$meta_file   = DATA_DIR . '/folder_meta.json';
$folder_meta = file_exists($meta_file) ? (json_decode(file_get_contents($meta_file), true) ?? []) : [];

// Recent files (cross-folder, last 10)
function get_recent_files($base, $limit = 10) {
    $files = [];
    if (!is_dir($base)) return $files;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $path = str_replace('\\', '/', $file->getPathname());
            if (strpos($path, '/.trash') !== false) continue;
            $rel    = ltrim(str_replace(str_replace('\\','/',$base), '', $path), '/');
            $parts  = explode('/', $rel);
            $name   = array_pop($parts);
            $folder = implode('/', $parts);
            $files[] = ['name'=>$name,'folder'=>$folder,'modified'=>$file->getMTime(),'size'=>$file->getSize(),'ext'=>strtolower(pathinfo($name,PATHINFO_EXTENSION))];
        }
    } catch (Exception $e) {}
    usort($files, fn($a,$b) => $b['modified'] - $a['modified']);
    return array_slice($files, 0, $limit);
}
$recent_files = get_recent_files($upload_base);

// Trash count
$trash_meta_file = DATA_DIR . '/trash_meta.json';
$trash_count = file_exists($trash_meta_file) ? count(json_decode(file_get_contents($trash_meta_file), true) ?? []) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Private Storage</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='85'>🗄️</text></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{
    --maroon:#3b82f6;--maroon-dark:#1d4ed8;--maroon-light:#2563eb;--maroon-bright:#60a5fa;
    --gold:#93c5fd;--gold-light:#bfdbfe;
    --bg:#040c18;--glass:rgba(30,80,200,.08);--glass-hover:rgba(30,80,200,.15);
    --glass-border:rgba(59,130,246,.18);--glass-bright:rgba(96,165,250,.35);
    --text:#e4edf5;--text-dim:#8aaac0;--text-muted:#3d5570;
    --success:#52c788;--danger:#e05050;--info:#7cb5f5;
    --radius:14px;--radius-sm:8px;
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;transition:background .3s,color .3s;}

/* ══════════════════════════════════════
   LIGHT MODE — Yin & Yang (White/Black)
   ══════════════════════════════════════ */
body.light{
    --maroon:#2563eb;--maroon-dark:#1d4ed8;--maroon-light:#3b7af5;--maroon-bright:#60a5fa;
    --gold:#1d4ed8;--gold-light:#3b82f6;
    --bg:#eef2fb;--glass:rgba(37,99,235,.05);--glass-hover:rgba(37,99,235,.09);
    --glass-border:rgba(37,99,235,.15);--glass-bright:rgba(96,165,250,.35);
    --text:#0d0d0d;--text-dim:#444;--text-muted:#888;
    --success:#16a34a;--danger:#dc2626;--info:#2563eb;
    --radius:14px;--radius-sm:8px;
    color:#0d0d0d; background:#eef2fb;
}

/* Background */
body.light .bg-orb{ opacity:0; }
body.light .bg-grid{
    background-image:linear-gradient(rgba(0,0,0,.04) 1px,transparent 1px),
                     linear-gradient(90deg,rgba(0,0,0,.04) 1px,transparent 1px);
    background-size:44px 44px;
}

/* Navbar */
body.light .navbar{
    background:rgba(255,255,255,.95);
    border-bottom:1px solid rgba(0,0,0,.08);
    box-shadow:0 1px 12px rgba(0,0,0,.06);
}
body.light .nav-logo-text{ color:#0d0d0d; font-weight:800; }
body.light .nav-logo-sub{ color:#999; }
body.light .nav-logo-icon{
    background:linear-gradient(135deg,#111,#333);
    box-shadow:0 4px 16px rgba(0,0,0,.25);
}
body.light .nav-stat-val{ color:#111; }
body.light .nav-stats{ background:rgba(0,0,0,.04);border-color:rgba(0,0,0,.08); }
body.light .nav-stat-lbl{ color:#999; }
body.light .nav-username{ color:#0d0d0d; font-weight:700; }
body.light .nav-ip{ color:#aaa; }
body.light .btn-icon{
    background:#fff;border-color:rgba(0,0,0,.1);color:#555;
    box-shadow:0 1px 4px rgba(0,0,0,.06);
}
body.light .btn-icon:hover{ background:#f5f5f5;border-color:rgba(0,0,0,.2);color:#111; }
body.light .btn-logout{
    background:rgba(220,38,38,.07);border-color:rgba(220,38,38,.18);color:#dc2626;
}
body.light .btn-logout:hover{ background:rgba(220,38,38,.13);border-color:rgba(220,38,38,.35); }

/* Cards */
body.light .glass-card{
    background:#fff;
    border:1px solid rgba(0,0,0,.08);
    box-shadow:0 2px 12px rgba(0,0,0,.06);
    backdrop-filter:none;
}
body.light .panel-heading{ color:#666; }
body.light .panel-heading i{ color:#111; }

/* Inputs */
body.light .field-input{
    background:#fafafa;border-color:rgba(0,0,0,.12);color:#0d0d0d;
    box-shadow:0 1px 3px rgba(0,0,0,.05);
}
body.light .field-input:focus{
    background:#fff;border-color:#111;box-shadow:0 0 0 3px rgba(0,0,0,.08);
}
body.light .field-input::placeholder{ color:#bbb; }
body.light .field-label{ color:#777; }
body.light select.field-input option{ background:#fff;color:#0d0d0d; }

/* Buttons */
body.light .btn-primary{
    background:linear-gradient(135deg,#1d4ed8,#3b82f6);
    border-color:rgba(37,99,235,.3);color:#fff;
    box-shadow:0 4px 14px rgba(29,78,216,.3);
}
body.light .btn-primary:hover{
    background:linear-gradient(135deg,#1e40af,#2563eb);
    box-shadow:0 6px 20px rgba(29,78,216,.45);
}
body.light .btn-ghost{
    background:#fff;border-color:rgba(0,0,0,.1);color:#555;
    box-shadow:0 1px 4px rgba(0,0,0,.05);
}
body.light .btn-ghost:hover{ background:#f5f5f5;border-color:rgba(0,0,0,.2);color:#111; }
body.light .btn-danger{ background:rgba(220,38,38,.07);border-color:rgba(220,38,38,.2);color:#dc2626; }
body.light .btn-danger:hover{ background:rgba(220,38,38,.14);border-color:rgba(220,38,38,.4); }
body.light .btn-success{ background:rgba(22,163,74,.08);border-color:rgba(22,163,74,.22);color:#16a34a; }
body.light .btn-info{ background:rgba(37,99,235,.08);border-color:rgba(37,99,235,.2);color:#2563eb; }
body.light .btn-gold{ background:rgba(230,126,34,.08);border-color:rgba(230,126,34,.22);color:#c05c00; }

/* Upload zone */
body.light .drop-zone{
    background:#fafafa;border-color:rgba(0,0,0,.12);
}
body.light .drop-zone:hover,body.light .drop-zone.active{
    border-color:rgba(0,0,0,.3);background:rgba(0,0,0,.02);
}
body.light .drop-icon{ color:#333; }
body.light .drop-title{ color:#444; }
body.light .drop-sub{ color:#999; }
body.light .file-chip{ background:#f5f5f5;border-color:rgba(0,0,0,.08); }
body.light .file-chip-name{ color:#0d0d0d; }
body.light .progress-bar-fill{ background:linear-gradient(90deg,#111,#444); }

/* Table */
body.light .file-table thead th{
    background:#f5f5f5;color:#777;
    border-bottom:2px solid rgba(0,0,0,.08);
}
body.light .file-table thead th.sortable:hover{ color:#111; }
body.light .file-table tbody tr td{
    color:#0d0d0d;border-bottom-color:rgba(0,0,0,.05);
}
body.light .file-table tbody tr:hover td{ background:rgba(0,0,0,.025); }
body.light .file-table tbody tr.selected-row td{ background:rgba(0,0,0,.04)!important; }
body.light .item-name{ color:#0d0d0d;font-weight:500; }
body.light .item-meta{ color:#aaa; }
body.light .size-text{ color:#777; }
body.light .ext-badge{ background:#eee;color:#555;border-color:rgba(0,0,0,.1); }

/* Grid mode cards — THIS is the key fix */
body.light .grid-mode tbody tr{
    background:#fff!important;
    border-color:rgba(0,0,0,.08)!important;
    box-shadow:0 2px 10px rgba(0,0,0,.06);
}
body.light .grid-mode tbody tr:hover{
    border-color:rgba(0,0,0,.2)!important;
    box-shadow:0 6px 20px rgba(0,0,0,.1);
    transform:translateY(-3px);
}
body.light .grid-mode tbody tr.selected-row{
    border-color:#111!important;
    box-shadow:0 0 0 2px rgba(0,0,0,.2)!important;
}
body.light .grid-mode td:nth-child(6){ border-top-color:rgba(0,0,0,.06); }
body.light .grid-mode .fname{ color:#0d0d0d; }
body.light .grid-mode .thumb{ border-bottom-color:rgba(0,0,0,.06); }
body.light .grid-mode .chk-td{ background:rgba(255,255,255,.85); }

/* Breadcrumb */
body.light .breadcrumb{ background:rgba(255,255,255,.8);border-color:rgba(0,0,0,.08); }
body.light .breadcrumb a{ color:#555; }
body.light .breadcrumb a:hover{ background:rgba(0,0,0,.05);color:#111; }
body.light .breadcrumb-sep{ color:#ccc; }
body.light .breadcrumb-count{ color:#aaa; }
body.light .btn-back{ background:#f0f0f0;border-color:rgba(0,0,0,.1);color:#333; }
body.light .btn-back:hover{ background:#e0e0e0;color:#000; }

/* Type filter bar */
body.light .type-bar{ background:rgba(255,255,255,.9);border-color:rgba(0,0,0,.08); }
body.light .type-btn{ color:#666;border-color:transparent; }
body.light .type-btn:hover{ background:rgba(0,0,0,.05);color:#111; }
body.light .type-btn.active{
    background:#2563eb;border-color:#2563eb;color:#fff;
}

/* Search */
body.light .search-input-wrap input{
    background:#fff;border-color:rgba(0,0,0,.12);color:#0d0d0d;
}
body.light .search-results-panel{
    background:#fff;border-color:rgba(0,0,0,.1);
    box-shadow:0 8px 32px rgba(0,0,0,.1);
}
body.light .search-result-item:hover{ background:#f5f5f5; }
body.light .search-result-name{ color:#0d0d0d; }
body.light .search-result-folder{ color:#aaa; }

/* Bulk bar */
body.light .bulk-bar{
    background:rgba(255,255,255,.97);border-color:rgba(0,0,0,.1);
    box-shadow:0 4px 24px rgba(0,0,0,.08);color:#0d0d0d;
}

/* Storage bar */
body.light .storage-bar-wrap{ background:rgba(255,255,255,.8);border-color:rgba(0,0,0,.08); }
body.light .storage-fill{ background:linear-gradient(90deg,#111,#555); }

/* Recent files */
body.light .recent-section{ background:rgba(255,255,255,.7);border-color:rgba(0,0,0,.08); }
body.light .recent-item:hover{ background:#f5f5f5; }
body.light .recent-name{ color:#0d0d0d; }
body.light .recent-folder{ color:#aaa; }

/* Modals */
body.light .modal-overlay{ background:rgba(0,0,0,.25); }
body.light .modal-inner{
    background:#fff;border-color:rgba(0,0,0,.1);
    box-shadow:0 24px 64px rgba(0,0,0,.15);
}
body.light .modal-title{ color:#0d0d0d;font-weight:700; }
body.light .modal-header{ border-bottom-color:rgba(0,0,0,.08); }
body.light .modal-footer{ border-top-color:rgba(0,0,0,.08); }
body.light .modal-close{ color:#aaa; }
body.light .modal-close:hover{ color:#111;background:#f0f0f0; }

/* Context menu */
body.light .ctx-menu{
    background:#fff;border-color:rgba(0,0,0,.1);
    box-shadow:0 8px 32px rgba(0,0,0,.12);
}
body.light .ctx-item{ color:#0d0d0d; }
body.light .ctx-item:hover{ background:#f5f5f5;color:#111; }
body.light .ctx-sep{ border-color:rgba(0,0,0,.08); }

/* Msg boxes */
body.light .msg-success{ background:rgba(22,163,74,.07);border-color:rgba(22,163,74,.2);color:#16a34a; }
body.light .msg-error{ background:rgba(220,38,38,.07);border-color:rgba(220,38,38,.2);color:#dc2626; }

/* Scrollbar */
body.light ::-webkit-scrollbar-track{ background:#f0f2f5; }
body.light ::-webkit-scrollbar-thumb{ background:rgba(0,0,0,.15); }
body.light ::-webkit-scrollbar-thumb:hover{ background:rgba(0,0,0,.25); }
.bg-orb{position:fixed;border-radius:50%;filter:blur(100px);pointer-events:none;z-index:0;animation:drift 12s ease-in-out infinite alternate;}
.orb-1{width:500px;height:500px;background:radial-gradient(circle,rgba(29,78,216,.38),transparent 70%);top:-150px;left:-150px;}
.orb-2{width:400px;height:400px;background:radial-gradient(circle,rgba(15,40,140,.28),transparent 70%);bottom:-100px;right:-100px;animation-delay:-6s;}
.orb-3{width:300px;height:300px;background:radial-gradient(circle,rgba(59,130,246,.18),transparent 70%);top:40%;right:20%;animation-delay:-3s;}
@keyframes drift{from{transform:translate(0,0) scale(1);}to{transform:translate(20px,15px) scale(1.04);}}
.bg-grid{position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:linear-gradient(rgba(59,130,246,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(59,130,246,.04) 1px,transparent 1px);
    background-size:44px 44px;}
.layout{position:relative;z-index:1;display:flex;flex-direction:column;min-height:100vh;}

/* ── Navbar ── */
.navbar{position:sticky;top:0;z-index:100;background:rgba(4,8,20,.88);border-bottom:1px solid var(--glass-border);
    backdrop-filter:blur(24px);padding:0 24px;height:64px;display:flex;align-items:center;justify-content:space-between;gap:16px;}
.nav-logo{display:flex;align-items:center;gap:11px;flex-shrink:0;}
.nav-logo-icon{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--maroon-dark),var(--maroon-bright));
    display:flex;align-items:center;justify-content:center;font-size:16px;box-shadow:0 4px 16px rgba(29,78,216,.5);}
.nav-logo-text{font-size:15px;font-weight:700;color:var(--text);}
.nav-logo-sub{font-size:10px;color:var(--text-muted);}
.nav-stats{display:flex;gap:0;background:rgba(255,255,255,.03);border:1px solid var(--glass-border);border-radius:10px;padding:4px 10px;}
.nav-stat{text-align:center;padding:2px 10px;}
.nav-stat:not(:last-child){border-right:1px solid var(--glass-border);}
.nav-stat-val{font-size:14px;font-weight:700;color:var(--gold);}
.nav-stat-lbl{font-size:9px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;}
.nav-actions{display:flex;align-items:center;gap:8px;}
.nav-user-info{text-align:right;}
.nav-username{font-size:13px;font-weight:600;color:var(--text);}
.nav-ip{font-size:10px;color:var(--text-muted);font-family:monospace;}
.btn-icon{width:34px;height:34px;display:flex;align-items:center;justify-content:center;
    background:rgba(255,255,255,.04);border:1px solid var(--glass-border);border-radius:8px;
    color:var(--text-dim);font-size:13px;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;}
.btn-icon:hover{background:rgba(59,130,246,.18);border-color:var(--glass-bright);color:var(--text);}
.btn-logout{display:flex;align-items:center;gap:7px;padding:7px 14px;
    background:rgba(200,0,0,.1);border:1px solid rgba(200,40,40,.25);border-radius:9px;
    color:#e07070;font-size:13px;font-weight:500;cursor:pointer;text-decoration:none;transition:all .2s;font-family:'Inter',sans-serif;}
.btn-logout:hover{background:rgba(200,0,0,.22);border-color:rgba(200,40,40,.5);color:#ff9090;}

/* ── Main ── */
.main{flex:1;max-width:1320px;width:100%;margin:0 auto;padding:24px 20px;}
.glass-card{background:rgba(5,14,35,.72);border:1px solid var(--glass-border);border-radius:var(--radius);backdrop-filter:blur(20px);}

/* ── Action row ── */
.action-row{display:grid;grid-template-columns:1fr 1.6fr;gap:14px;margin-bottom:18px;}
.panel{padding:22px;}
.panel-heading{display:flex;align-items:center;gap:9px;font-size:12px;font-weight:600;color:var(--text-dim);
    text-transform:uppercase;letter-spacing:.7px;margin-bottom:16px;}
.panel-heading i{color:var(--maroon-bright);}
.field-row{display:flex;gap:9px;align-items:flex-end;}
.field{flex:1;}
.field-label{display:block;font-size:11px;font-weight:600;color:var(--text-muted);
    text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px;}
.field-input{width:100%;padding:10px 13px;background:rgba(255,255,255,.04);border:1px solid rgba(59,130,246,.18);
    border-radius:var(--radius-sm);color:var(--text);font-size:14px;font-family:'Inter',sans-serif;outline:none;transition:all .2s;}
.field-input:focus{background:rgba(30,80,200,.08);border-color:var(--maroon-bright);box-shadow:0 0 0 3px rgba(59,130,246,.15);}
.field-input::placeholder{color:rgba(176,144,144,.35);}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:var(--radius-sm);
    font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;transition:all .2s;
    border:1px solid transparent;text-decoration:none;white-space:nowrap;}
.btn-primary{background:linear-gradient(135deg,var(--maroon-dark),var(--maroon-light));border-color:rgba(59,130,246,.3);color:#fff;box-shadow:0 4px 16px rgba(29,78,216,.35);}
.btn-primary:hover{background:linear-gradient(135deg,var(--maroon),var(--maroon-bright));box-shadow:0 6px 24px rgba(59,130,246,.55);transform:translateY(-1px);}
.btn-ghost{background:rgba(255,255,255,.04);border-color:var(--glass-border);color:var(--text-dim);}
.btn-ghost:hover{background:rgba(255,255,255,.08);border-color:var(--glass-bright);color:var(--text);}
.btn-danger{background:rgba(200,0,0,.12);border-color:rgba(200,40,40,.28);color:#e07070;}
.btn-danger:hover{background:rgba(200,0,0,.25);border-color:rgba(220,50,50,.5);color:#ff9090;}
.btn-success{background:rgba(80,200,130,.1);border-color:rgba(80,200,130,.25);color:var(--success);}
.btn-success:hover{background:rgba(80,200,130,.2);border-color:rgba(80,200,130,.45);}
.btn-info{background:rgba(120,180,245,.08);border-color:rgba(120,180,245,.22);color:var(--info);}
.btn-info:hover{background:rgba(120,180,245,.16);border-color:rgba(120,180,245,.4);}
.btn-gold{background:rgba(201,168,76,.1);border-color:rgba(201,168,76,.25);color:var(--gold);}
.btn-gold:hover{background:rgba(201,168,76,.2);border-color:rgba(201,168,76,.45);}
.btn-sm{padding:5px 10px;font-size:12px;border-radius:6px;}
.btn:disabled{opacity:.45;cursor:not-allowed;transform:none!important;}

/* ── Upload area ── */
.drop-zone{border:2px dashed rgba(59,130,246,.25);border-radius:var(--radius-sm);padding:26px 20px;text-align:center;
    cursor:pointer;transition:all .25s;background:rgba(29,78,216,.04);}
.drop-zone:hover,.drop-zone.active{border-color:rgba(96,165,250,.5);background:rgba(29,78,216,.1);}
.drop-icon{font-size:30px;color:var(--maroon-bright);margin-bottom:9px;opacity:.75;}
.drop-title{font-size:13px;font-weight:600;color:var(--text-dim);margin-bottom:3px;}
.drop-sub{font-size:12px;color:var(--text-muted);}
.file-chips{margin:12px 0;max-height:140px;overflow-y:auto;display:flex;flex-direction:column;gap:4px;}
.file-chip{display:flex;align-items:center;justify-content:space-between;padding:7px 11px;
    background:rgba(255,255,255,.03);border:1px solid var(--glass-border);border-radius:7px;font-size:12px;}
.file-chip-name{color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px;}
.progress-bar-wrap{height:5px;background:rgba(255,255,255,.06);border-radius:3px;overflow:hidden;margin-top:3px;}
.progress-bar-fill{height:100%;width:0%;border-radius:3px;background:linear-gradient(90deg,var(--maroon),var(--maroon-bright));transition:width .3s;}
.progress-meta{display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-bottom:3px;}

/* ── Msg box ── */
.msg-box{display:none;padding:10px 14px;border-radius:var(--radius-sm);font-size:13px;margin-bottom:13px;align-items:center;gap:8px;}
.msg-success{display:flex!important;background:rgba(80,200,130,.1);border:1px solid rgba(80,200,130,.25);color:var(--success);}
.msg-error{display:flex!important;background:rgba(200,0,0,.1);border:1px solid rgba(200,40,40,.28);color:#e07070;}

/* ── Breadcrumb ── */
.breadcrumb{display:flex;align-items:center;flex-wrap:wrap;gap:5px;padding:11px 16px;margin-bottom:14px;font-size:13px;}
.breadcrumb a{color:var(--text-dim);text-decoration:none;padding:3px 7px;border-radius:6px;transition:all .2s;}
.breadcrumb a:hover{background:var(--glass-hover);color:var(--text);}
.breadcrumb-sep{color:var(--text-muted);font-size:10px;}
.breadcrumb-count{margin-left:auto;font-size:12px;color:var(--text-muted);}
.btn-back{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;
    background:rgba(29,78,216,.25);border:1px solid var(--glass-bright);border-radius:7px;
    color:var(--text);transition:all .2s;flex-shrink:0;}
.btn-back:hover{background:rgba(180,0,0,.45);color:#fff;transform:translateX(-2px);}

/* ── Context Menu ── */
.ctx-menu{position:fixed;z-index:9999;background:rgba(4,10,28,.96);border:1px solid var(--glass-bright);
    border-radius:10px;padding:5px 0;min-width:195px;box-shadow:0 10px 40px rgba(0,0,0,.7);
    backdrop-filter:blur(24px);display:none;animation:ctxIn .12s ease;}
.ctx-menu.open{display:block;}
@keyframes ctxIn{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}
.ctx-item{display:flex;align-items:center;gap:10px;padding:9px 15px;font-size:13px;
    color:var(--text-dim);cursor:pointer;transition:all .12s;font-family:'Inter',sans-serif;}
.ctx-item:hover{background:rgba(59,130,246,.22);color:var(--text);}
.ctx-item i{width:14px;text-align:center;color:var(--text-muted);font-size:12px;}
.ctx-item:hover i{color:var(--gold);}
.ctx-item.ctx-danger:hover{background:rgba(200,40,40,.2);color:#e07070;}
.ctx-item.ctx-danger:hover i{color:#e07070;}
.ctx-item.ctx-disabled{opacity:.35;cursor:not-allowed;pointer-events:none;}
.ctx-sep{height:1px;background:var(--glass-border);margin:4px 0;}
.ctx-label{font-size:10px;color:var(--text-muted);padding:5px 15px 2px;text-transform:uppercase;letter-spacing:.6px;}

/* ── Drag & Drop ── */
tr[draggable="true"]{cursor:grab;}
tr[draggable="true"]:active{cursor:grabbing;}
tr.drag-over td{background:rgba(59,130,246,.2)!important;}
tr.drag-over{outline:2px dashed var(--maroon-bright);outline-offset:-2px;}
tr.dragging{opacity:.4;}

/* ── Folder count badge ── */
.count-badge{font-size:10px;color:var(--text-muted);background:rgba(255,255,255,.05);
    border:1px solid var(--glass-border);border-radius:4px;padding:1px 6px;margin-left:5px;}

/* ── Trash button badge ── */
.trash-btn{position:relative;}
.trash-badge{position:absolute;top:-5px;right:-5px;background:var(--danger);color:#fff;
    font-size:9px;font-weight:700;min-width:16px;height:16px;border-radius:8px;
    display:flex;align-items:center;justify-content:center;padding:0 3px;line-height:1;}

/* ── Recent files ── */
.recent-section{padding:14px 18px;}
.recent-header{font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;
    letter-spacing:.6px;margin-bottom:11px;display:flex;align-items:center;gap:7px;}
.recent-header i{color:var(--gold);}
.recent-files{display:flex;gap:10px;overflow-x:auto;padding-bottom:4px;}
.recent-files::-webkit-scrollbar{height:4px;}
.recent-files::-webkit-scrollbar-track{background:transparent;}
.recent-files::-webkit-scrollbar-thumb{background:rgba(59,130,246,.3);border-radius:2px;}
.recent-item{flex-shrink:0;width:88px;text-align:center;cursor:pointer;border-radius:10px;
    padding:8px 6px 7px;background:rgba(255,255,255,.02);border:1px solid var(--glass-border);
    transition:all .18s;overflow:hidden;}
.recent-item:hover{background:rgba(59,130,246,.1);border-color:var(--glass-bright);transform:translateY(-2px);}
.recent-item img{width:72px;height:56px;object-fit:cover;border-radius:7px;border:1px solid var(--glass-border);}
.recent-item>span{font-size:26px;display:block;margin:6px 0 4px;}
.recent-name{font-size:10px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;
    white-space:nowrap;max-width:76px;margin:0 auto;}

/* ── Per-page selector ── */
.perpage-select{padding:5px 9px;background:rgba(255,255,255,.04);border:1px solid var(--glass-border);
    border-radius:8px;color:var(--text-dim);font-size:12px;font-family:'Inter',sans-serif;
    cursor:pointer;outline:none;}
.perpage-select:focus{border-color:var(--maroon-bright);}
.perpage-select option{background:#1a0810;}

/* ── Folder color dot ── */
.folder-color-dot{width:11px;height:11px;border-radius:50%;display:inline-block;
    flex-shrink:0;border:1px solid rgba(255,255,255,.15);}

/* ── Storage stats bar ── */
.folder-stats-list{display:flex;flex-direction:column;gap:8px;padding:4px 0;}
.fstat-row{display:grid;grid-template-columns:140px 1fr 70px;align-items:center;gap:10px;font-size:12px;}
.fstat-name{color:var(--text-dim);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.fstat-bar-wrap{height:6px;background:rgba(255,255,255,.06);border-radius:3px;overflow:hidden;}
.fstat-bar-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,var(--maroon),var(--maroon-bright));transition:width .5s;}
.fstat-size{color:var(--text-muted);text-align:right;font-variant-numeric:tabular-nums;}

/* ── Edit file textarea ── */
.edit-textarea{width:100%;min-height:340px;background:rgba(0,0,0,.3);border:1px solid var(--glass-border);
    border-radius:10px;padding:14px;color:var(--text);font-family:'Courier New',monospace;
    font-size:12px;line-height:1.6;resize:vertical;outline:none;transition:border-color .2s;}
.edit-textarea:focus{border-color:var(--maroon-bright);}

/* ── Custom Confirm ── */
.confirm-icon{font-size:44px;margin-bottom:12px;display:block;}
.confirm-message{font-size:15px;color:var(--text);line-height:1.5;margin-bottom:6px;}
.confirm-sub{font-size:12px;color:var(--text-muted);}

/* ── Type Filter Bar ── */
.type-filter-bar{display:flex;align-items:center;gap:6px;padding:10px 16px;flex-wrap:wrap;}
.tfbtn{padding:5px 13px;border-radius:20px;border:1px solid var(--glass-border);
    background:rgba(255,255,255,.03);color:var(--text-muted);font-size:12px;font-weight:500;
    cursor:pointer;transition:all .18s;font-family:'Inter',sans-serif;white-space:nowrap;}
.tfbtn:hover{background:rgba(59,130,246,.12);border-color:var(--glass-bright);color:var(--text-dim);}
.tfbtn.active{background:rgba(59,130,246,.28);border-color:var(--maroon-bright);color:var(--text);font-weight:600;}
.type-filter-count{margin-left:auto;font-size:12px;color:var(--text-muted);}

/* ── Global Search Modal ── */
.gsearch-result{display:flex;align-items:center;gap:11px;padding:10px 16px;cursor:pointer;
    border-bottom:1px solid rgba(255,255,255,.04);transition:background .15s;}
.gsearch-result:hover{background:rgba(59,130,246,.1);}
.gsearch-result-name{font-size:13px;color:var(--text);font-weight:500;}
.gsearch-result-path{font-size:11px;color:var(--text-muted);font-family:monospace;}

/* ── Fullscreen image ── */
#previewModal.fs-mode{background:rgba(0,0,0,.99);}
#previewModal.fs-mode .modal{max-width:100vw!important;width:100vw;max-height:100vh;height:100vh;border-radius:0;border:none;margin:0;}
#previewModal.fs-mode #lbImg{max-height:calc(100vh - 130px)!important;max-width:100%!important;}

/* ── Bulk toolbar ── */
.bulk-toolbar{display:none;align-items:center;gap:10px;padding:10px 16px;
    background:rgba(29,78,216,.15);border:1px solid rgba(59,130,246,.3);
    border-radius:10px;margin-bottom:14px;flex-wrap:wrap;}
.bulk-toolbar.visible{display:flex;}
.bulk-count{font-size:13px;font-weight:600;color:var(--maroon-bright);}

/* ── File table ── */
.file-table-wrap{overflow:hidden;}
.file-table-head{padding:16px 20px;border-bottom:1px solid var(--glass-border);
    display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;}
.file-table-head h2{font-size:15px;font-weight:600;color:var(--text);display:flex;align-items:center;gap:9px;}
.file-table-head h2 i{color:var(--maroon-bright);}
.search-wrap{position:relative;flex:1;min-width:180px;max-width:340px;}
.search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:12px;pointer-events:none;transition:color .2s;}
.search-input{width:100%;padding:8px 34px 8px 34px;background:rgba(255,255,255,.04);border:1px solid rgba(59,130,246,.18);
    border-radius:9px;color:var(--text);font-size:13px;font-family:'Inter',sans-serif;outline:none;transition:all .2s;}
.search-input::placeholder{color:rgba(176,144,144,.35);}
.search-input:focus{background:rgba(30,80,200,.08);border-color:var(--maroon-bright);box-shadow:0 0 0 3px rgba(59,130,246,.15);}
.search-wrap:focus-within .search-icon{color:var(--maroon-bright);}
.search-clear{position:absolute;right:9px;top:50%;transform:translateY(-50%);background:none;border:none;
    color:var(--text-muted);cursor:pointer;font-size:12px;display:none;padding:2px 4px;}
.search-clear:hover{color:var(--text);}
.search-count{font-size:12px;color:var(--text-muted);white-space:nowrap;}
.view-toggle{display:flex;gap:3px;background:rgba(255,255,255,.03);border:1px solid var(--glass-border);border-radius:8px;padding:3px;}
.view-btn{padding:5px 10px;border-radius:5px;border:none;background:transparent;color:var(--text-muted);font-size:13px;cursor:pointer;transition:all .2s;}
.view-btn.active{background:rgba(59,130,246,.35);color:var(--text);}
.view-btn:hover:not(.active){color:var(--text-dim);}

table{width:100%;border-collapse:collapse;}
thead th{padding:11px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--text-muted);
    text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid var(--glass-border);white-space:nowrap;}
th.sortable{cursor:pointer;user-select:none;transition:color .2s;}
th.sortable:hover{color:var(--text-dim);}
th.sortable .sort-icon{margin-left:5px;opacity:.4;font-size:10px;}
th.sort-asc .sort-icon::before{content:'↑';opacity:1;color:var(--gold);}
th.sort-desc .sort-icon::before{content:'↓';opacity:1;color:var(--gold);}
th.sort-asc,th.sort-desc{color:var(--gold);}
.chk-th{width:36px;padding:11px 8px 11px 16px!important;}
tbody tr{transition:background .15s;}
tbody tr:hover{background:rgba(59,130,246,.07);}
tbody tr.selected-row{background:rgba(29,78,216,.12)!important;border-left:2px solid var(--maroon-bright);}
tbody tr:not(:last-child) td{border-bottom:1px solid rgba(255,255,255,.04);}
td{padding:11px 14px;vertical-align:middle;}
.chk-td{padding:11px 8px 11px 16px!important;}
input[type=checkbox]{accent-color:var(--maroon-bright);width:15px;height:15px;cursor:pointer;}

.name-cell{display:flex;align-items:center;gap:9px;}
.thumb{width:42px;height:42px;border-radius:7px;object-fit:cover;flex-shrink:0;border:1px solid var(--glass-border);}
.file-emoji{font-size:20px;flex-shrink:0;line-height:1;}
.fname{font-size:13px;font-weight:500;color:var(--text);}
.fname.is-folder{color:var(--gold);}
.ext-badge{display:inline-block;padding:2px 6px;background:rgba(29,78,216,.18);border:1px solid rgba(59,130,246,.22);
    border-radius:4px;font-size:10px;color:var(--maroon-bright);text-transform:uppercase;letter-spacing:.4px;margin-left:5px;flex-shrink:0;}
.type-badge{display:inline-block;padding:3px 8px;border-radius:5px;font-size:11px;font-weight:600;
    background:rgba(29,78,216,.15);border:1px solid rgba(59,130,246,.2);color:var(--text-dim);text-transform:uppercase;}
.type-badge.folder{background:rgba(201,168,76,.12);border-color:rgba(201,168,76,.25);color:var(--gold);}
.size-text{font-size:12px;color:var(--text-dim);font-variant-numeric:tabular-nums;}
.date-text{font-size:11px;color:var(--text-muted);font-family:monospace;}
.actions{display:flex;gap:4px;flex-wrap:wrap;}

/* ── Pagination ── */
.pagination-wrap{display:none;align-items:center;justify-content:center;gap:5px;padding:14px;border-top:1px solid var(--glass-border);flex-wrap:wrap;}
.pag-btn{min-width:34px;height:34px;padding:0 9px;display:flex;align-items:center;justify-content:center;
    background:rgba(255,255,255,.04);border:1px solid var(--glass-border);border-radius:7px;
    color:var(--text-dim);font-size:13px;font-weight:500;font-family:'Inter',sans-serif;cursor:pointer;transition:all .2s;}
.pag-btn:hover:not(.disabled):not(.active){background:rgba(59,130,246,.15);border-color:var(--glass-bright);color:var(--text);}
.pag-btn.active{background:linear-gradient(135deg,var(--maroon-dark),var(--maroon-light));border-color:rgba(59,130,246,.4);color:#fff;box-shadow:0 3px 12px rgba(29,78,216,.4);}
.pag-btn.disabled{opacity:.35;cursor:not-allowed;}
.pag-dots{color:var(--text-muted);font-size:13px;padding:0 3px;}
.pag-info{font-size:12px;color:var(--text-muted);padding:0 6px;}

/* ── Empty / no results ── */
.empty-state{text-align:center;padding:60px 20px;color:var(--text-muted);}
.empty-icon{font-size:52px;margin-bottom:14px;opacity:.4;}
.empty-state h3{font-size:16px;font-weight:600;color:var(--text-dim);margin-bottom:7px;}
.no-results{display:none;text-align:center;padding:40px 20px;color:var(--text-muted);}
.no-results i{font-size:36px;margin-bottom:12px;display:block;opacity:.4;}

/* ── GRID MODE ── */
.grid-mode table{display:block;}
.grid-mode thead{display:none;}
.grid-mode tbody{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:11px;padding:14px;}
.grid-mode tbody tr{display:flex;flex-direction:column;background:rgba(5,14,38,.8);border:1px solid var(--glass-border);
    border-radius:12px;overflow:hidden;transition:all .2s;position:relative;}
.grid-mode tbody tr:hover{border-color:var(--glass-bright);transform:translateY(-3px);box-shadow:0 8px 24px rgba(29,78,216,.25);}
.grid-mode tbody tr.selected-row{border-color:var(--maroon-bright)!important;box-shadow:0 0 0 2px rgba(200,40,40,.3);}
.grid-mode .chk-td{position:absolute;top:6px;left:6px;z-index:2;padding:0!important;background:rgba(0,0,0,.5);border-radius:4px;width:22px;height:22px;display:flex;align-items:center;justify-content:center;}
.grid-mode td:nth-child(2){padding:0;flex:1;}
.grid-mode .name-cell{flex-direction:column;align-items:center;gap:5px;padding:0 0 10px;text-align:center;}
.grid-mode .thumb{width:100%;height:125px;object-fit:cover;border-radius:0;border:none;border-bottom:1px solid var(--glass-border);margin:0;}
.grid-mode .file-emoji{font-size:36px;padding:20px 0 6px;line-height:1;}
.grid-mode .fname{font-size:11px;padding:0 8px;word-break:break-word;line-height:1.4;max-width:100%;}
.grid-mode .ext-badge{display:none;}
.grid-mode td:nth-child(3),
.grid-mode td:nth-child(4),
.grid-mode td:nth-child(5){display:none;}
.grid-mode td:nth-child(6){padding:7px;border-top:1px solid var(--glass-border);}
.grid-mode .actions{justify-content:center;gap:3px;flex-wrap:wrap;}

/* ── Modals ── */
.modal-overlay{display:none;position:fixed;inset:0;z-index:200;background:rgba(0,0,0,.82);
    backdrop-filter:blur(6px);align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:rgba(14,2,8,.92);border:1px solid var(--glass-border);border-radius:20px;
    width:90%;backdrop-filter:blur(28px);box-shadow:0 32px 80px rgba(0,0,0,.7),0 0 60px rgba(29,78,216,.2);
    animation:modalIn .22s ease-out;overflow:hidden;}
@keyframes modalIn{from{transform:scale(.94);opacity:0;}to{transform:scale(1);opacity:1;}}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--glass-border);}
.modal-header h3{font-size:15px;font-weight:600;color:var(--text);display:flex;align-items:center;gap:9px;}
.modal-header h3 i{color:var(--maroon-bright);}
.modal-body{padding:22px;}
.modal-footer{display:flex;gap:9px;padding:0 22px 22px;}
.modal-footer .btn{flex:1;justify-content:center;}
.modal-scroll{max-height:55vh;overflow-y:auto;}

/* ── Log modal ── */
.log-entry{padding:8px 12px;font-size:12px;font-family:monospace;border-bottom:1px solid rgba(255,255,255,.04);color:var(--text-dim);}
.log-entry:hover{background:rgba(255,255,255,.03);}

/* ── Storage bar ── */
.storage-bar-wrap{width:120px;}
.storage-bar-bg{height:4px;background:rgba(255,255,255,.08);border-radius:2px;overflow:hidden;margin-top:4px;}
.storage-bar-fill{height:100%;border-radius:2px;background:linear-gradient(90deg,var(--maroon),var(--maroon-bright));transition:width .5s;}

/* ── Footer ── */
.footer{border-top:1px solid var(--glass-border);padding:14px 24px;display:flex;justify-content:space-between;
    align-items:center;flex-wrap:wrap;gap:7px;font-size:11px;color:var(--text-muted);background:rgba(4,8,20,.6);}
.footer-meta{display:flex;gap:18px;flex-wrap:wrap;}
.footer-meta span{font-family:monospace;}

/* ── Scrollbar ── */
::-webkit-scrollbar{width:4px;height:4px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:rgba(59,130,246,.3);border-radius:2px;}

/* ── Toast ── */
.toast{position:fixed;bottom:24px;right:24px;z-index:300;background:rgba(14,2,8,.96);
    border:1px solid var(--glass-border);border-radius:12px;padding:12px 18px;
    display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500;color:var(--text);
    box-shadow:0 8px 32px rgba(0,0,0,.5);transform:translateY(80px);opacity:0;transition:all .3s;}
.toast.show{transform:translateY(0);opacity:1;}

/* ── Mobile responsive ── */
@media(max-width:768px){
    .navbar{padding:0 14px;height:auto;flex-wrap:wrap;gap:10px;padding-top:10px;padding-bottom:10px;}
    .nav-stats{display:none;}
    .storage-bar-wrap{display:none;}
    .main{padding:14px 12px;}
    .action-row{grid-template-columns:1fr;}
    .file-table-head{flex-direction:column;align-items:stretch;gap:8px;}
    .search-wrap{max-width:100%;}
    table{display:block;overflow-x:auto;}
    thead th:nth-child(3),thead th:nth-child(4),
    tbody td:nth-child(3),tbody td:nth-child(4){display:none;}
    .bulk-toolbar{flex-direction:column;align-items:flex-start;}
    .footer{flex-direction:column;text-align:center;}
    .footer-meta{justify-content:center;}
    .modal{width:96%;}
}
@media(max-width:480px){
    .nav-logo-sub{display:none;}
    .actions{gap:3px;}
    .btn-sm{padding:4px 7px;font-size:11px;}
}
</style>
</head>
<body>
<div class="bg-orb orb-1"></div><div class="bg-orb orb-2"></div><div class="bg-orb orb-3"></div>
<div class="bg-grid"></div>
<div class="layout">

<!-- ══ NAVBAR ══ -->
<nav class="navbar">
    <div class="nav-logo">
        <div class="nav-logo-icon">🗄️</div>
        <div><div class="nav-logo-text">Private Storage</div><div class="nav-logo-sub">Secure File Vault</div></div>
    </div>

    <div class="nav-stats">
        <div class="nav-stat"><div class="nav-stat-val"><?php echo count($folder_list);?></div><div class="nav-stat-lbl">Folders</div></div>
        <div class="nav-stat"><div class="nav-stat-val"><?php echo count($file_list);?></div><div class="nav-stat-lbl">Files</div></div>
        <div class="nav-stat"><div class="nav-stat-val"><?php echo format_size($total_size);?></div><div class="nav-stat-lbl">Here</div></div>
    </div>

    <!-- Storage usage bar -->
    <div class="storage-bar-wrap">
        <?php
        $disk_total = disk_total_space(UPLOAD_DIR) ?: 1;
        $pct = min(100, round(($storage_used / $disk_total) * 100));
        ?>
        <div style="font-size:11px;color:var(--text-muted);display:flex;justify-content:space-between;">
            <span>Storage</span><span style="color:var(--gold);"><?php echo format_size($storage_used);?></span>
        </div>
        <div class="storage-bar-bg"><div class="storage-bar-fill" style="width:<?php echo $pct;?>%;"></div></div>
    </div>

    <div class="nav-actions">
        <div style="text-align:right;">
            <div class="nav-username"><?php echo htmlspecialchars($_SESSION['username']);?></div>
            <div class="nav-ip"><?php echo htmlspecialchars($_SESSION['ip']);?></div>
        </div>
        <button class="btn-icon" onclick="showGlobalSearch()" title="Search All Folders (Ctrl+F)"><i class="fas fa-search"></i></button>
        <button class="btn-icon" onclick="extendSession()" title="Extend Session"><i class="fas fa-clock"></i></button>
        <button class="btn-icon trash-btn" onclick="showTrashModal()" title="Recycle Bin">
            <i class="fas fa-trash-alt"></i>
            <?php if($trash_count > 0): ?><span class="trash-badge"><?php echo $trash_count; ?></span><?php endif; ?>
        </button>
        <button class="btn-icon" onclick="showStatsModal()" title="Storage Stats"><i class="fas fa-chart-bar"></i></button>
        <button class="btn-icon" onclick="showLogModal()" title="Activity Log"><i class="fas fa-history"></i></button>
        <button class="btn-icon" onclick="showChangePwModal()" title="Change Password"><i class="fas fa-key"></i></button>
        <button class="btn-icon" onclick="toggleTheme()" id="btnTheme" title="Toggle Light/Dark"><i class="fas fa-sun"></i></button>
        <a href="#" class="btn-logout" onclick="customConfirm('Sign out of Private Storage?',()=>location.href='logout.php','Sign Out',false,'fa-sign-out-alt');return false;"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</nav>

<!-- ══ MAIN ══ -->
<div class="main">

    <!-- Action panel -->
    <div class="action-row">
        <div class="glass-card panel">
            <div class="panel-heading"><i class="fas fa-folder-plus"></i> New Folder</div>
            <div id="folderMessage" class="msg-box"></div>
            <div class="field-row">
                <div class="field">
                    <label class="field-label">Folder name</label>
                    <input type="text" id="folderName" class="field-input" placeholder="my-folder" pattern="[a-zA-Z0-9_-]+">
                </div>
                <button onclick="createFolderAjax()" class="btn btn-primary"><i class="fas fa-plus"></i> Create</button>
            </div>
        </div>

        <div class="glass-card panel">
            <div class="panel-heading"><i class="fas fa-cloud-upload-alt"></i> Upload Files</div>
            <div id="dropArea" class="drop-zone">
                <div class="drop-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                <div class="drop-title">Drop files here</div>
                <div class="drop-sub">or <span style="color:var(--maroon-bright);cursor:pointer;" onclick="document.getElementById('file_upload').click()">click to browse</span></div>
                <input type="file" id="file_upload" multiple style="display:none;">
            </div>
            <div id="selectedFiles" style="display:none;">
                <div class="file-chips" id="filesList"></div>
                <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;color:var(--text-muted);margin-bottom:10px;">
                    <span><span id="totalFiles">0</span> files · <span id="totalSize">0 B</span></span>
                    <button onclick="clearFiles()" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Clear</button>
                </div>
            </div>
            <button onclick="uploadFiles()" class="btn btn-primary" style="width:100%;justify-content:center;" id="uploadBtn">
                <i class="fas fa-upload"></i> Upload All
            </button>
            <div id="uploadProgress" style="display:none;margin-top:14px;">
                <div class="progress-meta">
                    <span style="font-size:12px;color:var(--text-dim);">Progress</span>
                    <span id="overallPercent" style="color:var(--gold);font-weight:600;font-size:12px;">0%</span>
                </div>
                <div class="progress-bar-wrap"><div id="overallProgressBar" class="progress-bar-fill"></div></div>
                <div id="individualProgress" style="margin-top:10px;display:flex;flex-direction:column;gap:5px;max-height:110px;overflow-y:auto;"></div>
            </div>
        </div>
    </div>

    <!-- Recent Files -->
    <?php if(!empty($recent_files) && empty($current_folder)): ?>
    <div class="glass-card recent-section" style="margin-bottom:14px;">
        <div class="recent-header"><i class="fas fa-clock"></i> Recently Added</div>
        <div class="recent-files">
        <?php foreach($recent_files as $rf):
            $rf_path = 'uploads/'.($rf['folder']?$rf['folder'].'/':'').$rf['name'];
            $is_img  = in_array($rf['ext'],['jpg','jpeg','png','gif','bmp','webp']);
        ?>
        <div class="recent-item"
             onclick="viewFile('<?php echo htmlspecialchars(addslashes($rf['name']));?>','<?php echo $rf['ext'];?>')"
             title="<?php echo htmlspecialchars($rf['name']);?> — <?php echo $rf['folder']?:'root';?>">
            <?php if($is_img): ?>
            <img src="<?php echo htmlspecialchars($rf_path);?>" loading="lazy" alt="" onerror="this.style.display='none'">
            <?php else: ?>
            <span><?php echo get_file_icon($rf['ext'],false);?></span>
            <?php endif; ?>
            <div class="recent-name"><?php echo htmlspecialchars($rf['name']);?></div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Breadcrumb -->
    <div class="glass-card breadcrumb" style="margin-bottom:14px;">
        <?php if(!empty($current_folder)):
            $crumbs = array_values(array_filter(explode('/', $current_folder)));
            $parent_path = count($crumbs) > 1 ? implode('/', array_slice($crumbs, 0, -1)) : '';
        ?>
        <a href="dashboard.php<?php echo $parent_path !== '' ? '?folder='.urlencode($parent_path) : ''; ?>" class="btn-back" title="Go back"><i class="fas fa-arrow-left"></i></a>
        <?php endif; ?>
        <a href="dashboard.php"><i class="fas fa-home"></i> Root</a>
        <?php if(!empty($breadcrumbs)): $cur=''; foreach($breadcrumbs as $crumb): $cur.='/'.$crumb; ?>
            <span class="breadcrumb-sep"><i class="fas fa-chevron-right" style="font-size:9px;"></i></span>
            <a href="dashboard.php?folder=<?php echo urlencode(ltrim($cur,'/'));?>"><?php echo htmlspecialchars($crumb);?></a>
        <?php endforeach; endif; ?>
        <span class="breadcrumb-count"><?php echo count($folder_list);?> folders, <?php echo count($file_list);?> files</span>
    </div>

    <!-- Type Filter Bar -->
    <div class="glass-card type-filter-bar" style="margin-bottom:14px;" id="typeFilterBar">
        <button class="tfbtn active" onclick="filterByType('')"       data-type="">All</button>
        <button class="tfbtn"        onclick="filterByType('image')"  data-type="image">🖼 Images</button>
        <button class="tfbtn"        onclick="filterByType('video')"  data-type="video">🎬 Video</button>
        <button class="tfbtn"        onclick="filterByType('audio')"  data-type="audio">🎵 Audio</button>
        <button class="tfbtn"        onclick="filterByType('doc')"    data-type="doc">📄 Docs</button>
        <button class="tfbtn"        onclick="filterByType('archive')" data-type="archive">📦 Archives</button>
        <button class="tfbtn"        onclick="filterByType('folder')" data-type="folder">📁 Folders</button>
        <span class="type-filter-count" id="typeFilterCount"></span>
    </div>

    <!-- Bulk toolbar -->
    <div class="bulk-toolbar glass-card" id="bulkToolbar" style="margin-bottom:14px;">
        <span class="bulk-count" id="bulkCount">0 selected</span>
        <button onclick="bulkTrash()" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Trash</button>
        <button onclick="showBulkMoveModal()" class="btn btn-gold btn-sm"><i class="fas fa-share"></i> Move</button>
        <button onclick="bulkDownloadZip()" class="btn btn-info btn-sm"><i class="fas fa-file-archive"></i> Download ZIP</button>
        <button onclick="showBatchRenameModal()" class="btn btn-ghost btn-sm"><i class="fas fa-font"></i> Batch Rename</button>
        <button onclick="clearSelection()" class="btn btn-ghost btn-sm" style="margin-left:auto;"><i class="fas fa-times"></i> Clear</button>
    </div>

    <!-- File table -->
    <div class="glass-card file-table-wrap" id="fileTableWrap">
        <div class="file-table-head">
            <h2><i class="fas fa-layer-group"></i> Files</h2>
            <div class="search-wrap">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="Search files & folders..." oninput="handleSearch(this.value)">
                <button class="search-clear" id="searchClear" onclick="clearSearch()"><i class="fas fa-times"></i></button>
            </div>
            <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap;">
                <span class="search-count" id="searchCount"></span>
                <select class="perpage-select" id="perPageSelect" onchange="changePerPage(this.value)" title="Items per page">
                    <option value="20">20 / page</option>
                    <option value="50">50 / page</option>
                    <option value="100">100 / page</option>
                    <option value="0">Show all</option>
                </select>
                <button onclick="showBatchRenameModal()" class="btn btn-ghost btn-sm" title="Batch Rename" id="batchRenameBtn" style="display:none;"><i class="fas fa-font"></i></button>
                <div class="view-toggle">
                    <button class="view-btn" id="btnList" onclick="setView('list')" title="List"><i class="fas fa-list"></i></button>
                    <button class="view-btn" id="btnGrid" onclick="setView('grid')" title="Grid"><i class="fas fa-th"></i></button>
                </div>
            </div>
        </div>

        <?php if(empty($all_items)): ?>
        <div class="empty-state">
            <div class="empty-icon">📂</div>
            <h3>This folder is empty</h3>
            <p>Upload files or create a new folder above</p>
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th class="chk-th"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
                    <th class="sortable" onclick="sortBy('name')">Name <span class="sort-icon"></span></th>
                    <th class="sortable" onclick="sortBy('type')">Type <span class="sort-icon"></span></th>
                    <th class="sortable" onclick="sortBy('size')">Size <span class="sort-icon"></span></th>
                    <th class="sortable" onclick="sortBy('date')">Modified <span class="sort-icon"></span></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            // Build folder key for meta lookup
            $folder_key_prefix = $current_folder ? $current_folder . '/' : '';
            foreach($all_items as $item):
                $img_exts = ['jpg','jpeg','png','gif','bmp','webp'];
                $thumb    = 'uploads/'.($current_folder?$current_folder.'/':'').$item['name'];
                $item_fkey = $folder_key_prefix . $item['name'];
                $f_meta   = $item['is_dir'] ? ($folder_meta[$item_fkey] ?? []) : [];
                $f_color  = $f_meta['color'] ?? '';
                $f_icon   = $f_meta['icon']  ?? '';
            ?>
            <tr data-name="<?php echo htmlspecialchars($item['name']);?>"
                data-type="<?php echo $item['is_dir']?'folder':$item['ext'];?>"
                data-size="<?php echo $item['size']==='-'?-1:$item['size'];?>"
                data-date="<?php echo $item['modified'];?>"
                data-is-folder="<?php echo $item['is_dir']?'1':'0';?>"
                data-count="<?php echo $item['count'];?>"
                draggable="true">

                <td class="chk-td">
                    <input type="checkbox" class="row-chk"
                           data-name="<?php echo htmlspecialchars($item['name']);?>"
                           data-is-folder="<?php echo $item['is_dir']?'1':'0';?>"
                           onclick="toggleSelect(this,event)">
                </td>
                <td>
                    <div class="name-cell">
                        <?php if(!$item['is_dir'] && in_array($item['ext'],$img_exts)): ?>
                            <img src="<?php echo htmlspecialchars($thumb);?>" class="thumb" alt="" onerror="this.style.display='none'" loading="lazy">
                        <?php elseif($item['is_dir']): ?>
                            <span class="file-emoji" style="<?php echo $f_color?'filter:drop-shadow(0 0 4px '.$f_color.')':''; ?>"><?php echo $f_icon ?: '📁'; ?></span>
                            <?php if($f_color): ?><span class="folder-color-dot" style="background:<?php echo htmlspecialchars($f_color);?>;"></span><?php endif; ?>
                        <?php else: ?>
                            <span class="file-emoji"><?php echo get_file_icon($item['ext'],$item['is_dir']);?></span>
                        <?php endif; ?>
                        <span class="fname <?php echo $item['is_dir']?'is-folder':'';?>" <?php echo $f_color?'style="color:'.htmlspecialchars($f_color).';"':''; ?>><?php echo htmlspecialchars($item['name']);?></span>
                        <?php if(!$item['is_dir']&&$item['ext']): ?><span class="ext-badge"><?php echo strtoupper($item['ext']);?></span><?php endif; ?>
                    </div>
                </td>
                <td>
                    <span class="type-badge <?php echo $item['is_dir']?'folder':'';?>">
                        <?php echo $item['is_dir']?'Folder':strtoupper($item['ext']?:'File');?>
                    </span>
                    <?php if($item['is_dir']): ?><span class="count-badge"><?php echo $item['count'];?> items</span><?php endif; ?>
                </td>
                <td><span class="size-text"><?php echo format_size($item['size']);?></span></td>
                <td><span class="date-text"><?php echo date('Y-m-d H:i',$item['modified']);?></span></td>
                <td>
                    <div class="actions">
                    <?php if($item['is_dir']): ?>
                        <a href="dashboard.php?folder=<?php echo urlencode(ltrim($relative_path.'/'.$item['name'],'/'));?>" class="btn btn-gold btn-sm">
                            <i class="fas fa-folder-open"></i>
                        </a>
                        <a href="ajax_zip.php?folder=<?php echo urlencode($current_folder);?>&sub_folder=<?php echo urlencode($item['name']);?>&csrf_token=<?php echo urlencode($csrf_token);?>"
                           class="btn btn-info btn-sm" title="Download as ZIP"><i class="fas fa-file-archive"></i></a>
                        <button onclick="showFolderColorModal('<?php echo htmlspecialchars($item['name']);?>','<?php echo htmlspecialchars($item_fkey);?>','<?php echo htmlspecialchars($f_color);?>','<?php echo htmlspecialchars($f_icon);?>')" class="btn btn-ghost btn-sm" title="Color & Icon"><i class="fas fa-palette"></i></button>
                        <button onclick="showMoveModal('<?php echo htmlspecialchars($item['name']);?>',true)" class="btn btn-ghost btn-sm" title="Move"><i class="fas fa-share"></i></button>
                        <button onclick="showEditModal('<?php echo htmlspecialchars($item['name']);?>',true)" class="btn btn-ghost btn-sm" title="Rename"><i class="fas fa-edit"></i></button>
                        <button onclick="moveToTrash('<?php echo htmlspecialchars($item['name']);?>')" class="btn btn-danger btn-sm" title="Move to Trash"><i class="fas fa-trash"></i></button>
                    <?php else: ?>
                        <button onclick="viewFile('<?php echo htmlspecialchars($item['name']);?>','<?php echo $item['ext'];?>')" class="btn btn-info btn-sm" title="Preview"><i class="fas fa-eye"></i></button>
                        <button onclick="copyFileLink('<?php echo htmlspecialchars($item['name']);?>')" class="btn btn-gold btn-sm" title="Copy Link"><i class="fas fa-link"></i></button>
                        <button onclick="copyFilePath('<?php echo htmlspecialchars($item['name']);?>')" class="btn btn-ghost btn-sm" title="Copy Path"><i class="fas fa-code"></i></button>
                        <button onclick="duplicateFile('<?php echo htmlspecialchars($item['name']);?>')" class="btn btn-ghost btn-sm" title="Duplicate"><i class="fas fa-copy"></i></button>
                        <button onclick="showShareModal('<?php echo htmlspecialchars($item['name']);?>')" class="btn btn-ghost btn-sm" title="Share Link"><i class="fas fa-external-link-alt"></i></button>
                        <button onclick="showMoveModal('<?php echo htmlspecialchars($item['name']);?>',false)" class="btn btn-ghost btn-sm" title="Move"><i class="fas fa-share"></i></button>
                        <button onclick="showEditModal('<?php echo htmlspecialchars($item['name']);?>',false)" class="btn btn-ghost btn-sm" title="Rename"><i class="fas fa-edit"></i></button>
                        <button onclick="moveToTrash('<?php echo htmlspecialchars($item['name']);?>')" class="btn btn-danger btn-sm" title="Move to Trash"><i class="fas fa-trash"></i></button>
                        <a href="<?php echo htmlspecialchars($thumb);?>" download class="btn btn-ghost btn-sm" title="Download"><i class="fas fa-download"></i></a>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="no-results" id="noResults"><i class="fas fa-search"></i><p>No results for "<strong id="noResultsQuery"></strong>"</p></div>
        <div class="pagination-wrap" id="pagination"></div>
    </div>

</div><!-- /main -->

<footer class="footer">
    <span>Private Storage — Secure File Vault</span>
    <div class="footer-meta">
        <span>Login: <?php echo date('H:i:s',$_SESSION['login_time']);?></span>
        <span>Timeout: <?php echo ceil(($session_timeout-(time()-$_SESSION['login_time']))/60);?> min</span>
        <span>Server: <?php echo date('H:i:s');?></span>
    </div>
</footer>
</div><!-- /layout -->

<!-- ══ MODALS ══ -->

<!-- Preview -->
<div id="previewModal" class="modal-overlay">
    <div class="modal" style="max-width:820px;">
        <div class="modal-header">
            <h3><i class="fas fa-eye"></i> <span id="fileName">Preview</span></h3>
            <div style="display:flex;gap:7px;align-items:center;">
                <button id="lbPrev" onclick="lightboxNav(-1)" class="btn btn-ghost btn-sm" style="display:none;" title="Previous image"><i class="fas fa-chevron-left"></i></button>
                <span id="lbCounter" style="font-size:11px;color:var(--text-muted);display:none;"></span>
                <button id="lbNext" onclick="lightboxNav(1)" class="btn btn-ghost btn-sm" style="display:none;" title="Next image"><i class="fas fa-chevron-right"></i></button>
                <button onclick="downloadCurrentFile()" class="btn btn-ghost btn-sm"><i class="fas fa-download"></i></button>
                <button onclick="closeModal('previewModal')" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="modal-body" id="previewBody" style="text-align:center;"></div>
    </div>
</div>

<!-- Rename -->
<div id="editModal" class="modal-overlay">
    <div class="modal" style="max-width:450px;">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> <span id="editModalTitle">Rename</span></h3>
            <button onclick="closeModal('editModal')" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div id="editMessage" class="msg-box" style="margin-bottom:14px;"></div>
            <div style="margin-bottom:14px;">
                <label class="field-label">Current name</label>
                <input type="text" id="currentName" class="field-input" readonly style="background:rgba(255,255,255,.02);color:var(--text-muted);">
            </div>
            <div>
                <label class="field-label">New name</label>
                <input type="text" id="newName" class="field-input" placeholder="Enter new name..." pattern="[a-zA-Z0-9_.\-]+">
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="performRename()" class="btn btn-primary"><i class="fas fa-check"></i> Rename</button>
            <button onclick="closeModal('editModal')" class="btn btn-ghost"><i class="fas fa-times"></i> Cancel</button>
        </div>
    </div>
</div>

<!-- Delete -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header" style="border-color:rgba(200,40,40,.25);">
            <h3 style="color:#e07070;"><i class="fas fa-exclamation-triangle"></i> <span id="deleteModalTitle">Delete</span></h3>
            <button onclick="closeModal('deleteModal')" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="text-align:center;padding:26px 22px;">
            <div style="font-size:42px;margin-bottom:14px;">⚠️</div>
            <h3 style="color:#e07070;margin-bottom:9px;font-size:16px;">Are you sure?</h3>
            <p id="deleteMessage" style="color:var(--text-dim);font-size:13px;line-height:1.5;">Delete: <strong id="deleteItemName" style="color:var(--text);"></strong></p>
            <p style="color:var(--text-muted);font-size:12px;margin-top:8px;">This cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <button onclick="performDelete()" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
            <button onclick="closeModal('deleteModal')" class="btn btn-ghost"><i class="fas fa-times"></i> Cancel</button>
        </div>
    </div>
</div>

<!-- Move -->
<div id="moveModal" class="modal-overlay">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <h3><i class="fas fa-share"></i> <span id="moveModalTitle">Move</span></h3>
            <button onclick="closeModal('moveModal')" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div id="moveMessage" class="msg-box" style="margin-bottom:14px;"></div>
            <label class="field-label">Move to folder</label>
            <select id="moveDestination" class="field-input" style="cursor:pointer;"></select>
            <p style="font-size:12px;color:var(--text-muted);margin-top:9px;"><i class="fas fa-info-circle"></i> Moving item: <strong id="moveItemName" style="color:var(--text-dim);"></strong></p>
        </div>
        <div class="modal-footer">
            <button onclick="performMove()" class="btn btn-primary"><i class="fas fa-share"></i> Move Here</button>
            <button onclick="closeModal('moveModal')" class="btn btn-ghost"><i class="fas fa-times"></i> Cancel</button>
        </div>
    </div>
</div>

<!-- Change Password -->
<div id="changePwModal" class="modal-overlay">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <h3><i class="fas fa-key"></i> Change Password</h3>
            <button onclick="closeModal('changePwModal')" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div id="pwMessage" class="msg-box" style="margin-bottom:14px;"></div>
            <div style="margin-bottom:13px;">
                <label class="field-label">Current Password</label>
                <input type="password" id="currentPw" class="field-input" placeholder="Enter current password">
            </div>
            <div style="margin-bottom:13px;">
                <label class="field-label">New Password <span style="color:var(--text-muted);font-size:10px;">(min 8 chars)</span></label>
                <input type="password" id="newPw" class="field-input" placeholder="Enter new password">
            </div>
            <div>
                <label class="field-label">Confirm New Password</label>
                <input type="password" id="confirmPw" class="field-input" placeholder="Confirm new password">
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="performChangePw()" class="btn btn-primary" id="changePwBtn"><i class="fas fa-check"></i> Update Password</button>
            <button onclick="closeModal('changePwModal')" class="btn btn-ghost"><i class="fas fa-times"></i> Cancel</button>
        </div>
    </div>
</div>

<!-- Activity Log -->
<div id="logModal" class="modal-overlay">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h3><i class="fas fa-history"></i> Activity Log</h3>
            <button onclick="closeModal('logModal')" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="padding:0;">
            <div id="logContent" class="modal-scroll" style="min-height:200px;">
                <div style="padding:30px;text-align:center;color:var(--text-muted);">
                    <i class="fas fa-spinner fa-spin" style="font-size:24px;"></i>
                </div>
            </div>
        </div>
        <div class="modal-footer" style="padding:14px 22px;">
            <button onclick="closeModal('logModal')" class="btn btn-ghost" style="flex:none;"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- Trash Modal -->
<div id="trashModal" class="modal-overlay">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h3><i class="fas fa-trash-alt"></i> Recycle Bin</h3>
            <button onclick="closeModal('trashModal')" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="padding:0;">
            <div id="trashContent" class="modal-scroll" style="min-height:160px;"></div>
        </div>
        <div class="modal-footer">
            <button onclick="emptyTrash()" class="btn btn-danger"><i class="fas fa-fire"></i> Empty Trash</button>
            <button onclick="closeModal('trashModal')" class="btn btn-ghost"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- Batch Rename Modal -->
<div id="batchRenameModal" class="modal-overlay">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <h3><i class="fas fa-font"></i> Batch Rename</h3>
            <button onclick="closeModal('batchRenameModal')" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div id="batchRenameMsg" class="msg-box" style="margin-bottom:14px;"></div>
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">
                Rename <strong id="batchCount" style="color:var(--text);"></strong> selected items with a pattern.<br>
                Result: <code style="color:var(--gold);" id="batchPreview">pattern_001.ext</code>
            </p>
            <div style="display:grid;grid-template-columns:1fr 80px 70px;gap:9px;margin-bottom:12px;">
                <div>
                    <label class="field-label">Pattern</label>
                    <input type="text" id="batchPattern" class="field-input" placeholder="photo_vacation" oninput="updateBatchPreview()">
                </div>
                <div>
                    <label class="field-label">Start #</label>
                    <input type="number" id="batchStart" class="field-input" value="1" min="1" oninput="updateBatchPreview()">
                </div>
                <div>
                    <label class="field-label">Digits</label>
                    <input type="number" id="batchPadding" class="field-input" value="3" min="1" max="6" oninput="updateBatchPreview()">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="performBatchRename()" class="btn btn-primary"><i class="fas fa-check"></i> Rename All</button>
            <button onclick="closeModal('batchRenameModal')" class="btn btn-ghost"><i class="fas fa-times"></i> Cancel</button>
        </div>
    </div>
</div>

<!-- Share Link Modal -->
<div id="shareModal" class="modal-overlay">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h3><i class="fas fa-external-link-alt"></i> Share File</h3>
            <button onclick="closeModal('shareModal')" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div id="shareMsg" class="msg-box" style="margin-bottom:14px;"></div>
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">Generate a temporary public link for <strong id="shareFileName" style="color:var(--text);"></strong></p>
            <div style="margin-bottom:14px;">
                <label class="field-label">Link expires after</label>
                <select id="shareExpiry" class="field-input">
                    <option value="1">1 hour</option>
                    <option value="6">6 hours</option>
                    <option value="24" selected>24 hours</option>
                    <option value="72">3 days</option>
                    <option value="168">7 days</option>
                    <option value="720">30 days</option>
                </select>
            </div>
            <div id="shareResult" style="display:none;">
                <label class="field-label">Share URL (expires <span id="shareExpiresFmt"></span>)</label>
                <div style="display:flex;gap:7px;margin-bottom:12px;">
                    <input type="text" id="shareUrl" class="field-input" readonly style="font-size:11px;font-family:monospace;">
                    <button onclick="copyShareUrl()" class="btn btn-gold btn-sm"><i class="fas fa-copy"></i></button>
                </div>
                <div id="shareQrWrap" style="text-align:center;padding:12px 0;"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="createShareLink()" class="btn btn-primary" id="createShareBtn"><i class="fas fa-link"></i> Generate Link</button>
            <button onclick="closeModal('shareModal')" class="btn btn-ghost"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- Folder Color/Icon Modal -->
<div id="folderColorModal" class="modal-overlay">
    <div class="modal" style="max-width:400px;">
        <div class="modal-header">
            <h3><i class="fas fa-palette"></i> Folder Style</h3>
            <button onclick="closeModal('folderColorModal')" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="fcFolderKey">
            <div style="margin-bottom:14px;">
                <label class="field-label">Folder Icon (emoji)</label>
                <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:8px;">
                    <?php foreach(['📁','🗂️','📂','💼','🎯','🎨','📸','🎵','🎬','📚','💾','🔒','⭐','🚀','🏠','🌟'] as $em): ?>
                    <button onclick="selectIcon('<?php echo $em;?>')" class="btn btn-ghost btn-sm" style="font-size:18px;padding:6px 9px;"><?php echo $em;?></button>
                    <?php endforeach; ?>
                </div>
                <input type="text" id="fcIconInput" class="field-input" placeholder="Or type any emoji" maxlength="4" style="font-size:18px;width:100px;">
            </div>
            <div>
                <label class="field-label">Folder Color</label>
                <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:8px;">
                    <?php foreach(['#c9a84c','#e05050','#52c788','#7cb5f5','#c050e0','#f59050','#50c8c0','#ff80ab','#ffffff',''] as $cl): ?>
                    <button onclick="selectColor('<?php echo $cl;?>')" style="width:28px;height:28px;border-radius:50%;background:<?php echo $cl?:'rgba(255,255,255,.1)';?>;border:2px solid rgba(255,255,255,.15);cursor:pointer;" title="<?php echo $cl?:'None';?>"></button>
                    <?php endforeach; ?>
                </div>
                <input type="color" id="fcColorInput" value="#c9a84c" style="margin-top:4px;">
            </div>
            <div style="margin-top:16px;padding:12px;background:rgba(0,0,0,.2);border-radius:9px;text-align:center;">
                <span style="font-size:28px;" id="fcPreviewIcon">📁</span>
                <span style="font-size:14px;font-weight:600;margin-left:9px;" id="fcPreviewName">Folder</span>
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="saveFolderMeta()" class="btn btn-primary"><i class="fas fa-check"></i> Save</button>
            <button onclick="resetFolderMeta()" class="btn btn-ghost"><i class="fas fa-undo"></i> Reset</button>
            <button onclick="closeModal('folderColorModal')" class="btn btn-ghost"><i class="fas fa-times"></i> Cancel</button>
        </div>
    </div>
</div>

<!-- Edit File Modal -->
<div id="editFileModal" class="modal-overlay">
    <div class="modal" style="max-width:780px;">
        <div class="modal-header">
            <h3><i class="fas fa-code"></i> Edit: <span id="editFileName"></span></h3>
            <div style="display:flex;gap:7px;">
                <button onclick="saveFileEdit()" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save</button>
                <button onclick="closeModal('editFileModal')" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="modal-body">
            <div id="editFileMsg" class="msg-box" style="margin-bottom:10px;"></div>
            <textarea id="editFileContent" class="edit-textarea" spellcheck="false"></textarea>
        </div>
    </div>
</div>

<!-- Stats Modal -->
<div id="statsModal" class="modal-overlay">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header">
            <h3><i class="fas fa-chart-bar"></i> Storage Stats</h3>
            <button onclick="closeModal('statsModal')" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="statsContent" style="min-height:120px;"></div>
    </div>
</div>

<!-- Custom Confirm Modal -->
<div id="confirmModal" class="modal-overlay">
    <div class="modal" style="max-width:420px;">
        <div class="modal-body" style="text-align:center;padding:32px 28px 16px;">
            <span class="confirm-icon" id="confirmIcon">⚠️</span>
            <p class="confirm-message" id="confirmMsg">Are you sure?</p>
            <p class="confirm-sub" id="confirmSub"></p>
        </div>
        <div class="modal-footer" style="justify-content:center;gap:10px;">
            <button id="confirmOkBtn" class="btn btn-danger" onclick="confirmResolve(true)"><i class="fas fa-check"></i> Confirm</button>
            <button class="btn btn-ghost" onclick="confirmResolve(false)"><i class="fas fa-times"></i> Cancel</button>
        </div>
    </div>
</div>

<!-- Global Search Modal -->
<div id="globalSearchModal" class="modal-overlay">
    <div class="modal" style="max-width:660px;">
        <div class="modal-header">
            <h3><i class="fas fa-search"></i> Search All Folders</h3>
            <button onclick="closeModal('globalSearchModal')" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="padding:16px 20px 10px;">
            <div style="display:flex;gap:9px;margin-bottom:14px;">
                <input type="text" id="globalSearchInput" class="field-input" placeholder="Search filename across all folders..." oninput="onGlobalSearchInput()" onkeydown="if(event.key==='Enter')doGlobalSearch()">
                <button onclick="doGlobalSearch()" class="btn btn-primary" id="globalSearchBtn"><i class="fas fa-search"></i></button>
            </div>
            <div id="globalSearchResults" style="min-height:80px;max-height:420px;overflow-y:auto;"></div>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toast" class="toast">
    <i id="toastIcon" class="fas fa-check-circle" style="color:var(--success);"></i>
    <span id="toastMessage">Done!</span>
</div>

<!-- Context Menu -->
<div id="ctxMenu" class="ctx-menu">
    <div class="ctx-label" id="ctxLabel">File</div>
    <div class="ctx-item" id="ctxOpen"   onclick="ctxAction('open')"><i class="fas fa-folder-open"></i> Open Folder</div>
    <div class="ctx-item" id="ctxPreview" onclick="ctxAction('preview')"><i class="fas fa-eye"></i> Preview</div>
    <div class="ctx-item" id="ctxCopyLink" onclick="ctxAction('copylink')"><i class="fas fa-link"></i> Copy Link</div>
    <div class="ctx-item" id="ctxDownload" onclick="ctxAction('download')"><i class="fas fa-download"></i> Download</div>
    <div class="ctx-sep"></div>
    <div class="ctx-item" id="ctxRename"  onclick="ctxAction('rename')"><i class="fas fa-edit"></i> Rename</div>
    <div class="ctx-item" id="ctxMove"    onclick="ctxAction('move')"><i class="fas fa-share"></i> Move</div>
    <div class="ctx-item" id="ctxDuplicate" onclick="ctxAction('duplicate')"><i class="fas fa-copy"></i> Duplicate</div>
    <div class="ctx-sep"></div>
    <div class="ctx-item ctx-danger" id="ctxDelete" onclick="ctxAction('delete')"><i class="fas fa-trash"></i> Delete</div>
</div>

<!-- Hidden form for bulk ZIP download -->
<form id="bulkZipForm" method="POST" action="ajax_bulk_zip.php" style="display:none;">
    <input type="hidden" name="csrf_token" id="bulkZipCsrf">
    <input type="hidden" name="current_folder" id="bulkZipFolder">
    <input type="hidden" name="items" id="bulkZipItems">
</form>

<!-- ══ SCRIPTS ══ -->
<script>
const CSRF_TOKEN   = '<?php echo $csrf_token; ?>';
const BASE_URL     = (()=>{ const p=window.location.pathname.split('/'); p.pop(); return window.location.origin+p.join('/'); })();
const ALL_FOLDERS  = <?php echo $all_folders_json; ?>;
const FOLDER_META  = <?php echo json_encode($folder_meta); ?>;
const STORAGE_USED = <?php echo $storage_used; ?>;
let ITEMS_PER_PAGE = 20;

let allRows=[], filteredRows=[], currentPage=1;
let sortCol='', sortDir='asc';
let selectedItems = new Set();
let moveTargetItem = null, moveIsBulk = false;
let currentPreviewFile = null, currentRotation = 0;
let currentEditItem  = {name:'',isFolder:false};
let currentDeleteItem= {name:'',isFolder:false};
let ctxTarget = null; // row currently targeted by context menu
let imageRows = [];   // list of image-previewable rows
let lbIndex   = -1;   // current lightbox index

// ══ INIT ══
document.addEventListener('DOMContentLoaded', () => {
    allRows = Array.from(document.querySelectorAll('.file-table tbody tr'));
    filteredRows = [...allRows];
    imageRows = allRows.filter(r => ['jpg','jpeg','png','gif','bmp','webp','svg'].includes(r.dataset.type));
    updateSearchCount();
    // Default sort: folders first, newest date
    sortCol='date'; sortDir='desc';
    const thDate = document.querySelectorAll('th.sortable')[3];
    if(thDate){thDate.classList.add('sort-desc');thDate.style.color='var(--gold)';}
    _sortArr(allRows,'date','desc');
    _sortArr(filteredRows,'date','desc');
    const tbody=document.querySelector('.file-table tbody');
    if(tbody) allRows.forEach(r=>tbody.appendChild(r));
    // Restore theme
    if(localStorage.getItem('storageTheme')==='light'){
        document.body.classList.add('light');
        const ic=document.querySelector('#btnTheme i');
        if(ic){ic.className='fas fa-moon';}
    }
    // Restore per-page
    const savedPP = localStorage.getItem('storagePerPage') || '20';
    ITEMS_PER_PAGE = parseInt(savedPP) || 0;
    const ppSel = document.getElementById('perPageSelect');
    if(ppSel) ppSel.value = savedPP;
    renderPage();
    setView(localStorage.getItem('storageView')||'list');
    initDragDrop();
    initContextMenu();
    initKeyboard();
    initPasteUpload();
    // Inline rename: dblclick on filename
    document.querySelectorAll('.fname').forEach(el=>{
        el.addEventListener('dblclick', e=>{
            e.preventDefault(); e.stopPropagation();
            const tr=el.closest('tr');
            if(tr) showEditModal(tr.dataset.name, tr.dataset.isFolder==='1');
        });
    });
});

// ══ MODAL HELPERS ══
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        ALL_MODALS.forEach(closeModal);
        document.getElementById('ctxMenu').classList.remove('open');
    }
});
const ALL_MODALS = ['previewModal','editModal','deleteModal','moveModal','changePwModal','logModal','trashModal','batchRenameModal','shareModal','folderColorModal','editFileModal','statsModal','confirmModal','globalSearchModal'];
ALL_MODALS.forEach(id => {
    const el = document.getElementById(id);
    if(el) el.addEventListener('click', function(e) { if(e.target===this) closeModal(id); });
});
// Auto session extend every 10 minutes
setInterval(()=>{
    const xhr=new XMLHttpRequest();
    xhr.open('POST','ajax_session.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.send('csrf_token='+encodeURIComponent(CSRF_TOKEN));
}, 10*60*1000);

// ══ CREATE FOLDER ══
function createFolderAjax() {
    const name = document.getElementById('folderName').value.trim();
    if (!name) { showFolderMsg('Folder name cannot be empty','error'); return; }
    if (!/^[a-zA-Z0-9_-]+$/.test(name)) { showFolderMsg('Only letters, numbers, _ and - allowed','error'); return; }
    const folder = new URLSearchParams(window.location.search).get('folder')||'';
    const xhr = new XMLHttpRequest();
    xhr.open('POST','ajax_create_folder.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload = () => {
        const r = JSON.parse(xhr.responseText);
        if (r.success) { showFolderMsg('Folder created! Refreshing...','success'); setTimeout(()=>location.reload(),900); }
        else showFolderMsg(r.message,'error');
    };
    xhr.send('folder_name='+encodeURIComponent(name)+'&current_folder='+encodeURIComponent(folder)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}
function showFolderMsg(msg,type) {
    const el = document.getElementById('folderMessage');
    el.className = 'msg-box '+(type==='success'?'msg-success':'msg-error');
    el.innerHTML = '<i class="fas fa-'+(type==='success'?'check-circle':'exclamation-circle')+'"></i> '+msg;
}

// ══ UPLOAD ══
let selectedFiles=[], uploadInProgress=false;
const dropArea  = document.getElementById('dropArea');
const fileInput = document.getElementById('file_upload');
dropArea.addEventListener('dragover',  e=>{e.preventDefault();dropArea.classList.add('active');});
dropArea.addEventListener('dragleave', ()=>dropArea.classList.remove('active'));
dropArea.addEventListener('drop', e=>{e.preventDefault();dropArea.classList.remove('active');if(e.dataTransfer.files.length)handleFiles(e.dataTransfer.files);});
dropArea.addEventListener('click', ()=>fileInput.click());
fileInput.addEventListener('change', e=>{if(e.target.files.length)handleFiles(e.target.files);});
function handleFiles(files){selectedFiles=Array.from(files);updateFileList();}
function updateFileList(){
    const wrap=document.getElementById('selectedFiles'), list=document.getElementById('filesList');
    if(!selectedFiles.length){wrap.style.display='none';return;}
    wrap.style.display='block';
    document.getElementById('totalFiles').textContent=selectedFiles.length;
    document.getElementById('totalSize').textContent=formatBytes(selectedFiles.reduce((s,f)=>s+f.size,0));
    list.innerHTML='';
    selectedFiles.forEach((f,i)=>{
        const c=document.createElement('div'); c.className='file-chip';
        c.innerHTML=`<span class="file-chip-name" title="${f.name}">${f.name}</span>
            <span style="display:flex;align-items:center;gap:7px;">
                <span style="color:var(--text-muted);font-size:11px;">${formatBytes(f.size)}</span>
                <button onclick="removeFile(${i})" class="btn btn-ghost btn-sm" style="padding:2px 6px;"><i class="fas fa-times"></i></button>
            </span>`;
        list.appendChild(c);
    });
}
function removeFile(i){selectedFiles.splice(i,1);const dt=new DataTransfer();selectedFiles.forEach(f=>dt.items.add(f));fileInput.files=dt.files;updateFileList();}
function clearFiles(){selectedFiles=[];fileInput.value='';updateFileList();document.getElementById('uploadProgress').style.display='none';}
function formatBytes(b,d=2){if(!b)return'0 B';const k=1024,s=['B','KB','MB','GB'],i=Math.floor(Math.log(b)/Math.log(k));return parseFloat((b/Math.pow(k,i)).toFixed(d))+' '+s[i];}
function uploadFiles(){
    if(uploadInProgress){showToast('Upload already in progress','warn');return;}
    if(!selectedFiles.length){showToast('No files selected','error');return;}
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const fd=new FormData(); fd.append('current_folder',folder); fd.append('csrf_token',CSRF_TOKEN);
    selectedFiles.forEach(f=>fd.append('files[]',f));
    const prog=document.getElementById('uploadProgress'),btn=document.getElementById('uploadBtn');
    prog.style.display='block'; btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Uploading...';
    const indiv=document.getElementById('individualProgress'); indiv.innerHTML='';
    selectedFiles.forEach((f,i)=>{
        const el=document.createElement('div');
        el.innerHTML=`<div class="progress-meta"><span style="color:var(--text-dim);font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:70%;" title="${f.name}">${f.name}</span><span id="fp_${i}" style="color:var(--gold);font-size:11px;">0%</span></div><div class="progress-bar-wrap"><div id="fb_${i}" class="progress-bar-fill"></div></div>`;
        indiv.appendChild(el);
    });
    const xhr=new XMLHttpRequest();
    xhr.upload.addEventListener('progress',e=>{if(e.lengthComputable){const p=Math.round((e.loaded/e.total)*100);document.getElementById('overallProgressBar').style.width=p+'%';document.getElementById('overallPercent').textContent=p+'%';}});
    xhr.onload=()=>{
        btn.disabled=false; btn.innerHTML='<i class="fas fa-upload"></i> Upload All';
        if(xhr.status===200){
            const r=JSON.parse(xhr.responseText);
            selectedFiles.forEach((_,i)=>{const b=document.getElementById('fb_'+i),p=document.getElementById('fp_'+i);if(b){b.style.width='100%';b.style.background='var(--success)';}if(p){p.textContent='100%';p.style.color='var(--success)';}});
            showToast(`Uploaded ${r.uploaded}/${r.total} files`,r.failed?'warn':'success');
            setTimeout(()=>location.reload(),1600);
        } else showToast('Server error!','error');
        uploadInProgress=false;
    };
    xhr.onerror=()=>{btn.disabled=false;btn.innerHTML='<i class="fas fa-upload"></i> Upload All';showToast('Network error!','error');uploadInProgress=false;};
    xhr.open('POST','ajax_upload.php'); xhr.send(fd); uploadInProgress=true;
}

// ══ SEARCH + FILTER + PAGINATION ══
let activeTypeFilter = '';
const TYPE_MAP = {
    image:   ['jpg','jpeg','png','gif','bmp','webp','svg','ico'],
    video:   ['mp4','avi','mkv','mov','wmv','webm'],
    audio:   ['mp3','wav','flac','m4a','ogg'],
    doc:     ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','rtf','csv','odt','md','log'],
    archive: ['zip','rar','7z','tar','gz'],
};
function filterByType(type){
    activeTypeFilter=type;
    document.querySelectorAll('.tfbtn').forEach(b=>b.classList.toggle('active',b.dataset.type===type));
    applyFilters();
}
function applyFilters(){
    const query=(document.getElementById('searchInput').value||'').trim().toLowerCase();
    document.getElementById('searchClear').style.display=query?'block':'none';
    filteredRows=allRows.filter(r=>{
        if(activeTypeFilter){
            if(activeTypeFilter==='folder'){if(r.dataset.isFolder!=='1')return false;}
            else{if(r.dataset.isFolder==='1')return false;const exts=TYPE_MAP[activeTypeFilter]||[];if(!exts.includes(r.dataset.type))return false;}
        }
        if(query){const name=(r.querySelector('.fname')?.textContent||'').toLowerCase();if(!name.includes(query))return false;}
        return true;
    });
    currentPage=1;
    const el=document.getElementById('typeFilterCount');
    if(el) el.textContent=filteredRows.length+' item'+(filteredRows.length!==1?'s':'');
    updateSearchCount(query);
    const noRes=document.getElementById('noResults');
    if(!filteredRows.length&&(query||activeTypeFilter)){document.getElementById('noResultsQuery').textContent=query||activeTypeFilter;noRes.style.display='block';}
    else noRes.style.display='none';
    renderPage();
}
function handleSearch(q){applyFilters();}
function clearSearch(){document.getElementById('searchInput').value='';applyFilters();}
function updateSearchCount(q){const el=document.getElementById('searchCount');if(!el)return;el.textContent=q&&q.trim()?filteredRows.length+' result'+(filteredRows.length!==1?'s':''):allRows.length+' item'+(allRows.length!==1?'s':'');}
function changePerPage(val){
    ITEMS_PER_PAGE=parseInt(val)||0;
    currentPage=1;
    localStorage.setItem('storagePerPage',val);
    renderPage();
}
function renderPage(){
    const ipp=ITEMS_PER_PAGE||filteredRows.length;
    const start=(currentPage-1)*ipp,end=start+ipp;
    allRows.forEach(r=>r.style.display='none');
    filteredRows.slice(start,end).forEach(r=>r.style.display='');
    buildPagination();
}
function buildPagination(){
    const ipp=ITEMS_PER_PAGE||filteredRows.length||1;
    const total=Math.ceil(filteredRows.length/ipp),pag=document.getElementById('pagination');
    if(!pag)return;
    if(total<=1){pag.style.display='none';return;}
    pag.style.display='flex';
    const s=(currentPage-1)*ipp+1,e=Math.min(currentPage*ipp,filteredRows.length);
    let html=`<button class="pag-btn ${currentPage===1?'disabled':''}" onclick="goPage(${currentPage-1})"><i class="fas fa-chevron-left"></i></button>`;
    for(let i=1;i<=total;i++){
        if(total<=7||i===1||i===total||(i>=currentPage-1&&i<=currentPage+1))html+=`<button class="pag-btn ${i===currentPage?'active':''}" onclick="goPage(${i})">${i}</button>`;
        else if(i===currentPage-2||i===currentPage+2)html+=`<span class="pag-dots">…</span>`;
    }
    html+=`<button class="pag-btn ${currentPage===total?'disabled':''}" onclick="goPage(${currentPage+1})"><i class="fas fa-chevron-right"></i></button>`;
    html+=`<span class="pag-info">${s}–${e} of ${filteredRows.length}</span>`;
    pag.innerHTML=html;
}
function goPage(p){const ipp=ITEMS_PER_PAGE||filteredRows.length||1;const t=Math.ceil(filteredRows.length/ipp);if(p<1||p>t)return;currentPage=p;renderPage();document.querySelector('.file-table-wrap').scrollIntoView({behavior:'smooth',block:'start'});}

// ══ SORT ══
function _cmpVal(r,col){
    if(col==='name') return r.dataset.name.toLowerCase();
    if(col==='type') return r.dataset.type.toLowerCase();
    if(col==='size') return parseFloat(r.dataset.size);
    if(col==='date') return parseInt(r.dataset.date);
    return '';
}
function _sortArr(arr,col,dir){
    // Folders always on top (unless sorting by type explicitly)
    arr.sort((a,b)=>{
        if(col!=='type'){
            const af=a.dataset.isFolder==='1', bf=b.dataset.isFolder==='1';
            if(af&&!bf)return -1; if(!af&&bf)return 1;
        }
        const av=_cmpVal(a,col), bv=_cmpVal(b,col);
        if(av<bv)return dir==='asc'?-1:1;
        if(av>bv)return dir==='asc'?1:-1;
        return 0;
    });
}
function sortBy(col){
    document.querySelectorAll('th.sortable').forEach(th=>th.classList.remove('sort-asc','sort-desc'));
    const thIdx={'name':0,'type':1,'size':2,'date':3}[col];
    const th=document.querySelectorAll('th.sortable')[thIdx];
    if(sortCol===col) sortDir=sortDir==='asc'?'desc':'asc';
    else{sortCol=col;sortDir='asc';}
    th.classList.add('sort-'+sortDir);
    _sortArr(filteredRows,col,sortDir);
    _sortArr(allRows,col,sortDir);
    const tbody=document.querySelector('.file-table tbody');
    if(tbody) allRows.forEach(r=>tbody.appendChild(r));
    currentPage=1; renderPage();
}

// ══ BULK SELECT ══
function toggleSelectAll(chk){
    const visibleRows=filteredRows.slice((currentPage-1)*ITEMS_PER_PAGE,currentPage*ITEMS_PER_PAGE);
    visibleRows.forEach(r=>{
        const c=r.querySelector('.row-chk');
        if(c){c.checked=chk.checked;if(chk.checked)selectedItems.add(c.dataset.name);else selectedItems.delete(c.dataset.name);r.classList.toggle('selected-row',chk.checked);}
    });
    updateBulkToolbar();
}
let lastCheckedIdx = -1;
function toggleSelect(chk, event){
    const tr = chk.closest('tr');
    const currentIdx = allRows.indexOf(tr);
    if(event && event.shiftKey && lastCheckedIdx >= 0 && lastCheckedIdx !== currentIdx){
        const lo=Math.min(lastCheckedIdx,currentIdx), hi=Math.max(lastCheckedIdx,currentIdx);
        allRows.slice(lo, hi+1).forEach(r=>{
            if(r.style.display==='none') return;
            const c=r.querySelector('.row-chk');
            if(c){c.checked=true; selectedItems.add(c.dataset.name); r.classList.add('selected-row');}
        });
    } else {
        if(chk.checked) selectedItems.add(chk.dataset.name);
        else selectedItems.delete(chk.dataset.name);
        tr.classList.toggle('selected-row', chk.checked);
        lastCheckedIdx = currentIdx;
    }
    updateBulkToolbar();
}
function updateBulkToolbar(){
    const bar=document.getElementById('bulkToolbar');
    const count=selectedItems.size;
    const batchBtn=document.getElementById('batchRenameBtn');
    if(count>0){
        bar.classList.add('visible');
        document.getElementById('bulkCount').textContent=count+' selected';
        if(batchBtn) batchBtn.style.display='';
    } else {
        bar.classList.remove('visible');
        if(batchBtn) batchBtn.style.display='none';
    }
}
function clearSelection(){
    selectedItems.clear();
    document.querySelectorAll('.row-chk').forEach(c=>{c.checked=false;c.closest('tr').classList.remove('selected-row');});
    const sa=document.getElementById('selectAll');if(sa)sa.checked=false;
    updateBulkToolbar();
}
function bulkTrash(){
    if(!selectedItems.size){showToast('Nothing selected','warn');return;}
    customConfirm(`Move ${selectedItems.size} item(s) to trash?`,()=>{
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const names=Array.from(selectedItems);
    let done=0, failed=0;
    const total=names.length;
    function next(i){
        if(i>=total){
            const msg=`Moved ${done} item(s) to trash`+(failed?`, ${failed} failed`:'');
            showToast(msg,failed?'warn':'success');
            setTimeout(()=>location.reload(),1200);
            return;
        }
        const xhr=new XMLHttpRequest();
        xhr.open('POST','ajax_trash.php',true);
        xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
        xhr.onload=()=>{const r=JSON.parse(xhr.responseText);if(r.success)done++;else failed++;next(i+1);};
        xhr.onerror=()=>{failed++;next(i+1);};
        xhr.send('action=move_to_trash&item_name='+encodeURIComponent(names[i])+'&current_folder='+encodeURIComponent(folder)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
    }
    next(0);
    },'Move to Trash');
}
function bulkDelete(){bulkTrash();}
function showBulkMoveModal(){
    if(!selectedItems.size){showToast('Nothing selected','warn');return;}
    moveTargetItem=null; moveIsBulk=true;
    document.getElementById('moveModalTitle').textContent='Move '+selectedItems.size+' Item(s)';
    document.getElementById('moveItemName').textContent=Array.from(selectedItems).join(', ');
    document.getElementById('moveMessage').style.display='none';
    populateMoveDropdown();
    openModal('moveModal');
}

// ══ MOVE SINGLE ══
function showMoveModal(name,isFolder){
    moveTargetItem={name,isFolder}; moveIsBulk=false;
    document.getElementById('moveModalTitle').textContent='Move '+(isFolder?'Folder':'File');
    document.getElementById('moveItemName').textContent=name;
    document.getElementById('moveMessage').style.display='none';
    populateMoveDropdown();
    openModal('moveModal');
}
function populateMoveDropdown(){
    const sel=document.getElementById('moveDestination');
    sel.innerHTML='';
    ALL_FOLDERS.forEach(f=>{
        const opt=document.createElement('option');
        opt.value=f.path; opt.textContent=f.label;
        sel.appendChild(opt);
    });
}
function performMove(){
    const dest=document.getElementById('moveDestination').value;
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const xhr=new XMLHttpRequest();
    xhr.open('POST',moveIsBulk?'ajax_bulk.php':'ajax_move.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{
        const r=JSON.parse(xhr.responseText);
        if(r.success){showMoveMsg(r.message,'success');setTimeout(()=>{closeModal('moveModal');location.reload();},1000);}
        else showMoveMsg(r.message,'error');
    };
    if(moveIsBulk){
        const items=Array.from(selectedItems).map(n=>({name:n}));
        xhr.send('action=move_bulk&current_folder='+encodeURIComponent(folder)+'&to_folder='+encodeURIComponent(dest)+'&items='+encodeURIComponent(JSON.stringify(items))+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
    } else {
        xhr.send('item_name='+encodeURIComponent(moveTargetItem.name)+'&from_folder='+encodeURIComponent(folder)+'&to_folder='+encodeURIComponent(dest)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
    }
}
function showMoveMsg(msg,type){
    const el=document.getElementById('moveMessage');
    el.className='msg-box '+(type==='success'?'msg-success':'msg-error');
    el.innerHTML='<i class="fas fa-'+(type==='success'?'check-circle':'exclamation-circle')+'"></i> '+msg;
}

// ══ COPY LINK ══
function copyFileLink(filename){
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const url=BASE_URL+'/uploads/'+(folder?folder+'/':'')+filename;
    if(navigator.clipboard)navigator.clipboard.writeText(url).then(()=>showToast('Link copied!','success'),()=>fallbackCopy(url));
    else fallbackCopy(url);
}
function fallbackCopy(url){const el=document.createElement('input');el.style.position='absolute';el.style.left='-9999px';el.value=url;document.body.appendChild(el);el.select();document.execCommand('copy');document.body.removeChild(el);showToast('Link copied!','success');}

// ══ PREVIEW ══
function viewFile(filename,ext,fromLightbox=false){
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const path='uploads/'+(folder?folder+'/':'')+filename;
    document.getElementById('fileName').textContent=filename;
    document.getElementById('previewBody').innerHTML='<div style="padding:50px;text-align:center;color:var(--text-muted);"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    openModal('previewModal');
    currentPreviewFile=path; currentRotation=0;
    ext=ext.toLowerCase();
    const isImg=['jpg','jpeg','png','gif','bmp','webp','svg'].includes(ext);
    // Lightbox state
    if(isImg && !fromLightbox){
        lbIndex=imageRows.findIndex(r=>r.dataset.name===filename);
    }
    const lbPrev=document.getElementById('lbPrev'),lbNext=document.getElementById('lbNext'),lbCtr=document.getElementById('lbCounter');
    if(isImg && imageRows.length>1){
        lbPrev.style.display=''; lbNext.style.display=''; lbCtr.style.display='';
        lbCtr.textContent=(lbIndex+1)+' / '+imageRows.length;
        lbPrev.disabled=(lbIndex<=0); lbNext.disabled=(lbIndex>=imageRows.length-1);
    } else {
        lbPrev.style.display='none'; lbNext.style.display='none'; lbCtr.style.display='none';
    }
    const body=document.getElementById('previewBody');
    if(isImg){
        body.innerHTML=`<img id="lbImg" src="${path}" style="max-width:100%;max-height:58vh;border-radius:10px;border:1px solid var(--glass-border);" onerror="this.alt='Failed to load'">
            <div id="imgInfo" style="font-size:11px;color:var(--text-muted);margin-top:8px;text-align:center;"></div>
            <div style="display:flex;justify-content:center;gap:8px;margin-top:10px;flex-wrap:wrap;">
                <button onclick="toggleFullscreen()" class="btn btn-ghost btn-sm"><i class="fas fa-expand"></i> Fullscreen</button>
                <button onclick="zoomImage()" class="btn btn-ghost btn-sm"><i class="fas fa-search-plus"></i> Zoom</button>
                <button onclick="rotateImage()" class="btn btn-ghost btn-sm"><i class="fas fa-redo"></i> Rotate</button>
                <button onclick="copyFileLink('${filename}')" class="btn btn-gold btn-sm"><i class="fas fa-link"></i> Copy Link</button>
                <button onclick="copyFilePath('${filename}')" class="btn btn-ghost btn-sm"><i class="fas fa-code"></i> Copy Path</button>
            </div>`;
        const img=document.getElementById('lbImg');
        img.onload=()=>{const info=document.getElementById('imgInfo');if(info)info.textContent=img.naturalWidth+'×'+img.naturalHeight+'px';};
    } else if(['mp3','wav','ogg','m4a','flac'].includes(ext)){
        body.innerHTML=`<div style="padding:20px;"><audio controls style="width:100%;"><source src="${path}">Not supported.</audio></div>`;
    } else if(['mp4','webm','mov'].includes(ext)){
        body.innerHTML=`<video controls style="max-width:100%;max-height:58vh;border-radius:8px;background:#000;"><source src="${path}">Not supported.</video>`;
    } else if(['txt','md','log','json','xml','csv','html','css','js'].includes(ext)){
        fetch(path+'?nocache='+Date.now()).then(r=>r.text()).then(t=>{
            const truncated=t.length>10000;
            const display=truncated?t.substring(0,10000)+'\n\n… [truncated]':t;
            body.innerHTML=`<div style="text-align:left;background:rgba(0,0,0,.3);border:1px solid var(--glass-border);border-radius:10px;padding:18px;max-height:52vh;overflow:auto;margin-bottom:10px;"><pre style="color:var(--text);font-family:'Courier New',monospace;font-size:12px;white-space:pre-wrap;word-break:break-all;">${escapeHtml(display)}</pre></div>
            <div style="display:flex;justify-content:center;gap:8px;">
                <button onclick="closeModal('previewModal');openFileEditor('${filename}','${ext}')" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Edit File</button>
                <button onclick="copyFileLink('${filename}')" class="btn btn-gold btn-sm"><i class="fas fa-link"></i> Copy Link</button>
            </div>`;
        }).catch(()=>{body.innerHTML=`<div style="text-align:center;padding:30px;color:var(--text-muted);">Cannot load preview. <button onclick="downloadCurrentFile()" class="btn btn-primary" style="margin-top:12px;"><i class="fas fa-download"></i> Download</button></div>`;});
    } else if(ext === 'pdf'){
        body.innerHTML=`<iframe src="${path}" style="width:100%;height:62vh;border-radius:8px;border:1px solid var(--glass-border);background:#fff;"></iframe>
            <div style="display:flex;justify-content:center;gap:8px;margin-top:10px;">
                <a href="${path}" target="_blank" class="btn btn-ghost btn-sm"><i class="fas fa-external-link-alt"></i> Open in New Tab</a>
                <button onclick="downloadCurrentFile()" class="btn btn-ghost btn-sm"><i class="fas fa-download"></i> Download</button>
                <button onclick="copyFileLink('${filename}')" class="btn btn-gold btn-sm"><i class="fas fa-link"></i> Copy Link</button>
            </div>`;
    } else {
        body.innerHTML=`<div style="text-align:center;padding:40px;color:var(--text-muted);"><div style="font-size:50px;margin-bottom:14px;">📄</div><h3 style="color:var(--text-dim);margin-bottom:8px;">No preview</h3><p style="font-size:13px;">.${ext} cannot be previewed</p><div style="display:flex;gap:8px;justify-content:center;margin-top:18px;"><button onclick="downloadCurrentFile()" class="btn btn-primary"><i class="fas fa-download"></i> Download</button><button onclick="copyFileLink('${filename}')" class="btn btn-gold"><i class="fas fa-link"></i> Copy Link</button></div></div>`;
    }
}
function downloadCurrentFile(){if(!currentPreviewFile)return;const a=document.createElement('a');a.href=currentPreviewFile;a.download=currentPreviewFile.split('/').pop();document.body.appendChild(a);a.click();document.body.removeChild(a);showToast('Download started!','success');}
function zoomImage(){const img=document.querySelector('#previewBody img');if(!img)return;const z=img.style.maxHeight==='none';img.style.maxWidth=z?'100%':'none';img.style.maxHeight=z?'58vh':'none';}
function rotateImage(){const img=document.querySelector('#previewBody img');if(!img)return;currentRotation=(currentRotation+90)%360;img.style.transform=`rotate(${currentRotation}deg)`;img.style.transition='transform .3s';}

// ══ RENAME ══
function showEditModal(name,isFolder){
    currentEditItem={name,isFolder};
    document.getElementById('editModalTitle').textContent=isFolder?'Rename Folder':'Rename File';
    document.getElementById('currentName').value=name;
    document.getElementById('newName').value='';
    document.getElementById('editMessage').style.display='none';
    openModal('editModal');
    setTimeout(()=>document.getElementById('newName').focus(),120);
}
function performRename(){
    const newName=document.getElementById('newName').value.trim();
    if(!newName){showEditMsg('Name cannot be empty','error');return;}
    if(newName===currentEditItem.name){showEditMsg('Same as current name','error');return;}
    if(!/^[a-zA-Z0-9_.\-]+$/.test(newName)){showEditMsg('Use letters, numbers, dot, _ or -','error');return;}
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const xhr=new XMLHttpRequest();
    xhr.open('POST','ajax_actions.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);if(r.success){showEditMsg('Renamed! Refreshing...','success');setTimeout(()=>location.reload(),900);}else showEditMsg(r.message,'error');};
    xhr.send('action=rename&old_name='+encodeURIComponent(currentEditItem.name)+'&new_name='+encodeURIComponent(newName)+'&current_folder='+encodeURIComponent(folder)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}
function showEditMsg(msg,type){const el=document.getElementById('editMessage');el.className='msg-box '+(type==='success'?'msg-success':'msg-error');el.innerHTML='<i class="fas fa-'+(type==='success'?'check-circle':'exclamation-circle')+'"></i> '+msg;}

// ══ DELETE ══
function showDeleteModal(name,isFolder){
    currentDeleteItem={name,isFolder};
    document.getElementById('deleteModalTitle').textContent=isFolder?'Delete Folder':'Delete File';
    document.getElementById('deleteItemName').textContent=name;
    openModal('deleteModal');
}
function performDelete(){
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const action=currentDeleteItem.isFolder?'delete_folder':'delete_file';
    const key=currentDeleteItem.isFolder?'folder_name':'file_name';
    const xhr=new XMLHttpRequest();
    xhr.open('POST','ajax_actions.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);closeModal('deleteModal');if(r.success){showToast('Deleted!','success');setTimeout(()=>location.reload(),900);}else showToast(r.message,'error');};
    xhr.send('action='+action+'&'+key+'='+encodeURIComponent(currentDeleteItem.name)+'&current_folder='+encodeURIComponent(folder)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}

// ══ CHANGE PASSWORD ══
function showChangePwModal(){document.getElementById('currentPw').value='';document.getElementById('newPw').value='';document.getElementById('confirmPw').value='';document.getElementById('pwMessage').style.display='none';openModal('changePwModal');}
function performChangePw(){
    const cur=document.getElementById('currentPw').value;
    const nw=document.getElementById('newPw').value;
    const conf=document.getElementById('confirmPw').value;
    if(!cur||!nw||!conf){showPwMsg('All fields required','error');return;}
    const btn=document.getElementById('changePwBtn');
    btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Updating...';
    const xhr=new XMLHttpRequest();
    xhr.open('POST','ajax_change_password.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{
        btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> Update Password';
        const r=JSON.parse(xhr.responseText);
        if(r.success){showPwMsg(r.message,'success');setTimeout(()=>closeModal('changePwModal'),2000);}
        else showPwMsg(r.message,'error');
    };
    xhr.send('current_password='+encodeURIComponent(cur)+'&new_password='+encodeURIComponent(nw)+'&confirm_password='+encodeURIComponent(conf)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}
function showPwMsg(msg,type){const el=document.getElementById('pwMessage');el.className='msg-box '+(type==='success'?'msg-success':'msg-error');el.innerHTML='<i class="fas fa-'+(type==='success'?'check-circle':'exclamation-circle')+'"></i> '+msg;}

// ══ ACTIVITY LOG ══
function showLogModal(){
    openModal('logModal');
    document.getElementById('logContent').innerHTML='<div style="padding:30px;text-align:center;color:var(--text-muted);"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i></div>';
    fetch('ajax_log.php?csrf_token='+encodeURIComponent(CSRF_TOKEN))
        .then(r=>r.json()).then(r=>{
            if(!r.entries||!r.entries.length){document.getElementById('logContent').innerHTML='<div style="padding:30px;text-align:center;color:var(--text-muted);">No activity logged yet.</div>';return;}
            document.getElementById('logContent').innerHTML=r.entries.map(e=>`<div class="log-entry">${e}</div>`).join('');
        }).catch(()=>{document.getElementById('logContent').innerHTML='<div style="padding:20px;color:#e07070;">Failed to load log.</div>';});
}

// ══ VIEW TOGGLE ══
function toggleTheme(){
    const isLight = document.body.classList.toggle('light');
    const ic = document.querySelector('#btnTheme i');
    if(ic){ ic.className = isLight ? 'fas fa-moon' : 'fas fa-sun'; }
    localStorage.setItem('storageTheme', isLight ? 'light' : 'dark');
}
function setView(mode){
    const wrap=document.getElementById('fileTableWrap');
    document.getElementById('btnList').classList.toggle('active',mode==='list');
    document.getElementById('btnGrid').classList.toggle('active',mode==='grid');
    if(mode==='grid')wrap.classList.add('grid-mode'); else wrap.classList.remove('grid-mode');
    localStorage.setItem('storageView',mode);
    if(allRows.length)renderPage();
}

// ══ TOAST ══
function showToast(msg,type='success'){
    const toast=document.getElementById('toast'),icon=document.getElementById('toastIcon');
    document.getElementById('toastMessage').textContent=msg;
    const c={success:'var(--success)',error:'var(--danger)',warn:'var(--gold)',info:'var(--info)'};
    const ic={success:'check-circle',error:'exclamation-circle',warn:'exclamation-triangle',info:'info-circle'};
    icon.className='fas fa-'+(ic[type]||'check-circle'); icon.style.color=c[type]||c.success;
    toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'),3200);
}
function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}

// ══ LIGHTBOX NAVIGATION ══
function lightboxNav(dir){
    lbIndex = Math.max(0, Math.min(imageRows.length-1, lbIndex+dir));
    const row = imageRows[lbIndex];
    if(row) viewFile(row.dataset.name, row.dataset.type, true);
}

// ══ CONTEXT MENU ══
let ctxRow = null;
function initContextMenu(){
    const menu = document.getElementById('ctxMenu');
    document.querySelector('.file-table tbody')?.addEventListener('contextmenu', e=>{
        const tr = e.target.closest('tr');
        if(!tr) return;
        e.preventDefault();
        ctxRow = tr;
        const isFolder = tr.dataset.isFolder === '1';
        const name = tr.dataset.name;
        document.getElementById('ctxLabel').textContent = name.length>22 ? name.substring(0,22)+'…' : name;
        document.getElementById('ctxOpen').style.display    = isFolder ? '' : 'none';
        document.getElementById('ctxPreview').style.display = isFolder ? 'none' : '';
        document.getElementById('ctxCopyLink').style.display = isFolder ? 'none' : '';
        document.getElementById('ctxDownload').style.display = isFolder ? 'none' : '';
        document.getElementById('ctxDuplicate').style.display = isFolder ? 'none' : '';
        // Position
        let x=e.clientX, y=e.clientY;
        menu.style.left=x+'px'; menu.style.top=y+'px';
        menu.classList.add('open');
        // Adjust if out of viewport
        requestAnimationFrame(()=>{
            const r=menu.getBoundingClientRect();
            if(r.right>window.innerWidth) menu.style.left=(x-r.width)+'px';
            if(r.bottom>window.innerHeight) menu.style.top=(y-r.height)+'px';
        });
    });
    document.addEventListener('click', ()=>menu.classList.remove('open'));
    document.addEventListener('scroll', ()=>menu.classList.remove('open'), true);
}
function ctxAction(action){
    if(!ctxRow) return;
    document.getElementById('ctxMenu').classList.remove('open');
    const name = ctxRow.dataset.name;
    const isFolder = ctxRow.dataset.isFolder === '1';
    const folder = new URLSearchParams(window.location.search).get('folder')||'';
    const ext = ctxRow.dataset.type;
    const path = 'uploads/'+(folder?folder+'/':'')+name;
    switch(action){
        case 'open':    location.href='dashboard.php?folder='+(folder?folder+'/':'')+encodeURIComponent(name); break;
        case 'preview': viewFile(name, ext); break;
        case 'copylink': copyFileLink(name); break;
        case 'download': {const a=document.createElement('a');a.href=path;a.download=name;document.body.appendChild(a);a.click();document.body.removeChild(a);} break;
        case 'rename':  showEditModal(name, isFolder); break;
        case 'move':    showMoveModal(name, isFolder); break;
        case 'duplicate': duplicateFile(name); break;
        case 'delete':  showDeleteModal(name, isFolder); break;
    }
}

// ══ KEYBOARD SHORTCUTS ══
function initKeyboard(){
    document.addEventListener('keydown', e=>{
        if(e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA'||e.target.isContentEditable) return;
        if(document.querySelector('.modal-overlay.open')) return;
        if(e.key==='Delete'&&selectedItems.size){e.preventDefault();bulkDelete();}
        else if(e.key==='Delete'&&ctxRow){e.preventDefault();showDeleteModal(ctxRow.dataset.name,ctxRow.dataset.isFolder==='1');}
        else if((e.ctrlKey||e.metaKey)&&e.key==='a'){e.preventDefault();const sa=document.getElementById('selectAll');if(sa){sa.checked=true;toggleSelectAll(sa);}}
    });
}

// ══ DRAG & DROP TO FOLDER (move) ══
function initDragDrop(){
    const tbody = document.querySelector('.file-table tbody');
    if(!tbody) return;
    tbody.addEventListener('dragstart', e=>{
        const tr = e.target.closest('tr'); if(!tr) return;
        tr.classList.add('dragging');
        e.dataTransfer.setData('text/plain', tr.dataset.name);
        e.dataTransfer.setData('application/isFolder', tr.dataset.isFolder);
        e.dataTransfer.effectAllowed = 'move';
    });
    tbody.addEventListener('dragend', e=>{
        document.querySelectorAll('tr.dragging').forEach(r=>r.classList.remove('dragging'));
        document.querySelectorAll('tr.drag-over').forEach(r=>r.classList.remove('drag-over'));
    });
    tbody.addEventListener('dragover', e=>{
        e.preventDefault(); e.dataTransfer.dropEffect='move';
        const tr=e.target.closest('tr');
        document.querySelectorAll('tr.drag-over').forEach(r=>r.classList.remove('drag-over'));
        if(tr&&tr.dataset.isFolder==='1'&&!tr.classList.contains('dragging')) tr.classList.add('drag-over');
    });
    tbody.addEventListener('dragleave', e=>{
        if(!e.relatedTarget||!e.relatedTarget.closest('tr.drag-over')) document.querySelectorAll('tr.drag-over').forEach(r=>r.classList.remove('drag-over'));
    });
    tbody.addEventListener('drop', e=>{
        e.preventDefault();
        document.querySelectorAll('tr.drag-over').forEach(r=>r.classList.remove('drag-over'));
        const destRow=e.target.closest('tr');
        if(!destRow||destRow.dataset.isFolder!=='1') return;
        const srcName=e.dataTransfer.getData('text/plain');
        if(srcName===destRow.dataset.name) return;
        const folder=new URLSearchParams(window.location.search).get('folder')||'';
        const destFolder=(folder?folder+'/':'')+destRow.dataset.name;
        const xhr=new XMLHttpRequest();
        xhr.open('POST','ajax_move.php',true);
        xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
        xhr.onload=()=>{const r=JSON.parse(xhr.responseText);if(r.success){showToast(srcName+' moved!','success');setTimeout(()=>location.reload(),900);}else showToast(r.message,'error');};
        xhr.send('item_name='+encodeURIComponent(srcName)+'&from_folder='+encodeURIComponent(folder)+'&to_folder='+encodeURIComponent(destFolder)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
    });
}

// ══ PASTE TO UPLOAD ══
function initPasteUpload(){
    document.addEventListener('paste', e=>{
        if(e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA') return;
        const items = Array.from(e.clipboardData?.items||[]);
        const imgItems = items.filter(it=>it.kind==='file'&&it.type.startsWith('image/'));
        if(!imgItems.length) return;
        const files = imgItems.map(it=>it.getAsFile());
        handleFiles(files);
        showToast(files.length+' image(s) pasted — click Upload All','info');
        // Scroll to upload area
        document.getElementById('dropArea')?.scrollIntoView({behavior:'smooth',block:'center'});
    });
}

// ══ SESSION EXTEND ══
function extendSession(){
    const xhr=new XMLHttpRequest();
    xhr.open('POST','ajax_session.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);showToast(r.message,r.success?'success':'error');};
    xhr.send('csrf_token='+encodeURIComponent(CSRF_TOKEN));
}

// ══ DUPLICATE FILE ══
function duplicateFile(filename){
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const xhr=new XMLHttpRequest();
    xhr.open('POST','ajax_actions.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);if(r.success){showToast(r.message,'success');setTimeout(()=>location.reload(),1000);}else showToast(r.message,'error');};
    xhr.send('action=copy_file&file_name='+encodeURIComponent(filename)+'&current_folder='+encodeURIComponent(folder)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}

// ══ BULK DOWNLOAD ZIP ══
function bulkDownloadZip(){
    if(!selectedItems.size){showToast('Nothing selected','warn');return;}
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const items=JSON.stringify(Array.from(selectedItems).map(n=>({name:n})));
    document.getElementById('bulkZipCsrf').value   = CSRF_TOKEN;
    document.getElementById('bulkZipFolder').value = folder;
    document.getElementById('bulkZipItems').value  = items;
    showToast('Preparing ZIP download…','info');
    document.getElementById('bulkZipForm').submit();
}

// ══ MOVE TO TRASH (single) ══
function moveToTrash(name){
    customConfirm('Move "'+name+'" to trash?',()=>{
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const xhr=new XMLHttpRequest();
    xhr.open('POST','ajax_trash.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);showToast(r.message,r.success?'success':'error');if(r.success)setTimeout(()=>location.reload(),900);};
    xhr.send('action=move_to_trash&item_name='+encodeURIComponent(name)+'&current_folder='+encodeURIComponent(folder)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
    },'Move to Trash');
}

// ══ TRASH MODAL ══
function showTrashModal(){
    openModal('trashModal');
    const el=document.getElementById('trashContent');
    el.innerHTML='<div style="padding:30px;text-align:center;color:var(--text-muted);"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    fetch('ajax_trash.php?action=list&csrf_token='+encodeURIComponent(CSRF_TOKEN))
        .then(r=>r.json()).then(r=>{
            if(!r.items||!r.items.length){el.innerHTML='<div style="padding:30px;text-align:center;color:var(--text-muted);"><i class="fas fa-trash-alt" style="font-size:36px;display:block;margin-bottom:12px;opacity:.3;"></i>Trash is empty</div>';return;}
            el.innerHTML=`<table style="width:100%;border-collapse:collapse;">
                <thead><tr>
                    <th style="padding:9px 14px;text-align:left;font-size:11px;color:var(--text-muted);text-transform:uppercase;border-bottom:1px solid var(--glass-border);">Name</th>
                    <th style="padding:9px 14px;font-size:11px;color:var(--text-muted);text-transform:uppercase;border-bottom:1px solid var(--glass-border);">From</th>
                    <th style="padding:9px 14px;font-size:11px;color:var(--text-muted);text-transform:uppercase;border-bottom:1px solid var(--glass-border);">Deleted</th>
                    <th style="padding:9px 14px;border-bottom:1px solid var(--glass-border);"></th>
                </tr></thead>
                <tbody>${r.items.map(item=>`<tr style="border-bottom:1px solid rgba(255,255,255,.04);">
                    <td style="padding:9px 14px;font-size:13px;color:var(--text);">${item.is_dir?'📁 ':'📄 '}${escapeHtml(item.original_name)}</td>
                    <td style="padding:9px 14px;font-size:11px;color:var(--text-muted);">${escapeHtml(item.original_folder||'root')}</td>
                    <td style="padding:9px 14px;font-size:11px;color:var(--text-muted);white-space:nowrap;">${item.deleted_at_fmt}</td>
                    <td style="padding:9px 14px;">
                        <div style="display:flex;gap:5px;">
                            <button onclick="restoreTrashItem('${escapeHtml(item.trash_name)}')" class="btn btn-success btn-sm" title="Restore"><i class="fas fa-undo"></i></button>
                            <button onclick="deleteTrashItem('${escapeHtml(item.trash_name)}')" class="btn btn-danger btn-sm" title="Delete Permanently"><i class="fas fa-times"></i></button>
                        </div>
                    </td>
                </tr>`).join('')}</tbody>
            </table>`;
        }).catch(()=>{el.innerHTML='<div style="padding:20px;color:#e07070;">Failed to load trash.</div>';});
}
function restoreTrashItem(trashName){
    const xhr=new XMLHttpRequest();
    xhr.open('POST','ajax_trash.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);showToast(r.message,r.success?'success':'error');if(r.success){closeModal('trashModal');setTimeout(()=>location.reload(),900);}};
    xhr.send('action=restore&trash_name='+encodeURIComponent(trashName)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}
function deleteTrashItem(trashName){
    customConfirm('Permanently delete this item?',()=>{
    const xhr=new XMLHttpRequest();
    xhr.open('POST','ajax_trash.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);showToast(r.message,r.success?'success':'error');if(r.success)showTrashModal();};
    xhr.send('action=delete_permanent&trash_name='+encodeURIComponent(trashName)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
    },'Delete Permanently');
}
function emptyTrash(){
    customConfirm('Permanently delete ALL items in trash?',()=>{
    const xhr=new XMLHttpRequest();
    xhr.open('POST','ajax_trash.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);showToast(r.message,r.success?'success':'error');if(r.success)showTrashModal();};
    xhr.send('action=empty_trash&csrf_token='+encodeURIComponent(CSRF_TOKEN));
    },'Empty Trash');
}

// ══ BATCH RENAME ══
function showBatchRenameModal(){
    if(!selectedItems.size){showToast('Select items first','warn');return;}
    document.getElementById('batchCount').textContent=selectedItems.size;
    document.getElementById('batchPattern').value='';
    document.getElementById('batchStart').value='1';
    document.getElementById('batchPadding').value='3';
    document.getElementById('batchRenameMsg').style.display='none';
    updateBatchPreview();
    openModal('batchRenameModal');
    setTimeout(()=>document.getElementById('batchPattern').focus(),120);
}
function updateBatchPreview(){
    const pattern=document.getElementById('batchPattern').value||'name';
    const start=parseInt(document.getElementById('batchStart').value)||1;
    const pad=parseInt(document.getElementById('batchPadding').value)||3;
    document.getElementById('batchPreview').textContent=pattern.replace(/ /g,'_')+'_'+String(start).padStart(pad,'0')+'.ext';
}
function performBatchRename(){
    const pattern=document.getElementById('batchPattern').value.trim();
    if(!pattern){showBatchMsg('Pattern cannot be empty','error');return;}
    if(!/^[a-zA-Z0-9_\- ]+$/.test(pattern)){showBatchMsg('Only letters, numbers, spaces, _ or - allowed','error');return;}
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const items=JSON.stringify(Array.from(selectedItems).map(n=>({name:n})));
    const xhr=new XMLHttpRequest();
    xhr.open('POST','ajax_batch_rename.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{
        const r=JSON.parse(xhr.responseText);
        if(r.success){showBatchMsg(`Renamed ${r.renamed} items!`,'success');setTimeout(()=>{closeModal('batchRenameModal');location.reload();},1200);}
        else showBatchMsg(r.message,'error');
    };
    xhr.send('pattern='+encodeURIComponent(pattern)+'&start_num='+document.getElementById('batchStart').value+'&padding='+document.getElementById('batchPadding').value+'&current_folder='+encodeURIComponent(folder)+'&items='+encodeURIComponent(items)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}
function showBatchMsg(msg,type){const el=document.getElementById('batchRenameMsg');el.className='msg-box '+(type==='success'?'msg-success':'msg-error');el.innerHTML='<i class="fas fa-'+(type==='success'?'check-circle':'exclamation-circle')+'"></i> '+msg;}

// ══ SHARE LINK ══
let currentShareFile = '';
function showShareModal(filename){
    currentShareFile=filename;
    document.getElementById('shareFileName').textContent=filename;
    document.getElementById('shareMsg').style.display='none';
    document.getElementById('shareResult').style.display='none';
    document.getElementById('shareQrWrap').innerHTML='';
    openModal('shareModal');
}
function createShareLink(){
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const hours=document.getElementById('shareExpiry').value;
    const btn=document.getElementById('createShareBtn');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Generating…';
    const xhr=new XMLHttpRequest();
    xhr.open('POST','ajax_share.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{
        btn.disabled=false; btn.innerHTML='<i class="fas fa-link"></i> Generate Link';
        const r=JSON.parse(xhr.responseText);
        if(r.success){
            document.getElementById('shareUrl').value=r.url;
            document.getElementById('shareExpiresFmt').textContent=r.expires_fmt;
            document.getElementById('shareResult').style.display='block';
            // Generate QR
            const qrWrap=document.getElementById('shareQrWrap');
            qrWrap.innerHTML='';
            if(typeof QRCode!=='undefined'){
                new QRCode(qrWrap,{text:r.url,width:160,height:160,colorDark:'#c02828',colorLight:'#07000a'});
            }
        } else {
            const el=document.getElementById('shareMsg');
            el.className='msg-box msg-error'; el.innerHTML='<i class="fas fa-exclamation-circle"></i> '+r.message;
        }
    };
    xhr.send('action=create&file_name='+encodeURIComponent(currentShareFile)+'&current_folder='+encodeURIComponent(folder)+'&expires_hours='+encodeURIComponent(hours)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}
function copyShareUrl(){
    const url=document.getElementById('shareUrl').value;
    if(navigator.clipboard)navigator.clipboard.writeText(url).then(()=>showToast('Share URL copied!','success'));
    else{const el=document.getElementById('shareUrl');el.select();document.execCommand('copy');showToast('Share URL copied!','success');}
}

// ══ EDIT TEXT FILE ══
let editingFileName='';
function openFileEditor(filename,ext){
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const path='uploads/'+(folder?folder+'/':'')+filename;
    editingFileName=filename;
    document.getElementById('editFileName').textContent=filename;
    document.getElementById('editFileMsg').style.display='none';
    document.getElementById('editFileContent').value='Loading…';
    openModal('editFileModal');
    fetch(path+'?nocache='+Date.now()).then(r=>r.text()).then(t=>{
        document.getElementById('editFileContent').value=t;
    }).catch(()=>{document.getElementById('editFileContent').value='';showToast('Failed to load file','error');});
}
function saveFileEdit(){
    const content=document.getElementById('editFileContent').value;
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const xhr=new XMLHttpRequest();
    xhr.open('POST','ajax_edit_file.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{
        const r=JSON.parse(xhr.responseText);
        const el=document.getElementById('editFileMsg');
        el.className='msg-box '+(r.success?'msg-success':'msg-error');
        el.innerHTML='<i class="fas fa-'+(r.success?'check-circle':'exclamation-circle')+'"></i> '+r.message;
        if(r.success) showToast('File saved!','success');
    };
    xhr.send('file_name='+encodeURIComponent(editingFileName)+'&current_folder='+encodeURIComponent(folder)+'&content='+encodeURIComponent(content)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}

// ══ FOLDER COLOR/ICON ══
let fcFolderName='', fcFolderKey='';
function showFolderColorModal(name,key,curColor,curIcon){
    fcFolderName=name; fcFolderKey=key;
    document.getElementById('fcFolderKey').value=key;
    document.getElementById('fcIconInput').value=curIcon||'';
    document.getElementById('fcColorInput').value=curColor||'#c9a84c';
    document.getElementById('fcPreviewName').textContent=name;
    document.getElementById('fcPreviewIcon').textContent=curIcon||'📁';
    document.getElementById('fcPreviewIcon').style.color=curColor||'';
    openModal('folderColorModal');
}
function selectIcon(em){
    document.getElementById('fcIconInput').value=em;
    document.getElementById('fcPreviewIcon').textContent=em;
}
function selectColor(c){
    document.getElementById('fcColorInput').value=c||'#c9a84c';
    document.getElementById('fcPreviewIcon').style.color=c||'';
    document.getElementById('fcPreviewName').style.color=c||'';
}
document.addEventListener('DOMContentLoaded',()=>{
    const ci=document.getElementById('fcColorInput');
    if(ci) ci.addEventListener('input',e=>{document.getElementById('fcPreviewIcon').style.color=e.target.value;document.getElementById('fcPreviewName').style.color=e.target.value;});
    const ii=document.getElementById('fcIconInput');
    if(ii) ii.addEventListener('input',e=>{document.getElementById('fcPreviewIcon').textContent=e.target.value||'📁';});
});
function saveFolderMeta(){
    const icon=document.getElementById('fcIconInput').value;
    const color=document.getElementById('fcColorInput').value;
    const xhr=new XMLHttpRequest();
    xhr.open('POST','ajax_folder_meta.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);if(r.success){showToast('Folder style saved!','success');closeModal('folderColorModal');setTimeout(()=>location.reload(),700);}else showToast(r.message,'error');};
    xhr.send('folder_key='+encodeURIComponent(fcFolderKey)+'&color='+encodeURIComponent(color)+'&icon='+encodeURIComponent(icon)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}
function resetFolderMeta(){
    const xhr=new XMLHttpRequest();
    xhr.open('POST','ajax_folder_meta.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);if(r.success){showToast('Reset!','success');closeModal('folderColorModal');setTimeout(()=>location.reload(),700);}};
    xhr.send('folder_key='+encodeURIComponent(fcFolderKey)+'&color=&icon=&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}

// ══ STORAGE STATS MODAL ══
function showStatsModal(){
    openModal('statsModal');
    const el=document.getElementById('statsContent');
    el.innerHTML='<div style="padding:30px;text-align:center;color:var(--text-muted);"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    // Build stats from PHP-injected data (calculate per root-level folder recursively is too slow in JS, show overall + folder list)
    const totalMB=(STORAGE_USED/1024/1024).toFixed(2);
    const limitMB=5120; // 5GB soft limit display
    const pct=Math.min(100,((STORAGE_USED/1024/1024/limitMB)*100)).toFixed(1);
    el.innerHTML=`
        <div style="padding:18px 22px;">
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:7px;">
                <span style="color:var(--text-dim);">Total Storage Used</span>
                <span style="color:var(--gold);font-weight:700;">${totalMB} MB</span>
            </div>
            <div style="height:8px;background:rgba(255,255,255,.06);border-radius:4px;overflow:hidden;margin-bottom:18px;">
                <div style="height:100%;width:${pct}%;background:linear-gradient(90deg,var(--maroon),var(--maroon-bright));border-radius:4px;"></div>
            </div>
            <p style="font-size:12px;color:var(--text-muted);text-align:center;">
                Folder breakdown requires refreshing. Use the activity log to track uploads.
            </p>
        </div>`;
}

// ══ CUSTOM CONFIRM ══
let _confirmCallback = null;
function customConfirm(msg, onYes, confirmText='Confirm', danger=true, iconCls='fa-exclamation-triangle', sub=''){
    _confirmCallback = onYes;
    document.getElementById('confirmMsg').textContent = msg;
    document.getElementById('confirmSub').textContent = sub;
    document.getElementById('confirmIcon').textContent = danger ? '⚠️' : 'ℹ️';
    const btn = document.getElementById('confirmOkBtn');
    btn.innerHTML = '<i class="fas fa-check"></i> ' + confirmText;
    btn.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
    openModal('confirmModal');
}
function confirmResolve(yes){
    closeModal('confirmModal');
    if(yes && _confirmCallback) _confirmCallback();
    _confirmCallback = null;
}

// ══ FULLSCREEN IMAGE ══
function toggleFullscreen(){
    const modal = document.getElementById('previewModal');
    const isFs = modal.classList.toggle('fs-mode');
    const btns = document.querySelectorAll('#previewBody button');
    btns.forEach(b=>{ if(b.onclick && b.getAttribute('onclick') && b.getAttribute('onclick').includes('toggleFullscreen'))
        b.innerHTML = isFs ? '<i class="fas fa-compress"></i> Exit Full' : '<i class="fas fa-expand"></i> Fullscreen';
    });
}

// ══ COPY FILE PATH ══
function copyFilePath(filename){
    const folder = new URLSearchParams(window.location.search).get('folder')||'';
    const path = 'uploads/'+(folder?folder+'/':'')+filename;
    if(navigator.clipboard) navigator.clipboard.writeText(path).then(()=>showToast('Path copied!','success'));
    else fallbackCopy(path);
}

// ══ GLOBAL SEARCH ══
let gsearchTimer = null;
function showGlobalSearch(){
    document.getElementById('globalSearchInput').value = '';
    document.getElementById('globalSearchResults').innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-muted);">Type to search across all folders…</div>';
    openModal('globalSearchModal');
    setTimeout(()=>document.getElementById('globalSearchInput').focus(), 120);
}
function onGlobalSearchInput(){
    clearTimeout(gsearchTimer);
    const q = document.getElementById('globalSearchInput').value.trim();
    if(q.length < 2){ document.getElementById('globalSearchResults').innerHTML='<div style="padding:24px;text-align:center;color:var(--text-muted);">Type at least 2 characters…</div>'; return; }
    gsearchTimer = setTimeout(doGlobalSearch, 350);
}
function doGlobalSearch(){
    const q = document.getElementById('globalSearchInput').value.trim();
    if(q.length < 2) return;
    const el = document.getElementById('globalSearchResults');
    const btn = document.getElementById('globalSearchBtn');
    el.innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-muted);"><i class="fas fa-spinner fa-spin fa-lg"></i></div>';
    btn.disabled = true;
    fetch('ajax_search.php?q='+encodeURIComponent(q))
        .then(r=>r.json()).then(r=>{
            btn.disabled = false;
            if(!r.success){ el.innerHTML=`<div style="padding:20px;text-align:center;color:#e07070;">${escapeHtml(r.message||'Error')}</div>`; return; }
            if(!r.results.length){ el.innerHTML=`<div style="padding:24px;text-align:center;color:var(--text-muted);">No results for "<strong style="color:var(--text);">${escapeHtml(q)}</strong>"</div>`; return; }
            el.innerHTML = r.results.map(f=>{
                const icon = f.is_dir ? '📁' : getFileIconEmoji(f.ext);
                const destFolder = f.is_dir ? (f.folder?(f.folder+'/'+f.name):f.name) : f.folder;
                const href = 'dashboard.php' + (destFolder ? '?folder='+encodeURIComponent(destFolder) : '');
                const sizeStr = f.size > 0 ? formatBytes(f.size) : (f.is_dir ? 'Folder' : '');
                return `<div class="gsearch-result" onclick="closeModal('globalSearchModal');window.location='${href}'">
                    <span style="font-size:20px;flex-shrink:0;">${icon}</span>
                    <div style="min-width:0;flex:1;">
                        <div class="gsearch-result-name">${escapeHtml(f.name)}</div>
                        <div class="gsearch-result-path">${escapeHtml(f.folder||'root')}</div>
                    </div>
                    <span style="font-size:11px;color:var(--text-muted);white-space:nowrap;flex-shrink:0;">${sizeStr}</span>
                </div>`;
            }).join('');
        }).catch(()=>{ btn.disabled=false; el.innerHTML='<div style="padding:20px;color:#e07070;">Search failed.</div>'; });
}
function getFileIconEmoji(ext){
    const m={jpg:'🖼️',jpeg:'🖼️',png:'🖼️',gif:'🖼️',webp:'🖼️',svg:'🖼️',pdf:'📕',doc:'📄',docx:'📄',txt:'📝',md:'📝',zip:'📦',rar:'📦',mp3:'🎵',wav:'🎵',mp4:'🎬',mkv:'🎬',json:'📋',csv:'📊',xls:'📊',xlsx:'📊'};
    return m[ext]||'📄';
}

// ══ KEYBOARD: Ctrl+F = global search ══
document.addEventListener('keydown', e=>{
    if((e.ctrlKey||e.metaKey) && e.key==='f' && !document.querySelector('.modal-overlay.open')){
        e.preventDefault(); showGlobalSearch();
    }
});

</script>
</body>
</html>
