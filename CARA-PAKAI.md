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
| Portal wali (lokal) | http://localhost/pwa_nailulmuna/wali/login.php |
| Portal wali (live) | https://wali.nailulmuna.id/wali/login.php |
| Login staf (live) | https://pwa.nailulmuna.id/login.php |

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

## Cron snapshot laporan ke Google Sheet

Setiap hari (default jam **05:00** WIB), sistem menulis snapshot 7 laporan keuangan ke satu Google Spreadsheet (data tabular, bukan PDF).

**Setup Google Cloud (sekali):**

1. [Google Cloud Console](https://console.cloud.google.com/) → buat project → aktifkan **Google Sheets API** + **Google Drive API**
2. Buat **Service Account** → unduh JSON key
3. Salin ke `config/google_service_account.json` (lihat `config/google_service_account.json.example`)
4. Opsional: buat spreadsheet kosong → share **Editor** ke email service account (`...@...iam.gserviceaccount.com`)

**Di aplikasi:**

1. **Pengaturan → Snapshot Laporan Google Sheet**
2. Isi email penerima (Viewer), jam snapshot, path JSON
3. Centang **Aktifkan snapshot harian otomatis** → Simpan (cron otomatis pakai data **as_of = kemarin**)
4. **Kirim snapshot sekarang** — pilih tanggal **as_of** (default kemarin) lalu kirim; berguna untuk backfill tanggal tertentu tanpa menunggu cron
5. Cek spreadsheet: tab `Neraca_Pondok`, `Rekap_Kas_Bulanan`, `Tunggakan_Syahriyah`, `Syahriyah_12Bulan`, `Payroll_Pembimbing`, `BOS_BKU`, `BOS_LRA` (7 tab tetap, di-overwrite setiap kirim)

**Tombol kirim tidak jalan?** Cek badge **Kredensial SA** di kartu Status (bukan badge **Aktif**). Setelah ubah path JSON, klik **Simpan** dulu. Tombol tetap bisa diklik; jika kredensial belum siap, pesan error muncul di atas form.

**Error `Google API error: The caller does not have permission`:** Service Account sudah dapat token, tetapi Google menolak akses ke Sheet/Drive. Share spreadsheet PNM10 sebagai **Editor** ke email SA (`...@...iam.gserviceaccount.com`), aktifkan **Google Sheets API** + **Google Drive API** di project Cloud Console yang sama dengan file JSON. Gunakan **Tes akses Google** di halaman pengaturan sebelum kirim penuh. Jika kirim data sukses tetapi invite penerima gagal, kosongkan field email penerima dan bagikan Viewer manual dari Google Drive.

**Jadwalkan cron (Windows/XAMPP):**

1. Klik kanan **`setup-cron-laporan-snapshot.bat`** → **Run as administrator**
2. Task `PWA_NailulMuna_Laporan_Snapshot` memanggil `php cron/laporan_snapshot.php` setiap 1 menit (guard: hanya sekali sehari setelah jam setting)

**Uji manual:**

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\pwa_nailulmuna\cron\laporan_snapshot.php
```

**Hosting:** `* * * * * curl -s "https://domain/cron/laporan_snapshot.php?key=..."` (kunci di halaman pengaturan snapshot).

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

---

## Input poin (form pengurus)

Menu **Poin → Input**. Alur singkat:

1. **Pilih santri** — panel presensi menampilkan ringkasan alpa/telat periode (bulan/minggu, sesuai Pengaturan Peraturan). Kejadian yang belum masuk ledger bisa **Tarik ke poin** (bobot per alpa/telat di setting yang sama). Auto-sync background **mati** secara default; jangan centang legacy kecuali pondok sengaja ingin perilaku lama.
2. **Cari pelanggaran** — ketik min. 2 huruf (nama, kode, contoh pelanggaran). Rule **Ringan (1 poin / D. Ringan)** tidak bisa pakai peringan/pemberat.
3. **Peringan atau pemberat** — hanya untuk rule **Sedang ke atas** (bobot ≥ 3); efek **persen** terhadap poin dasar; maksimal satu jenis. Preview **Poin final** di form sebelum simpan.
4. **Master Peringan/Pemberat** — kelola di **Pengaturan → Peraturan** (bagian bawah halaman poin).

Uji cepat: cari kata kunci contoh pelanggaran → pilih rule berat → pemberat +50% → simpan → cek rekap poin santri; tarik presensi pending → maintenance jalan → tidak double (unique per `reference_presensi_id`).

---

## Penepian keaktifan vs izin resmi (PRESNA)

Santri **masih AKTIF** di data tetapi sementara **di luar pondok** dan **tidak** ikut slot presensi (netral di rekap keaktifan/PRESNA — **bukan** ALPA, **bukan** IZIN/SAKIT).

| Situasi | Pakai |
|--------|--------|
| Boyong / tidak kembali mondok | **NONAKTIF** di data santri |
| Libur pondok / tingkatan | **Libur akademik** |
| Keluar, sakit, syar'i wali, tugas resmi | **Izin resmi** (Perizinan) → tercatat IZIN/SAKIT + penalti PRESNA |
| Pulang sementara tanpa izin formal, tidak boleh kena ALPA | **Penepian keaktifan** (super admin / pengasuh) |

**Input (super admin & pengasuh):** **Perizinan → Penepian keaktifan** (tab hub, hanya role ini), **Edit santri → Catat penepian keaktifan**, atau **Kelola penepian** dari halaman daftar menepi pengasuh. Cukup **tanggal mulai** + alasan wajib — tidak ada tanggal selesai di form; penepian berlaku sampai **Selesai hari ini** (mencatat tanggal akhir) atau **Batalkan**. **Edit** hanya mengubah mulai/alasan selama masih aktif. **Pengurus dan petugas absensi tidak dapat mengubah** penepian. Data lama yang sudah punya rentang tetap dibaca sistem.

**Pembimbing:** hanya **melihat** daftar santri bimbingan yang menepi (dashboard pembimbing).

**Pengasuh:** lihat daftar di **Dashboard pengasuh** (kartu *Santri menepi*) dan **Laporan hari**; **mencatat/mengubah** penepian jika login sebagai pengasuh (role kiai) atau super admin.

**Scan kartu:** pesan *Sedang menepi keaktifan — presensi tidak dihitung*.

**Jangan** pakai izin keluar hanya untuk hindari ALPA jika tidak ingin penalti Izin×2. **Jangan** dobel: rentang yang sama jangan sekaligus izin resmi + penepian.

UAT otomatis (lokal):

```powershell
C:\xampp\php\php.exe scripts\_uat_penepian_keaktifan.php
```

---

## Multi Scan — deploy & UAT kamera

Setelah upload ke hosting, pastikan browser/PWA tidak memakai JS lama:

```powershell
C:\xampp\php\php.exe scripts\_diag_multi_scan_deploy.php
C:\xampp\php\php.exe scripts\_diag_multi_scan_deploy.php https://pwa.nailulmuna.id
C:\xampp\php\php.exe scripts\_uat_multi_scan_readiness.php
```

Di HP: buka **Multi Scan** → ketuk **Mulai scan kamera** → scan kartu (harus ada feedback). Asset scan memakai `?v=mtime` otomatis; halaman `login.php?scan=1` tidak di-cache browser.

**QR terbaca vs “ditolak”:** jika **tidak ada bip/getar** sama sekali → masalah kamera/decode (lihat diag deploy di atas). Jika ada **“Kartu terbaca…”** lalu peringatan → QR sudah terbaca; absensi mengikuti **tingkatan santri** dan jam (strip jadwal bisa menampilkan kegiatan tingkatan lain). Cek data:

```powershell
C:\xampp\php\php.exe scripts\_diag_scan_santri_jadwal.php KODE_QR_KARTU
```

Jam HP yang melenceng &gt;5 menit otomatis diganti waktu server saat validasi jadwal.

**Jadwal dobel (nama + tingkatan):** jika dua master kegiatan **nama sama** (ID beda) sama-sama punya jadwal **tingkatan sama**, absensi santri bisa ambigu. Cek banner kuning di **Jadwal** / **Kegiatan**, atau:

```powershell
C:\xampp\php\php.exe scripts\_diag_jadwal_duplicate.php
```

Penambahan slot jadwal yang memperparah duplikat ditolak otomatis.

---

## Performa di hosting (pwa.nailulmuna.id)

Setelah deploy DB lengkap (impor/migrasi), aktifkan **skip migrasi otomatis per login**:

- Di panel hosting: environment **`PONDOK_SCHEMA_READY=1`**, **atau**
- phpMyAdmin: `INSERT INTO app_settings (setting_key, setting_value) VALUES ('pondok_schema_deploy_ready','1') ON DUPLICATE KEY UPDATE setting_value='1';`

Diagnostik (CLI di server atau lokal):

```powershell
C:\xampp\php\php.exe scripts\_diag_hosting_perf.php
C:\xampp\php\php.exe scripts\_diag_perf_bootstrap.php
```

Operasional: jalankan **cron** `cron/wa_auto.php`; matikan **Fallback cron saat buka app** di WA Otomatis → Gateway jika cron sudah jalan. Pastikan **OPcache** PHP aktif di hosting.

### WA otomatis & Google Sheet tanpa buka aplikasi

Keduanya jalan lewat **cron hosting** (CLI atau URL HTTP + key), bukan lewat staf yang login.

| Fitur | Script cron | Cek di UI |
|--------|-------------|-----------|
| WA otomatis | `cron/wa_auto.php` (1–5 menit) | Pengaturan → WA Otomatis → **Ringkasan** — *Terakhir tick cron* harus **&lt;10 menit** |
| Snapshot Sheet | `cron/laporan_snapshot.php` (tiap menit; push sekali/hari setelah jam setting) | Pengaturan → **Snapshot Laporan Google Sheet** — badge cron + `last_date` / `last_error` |

Optimasi performa (cache `app_settings`, lazy FCM, dll.) **tidak** mematikan cron. Fallback WA saat browsing default **mati**; setiap hit cron HTTP/CLI mematikan fallback otomatis agar tidak dobel.

Verifikasi CLI (server atau lokal ke DB yang sama):

```powershell
C:\xampp\php\php.exe scripts\_diag_cron_wa_sheet.php
C:\xampp\php\php.exe cron\wa_auto.php
C:\xampp\php\php.exe cron\laporan_snapshot.php
```

---

## Ganti munawib pembimbing (portal & pengasuh)

- **Pembimbing:** menu Perizinan → *Cari / ganti munawib* — pilih rentang tanggal (1 hari atau beberapa hari), kegiatan, munawib, materi per halaman, dan **alasan wajib** (tulis sendiri). Ajukan minimal **3 hari** sebelum jadwal terlaksana. Semua pengajuan menunggu **persetujuan pengasuh**; WA otomatis ke pengasuh hanya untuk pengajuan **lebih dari satu hari** (toggle yang sama dengan izin syar'i di WA Otomatis).
- **Pengasuh:** *Pengasuh → Perizinan* — bagian *Pengganti munawib pembimbing* → Setujui / Tolak. Setelah disetujui, penugasan munawib aktif di jadwal.
- **Ubah/batal** override yang sudah aktif: masih maks. **3 jam** sebelum jadwal asli.
