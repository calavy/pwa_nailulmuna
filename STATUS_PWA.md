# Status PWA — Riwayat Perubahan

File ini mencatat setiap potong pekerjaan di proyek PWA Nailul Muna.
**Konvensi:** Baca file ini di awal setiap sesi kerja sebelum mengerjakan permintaan baru.

---

## Entri

### [2026-08-17] Satu halaman login untuk wali
- **Apa yang diubah:** Wali masuk lewat `login.php` seperti pengguna lain. `/wali/login.php` dialihkan ke login utama. Logout dan sesi invalid portal wali kembali ke `/login.php`. Tautan "Portal wali" dihapus dari grid/footer.
- **File:** `wali/login.php`, `login.php`, `wali/logout.php`, `wali/inc_portal.php`, `helpers/auth_portal_links.php`, `includes/partials/auth_portal_role_grid.php`, `presensi/login.php`, `santri_portal/login.php`, `data/wali.php`, `santri/edit.php`, `CARA-PAKAI.md`, `STATUS_PWA.md`
- **Alasan/konteks:** Satu pintu masuk; dashboard wali tetap setelah login.
- **Status:** belum diuji browser manual

### [2026-08-17] Mode tampilan pondok (hanya Super Admin)
- **Apa yang diubah:** Mode terang/gelap disimpan di `app_settings.ui_theme_mode`. Hanya super admin (Kelola user) yang dapat mengubah; seluruh pengguna (pengurus, wali, santri, login, koperasi) mengikuti setting itu. localStorage bukan sumber kebenaran lagi.
- **File:** `helpers/app.php`, `settings/admin.php`, `settings/partials/pondok_theme_toggle.php`, `assets/js/theme-mode.js`, `includes/header.php`, `includes/auth_portal_layout.php`, `wali/includes/layout.php`, `santri_portal/includes/layout.php`, `includes/koperasi_portal_layout.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Toggle sebelumnya hanya mengubah perangkat super admin.
- **Status:** belum diuji browser manual

### [2026-08-17] Login utama: saran nama santri (typeahead)
- **Apa yang diubah:** Kolom identitas di `login.php` menampilkan saran nama + NIS setelah 2 huruf (maks 8 hasil, santri aktif). Pilih baris mengisi NIS lalu fokus ke password. API publik terbatas `api/login_santri_suggest.php` (rate-limit jika IP sudah diblokir login gagal). Tidak aktif untuk destinasi setoran.
- **File:** `helpers/wali_portal.php`, `api/login_santri_suggest.php`, `login.php`, `assets/js/login-santri-suggest.js`, `assets/css/auth-portal.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Wali masuk dari halaman login utama; butuh referensi nama tanpa buka `/wali/login.php`.
- **Status:** belum diuji browser manual

### [2026-08-17] Login utama: fallback portal wali (NIS/nama + PIN)
- **Apa yang diubah:** Jika username/password di `login.php` tidak cocok dengan akun pengurus, sistem coba NIS atau nama santri + PIN portal wali, lalu redirect ke `wali/index.php`. Placeholder form: *Username, NIP, NIS, atau nama santri*. Pesan gagal tetap generik. Destinasi setoran tidak memakai fallback ini.
- **File:** `login.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Wali mencoba masuk dari halaman login utama, bukan `/wali/login.php`.
- **Status:** belum diuji browser manual

### [2026-08-17] Portal wali: login NIS/nama + penyederhanaan pengaturan
- **Apa yang diubah:** Login portal wali menerima **NIS atau nama santri** + PIN (`wali_portal_find_santri_by_identity`, `wali_portal_verify_login`). Halaman Data → Wali santri: fokus tabel portal per santri (stat PIN sudah/belum); field **Akun pengguna** dihapus dari UI (tidak perlu hubungkan ke `users`). Pesan error login lebih jelas jika PIN belum diatur.
- **File:** `helpers/wali_portal.php`, `wali/login.php`, `data/wali.php`, `santri/edit.php`, `includes/partials/auth_portal_role_grid.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Portal wali tidak bisa diakses karena PIN belum diatur / admin bingung harus buat akun pengurus; cukup NIS atau nama santri dari data wali santri.
- **Status:** lint PHP; uji login manual disarankan

### [2026-08-17] Tier ALPA: nomor WA Putra dan Putri per ambang
- **Apa yang diubah:** Tabel *Tier penerima (ambang poin alpa)* punya dua kolom nomor: **WA Putra** dan **WA Putri**. Kolom `wa` lama dimigrasi ke keduanya. Kirim crossing/manual/generate ALPA selalu pecah per kelompok; kolom kosong memakai fallback ALPA Putra/Putri di atas tabel.
- **File:** `helpers/alpa_tier.php`, `helpers/alpa_wa.php`, `settings/includes/wa_otomatis_logic.php`, `settings/partials/wa_otomatis_tab_alpa.php`, `presensi/alpha.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Satu kolom Nomor WA di baris tier masih mengirim putra dan putri ke nomor yang sama.
- **Status:** belum diuji browser manual

### [2026-08-17] Penerima ALPA Putra dan Putri tersendiri
- **Apa yang diubah:** Tab Alpa hanya dua field nomor: **ALPA Putra** dan **ALPA Putri**. Field `wa_pengurus` dihapus dari form (tetap fallback diam di kode jika Putra kosong). Saat simpan, nomor Putra kosong disalin dari `wa_pengurus` lama. Buku nomor: peran `pengurus` = Pengurus (umum); ALPA memakai peran `alpa_putra` / `alpa_putri`.
- **File:** `settings/partials/wa_otomatis_tab_alpa.php`, `settings/includes/wa_otomatis_logic.php`, `helpers/wa_nomor.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Pengisian penerima ALPA harus terpisah putra/putri, bukan satu nomor pengurus.
- **Status:** belum diuji browser manual

### [2026-08-17] Cron hosting deploy — observability app-wide
- **Apa yang diubah:** `cron/wa_auto.php` memanggil `ensure_pondok_settings_defaults()` saat tick (edge case DB kosong). Health script `_test_wa_cron_health.php` parse `wa_auto_scheduled_last_result.jobs` (tagihan, cashless, poin, dll.). Tab Ringkasan: catatan deploy hosting, checklist pasca-deploy, ringkasan job terjadwal; tab Alpa: catatan deploy tidak mengubah jadwal panel.
- **File:** `cron/wa_auto.php`, `scripts/_test_wa_cron_health.php`, `settings/partials/wa_otomatis_tab_ringkasan.php`, `settings/partials/wa_otomatis_tab_alpa.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Hosting yang sudah berjalan — deploy hanya ganti kode; cron panel + setting DB persisten; diagnostik cron mencerminkan seluruh app.
- **Status:** belum diuji di hosting live

### [2026-08-17] WA otomatis ALPA Putra/Putri + diagnostik cron
- **Apa yang diubah:** Penerima notifikasi crossing ALPA dipisah putra/putri (`wa_alpa_pengurus_putra` / `wa_alpa_pengurus_putri`, peran `alpa_putra` / `alpa_putri`). Tier tanpa nomor WA → kirim 2 batch terpisah per kelompok; tier dengan nomor tetap satu batch. Tab Alpa: field Putra/Putri, panel status cron, preview manual per kelompok. Script `scripts/_test_wa_cron_health.php` untuk smoke test kesehatan cron.
- **File:** `helpers/app.php`, `helpers/wa_nomor.php`, `helpers/alpa_tier.php`, `helpers/alpa_wa.php`, `settings/includes/wa_otomatis_logic.php`, `settings/partials/wa_otomatis_tab_alpa.php`, `scripts/_test_wa_cron_health.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Pengurus putra/putri butuh notifikasi ALPA terpisah; perlu observability cron agar jadwal WA otomatis terpantau jelas.
- **Status:** smoke test placeholder lulus; health cron exit 1 jika cron belum aktif (normal di dev)

### [2026-08-17] Rekap ALPA terpisah Putra & Putri
- **Apa yang diubah:** Laporan ALPA per santri dipisah: `/rekap/alpa_santri_putra.php` (Putra) dan `/rekap/alpa_santri_putri.php` (Putri). Filter kelompok berdasarkan sufiks tingkatan `(putri)` via `jadwal_tingkatan_kelompok_dari_nama()`. URL lama `/rekap/alpa_santri.php` redirect 301 ke Putra. Shared helper + partial UI; menu Ketertiban → Rekap & hub Rekap Presensi diperbarui.
- **File:** `helpers/rekap_alpa_santri.php`, `includes/partials/rekap_alpa_santri_body.php`, `rekap/alpa_santri_putra.php`, `rekap/alpa_santri_putri.php`, `rekap/alpa_santri.php`, `includes/menu_data.php`, `helpers/user_permissions.php`, `helpers/app_hub.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Pengurus putra/putri butuh rekap ALPA terpisah; halaman lama menampilkan semua santri sekaligus.
- **Status:** belum diuji browser manual

### [2026-08-17] Kontras notif + status offline dashboard
- **Apa yang diubah:** Flash/alert Bootstrap di `.app-main` mode mockup gelap (`data-theme="dark"`) memakai kontras terang (teks gelap di latar hijau/merah/kuning terang). Feedback scan presensi/cashless & toast offline dipertebal (`font-weight: 600`, kontras lebih kuat). Dashboard status dinamis: `navigator.onLine` + jumlah antrian IndexedDB (`updateDashboardStatus` di `offline-sync.js`); pill amber saat offline, cyan saat ada antrian pending.
- **File:** `assets/css/app.css`, `assets/css/dashboard.css`, `assets/css/presensi-scan.css`, `assets/css/cashless-scan.css`, `assets/css/offline-sync.css`, `dashboard.php`, `assets/js/offline-sync.js`, `STATUS_PWA.md`
- **Alasan/konteks:** Notif seperti "Login berhasil" sulit dibaca di kartu putih; status dashboard selalu "Normal Online" meski offline.
- **Status:** smoke test offline lulus; **checklist uji manual HP:**
  1. Buka `/presensi/scan.php` sekali saat online (SW cache).
  2. Matikan WiFi → scan QR santri → toast "tersimpan lokal" + badge antrian.
  3. Nyalakan WiFi → auto-sync → feedback sukses, antrian bersih.
  4. Scan santri B tanpa refresh (flash + continuous scan).
  5. Dashboard: status berubah Offline saat WiFi mati; kembali Online + jumlah antrian jika ada.

### [2026-08-17] Scan flash + auto-continue & portal wali NIS+PIN
- **Apa yang diubah:** Tombol Flash menonjol di presensi/login-scan/cashless; tombol Ulangi/refresh disembunyikan dari bar utama. Scan berikutnya otomatis tanpa reload (kamera login scan tidak lagi di-stop). Portal wali: form utama NIS+PIN langsung, plus “Lupa NIS?” untuk cari nama.
- **File:** `assets/js/presensi-scan-camera.js`, `presensi/scan.php`, `includes/partials/presensi_scan_ui.php`, `includes/partials/login_scan_kegiatan.php`, `assets/js/login-scan-kegiatan.js`, `keuangan/cashless_scan.php`, `assets/css/presensi-scan.css`, `assets/css/cashless-scan.css`, `wali/login.php`, `assets/css/auth-portal.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Petugas butuh flash dan scan berurutan; wali harus masuk pakai NIS (bukan username pengurus).
- **Status:** lint PHP; uji kamera/HP manual masih disarankan

### [2026-08-17] Offline polish: SW cold-start + duplicate + hydrate poin
- **Apa yang diubah:** Service Worker precache shell HTML `/presensi/scan.php` + `/poin/input.php` (+ aset scan & `santri-select.js`) saat install dengan fetch per-item (satu gagal tidak gagalkan semua). Warm cache modul saat online via `pwa-register.js`. Konflik presensi mengembalikan `type: duplicate` langsung + copy “perangkat lain”. Form poin dihydrate dari IndexedDB `reference_cache` saat offline. Copy UX: buka scan/poin sekali saat online.
- **File:** `helpers/pwa_offline.php`, `helpers/offline_sync_dedup.php`, `helpers/presensi_scan_post.inc.php`, `assets/js/offline-sync.js`, `assets/js/pwa-register.js`, `includes/partials/offline_status_bar.php`, `scripts/_test_offline_presensi_poin.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Melengkapi gap audit status offline (Option C inti tanpa aturan bisnis baru untuk poin antar-device / cashless).
- **Status:** smoke test diperbarui

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
