# Manajemen Santri - Fokus Presensi QR

## Setup Wajib

1. Start `Apache` dan `MySQL` di XAMPP.
2. Pastikan database bernama `santri`.
3. Jalankan SQL untuk tabel santri minimal:

```sql
CREATE TABLE IF NOT EXISTS santri (
  id INT(11) NOT NULL AUTO_INCREMENT,
  qr VARCHAR(255) DEFAULT NULL,
  nis VARCHAR(30) NOT NULL,
  nama_santri VARCHAR(100) NOT NULL,
  tingkatan VARCHAR(20) DEFAULT NULL,
  no_wa_wali VARCHAR(30) DEFAULT NULL,
  is_aktif TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_nis (nis)
);
```

4. Import file `schema_presensi.sql` di phpMyAdmin.
5. Buka `http://localhost/manajement_santri`.

## Modul

- Scan presensi QR (`presensi/scan.php`)
- Jadwal kegiatan per tingkatan (`jadwal/index.php`)
- Perizinan + surat izin A5 (`perizinan/index.php`)
- Scan kembali dari izin (`perizinan/kembali.php`)
- Rekap presensi masehi/hijriyah (`rekap/index.php`)
- Settings WA Gateway + kriteria alpa (`settings/index.php`)
- Admin tambahan (`settings/admin.php`)
- Upload logo langsung dari komputer (`settings/index.php`)
- Import data santri massal Excel/CSV (`santri/import.php`)

## Update penting terbaru

- Tampilan aplikasi dioptimalkan untuk ponsel (`assets/css/app.css`)
- Menu aplikasi model sidebar kiri (desktop) + offcanvas (mobile)
- Surat izin A5 memakai layout modern + jam terbit otomatis
- Surat izin memuat QR return untuk scan saat santri kembali
- Nama pengasuh di surat otomatis dari settings
- Scan QR realtime ditingkatkan dengan pilihan kamera + validasi 2x scan agar lebih akurat

## Scheduler WA Otomatis

`jam_kirim_wa_auto` akan dieksekusi lebih akurat jika endpoint cron dipanggil berkala:

- File cron: `cron/wa_auto.php`
- Mode CLI (disarankan):
  - `php c:\xampp\htdocs\manajement_santri\cron\wa_auto.php`
- Mode HTTP:
  - `http://localhost/manajement_santri/cron/wa_auto.php`
  - Jika setting `wa_auto_cron_key` diisi, tambahkan `?key=ISI_KEY`.

Contoh jadwal:
- Linux cron: tiap 1 menit.
- Windows Task Scheduler: jalankan per 1 menit memakai `php.exe` + argumen file di atas.

## Login awal

- Username: `admin`
- Password: `admin123`

Jika tabel `users` sudah diisi, login akan memakai data user tersebut.
