<?php

declare(strict_types=1);

require_once __DIR__ . '/jadwal_ui.php';
require_once __DIR__ . '/jadwal_jamaah.php';
require_once __DIR__ . '/operasional_audit.php';

function jadwal_handle_post(PDO $pdo, int $auditUserId, bool $jadwalPembimbingScope, int $pembimbingScopeId): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return false;
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'tambah_kegiatan') {
        jadwal_handle_tambah_kegiatan($pdo);
        return true;
    }
    if ($action === 'hapus_kegiatan') {
        jadwal_handle_hapus_kegiatan($pdo, $jadwalPembimbingScope);
        return true;
    }
    if ($action === 'tambah_jadwal') {
        jadwal_handle_tambah_jadwal($pdo, $auditUserId, $jadwalPembimbingScope, $pembimbingScopeId);
        return true;
    }
    if ($action === 'jamaah_waktu') {
        jadwal_handle_jamaah_waktu($pdo, $auditUserId, $jadwalPembimbingScope);
        return true;
    }
    if ($action === 'jamaah_buat_dasar') {
        jadwal_handle_jamaah_buat_dasar($pdo, $auditUserId, $jadwalPembimbingScope);
        return true;
    }
    if ($action === 'jamaah_pisah') {
        jadwal_handle_jamaah_pisah($pdo, $auditUserId, $jadwalPembimbingScope);
        return true;
    }

    return false;
}

function jadwal_handle_tambah_kegiatan(PDO $pdo): void
{
    ensure_kegiatan_kategori_column($pdo);
    $namaKegiatan = trim((string) ($_POST['nama_kegiatan'] ?? ''));
    $kategoriKegiatan = strtoupper(trim((string) ($_POST['kategori_kegiatan'] ?? 'TAALIM')));
    if (!in_array($kategoriKegiatan, ['JAMAAH', 'TAALIM'], true)) {
        $kategoriKegiatan = 'TAALIM';
    }
    if ($namaKegiatan === '') {
        set_flash('error', 'Nama kegiatan wajib diisi.');
        header('Location: ' . app_href('/jadwal/kegiatan.php'));
        exit;
    }
    $pdo->prepare('INSERT INTO kegiatan (nama_kegiatan, kategori_kegiatan, is_active) VALUES (:nama, :kat, 1)')
        ->execute(['nama' => $namaKegiatan, 'kat' => $kategoriKegiatan]);
    $newId = (int) $pdo->lastInsertId();
    set_flash('success', 'Kegiatan "' . $namaKegiatan . '" berhasil ditambahkan.');
    header('Location: ' . app_href('/jadwal/index.php?panel=jadwal&kegiatan_id=' . $newId));
    exit;
}

function jadwal_handle_hapus_kegiatan(PDO $pdo, bool $jadwalPembimbingScope): void
{
    if ($jadwalPembimbingScope) {
        set_flash('error', 'Hapus master kegiatan hanya untuk pengurus.');
        header('Location: ' . app_href('/jadwal/kegiatan.php'));
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        set_flash('error', 'ID kegiatan tidak valid.');
        header('Location: ' . app_href('/jadwal/kegiatan.php'));
        exit;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM jadwal_kegiatan WHERE kegiatan_id = :id');
    $st->execute(['id' => $id]);
    if ((int) $st->fetchColumn() > 0) {
        set_flash('error', 'Kegiatan masih dipakai di jadwal. Hapus slot jadwal terlebih dahulu.');
        header('Location: ' . app_href('/jadwal/kegiatan.php'));
        exit;
    }
    $stN = $pdo->prepare('SELECT nama_kegiatan FROM kegiatan WHERE id = :id LIMIT 1');
    $stN->execute(['id' => $id]);
    $nama = (string) ($stN->fetchColumn() ?: '');
    $pdo->prepare('DELETE FROM kegiatan WHERE id = :id')->execute(['id' => $id]);
    set_flash('success', 'Kegiatan "' . $nama . '" dihapus.');
    header('Location: ' . app_href('/jadwal/kegiatan.php'));
    exit;
}

function jadwal_handle_tambah_jadwal(PDO $pdo, int $auditUserId, bool $jadwalPembimbingScope, int $pembimbingScopeId): void
{
    $hari = [0 => 'Setiap Hari', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
    $tingkatanInput = $_POST['tingkatan'] ?? [];
    $hariInput = $_POST['hari_ke'] ?? [];
    $tingkatanDipilih = is_array($tingkatanInput) ? array_values(array_filter(array_map('trim', $tingkatanInput), static fn ($v): bool => $v !== '')) : [];
    $hariDipilih = is_array($hariInput) ? array_values(array_filter(array_map('intval', $hariInput), static fn ($v): bool => $v >= 0 && $v <= 7)) : [];

    if (!$tingkatanDipilih || !$hariDipilih) {
        set_flash('error', 'Pilih minimal 1 tingkatan dan 1 hari.');
        header('Location: ' . app_href('/jadwal/index.php?panel=jadwal'));
        exit;
    }

    $kegiatanId = (int) ($_POST['kegiatan_id'] ?? 0);
    if ($kegiatanId <= 0) {
        set_flash('error', 'Pilih kegiatan.');
        header('Location: ' . app_href('/jadwal/index.php?panel=jadwal'));
        exit;
    }

    $jamMulai = (string) ($_POST['jam_mulai'] ?? '00:00');
    $jamSelesai = (string) ($_POST['jam_selesai'] ?? '00:00');
    if (jadwal_norm_jam($jamSelesai) <= jadwal_norm_jam($jamMulai)) {
        set_flash('error', 'Jam selesai harus setelah jam mulai.');
        header('Location: ' . app_href('/jadwal/index.php?panel=jadwal'));
        exit;
    }

    $tempatJadwal = trim((string) ($_POST['tempat'] ?? ''));
    $tempatJadwal = $tempatJadwal !== '' ? $tempatJadwal : null;

    $pembimbingIdPost = (int) ($_POST['pembimbing_id'] ?? 0) ?: null;
    if ($jadwalPembimbingScope) {
        if ($pembimbingScopeId <= 0) {
            set_flash('error', 'Akun login belum terhubung ke data pembimbing.');
            header('Location: ' . app_href('/jadwal/index.php?panel=jadwal'));
            exit;
        }
        $pembimbingIdPost = $pembimbingScopeId;
    }

    foreach ($tingkatanDipilih as $tingkatan) {
        foreach ($hariDipilih as $hariKe) {
            $bentrok = jadwal_cek_bentrok($pdo, $tingkatan, $hariKe, $jamMulai, $jamSelesai);
            if ($bentrok !== null) {
                set_flash('error', jadwal_pesan_bentrok($bentrok, $hari));
                header('Location: ' . app_href('/jadwal/index.php?panel=jadwal'));
                exit;
            }
        }
    }

    $insert = $pdo->prepare('
        INSERT INTO jadwal_kegiatan (kegiatan_id, tingkatan, hari_ke, jam_mulai, jam_selesai, pembimbing_id, tempat)
        VALUES (:kegiatan_id, :tingkatan, :hari_ke, :jam_mulai, :jam_selesai, :pembimbing_id, :tempat)
    ');
    $created = 0;
    $createdIds = [];
    foreach ($tingkatanDipilih as $tingkatan) {
        foreach ($hariDipilih as $hariKe) {
            $insert->execute([
                'kegiatan_id' => $kegiatanId,
                'tingkatan' => $tingkatan,
                'hari_ke' => $hariKe,
                'jam_mulai' => $jamMulai,
                'jam_selesai' => $jamSelesai,
                'pembimbing_id' => $pembimbingIdPost,
                'tempat' => $tempatJadwal,
            ]);
            $newId = (int) $pdo->lastInsertId();
            if ($newId > 0) {
                $createdIds[] = $newId;
            }
            $created++;
        }
    }

    operasional_audit_log(
        $pdo,
        OPERASIONAL_AUDIT_MODUL_JADWAL,
        'CREATE',
        $createdIds[0] ?? 0,
        null,
        [
            'jumlah_baru' => $created,
            'jadwal_ids' => $createdIds,
            'kegiatan_id' => $kegiatanId,
        ],
        $auditUserId,
        'Penambahan jadwal (' . $created . ' baris)'
    );
    set_flash('success', 'Jadwal berhasil ditambahkan: ' . $created . ' slot.');
    header('Location: ' . app_href('/jadwal/index.php'));
    exit;
}

function jadwal_handle_jamaah_waktu(PDO $pdo, int $auditUserId, bool $jadwalPembimbingScope): void
{
    if ($jadwalPembimbingScope) {
        set_flash('error', 'Atur waktu jamaah hanya untuk pengurus.');
        header('Location: ' . app_href('/jadwal/index.php?tab=jamaah'));
        exit;
    }
    $kegiatanId = (int) ($_POST['kegiatan_id'] ?? 0);
    $kelompok = (string) ($_POST['kelompok'] ?? '');
    $jamMulai = trim((string) ($_POST['jam_mulai'] ?? ''));
    $jamSelesai = trim((string) ($_POST['jam_selesai'] ?? ''));
    $result = jadwal_jamaah_terapkan_waktu($pdo, $kegiatanId, $kelompok, $jamMulai, $jamSelesai, $auditUserId);
    set_flash($result['ok'] ? 'success' : 'error', (string) ($result['message'] ?? ''));
    header('Location: ' . app_href('/jadwal/index.php?tab=jamaah'));
    exit;
}

function jadwal_handle_jamaah_buat_dasar(PDO $pdo, int $auditUserId, bool $jadwalPembimbingScope): void
{
    if ($jadwalPembimbingScope) {
        set_flash('error', 'Atur waktu jamaah hanya untuk pengurus.');
        header('Location: ' . app_href('/jadwal/index.php?tab=jamaah'));
        exit;
    }
    $kegiatanId = (int) ($_POST['kegiatan_id'] ?? 0);
    $kelompok = (string) ($_POST['kelompok'] ?? '');
    $jamMulai = trim((string) ($_POST['jam_mulai'] ?? ''));
    $jamSelesai = trim((string) ($_POST['jam_selesai'] ?? ''));
    $result = jadwal_jamaah_buat_slot_dasar($pdo, $kegiatanId, $kelompok, $jamMulai, $jamSelesai, $auditUserId);
    set_flash($result['ok'] ? 'success' : 'error', (string) ($result['message'] ?? ''));
    header('Location: ' . app_href('/jadwal/index.php?tab=jamaah'));
    exit;
}

function jadwal_handle_jamaah_pisah(PDO $pdo, int $auditUserId, bool $jadwalPembimbingScope): void
{
    if ($jadwalPembimbingScope) {
        set_flash('error', 'Atur waktu jamaah hanya untuk pengurus.');
        header('Location: ' . app_href('/jadwal/index.php?tab=jamaah'));
        exit;
    }
    $kegiatanId = (int) ($_POST['kegiatan_id'] ?? 0);
    $result = jadwal_jamaah_pisah_semua_tingkatan(
        $pdo,
        $kegiatanId,
        trim((string) ($_POST['jam_mulai_putra'] ?? '')),
        trim((string) ($_POST['jam_selesai_putra'] ?? '')),
        trim((string) ($_POST['jam_mulai_putri'] ?? '')),
        trim((string) ($_POST['jam_selesai_putri'] ?? '')),
        $auditUserId
    );
    set_flash($result['ok'] ? 'success' : 'error', (string) ($result['message'] ?? ''));
    header('Location: ' . app_href('/jadwal/index.php?tab=jamaah'));
    exit;
}
