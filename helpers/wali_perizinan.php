<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/santri_operasional.php';
require_once __DIR__ . '/wali_portal.php';

function wali_perizinan_ensure_tables(PDO $pdo): void
{
    if (!table_exists($pdo, 'perizinan')) {
        return;
    }
    $pdo->exec("ALTER TABLE perizinan
        ADD COLUMN IF NOT EXISTS jenis_izin ENUM('SAKIT','KELUAR','TUGAS','PULANG') NOT NULL DEFAULT 'KELUAR',
        ADD COLUMN IF NOT EXISTS jam_mulai TIME NULL,
        ADD COLUMN IF NOT EXISTS jam_selesai TIME NULL,
        ADD COLUMN IF NOT EXISTS durasi_jam DECIMAL(5,2) NULL,
        ADD COLUMN IF NOT EXISTS approval_status ENUM('PENDING','DISETUJUI','DITOLAK') NOT NULL DEFAULT 'PENDING',
        ADD COLUMN IF NOT EXISTS approved_by INT NULL,
        ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL,
        ADD COLUMN IF NOT EXISTS rejected_reason VARCHAR(255) NULL,
        ADD COLUMN IF NOT EXISTS grace_menit INT NOT NULL DEFAULT 15");
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
    string $alasan,
    string $pemberiIzin,
    ?string $gejala = null,
    ?float $suhuTubuh = null
): array {
    wali_perizinan_ensure_tables($pdo);
    if (!table_exists($pdo, 'perizinan')) {
        return ['ok' => false, 'message' => 'Modul perizinan belum aktif di pondok.'];
    }

    $santriId = (int) $santriId;
    if ($santriId <= 0 || !in_array($santriId, $santriIdsAllowed, true)) {
        return ['ok' => false, 'message' => 'Data santri tidak valid untuk akun wali Anda.'];
    }

    $jenisIzin = strtoupper(trim($jenisIzin));
    if (!in_array($jenisIzin, ['SAKIT', 'KELUAR', 'PULANG'], true)) {
        $jenisIzin = 'KELUAR';
    }

    $alasan = trim($alasan);
    $pemberiIzin = trim($pemberiIzin);
    if ($alasan === '') {
        return ['ok' => false, 'message' => 'Alasan izin wajib diisi.'];
    }
    if ($pemberiIzin === '') {
        return ['ok' => false, 'message' => 'Nama pemohon wajib diisi.'];
    }

    if ($tanggalMulai === '' || $tanggalSelesai === '') {
        return ['ok' => false, 'message' => 'Tanggal mulai dan selesai wajib diisi.'];
    }

    $chk = $pdo->prepare('SELECT 1 FROM santri s WHERE s.id = :id AND ' . santri_sql_aktif_only('s') . ' LIMIT 1');
    $chk->execute(['id' => $santriId]);
    if (!$chk->fetchColumn()) {
        return ['ok' => false, 'message' => 'Santri tidak aktif — tidak dapat mengajukan izin.'];
    }

    if ($jenisIzin === 'SAKIT') {
        if (trim((string) $gejala) === '') {
            return ['ok' => false, 'message' => 'Untuk izin sakit, gejala wajib diisi.'];
        }
        if ($suhuTubuh === null || $suhuTubuh <= 0) {
            return ['ok' => false, 'message' => 'Untuk izin sakit, suhu tubuh wajib diisi.'];
        }
    }

    $pengasuh = trim((string) app_setting($pdo, 'nama_pengasuh', 'Pengasuh Pondok'));
    $grace = (int) app_setting($pdo, 'grace_period_menit', '15');

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('
            INSERT INTO perizinan (
                santri_id, jenis_izin, tanggal_mulai, tanggal_selesai, jam_mulai, jam_selesai, durasi_jam,
                alasan, pemberi_izin, penandatangan_pengasuh, status_izin, approval_status, grace_menit
            ) VALUES (
                :sid, :jenis, :tgl1, :tgl2, :jm1, :jm2, :durasi,
                :alasan, :pemohon, :pengasuh, "IZIN", "PENDING", :grace
            )
        ');
        $ins->execute([
            'sid' => $santriId,
            'jenis' => $jenisIzin,
            'tgl1' => $tanggalMulai,
            'tgl2' => $tanggalSelesai,
            'jm1' => $jamMulai !== '' ? $jamMulai : date('H:i'),
            'jm2' => $jamSelesai !== '' ? $jamSelesai : date('H:i'),
            'durasi' => $durasiJam > 0 ? $durasiJam : null,
            'alasan' => $alasan,
            'pemohon' => $pemberiIzin,
            'pengasuh' => $pengasuh,
            'grace' => $grace,
        ]);
        $izinId = (int) $pdo->lastInsertId();

        if ($jenisIzin === 'SAKIT' && table_exists($pdo, 'ehealth_records')) {
            $pdo->exec('
                CREATE TABLE IF NOT EXISTS ehealth_records (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    santri_id INT NOT NULL,
                    gejala TEXT NOT NULL,
                    suhu_tubuh DECIMAL(4,1) NULL,
                    tindakan TEXT NULL,
                    status_kesehatan ENUM("RAWAT_PONDOK","DIRUJUK_RS","ISOLASI","SELESAI") NOT NULL DEFAULT "RAWAT_PONDOK",
                    notifikasi_wali TINYINT(1) NOT NULL DEFAULT 0,
                    created_by INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
                )
            ');
            $pdo->prepare('
                INSERT INTO ehealth_records (santri_id, gejala, suhu_tubuh, tindakan, status_kesehatan, notifikasi_wali, created_by)
                VALUES (:sid, :gejala, :suhu, NULL, "RAWAT_PONDOK", 0, NULL)
            ')->execute([
                'sid' => $santriId,
                'gejala' => trim((string) $gejala),
                'suhu' => $suhuTubuh,
            ]);
        }

        $pdo->commit();

        return [
            'ok' => true,
            'message' => 'Permohonan izin #' . $izinId . ' terkirim. Menunggu persetujuan pengurus pondok.',
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
