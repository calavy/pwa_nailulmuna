# Bukti verifikasi teknis keuangan

**Dokumen [`ALUR_KEUANGAN.md`](ALUR_KEUANGAN.md) = pedoman, bukan bukti perbaikan.** Isi file ini dengan output nyata sebelum deploy produksi.

---

## 1. Audit kode (read-only)

**Commit HEAD saat audit lokal:** `1c67260c7abc6e3ea53672d45b718b664851e00b`

**Perubahan belum di-commit (hanya docs/UI link):** `DEPLOY-LIVE.md`, `docs/ALUR_KEUANGAN.md` (baru), stub pipeline/laporan, `keuangan/panduan.php`, `PANDUAN-KEUANGAN.md`.

**Kode PHP rekap (sudah di tree / main):**

| Klaim | Lokasi | Status audit |
|-------|--------|--------------|
| Baris Awal Tahun (TA) | `keuangan_rekap_kas_bulan_append_baris_awal_tahun()` dipanggil di `keuangan_build_rekap_kas_bulanan` | Ada |
| Footer label total TA | `keuangan_rekap_kas_bulan_render_tabel` tfoot | Ada |
| Subtotal rekonsiliasi | `keuangan_rekap_kas_tagihan_subtotals()` | Ada |
| Invalidasi cache mutasi | `keuangan_mutasi_cache_invalidate()` → `keuangan_rekap_tagihan_cache_invalidate` | Ada di `keuangan_transaksi.php` (3 path), `keuangan_pembayaran_admin.php` (update/hapus) |
| Diagnostik | `scripts/_diag_rekap_tagihan_reconcile.php`, `_diag_rekap_kas_selisih.php` | Ada |

**Pipeline §11 vs `pembayaran/rekap_pos.php`:**

- Rekap POS memanggil **`keuangan_rekap_pos_with_expected`** (tanpa cache sesi).
- Rekap kas bulanan / agregat TA memanggil **`keuangan_rekap_pos_with_expected_cached`** → data inti sama, TTL 600s di UI laporan POS cached path (`pembayaran/laporan.php` pakai cached).
- Keduanya memakai `keuangan_tagihan_breakdown_for_santri` di dalam `keuangan_rekap_pos_with_expected` — **satu sumber logika**, perbedaan hanya cache.

**Kas:** tidak ada diff lokal vs `origin/main` pada `keuangan_rekonsiliasi.php` / `keuangan_aruskas.php` pada audit ini.

---

## 2. Diagnostik lokal

Arsip output: [`docs/evidence/diag-local-2026-10-12.txt`](evidence/diag-local-2026-10-12.txt)

| Uji | Hasil lokal |
|-----|-------------|
| Selisih footer - jumlah baris UI (tagihan) | **Rp 0 OK** |
| Selisih total - subtotal tagihan | **Rp 0 OK** |
| Selisih fisik - hitung (TA akhir) | **0** |
| Awal Tahun `paid` | **Rp 0** (lokal tidak mereplikasi gejala ~Rp66 juta produksi) |

**Interpretasi:** mekanisme UI/cakupan baris + footer **konsisten di lokal**; bukti fix gejala produksi **belum** — perlu diag + UI di DB produksi.

---

## 3. Diagnostik produksi (WAJIB — isi manual)

Jalankan read-only di server / profil DB hosting (jangan buat transaksi uji):

```powershell
C:\xampp\php\php.exe scripts\_diag_rekap_tagihan_reconcile.php [tm] [ts] [bulan]
C:\xampp\php\php.exe scripts\_diag_rekap_kas_selisih.php
```

| Field | Produksi |
|-------|----------|
| Tanggal dijalankan | _isi_ |
| TA (tm/ts/bulan) | _isi_ |
| `Selisih footer - jumlah baris UI` | _isi (harus Rp 0 OK)_ |
| `awal_tahun.paid` | _isi_ |
| Selisih historis screenshot (~66 juta) vs `awal_tahun.paid` | _isi_ |
| Selisih kas fisik - hitung TA | _isi_ |
| Paste output / lampiran | _docs/evidence/diag-produksi-YYYYMMDD.txt_ |

---

## 4. UI produksi read-only (WAJIB — isi manual)

Halaman: `/keuangan/rekap-kas-bulan.php` (TA yang sama dengan screenshot masalah)

- [ ] Ada baris **Awal Tahun (TA)** jika `awal_tahun` expected/paid/sisa > 0
- [ ] Kartu **Terbayar tagihan** = footer kolom Terbayar
- [ ] Kartu **Sisa tagihan** = footer kolom Sisa
- [ ] Jumlah manual kolom Terbayar (semua baris) = footer
- [ ] Saldo kas: aritmetika tampilan masuk/keluar tidak regresi (bandingkan sebelum deploy)

---

## 5. Gate deploy

- [ ] Audit kode + diff PHP rilis direview (bukan hanya docs)
- [ ] Diagnostik **produksi** lulus (§3)
- [ ] UI produksi lulus (§4)
- [ ] Backup DB produksi
- [ ] `.github/workflows/deploy-production.yml` — `DEPLOY_FILES` sesuai commit
- [ ] **Database produksi tidak diimpor/ditimpa**

**Commit yang akan dirilis:** _isi hash setelah merge_

**Deploy dilakukan:** _tidak / tanggal / workflow run ID_

---

## 6. Catatan agent (lingkungan terbatas)

Diagnostik **produksi** dan cek **UI live** tidak dapat diselesaikan otomatis dari IDE (tanpa kredensial DB hosting / browser produksi). Todo plan §3–§4 menunggu pengisian manual di atas.
