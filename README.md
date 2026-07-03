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
private-storage/
├── index.php               # Login page
├── dashboard.php           # Main file manager UI
├── logout.php              # Session destroy + redirect
├── share.php               # Public share link handler
├── config.private.php      # Credentials, constants, helper functions
├── assets/
│   ├── css/
│   │   ├── app.css         # Dashboard theme (dark + light mode)
│   │   └── login.css       # Login page theme
│   └── js/
│       └── app.js          # All dashboard logic (upload, modals, search, ...)
├── api/                    # JSON endpoints (all CSRF + session protected)
│   ├── upload.php          # File upload (1 file per request — stabil)
│   ├── actions.php         # Rename, delete, copy
│   ├── move.php            # Move files/folders
│   ├── bulk.php            # Bulk delete/move
│   ├── bulk_zip.php        # Bulk download as ZIP
│   ├── zip.php             # Folder download as ZIP
│   ├── create_folder.php   # Create new folder
│   ├── trash.php           # Recycle bin operations
│   ├── batch_rename.php    # Batch rename
│   ├── share.php           # Share link create/list/delete
│   ├── edit_file.php       # Text file editor
│   ├── folder_meta.php     # Folder color/icon
│   ├── change_password.php # Change password
│   ├── log.php             # Activity log reader
│   ├── search.php          # Global search
│   └── session.php         # Session extend
├── uploads/                # User files (excluded from git, PHP execution blocked)
└── data/                   # App data: logs, trash meta, share links (blocked from web)
```

---

## 🎨 UI Preview

- **Design:** Midnight Vault — deep navy + royal blue + gold accents, dark & light mode
- **Palette:** `#055ff0` (blue) · `#FFD700` (gold) · `#020b25` (background)
- **Fonts:** Fraunces (display) + Instrument Sans (body)
- **Icons:** Font Awesome 6

## 📤 Upload Behavior

Files are uploaded **sequentially, one file per HTTP request**:
- Stable for 1, 2, or many files — request size never exceeds `post_max_size`
- Per-file progress bar + overall progress
- Automatic 1× retry on network failure
- Client-side validation (extension + size) before anything is sent
- Server limit: 100MB per file (`MAX_FILE_SIZE` + `.htaccess` php values)

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
