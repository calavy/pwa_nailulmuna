-- 2026-05-14 | Santri keluar: kolom kategori, penyelesaian keuangan, nomor surat
-- (Aplikasi juga menambahkan kolom otomatis lewat ensure_santri_keluar_columns jika belum ada.)

ALTER TABLE santri ADD COLUMN IF NOT EXISTS keluar_kategori VARCHAR(40) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS keluar_settled_at DATETIME NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS nomor_surat_keluar VARCHAR(180) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS nomor_surat_tanggungan VARCHAR(180) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS keluar_ringkasan_keuangan TEXT NULL;
