-- Foto profil akun pengguna aplikasi
ALTER TABLE users ADD COLUMN IF NOT EXISTS foto_profil VARCHAR(255) NULL DEFAULT NULL;
