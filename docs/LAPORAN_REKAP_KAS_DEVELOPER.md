# Laporan developer — Perbaikan Rekap Kas Bulanan & Tagihan

## Akar masalah

**Total tagihan** di footer/kartu (`tagihan['total']`) menjumlah **tagihan bulanan 1..N** plus **Awal Tahun** (`keuangan_rekap_tagihan_bulanan_ta`).

**Kolom Terbayar/Sisa per baris** hanya diisi dari baris bulanan (`keuangan_rekap_kas_gabung_tagihan`), sehingga jumlah manual baris bulan ≠ footer. Ini selaras gejala screenshot (selisih terbayar ~Rp66 juta) **jika** nominal ≈ `awal_tahun.paid` di produksi — verifikasi dengan `scripts/_diag_rekap_tagihan_reconcile.php`.

Logika **saldo kas** tidak diubah (mutasi periode + saldo fisik per akun).

## Perubahan kode

| File | Perubahan |
|------|-----------|
| `helpers/keuangan_rekap_kas_bulan.php` | Baris **Awal Tahun (TA)**; label footer; helper append; CSS baris |
| `helpers/keuangan_rekap_tagihan_bulan.php` | `keuangan_rekap_kas_tagihan_subtotals`, `keuangan_mutasi_cache_invalidate` |
| `helpers/keuangan_transaksi.php` | Invalidasi cache rekap setelah simpan pembayaran/pemasukan/pengeluaran |
| `helpers/keuangan_pembayaran_admin.php` | Invalidasi cache setelah koreksi/hapus pembayaran |
| `keuangan/rekap-kas-bulan.php` | Penjelasan UI |
| `scripts/_diag_rekap_tagihan_reconcile.php` | Diagnostik rekonsiliasi |
| `.github/workflows/deploy-production.yml` | Deploy SFTP allowlist + lint |
| `docs/REKAP_TAGIHAN_PIPELINE.md` | Dokumentasi pipeline |

## Pengujian

1. `php -l` pada file allowlist deploy.
2. `php scripts/_diag_rekap_tagihan_reconcile.php` — selisih footer vs jumlah baris UI harus **0** jika baris Awal Tahun tampil.
3. `php scripts/_diag_rekap_kas_selisih.php` — saldo TA tidak berubah rumus.
4. Manual: simpan pembayaran → refresh rekap (tanpa menunggu TTL cache).
5. Smoke: dashboard, `pembayaran/rekap_pos.php`, arus kas, neraca.

## Deployment

- **Database produksi:** tidak diimpor/ditimpa.
- **Workflow:** GitHub Actions → `Deploy Production (SFTP allowlist)` — set secrets + environment `production`.
- Perbarui `DEPLOY_FILES` di workflow jika ada file tambahan pada rilis berikutnya.
- Runbook manual: `DEPLOY-LIVE.md`.

## Verifikasi produksi (read-only)

- [ ] Rekap kas: footer Terbayar/Sisa = jumlah baris (bulanan + baris Awal Tahun).
- [ ] Kartu mini-stat = footer.
- [ ] Saldo akhir kas konsisten dengan sebelum deploy (jika tidak ada transaksi baru).
- [ ] Log PHP / workflow sukses.

## Commit

Isi commit saat rilis: hash git + daftar file dari allowlist workflow.
