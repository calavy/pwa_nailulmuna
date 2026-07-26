<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/datetime_display.php';
require_once __DIR__ . '/wa_otomatis.php';
require_once __DIR__ . '/wa_templates.php';
require_once __DIR__ . '/keuangan_typography.php';

function keuangan_wa_pembayaran_wali_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'wa_pembayaran_wali_enabled', '1')) === '1';
}

/** Label status untuk WA wali: DITERIMA atau BELUM DITERIMA · DI CICIL. */
function keuangan_wa_status_lunas_label(string $statusRaw): string
{
    return strtoupper(trim($statusRaw)) === 'LUNAS' ? 'DITERIMA' : 'BELUM DITERIMA · DI CICIL';
}

/**
 * @param array<string, mixed> $pembayaranRow
 */
function keuangan_wa_sisa_tagihan_nominal(PDO $pdo, array $pembayaranRow): int
{
    $santriId = (int) ($pembayaranRow['santri_id'] ?? 0);
    if ($santriId <= 0) {
        return 0;
    }
    if (!function_exists('keuangan_tagihan_breakdown_for_santri')) {
        require_once __DIR__ . '/keuangan_rekap.php';
    }
    if (!function_exists('keuangan_biaya_definitions')) {
        require_once __DIR__ . '/keuangan_transaksi.php';
    }
    $jenisPeriode = strtoupper(trim((string) ($pembayaranRow['jenis_periode'] ?? 'BULANAN')));
    $bulanTagihan = (int) ($pembayaranRow['bulan_tagihan'] ?? 0);
    $taMulai = (int) ($pembayaranRow['tahun_ajaran_mulai'] ?? 0);
    $taSelesai = (int) ($pembayaranRow['tahun_ajaran_selesai'] ?? 0);
    $breakdown = keuangan_tagihan_breakdown_for_santri(
        $pdo,
        $santriId,
        $jenisPeriode,
        $bulanTagihan,
        $taMulai,
        $taSelesai,
        keuangan_biaya_definitions()
    );
    $wajibSlugs = $jenisPeriode === 'BULANAN' ? keuangan_tagihan_wajib_slugs() : [];
    $totalSisa = 0;
    foreach ($breakdown as $slug => $info) {
        if (!is_array($info)) {
            continue;
        }
        if ($jenisPeriode === 'BULANAN' && $wajibSlugs !== [] && !in_array($slug, $wajibSlugs, true)) {
            continue;
        }
        if ($slug === 'saku') {
            continue;
        }
        $totalSisa += max(0, (int) ($info['sisa'] ?? 0));
    }

    return $totalSisa;
}

function wa_format_pembayaran_masuk_wali(
    PDO $pdo,
    string $namaSantri,
    int $totalNominal,
    string $tanggalBayar,
    string $metodeBayar,
    string $periodeTagihan,
    string $rincianPembayaran,
    string $statusLunas,
    string $noKuitansi,
    string $keterangan = '',
    int $sisaTagihan = 0
): string {
    $keteranganBlok = trim($keterangan) !== '' ? "\nCatatan: _{$keterangan}_\n" : '';
    $rincianBlok = trim($rincianPembayaran) !== '' ? "\n*Rincian:*\n{$rincianPembayaran}\n" : '';
    $statusLabel = keuangan_wa_status_lunas_label($statusLunas);
    $sisaBaris = '';
    if (strtoupper(trim($statusLunas)) !== 'LUNAS' && $sisaTagihan > 0) {
        $sisaBaris = "\nSisa tagihan periode ini: *" . keuangan_format_rupiah($sisaTagihan) . "*\n";
    }

    return wa_template_render($pdo, 'pembayaran_masuk_wali', [
        'nama_santri' => $namaSantri,
        'nominal_total' => keuangan_format_rupiah($totalNominal),
        'tanggal_bayar' => app_format_tanggal_id($tanggalBayar),
        'metode_bayar' => match (strtoupper(trim($metodeBayar))) {
            'TRANSFER' => 'Transfer',
            'MIDTRANS' => 'Midtrans',
            default => 'Tunai/Kas',
        },
        'periode_tagihan' => $periodeTagihan !== '' ? $periodeTagihan : '-',
        'rincian_pembayaran' => $rincianBlok,
        'status_lunas' => $statusLabel,
        'sisa_tagihan' => $sisaTagihan > 0 ? keuangan_format_rupiah($sisaTagihan) : '-',
        'sisa_tagihan_baris' => $sisaBaris,
        'no_kuitansi' => $noKuitansi !== '' ? $noKuitansi : '-',
        'keterangan' => $keteranganBlok,
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
    ]);
}

/**
 * @return array{sent:int,reason:string}
 */
function keuangan_kirim_wa_pembayaran_wali(PDO $pdo, int $pembayaranId): array
{
    if ($pembayaranId <= 0) {
        return ['sent' => 0, 'reason' => 'invalid'];
    }
    if (!keuangan_wa_pembayaran_wali_enabled($pdo)) {
        return ['sent' => 0, 'reason' => 'disabled'];
    }
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return ['sent' => 0, 'reason' => 'master_off'];
    }
    if (wa_otomatis_gateway_error($pdo) !== null) {
        return ['sent' => 0, 'reason' => 'gateway'];
    }
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'santri')) {
        return ['sent' => 0, 'reason' => 'schema'];
    }

    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $st = $pdo->prepare("
        SELECT p.id, p.santri_id, p.jenis_periode, p.bulan_tagihan, p.tahun_ajaran_mulai, p.tahun_ajaran_selesai,
               p.tanggal_bayar, p.total_nominal, p.keterangan, p.status_lunas, p.metode_bayar,
               s.{$nameCol} AS nama_santri, s.no_wa_wali
        FROM keuangan_pembayaran p
        INNER JOIN santri s ON s.id = p.santri_id
        WHERE p.id = :id
        LIMIT 1
    ");
    $st->execute(['id' => $pembayaranId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['sent' => 0, 'reason' => 'not_found'];
    }

    $waliPhone = wa_otomatis_santri_wali_phone($pdo, $row);
    if ($waliPhone === '') {
        $waliPhone = wa_otomatis_santri_wali_phone($pdo, (int) ($row['santri_id'] ?? 0));
    }
    if ($waliPhone === '') {
        return ['sent' => 0, 'reason' => 'no_phone'];
    }

    $detailLines = [];
    if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
        $stD = $pdo->prepare('SELECT pos_nama, nominal FROM keuangan_pembayaran_detail WHERE pembayaran_id = :id ORDER BY id ASC');
        $stD->execute(['id' => $pembayaranId]);
        foreach ($stD->fetchAll(PDO::FETCH_ASSOC) ?: [] as $dr) {
            $detailLines[] = '• ' . trim((string) ($dr['pos_nama'] ?? '-'))
                . ': *' . keuangan_format_rupiah((int) ($dr['nominal'] ?? 0)) . '*';
        }
    }

    $jenisPeriode = strtoupper(trim((string) ($row['jenis_periode'] ?? 'BULANAN')));
    $bulanTagihan = (int) ($row['bulan_tagihan'] ?? 0);
    $taMulai = (int) ($row['tahun_ajaran_mulai'] ?? 0);
    $taSelesai = (int) ($row['tahun_ajaran_selesai'] ?? 0);
    if ($jenisPeriode === 'AWAL_TAHUN') {
        $periode = 'Awal tahun ajaran ' . $taMulai . '/' . $taSelesai;
    } elseif ($bulanTagihan > 0) {
        if (function_exists('pondok_bulan_slots_tahun_ajaran')) {
            require_once __DIR__ . '/pondok_kalender.php';
            $slots = pondok_bulan_slots_tahun_ajaran($pdo, $taMulai, $taSelesai);
            $slot = pondok_slot_dari_bulan_tagihan($slots, $bulanTagihan);
            $periode = is_array($slot)
                ? (string) ($slot['label'] ?? ('Bulan ' . $bulanTagihan))
                : ('Bulan ' . $bulanTagihan);
        } else {
            $periode = 'Bulan ' . $bulanTagihan . ' · TA ' . $taMulai . '/' . $taSelesai;
        }
    } else {
        $periode = 'TA ' . $taMulai . '/' . $taSelesai;
    }

    $statusLunas = column_exists($pdo, 'keuangan_pembayaran', 'status_lunas')
        ? strtoupper(trim((string) ($row['status_lunas'] ?? 'LUNAS')))
        : 'LUNAS';
    $metode = column_exists($pdo, 'keuangan_pembayaran', 'metode_bayar')
        ? strtoupper(trim((string) ($row['metode_bayar'] ?? 'KAS')))
        : 'KAS';
    $sisaTagihan = $statusLunas === 'LUNAS' ? 0 : keuangan_wa_sisa_tagihan_nominal($pdo, $row);

    $msg = wa_format_pembayaran_masuk_wali(
        $pdo,
        (string) ($row['nama_santri'] ?? '-'),
        (int) ($row['total_nominal'] ?? 0),
        (string) ($row['tanggal_bayar'] ?? date('Y-m-d')),
        $metode,
        $periode,
        implode("\n", $detailLines),
        $statusLunas,
        'KW-' . str_pad((string) $pembayaranId, 6, '0', STR_PAD_LEFT),
        trim((string) ($row['keterangan'] ?? '')),
        $sisaTagihan
    );

    $ok = send_wa_message($pdo, $waliPhone, $msg, ['kind' => 'tagihan']);

    return ['sent' => $ok ? 1 : 0, 'reason' => $ok ? 'ok' : 'failed'];
}

/** Teks tambahan flash setelah simpan pembayaran. */
function keuangan_wa_pembayaran_flash_teks(array $result): string
{
    $reason = (string) ($result['reason'] ?? '');

    return match ($reason) {
        'ok' => ' WA pemberitahuan terkirim ke wali santri.',
        'disabled' => ' (Notifikasi WA wali nonaktif di pengaturan.)',
        'master_off' => ' (WA otomatis master nonaktif — pemberitahuan wali tidak dikirim.)',
        'gateway' => ' (Gateway WA belum siap — pemberitahuan wali tidak dikirim.)',
        'no_phone' => ' (WA wali tidak terkirim — nomor wali santri kosong.)',
        'failed' => ' (WA wali gagal terkirim — cek tab Riwayat WA Otomatis.)',
        'not_found', 'schema', 'invalid' => '',
        default => '',
    };
}
