<?php
require_once 'config.private.php';
secure_session_start();

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
    if ($file[0] === '.') continue; // skip juga dotfiles (.htaccess, .gitkeep, .trash)
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
            if ($name === '' || $name[0] === '.') continue; // skip dotfiles
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
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect x='6' y='6' width='88' height='88' rx='26' fill='%23055ff0'/><rect x='9' y='9' width='82' height='82' rx='23' fill='none' stroke='%23FFD700' stroke-width='5'/><circle cx='50' cy='50' r='19' fill='none' stroke='white' stroke-width='7'/><circle cx='50' cy='50' r='6' fill='%23FFD700'/><path d='M50 21v10M50 69v10M21 50h10M69 50h10' stroke='white' stroke-width='7' stroke-linecap='round'/></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/app.css">
<link rel="stylesheet" href="assets/css/cursor.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>
<div class="bg-orb orb-1"></div><div class="bg-orb orb-2"></div><div class="bg-orb orb-3"></div>
<div class="bg-grid"></div>
<div class="layout">

<!-- ══ NAVBAR ══ -->
<nav class="navbar">
    <div class="nav-logo">
        <div class="nav-logo-icon"><i class="fas fa-vault"></i></div>
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

    <!-- Hamburger (mobile only) -->
    <button class="btn-icon nav-burger" id="navBurger" onclick="toggleMobileMenu(event)" aria-label="Menu">
        <span class="burger-lines"><span></span><span></span><span></span></span>
        <?php if($trash_count > 0): ?><span class="trash-badge burger-badge"><?php echo $trash_count; ?></span><?php endif; ?>
    </button>
</nav>

<!-- ══ MOBILE MENU DRAWER ══ -->
<div class="mobile-menu" id="mobileMenu">
    <div class="mm-user">
        <div class="mm-avatar"><i class="fas fa-user"></i></div>
        <div>
            <div class="mm-username"><?php echo htmlspecialchars($_SESSION['username']);?></div>
            <div class="mm-ip"><?php echo htmlspecialchars($_SESSION['ip']);?></div>
        </div>
    </div>
    <div class="mm-sep"></div>
    <button class="mm-item" onclick="closeMobileMenu();showGlobalSearch()"><i class="fas fa-search"></i> Search All Folders</button>
    <button class="mm-item" onclick="closeMobileMenu();showTrashModal()"><i class="fas fa-trash-alt"></i> Recycle Bin
        <?php if($trash_count > 0): ?><span class="mm-badge"><?php echo $trash_count; ?></span><?php endif; ?>
    </button>
    <button class="mm-item" onclick="closeMobileMenu();showStatsModal()"><i class="fas fa-chart-bar"></i> Storage Stats</button>
    <button class="mm-item" onclick="closeMobileMenu();showLogModal()"><i class="fas fa-history"></i> Activity Log</button>
    <div class="mm-sep"></div>
    <button class="mm-item" onclick="closeMobileMenu();showChangePwModal()"><i class="fas fa-key"></i> Change Password</button>
    <button class="mm-item" onclick="closeMobileMenu();extendSession()"><i class="fas fa-clock"></i> Extend Session</button>
    <button class="mm-item" onclick="closeMobileMenu();toggleTheme()"><i class="fas fa-adjust"></i> Light / Dark Mode</button>
    <div class="mm-sep"></div>
    <button class="mm-item mm-danger" onclick="closeMobileMenu();customConfirm('Sign out of Private Storage?',()=>location.href='logout.php','Sign Out',false,'fa-sign-out-alt')"><i class="fas fa-sign-out-alt"></i> Logout</button>
</div>
<div class="mobile-menu-backdrop" id="mobileMenuBackdrop" onclick="closeMobileMenu()"></div>

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
                        <a href="api/zip.php?folder=<?php echo urlencode($current_folder);?>&sub_folder=<?php echo urlencode($item['name']);?>&csrf_token=<?php echo urlencode($csrf_token);?>"
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
                    <?php foreach(['#FFD700','#e05050','#52c788','#7cb5f5','#c050e0','#f59050','#50c8c0','#ff80ab','#ffffff',''] as $cl): ?>
                    <button onclick="selectColor('<?php echo $cl;?>')" style="width:28px;height:28px;border-radius:50%;background:<?php echo $cl?:'rgba(255,255,255,.1)';?>;border:2px solid rgba(255,255,255,.15);cursor:pointer;" title="<?php echo $cl?:'None';?>"></button>
                    <?php endforeach; ?>
                </div>
                <input type="color" id="fcColorInput" value="#FFD700" style="margin-top:4px;">
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
<form id="bulkZipForm" method="POST" action="api/bulk_zip.php" style="display:none;">
    <input type="hidden" name="csrf_token" id="bulkZipCsrf">
    <input type="hidden" name="current_folder" id="bulkZipFolder">
    <input type="hidden" name="items" id="bulkZipItems">
</form>

<!-- ══ SCRIPTS ══ -->
<script>
const CSRF_TOKEN    = '<?php echo $csrf_token; ?>';
const BASE_URL      = (()=>{ const p=window.location.pathname.split('/'); p.pop(); return window.location.origin+p.join('/'); })();
const ALL_FOLDERS   = <?php echo $all_folders_json; ?>;
const FOLDER_META   = <?php echo json_encode($folder_meta); ?>;
const STORAGE_USED  = <?php echo $storage_used; ?>;
const MAX_FILE_SIZE = <?php echo MAX_FILE_SIZE; ?>;
const ALLOWED_EXTS  = <?php echo json_encode(ALLOWED_EXTENSIONS); ?>;
let ITEMS_PER_PAGE  = 20;
</script>
<script src="assets/js/app.js"></script>
</body>
</html>
