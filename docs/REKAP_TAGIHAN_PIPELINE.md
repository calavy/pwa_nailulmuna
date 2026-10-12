# Pipeline logika tagihan (satu sumber)

## Alur data

`santri aktif` → `keuangan_tagihan_breakdown_for_santri()` → agregat per POS → rekap.

| Lapisan | Fungsi | File |
|---------|--------|------|
| Expected + paid valid per santri/POS | `keuangan_tagihan_breakdown_for_santri` | `helpers/tagihan_santri_masuk.php` |
| Agregat POS + expected | `keuangan_rekap_pos_with_expected` | `helpers/keuangan_rekap.php` |
| Cache sesi (TTL 600s) | `keuangan_rekap_pos_with_expected_cached` | `helpers/keuangan_rekap.php` |
| Agregat periode BULANAN / AWAL_TAHUN | `keuangan_rekap_tagihan_agregat_pos` | `helpers/keuangan_rekap_tagihan_bulan.php` |
| Baris bulan + total TA | `keuangan_rekap_tagihan_bulanan_ta` | `helpers/keuangan_rekap_tagihan_bulan.php` |
| Gabung ke baris kas | `keuangan_rekap_kas_gabung_tagihan` + baris Awal Tahun | `helpers/keuangan_rekap_kas_bulan.php` |
| Laporan POS (UI terpisah) | memanggil cached expected/paid | `pembayaran/rekap_pos.php` |

## Definisi

- **expected:** kewajiban yang berlaku untuk santri dalam cakupan jenis periode + bulan tagihan + TA.
- **paid (rekap):** pembayaran valid, dibatasi `min(paid, expected)` per POS per santri (kelebihan → `paid_lebih`).
- **sisa (agregat):** `max(0, expected - paid)` per periode agregat; bukan selalu `sum(sisa per bulan)` jika ada kelebihan bayar.

## Invalidasi cache

Setelah mutasi pembayaran/pemasukan/pengeluaran: `keuangan_mutasi_cache_invalidate()` → rekap POS sesi + dashboard.

Pemanggil: `keuangan_save_pembayaran`, simpan pemasukan/pengeluaran, koreksi/hapus pembayaran admin.

## Diagnostik

```bash
php scripts/_diag_rekap_tagihan_reconcile.php [tm] [ts] [bulan]
php scripts/_diag_rekap_kas_selisih.php
```
