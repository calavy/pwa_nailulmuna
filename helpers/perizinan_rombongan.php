<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/santri_operasional.php';
require_once __DIR__ . '/santri_list_sort.php';

function perizinan_rombongan_ensure_schema(PDO $pdo): void
{
    if (!table_exists($pdo, 'perizinan')) {
        return;
    }
    require_once __DIR__ . '/perizinan_jenis.php';
    perizinan_tujuan_ensure_schema($pdo);
    if (!column_exists($pdo, 'perizinan', 'rombongan_id')) {
        $pdo->exec('ALTER TABLE perizinan ADD COLUMN rombongan_id INT UNSIGNED NULL AFTER santri_id');
    }
    if (!column_exists($pdo, 'perizinan', 'rombongan_kembali')) {
        $pdo->exec('ALTER TABLE perizinan ADD COLUMN rombongan_kembali TINYINT(1) NOT NULL DEFAULT 0 AFTER rombongan_id');
    }
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS perizinan_rombongan_meta (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            jenis_izin VARCHAR(20) NOT NULL DEFAULT "KELUAR",
            tanggal_mulai DATE NOT NULL,
            tanggal_selesai DATE NOT NULL,
            jam_mulai TIME NULL,
            jam_selesai TIME NULL,
            durasi_jam DECIMAL(5,2) NULL,
            alasan TEXT NOT NULL,
            pemberi_izin VARCHAR(100) NOT NULL,
            penandatangan_pengasuh VARCHAR(100) NOT NULL,
            approval_status ENUM("PENDING","DISETUJUI","DITOLAK") NOT NULL DEFAULT "PENDING",
            approved_by INT NULL,
            approved_at DATETIME NULL,
            rejected_reason VARCHAR(255) NULL,
            qr_token VARCHAR(120) NULL,
            waktu_keluar DATETIME NULL,
            grace_menit INT NOT NULL DEFAULT 15,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rombongan_approval (approval_status, tanggal_mulai)
        )
    ');
}

/**
 * @param list<int> $santriIds
 * @return array{ok:bool,message:string,rombongan_id?:int}
 */
function perizinan_rombongan_create(PDO $pdo, array $post, array $santriIds, int $userId): array
{
    perizinan_rombongan_ensure_schema($pdo);
    $santriIds = array_values(array_unique(array_filter(array_map('intval', $santriIds))));
    if (count($santriIds) < 2) {
        return ['ok' => false, 'message' => 'Izin rombongan minimal 2 santri.'];
    }

    require_once __DIR__ . '/perizinan_jenis.php';
    $jenisIzin = perizinan_jenis_izin_normalize((string) ($post['jenis_izin'] ?? 'KELUAR'));
    if ($jenisIzin === 'SAKIT') {
        return ['ok' => false, 'message' => 'Izin rombongan tidak untuk jenis sakit — gunakan izin per santri + E-Health.'];
    }

    $tglMulai = trim((string) ($post['tanggal_mulai'] ?? date('Y-m-d')));
    $tglSelesai = trim((string) ($post['tanggal_selesai'] ?? date('Y-m-d')));
    $jamMulai = trim((string) ($post['jam_mulai'] ?? date('H:i')));
    $jamSelesai = trim((string) ($post['jam_selesai'] ?? date('H:i')));
    $alasan = trim((string) ($post['alasan'] ?? ''));
    $tujuan = perizinan_tujuan_normalize((string) ($post['tujuan'] ?? ''));
    $pemberi = trim((string) ($post['pemberi_izin'] ?? ''));
    $pengasuh = trim((string) ($post['penandatangan_pengasuh'] ?? ''));
    $grace = max(0, (int) ($post['grace_menit'] ?? app_setting($pdo, 'grace_period_menit', '15')));

    if ($alasan === '' || $pemberi === '' || $pengasuh === '') {
        return ['ok' => false, 'message' => 'Alasan, pemberi izin, dan pengasuh wajib diisi.'];
    }
    $tujuanErr = perizinan_validasi_tujuan($jenisIzin, $tujuan);
    if ($tujuanErr !== null) {
        return ['ok' => false, 'message' => $tujuanErr];
    }

    foreach ($santriIds as $sid) {
        $chk = $pdo->prepare('SELECT 1 FROM santri s WHERE s.id = :id AND ' . santri_sql_aktif_only('s') . ' LIMIT 1');
        $chk->execute(['id' => $sid]);
        if (!$chk->fetchColumn()) {
            return ['ok' => false, 'message' => 'Santri ID ' . $sid . ' tidak aktif.'];
        }
    }

    $insMeta = $pdo->prepare('
        INSERT INTO perizinan_rombongan_meta
        (jenis_izin, tanggal_mulai, tanggal_selesai, jam_mulai, jam_selesai, durasi_jam, alasan, tujuan, pemberi_izin, penandatangan_pengasuh, grace_menit)
        VALUES (:jenis, :tgl1, :tgl2, :jm1, :jm2, :dur, :alasan, :tujuan, :pemberi, :pengasuh, :grace)
    ');
    $durasi = (float) ($post['durasi_jam'] ?? 0);
    $pdo->beginTransaction();
    try {
        $insMeta->execute([
            'jenis' => $jenisIzin,
            'tgl1' => $tglMulai,
            'tgl2' => $tglSelesai,
            'jm1' => $jamMulai,
            'jm2' => $jamSelesai,
            'dur' => $durasi,
            'alasan' => $alasan,
            'tujuan' => $tujuan !== '' ? $tujuan : null,
            'pemberi' => $pemberi,
            'pengasuh' => $pengasuh,
            'grace' => $grace,
        ]);
        $rombonganId = (int) $pdo->lastInsertId();

        $insIzin = $pdo->prepare('
            INSERT INTO perizinan
            (santri_id, rombongan_id, jenis_izin, tanggal_mulai, tanggal_selesai, jam_mulai, jam_selesai, durasi_jam,
             alasan, tujuan, pemberi_izin, penandatangan_pengasuh, status_izin, approval_status, grace_menit)
            VALUES (:sid, :rid, :jenis, :tgl1, :tgl2, :jm1, :jm2, :dur, :alasan, :tujuan, :pemberi, :pengasuh, "IZIN", "PENDING", :grace)
        ');
        foreach ($santriIds as $sid) {
            $insIzin->execute([
                'sid' => $sid,
                'rid' => $rombonganId,
                'jenis' => $jenisIzin,
                'tgl1' => $tglMulai,
                'tgl2' => $tglSelesai,
                'jm1' => $jamMulai,
                'jm2' => $jamSelesai,
                'dur' => $durasi,
                'alasan' => $alasan,
                'tujuan' => $tujuan !== '' ? $tujuan : null,
                'pemberi' => $pemberi,
                'pengasuh' => $pengasuh,
                'grace' => $grace,
            ]);
        }
        $pdo->commit();

        return ['ok' => true, 'message' => 'Izin rombongan (' . count($santriIds) . ' santri) menunggu persetujuan.', 'rombongan_id' => $rombonganId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menyimpan izin rombongan.'];
    }
}

/** @return array<string, mixed>|null */
function perizinan_rombongan_meta(PDO $pdo, int $rombonganId): ?array
{
    perizinan_rombongan_ensure_schema($pdo);
    if ($rombonganId <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM perizinan_rombongan_meta WHERE id = :id LIMIT 1');
    $st->execute(['id' => $rombonganId]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** Urutan standar rombongan: tingkatan (master urutan) lalu NIS. */
function perizinan_rombongan_order_sql(string $alias = 's', ?PDO $pdo = null): string
{
    if ($pdo === null) {
        global $pdo;
    }
    $nis = santri_list_sort_col($alias, 'nis');
    $nama = santri_list_sort_col($alias, santri_list_nama_db_column($pdo));
    $nisOrder = "CAST({$nis} AS UNSIGNED) ASC, LENGTH({$nis}) ASC, {$nis} ASC";

    return santri_list_tingkatan_order_expr($alias, $pdo) . ", {$nisOrder}, {$nama} ASC";
}

/**
 * Kelompokkan baris santri per tingkatan (kunci sudah urut tingkatan).
 *
 * @param list<array<string, mixed>> $rows
 * @return array<string, list<array<string, mixed>>>
 */
function perizinan_rombongan_group_by_tingkatan(array $rows): array
{
    $grouped = [];
    foreach ($rows as $r) {
        $tk = trim((string) ($r['tingkatan'] ?? '')) ?: '—';
        if (!isset($grouped[$tk])) {
            $grouped[$tk] = [];
        }
        $grouped[$tk][] = $r;
    }
    uksort($grouped, static function (string $a, string $b): int {
        $ra = santri_list_tingkatan_rank($a);
        $rb = santri_list_tingkatan_rank($b);
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }

        return strcasecmp($a, $b);
    });

    return $grouped;
}

/** Daftar santri aktif untuk form pilih rombongan (urut tingkatan + NIS). */
function perizinan_rombongan_santri_aktif_grouped(PDO $pdo): array
{
    if (!table_exists($pdo, 'santri')) {
        return [];
    }
    $namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $aktif = santri_sql_aktif_only('s');
    $st = $pdo->query('
        SELECT s.id, s.' . $namaCol . ' AS nama_santri, s.nis, s.tingkatan
        FROM santri s
        WHERE ' . $aktif . '
        ORDER BY ' . perizinan_rombongan_order_sql('s', $pdo) . '
    ');
    $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    return perizinan_rombongan_group_by_tingkatan($rows);
}

/** @return list<array<string, mixed>> */
function perizinan_rombongan_anggota(PDO $pdo, int $rombonganId): array
{
    perizinan_rombongan_ensure_schema($pdo);
    if ($rombonganId <= 0 || !table_exists($pdo, 'santri')) {
        return [];
    }
    $namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $st = $pdo->prepare('
        SELECT i.id, i.santri_id, i.rombongan_kembali, i.waktu_kembali, i.status_izin, i.approval_status,
               s.' . $namaCol . ' AS nama_santri, s.nis, s.tingkatan
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE i.rombongan_id = :rid
        ORDER BY ' . perizinan_rombongan_order_sql('s', $pdo) . '
    ');
    $st->execute(['rid' => $rombonganId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string, list<array<string, mixed>>> */
function perizinan_rombongan_anggota_grouped(PDO $pdo, int $rombonganId): array
{
    return perizinan_rombongan_group_by_tingkatan(perizinan_rombongan_anggota($pdo, $rombonganId));
}

function perizinan_rombongan_by_qr(PDO $pdo, string $qrToken): ?array
{
    $qr = trim($qrToken);
    if ($qr === '') {
        return null;
    }
    perizinan_rombongan_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT * FROM perizinan_rombongan_meta
        WHERE qr_token = :qr AND approval_status = "DISETUJUI"
        LIMIT 1
    ');
    $st->execute(['qr' => $qr]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * Setujui rombongan: satu QR untuk semua anggota.
 *
 * @return array{ok:bool,message:string}
 */
function perizinan_rombongan_approve(PDO $pdo, int $rombonganId, array $post, int $userId, bool $bypassAlpa = false, bool $stampPengasuh = false): array
{
    require_once __DIR__ . '/perizinan_approval.php';
    perizinan_approval_ensure_schema($pdo);

    $meta = perizinan_rombongan_meta($pdo, $rombonganId);
    if (!$meta) {
        return ['ok' => false, 'message' => 'Data rombongan tidak ditemukan.'];
    }

    $jenisIzin = strtoupper((string) ($meta['jenis_izin'] ?? 'KELUAR'));
    if ($stampPengasuh && !perizinan_memerlukan_persetujuan_pengasuh($jenisIzin)) {
        return ['ok' => false, 'message' => 'Hanya izin syar\'i rombongan yang dapat disetujui pengasuh.'];
    }
    $anggota = perizinan_rombongan_anggota($pdo, $rombonganId);
    foreach ($anggota as $ang) {
        $sid = (int) ($ang['santri_id'] ?? 0);
        $alpaErr = perizinan_validasi_setujui_alpa($pdo, $sid, $jenisIzin, $bypassAlpa, $stampPengasuh);
        if ($alpaErr !== null) {
            $nama = (string) ($ang['nama_santri'] ?? 'Santri #' . $sid);

            return ['ok' => false, 'message' => $nama . ': ' . $alpaErr];
        }
    }
    if (!$stampPengasuh && perizinan_memerlukan_persetujuan_pengasuh($jenisIzin) && column_exists($pdo, 'perizinan', 'pengasuh_approved_at')) {
        $stPengasuh = $pdo->prepare('
            SELECT COUNT(*) FROM perizinan
            WHERE rombongan_id = :rid AND approval_status = "PENDING" AND pengasuh_approved_at IS NULL
        ');
        $stPengasuh->execute(['rid' => $rombonganId]);
        if ((int) ($stPengasuh->fetchColumn() ?: 0) > 0) {
            return ['ok' => false, 'message' => 'Izin syar\'i rombongan belum disetujui pengasuh. Minta pengasuh meninjau terlebih dahulu.'];
        }
    }

    $qrToken = trim((string) ($meta['qr_token'] ?? ''));
    if ($qrToken === '') {
        $qrToken = bin2hex(random_bytes(16));
    }
    $tglMulai = trim((string) ($post['tanggal_mulai'] ?? $meta['tanggal_mulai'] ?? ''));
    $tglSelesai = trim((string) ($post['tanggal_selesai'] ?? $meta['tanggal_selesai'] ?? ''));
    $jamMulai = trim((string) ($post['jam_mulai'] ?? substr((string) ($meta['jam_mulai'] ?? ''), 0, 5)));
    $jamSelesai = trim((string) ($post['jam_selesai'] ?? substr((string) ($meta['jam_selesai'] ?? ''), 0, 5)));
    $pengasuhSql = $stampPengasuh ? ', pengasuh_approved_by = :uid, pengasuh_approved_at = NOW()' : '';

    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            UPDATE perizinan_rombongan_meta
            SET approval_status = "DISETUJUI", approved_by = :uid, approved_at = NOW(),
                qr_token = :qr, tanggal_mulai = :t1, tanggal_selesai = :t2, jam_mulai = :j1, jam_selesai = :j2
            WHERE id = :id
        ')->execute([
            'uid' => $userId,
            'qr' => $qrToken,
            't1' => $tglMulai,
            't2' => $tglSelesai,
            'j1' => $jamMulai,
            'j2' => $jamSelesai,
            'id' => $rombonganId,
        ]);
        $pdo->prepare('
            UPDATE perizinan
            SET approval_status = "DISETUJUI", approved_by = :uid, approved_at = NOW(),
                approved_bypass_alpa = :bypass,
                qr_token = :qr, status_izin = "IZIN",
                tanggal_mulai = :t1, tanggal_selesai = :t2, jam_mulai = :j1, jam_selesai = :j2' . $pengasuhSql . '
            WHERE rombongan_id = :rid
        ')->execute([
            'uid' => $userId,
            'bypass' => $bypassAlpa ? 1 : 0,
            'qr' => $qrToken,
            't1' => $tglMulai,
            't2' => $tglSelesai,
            'j1' => $jamMulai,
            'j2' => $jamSelesai,
            'rid' => $rombonganId,
        ]);
        $pdo->prepare('
            UPDATE santri s
            INNER JOIN perizinan i ON i.santri_id = s.id
            SET s.is_aktif = 0
            WHERE i.rombongan_id = :rid
        ')->execute(['rid' => $rombonganId]);
        $pdo->commit();

        $alasanMeta = (string) ($meta['alasan'] ?? '');
        $waRingkasan = perizinan_kirim_wa_rombongan_disetujui(
            $pdo,
            $anggota,
            $jenisIzin,
            $alasanMeta,
            $tglMulai,
            $tglSelesai,
            $jamMulai,
            $jamSelesai,
            $userId
        );
        $msg = $stampPengasuh
            ? 'Izin syar\'i rombongan disetujui pengasuh (' . count($anggota) . ' santri). Pengurus tinggal cetak surat rombongan.'
            : 'Izin rombongan disetujui. Satu QR/surat untuk semua anggota.';
        if ($bypassAlpa) {
            $msg .= ' (Syarat ALPA dilewati.)';
        }
        $msg .= perizinan_wa_flash_kirim_disetujui($waRingkasan);

        return ['ok' => true, 'message' => $msg];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menyetujui izin rombongan.'];
    }
}

/**
 * Centang santri yang sudah kembali; jika semua kembali, tutup rombongan.
 *
 * @param list<int> $santriKembaliIds
 */
function perizinan_rombongan_proses_kembali(PDO $pdo, int $rombonganId, array $santriKembaliIds, int $userId): array
{
    $meta = perizinan_rombongan_meta($pdo, $rombonganId);
    if (!$meta || (string) ($meta['approval_status'] ?? '') !== 'DISETUJUI') {
        return ['ok' => false, 'message' => 'Izin rombongan tidak valid.'];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $santriKembaliIds))));
    if ($ids === []) {
        return ['ok' => false, 'message' => 'Centang minimal satu santri yang sudah kembali.'];
    }

    $anggota = perizinan_rombongan_anggota($pdo, $rombonganId);
    $validIds = array_map(static fn(array $a): int => (int) ($a['santri_id'] ?? 0), $anggota);

    $pdo->beginTransaction();
    try {
        foreach ($ids as $sid) {
            if (!in_array($sid, $validIds, true)) {
                continue;
            }
            $pdo->prepare('
                UPDATE perizinan
                SET rombongan_kembali = 1, waktu_kembali = NOW(), status_izin = "KEMBALI"
                WHERE rombongan_id = :rid AND santri_id = :sid
            ')->execute(['rid' => $rombonganId, 'sid' => $sid]);
            $pdo->prepare('UPDATE santri SET is_aktif = 1 WHERE id = :sid')->execute(['sid' => $sid]);
        }
        $belum = $pdo->prepare('SELECT COUNT(*) FROM perizinan WHERE rombongan_id = :rid AND rombongan_kembali = 0');
        $belum->execute(['rid' => $rombonganId]);
        if ((int) $belum->fetchColumn() === 0) {
            $pdo->prepare('UPDATE perizinan_rombongan_meta SET waktu_keluar = COALESCE(waktu_keluar, NOW()) WHERE id = :id')->execute(['id' => $rombonganId]);
        }
        $pdo->commit();
        $n = count($ids);

        require_once __DIR__ . '/perizinan_approval.php';
        foreach ($ids as $sid) {
            if (!in_array($sid, $validIds, true)) {
                continue;
            }
            $stIz = $pdo->prepare('SELECT id FROM perizinan WHERE rombongan_id = :rid AND santri_id = :sid LIMIT 1');
            $stIz->execute(['rid' => $rombonganId, 'sid' => $sid]);
            $izinId = (int) ($stIz->fetchColumn() ?: 0);
            if ($izinId > 0) {
                perizinan_kirim_wa_pengurus_izin_selesai($pdo, $izinId);
            }
        }

        return ['ok' => true, 'message' => $n . ' santri dicatat kembali ke asrama.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal mencatat kembali.'];
    }
}

function perizinan_rombongan_scan_checkout(PDO $pdo, int $rombonganId): void
{
    $pdo->prepare('UPDATE perizinan_rombongan_meta SET waktu_keluar = NOW() WHERE id = :id AND waktu_keluar IS NULL')
        ->execute(['id' => $rombonganId]);
}
