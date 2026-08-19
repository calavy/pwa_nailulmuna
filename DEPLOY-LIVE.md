# Deploy ke live server (tanpa merusak data)

Panduan ini untuk memperbarui **kode PHP** dari GitHub ke hosting (mis. `pwa.nailulmuna.id`) sambil **mempertahankan database & upload** yang sudah ada.

## Dua profil database

| Lingkungan | File config (di PC) | File config (di server live) | File impor SQL | Database / user |
|------------|---------------------|------------------------------|----------------|-----------------|
| **XAMPP lokal** | `config/database.local.php` | — | `impor_lokal_pwa_nailulmuna.sql` | `pwa_nailulmuna` / `root` |
| **Hosting live** | `config/database.local.hosting.php` | salin → `config/database.local.php` | `impor_lengkap_pwa_nailulmuna.sql` (hanya DB kosong) | `u700125577_pwanailulmuna` / `u700125577_pwanailulmuna` |

Di PC: **jangan timpa** `database.local.php` lokal saat mengedit profil hosting — kredensial hosting hanya di `database.local.hosting.php` (gitignored). Password hosting ada di file itu; isi juga **MySQL Host Name** dari panel (mis. `sql313.infinityfree.com`).

Alternatif tanpa salin file di server: set env `PONDOK_DB_PROFILE=hosting` agar [`config/database.php`](config/database.php) memuat `database.local.hosting.php`.

Template hosting: `config/database.local.hosting.example.php` → salin jadi `database.local.hosting.php`.

---

## Yang AMAN vs BERBAHAYA

| Aksi | Aman di live? |
|------|----------------|
| `git pull` / upload file PHP, CSS, JS baru | ✅ Ya |
| `git pull` memperbarui `cron/wa_auto.php` (kode cron WA) | ✅ Ya |
| Menyimpan `config/database.local.php` di server (tidak ditimpa) | ✅ Ya |
| Menjalankan **hanya** perintah SQL baru di `migrasi_terbaru.sql` (bagian belum pernah dijalankan) | ✅ Ya, setelah backup |
| Impor ulang `impor_lengkap_pwa_nailulmuna.sql` | ❌ **HAPUS semua data** |
| Menghapus folder `uploads/` di server | ❌ Logo & berkas hilang |
| Menghapus/mengubah jadwal cron di panel hosting | ❌ WA otomatis (tagihan, pengingat) berhenti |

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
- `config/database.local.hosting.php`
- `config/app.local.php`
- `config/firebase.local.php` (jika ada)

**Aman untuk production (domain root, tanpa `/pwa_nailulmuna/`):**

- `config/app.php` memakai `base_path` null → deteksi otomatis folder kosong di hosting.
- Jangan commit `app.local.php` yang berisi `base_path` `/pwa_nailulmuna` atau `public_url` localhost — file itu hanya untuk XAMPP lokal.
- Setelah deploy, buka **Pengaturan → Identitas pesantren** → simpan ulang logo/warna PWA agar ikon install terbarui di HP.

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

## Portal wali di `wali.nailulmuna.id`

Staf tetap di `https://pwa.nailulmuna.id`. Portal wali memakai subdomain **sejajar**: `https://wali.nailulmuna.id` (satu aplikasi, document root sama).

### Server (DNS / SSL / vhost)

1. DNS: buat `A` atau `CNAME` `wali.nailulmuna.id` ke server yang sama dengan `pwa.nailulmuna.id`.
2. SSL: sertifikat untuk `wali.nailulmuna.id` (atau wildcard `*.nailulmuna.id`).
3. Vhost: **document root identik** dengan `pwa.nailulmuna.id` (bukan folder `wali/` saja).
4. Di `config/app.local.php` di server (jangan di-commit):

```php
'public_url' => 'https://pwa.nailulmuna.id',
'wali_public_url' => 'https://wali.nailulmuna.id',
```

### Cek setelah DNS hidup

- `https://wali.nailulmuna.id/` → login/beranda portal wali
- `https://wali.nailulmuna.id/wali/login.php` → login NIS + PIN
- `https://pwa.nailulmuna.id/wali/login.php` → dialihkan ke host wali
- `https://wali.nailulmuna.id/dashboard.php` → dialihkan ke portal wali (bukan dashboard staf)
- Cron WA tetap `https://pwa.nailulmuna.id/cron/wa_auto.php?...`
- Webhook Midtrans tetap di host `pwa`; setelah bayar, browser wali kembali ke `wali.nailulmuna.id`

---

## Langkah 5 — Verifikasi cron WA otomatis

Deploy memperbarui **kode** cron (`cron/wa_auto.php` + helper), tetapi **jadwal** di panel hosting dan **setting** di database (kunci cron, jam kirim) tidak berubah otomatis.

### A. Deploy pertama kali (belum pernah setup cron di hosting)

1. Login admin → **Pengaturan → WA Otomatis → Gateway** → isi **Kunci cron** (opsional tapi disarankan) → Simpan.
2. Salin URL cron dari tab **Ringkasan** (format: `https://pwa.nailulmuna.id/cron/wa_auto.php?key=...`).
3. Di panel hosting (cPanel / InfinityFree / sejenisnya), buat cron job:
   - **Frekuensi:** setiap 1 menit (`* * * * *`)
   - **Perintah:** `curl -s "URL_CRON"` (atau perintah `wget` setara)
4. Klik **Tes cron** di tab Ringkasan → respons harus `OK wa_auto ...`.
5. Tunggu 1–2 menit → badge **Cron aktif** (hijau) dan *Terakhir jalan* terupdate.
6. Nonaktifkan **Fallback cron saat staf buka app** di tab Gateway (navigasi lebih ringan).

### B. Deploy rutin (cron sudah pernah jalan)

1. Setelah `git pull` / upload, buka tab **Ringkasan** → pastikan *Terakhir jalan* masih terupdate (< 10 menit).
2. Klik **Tes cron** sekali — memastikan kode `cron/wa_auto.php` terbaru jalan.
3. Jika hosting pakai OPcache dan respons aneh, jalankan **Admin → Cek update → Bersihkan cache** (centang OPcache).

**Catatan XAMPP lokal:** deploy live tidak menyentuh Task Scheduler PC. Untuk lokal, tetap pakai [`setup-cron-wa.bat`](setup-cron-wa.bat) (sekali, Run as Administrator). Detail: [CARA-PAKAI.md](CARA-PAKAI.md#cron-wa-otomatis-xampp--windows).

---

## Troubleshooting

| Gejala | Solusi |
|--------|--------|
| Blank / error koneksi DB | Periksa `config/database.local.php` (host, user, pass dari panel hosting) |
| Menu hilang untuk pengurus | Settings → Admin → centang izin modul yang diperlukan |
| Bulan masih «Mei» bukan Muharram | Aktifkan kalender Hijriyah + backfill (langkah 4) |
| Logo hilang | Restore folder `uploads/` dari backup |
| WA tagihan/pengingat tidak terkirim | Cek **Pengaturan → WA → Ringkasan** — badge merah = cron mati; periksa jadwal di panel hosting |
| Tes cron → `Forbidden` | Kunci cron di URL tidak cocok dengan setting Gateway |
| Tes cron OK tapi badge tetap merah | Hosting membatasi frekuensi cron; naikkan interval panel atau hubungi support hosting |

---

## Ringkasan satu baris

**Pull/upload kode → backup DB → jalankan SQL baru di `migrasi_terbaru.sql` → jangan impor ulang database lengkap → jangan hapus `uploads/` dan `database.local.php` → verifikasi cron WA di Pengaturan → Ringkasan.**
