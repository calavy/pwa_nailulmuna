<?php

declare(strict_types=1);

require_once __DIR__ . '/push_fcm.php';
require_once __DIR__ . '/keuangan_transaksi.php';

function push_event_izin_pengajuan_baru(
    PDO $pdo,
    string $namaSantri,
    string $nis,
    string $jenisIzin,
    string $tanggalMulai,
    string $tanggalSelesai
): void {
    $title = 'Pengajuan izin baru';
    $body = $namaSantri . ' (' . $nis . ') — ' . $jenisIzin . ' ' . $tanggalMulai . ' s/d ' . $tanggalSelesai;
    push_notify_all_staff($pdo, 'izin_pengajuan', $title, $body, [
        'nis' => $nis,
        'jenis' => $jenisIzin,
    ], '/perizinan/index.php');
}

function push_event_izin_disetujui_wali(
    PDO $pdo,
    int $santriId,
    string $namaSantri,
    string $jenisLabel,
    string $tanggalSelesai,
    string $jamSelesai
): void {
    $title = 'Izin anak disetujui';
    $body = $namaSantri . ' — ' . $jenisLabel . ' hingga ' . $tanggalSelesai . ' ' . substr($jamSelesai, 0, 5);
    push_notify_wali_for_santri($pdo, $santriId, 'izin_keluar', $title, $body, [], '/wali/keaktifan.php');
}

function push_event_laporan_sakit_wali(
    PDO $pdo,
    int $santriId,
    string $namaSantri,
    string $gejala,
    string $statusKesehatan
): void {
    $title = 'Laporan kesehatan anak';
    $body = $namaSantri . ' — ' . $statusKesehatan . ($gejala !== '' ? ': ' . mb_substr($gejala, 0, 80) : '');
    push_notify_wali_for_santri($pdo, $santriId, 'laporan_sakit', $title, $body, [], '/wali/index.php');
    push_notify_all_staff($pdo, 'izin_pengajuan', 'Laporan sakit: ' . $namaSantri, $body, [], '/perizinan/index.php');
}

function push_event_tagihan_syahriyah_wali(
    PDO $pdo,
    int $santriId,
    string $namaSantri,
    string $periodeLabel,
    string $sisaFormatted
): void {
    $title = 'Pemberitahuan tagihan';
    $body = trim($namaSantri) . ' — ' . $periodeLabel . '. Sisa: ' . $sisaFormatted;
    push_notify_wali_for_santri($pdo, $santriId, 'syahriyah', $title, $body, [], '/wali/tagihan.php');
}

function push_event_pelanggaran_berat_kiai(
    PDO $pdo,
    string $namaSantri,
    string $nis,
    int $totalPoin,
    string $sanctionLabel
): void {
    $title = 'Pelanggaran berat';
    $body = $namaSantri . ' (' . $nis . ') — ' . $totalPoin . ' poin. ' . $sanctionLabel;
    push_notify_all_kiai($pdo, 'pelanggaran_berat', $title, $body, [
        'nis' => $nis,
        'poin' => (string) $totalPoin,
    ], '/poin/rekap.php');
}

function push_event_keuangan_harian_kiai(PDO $pdo, string $summaryBody): void
{
    $title = 'Ringkasan keuangan harian';
    push_notify_all_kiai($pdo, 'keuangan_harian', $title, mb_substr($summaryBody, 0, 400), [], '/keuangan/index.php');
}

function push_event_rapat_staff(PDO $pdo, string $title, string $body, ?string $url = null): void
{
    push_notify_all_staff($pdo, 'rapat', $title, $body, [], $url ?? '/dashboard.php');
}

function push_event_tugas_keamanan(PDO $pdo, string $title, string $body): void
{
    push_notify_all_staff($pdo, 'tugas_keamanan', $title, $body, [], '/dashboard.php');
}

function trigger_push_daily_kiai(PDO $pdo): void
{
    if (!push_should_send_fcm($pdo)) {
        return;
    }
    if (trim((string) app_setting($pdo, 'fcm_daily_kiai_enabled', '1')) !== '1') {
        return;
    }
    $sendTime = trim((string) app_setting($pdo, 'fcm_daily_kiai_time', '20:00'));
    if ($sendTime !== '' && preg_match('/^\d{2}:\d{2}$/', $sendTime) && date('H:i') < $sendTime) {
        return;
    }
    $today = date('Y-m-d');
    if (trim((string) app_setting($pdo, 'fcm_daily_kiai_last_date', '')) === $today) {
        return;
    }

    $parts = [];
    $namaPonpes = app_brand_nama_ponpes($pdo);
    $parts[] = $namaPonpes . ' — ' . date('d/m/Y');

    if (table_exists($pdo, 'keuangan_pembayaran')) {
        $st = $pdo->prepare('SELECT COALESCE(SUM(total_nominal),0) FROM keuangan_pembayaran WHERE tanggal_bayar = :t');
        $st->execute(['t' => $today]);
        $masuk = (int) $st->fetchColumn();
        $parts[] = 'Pemasukan hari ini: Rp ' . number_format($masuk, 0, ',', '.');
    }
    if (table_exists($pdo, 'keuangan_pengeluaran')) {
        $st = $pdo->prepare('SELECT COALESCE(SUM(nominal),0) FROM keuangan_pengeluaran WHERE tanggal = :t');
        $st->execute(['t' => $today]);
        $keluar = (int) $st->fetchColumn();
        $parts[] = 'Pengeluaran hari ini: Rp ' . number_format($keluar, 0, ',', '.');
    }

    if (table_exists($pdo, 'point_ledger')) {
        $threshold = 40;
        if (table_exists($pdo, 'point_sanctions')) {
            $th = $pdo->query('SELECT MAX(ambang_poin) FROM point_sanctions')->fetchColumn();
            if ($th !== false && (int) $th > 0) {
                $threshold = (int) $th;
            }
        }
        $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
        $st = $pdo->prepare("
            SELECT s.nis, s.{$nameCol} AS nama, COALESCE(SUM(pl.poin),0) AS total
            FROM point_ledger pl
            INNER JOIN santri s ON s.id = pl.santri_id
            WHERE pl.tanggal >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY s.id, s.nis, s.{$nameCol}
            HAVING total >= :th
            ORDER BY total DESC
            LIMIT 5
        ");
        $st->execute(['th' => $threshold]);
        $heavy = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($heavy !== []) {
            $parts[] = 'Pelanggaran berat (≥' . $threshold . ' poin): ' . count($heavy) . ' santri.';
        }
    }

    $body = implode(' · ', $parts);
    if (push_notify_all_kiai($pdo, 'keuangan_harian', 'Ringkasan harian pondok', $body) > 0) {
        save_setting($pdo, 'fcm_daily_kiai_last_date', $today);
    }
}

function trigger_push_tagihan_wali_from_cron(PDO $pdo): void
{
    if (!push_should_send_fcm($pdo) || !table_exists($pdo, 'santri')) {
        return;
    }
    if (trim((string) app_setting($pdo, 'wa_tagihan_auto_enabled', '0')) !== '1') {
        return;
    }
    $dueDay = max(1, min(30, (int) app_setting($pdo, 'wa_tagihan_day', '5')));
    if ((int) date('j') !== $dueDay) {
        return;
    }
    $periodKey = date('Y-m');
    $lastPush = trim((string) app_setting($pdo, 'fcm_tagihan_last_period_key', ''));
    if ($lastPush === 'push:' . $periodKey) {
        return;
    }

    ensure_santri_identity_columns($pdo);
    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $katCol = column_exists($pdo, 'santri', 'kategori_kelas') ? 'kategori_kelas' : (column_exists($pdo, 'santri', 'tingkatan') ? 'tingkatan' : null);
    $cols = 'id, nis, ' . $nameExpr . ' AS nama_santri';
    if ($katCol !== null) {
        $cols .= ', ' . $katCol . ' AS kelas_kategori';
    }
    $activeExpr = column_exists($pdo, 'santri', 'is_aktif') ? ' AND COALESCE(is_aktif,1) = 1 ' : '';
    $stmt = $pdo->query('SELECT ' . $cols . ' FROM santri WHERE 1=1 ' . $activeExpr . ' ORDER BY id ASC LIMIT 300');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $bulan = (int) date('n');
    $periode = keuangan_tahun_ajaran_aktif($pdo);
    $tm = (int) $periode['mulai'];
    $ts = (int) $periode['selesai'];

    $sent = 0;
    foreach ($rows as $row) {
        $sid = (int) ($row['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $kat = trim((string) ($row['kelas_kategori'] ?? ''));
        $st = tagihan_wajib_status_for_month($pdo, $sid, $bulan, $tm, $ts, $kat);
        $sisa = (int) ($st['sisa_total'] ?? 0);
        if ($sisa <= 0) {
            continue;
        }
        $nama = (string) ($row['nama_santri'] ?? 'Santri');
        $components = keuangan_tagihan_wajib_components($pdo, $kat);
        $labelKekurangan = wa_tagihan_label_kekurangan($components, $st['per_pos'] ?? []);
        $body = push_format_tagihan_otomatis_body($nama, $labelKekurangan, $sisa);
        if (push_notify_wali_for_santri(
            $pdo,
            $sid,
            'syahriyah',
            'Pemberitahuan tagihan',
            $body,
            ['sisa' => (string) $sisa],
            '/wali/tagihan.php'
        ) > 0) {
            $sent++;
        }
    }
    if ($rows !== []) {
        save_setting($pdo, 'fcm_tagihan_last_period_key', 'push:' . $periodKey);
    }
}

function push_maybe_pelanggaran_berat_after_point(PDO $pdo, int $santriId): void
{
    if (!push_should_send_fcm($pdo) || $santriId <= 0 || !table_exists($pdo, 'point_ledger')) {
        return;
    }

    $threshold = 40;
    if (table_exists($pdo, 'point_sanctions')) {
        $th = $pdo->query('SELECT MAX(ambang_poin) FROM point_sanctions WHERE is_active = 1')->fetchColumn();
        if ($th !== false && (int) $th > 0) {
            $threshold = (int) $th;
        }
    }

    $st = $pdo->prepare('SELECT COALESCE(SUM(point_delta), 0) FROM point_ledger WHERE santri_id = :sid');
    $st->execute(['sid' => $santriId]);
    $total = (int) $st->fetchColumn();
    if ($total < $threshold) {
        return;
    }

    $debounceKey = 'fcm_pelanggaran_push_' . $santriId . '_' . date('Y-m');
    if (trim((string) app_setting($pdo, $debounceKey, '')) === '1') {
        return;
    }

    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $stS = $pdo->prepare('SELECT nis, ' . $nameCol . ' AS nama FROM santri WHERE id = :id LIMIT 1');
    $stS->execute(['id' => $santriId]);
    $santri = $stS->fetch(PDO::FETCH_ASSOC);
    if (!$santri) {
        return;
    }

    $sanctionLabel = 'Perlu tindakan kedisiplinan';
    if (table_exists($pdo, 'point_sanctions')) {
        $sanRows = $pdo->query('SELECT ambang_poin, tindakan FROM point_sanctions WHERE is_active = 1 ORDER BY ambang_poin ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($sanRows as $sr) {
            if ($total >= (int) ($sr['ambang_poin'] ?? 0)) {
                $sanctionLabel = (string) ($sr['tindakan'] ?? $sanctionLabel);
            }
        }
    }

    $n = push_event_pelanggaran_berat_kiai(
        $pdo,
        (string) ($santri['nama'] ?? 'Santri'),
        (string) ($santri['nis'] ?? '-'),
        $total,
        $sanctionLabel
    );
    if ($n > 0) {
        save_setting($pdo, $debounceKey, '1');
    }
}
