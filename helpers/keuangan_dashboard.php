<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_neraca.php';

/**
 * Snapshot kondisi keuangan terkini untuk dashboard bendahara.
 *
 * @return array<string, mixed>|null null bila skema keuangan belum siap
 */
function keuangan_dashboard_snapshot(PDO $pdo): ?array
{
    if (!table_exists($pdo, 'santri') || !table_exists($pdo, 'keuangan_pembayaran')) {
        return null;
    }

    ensure_santri_identity_columns($pdo);

    $today = date('Y-m-d');
    $neraca = keuangan_build_neraca($pdo, $today);
    $selisih = (int) ($neraca['selisih'] ?? 0);
    $seimbang = abs($selisih) < 1;

    $periode = keuangan_periode_berjalan($pdo, $today);
    $bulan = (int) $periode['bulan'];
    $tm = (int) $periode['mulai'];
    $ts = (int) $periode['selesai'];

    $sql = 'SELECT id, nis, nama_santri, tingkatan, kategori_kelas, no_wa_wali FROM santri';
    if (column_exists($pdo, 'santri', 'is_aktif')) {
        $sql .= ' WHERE COALESCE(is_aktif, 1) = 1';
    }
    $sql .= ' ORDER BY nama_santri ASC';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $totalTagihan = 0;
    $totalTerbayar = 0;
    $totalPiutang = 0;
    $jumlahPenunggak = 0;
    $jumlahLunas = 0;
    $jumlahSebagian = 0;
    $penunggakDenganWa = 0;
    $penunggakTanpaWa = 0;
    $topPenunggak = [];

    foreach ($rows as $s) {
        $sid = (int) ($s['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $kat = trim((string) ($s['kategori_kelas'] ?? ''));
        if ($kat === '' && !empty($s['tingkatan'])) {
            $kat = (string) $s['tingkatan'];
        }
        $st = tagihan_wajib_status_for_month($pdo, $sid, $bulan, $tm, $ts, $kat);
        $expected = (int) ($st['expected_total'] ?? 0);
        if ($expected <= 0) {
            continue;
        }
        $paid = (int) ($st['paid_total'] ?? 0);
        $sisa = (int) ($st['sisa_total'] ?? 0);
        $status = (string) ($st['status'] ?? '');

        $totalTagihan += $expected;
        $totalTerbayar += min($paid, $expected);
        $totalPiutang += $sisa;

        if ($sisa <= 0) {
            $jumlahLunas++;
            continue;
        }

        $jumlahPenunggak++;
        if ($status === 'Sebagian') {
            $jumlahSebagian++;
        }

        $wa = trim((string) ($s['no_wa_wali'] ?? ''));
        if ($wa !== '') {
            $penunggakDenganWa++;
        } else {
            $penunggakTanpaWa++;
        }

        if (count($topPenunggak) < 8) {
            $topPenunggak[] = [
                'id' => $sid,
                'nama' => (string) ($s['nama_santri'] ?? ''),
                'nis' => (string) ($s['nis'] ?? ''),
                'sisa' => $sisa,
            ];
        } else {
            $minIdx = 0;
            $minSisa = (int) $topPenunggak[0]['sisa'];
            foreach ($topPenunggak as $i => $t) {
                if ((int) $t['sisa'] < $minSisa) {
                    $minSisa = (int) $t['sisa'];
                    $minIdx = $i;
                }
            }
            if ($sisa > $minSisa) {
                $topPenunggak[$minIdx] = [
                    'id' => $sid,
                    'nama' => (string) ($s['nama_santri'] ?? ''),
                    'nis' => (string) ($s['nis'] ?? ''),
                    'sisa' => $sisa,
                ];
            }
        }
    }

    usort($topPenunggak, static fn(array $a, array $b): int => ($b['sisa'] <=> $a['sisa']));

    $persenTertagih = $totalTagihan > 0
        ? round(($totalTerbayar / $totalTagihan) * 100, 1)
        : 0.0;

    $waEnabled = trim((string) app_setting($pdo, 'wa_tagihan_auto_enabled', '0')) === '1';
    $waCalendar = strtoupper(trim((string) app_setting($pdo, 'wa_tagihan_calendar', 'MASEHI')));
    if (!in_array($waCalendar, ['MASEHI', 'HIJRIYAH'], true)) {
        $waCalendar = 'MASEHI';
    }
    $waDueDay = max(1, min(30, (int) app_setting($pdo, 'wa_tagihan_day', '5')));
    $waSendTime = trim((string) app_setting($pdo, 'wa_tagihan_send_time', '08:00'));
    $waLastAt = trim((string) app_setting($pdo, 'wa_tagihan_last_sent_at', ''));
    $waLastKey = trim((string) app_setting($pdo, 'wa_tagihan_last_period_key', ''));

    $periodKey = date('Y-m');
    $todayDay = (int) date('j');
    if ($waCalendar === 'HIJRIYAH') {
        $periodKey = get_hijri_year_month($today);
        if (class_exists('IntlDateFormatter')) {
            $fmtDay = new IntlDateFormatter(
                islamic_calendar_locale(),
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                date_default_timezone_get(),
                IntlDateFormatter::TRADITIONAL,
                'd'
            );
            $hDay = $fmtDay->format(strtotime($today));
            if (is_string($hDay) && ctype_digit($hDay)) {
                $todayDay = (int) $hDay;
            }
        }
    }
    $periodSent = $waLastKey === $waCalendar . ':' . $periodKey;
    $hariIniJadwalKirim = $todayDay === $waDueDay;

    $tindakan = keuangan_dashboard_build_tindakan(
        $seimbang,
        $selisih,
        $totalPiutang,
        $jumlahPenunggak,
        $penunggakTanpaWa,
        $penunggakDenganWa,
        $waEnabled,
        $periodSent,
        $hariIniJadwalKirim,
        $waDueDay,
        $waSendTime,
        $periode,
        $topPenunggak
    );

    return [
        'neraca' => [
            'seimbang' => $seimbang,
            'selisih' => $selisih,
            'total_aset' => (int) ($neraca['aset']['total'] ?? 0),
            'total_pasiva' => (int) ($neraca['total_pasiva'] ?? 0),
            'as_of_label' => (string) ($neraca['as_of_label'] ?? $today),
        ],
        'tagihan_bulan' => [
            'bulan' => $bulan,
            'bulan_label' => (string) $periode['bulan_label'],
            'ta_label' => (string) $periode['ta_label'],
            'tm' => $tm,
            'ts' => $ts,
            'total_tagihan' => $totalTagihan,
            'total_terbayar' => $totalTerbayar,
            'total_piutang' => $totalPiutang,
            'jumlah_penunggak' => $jumlahPenunggak,
            'jumlah_lunas' => $jumlahLunas,
            'jumlah_sebagian' => $jumlahSebagian,
            'jumlah_santri_tagihan' => $jumlahLunas + $jumlahPenunggak,
            'persen_tertagih' => $persenTertagih,
            'top_penunggak' => $topPenunggak,
        ],
        'wa_tagihan' => [
            'enabled' => $waEnabled,
            'calendar' => $waCalendar,
            'due_day' => $waDueDay,
            'send_time' => $waSendTime,
            'last_sent_at' => $waLastAt !== '' ? $waLastAt : null,
            'last_period_key' => $waLastKey,
            'period_key' => $periodKey,
            'period_sudah_kirim' => $periodSent,
            'hari_ini_jadwal_kirim' => $hariIniJadwalKirim,
            'penunggak_dengan_wa' => $penunggakDenganWa,
            'penunggak_tanpa_wa' => $penunggakTanpaWa,
        ],
        'tindakan' => $tindakan,
    ];
}

/**
 * @param list<array{id:int,nama:string,nis:string,sisa:int}> $topPenunggak
 * @return list<array{level:string,judul:string,deskripsi:string,href:string,icon:string}>
 */
function keuangan_dashboard_build_tindakan(
    bool $seimbang,
    int $selisih,
    int $totalPiutang,
    int $jumlahPenunggak,
    int $penunggakTanpaWa,
    int $penunggakDenganWa,
    bool $waEnabled,
    bool $periodSent,
    bool $hariIniJadwalKirim,
    int $waDueDay,
    string $waSendTime,
    array $periode,
    array $topPenunggak
): array {
    $fmt = static fn(int $n): string => keuangan_format_rupiah($n);
    $bulanLabel = (string) ($periode['bulan_label'] ?? '');
    $taLabel = (string) ($periode['ta_label'] ?? '');
    $tagihanUrl = '/pwa_nailulmuna/pembayaran/tagihan_syahriyah.php?bulan=' . (int) ($periode['bulan'] ?? 0)
        . '&tm=' . (int) ($periode['mulai'] ?? 0) . '&ts=' . (int) ($periode['selesai'] ?? 0);
    $settingsUrl = '/pwa_nailulmuna/settings/index.php';
    $out = [];

    if (!$seimbang) {
        $out[] = [
            'level' => 'danger',
            'judul' => 'Neraca belum seimbang',
            'deskripsi' => 'Selisih ' . $fmt(abs($selisih)) . ' — periksa jurnal, saldo akun, dan transaksi terakhir.',
            'href' => '/pwa_nailulmuna/keuangan/neraca.php',
            'icon' => 'fa-scale-unbalanced',
        ];
    }

    if ($jumlahPenunggak > 0) {
        $out[] = [
            'level' => 'warning',
            'judul' => 'Tagih & terima pembayaran',
            'deskripsi' => $jumlahPenunggak . ' santri penunggak ' . $bulanLabel . ' TA ' . $taLabel
                . ' — piutang ' . $fmt($totalPiutang) . '.',
            'href' => $tagihanUrl,
            'icon' => 'fa-receipt',
        ];
    } else {
        $out[] = [
            'level' => 'success',
            'judul' => 'Tagihan bulan ini lunas',
            'deskripsi' => 'Semua santri aktif sudah memenuhi tagihan wajib ' . $bulanLabel . ' TA ' . $taLabel . '.',
            'href' => $tagihanUrl,
            'icon' => 'fa-circle-check',
        ];
    }

    if ($penunggakTanpaWa > 0) {
        $out[] = [
            'level' => 'warning',
            'judul' => 'Lengkapi nomor WA wali',
            'deskripsi' => $penunggakTanpaWa . ' penunggak belum punya nomor WhatsApp wali — tidak bisa dikirimi tagihan otomatis.',
            'href' => '/pwa_nailulmuna/santri/index.php',
            'icon' => 'fa-brands fa-whatsapp',
        ];
    }

    if ($jumlahPenunggak > 0) {
        if (!$waEnabled) {
            $out[] = [
                'level' => 'info',
                'judul' => 'Aktifkan WA tagihan otomatis',
                'deskripsi' => 'Pengingat tagihan ke wali belum diaktifkan. Nyalakan di pengaturan pondok agar terkirim tiap bulan.',
                'href' => $settingsUrl,
                'icon' => 'fa-gear',
            ];
        } elseif ($hariIniJadwalKirim && !$periodSent && $penunggakDenganWa > 0) {
            $jamInfo = $waSendTime !== '' && preg_match('/^\d{2}:\d{2}$/', $waSendTime)
                ? (' (jadwal jam ' . $waSendTime . ')')
                : '';
            $out[] = [
                'level' => 'danger',
                'judul' => 'Kirim WA tagihan hari ini',
                'deskripsi' => 'Hari ini tanggal ' . $waDueDay . ' — periode ' . $bulanLabel
                    . ' belum terkirim ke ' . $penunggakDenganWa . ' wali' . $jamInfo
                    . '. Pastikan cron `wa_auto.php` jalan atau buka aplikasi saat jam kirim.',
                'href' => $settingsUrl,
                'icon' => 'fa-paper-plane',
            ];
        } elseif (!$periodSent && $penunggakDenganWa > 0) {
            $out[] = [
                'level' => 'info',
                'judul' => 'WA tagihan periode ini belum terkirim',
                'deskripsi' => 'Jadwal kirim tanggal ' . $waDueDay . ' setiap bulan. '
                    . $penunggakDenganWa . ' wali siap menerima pengingat.',
                'href' => $settingsUrl,
                'icon' => 'fa-calendar-day',
            ];
        } elseif ($periodSent) {
            $out[] = [
                'level' => 'success',
                'judul' => 'WA tagihan periode ini sudah dikirim',
                'deskripsi' => 'Pengingat otomatis untuk periode berjalan sudah tercatat terkirim.',
                'href' => $settingsUrl,
                'icon' => 'fa-check-double',
            ];
        }
    }

    if ($topPenunggak !== [] && $jumlahPenunggak > 0) {
        $namaList = array_slice(array_map(
            static fn(array $t): string => trim((string) $t['nama']) !== '' ? (string) $t['nama'] : 'Santri',
            $topPenunggak
        ), 0, 3);
        $out[] = [
            'level' => 'secondary',
            'judul' => 'Prioritas penagihan',
            'deskripsi' => 'Sisa terbesar: ' . implode(', ', $namaList)
                . ($jumlahPenunggak > 3 ? '…' : '') . '.',
            'href' => $tagihanUrl,
            'icon' => 'fa-user-graduate',
        ];
    }

    return $out;
}
