<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/santri_operasional.php';

/**
 * Statistik ringkas untuk kartu dashboard.
 *
 * @return array<string, int>
 */
function dashboard_collect_stats(PDO $pdo): array
{
    $stats = [
        'santri_aktif' => 0,
        'pembimbing' => 0,
        'kegiatan' => 0,
        'jadwal' => 0,
        'presensi_hari' => 0,
        'setoran_bulan' => 0,
        'rapor_terbit' => 0,
    ];

    if (table_exists($pdo, 'santri')) {
        $sql = 'SELECT COUNT(*) FROM santri';
        if (column_exists($pdo, 'santri', 'status_santri')) {
            $sql .= ' WHERE ' . santri_sql_aktif_only('santri');
        } elseif (column_exists($pdo, 'santri', 'is_aktif')) {
            $sql .= ' WHERE COALESCE(is_aktif, 1) = 1';
        }
        $stats['santri_aktif'] = (int) $pdo->query($sql)->fetchColumn();
    }

    if (table_exists($pdo, 'pembimbing')) {
        $sql = 'SELECT COUNT(*) FROM pembimbing';
        if (column_exists($pdo, 'pembimbing', 'is_aktif')) {
            $sql .= ' WHERE COALESCE(is_aktif, 1) = 1';
        }
        $stats['pembimbing'] = (int) $pdo->query($sql)->fetchColumn();
    }

    if (table_exists($pdo, 'kegiatan')) {
        $stats['kegiatan'] = (int) $pdo->query('SELECT COUNT(*) FROM kegiatan WHERE COALESCE(is_active, 1) = 1')->fetchColumn();
    }

    if (table_exists($pdo, 'jadwal_kegiatan')) {
        $stats['jadwal'] = (int) $pdo->query('SELECT COUNT(*) FROM jadwal_kegiatan')->fetchColumn();
    }

    $today = date('Y-m-d');
    if (table_exists($pdo, 'presensi')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM presensi WHERE tanggal_presensi = :t');
        $st->execute(['t' => $today]);
        $stats['presensi_hari'] = (int) $st->fetchColumn();
    }

    if (table_exists($pdo, 'akademik_hafalan_setoran')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM akademik_hafalan_setoran WHERE tanggal_setoran >= :awal AND tanggal_setoran <= :akhir');
        $st->execute(['awal' => date('Y-m-01'), 'akhir' => date('Y-m-t')]);
        $stats['setoran_bulan'] = (int) $st->fetchColumn();
    }

    if (table_exists($pdo, 'akademik_rapor')) {
        $stats['rapor_terbit'] = (int) $pdo->query('SELECT COUNT(*) FROM akademik_rapor WHERE is_published = 1')->fetchColumn();
    }

    return $stats;
}
