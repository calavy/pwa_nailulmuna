# Checklist §8 — Penataan UI modul Keuangan

| Item | Status |
|------|--------|
| Dashboard lebih ringkas, KPI utama di atas | Done (markup) |
| Rekap kas: toolbar, kartu, tabel scroll/sticky | Done (markup/CSS) |
| Rekap kas: tampilan ringkas + rincian POS vertikal | Done (markup/CSS) |
| Rekap kas: strip ringkasan 8 metrik, cetak = lengkap | Done |
| Nominal rata kanan (`.keu-money`) | Done |
| Awal Tahun + footer Total TA jelas | Done |
| Filter & tombol berfungsi (no logic change) | Manual QA |
| Responsif ~390px | Manual QA |
| Angka before/after sama (filter sama) | Manual + CLI diag |
| Tidak ubah transaksi/jurnal/DB | Review diff |
| Screenshot desktop + HP | Manual (lihat audit-notes) |
| `php -l` file PHP disentuh | CI lokal |

## File diubah (penataan lanjutan)

- `keuangan/rekap-kas-bulan.php`, `keuangan/partials/ui_rekap_toolbar.php`
- `helpers/keuangan_rekap_kas_bulan.php` (CSS render + markup tabel/blok akun)
- `assets/css/keuangan.css`
- `keuangan/index.php`
- `keuangan/neraca.php`, `keuangan/arus-kas.php`
- `pembayaran/rekap_pos.php`, `pembayaran/partials/tagihan_syahriyah_list_block.php`

## Diagnostik

```bash
php scripts/_diag_rekap_tagihan_reconcile.php
php scripts/_diag_rekap_kas_selisih.php
```

Deploy: allowlist SFTP only; jangan timpa DB produksi.
