<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/** @return array{tgl_awal:string,tgl_akhir:string,label:string} */
function poin_presensi_periode_range(PDO $pdo): array
{
    $mode = trim((string) app_setting($pdo, 'point_presensi_periode', 'bulan'));
    if ($mode === 'minggu') {
        $monday = new DateTimeImmutable('monday this week');
        $sunday = $monday->modify('+6 days');

        return [
            'tgl_awal' => $monday->format('Y-m-d'),
            'tgl_akhir' => $sunday->format('Y-m-d'),
            'label' => 'Minggu ' . $monday->format('d/m') . '–' . $sunday->format('d/m/Y'),
        ];
    }

    return [
        'tgl_awal' => date('Y-m-01'),
        'tgl_akhir' => date('Y-m-t'),
        'label' => 'Bulan ' . date('F Y'),
    ];
}

/**
 * @return array{ok:bool,periode:array,counts:array{alpa:int,telat:int,pending:int},rows:list<array>}
 */
function poin_presensi_pending_pack(PDO $pdo, int $santriId): array
{
    $empty = [
        'ok' => false,
        'periode' => poin_presensi_periode_range($pdo),
        'counts' => ['alpa' => 0, 'telat' => 0, 'pending' => 0],
        'rows' => [],
    ];
    if ($santriId <= 0 || !table_exists($pdo, 'presensi')) {
        return $empty;
    }

    if (!function_exists('rekap_keaktifan_tanggal_mulai_scan')) {
        require_once __DIR__ . '/rekap_keaktifan.php';
    }
    $mulaiScan = rekap_keaktifan_tanggal_mulai_scan($pdo);
    $periode = poin_presensi_periode_range($pdo);
    $tglAwal = $periode['tgl_awal'];
    $tglAkhir = $periode['tgl_akhir'];
    if ($mulaiScan !== '' && $tglAwal < $mulaiScan) {
        $tglAwal = $mulaiScan;
    }

    require_once __DIR__ . '/presensi_jadwal.php';

    $sql = '
        SELECT p.id, p.tanggal_presensi, p.status_presensi, p.catatan, p.kegiatan_id,
               k.nama_kegiatan
        FROM presensi p
        LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
        LEFT JOIN point_ledger pl ON pl.reference_presensi_id = p.id AND pl.reference_presensi_id IS NOT NULL
        WHERE p.santri_id = :sid
          AND p.tanggal_presensi >= :awal
          AND p.tanggal_presensi <= :akhir
          AND pl.id IS NULL
        ORDER BY p.tanggal_presensi DESC, p.id DESC
    ';
    $st = $pdo->prepare($sql);
    $st->execute(['sid' => $santriId, 'awal' => $tglAwal, 'akhir' => $tglAkhir]);
    $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = [];
    $alpa = 0;
    $telat = 0;
    foreach ($raw as $row) {
        if (!presensi_row_eligible_for_hitung($pdo, $row)) {
            continue;
        }
        $status = strtoupper((string) ($row['status_presensi'] ?? ''));
        $catatan = (string) ($row['catatan'] ?? '');
        $isAlpa = $status === 'ALPA';
        $isTelat = !$isAlpa && stripos($catatan, 'Terlambat') !== false;
        if (!$isAlpa && !$isTelat) {
            continue;
        }
        if ($isAlpa) {
            $alpa++;
        } else {
            $telat++;
        }
        $rows[] = [
            'presensi_id' => (int) $row['id'],
            'tanggal' => (string) $row['tanggal_presensi'],
            'jenis' => $isAlpa ? 'ALPA' : 'TELAT',
            'kegiatan' => (string) ($row['nama_kegiatan'] ?? ''),
            'catatan' => $catatan,
        ];
    }

    return [
        'ok' => true,
        'periode' => $periode,
        'counts' => [
            'alpa' => $alpa,
            'telat' => $telat,
            'pending' => count($rows),
        ],
        'rows' => $rows,
    ];
}

/**
 * @param list<int> $presensiIds kosong = semua pending periode
 * @return array{ok:bool,message:string,added:int}
 */
function poin_presensi_pull_execute(PDO $pdo, int $santriId, array $presensiIds, int $userId): array
{
    ensure_point_tables($pdo);
    if ($santriId <= 0) {
        return ['ok' => false, 'message' => 'Pilih santri.', 'added' => 0];
    }

    $pack = poin_presensi_pending_pack($pdo, $santriId);
    if (!($pack['ok'] ?? false)) {
        return ['ok' => false, 'message' => 'Data presensi tidak tersedia.', 'added' => 0];
    }

    $allowedIds = [];
    foreach ((array) ($pack['rows'] ?? []) as $r) {
        $allowedIds[(int) ($r['presensi_id'] ?? 0)] = $r;
    }
    if ($allowedIds === []) {
        return ['ok' => false, 'message' => 'Tidak ada alpa/telat pending untuk ditarik.', 'added' => 0];
    }

    $targetIds = [];
    if ($presensiIds === []) {
        $targetIds = array_keys($allowedIds);
    } else {
        foreach ($presensiIds as $pid) {
            $pid = (int) $pid;
            if (isset($allowedIds[$pid])) {
                $targetIds[] = $pid;
            }
        }
    }
    if ($targetIds === []) {
        return ['ok' => false, 'message' => 'Tidak ada baris presensi valid yang dipilih.', 'added' => 0];
    }

    $pointAlpa = (int) app_setting($pdo, 'point_auto_alpa', '5');
    $pointTelat = (int) app_setting($pdo, 'point_auto_telat', '1');
    $ruleAlpa = (int) app_setting($pdo, 'point_rule_id_alpa', '0');
    $ruleTelat = (int) app_setting($pdo, 'point_rule_id_telat', '0');

    $hasBaseCol = column_exists($pdo, 'point_ledger', 'point_base');

    $added = 0;
    $affectedSantri = [];
    foreach ($targetIds as $pid) {
        $meta = $allowedIds[$pid];
        $isAlpa = (($meta['jenis'] ?? '') === 'ALPA');
        $points = $isAlpa ? $pointAlpa : $pointTelat;
        if ($points <= 0) {
            continue;
        }
        $sumber = $isAlpa ? 'PRESENSI_TARIK_ALPA' : 'PRESENSI_TARIK_TELAT';
        $ruleId = $isAlpa ? ($ruleAlpa > 0 ? $ruleAlpa : null) : ($ruleTelat > 0 ? $ruleTelat : null);
        $ket = $isAlpa
            ? ('Tarik poin presensi ALPA' . (($meta['kegiatan'] ?? '') !== '' ? ' — ' . $meta['kegiatan'] : ''))
            : ('Tarik poin presensi telat. ' . (string) ($meta['catatan'] ?? ''));

        try {
            if ($hasBaseCol) {
                $insert = $pdo->prepare('
                    INSERT INTO point_ledger (santri_id, tanggal, jenis_perubahan, point_delta, point_base, rule_id, sumber_data, reference_presensi_id, keterangan, created_by)
                    VALUES (:santri_id, :tanggal, "PLUS", :point_delta, :point_base, :rule_id, :sumber_data, :reference_presensi_id, :keterangan, :created_by)
                ');
                $insert->execute([
                    'santri_id' => $santriId,
                    'tanggal' => (string) ($meta['tanggal'] ?? date('Y-m-d')),
                    'point_delta' => $points,
                    'point_base' => $points,
                    'rule_id' => $ruleId,
                    'sumber_data' => $sumber,
                    'reference_presensi_id' => $pid,
                    'keterangan' => $ket,
                    'created_by' => $userId > 0 ? $userId : null,
                ]);
            } else {
                $insert = $pdo->prepare('
                    INSERT INTO point_ledger (santri_id, tanggal, jenis_perubahan, point_delta, rule_id, sumber_data, reference_presensi_id, keterangan, created_by)
                    VALUES (:santri_id, :tanggal, "PLUS", :point_delta, :rule_id, :sumber_data, :reference_presensi_id, :keterangan, :created_by)
                ');
                $insert->execute([
                    'santri_id' => $santriId,
                    'tanggal' => (string) ($meta['tanggal'] ?? date('Y-m-d')),
                    'point_delta' => $points,
                    'rule_id' => $ruleId,
                    'sumber_data' => $sumber,
                    'reference_presensi_id' => $pid,
                    'keterangan' => $ket,
                    'created_by' => $userId > 0 ? $userId : null,
                ]);
            }
            $added++;
            $affectedSantri[$santriId] = true;
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'Duplicate') === false && stripos($e->getMessage(), '1062') === false) {
                throw $e;
            }
        }
    }

    if ($added > 0 && $affectedSantri !== []) {
        require_once __DIR__ . '/poin_wa.php';
        foreach (array_keys($affectedSantri) as $sid) {
            poin_wa_maybe_notify_santri($pdo, (int) $sid);
        }
    }

    return [
        'ok' => $added > 0,
        'message' => $added > 0 ? ($added . ' entri presensi ditarik ke poin.') : 'Tidak ada entri baru (mungkin sudah pernah ditarik).',
        'added' => $added,
    ];
}
