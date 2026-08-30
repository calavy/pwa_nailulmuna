# Status PWA — Riwayat Perubahan

File ini mencatat setiap potong pekerjaan di proyek PWA Nailul Muna.
**Konvensi:** Baca file ini di awal setiap sesi kerja sebelum mengerjakan permintaan baru.

---

## Entri

### [2026-08-31] Kartu PRESNA pindah ke Peraturan poin
- **Apa yang diubah:** Kartu saklar tanpa scan / telat + bobot PRESNA hanya di Pengaturan → Peraturan poin (`settings/peraturan.php`). Dihapus dari rekap keaktifan, rekap tanpa scan, dashboard pengasuh, dan profil pondok. Tetap super admin.
- **File:** `settings/peraturan.php`, `settings/includes/poin_settings_logic.php`, `settings/partials/poin_settings_view.php`, `includes/partials/keaktifan_alpa_tanpa_scan_toggle.php`, `rekap/santri_bagus.php`, `presensi/rekap_tanpa_scan.php`, `pengasuh/dashboard.php`, `settings/pesantren.php`, `settings/partials/pondok_identity_view.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Pengaturan penilaian kehadiran campur di banyak halaman operasional; satu tempat bersama pengaturan poin.
- **Status:** terpasang; uji login super admin di Peraturan poin (simpan saklar + bobot)

### [2026-08-28] Banner libur wali: sama dengan kalender
- **Apa yang diubah:** Banner beranda portal wali tampil jika hari ini libur di grid kalender (rentang, mingguan, atau hari `is_libur`), tanpa mensyaratkan saklar “Blokir presensi” atau centang affects_presensi. Kalimat “Jama’ah/Ta’lim tetap berjalan” hanya jika blokir nyala dan mode parsial. Skip WA kelas kosong tidak diubah.
- **File:** `helpers/akademik.php`, `wali/partials/libur_banner.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Kalender oranye tapi beranda wali kosong karena banner sebelumnya hanya muncul jika blokir presensi nyala dan baris mencentang presensi.
- **Status:** terpasang; uji HP (pull-to-refresh beranda wali pada hari yang sudah libur di kalender)

### [2026-08-28] Bobot penilaian PRESNA di satu kartu
- **Apa yang diubah:** Super admin mengatur pengali Alpa, Izin, Sakit, Telat (penalti) dan Hadir (kredit) 0–10 di kartu yang sama dengan saklar tanpa scan / telat. Default 4, 2, 1, 3, dan Hadir ×1 sehingga persen tidak berubah sampai diubah. Rumus: nilai = Hadir×H + (N.HARI − Hadir) − penalti. Predikat 20/40/60/80 dan `batas_telat_menit` tidak diubah. Rekap, portal wali/santri, dan SKBT otomatis memakai bobot tersimpan.
- **File:** `helpers/app.php`, `helpers/penilaian_kehadiran.php`, `helpers/keaktifan_alpa_tanpa_scan.php`, `helpers/rekap_keaktifan.php`, `helpers/wali_portal.php`, `helpers/santri_riwayat.php`, `helpers/akademik_skbt.php`, `includes/partials/keaktifan_alpa_tanpa_scan_toggle.php`, `wali/keaktifan.php`, `santri_portal/keaktifan.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Pengali PRESNA sebelumnya kaku di kode; pondok ingin menyesuaikan tanpa deploy (mis. turunkan Alpa agar 0% lebih jarang, atau naikkan Hadir sebagai kredit).
- **Status:** terpasang; uji simpan bobot default (persen sama) lalu ubah Hadir/Alpa dan cek rekap/portal/SKBT

### [2026-08-28] Tanpa scan: nyala = Hadir
- **Apa yang diubah:** Saklar “Kegiatan tanpa scan dihitung Hadir”: nyala = slot Jama’ah/Ta’lim tanpa petugas dihitung Hadir (N.HARI tetap); mati = tidak ALPA dan slot tidak masuk N.HARI. Mode lama kelas kosong = ALPA dimatikan. Izin/Sakit tidak diubah. Portal wali ikut rekap yang sama.
- **File:** `helpers/keaktifan_alpa_tanpa_scan.php`, `helpers/app.php`, `helpers/presensi_jadwal.php`, `helpers/rekap_keaktifan.php`, `includes/partials/keaktifan_alpa_tanpa_scan_toggle.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Tidak ada petugas bukan kesalahan santri; penilaian PRESNA tetap jalan untuk ALPA/izin di kelas yang benar-benar discan.
- **Status:** terpasang; uji saklar nyala (ALPA slot kosong jadi Hadir) dan mati (N.HARI turun)

### [2026-08-28] Saklar: Telat dihitung Hadir
- **Apa yang diubah:** Super admin punya saklar penilaian: aktif = HADIR lewat batas telat tidak kena penalti ×3 (dihitung Hadir); nonaktif = tetap Telat. Default nonaktif. Daftar operasional siapa yang telat tidak diubah. Scan tetap tersimpan HADIR.
- **File:** `helpers/penilaian_kehadiran.php`, `helpers/app.php`, `helpers/keaktifan_alpa_tanpa_scan.php`, `helpers/rekap_keaktifan.php`, `includes/partials/keaktifan_alpa_tanpa_scan_toggle.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Predikat PRESNA memakai Telat×3; pondok ingin opsi memperlakukan telat sebagai hadir tanpa menghapus catatan jam.
- **Status:** terpasang; uji saklar ON (predikat naik, daftar telat tetap ada) dan OFF (penalti telat kembali)

### [2026-08-25] Libur: Jama’ah ikut nonaktif
- **Apa yang diubah:** Mode default presensi saat libur menjadi semua jalur libur (Ta’lim dan Jama’ah). Nilai lama `TAALIM_ONLY` di database diganti sekali ke `ALL_BLOCKED`. Dropdown di pengaturan kalender tetap ada jika suatu saat Jama’ah perlu jalan.
- **File:** `helpers/app.php`, `helpers/akademik.php`, `helpers/kalender_pengaturan.php`, `settings/kalender.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Libur sebelumnya hanya menahan Ta’lim; Jama’ah masih bisa di-scan dan memicu notifikasi WA.
- **Status:** terpasang; uji hari libur (scan Jama’ah ditolak, banner tanpa “Jama’ah tetap berjalan”)

### [2026-08-25] Libur: diamkan notif WA + banner wali
- **Apa yang diubah:** Notifikasi WA kelas kosong dan pengingat scan pembimbing/munawib mengikuti mode libur presensi (semua diblokir, atau hanya Ta’lim / Jama’ah). Beranda portal wali menampilkan banner nama libur dan sampai tanggal berapa (rentang), atau setiap hari untuk libur mingguan. Jadwal di database tidak dihapus.
- **File:** `helpers/akademik.php`, `helpers/wa_kegiatan_kosong.php`, `helpers/wa_pembimbing_scan.php`, `wali/partials/libur_banner.php`, `wali/index.php`, `assets/css/wali-portal.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Libur menahan scan tapi notif WA masih menganggap kegiatan wajib; wali tidak melihat keterangan libur di beranda.
- **Status:** terpasang; uji hari libur (banner + tidak ada WA kegiatan yang diliburkan)

### [2026-08-24] Template WA laporan kegiatan kosong
- **Apa yang diubah:** Teks laporan kelas/kegiatan kosong bisa diedit di tab Template WA. Dua slug: `kelas_kosong_pengurus` (petugas/pengurus) dan `kelas_kosong_pembimbing`. Default sama dengan teks lama. Tab Presensi punya tautan ke Template.
- **File:** `helpers/wa_templates.php`, `helpers/wa_kegiatan_kosong.php`, `settings/partials/wa_otomatis_tab_presensi.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Pesan sebelumnya tertulis di kode; admin tidak bisa mengubah redaksi tanpa deploy.
- **Status:** terpasang; uji kirim WA / simpan template manual

### [2026-08-24] UAT Brief 41: sisa cek lulus
- **Apa yang diubah:** Uji HP 24 Agu mengonfirmasi sisa cek yang ditahan setelah UAT 23 Agu (12/20). Tidak ada perubahan kode.
- **UAT rate-limit:** 5× password salah, percobaan ke-6 tampil *Terlalu banyak percobaan login. Coba lagi dalam 15 menit.* — **lulus**
- **UAT offline:** buka scan/Multi Scan saat online → airplane → scan 2 kartu (antrian terlihat) → online → antrian terkirim; pill dashboard berubah; cashless offline tidak tersimpan — **lulus**
- **UAT Multi Scan:** flash, pilih kamera, Super Fokus — **lulus**
- **File:** `STATUS_PWA.md`
- **Alasan/konteks:** Skor resmi 23 Agu tetap 12/20 (daftar 20 butir Brief 41 tidak ada di repo). Tiga sisa yang ditahan (rate-limit, Bagian 4 offline, kamera Multi Scan) sudah lulus. Pekerjaan 24 Agu (PRESNA 5 jenjang, saklar ALPA, SKBT/pengasuh) bukan bagian Brief 41.
- **Status:** lulus uji HP 24 Agu

### [2026-08-24] Predikat 3 jenjang disamakan ke PRESNA (5 kategori)
- **Apa yang diubah:** Field “Kategori baik/sedang (max alpa)” dihapus dari kartu Operasional presensi (setting DB tetap untuk setoran/PKPPS). SKBT predikat otomatis memakai rumus PRESNA: HADIR lewat batas = Telat, N.HARI = slot terhitung, 5 jenjang (Baik/Cukup/Sedang/Kurang/Buruk). Override pengasuh (`santri_nilai_keaktifan.nilai`) diperluas ENUM + form jadi 5 pilihan. Nilai ujian/ikhtibar/manual angka tidak diubah.
- **File:** `settings/partials/pondok_identity_view.php`, `helpers/akademik_skbt.php`, `helpers/penilaian_kehadiran.php`, `helpers/santri_keaktifan_nilai.php`, `pengasuh/nilai_keaktifan.php`, `includes/partials/santri_keaktifan_nilai_view.php`, `includes/partials/santri_buku_induk.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Keaktifan rekap sudah 5 jenjang PRESNA; SKBT dan penilaian pengasuh masih 3 (Baik/Sedang/Buruk) dari hitungan GHOIB.
- **Status:** terpasang; uji form pengasuh + cetak SKBT manual

### [2026-08-24] Penilaian kehadiran: rumus PRESNA asli
- **Apa yang diubah:** Rekap keaktifan memakai rumus PRESNA: AKUMULASI = Alpa×4 + Izin×2 + Sakit×1 + Telat×3; N.HARI = jumlah slot kegiatan terhitung (bukan 100); ABSENSI = max(0, N.HARI − AKUMULASI); % kehadiran = ABSENSI ÷ N.HARI. Predikat: Buruk ≤20%, Kurang 21–40%, Sedang 41–60%, Cukup 61–80%, Baik 81–100%. Spek draft dasar-100 / Alpa×3 / Baik >94% diarsipkan. Status Keluar PRESNA ("K") tidak ditambah di presensi — santri boyong/keluar sudah NONAKTIF dan tidak dinilai. Override pengasuh tetap Baik/Sedang/Buruk. Akademik tidak diubah.
- **File:** `helpers/penilaian_kehadiran.php`, `helpers/rekap_keaktifan.php`, `helpers/santri_riwayat.php`, `helpers/wali_portal.php`, `wali/keaktifan.php`, `santri_portal/keaktifan.php`, `includes/partials/santri_keaktifan_nilai_view.php`, `rekap/santri_bagus.php`, `yayasan/partials/keaktifan_bulan_panel.php`, `rekap/partials/alur_presensi_rekap_panduan.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Draft spek lebih ketat dari spreadsheet PRESNA 52; rumus asli dipakai agar predikat Cukup/Baik tidak tertarik jadi Buruk.
- **Status:** rumus terpasang; uji rekap/portal manual

### [2026-08-24] Saklar ALPA jika Jama'ah/Ta'lim tanpa scan
- **Apa yang diubah:** Setting `keaktifan_alpa_jika_tanpa_scan` (default ON). OFF: slot Jama'ah/Ta'lim tanpa satu pun HADIR tidak ditulis/dihitung ALPA (historis tidak dihapus). Laporan tanpa scan = Jama'ah + Ta'lim, 1 per kegiatan/waktu. Saklar hanya super admin (dashboard pengasuh, rekap tanpa scan, rekap keaktifan, profil pondok). Dashboard/laporan hari pengasuh hanya super admin dan pengasuh (kiai); disembunyikan dari menu admin/pengurus.
- **File:** `helpers/keaktifan_alpa_tanpa_scan.php`, `includes/partials/keaktifan_alpa_tanpa_scan_toggle.php`, `includes/auth.php`, `helpers/app.php`, `helpers/rekap_keaktifan.php`, `helpers/presensi_jadwal.php`, `pengasuh/dashboard.php`, `pengasuh/laporan_hari.php`, `presensi/rekap_tanpa_scan.php`, `rekap/santri_bagus.php`, `settings/pesantren.php`, `settings/partials/pondok_identity_view.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Kegiatan tanpa scan petugas jangan otomatis menandai seluruh santri ALPA; akses dashboard pengasuh dipersempit.
- **Status:** belum diuji browser manual

### [2026-08-24] Penilaian kehadiran: bobot Alpa/Izin/Telat/Sakit — TIDAK BERLAKU / DIARSIPKAN
- **Apa yang diubah:** Draft spek (dasar 100, Alpa×3, Baik >94%). **Tidak dipakai.** Diganti rumus PRESNA asli (entri di atas).
- **File:** (arsip catatan saja)
- **Alasan/konteks:** Ambang draft terlalu ketat dibanding spreadsheet PRESNA 52.
- **Status:** diarsipkan

### [2026-08-23] Multi Scan: pilih kamera seperti scan presensi
- **Apa yang diubah:** Multi Scan mendapat panel pilih kamera dan tombol Kamera, diikat ke `PresensiScanCamera` sama seperti scan presensi (`cameraSelect`, `settingsPanel`, `btnSettings`). Engine Flash tidak diganti.
- **File:** `includes/partials/login_scan_kegiatan.php`, `assets/js/login-scan-kegiatan.js`, `STATUS_PWA.md`
- **Alasan/konteks:** Flash hidup di scan presensi tapi mati di Multi karena Multi tidak bisa memilih kamera belakang (lensa yang punya torch).
- **Status:** lulus uji HP 24 Agu (lihat entri UAT Brief 41: sisa cek lulus)

### [2026-08-23] Scan: flash tidak bergantung caps.torch
- **Apa yang diubah:** Tombol Flash mencoba `applyConstraints` (torch + fillLightMode) meskipun `getCapabilities().torch` kosong. Track video dipilih yang live / yang punya torch. Super Fokus tidak mematikan torch. Pesan status jika kamera belum siap atau gagal.
- **File:** `assets/js/presensi-scan-camera.js`, `STATUS_PWA.md`
- **Alasan/konteks:** Banyak HP Android tidak melapor `caps.torch`, sehingga tombol Flash langsung dianggap tidak tersedia dan lampu tidak pernah diminta.
- **Status:** lulus uji HP 24 Agu (lihat entri UAT Brief 41: sisa cek lulus)

### [2026-08-23] UAT Brief 41 + perbaikan offline
- **Apa yang diubah:** Hasil UAT 23 Agu: 12/20 lulus (kamera, flash, 3 mode, menu peran, login arah). Belum lulus: rate-limit (belum terbukti) dan seluruh Bagian 4 offline. Perbaikan: tabel `login_attempt` dibuat saat buka login; SW tidak menyimpan HTML login sebagai `scan.php`; offline scan/poin fallback ke Multi Scan; Multi Scan offline mereset kamera (kartu berikutnya tanpa refresh); pill status online/offline di dashboard pembimbing; cashless tidak lagi masuk antrian (pesan butuh internet).
- **UAT rate-limit:** 5× password salah, percobaan ke-6 harus tampil *Terlalu banyak percobaan login. Coba lagi dalam 15 menit.*
- **UAT offline:** buka scan/Multi Scan saat online (login) → airplane → scan 2 kartu (antrian terlihat) → online → antrian terkirim; pill dashboard berubah; cashless offline tidak tersimpan.
- **File:** `helpers/login_rate_limit.php`, `login.php`, `helpers/pwa_offline.php`, `assets/js/pwa-register.js`, `includes/partials/pwa_scan_precache_boot.php`, `assets/js/login-scan-kegiatan.js`, `assets/js/offline-sync.js`, `includes/partials/dash_offline_status.php`, `pembimbing/dashboard.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Precache scan tanpa sesi jadi halaman login; cashless ikut antrian padahal harus online-only; dashboard pembimbing tidak punya pill status.
- **Status:** perbaikan terpasang; sisa cek lulus uji HP 24 Agu (lihat entri UAT Brief 41: sisa cek lulus)

### [2026-08-23] Perizinan: tombol aksi sekali klik
- **Apa yang diubah:** Form POST perizinan (kirim, setujui, tolak, simpan, hapus, tandai kembali) terkunci setelah satu submit yang valid. Klik kedua ditolak. Filter GET, cetak, dan tombol bantu (Pilih semua, Batal) tetap bisa diulang. Confirm batal atau ALPA terhalang tidak mengunci tombol.
- **File:** `assets/js/perizinan-submit-once.js`, `helpers/app.php`, `includes/footer.php`, `wali/includes/layout.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Ketuk cepat Setujui/Tolak/Kirim bisa mengirim form dua kali. Pencegahan sebelumnya hanya di modal setujui dan form pengasuh.
- **Status:** belum diuji HP manual

### [2026-08-23] Offline: login, Multi Scan, sinkron otomatis
- **Apa yang diubah:** Service worker mem-precache dan mengizinkan navigasi ke beranda, `login.php` (password + Multi Scan), dan dashboard. Saat offline, halaman dari cache ditampilkan dulu, bukan langsung `offline.php`. Setelah login online, username + hash password disimpan di perangkat; offline, form login dicegat dan membuka dashboard cache jika cocok. `offline-sync.js` ikut di portal login dan dashboard supaya antrian scan terkirim sendiri saat internet kembali.
- **File:** `helpers/pwa_offline.php`, `assets/js/login-offline.js`, `assets/js/offline-sync.js`, `assets/js/pwa-register.js`, `login.php`, `includes/auth_portal_layout.php`, `includes/footer.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Ketuk domain saat offline sering jatuh ke halaman offline; login user/pass dan dashboard belum ter-cache; antrian tidak ter-flush jika user hanya di halaman login.
- **Status:** lulus uji HP 24 Agu (lihat entri UAT Brief 41: sisa cek lulus)

### [2026-08-23] Multi Scan: kartu berikutnya tanpa refresh
- **Apa yang diubah:** Setelah absensi santri, kamera langsung siap kartu lain (seperti scan presensi). Kunci “tahan sampai kartu pergi” hanya untuk pembimbing/munawib dengan `stay_on_scan` (supaya scan 1 tidak langsung jadi portal). Jeda lepas kunci dipercepat 450ms.
- **File:** `assets/js/login-scan-kegiatan.js`, `assets/js/presensi-scan-camera.js`, `STATUS_PWA.md`
- **Alasan/konteks:** `holdUntilCodeGone` dipasang di semua hasil scan, sehingga ganti kartu santri terasa macet sampai refresh.
- **Status:** belum diuji HP manual

### [2026-08-23] Multi Scan: Super Fokus seperti scan presensi
- **Apa yang diubah:** Multi Scan mendapat tombol Super Fokus (ikon crosshairs) dan diikat ke `PresensiScanCamera` sama seperti scan presensi. Flash tetap. State aktif memakai CSS yang sama.
- **File:** `includes/partials/login_scan_kegiatan.php`, `assets/js/login-scan-kegiatan.js`, `assets/css/presensi-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Flash sudah ada di Multi, tetapi Super Fokus belum terpasang sehingga fokus kartu tidak bisa dioptimalkan di gerbang.
- **Status:** lulus uji HP 24 Agu (lihat entri UAT Brief 41: sisa cek lulus)

### [2026-08-22] Fonte: gelombang 15 + jeda setelah scan
- **Apa yang diubah:** Blast tagihan/grup dibatasi 15 pesan per jalan dengan jeda 12–20 detik. Setelah perangkat putus lalu nyambung, atau tombol “Perangkat baru di-scan”, blast ditahan 3 jam (tes 1 nomor tetap boleh). Tombol kirim tagihan menyimpan progres gelombang.
- **File:** `helpers/wa_otomatis.php`, `helpers/wa_tagihan.php`, `helpers/app.php`, `settings/includes/wa_otomatis_logic.php`, `settings/partials/wa_otomatis_tab_gateway.php`, `settings/partials/wa_otomatis_tab_tagihan.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Kirim ratusan tagihan sekaligus membuat WhatsApp memblokir nomor Fonte.
- **Status:** belum diuji kirim massal / scan QR manual

### [2026-08-22] Dashboard pembimbing admin: hero dua kartu
- **Apa yang diubah:** Cabang admin/pengurus memakai dua kartu (hijau kiri: nama + status scan, jam kanan). Markup `</div>` berlebih dihapus; nama tidak lagi `display:none`. Dashboard utama admin tidak diubah.
- **File:** `pembimbing/dashboard.php`, `assets/css/pembimbing-dashboard.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Administrator melihat cabang `dash-hero-split`, bukan portal banner pembimbing; kartu hijau hampir kosong dan jam full width.
- **Status:** belum diuji desktop manual

### [2026-08-22] Banner pembimbing desktop: dua kartu mockup
- **Apa yang diubah:** Desktop memakai layout mockup awal: kartu hijau kiri (nama, status scan, NIP) dan jam hitam kanan, chip statistik di bawah. Logo disembunyikan. Gaya tidak lagi tergantung tema gelap. HP tetap stacked (identitas lalu jam, tanpa chip).
- **File:** `assets/css/pembimbing-dashboard.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Di desktop logo merusak grid 2 kolom, dan dua kartu hanya tampil di tema gelap.
- **Status:** belum diuji desktop/HP manual

### [2026-08-22] Banner pembimbing: nama, status scan, NIP
- **Apa yang diubah:** Kartu hijau di atas jam hanya menampilkan nama pembimbing, badge Hadir/Belum scan, dan NIP. Kicker Kajian, subtitle, dan tagline dihapus dari kartu itu.
- **File:** `pembimbing/partials/portal_banner.php`, `assets/css/pembimbing-dashboard.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Kartu identitas terlalu ramai; yang dibutuhkan hanya identitas dan status kehadiran.
- **Status:** belum diuji HP manual

### [2026-08-22] Dashboard pembimbing HP: hapus logo/chip ganda, topbar pondok+aksi
- **Apa yang diubah:** Di HP, logo di kartu hijau dan chip “tingkatan/santri” disembunyikan. Topbar: baris 1 logo+nama pondok kiri dan profil/logout kanan; baris 2 judul Dashboard Pembimbing di bawah nama pondok. Padding bawah tetap agar FAB unggah tidak menutupi menu setoran.
- **File:** `assets/css/app.css`, `assets/css/pembimbing-dashboard.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Logo dan statistik ganda membuat tampilan ramai; judul terpotong; tombol unggah menutupi kartu bawah.
- **Status:** belum diuji HP manual

### [2026-08-22] Dashboard pembimbing HP: topbar dua baris, banner, menu
- **Apa yang diubah:** Topbar portal tanpa sidebar di HP dua baris (pondok lalu judul+profil). Banner: jam di bawah kartu hijau, chip tingkatan/santri tidak tertindih. Menu setoran sel 2 kolom seperti kartu lain. Padding bawah agar FAB unggah tidak menutupi menu. Teks deskripsi banner minimal 14px.
- **File:** `assets/css/app.css`, `assets/css/pembimbing-dashboard.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Di ponsel nama pondok berdempetan dengan Dashboard, jam menindih chip, kartu setoran terlalu lebar, dan tombol unggah menutupi konten.
- **Status:** belum diuji HP manual

### [2026-08-22] Dashboard: layout menyesuaikan layar ponsel
- **Apa yang diubah:** Di HP, halaman dashboard tidak geser horizontal. Padding shell memakai safe-area. Hero/KPI/panel admin-pengasuh lebih rapat, pill status satu kolom. Tab pengasuh bisa digeser. Menu pembimbing dan kartu yayasan 2 kolom. Portal wali mencegah overflow.
- **File:** `assets/css/app.css`, `assets/css/dashboard.css`, `assets/css/pengasuh-dashboard.css`, `assets/css/pembimbing-dashboard.css`, `assets/css/wali-portal.css`, `assets/css/yayasan-portal.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Tampilan dashboard di ponsel perlu mengikuti lebar perangkat tanpa pecah layout.
- **Status:** belum diuji HP manual

### [2026-08-22] Multi Scan: scan 1 jangan otomatis portal
- **Apa yang diubah:** Setelah kehadiran pembimbing/munawib, kamera mengabaikan kartu yang sama selama masih di lensa. Portal hanya jika kartu dijauhkan lalu discan lagi. Kartu lain tetap bisa discan langsung.
- **File:** `assets/js/login-scan-kegiatan.js`, `assets/js/presensi-scan-camera.js`, `STATUS_PWA.md`
- **Alasan/konteks:** Debounce direset setelah toast hadir, sehingga kartu yang masih di kamera terkirim sebagai scan kedua dan langsung masuk portal.
- **Status:** belum diuji HP manual

### [2026-08-22] Multi Scan: pembimbing/munawib kehadiran dulu, portal di scan kedua
- **Apa yang diubah:** Jika ada jadwal aktif, scan pertama kartu pembimbing/munawib hanya kehadiran (kamera tetap, tanpa refresh). Scan kedua (sudah tercatat) masuk portal. Tanpa jadwal, sekali scan langsung portal. Debounce kamera direset agar kartu berikutnya atau scan portal segera bisa.
- **File:** `api/scan/smart.php`, `assets/js/login-scan-kegiatan.js`, `assets/js/presensi-scan-camera.js`, `includes/partials/login_scan_kegiatan.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Satu scan absensi+portal membuat petugas tidak bisa lanjut scan kartu lain; portal hanya dibutuhkan di scan kedua.
- **Status:** belum diuji HP manual

### [2026-08-21] WA otomatis: pilih Fonte atau Meta Cloud API
- **Apa yang diubah:** Tab Gateway punya pilihan provider Fonte atau Meta. Kiriman ke nomor HP memakai provider yang dipilih. Grup WhatsApp tetap Fonte. Meta mengirim teks Cloud API; jika jendela 24 jam tertutup dan nama template diisi, fallback template satu parameter.
- **File:** `helpers/wa_otomatis.php`, `helpers/app.php`, `settings/includes/wa_otomatis_logic.php`, `settings/partials/wa_otomatis_tab_gateway.php`, `settings/partials/wa_otomatis_tab_presensi.php`, `settings/partials/wa_otomatis_tab_izin.php`, `settings/partials/wa_otomatis_tab_ringkasan.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Pondok ingin bisa memakai WhatsApp Cloud API Meta tanpa kehilangan kiriman grup Fonte.
- **Status:** belum diuji kirim live (Fonte/Meta)

### [2026-08-20] Portal wali: teks login PIN + pilih santri di beranda
- **Apa yang diubah:** Teks login diganti menjadi petunjuk cari nama/NIS lalu isi PIN dari pengurus. Jika wali punya lebih dari satu anak, beranda menampilkan daftar pilih santri (layout switcher sebelumnya tidak tampil karena variabel di luar fungsi).
- **File:** `wali/login.php`, `wali/index.php`, `wali/partials/anak_switcher.php`, `wali/includes/layout.php`, `wali/keaktifan.php`, `wali/izin.php`, `assets/css/wali-portal.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Teks lama merujuk menu pengurus; wali dengan beberapa anak perlu memilih santri di beranda.
- **Status:** belum diuji browser manual

### [2026-08-20] Multi Scan: routing otomatis tanpa pill mode
- **Apa yang diubah:** Pill Presensi / Portal / Setoran dihapus. Satu scan memakai smart API: santri = absensi, pembimbing/munawib = absensi jika jadwal aktif lalu masuk portal. `?dest=setoran` tetap langsung portal setoran. Perbaikan kamera dan Flash tidak diubah.
- **File:** `includes/partials/login_scan_kegiatan.php`, `assets/js/login-scan-kegiatan.js`, `api/scan/smart.php`, `scripts/_audit_scan_flow.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Multi Scan kembali ke routing otomatis seperti sebelumnya; pilih mode memotong alur absensi+portal.
- **Status:** belum diuji browser/HP manual

### [2026-08-20] UAT 41 Bagian 2: preview kamera, flash, 3 mode scan
- **Apa yang diubah:** Overlay html5-qrcode (`#qr-shaded-region`) disembunyikan; bingkai scan lebih transparan agar preview tidak hitam pekat. Video tetap mengisi viewport. Flash tidak disembunyikan; `detectTorch` diulang setelah preview; `applyConstraints` torch dengan fallback. Pill Presensi / Portal / Setoran dikembalikan di Multi Scan (Presensi = smart API, Portal = login kartu tanpa absensi, Setoran = portal setoran).
- **File:** `assets/css/presensi-scan.css`, `assets/css/auth-portal.css`, `assets/js/presensi-scan-camera.js`, `assets/js/login-scan-kegiatan.js`, `includes/partials/login_scan_kegiatan.php`, `api/scan/smart.php`, `scripts/_audit_scan_flow.php`, `STATUS_PWA.md`
- **Alasan/konteks:** UAT Brief 41 Bagian 2 gagal: kamera hitam di 2 HP, tombol Flash tidak menyala, 3 mode scan tidak terlihat.
- **Status:** belum diuji browser/HP manual

### [2026-08-20] Scan: preview kamera tidak hitam
- **Apa yang diubah:** Video dipaksa mengisi viewport (`object-fit: cover`, `min-height` 100%, opacity 1). Setelah start, `video.play()` + playsinline/muted. Enumerasi `getCameras` tidak lagi dipanggil otomatis setelah preview (hanya saat Ganti kamera).
- **File:** `assets/js/presensi-scan-camera.js`, `assets/css/presensi-scan.css`, `assets/css/auth-portal.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Scanner sudah “terbuka” tapi kotak tetap hitam karena elemen video collaps (`min-height: 0`) atau pause, plus overlay hitam.
- **Status:** belum diuji browser manual

### [2026-08-20] Scan: perbaiki kamera hitam Multi Scan
- **Apa yang diubah:** Start pertama hanya kamera belakang (`facingMode: environment`), tanpa ID lama dan tanpa resolusi 640×480. Gagal start mereset instance sebelum fallback. Enumerasi kamera ditunda 2 detik. Jika video tanpa frame, restart sekali. CSS video login memakai `object-fit: cover`.
- **File:** `assets/js/presensi-scan-camera.js`, `assets/css/presensi-scan.css`, `assets/css/auth-portal.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Preview Multi Scan hitam karena device ID tersimpan, constraint VGA, dan getCameras merusak stream yang baru hidup.
- **Status:** belum diuji browser manual

### [2026-08-20] Scan: kamera dibuka ringan
- **Apa yang diubah:** Kamera start sekali di 640×480 (10 fps), tanpa pre-open izin lalu ditutup. Daftar kamera diisi setelah preview (untuk Ganti kamera). Cashless sama; tombol Mulai scan cashless tetap.
- **File:** `assets/js/presensi-scan-camera.js`, `keuangan/cashless_scan.php`, `STATUS_PWA.md`
- **Alasan/konteks:** getUserMedia + enumerasi + start 1280×720 membuat kamera terasa berat dan lambat.
- **Status:** belum diuji browser manual

### [2026-08-20] Scan: kamera auto-start, teks jadwal tanpa klik
- **Apa yang diubah:** Tombol “Mulai scan kamera” tidak lagi menahan kamera di HP; kamera langsung menyala (gagal tetap pakai Coba lagi). Teks berjalan dan keterangan jadwal selalu tampil tanpa mengetuk kotak. Ketuk marquee tetap jeda/lanjut.
- **File:** `assets/js/presensi-scan-camera.js`, `assets/js/presensi-scan-timer.js`, `includes/partials/presensi_scan_timer_strip.php`, `assets/css/presensi-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Overlay Mulai scan menutup preview; keterangan kegiatan tersembunyi sampai kotak diklik.
- **Status:** belum diuji browser manual

### [2026-08-19] Scan: kamera tetap terbuka saat marquee tampil
- **Apa yang diubah:** Area kamera punya tinggi minimum agar tidak tertekan jadi 0 px saat teks kegiatan tampil. Flash tidak disembunyikan jika torch gagal dicek; jika kamera belum siap, status memberi tahu.
- **File:** `assets/css/presensi-scan.css`, `assets/css/auth-portal.css`, `assets/js/presensi-scan-camera.js`, `STATUS_PWA.md`
- **Alasan/konteks:** Marquee kegiatan yang tampil langsung membuat viewport kamera terlalu pendek, html5-qrcode gagal start, tombol flash tidak punya track video.
- **Status:** belum diuji browser manual

### [2026-08-19] Scan: marquee kegiatan aktif tampil tanpa klik
- **Apa yang diubah:** Jika ada kegiatan berlangsung, teks berjalan (nama · jam · tingkatan) tampil langsung di kotak timer. Upcoming/ended/none/libur tetap ringkas sampai diklik. Ketuk kotak tetap bisa menyembunyikan marquee; ketuk marquee tetap jeda/lanjut.
- **File:** `includes/partials/presensi_scan_timer_strip.php`, `assets/js/presensi-scan-timer.js`, `STATUS_PWA.md`
- **Alasan/konteks:** Petugas perlu melihat kegiatan yang sedang berlangsung tanpa harus mengetuk kotak dulu.
- **Status:** belum diuji browser manual

### [2026-08-19] Scan: teks ended/none tanpa “scan ditolak”
- **Apa yang diubah:** Status `ended` dan `none` menampilkan “Belum ada kegiatan berlangsung” (hint + judul), bukan “Di luar jadwal — scan ditolak”. Libur tetap “Hari libur — scan ditolak”. Logika izinkan/tolak scan tidak diubah.
- **File:** `assets/js/presensi-scan-timer.js`, `includes/partials/presensi_scan_timer_strip.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Di Multi Scan login kotak tertutup hanya menampilkan hint; teks “scan ditolak” terasa keras padahal hanya belum ada kegiatan.
- **Status:** belum diuji browser manual

### [2026-08-19] Scan: tampilkan kegiatan yang akan berlangsung
- **Apa yang diubah:** Jika belum ada kegiatan aktif, setelah kotak diklik tampil “Kegiatan yang akan berlangsung” plus nama, jam, dan tingkatan. Jika tidak ada jadwal berikutnya, tetap “Belum ada kegiatan berlangsung”.
- **File:** `includes/partials/presensi_scan_timer_strip.php`, `assets/js/presensi-scan-timer.js`, `assets/css/presensi-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Petugas perlu melihat kegiatan berikutnya, bukan hanya placeholder kosong.
- **Status:** belum diuji browser manual

### [2026-08-19] Scan: placeholder jika belum ada kegiatan berlangsung
- **Apa yang diubah:** Setelah kotak timer diklik, jika tidak ada kegiatan aktif tampil satu baris “Belum ada kegiatan berlangsung” (bukan strip kosong atau judul/jam menumpuk). Libur/di luar jadwal tetap teks khusus.
- **File:** `includes/partials/presensi_scan_timer_strip.php`, `assets/js/presensi-scan-timer.js`, `assets/css/presensi-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Teks berjalan kosong atau judul kegiatan berikutnya membuat kotak scan terasa berantakan.
- **Status:** belum diuji browser manual

### [2026-08-19] WA tagihan: rincian kekurangan per bulan
- **Apa yang diubah:** Satu pesan WA memuat rincian per bulan (nama bulan + pos syahriyah/makan yang masih kurang + nominal), di bawah ringkasan total. Template `{rincian_per_bulan}`; template lama tanpa placeholder tetap diisi otomatis.
- **File:** `helpers/tagihan_bulanan.php`, `helpers/wa_tagihan.php`, `helpers/app.php`, `helpers/wa_templates.php`, `docs/PANDUAN-KEUANGAN.md`, `STATUS_PWA.md`
- **Alasan/konteks:** Wali perlu melihat kekurangan per bulan, bukan hanya total gabungan.
- **Status:** belum diuji kirim WA live

### [2026-08-19] WA tagihan syahriyah + makan (tunggakan TA, satu pesan)
- **Apa yang diubah:** Pengingat WA (otomatis, manual, preview, push) mengirim satu pesan berisi sisa syahriyah dan makan. Tunggakan dari awal tahun ajaran ikut jika kumulatif aktif. Saku tidak ikut. Status lunas di halaman tagihan tetap hanya syahriyah.
- **File:** `helpers/app.php`, `helpers/tagihan_bulanan.php`, `helpers/wa_tagihan.php`, `helpers/push_events.php`, `helpers/wa_templates.php`, `settings/partials/wa_otomatis_tab_tagihan.php`, `docs/PANDUAN-KEUANGAN.md`, `STATUS_PWA.md`
- **Alasan/konteks:** Kiriman sebelumnya hanya syahriyah bulan wajib; wali perlu juga diingatkan makan dan tunggakan bulan sebelumnya dalam satu pesan.
- **Status:** belum diuji kirim WA live

### [2026-08-19] Kotak timer: jarak tulisan tidak numpuk
- **Apa yang diubah:** “Waktu sekarang” dipindah ke baris sendiri (tidak menimpa kotak). Padding/gap hint + jam `HH:MM:SS` diperlonggar, termasuk di layar pendek.
- **File:** `assets/css/presensi-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Overlay jam dinding + padding rapat membuat tulisan di kotak timer menumpuk.
- **Status:** belum diuji browser manual

### [2026-08-19] Teks berjalan scan hanya setelah klik
- **Apa yang diubah:** Kotak timer default hanya hint + jam `HH:MM:SS`. Teks berjalan (atau judul/jam kegiatan) tampil di atas countdown setelah kotak diklik; ketuk lagi untuk menyembunyikan. Ketuk marquee tetap jeda/lanjut tanpa menutup.
- **File:** `includes/partials/presensi_scan_timer_strip.php`, `assets/js/presensi-scan-timer.js`, `assets/css/presensi-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Marquee dan judul kegiatan membuat kotak ramai sebelum petugas membutuhkannya.
- **Status:** belum diuji browser manual

### [2026-08-19] Countdown scan tanpa teks satuan
- **Apa yang diubah:** Teks satuan “3 jam 42 menit” dihapus dari kotak timer. Countdown tetap `HH:MM:SS` (contoh `03:42:28`).
- **File:** `assets/js/presensi-scan-timer.js`, `helpers/presensi_scan_jadwal.php`, `includes/partials/presensi_scan_timer_strip.php`, `assets/css/presensi-scan.css`, `login.php`, `presensi/scan.php`, `yayasan/scan_musyawarah.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Hint + angka + satuan menumpuk sehingga kotak timer tidak rapi.
- **Status:** belum diuji browser manual

### [2026-08-19] Countdown scan jam:menit:detik + satuan
- **Apa yang diubah:** Countdown scan memakai `HH:MM:SS` (contoh `03:42:28`) plus teks satuan jam/menit (`3 jam 42 menit`; di bawah 1 jam hanya menit). Scan musyawarah memakai helper format yang sama.
- **File:** `assets/js/presensi-scan-timer.js`, `helpers/presensi_scan_jadwal.php`, `includes/partials/presensi_scan_timer_strip.php`, `assets/css/presensi-scan.css`, `yayasan/scan_musyawarah.php`, `login.php`, `presensi/scan.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Format menit total (`222:27`) terasa salah untuk sisa waktu beberapa jam.
- **Status:** belum diuji browser manual

### [2026-08-19] Multi Scan sekali lihat tanpa scroll
- **Apa yang diubah:** Halaman Multi Scan dikunci `100dvh` (border-box). Judul kartu teal disembunyikan (judul cukup di bar hijau). Timer dipadatkan; nama kegiatan tidak diulang jika marquee tampil. Kamera mengisi sisa ruang; hint layar pendek disembunyikan.
- **File:** `assets/css/auth-portal.css`, `assets/css/presensi-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Padding 100dvh + judul ganda + strip timer membuat Flash/Ganti kamera terdorong keluar layar.
- **Status:** belum diuji browser manual

### [2026-08-19] Portal wali di wali.nailulmuna.id
- **Apa yang diubah:** Host wali memblokir rute staf (dashboard/scan/keuangan) dan mengarah ke `/wali/`. Callback Midtrans finish memakai `app_wali_href()`. Service worker portal dibatasi scope `/wali/` + precache tanpa shell scan. Contoh config/DNS di `config/app.local.example.php` dan `DEPLOY-LIVE.md`.
- **File:** `helpers/app_path.php`, `config/session.php`, `config/database.php`, `helpers/midtrans.php`, `helpers/pwa_offline.php`, `wali/sw.php`, `assets/js/fcm-push.js`, `config/app.local.example.php`, `config/app.php`, `DEPLOY-LIVE.md`, `CARA-PAKAI.md`, `docs/PANDUAN-KEUANGAN.md`, `STATUS_PWA.md`
- **Alasan/konteks:** Staf di `pwa.nailulmuna.id`, portal wali di `wali.nailulmuna.id` (satu aplikasi, document root sama).
- **Status:** belum diuji browser/DNS live

### [2026-08-19] Scan cashless QR santri tanpa scroll
- **Apa yang diubah:** Halaman scan dikunci `100dvh` (overflow hidden). Topbar aplikasi dan riwayat di bawah disembunyikan agar kamera + Flash/Ganti kamera muat sekali lihat. Portal koperasi: padding main dihapus, kamera mengisi sisa layar di bawah header koperasi.
- **File:** `assets/css/cashless-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Scan QR santri masih perlu scroll karena kamera 100dvh ditambah topbar/admin bar/riwayat.
- **Status:** belum diuji browser manual

### [2026-08-19] Scan cashless: kamera sama ukuran scan presensi
- **Apa yang diubah:** Viewport QR santri dan QR nominal mengisi sisa layar (tanpa scroll). Nama santri di atas kamera nominal; Saldo Saku / sisa jatah tetap di bawah kamera. Flash/Ganti kamera menempel di bawah.
- **File:** `assets/css/cashless-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Overlay saldo di atas kamera mengganggu; petugas perlu lihat saldo di bawah seperti sebelumnya, tetap sekali lihat.
- **Status:** belum diuji browser manual

### [2026-08-19] Scan cashless: PIN muncul setelah QR santri
- **Apa yang diubah:** Kamera QR santri kembali mengisi sisa layar (tanpa kotak PIN). Setelah QR santri terbaca, kamera disembunyikan dan panel PIN muncul di ruang yang sama — pola seperti panel Bayar setelah QR nominal.
- **File:** `assets/css/cashless-scan.css`, `keuangan/cashless_scan.php`, `STATUS_PWA.md`
- **Alasan/konteks:** PIN tidak perlu tampil bersama kamera; ukurannya harus seperti alur konfirmasi nominal.
- **Status:** belum diuji browser manual

### [2026-08-19] Scan cashless: kamera QR santri = QR nominal
- **Apa yang diubah:** Kedua viewport kamera (QR santri dan QR uang) memakai kotak tetap yang sama: `min(30dvh, 220px)`.
- **File:** `assets/css/cashless-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Kamera santri mengisi sisa ruang di atas PIN sehingga tampak lebih besar dari kamera nominal.
- **Status:** belum diuji browser manual

### [2026-08-19] Scan cashless: kamera nominal sama ukuran QR santri
- **Apa yang diubah:** Viewport scan QR uang memakai ukuran yang sama dengan kamera QR santri (tanpa max-height 180px / 20dvh). Kotak bidik sama. Saat konfirmasi Bayar, kamera uang tetap disembunyikan.
- **File:** `assets/css/cashless-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Kamera nominal masih lebih kecil dari kamera scan santri.
- **Status:** belum diuji browser manual

### [2026-08-19] Scan cashless sekali lihat: Flash/kamera terlihat
- **Apa yang diubah:** Layout scan cashless mengisi tinggi perangkat. Kamera mengisi sisa ruang (tanpa min-height 28dvh). Tombol Flash dan Ganti kamera menempel di bawah tanpa geser. PIN/hint dipadatkan di layar pendek.
- **File:** `assets/css/cashless-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Penataan sekali lihat sudah diterapkan di Multi Scan; cashless masih mendorong tombol ke bawah lipatan.
- **Status:** belum diuji browser manual

### [2026-08-19] Scan sekali lihat: tombol Flash/kamera tetap terlihat
- **Apa yang diubah:** Halaman Multi Scan / scan presensi mengisi tinggi perangkat (flex). Viewport kamera tidak lagi memaksa 52–68dvh. Tombol Flash, Ganti kamera, Ulangi menempel di bawah layar tanpa geser.
- **File:** `assets/css/presensi-scan.css`, `assets/css/auth-portal.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Timer + kamera tinggi membuat tombol Flash dan Ganti kamera terdorong ke bawah lipatan.
- **Status:** belum diuji browser manual

### [2026-08-19] Marquee kuning/putih + waktu sekarang pojok kanan
- **Apa yang diubah:** Jam dinding jadi “Waktu sekarang : …” di pojok kanan. Teks berjalan satu warna (kuning); jika lebih dari satu kegiatan, bergantian kuning dan putih.
- **File:** `includes/partials/presensi_scan_timer_strip.php`, `includes/partials/presensi_scan_ui.php`, `helpers/presensi_scan_jadwal.php`, `assets/js/presensi-scan-timer.js`, `assets/css/presensi-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Jam sekarang perlu label dan posisi tetap; banyak kegiatan perlu dibedakan tanpa banyak warna.
- **Status:** belum diuji browser manual

### [2026-08-19] Timer scan: sisa waktu di bawah marquee
- **Apa yang diubah:** Tulisan “Sisa waktu scan” / “Mulai scan dalam” tampil di bawah teks berjalan. Angka countdown hanya sekali (tidak diulang di hint).
- **File:** `includes/partials/presensi_scan_timer_strip.php`, `assets/js/presensi-scan-timer.js`, `assets/css/presensi-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Hint memuat jam yang sama dengan countdown, sehingga angka sisa waktu tampil dobel.
- **Status:** belum diuji browser manual

### [2026-08-19] Marquee: tingkatan sama warna kegiatan
- **Apa yang diubah:** Warna tingkatan di teks berjalan disamakan dengan nama kegiatan (kuning `#facc15`). Tempat tetap oranye; jam cyan.
- **File:** `assets/css/presensi-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Tingkatan perlu terlihat setara dengan nama kegiatan, bukan abu.
- **Status:** belum diuji browser manual

### [2026-08-19] Marquee: warna kegiatan vs tempat
- **Apa yang diubah:** Nama kegiatan kuning tegas (`#facc15`), tempat oranye (`#fb923c`). Jam tetap cyan; tingkatan abu. Tempat tidak lagi digabung dengan tingkatan.
- **File:** `assets/css/presensi-scan.css`, `helpers/presensi_scan_jadwal.php`, `assets/js/presensi-scan-timer.js`, `STATUS_PWA.md`
- **Alasan/konteks:** Nama kegiatan dan tempat sulit dibedakan saat masih satu kelompok warna.
- **Status:** belum diuji browser manual

### [2026-08-19] Kamera nominal kompak + marquee kegiatan vs jam
- **Apa yang diubah:** Viewport scan QR uang diperkecil (~160px / 20dvh). Teks berjalan scan presensi membedakan warna nama kegiatan (kuning) dan jam (cyan); tingkatan/tempat abu.
- **File:** `assets/css/cashless-scan.css`, `helpers/presensi_scan_jadwal.php`, `assets/js/presensi-scan-timer.js`, `assets/css/presensi-scan.css`, `STATUS_PWA.md`
- **Alasan/konteks:** Kamera nominal masih terlalu besar; teks berjalan satu warna sehingga jam sulit dibedakan dari nama kegiatan.
- **Status:** belum diuji browser manual

### [2026-08-19] Scan cashless: kamera kecil + tombol Bayar
- **Apa yang diubah:** Viewport kamera diperkecil. Setelah QR uang terbaca, kamera disembunyikan dan panel nominal + Bayar langsung tampil (tidak menunggu stop kamera). Ketuk Bayar menampilkan hasil dulu; kamera santri menyusul. Lookup nominal memperpanjang sesi; batas server debit 120 detik (timeout 30 detik tetap untuk menunggu scan QR uang).
- **File:** `assets/css/cashless-scan.css`, `keuangan/cashless_scan.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Kamera memenuhi layar sehingga tombol Bayar tertutup; jeda setelah Bayar karena teardown kamera dan sesi 30 detik dari PIN.
- **Status:** belum diuji browser manual

### [2026-08-19] Multi Scan HP: izin kamera setelah ketuk
- **Apa yang diubah:** Di HP, Multi Scan dan scan petugas tidak lagi memanggil `getUserMedia` saat halaman terbuka. Overlay “Mulai scan kamera” muncul dulu; izin diminta setelah ketuk. Pesan error menyebut overlay Android (balon chat, filter, perekam).
- **File:** `assets/js/presensi-scan-camera.js`, `includes/partials/login_scan_kegiatan.php`, `assets/js/login-scan-kegiatan.js`, `STATUS_PWA.md`
- **Alasan/konteks:** Chrome Android menolak izin kamera jika ada overlay aplikasi lain; auto-start memicu dialog “Situs ini tidak dapat meminta izin Anda” sebelum petugas sempat menutup overlay.
- **Status:** belum diuji browser manual

### [2026-08-19] Dashboard pengasuh scroll + Multi Scan kartu berikutnya
- **Apa yang diubah:** Dashboard pengasuh tidak lagi terpotong oleh `dash-home-mobile-fit` (overflow hidden / max-height). CSS mobile mencegah overflow horizontal. Multi Scan login mengunci per QR dan langsung menerima kartu berbeda tanpa menunggu API / jeda 550ms.
- **File:** `assets/css/pengasuh-dashboard.css`, `assets/js/login-scan-kegiatan.js`, `STATUS_PWA.md`
- **Alasan/konteks:** Tampilan pengasuh tidak menyesuaikan perangkat; scan kartu berikutnya masih tertahan flag `submitted` global.
- **Status:** belum diuji browser manual

### [2026-08-18] Multi Scan: jadwal berlangsung + scan beruntun; portal wali terpisah
- **Apa yang diubah:** Halaman Multi Scan (`login.php?scan=1`) menampilkan strip jadwal kegiatan berlangsung (timer/marquee). Setelah absensi satu kartu, kamera siap kartu berikutnya tanpa refresh (debounce hanya untuk QR yang sama). Login wali kembali ke `/wali/login.php` (NIS/nama + PIN); login utama tidak lagi menerima fallback wali. Siap subdomain `wali_public_url` (mis. `https://wali.nailulmuna.id`).
- **File:** `includes/partials/presensi_scan_timer_strip.php`, `includes/partials/login_scan_kegiatan.php`, `login.php`, `presensi/scan.php`, `assets/js/presensi-scan-camera.js`, `assets/js/login-scan-kegiatan.js`, `helpers/app_path.php`, `wali/login.php`, `wali/inc_portal.php`, `wali/logout.php`, `wali/manifest.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Petugas perlu melihat jadwal aktif di Multi Scan dan men-scan antrean kartu tanpa reload; wali tidak campur dengan login pengurus/scan.
- **Status:** belum diuji browser manual

### [2026-08-17] Offline: unggah otomatis + jam scan 7 hari
- **Apa yang diubah:** Jendela `scan_client_at` di server 7 hari (selaras antrian IndexedDB). Antrian dikirim otomatis saat online (init, `pageshow`, `visibilitychange`), urut waktu scan. HTTP 401 tidak membuang antrian; setelah login pengurus/petugas, flag `sessionStorage.pondok_flush_offline` memicu flush di halaman berikutnya.
- **File:** `helpers/presensi_scan_client.php`, `assets/js/offline-sync.js`, `helpers/app.php`, `login.php`, `presensi/login.php`, `helpers/login_qr_auth.php`, `includes/footer.php`, `includes/koperasi_portal_layout.php`, `includes/partials/login_scan_kegiatan.php`, `scripts/_test_offline_cashless_presensi_clock.php`, `STATUS_PWA.md`
- **Alasan/konteks:** Beberapa kegiatan tertumpuk harus masuk sesuai jam HP, bukan jam upload; unggah tanpa tombol; sesi habis tidak boleh menghapus antrian.
- **Status:** belum diuji browser manual

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
