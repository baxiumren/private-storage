# Custom Cursor — Mickey Glove

Cursor diatur di `assets/css/cursor.css`. Ada 2 kondisi:

| File | Dipakai saat |
|------|--------------|
| `hand.png` / `hand.svg` | normal (telunjuk) |
| `hand-fu.png` / `hand-fu.svg` | hover di tombol/link (jari tengah) |

## Mau ganti pakai gambar sendiri (misal dari pack Mickey Mouse)?

1. Siapkan PNG ukuran **32x32 px** (maksimal, lebih besar diabaikan browser).
2. Simpan di folder ini dengan nama persis:
   - `hand.png` — cursor normal
   - `hand-fu.png` — cursor hover
3. Selesai — PNG otomatis diprioritaskan, SVG bawaan jadi cadangan.

Kalau ujung "titik klik" gambarmu bukan di koordinat (16,2) dari kiri-atas,
sesuaikan angka hotspot di `assets/css/cursor.css` (angka setelah url).
