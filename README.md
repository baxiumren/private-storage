# 🗄️ Private Storage

A self-hosted personal file manager built with PHP. Designed for deployment on shared hosting (cPanel) with a modern glassmorphism UI.

![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=flat&logo=php&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green?style=flat)

---

## ✨ Features

### File Management
- Upload files with drag & drop support
- Create, rename, delete folders and files
- Move & copy files between folders
- Duplicate files
- Inline text file editor (`.txt`, `.md`, `.json`, `.xml`, etc.)
- Paste upload (Ctrl+V image directly)

### Views & Navigation
- Table view and Gallery/Grid view with image thumbnails
- Breadcrumb navigation with back button
- Sort by name, size, date, type (folders always on top)
- Items per page selector (10 / 20 / 50 / 100)
- Type filter bar (All / Images / Video / Audio / Docs / Archives / Folders)
- Global search across all folders
- Recent files section

### Bulk Operations
- Select multiple files with checkboxes (Shift+click range select)
- Bulk delete, move, download as ZIP
- Batch rename with pattern (e.g. `photo_001`, `photo_002`, ...)

### Preview
- Lightbox image viewer with prev/next navigation
- Fullscreen image mode
- PDF inline preview
- Image dimensions display
- QR code for share links

### Sharing
- Generate time-limited share links (1h – 720h)
- QR code generation for share links
- Manage & delete active share links

### Recycle Bin
- Soft delete — files go to trash before permanent deletion
- Restore files from trash
- Empty trash

### Folder Customization
- Set custom color per folder
- Set custom emoji icon per folder

### Security
- Session-based authentication with IP + User-Agent hijack detection
- Server-side session timeout (5 hours)
- CSRF protection on all POST requests
- IP-based login lockout (5 attempts → 15 min lockout)
- Path traversal prevention (`realpath()` validation)
- File type whitelist (extension + MIME check)
- PHP execution blocked inside `uploads/` via `.htaccess`
- `data/` directory blocked from web access

### Other
- Activity log viewer
- Storage usage indicator + stats modal
- Change password (from dashboard)
- Auto session extend (every 10 min)
- Right-click context menu
- Keyboard shortcuts (see below)
- Mobile responsive design
- Custom confirm dialogs (no browser popups)

---

## ⌨️ Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Ctrl+F` | Focus search |
| `Ctrl+A` | Select all files |
| `Delete` | Delete selected files |
| `Esc` | Close modal / deselect |

---

## 🚀 Installation

### Requirements
- PHP 7.4+
- Apache with `mod_rewrite` enabled
- `ZipArchive` PHP extension (for ZIP download)

### Steps

1. **Clone the repo**
   ```bash
   git clone https://github.com/YOUR_USERNAME/private-storage.git
   ```

2. **Upload to your server**
   - Upload all files to your hosting (e.g. `public_html/storage/`)

3. **Set permissions** (cPanel/Linux)
   ```
   uploads/  → 755
   data/     → 750
   ```

4. **Login**
   - Open `yourdomain.com/storage/`
   - Default credentials:

   | Username | Password |
   |----------|----------|
   | `admin`  | `admin123` |

   > ⚠️ **Change your password immediately after first login** via the dashboard menu.

---

## 📁 Project Structure

```
storage-project/
├── index.php               # Login page
├── dashboard.php           # Main file manager UI
├── logout.php              # Session destroy + redirect
├── share.php               # Public share link handler
├── config.private.php      # Credentials, constants, helper functions
├── ajax_upload.php         # Handle file uploads
├── ajax_actions.php        # Rename, delete, copy
├── ajax_move.php           # Move files/folders
├── ajax_bulk.php           # Bulk delete/move
├── ajax_bulk_zip.php       # Bulk download as ZIP
├── ajax_zip.php            # Folder download as ZIP
├── ajax_create_folder.php  # Create new folder
├── ajax_trash.php          # Recycle bin operations
├── ajax_batch_rename.php   # Batch rename
├── ajax_share.php          # Share link create/list/delete
├── ajax_edit_file.php      # Text file editor
├── ajax_folder_meta.php    # Folder color/icon
├── ajax_change_password.php# Change password
├── ajax_log.php            # Activity log reader
├── ajax_search.php         # Global search
├── ajax_session.php        # Session extend
├── uploads/                # User files (excluded from git)
└── data/                   # App data: logs, trash meta, share links (excluded from git)
```

---

## 🎨 UI Preview

- **Design:** Maroon + Glassmorphism dark theme
- **Font:** Inter
- **Icons:** Font Awesome 6

---

## ⚙️ Configuration

All settings are in `config.private.php`:

```php
define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100MB per file

define('ALLOWED_EXTENSIONS', [
    'jpg', 'jpeg', 'png', 'gif', 'webp',   // Images
    'pdf', 'doc', 'docx', 'txt', 'md',     // Documents
    'zip', 'rar', '7z',                     // Archives
    'mp4', 'mp3', 'wav',                    // Media
    // ... and more
]);

define('MAX_LOGIN_ATTEMPTS', 5);    // Lockout after 5 failed attempts
define('LOCKOUT_DURATION', 900);    // 15 minutes lockout
```

---

## 📝 License

MIT — free to use, modify, and distribute.
