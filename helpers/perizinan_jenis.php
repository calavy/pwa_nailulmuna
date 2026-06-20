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
        'SYARI' => 'Izin',
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

/** Hanya izin syar'i (pengajuan wali) yang wajib persetujuan pengasuh; setelah itu pengurus hanya cetak surat. */
function perizinan_memerlukan_persetujuan_pengasuh(string $jenis): bool
{
    return perizinan_jenis_izin_normalize($jenis) === perizinan_jenis_syari_kode();
}

/** Sakit, keluar, tugas: langsung disetujui + notifikasi WA; tanpa antrean persetujuan pengurus. */
function perizinan_langsung_disetujui_tanpa_persetujuan(string $jenis): bool
{
    return !perizinan_memerlukan_persetujuan_pengasuh($jenis);
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

/**
 * Konteks teks notifikasi otomatis saat izin disetujui — beda per jenis (sakit, keluar, izin, tugas).
 *
 * @return array{
 *   jenis_izin:string,
 *   label_alasan:string,
 *   judul_disetujui:string,
 *   judul_grup:string,
 *   judul_pengurus:string,
 *   instruksi_wali:string,
 *   judul_push_wali:string
 * }
 */
function perizinan_jenis_wa_disetujui_vars(string $jenis): array
{
    $kode = perizinan_jenis_izin_normalize($jenis);

    return match ($kode) {
        'SAKIT' => [
            'jenis_izin' => 'izin sakit',
            'label_alasan' => 'Alasan',
            'judul_disetujui' => 'Izin sakit santri binaan',
            'judul_grup' => '🤒 *Izin sakit disetujui*',
            'judul_pengurus' => '📄 *Izin sakit disetujui — siap cetak surat*',
            'instruksi_wali' => 'Mohon perhatikan kesehatan putra/putri Anda. Pastikan istirahat cukup dan segera hubungi pengasuh pondok bila kondisi tidak membaik.',
            'judul_push_wali' => 'Izin sakit anak disetujui',
        ],
        'KELUAR' => [
            'jenis_izin' => 'izin keluar',
            'label_alasan' => 'Keperluan',
            'judul_disetujui' => 'Izin keluar santri binaan',
            'judul_grup' => '🚪 *Izin keluar disetujui*',
            'judul_pengurus' => '📄 *Izin keluar disetujui — siap cetak surat*',
            'instruksi_wali' => 'Mohon putra/putri Anda menjaga adab, mematuhi tujuan izin, dan kembali tepat waktu sesuai surat izin yang berlaku.',
            'judul_push_wali' => 'Izin keluar anak disetujui',
        ],
        'SYARI' => [
            'jenis_izin' => 'izin',
            'label_alasan' => 'Keperluan',
            'judul_disetujui' => 'Izin santri binaan',
            'judul_grup' => '📋 *Izin disetujui*',
            'judul_pengurus' => '📄 *Izin disetujui — siap cetak surat*',
            'instruksi_wali' => 'Mohon putra/putri Anda kembali tepat waktu sesuai ketentuan pondok dan surat izin yang berlaku.',
            'judul_push_wali' => 'Izin anak disetujui',
        ],
        'TUGAS' => [
            'jenis_izin' => 'izin tugas',
            'label_alasan' => 'Keperluan',
            'judul_disetujui' => 'Izin tugas santri binaan',
            'judul_grup' => '📌 *Izin tugas disetujui*',
            'judul_pengurus' => '📄 *Izin tugas disetujui — siap cetak surat*',
            'instruksi_wali' => 'Mohon putra/putri Anda menyelesaikan tugas sesuai amanah pondok dan kembali tepat waktu.',
            'judul_push_wali' => 'Izin tugas anak disetujui',
        ],
        default => [
            'jenis_izin' => 'izin',
            'label_alasan' => 'Keperluan',
            'judul_disetujui' => 'Izin santri binaan',
            'judul_grup' => '📋 *Izin disetujui*',
            'judul_pengurus' => '📄 *Izin disetujui — siap cetak surat*',
            'instruksi_wali' => 'Mohon putra/putri Anda kembali tepat waktu sesuai ketentuan yang berlaku.',
            'judul_push_wali' => 'Izin anak disetujui',
        ],
    };
}

/** Label jenis untuk teks WA/laporan otomatis (frasa lengkap). */
function perizinan_jenis_wa_label(string $jenis): string
{
    return perizinan_jenis_wa_disetujui_vars($jenis)['jenis_izin'];
}

/** Label field isi permohonan di WA: sakit = Alasan; keluar/tugas/izin = Keperluan. */
function perizinan_jenis_wa_label_alasan(string $jenis): string
{
    return perizinan_jenis_wa_disetujui_vars($jenis)['label_alasan'];
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
