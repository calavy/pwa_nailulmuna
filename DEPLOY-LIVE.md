# Deploy ke live server (tanpa merusak data)

Panduan ini untuk memperbarui **kode PHP** dari GitHub ke hosting (mis. `pwa.nailulmuna.id`) sambil **mempertahankan database & upload** yang sudah ada.

## Yang AMAN vs BERBAHAYA

| Aksi | Aman di live? |
|------|----------------|
| `git pull` / upload file PHP, CSS, JS baru | ✅ Ya |
| Menyimpan `config/database.local.php` di server (tidak ditimpa) | ✅ Ya |
| Menjalankan **hanya** perintah SQL baru di `migrasi_terbaru.sql` (bagian belum pernah dijalankan) | ✅ Ya, setelah backup |
| Impor ulang `impor_lengkap_pwa_nailulmuna.sql` | ❌ **HAPUS semua data** |
| Menghapus folder `uploads/` di server | ❌ Logo & berkas hilang |

---

## Sebelum deploy (wajib)

1. **Backup database** di phpMyAdmin hosting → Export → SQL.
2. **Catat** isi `config/database.local.php` di server (jangan di-commit ke GitHub).
3. **Catat** folder `uploads/` — jangan dihapus saat upload file.

---

## Langkah 1 — Push dari komputer ke GitHub

Di folder proyek (PowerShell):

```powershell
cd C:\xampp\htdocs\pwa_nailulmuna
git add .
git commit -m "Pesan perubahan Anda"
git pull origin main
git push origin main
```

Atau double-click **`upload-github.bat`** dan isi pesan commit.

File yang **tidak** ikut GitHub (tetap hanya di PC/server):

- `config/database.local.php`
- `config/app.local.php`
- `config/firebase.local.php` (jika ada)

---

## Langkah 2 — Update file di live server

### Opsi A: Git di hosting (jika tersedia SSH/Git)

```bash
cd /path/ke/pwa_nailulmuna
git pull origin main
```

Jangan timpa `config/database.local.php`.

### Opsi B: Upload manual (FTP / File Manager)

1. Download ZIP dari GitHub: https://github.com/calavy/pwa_nailulmuna → Code → Download ZIP  
   Atau upload folder hasil `git pull` lokal.
2. Extract ke folder situs (root subdomain).
3. **Jangan replace** file ini jika sudah ada dan benar:
   - `config/database.local.php`
   - `config/app.local.php`
   - `config/firebase.local.php`
   - Seluruh folder **`uploads/`**

---

## Langkah 3 — Migrasi database (incremental)

1. Login phpMyAdmin **hosting**.
2. Pilih database pondok (bukan database kosong baru).
3. Buka tab **SQL**.
4. Jalankan **hanya** blok SQL di `migrasi_terbaru.sql` yang **belum** pernah dijalankan di server.  
   File ini memakai `CREATE TABLE IF NOT EXISTS` dan `ADD COLUMN IF NOT EXISTS` — aman dijalankan ulang untuk banyak bagian.

Contoh bagian terbaru (Yayasan + kalender):

- Tabel `yayasan_pengurus`, `yayasan_rapat`, `yayasan_notulen`
- Kolom `kalender_hijriyah` pada pembayaran
- `UPDATE app_settings` untuk kalender Hijriyah (hanya mengubah jika masih `MASEHI`)

5. Jika ada error "sudah ada" / duplicate — biasanya boleh diabaikan untuk baris itu.

**Jangan** impor ulang `impor_lengkap_pwa_nailulmuna.sql` di live.

---

## Langkah 4 — Setelah deploy (cek aplikasi)

1. Buka `https://pwa.nailulmuna.id/login.php` (sesuaikan domain Anda).
2. Login admin → cek menu **Yayasan**, **Keuangan**, **Kalender**.
3. **Pengaturan pondok** → Kalender tagihan = **Hijriyah** (jika belum).
4. **Settings → Kalender** → *Sesuaikan data lama ke Hijriyah* (sekali saja, jika bulan tagihan masih Masehi).
5. **Kelola akses user** → centang izin **Yayasan** untuk pengurus yang perlu.

---

## Troubleshooting

| Gejala | Solusi |
|--------|--------|
| Blank / error koneksi DB | Periksa `config/database.local.php` (host, user, pass dari panel hosting) |
| Menu hilang untuk pengurus | Settings → Admin → centang izin modul yang diperlukan |
| Bulan masih «Mei» bukan Muharram | Aktifkan kalender Hijriyah + backfill (langkah 4) |
| Logo hilang | Restore folder `uploads/` dari backup |

---

## Ringkasan satu baris

**Pull/upload kode → backup DB → jalankan SQL baru di `migrasi_terbaru.sql` → jangan impor ulang database lengkap → jangan hapus `uploads/` dan `database.local.php`.**
