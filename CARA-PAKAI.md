# Cara pakai — preview lokal & GitHub

## Preview lokal (paling mudah)

1. Nyalakan **XAMPP** → Apache + MySQL **Start**
2. Double-click file **`mulai-local.bat`** di folder proyek  
   *(atau buka browser: http://localhost/pwa_nailulmuna/cek-server.php)*
3. Login: http://localhost/pwa_nailulmuna/login.php  
   - User: `admin` / Pass: `admin123` (default impor DB)

**Database pertama kali:** phpMyAdmin → impor **`impor_lokal_pwa_nailulmuna.sql`** (cukup sekali; sudah termasuk semua migrasi).  
Alternatif hosting/lama: `impor_lengkap_pwa_nailulmuna.sql` lalu `migrasi_terbaru.sql`.

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

Instalasi **pertama kali** saja: impor **`impor_lokal_pwa_nailulmuna.sql`** (lokal) atau `impor_lengkap_pwa_nailulmuna.sql` + `migrasi_terbaru.sql` (hosting), lalu buat `database.local.php` jika belum ada.

---

## URL penting (lokal)

| Halaman | URL |
|---------|-----|
| Cek server | http://localhost/pwa_nailulmuna/cek-server.php |
| Login | http://localhost/pwa_nailulmuna/login.php |

**Panduan keuangan lengkap:** lihat [docs/PANDUAN-KEUANGAN.md](docs/PANDUAN-KEUANGAN.md) (setup, tagihan, pembayaran, cashless, laporan, troubleshooting).

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

---

## Cron WA otomatis (XAMPP / Windows)

Agar pengingat tagihan syahriyah & job WA lain jalan tepat waktu (tanpa harus buka aplikasi):

1. Pastikan **Apache + MySQL** XAMPP sudah jalan.
2. Klik kanan **`setup-cron-wa.bat`** → **Run as administrator**.
3. Task Scheduler `PWA_NailulMuna_WA_Auto` akan memanggil `php cron/wa_auto.php` **setiap 1 menit**.
4. Cek di **Pengaturan → WA Otomatis → Ringkasan**: kolom *Terakhir jalan* harus terupdate (~1 menit).

**Uji manual** (PowerShell):

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\pwa_nailulmuna\cron\wa_auto.php
```

**Hapus jadwal** (jika perlu):

```powershell
schtasks /Delete /TN "PWA_NailulMuna_WA_Auto" /F
```

**Hosting online:** jadwalkan URL `https://pwa.nailulmuna.id/cron/wa_auto.php?key=...` (kunci di Pengaturan → WA → Gateway) via cron panel hosting — minimal setiap menit.

---

## Pembayaran Saku → Saldo Cashless

Jika pembayaran pos **Saku** sudah dicatat tetapi saldo cashless santri tidak bertambah:

**Di aplikasi (setelah deploy):**

1. Buka **Keuangan → Perbaikan Kas**
2. Cek bagian *Pembayaran Saku tanpa top-up cashless*
3. Klik **Backfill top-up saku** (aman — tidak duplikat jika sudah pernah top-up)

**Di server (CLI):**

```powershell
cd C:\xampp\htdocs\pwa_nailulmuna
C:\xampp\php\php.exe scripts\verify_saku_cashless_audit.php
C:\xampp\php\php.exe scripts\backfill_saku_cashless_topup.php
C:\xampp\php\php.exe scripts\backfill_saku_cashless_topup.php --apply
C:\xampp\php\php.exe scripts\verify_saku_cashless_audit.php
```

Harapan setelah backfill: `Pembayaran saku tanpa TOPUP: 0`. Input pembayaran Saku baru harus langsung menambah saldo.

---

## Saldo akhir vs uang nyata

| Yang Anda lihat | Arti |
|-----------------|------|
| **Kas fisik + Rekening** (dashboard atas) | Uang nyata di laci & bank — ini yang harus cocok dengan hitungan fisik |
| **Saldo akhir (uang nyata)** (rekap / arus kas) | Jumlah saldo semua akun kas/bank aktif per tanggal |
| **Hitung buku** | Saldo awal TA + mutasi tercatat; bisa selisih jika ada transaksi tanpa akun |

**Diagnostik selisih (CLI):**

```powershell
cd C:\xampp\htdocs\pwa_nailulmuna
C:\xampp\php\php.exe scripts\_diag_rekap_kas_selisih.php
```

Jika selisih besar: **Keuangan → Perbaikan Kas** (transaksi tanpa akun, gaji tanpa pengeluaran, **nominal melebihi tagihan** pada data lama).

---

## Santri baru vs lama — tagihan & target

| Jenis | Bulanan | Awal tahun |
|-------|---------|------------|
| **Santri baru** | Mulai **bulan tanggal masuk** pada TA pertama (bulan sebelumnya = 0) | Tarif/komponen «baru» |
| **Santri lama** | Penuh bulan 1–12 | Tarif/komponen «lama» |

**Aktifkan di Keuangan → Pengaturan → Umum:**
1. Centang *Santri baru ditagih bulanan mulai bulan tanggal masuk*
2. Centang *Bedakan tarif awal tahun santri baru vs lama*
3. Pastikan setiap santri punya **tanggal masuk** di data santri

**Diagnostik (CLI):**

```powershell
cd C:\xampp\htdocs\pwa_nailulmuna
C:\xampp\php\php.exe scripts\_diag_tagihan_baru_lama.php
C:\xampp\php\php.exe scripts\_diag_tagihan_baru_lama.php 42
```

Baris `[OK]` untuk santri baru: expected bulan 1 = 0, expected bulan masuk > 0. Bagian akhir membandingkan target laporan 12 bulan vs rekap kas (harus `OK` per bulan).
