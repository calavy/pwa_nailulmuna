<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/santri_operasional.php';
require_once __DIR__ . '/wali_portal.php';
require_once __DIR__ . '/perizinan_jenis.php';
require_once __DIR__ . '/perizinan_approval.php';
require_once __DIR__ . '/perizinan_syari_kategori.php';

function wali_perizinan_ensure_tables(PDO $pdo): void
{
    if (!table_exists($pdo, 'perizinan')) {
        return;
    }
    perizinan_approval_ensure_schema($pdo);
    perizinan_tujuan_ensure_schema($pdo);
    perizinan_syari_kategori_ensure_schema($pdo);
    $pdo->exec("ALTER TABLE perizinan
        ADD COLUMN IF NOT EXISTS jam_mulai TIME NULL,
        ADD COLUMN IF NOT EXISTS jam_selesai TIME NULL,
        ADD COLUMN IF NOT EXISTS durasi_jam DECIMAL(5,2) NULL,
        ADD COLUMN IF NOT EXISTS approval_status ENUM('PENDING','DISETUJUI','DITOLAK') NOT NULL DEFAULT 'PENDING',
        ADD COLUMN IF NOT EXISTS approved_by INT NULL,
        ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL,
        ADD COLUMN IF NOT EXISTS rejected_reason VARCHAR(255) NULL,
        ADD COLUMN IF NOT EXISTS grace_menit INT NOT NULL DEFAULT 15");
}

/** Jenis izin yang boleh diajukan wali lewat portal (hanya izin syar'i). */
function wali_perizinan_jenis_portal(): string
{
    return perizinan_jenis_syari_kode();
}

/**
 * @param list<int> $santriIds
 * @return list<array<string, mixed>>
 */
function wali_perizinan_list_for_santri(PDO $pdo, array $santriIds, int $limit = 40): array
{
    wali_perizinan_ensure_tables($pdo);
    $santriIds = array_values(array_unique(array_filter(array_map('intval', $santriIds), static fn(int $id): bool => $id > 0)));
    if ($santriIds === [] || !table_exists($pdo, 'perizinan')) {
        return [];
    }

    $limit = max(5, min(100, $limit));
    $ph = implode(',', array_fill(0, count($santriIds), '?'));
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $sql = '
        SELECT i.*, s.nis, s.' . $nameCol . ' AS nama_santri
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE i.santri_id IN (' . $ph . ')
        ORDER BY i.id DESC
        LIMIT ' . $limit;

    $st = $pdo->prepare($sql);
    $st->execute($santriIds);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{ok:bool,message:string}
 */
function wali_perizinan_ajukan(
    PDO $pdo,
    int $santriId,
    array $santriIdsAllowed,
    string $jenisIzin,
    string $tanggalMulai,
    string $tanggalSelesai,
    string $jamMulai,
    string $jamSelesai,
    float $durasiJam,
    string $syariKategori,
    string $alasan,
    string $tujuan,
    string $pemberiIzin
): array {
    wali_perizinan_ensure_tables($pdo);
    if (!table_exists($pdo, 'perizinan')) {
        return ['ok' => false, 'message' => 'Modul perizinan belum aktif di pondok.'];
    }

    $santriId = (int) $santriId;
    if ($santriId <= 0 || !in_array($santriId, $santriIdsAllowed, true)) {
        return ['ok' => false, 'message' => 'Data santri tidak valid untuk akun wali Anda.'];
    }

    $jenisIzin = perizinan_jenis_izin_normalize($jenisIzin);
    if ($jenisIzin !== wali_perizinan_jenis_portal()) {
        return ['ok' => false, 'message' => 'Portal wali hanya menerima pengajuan Izin.'];
    }

    $syariKategori = perizinan_syari_kategori_normalize_kode($pdo, $syariKategori);
    if ($syariKategori === '') {
        return ['ok' => false, 'message' => 'Pilih keperluan izin terlebih dahulu.'];
    }
    $kat = perizinan_syari_kategori_by_kode($pdo, $syariKategori);
    if (!$kat || empty($kat['enabled'])) {
        return ['ok' => false, 'message' => 'Keperluan izin tidak tersedia. Hubungi pengurus pondok.'];
    }

    if ($tanggalMulai === '') {
        return ['ok' => false, 'message' => 'Tanggal mulai wajib diisi.'];
    }
    if (strtotime($tanggalMulai) === false) {
        return ['ok' => false, 'message' => 'Tanggal mulai tidak valid.'];
    }

    $durasiHari = max(1, (int) ($kat['durasi_hari'] ?? 1));
    $tanggalSelesai = perizinan_syari_kategori_tanggal_selesai($tanggalMulai, $durasiHari);

    $jamMulai = trim($jamMulai) !== '' ? trim($jamMulai) : date('H:i');
    $jamSelesai = $jamMulai;

    $durasiErr = perizinan_syari_kategori_validasi_durasi($pdo, $syariKategori, $tanggalMulai, $tanggalSelesai);
    if ($durasiErr !== null) {
        return ['ok' => false, 'message' => $durasiErr];
    }

    $alasan = perizinan_syari_kategori_susun_alasan($pdo, $syariKategori, '');
    $pemberiIzin = trim($pemberiIzin);
    if ($pemberiIzin === '') {
        return ['ok' => false, 'message' => 'Nama pemohon wajib diisi.'];
    }
    $tujuan = perizinan_tujuan_normalize($tujuan);
    $tujuanErr = perizinan_validasi_tujuan($jenisIzin, $tujuan);
    if ($tujuanErr !== null) {
        return ['ok' => false, 'message' => $tujuanErr];
    }

    if ($tanggalMulai === '' || $tanggalSelesai === '') {
        return ['ok' => false, 'message' => 'Tanggal mulai dan selesai wajib diisi.'];
    }

    $chk = $pdo->prepare('SELECT 1 FROM santri s WHERE s.id = :id AND ' . santri_sql_aktif_only('s') . ' LIMIT 1');
    $chk->execute(['id' => $santriId]);
    if (!$chk->fetchColumn()) {
        return ['ok' => false, 'message' => 'Santri tidak aktif — tidak dapat mengajukan izin.'];
    }

    $pengasuh = trim((string) app_setting($pdo, 'nama_pengasuh', 'Pengasuh Pondok'));
    $grace = (int) app_setting($pdo, 'grace_period_menit', '15');

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('
            INSERT INTO perizinan (
                santri_id, jenis_izin, syari_kategori, tanggal_mulai, tanggal_selesai, jam_mulai, jam_selesai, durasi_jam,
                alasan, tujuan, pemberi_izin, penandatangan_pengasuh, status_izin, approval_status, grace_menit
            ) VALUES (
                :sid, :jenis, :kat, :tgl1, :tgl2, :jm1, :jm2, :durasi,
                :alasan, :tujuan, :pemohon, :pengasuh, "IZIN", "PENDING", :grace
            )
        ');
        $ins->execute([
            'sid' => $santriId,
            'jenis' => $jenisIzin,
            'kat' => $syariKategori,
            'tgl1' => $tanggalMulai,
            'tgl2' => $tanggalSelesai,
            'jm1' => $jamMulai !== '' ? $jamMulai : date('H:i'),
            'jm2' => $jamSelesai !== '' ? $jamSelesai : date('H:i'),
            'durasi' => $durasiJam > 0 ? $durasiJam : null,
            'alasan' => $alasan,
            'tujuan' => $tujuan !== '' ? $tujuan : null,
            'pemohon' => $pemberiIzin,
            'pengasuh' => $pengasuh,
            'grace' => $grace,
        ]);
        $izinId = (int) $pdo->lastInsertId();

        $pdo->commit();

        $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
        $stInfo = $pdo->prepare('SELECT ' . $nameCol . ' AS nama_santri, nis FROM santri WHERE id = :id LIMIT 1');
        $stInfo->execute(['id' => $santriId]);
        $sInfo = $stInfo->fetch(PDO::FETCH_ASSOC) ?: [];
        require_once __DIR__ . '/push_events.php';
        perizinan_push_setelah_pengajuan(
            $pdo,
            (string) ($sInfo['nama_santri'] ?? '-'),
            (string) ($sInfo['nis'] ?? ''),
            $jenisIzin,
            $tanggalMulai,
            $tanggalSelesai
        );

        $msg = 'Permohonan izin #' . $izinId . ' terkirim. Menunggu persetujuan pengasuh — setelah disetujui, pengurus tinggal cetak surat.';
        $alpaPortal = wali_perizinan_alpa_info_portal($pdo, $santriId, $tanggalMulai);
        if (!empty($alpaPortal['blocked'])) {
            $msg .= ' Catatan: santri terhalang syarat ALPA — pengasuh akan menilai permohonan ini.';
        }

        return [
            'ok' => true,
            'message' => $msg,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal mengirim permohonan: ' . $e->getMessage()];
    }
}

function wali_perizinan_status_badge(string $status): string
{
    return match (strtoupper(trim($status))) {
        'DISETUJUI' => 'success',
        'DITOLAK' => 'danger',
        default => 'warning',
    };
}

/**
 * Info syarat ALPA untuk tampilan portal wali (informasi saja — pengajuan tetap boleh).
 *
 * @return array{
 *   subject:bool,
 *   allowed:bool,
 *   blocked:bool,
 *   enabled:bool,
 *   alpa_count:int,
 *   max:int,
 *   hari:int,
 *   ringkasan:string,
 *   penjelasan:string,
 *   message:string
 * }
 */
function wali_perizinan_alpa_info_portal(PDO $pdo, int $santriId, ?string $refDate = null): array
{
    $base = [
        'subject' => false,
        'allowed' => true,
        'blocked' => false,
        'enabled' => false,
        'alpa_count' => 0,
        'max' => 0,
        'hari' => 0,
        'ringkasan' => '',
        'penjelasan' => '',
        'message' => '',
    ];
    if ($santriId <= 0) {
        return $base;
    }

    $ref = $refDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($refDate)) ? trim($refDate) : date('Y-m-d');
    $cfg = perizinan_alpa_settings($pdo);
    $base['enabled'] = !empty($cfg['enabled']);
    if (!$cfg['enabled']) {
        $base['penjelasan'] = 'Pembatasan ALPA untuk izin belum diaktifkan pengurus pondok.';
        return $base;
    }

    $cek = perizinan_alpa_cek_approval($pdo, $santriId, wali_perizinan_jenis_portal(), $ref);
    $blocked = !empty($cek['subject']) && empty($cek['allowed']);

    return [
        'subject' => !empty($cek['subject']),
        'allowed' => !empty($cek['allowed']),
        'blocked' => $blocked,
        'enabled' => true,
        'alpa_count' => (int) ($cek['alpa_count'] ?? 0),
        'max' => (int) ($cek['max'] ?? 0),
        'hari' => (int) ($cek['hari'] ?? 0),
        'ringkasan' => (string) ($cek['ringkasan'] ?? ''),
        'penjelasan' => perizinan_alpa_penjelasan_plain($cek),
        'message' => (string) ($cek['message'] ?? ''),
        'catatan' => (string) ($cek['catatan'] ?? ''),
    ];
}
