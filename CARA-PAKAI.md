# Cara pakai — preview lokal & GitHub

## Preview lokal (paling mudah)

1. Nyalakan **XAMPP** → Apache + MySQL **Start**
2. Double-click file **`mulai-local.bat`** di folder proyek  
   *(atau buka browser: http://localhost/pwa_nailulmuna/cek-server.php)*
3. Login: http://localhost/pwa_nailulmuna/login.php  
   - User: `admin` / Pass: `admin123` (default impor DB)

**Database pertama kali:** phpMyAdmin → impor `impor_lengkap_pwa_nailulmuna.sql` lalu `migrasi_terbaru.sql`

Path URL **otomatis** menyesuaikan folder XAMPP — tidak perlu ubah kode saat preview.

**Navigasi:** dari Dashboard bisa klik Keuangan, Santri, dll. — semua link internal otomatis pakai prefix `/pwa_nailulmuna/`.

---

## Upload ke GitHub

1. Edit & tes di lokal sampai OK
2. Double-click **`upload-github.bat`**
3. Ketik pesan commit (contoh: `Perbaikan riwayat pembayaran`)
4. Selesai — cek: https://github.com/calavy/pwa_nailulmuna

Atau manual di PowerShell:

```powershell
cd C:\xampp\htdocs\pwa_nailulmuna
git add .
git commit -m "Pesan perubahan Anda"
git pull origin main
git push origin main
```

**Tidak ikut GitHub (tetap di PC saja):** `config/app.local.php`, `config/database.local.php`

---

## Hosting online (live server)

**Panduan lengkap & aman (tanpa hapus data):** baca **[DEPLOY-LIVE.md](DEPLOY-LIVE.md)**

Ringkas:

1. Push ke GitHub (`upload-github.bat` atau `git push`)
2. Upload / `git pull` ke server — **jangan timpa** `config/database.local.php` dan folder **`uploads/`**
3. Backup database → jalankan SQL **baru** dari `migrasi_terbaru.sql` saja (bukan impor ulang `impor_lengkap_...sql`)
4. Buka https://pwa.nailulmuna.id/login.php

Instalasi **pertama kali** saja: impor `impor_lengkap_pwa_nailulmuna.sql` + `migrasi_terbaru.sql`, lalu buat `database.local.php`.

---

## URL penting (lokal)

| Halaman | URL |
|---------|-----|
| Cek server | http://localhost/pwa_nailulmuna/cek-server.php |
| Login | http://localhost/pwa_nailulmuna/login.php |
| Portal wali | http://localhost/pwa_nailulmuna/wali/login.php |

**Jangan** buka URL dengan `...` di akhir — itu hanya contoh, bukan link asli.

---

## Preview dari HP (ngrok)

1. Nyalakan **Apache** di XAMPP.
2. Jalankan ngrok ke port 80, misalnya: `ngrok http 80`
3. Di HP buka **salah satu** URL ini (ganti `SUBDOMAIN` dengan domain ngrok Anda):
   - `https://SUBDOMAIN.ngrok-free.dev/pwa_nailulmuna/login.php` ← disarankan
   - atau cukup `https://SUBDOMAIN.ngrok-free.dev/` — akan dialihkan otomatis ke beranda aplikasi

**Penting:** Jangan mengandalkan path `/dashboard/` — itu bukan bagian aplikasi ini.

Jika pernah **pasang PWA** dari URL lama/salah: hapus pintasan di layar utama, buka ulang link di atas, lalu pasang lagi.

Opsional (ikon/logo PWA konsisten di ngrok): salin `config/app.local.example.php` → `app.local.php`, isi `public_url` dengan URL ngrok lengkap + `/pwa_nailulmuna`.

Logo & PWA di layar utama: **Pengaturan → Identitas pesantren** → unggah logo → atur warna tema/latar PWA → **Simpan**. Hapus pintasan PWA lama di HP, pasang ulang dari browser agar ikon & splash ikut logo baru.
