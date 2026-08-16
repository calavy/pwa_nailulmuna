# Status PWA — Riwayat Perubahan

File ini mencatat setiap potong pekerjaan di proyek PWA Nailul Muna.
**Konvensi:** Baca file ini di awal setiap sesi kerja sebelum mengerjakan permintaan baru.

---

## Entri

### [2026-08-16] Pembuatan file pelacak riwayat kerja
- **Apa yang diubah:** File `STATUS_PWA.md` dibuat sebagai log riwayat perubahan proyek. Belum ada perubahan kode aplikasi.
- **File:** `STATUS_PWA.md`
- **Alasan/konteks:** Agar jejak pekerjaan tidak hilang antar sesi/chat. Rencana pekerjaan mendatang:
  - **Bagian 1 — Penyederhanaan login:** alur masuk multi-peran saat ini tersebar di `login.php`, `beranda.php`, `includes/partials/auth_portal_role_grid.php`, plus portal terpisah (`presensi/login.php`, `wali/login.php`, `santri_portal/login.php`).
  - **Bagian 2 — Pintasan Scan Kegiatan:** akses cepat ke presensi scan (`presensi/scan.php`), kemungkinan dari dashboard/menu (`helpers/dashboard_menu.php`, `includes/menu_data.php`).
  - **Bagian 3 — Menu per-role:** struktur menu berbeda per role (saat ini sudah ada pemisahan pembimbing di `includes/menu_data.php`; perlu diselaraskan untuk role lain).
- **Status:** selesai

### [2026-08-16] Penyederhanaan login — form tunggal (Bagian 1)
- **Apa yang diubah:** `login.php` disederhanakan jadi satu form (username/NIP + password). Backend otomatis mendeteksi peran via tabel `users` + cek `pembimbing`. Rate-limit login ditambah (5x gagal / 15 menit per IP atau akun). Login QR pembimbing/munawib dihapus. `beranda.php` redirect ke `login.php`.
- **File:** `login.php`, `beranda.php`, `helpers/login_rate_limit.php` (baru), `includes/partials/auth_portal_alt_links.php` (baru), `helpers/login_pembimbing.php`, `includes/auth_portal_layout.php`, `includes/partials/auth_portal_role_grid.php`, `presensi/login.php`, `presensi/scan.php`, `pembimbing/setoran*.php`, `pembimbing/munawib_portal.php`, `pembimbing/partials/setoran_portal_bootstrap.php`, `scripts/_audit_http_login.php`
- **Alasan/konteks:** Bagian 1 dari rencana PWA — hilangkan langkah pilih peran, pesan error generik anti-enumeration, wali & presensi tetap jalur terpisah.
- **Status:** belum diuji

### [2026-08-16] Pintasan Scan Kegiatan di login (Bagian 2)
- **Apa yang diubah:** Tombol kecil "Scan Kegiatan" di bawah form login. Layar `login.php?scan=1` buka kamera inline; 3 pill mode (Presensi default, Portal, Setoran) muncul setelah kamera siap. Presensi POST ke `presensi/scan.php?portal=1`; portal/setoran via handler QR (`helpers/login_qr_auth.php`). Duplikat "Scan presensi" di footer dihapus.
- **File:** `login.php`, `helpers/login_qr_auth.php` (baru), `includes/partials/login_scan_kegiatan.php` (baru), `assets/js/login-scan-kegiatan.js` (baru), `assets/css/auth-portal.css`, `includes/partials/auth_portal_alt_links.php`, `presensi/scan.php`
- **Alasan/konteks:** Musyawir sering melewatkan scan presensi karena jalur QR tersembunyi setelah Bagian 1. Satu pintu masuk scan yang jelas tanpa ubah schema database.
- **Status:** belum diuji

### [2026-08-16] Sidebar mega-menu per kategori & ACL (Bagian 3)
- **Apa yang diubah:** Sidebar direorganisasi jadi 5 kategori mega-menu (Santri, Ketertiban, Keuangan, Akademik, Yayasan) dengan collapse/expand di desktop & mobile. ACL filter sudah ada sebelumnya (`filter_menu_items_by_acl`) — grup kosong otomatis disembunyikan. Alias ID grup lama di `menu_hub.php` agar breadcrumb lama tetap jalan. Desktop sidebar beralih dari mode hub ke accordion.
- **File:** `includes/menu_data.php`, `includes/header.php`, `helpers/app.php`, `menu/menu_hub.php`, `assets/css/app.css`, `scripts/_test_menu_bagian3.php` (smoke test)
- **Alasan/konteks:** Menu terasa penuh (~9 grup top-level). Penyaringan per user sudah jalan jika admin mengatur hak akses di Kelola Akses User; pengurus tanpa ACL eksplisit masih mendapat izin default luas (semua modul kecuali kelola user).
- **Status:** smoke test lulus; belum diuji browser manual

### [2026-08-16] Offline presensi scan & input poin (antrian IndexedDB)
- **Apa yang diubah:** Modul presensi scan dan poin input mendukung input tertunda saat offline: antrian IndexedDB (`pondok-offline-v1` v3), auto-sync saat online + retry berkala, badge antrian, banner status offline, `client_uuid` idempotensi, tabel `offline_sync_log`, aturan konflik presensi (timestamp lebih awal menang). Login Scan Kegiatan (`login.php?scan=1`) terhubung antrian offline. API baru: `api/offline/poin_submit.php`, `api/offline/reference_pack.php`. SW allowlist: `poin/input.php`, `login.php?scan=1`.
- **File:** `assets/js/offline-sync.js`, `assets/js/login-scan-kegiatan.js`, `helpers/offline_sync_dedup.php`, `helpers/poin_offline.php`, `presensi/scan.php`, `poin/input.php`, `api/offline/poin_submit.php`, `api/offline/reference_pack.php`, `helpers/pwa_offline.php`, `includes/partials/offline_status_bar.php`, `includes/partials/login_scan_kegiatan.php`, `assets/css/offline-sync.css`, `migrasi_2026_08_offline_sync_log.sql`, `scripts/_test_offline_presensi_poin.php`
- **Alasan/konteks:** Internet pondok sering mati; presensi & poin adalah modul paling kritis. Modul lain tetap online-only.
- **Status:** smoke test lulus; belum diuji browser manual (2 device, offline/online)
