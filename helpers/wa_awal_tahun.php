<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/wa_otomatis.php';
require_once __DIR__ . '/wa_templates.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_rekap.php';
require_once __DIR__ . '/tagihan_santri_masuk.php';

function wa_awal_tahun_auto_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'wa_awal_tahun_auto_enabled', '0')) === '1';
}

function wa_awal_tahun_send_time_ok(PDO $pdo, ?string $nowHm = null): bool
{
    $jam = trim((string) app_setting($pdo, 'wa_awal_tahun_send_time', '09:00'));
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $jam, $jm)) {
        return true;
    }
    $nowHm = $nowHm ?? date('H:i');
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $nowHm, $nm)) {
        return false;
    }
    $nowMinutes = ((int) $nm[1]) * 60 + (int) $nm[2];
    $jamMinutes = ((int) $jm[1]) * 60 + (int) $jm[2];

    return $nowMinutes >= $jamMinutes;
}

/**
 * @return array{sent:int,failed:int,skipped:int,eligible:int,message:string,ok:bool}
 */
function wa_awal_tahun_jalankan_kirim(PDO $pdo, bool $paksa = false): array
{
    if (!$paksa && !wa_awal_tahun_auto_enabled($pdo)) {
        return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => 'Pengingat awal tahun nonaktif.'];
    }
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => 'Master WA otomatis nonaktif.'];
    }
    $gwErr = wa_otomatis_gateway_error($pdo);
    if ($gwErr !== null) {
        return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => $gwErr];
    }

    $today = date('Y-m-d');
    $ta = keuangan_tahun_ajaran_aktif($pdo);
    $taMulai = (int) ($ta['mulai'] ?? 0);
    $taSelesai = (int) ($ta['selesai'] ?? 0);
    if ($taMulai <= 0) {
        return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => 'Tahun ajaran aktif belum diatur.'];
    }

    $periodKey = 'AWAL_TAHUN:' . $taMulai . '-' . $taSelesai;
    if (!$paksa) {
        if (!wa_awal_tahun_send_time_ok($pdo)) {
            return [
                'ok' => true,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'eligible' => 0,
                'message' => 'Belum jam kirim pengingat awal tahun.',
            ];
        }
        $lastKey = trim((string) app_setting($pdo, 'wa_awal_tahun_last_period_key', ''));
        if ($lastKey === $periodKey) {
            return [
                'ok' => true,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'eligible' => 0,
                'message' => 'Pengingat awal tahun TA ini sudah dikirim.',
            ];
        }
    }

    ensure_keuangan_transaksi_tables($pdo);
    if (!table_exists($pdo, 'santri')) {
        return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => 'Tabel santri tidak tersedia.'];
    }

    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $aktif = column_exists($pdo, 'santri', 'is_aktif') ? ' AND COALESCE(is_aktif, 1) = 1 ' : '';
    $cols = 'id, nis, ' . $nameExpr . ' AS nama_santri';
    if (column_exists($pdo, 'santri', 'no_wa_wali')) {
        $cols .= ', no_wa_wali';
    }
    if (column_exists($pdo, 'santri', 'wali_santri_id')) {
        $cols .= ', wali_santri_id';
    }
    foreach (['nama_ayah', 'no_kontak_ayah', 'nama_ibu', 'no_kontak_ibu'] as $c) {
        if (column_exists($pdo, 'santri', $c)) {
            $cols .= ', ' . $c;
        }
    }

    $defs = keuangan_biaya_definitions();
    $sent = 0;
    $failed = 0;
    $skipped = 0;
    $eligible = 0;
    $periodeLabel = 'Awal tahun ajaran ' . $taMulai . '/' . $taSelesai;

    $rows = $pdo->query('SELECT ' . $cols . ' FROM santri WHERE 1=1 ' . $aktif . ' ORDER BY id ASC LIMIT 800')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $santri) {
        $sid = (int) ($santri['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $breakdown = keuangan_tagihan_breakdown_for_santri($pdo, $sid, 'AWAL_TAHUN', 0, $taMulai, $taSelesai, $defs);
        $components = [];
        $sisaTotal = 0;
        foreach ($breakdown as $slug => $info) {
            if (!is_string($slug) || !is_array($info)) {
                continue;
            }
            if (($info['berlaku'] ?? true) === false) {
                continue;
            }
            $sisa = max(0, (int) ($info['sisa'] ?? 0));
            if ($sisa <= 0) {
                continue;
            }
            $namaPos = $slug;
            foreach ($defs as $d) {
                if ((string) ($d['slug'] ?? '') === $slug) {
                    $namaPos = (string) ($d['nama'] ?? $slug);
                    break;
                }
            }
            $components[] = ['slug' => $slug, 'nama' => $namaPos, 'nominal' => $sisa];
            $sisaTotal += $sisa;
        }
        if ($sisaTotal <= 0) {
            continue;
        }
        $eligible++;
        $phone = wa_otomatis_santri_wali_phone($pdo, $santri);
        if ($phone === '') {
            $skipped++;
            continue;
        }

        $lines = [];
        foreach ($components as $c) {
            $lines[] = '• ' . (string) $c['nama'] . ': Rp ' . number_format((int) $c['nominal'], 0, ',', '.');
        }
        $msg = wa_template_render($pdo, 'tagihan_otomatis_wali', [
            'nama_santri' => (string) ($santri['nama_santri'] ?? 'Santri'),
            'label_kekurangan' => implode("\n", $lines),
            'nominal_kekurangan' => 'Rp ' . number_format($sisaTotal, 0, ',', '.'),
            'periode_tagihan' => $periodeLabel,
            'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
        ]);

        $res = wa_otomatis_send($pdo, $phone, $msg, ['kind' => 'tagihan']);
        if (!empty($res['success'])) {
            $sent++;
        } else {
            $failed++;
        }
        usleep(350000);
    }

    if ($sent > 0 || ($paksa && $eligible === 0)) {
        if ($sent > 0) {
            save_setting($pdo, 'wa_awal_tahun_last_period_key', $periodKey);
            save_setting($pdo, 'wa_awal_tahun_last_sent_at', date('Y-m-d H:i:s'));
        }
        save_setting($pdo, 'wa_awal_tahun_last_stats', json_encode([
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'eligible' => $eligible,
        ], JSON_UNESCAPED_UNICODE));
    }

    $ok = $paksa ? ($sent > 0 || $eligible === 0) : $sent > 0;
    $message = $sent > 0
        ? 'Pengingat awal tahun terkirim ke ' . $sent . ' wali (' . $failed . ' gagal, ' . $skipped . ' tanpa nomor).'
        : ($eligible === 0
            ? 'Tidak ada santri dengan tunggakan awal tahun.'
            : 'Gagal mengirim pengingat awal tahun (' . $failed . ' gagal).');

    return [
        'ok' => $ok,
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped,
        'eligible' => $eligible,
        'message' => $message,
    ];
}

function wa_awal_tahun_cron(PDO $pdo): void
{
    wa_awal_tahun_jalankan_kirim($pdo, false);
}
