# Penjelasan keseluruhan modul Keuangan (rumus & tampilan)

Ringkasan untuk bendahara, pengurus, dan developer. Arsitektur teknis lengkap: [ALUR_KEUANGAN.md](ALUR_KEUANGAN.md). Operasional harian: [PANDUAN-KEUANGAN.md](PANDUAN-KEUANGAN.md). Versi interaktif: `/keuangan/panduan.php`.

---

## 1. Prinsip besar

| Jenis | Contoh | Arti |
|-------|--------|------|
| **Dihitung on-the-fly** | Tagihan `expected`, sisa per POS/bulan, alokasi virtual | Tidak ada tabel invoice tetap; rumus dari pengaturan TA + profil santri |
| **Disimpan di DB** | `keuangan_pembayaran` + detail, pengeluaran/pemasukan, jurnal, akun kas, ledger saku | Sumber kebenaran uang masuk/keluar dan saldo buku |

**Wajib lunas bulanan:** pos **syahriyah** (makan/saku bisa wajib di pengaturan tetapi tidak selalu menentukan status lunas global).

---

## 2. Rumus tagihan

### Per santri, per POS, per periode

Helper: `keuangan_tagihan_breakdown_for_santri` → `helpers/tagihan_santri_masuk.php`.

| Simbol | Rumus |
|--------|--------|
| **expected** | Tarif tier ± override bulan/kelas makan ± potongan + PKPPS; pos tidak berlaku = 0 |
| **paid** | SUM detail pembayaran untuk santri, TA, jenis_periode, bulan_tagihan, pos_slug |
| **sisa** | `max(0, expected − paid)` per POS |
| **paid (rekap agregat)** | `min(paid, expected)` per POS; kelebihan → `paid_lebih` |
| **sisa (agregat TA)** | `max(0, expected − paid_valid)` per blok — bukan selalu jumlah sisa per baris bulan |

**Periode:** `BULANAN` (bulan 1–12) dan `AWAL_TAHUN` (komponen awal TA terpisah).

### Agregat semua santri aktif

| Lapisan | Fungsi |
|---------|--------|
| Breakdown | `keuangan_tagihan_breakdown_for_santri` |
| Rekap POS | `keuangan_rekap_pos_with_expected` |
| Baris bulan + Awal Tahun | `keuangan_rekap_tagihan_bulanan_ta` |
| Kolom di rekap kas | `keuangan_rekap_kas_gabung_tagihan` |

Cache sesi ~600s; invalidasi: `keuangan_mutasi_cache_invalidate()` setelah mutasi.

---

## 3. Rumus kas & saldo

### Saldo kas operasional pondok

`keuangan_aruskas_total_kas($pdo, $asOf)` — per akun kas/bank aktif (1101/1102):

**Saldo = saldo awal akun + masuk − keluar** sampai tanggal tersebut.

Pembayaran **saku** → jurnal kas titipan (1103), **bukan** kas operasional pondok (view `pondok` di rekap kas).

### Rekap kas bulanan

Builder: `keuangan_build_rekap_kas_bulanan` — halaman `/keuangan/rekap-kas-bulan.php`.

| Kolom | Arti |
|-------|------|
| Masuk / Keluar / Bersih | Mutasi riil periode bulan tagihan |
| Saldo akhir (hitung) | Saldo awal + bersih (berjalan) |
| Saldo fisik | Total kas buku per akhir periode kalender |
| Selisih | Fisik − hitung |
| Tagihan / Terbayar / Sisa | Pipeline tagihan (+ baris **Awal Tahun (TA)**) |

**Footer total TA** = bulanan 1..N + Awal Tahun. Jumlah kolom Terbayar/Sisa **semua baris** (termasuk Awal Tahun) harus = footer.

**Tagihan ≠ uang masuk** — jangan samakan kolom tagihan dengan kolom masuk.

Diagnostik: `php scripts/_diag_rekap_tagihan_reconcile.php`, `php scripts/_diag_rekap_kas_selisih.php`.

---

## 4. Tampilan (peta layar)

| Area | URL |
|------|-----|
| Dashboard | `/keuangan/index.php` |
| Panduan | `/keuangan/panduan.php` |
| Pengaturan / bayar / keluar-masuk | `/keuangan/pengaturan.php`, `pembayaran.php`, `pengeluaran.php`, `pemasukan.php` |
| Tagihan & rekap POS | `/pembayaran/tagihan_syahriyah.php`, `rekap_pos.php`, `rekap_tagihan_santri.php` |
| Rekap kas bulanan | `/keuangan/rekap-kas-bulan.php` (`view=pondok` vs `full`, `?print=1`) |
| Neraca / arus kas | `/keuangan/neraca.php`, `arus-kas.php` |
| Portal wali | `/wali/keuangan.php` |
| Perbaikan selisih | `/keuangan/perbaikan-kas.php` |

### KPI dashboard (bulan berjalan)

Dari `tagihan_syahriyah_list_cached`:

- **totalTagihan** — expected syahriyah bulan ini (semua santri aktif)
- **totalTerbayar** — paid valid bulan ini
- **totalPiutang** — `max(0, totalTagihan − totalTerbayar)`
- **persenTertagih** — `totalTerbayar / totalTagihan × 100` jika tagihan > 0

Refresh cache: `?refresh=1` di dashboard.

---

## 5. Alur pembayaran (singkat)

1. Form `/keuangan/pembayaran.php` → `keuangan_save_pembayaran`
2. Header + detail per `pos_slug`; validasi urutan bulan, anti dobel, nominal ≤ sisa
3. Jurnal otomatis; pos saku → top-up cashless
4. `keuangan_mutasi_cache_invalidate()` → laporan berikutnya fresh

---

## 6. Kinerja sistem (cache)

- TTL ~600s: dashboard, neraca/arus kas cached, rekap POS, list tagihan
- Setelah simpan/hapus transaksi, cache di-invalid; jika stale: logout/login atau `?refresh=1`
- Schema ringan: `keuangan_ensure_schema_deferred()` saat halaman dibuka

---

## 7. Cara membaca angka (anti salah paham)

1. Tagihan tinggi di rekap kas **bukan** uang masuk bulan itu.
2. **Awal Tahun** masuk total footer TA; harus ada baris tabel sendiri.
3. View **pondok** mengecualikan saku dari total masuk pondok; **full** untuk audit titipan.
4. Selisih fisik vs hitung → gunakan perbaikan-kas dan diag selisih; jangan “sesuaikan” saldo agar cocok tagihan.
