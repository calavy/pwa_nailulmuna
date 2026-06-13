<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function perizinan_jenis_syari_kode(): string
{
    return 'SYARI';
}

/** @return list<string> */
function perizinan_jenis_izin_kodes(): array
{
    return ['SAKIT', 'KELUAR', 'TUGAS', 'PULANG', 'SYARI'];
}

/** @return array<string, string> kode => label tampilan */
function perizinan_jenis_izin_dropdown(): array
{
    return [
        'KELUAR' => 'Keluar',
        'SAKIT' => 'Sakit',
        'TUGAS' => 'Tugas',
        'SYARI' => 'Izin Syar\'i',
    ];
}

function perizinan_jenis_izin_normalize(string $jenis): string
{
    $j = strtoupper(trim($jenis));
    if ($j === 'PULANG') {
        return 'TUGAS';
    }

    return in_array($j, perizinan_jenis_izin_kodes(), true) ? $j : 'KELUAR';
}

function perizinan_jenis_izin_valid(string $jenis): bool
{
    return in_array(perizinan_jenis_izin_normalize($jenis), perizinan_jenis_izin_kodes(), true);
}

/** Hanya izin syar'i yang wajib persetujuan pengasuh; setelah itu pengurus hanya cetak surat. */
function perizinan_memerlukan_persetujuan_pengasuh(string $jenis): bool
{
    return perizinan_jenis_izin_normalize($jenis) === perizinan_jenis_syari_kode();
}

/**
 * @param array<string, mixed> $izin
 */
function perizinan_izin_menunggu_persetujuan_pengasuh(PDO $pdo, array $izin): bool
{
    if (!perizinan_memerlukan_persetujuan_pengasuh((string) ($izin['jenis_izin'] ?? ''))) {
        return false;
    }
    if (!column_exists($pdo, 'perizinan', 'pengasuh_approved_at')) {
        return false;
    }

    return trim((string) ($izin['pengasuh_approved_at'] ?? '')) === '';
}

function perizinan_jenis_ensure_enum(PDO $pdo): void
{
    if (!table_exists($pdo, 'perizinan')) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("ALTER TABLE perizinan MODIFY COLUMN jenis_izin ENUM('SAKIT','KELUAR','TUGAS','PULANG','SYARI') NOT NULL DEFAULT 'KELUAR'");
    } catch (Throwable $e) {
        /* kolom VARCHAR atau sudah diperbarui */
    }
}

function perizinan_jenis_label(string $jenis): string
{
    $kode = perizinan_jenis_izin_normalize($jenis);
    $map = perizinan_jenis_izin_dropdown();

    return $map[$kode] ?? ($jenis !== '' ? $jenis : 'Keluar');
}

/** Izin keluar, tugas, dan syar'i wajib isi tujuan. */
function perizinan_memerlukan_tujuan(string $jenis): bool
{
    return in_array(perizinan_jenis_izin_normalize($jenis), ['KELUAR', 'TUGAS', 'SYARI'], true);
}

function perizinan_tujuan_normalize(string $raw): string
{
    return mb_substr(trim($raw), 0, 255);
}

function perizinan_validasi_tujuan(string $jenis, string $tujuan): ?string
{
    if (!perizinan_memerlukan_tujuan($jenis)) {
        return null;
    }
    if (perizinan_tujuan_normalize($tujuan) === '') {
        return 'Tujuan wajib diisi untuk izin keluar, tugas, dan syar\'i.';
    }

    return null;
}

function perizinan_tujuan_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done || !table_exists($pdo, 'perizinan')) {
        return;
    }
    $done = true;
    if (!column_exists($pdo, 'perizinan', 'tujuan')) {
        try {
            $pdo->exec('ALTER TABLE perizinan ADD COLUMN tujuan VARCHAR(255) NULL DEFAULT NULL AFTER alasan');
        } catch (Throwable $e) {
            /* abaikan */
        }
    }
    if (table_exists($pdo, 'perizinan_rombongan_meta') && !column_exists($pdo, 'perizinan_rombongan_meta', 'tujuan')) {
        try {
            $pdo->exec('ALTER TABLE perizinan_rombongan_meta ADD COLUMN tujuan VARCHAR(255) NULL DEFAULT NULL AFTER alasan');
        } catch (Throwable $e) {
            /* abaikan */
        }
    }
}
