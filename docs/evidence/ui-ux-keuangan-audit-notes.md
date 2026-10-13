# UI/UX Keuangan — catatan audit

Tanggal pembaruan: 2026-10-13

## Sebelum / sesudah

Screenshot **before** (tabel POS penuh + 8 mini-stat) dan **after** (ringkas sekali lihat):

| Halaman | 1366×768 | 1600×900 | Mobile (~390px) |
|---------|----------|----------|-----------------|
| `/keuangan/rekap-kas-bulan.php` (default ringkas) | Ambil manual | Ambil manual | Ambil manual |
| `/keuangan/rekap-kas-bulan.php?detail=1` | Ambil manual | Ambil manual | Ambil manual |
| `/keuangan/index.php` | — | — | Ambil manual |

Binary screenshot tidak disimpan di repo; lampirkan saat review deploy.

## Rekap Kas — Rincian per POS vertikal (2026-10-13)

**File diubah:** `helpers/keuangan_rekap_tagihan_bulan.php` (`keuangan_rekap_tagihan_matrix_by_pos`), `helpers/keuangan_rekap_kas_bulan.php` (render POS + rekonsiliasi), `keuangan/rekap-kas-bulan.php`, `keuangan/partials/ui_rekap_toolbar.php`, `assets/css/keuangan.css`.

**Perilaku:**

- Atas: ringkasan strip + tabel rekap inti (ringkas).
- Bawah: tabel terpisah per POS (tagihan + kas masuk/keluar + **Netto** = masuk − keluar per bulan, informatif bukan saldo), blok Awal Tahun per komponen (kas masuk per slug; kas keluar agregat di subtotal; Netto baris `—`, subtotal = masuk slug − keluar AT TA), Donasi/Lain (kas-only), nav anchor, panel Rekonsiliasi Total TA.
- `?detail=1` → redirect ke `#rekap-rincian-pos` (bookmark lama).
- Cetak/PDF: inti + semua section POS vertikal (`print=1`, tanpa `detail`).
- Tagihan per POS: `keuangan_rekap_pos_with_expected_cached` (sama dengan rekap POS); mutasi dari baris rekap existing.

## Rekap Kas — Sekali lihat (2026-10-13)

**Perilaku (masih berlaku):** tabel inti ringkas, strip 8 metrik; saldo akun di `<details>` bawah tabel inti.

**Checklist uji (§F instruksi final):**

- [ ] 1366×768 & 1600×900: ringkasan + tabel ringkas tanpa scroll horizontal
- [ ] Toggle «Lihat rincian lengkap» / «Kembali ke ringkas» + filter/view tetap
- [ ] Footer Total TA & strip ringkasan = nilai sama sebelum/sesudah (filter sama)
- [ ] `php -l` OK pada file di atas (2026-10-13: OK)

## Perubahan lanjutan (instruksi penataan)

- Rekap kas: toolbar terpisah (view / filter / aksi sekunder), kartu 2 kolom HP, thead sticky + kolom Bulan & Saldo awal freeze, footer Total TA menonjol.
- Dashboard: 5 KPI prioritas (+ `total_terbayar`), likuid operasional, indikator tambahan di `<details>`.
- Neraca & arus kas: `ui_page_header` + filter bar konsisten.

## Regresi angka

CLI (read-only):

- `php scripts/_diag_rekap_tagihan_reconcile.php`
- `php scripts/_diag_rekap_kas_selisih.php`

Bandingskan KPI dashboard & footer rekap dengan filter TA/bulan yang sama sebelum deploy produksi.
