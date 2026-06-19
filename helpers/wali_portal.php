<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/wali.php';

/** Salam sesuai jam (WIB server). */
function wali_portal_salam_waktu(): string
{
    $h = (int) date('G');
    if ($h >= 3 && $h < 11) {
        return 'Selamat pagi';
    }
    if ($h >= 11 && $h < 15) {
        return 'Selamat siang';
    }
    if ($h >= 15 && $h < 18) {
        return 'Selamat sore';
    }

    return 'Selamat malam';
}

/** Nama wali dari tabel wali_santri atau nama kafil di santri. */
function wali_portal_resolve_nama_wali(PDO $pdo, array $santriRow): string
{
    $wid = (int) ($santriRow['wali_santri_id'] ?? 0);
    if ($wid > 0 && table_exists($pdo, 'wali_santri')) {
        $st = $pdo->prepare('SELECT nama FROM wali_santri WHERE id = :id LIMIT 1');
        $st->execute(['id' => $wid]);
        $nama = trim((string) ($st->fetchColumn() ?: ''));
        if ($nama !== '') {
            return $nama;
        }
    }

    $namaAyah = trim((string) ($santriRow['nama_ayah'] ?? ''));
    if ($namaAyah !== '') {
        return $namaAyah;
    }

    return trim((string) ($santriRow['nama_kafil'] ?? ''));
}

/**
 * @return array{nama:string,no_wa:string,pin_ada:bool}
 */
function wali_portal_fetch_contact(PDO $pdo, int $santriId): array
{
    if ($santriId <= 0 || !table_exists($pdo, 'santri')) {
        return ['nama' => '', 'no_wa' => '', 'pin_ada' => false];
    }
    ensure_santri_identity_columns($pdo);
    ensure_wali_santri_table($pdo);

    $cols = ['id', 'wali_portal_pin_hash', 'no_wa_wali', 'nama_kafil', 'nama_ayah'];
    if (column_exists($pdo, 'santri', 'wali_santri_id')) {
        $cols[] = 'wali_santri_id';
    }
    if (column_exists($pdo, 'santri', 'no_kontak_ayah')) {
        $cols[] = 'no_kontak_ayah';
    }
    if (column_exists($pdo, 'santri', 'no_kontak_ibu')) {
        $cols[] = 'no_kontak_ibu';
    }
    $st = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['nama' => '', 'no_wa' => '', 'pin_ada' => false];
    }

    if (!function_exists('santri_resolve_no_wa_wali')) {
        require_once __DIR__ . '/santri_wa.php';
    }

    return [
        'nama' => wali_portal_resolve_nama_wali($pdo, $row),
        'no_wa' => santri_resolve_no_wa_wali($pdo, $row),
        'pin_ada' => trim((string) ($row['wali_portal_pin_hash'] ?? '')) !== '',
    ];
}

/**
 * Kontak portal dari baris query (tanpa query ulang per santri).
 *
 * @return array{nama:string,no_wa:string,pin_ada:bool}
 */
function wali_portal_contact_from_row(PDO $pdo, array $row): array
{
    if (!function_exists('santri_resolve_no_wa_wali')) {
        require_once __DIR__ . '/santri_wa.php';
    }

    $nama = trim((string) ($row['wali_nama'] ?? ''));
    if ($nama === '') {
        $nama = wali_portal_resolve_nama_wali($pdo, $row);
    }

    return [
        'nama' => $nama,
        'no_wa' => santri_resolve_no_wa_wali($pdo, $row),
        'pin_ada' => !empty($row['pin_ada']) || trim((string) ($row['wali_portal_pin_hash'] ?? '')) !== '',
    ];
}

/** Sinkronkan nama & WA wali ke santri.no_wa_wali dan profil wali_santri. */
function wali_portal_apply_contact(PDO $pdo, int $santriId, string $namaWali, string $noWaRaw): void
{
    if ($santriId <= 0 || !table_exists($pdo, 'santri')) {
        return;
    }
    ensure_wali_santri_table($pdo);
    ensure_santri_identity_columns($pdo);

    $namaWali = trim($namaWali);
    $noWaStore = trim($noWaRaw);
    $waDigits = wali_santri_normalize_wa_digits($noWaStore);
    if ($namaWali === '' && $noWaStore === '') {
        return;
    }

    if (column_exists($pdo, 'santri', 'no_wa_wali') && $noWaStore !== '') {
        $pdo->prepare('UPDATE santri SET no_wa_wali = :w WHERE id = :id')->execute([
            'w' => mb_substr($noWaStore, 0, 40),
            'id' => $santriId,
        ]);
    }

    if (!column_exists($pdo, 'santri', 'wali_santri_id')) {
        return;
    }

    $st = $pdo->prepare('SELECT wali_santri_id FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $prevWaliId = (int) ($st->fetchColumn() ?: 0);

    if ($namaWali === '') {
        if ($prevWaliId > 0 && $noWaStore !== '') {
            $pdo->prepare('UPDATE wali_santri SET no_wa = :w WHERE id = :id')->execute([
                'w' => mb_substr($noWaStore, 0, 40),
                'id' => $prevWaliId,
            ]);
        }

        return;
    }

    $targetWaliId = $prevWaliId;
    $found = wali_santri_find_id_by_nama_and_wa($pdo, $namaWali, $waDigits);
    if ($found !== null) {
        $targetWaliId = $found;
    }

    if ($targetWaliId > 0 && $targetWaliId !== $prevWaliId) {
        santri_set_wali_santri_id_and_prune_previous($pdo, $santriId, $prevWaliId, $targetWaliId);
    } elseif ($targetWaliId <= 0) {
        $targetWaliId = wali_santri_insert_profile($pdo, $namaWali, $waDigits, null);
        if ($targetWaliId > 0) {
            santri_set_wali_santri_id_and_prune_previous($pdo, $santriId, $prevWaliId, $targetWaliId);
        }
    }

    if ($targetWaliId > 0) {
        $pdo->prepare('UPDATE wali_santri SET nama = :n, no_wa = :w WHERE id = :id')->execute([
            'n' => mb_substr($namaWali, 0, 120),
            'w' => $noWaStore !== '' ? mb_substr($noWaStore, 0, 40) : null,
            'id' => $targetWaliId,
        ]);
    }
}

/**
 * Simpan PIN portal + kontak wali (nama & WhatsApp).
 *
 * @return array{ok:bool,message:string}
 */
function wali_portal_save_settings(
    PDO $pdo,
    int $santriId,
    string $pinBaru,
    string $pinKonf,
    string $namaWali,
    string $noWa
): array {
    if ($santriId <= 0) {
        return ['ok' => false, 'message' => 'Santri tidak valid.'];
    }
    ensure_santri_identity_columns($pdo);

    $pinBaru = trim($pinBaru);
    $pinKonf = trim($pinKonf);
    $hasPin = $pinBaru !== '' || $pinKonf !== '';
    $hasContact = trim($namaWali) !== '' || trim($noWa) !== '';

    if (!$hasPin && !$hasContact) {
        return ['ok' => false, 'message' => 'Isi PIN dan/atau data wali (nama & WhatsApp).'];
    }

    if ($hasPin) {
        if ($pinBaru === '' || $pinKonf === '') {
            return ['ok' => false, 'message' => 'Isi PIN baru dan konfirmasi PIN.'];
        }
        if (strlen($pinBaru) < 6) {
            return ['ok' => false, 'message' => 'PIN portal minimal 6 karakter.'];
        }
        if ($pinBaru !== $pinKonf) {
            return ['ok' => false, 'message' => 'PIN dan konfirmasi tidak sama.'];
        }
        if (!column_exists($pdo, 'santri', 'wali_portal_pin_hash')) {
            return ['ok' => false, 'message' => 'Kolom PIN portal belum tersedia.'];
        }
        $pdo->prepare('UPDATE santri SET wali_portal_pin_hash = :h WHERE id = :id')->execute([
            'h' => password_hash($pinBaru, PASSWORD_DEFAULT),
            'id' => $santriId,
        ]);
    }

    if ($hasContact) {
        $noWaTrim = trim($noWa);
        if ($noWaTrim !== '') {
            $waNorm = normalize_wa_phone($noWaTrim);
            if ($waNorm === '' || strlen($waNorm) < 10) {
                return ['ok' => false, 'message' => 'Nomor WhatsApp wali tidak valid (contoh: 628xxxxxxxxxx).'];
            }
        }
        wali_portal_apply_contact($pdo, $santriId, $namaWali, $noWa);
    }

    $parts = [];
    if ($hasPin) {
        $parts[] = 'PIN';
    }
    if ($hasContact) {
        $parts[] = 'kontak wali';
    }

    return ['ok' => true, 'message' => 'Pengaturan portal wali (' . implode(' & ', $parts) . ') berhasil disimpan.'];
}

/**
 * @return array{salam:string,nama_wali:string,nama_anak:string,line:string,subline:string}
 */
function wali_portal_build_greeting(PDO $pdo, array $santriRow): array
{
    $namaAnak = trim((string) ($santriRow['nama_tampil'] ?? $santriRow['nama_santri'] ?? ''));
    $namaWali = wali_portal_resolve_nama_wali($pdo, $santriRow);

    if ($namaWali !== '' && $namaAnak !== '') {
        $line = 'Bapak/Ibu ' . $namaWali . ' — wali ' . $namaAnak;
        $subline = 'Berikut ringkasan untuk putra/putri Anda.';
    } elseif ($namaAnak !== '') {
        $line = 'Wali santri ' . $namaAnak;
        $subline = 'Berikut ringkasan untuk putra/putri Anda.';
    } else {
        $line = 'Portal wali santri';
        $subline = 'Berikut ringkasan keuangan dan keaktifan.';
    }

    return [
        'salam' => '',
        'nama_wali' => $namaWali,
        'nama_anak' => $namaAnak,
        'line' => $line,
        'subline' => $subline,
    ];
}

function wali_portal_format_rupiah(int $nominal): string
{
    return 'Rp ' . number_format($nominal, 0, ',', '.');
}

/** Label periode pembayaran untuk tampilan wali. */
function wali_portal_label_periode(PDO $pdo, array $row): string
{
    if (!function_exists('pondok_label_periode_pembayaran')) {
        require_once __DIR__ . '/pondok_kalender.php';
    }

    return pondok_label_periode_pembayaran($pdo, $row);
}

/**
 * Daftar pembayaran santri + rincian POS.
 *
 * @return list<array<string,mixed>>
 */
function wali_portal_fetch_pembayaran_list(PDO $pdo, int $santriId, int $limit = 60): array
{
    if ($santriId <= 0 || !table_exists($pdo, 'keuangan_pembayaran')) {
        return [];
    }
    $limit = max(1, min(120, $limit));
    $detailOk = table_exists($pdo, 'keuangan_pembayaran_detail');

    $metodeCol = column_exists($pdo, 'keuangan_pembayaran', 'metode_bayar') ? 'p.metode_bayar' : "'KAS' AS metode_bayar";
    $refCol = column_exists($pdo, 'keuangan_pembayaran', 'no_referensi') ? 'p.no_referensi' : "'' AS no_referensi";

    $khCol = column_exists($pdo, 'keuangan_pembayaran', 'kalender_hijriyah')
        ? 'p.kalender_hijriyah'
        : 'NULL AS kalender_hijriyah';
    $st = $pdo->prepare("
        SELECT p.id, p.jenis_periode, p.tahun_ajaran_mulai, p.tahun_ajaran_selesai, p.bulan_tagihan,
               {$khCol}, p.tanggal_bayar, p.total_nominal, {$metodeCol}, {$refCol}, p.keterangan
        FROM keuangan_pembayaran p
        WHERE p.santri_id = :sid
        ORDER BY p.tanggal_bayar DESC, p.id DESC
        LIMIT {$limit}
    ");
    $st->execute(['sid' => $santriId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === [] || !$detailOk) {
        foreach ($rows as &$r) {
            $r['details'] = [];
        }
        unset($r);

        return $rows;
    }

    $ids = array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $rows);
    $ids = array_values(array_filter($ids, static fn(int $v): bool => $v > 0));
    $detailMap = [];
    if ($ids !== []) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $det = $pdo->prepare("SELECT pembayaran_id, pos_slug, pos_nama, nominal FROM keuangan_pembayaran_detail WHERE pembayaran_id IN ($in) ORDER BY pembayaran_id ASC, id ASC");
        $det->execute($ids);
        foreach ($det->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $pid = (int) $d['pembayaran_id'];
            $detailMap[$pid][] = $d;
        }
    }

    foreach ($rows as &$r) {
        $pid = (int) ($r['id'] ?? 0);
        $r['details'] = $detailMap[$pid] ?? [];
        $r['periode_label'] = wali_portal_label_periode($pdo, $r);
    }
    unset($r);

    return $rows;
}

/** Ringkasan total terbayar per komponen POS (tahun ajaran aktif opsional). */
function wali_portal_ringkasan_pos(PDO $pdo, int $santriId, ?int $tm = null, ?int $ts = null): array
{
    if ($santriId <= 0 || !table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return [];
    }
    $sql = '
        SELECT d.pos_slug, d.pos_nama, COALESCE(SUM(d.nominal), 0) AS total
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        WHERE p.santri_id = :sid';
    $params = ['sid' => $santriId];
    if ($tm !== null && $ts !== null) {
        $sql .= ' AND p.tahun_ajaran_mulai = :tm AND p.tahun_ajaran_selesai = :ts';
        $params['tm'] = $tm;
        $params['ts'] = $ts;
    }
    $sql .= ' GROUP BY d.pos_slug, d.pos_nama ORDER BY d.pos_nama ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Pastikan pembayaran milik santri sesi wali; kembalikan baris atau null. */
function wali_portal_fetch_pembayaran_for_wali(PDO $pdo, int $pembayaranId, int $santriId): ?array
{
    if ($pembayaranId <= 0 || $santriId <= 0 || !table_exists($pdo, 'keuangan_pembayaran')) {
        return null;
    }
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 's.nama_santri' : 's.nama';
    $st = $pdo->prepare("
        SELECT p.*, s.nis, {$nameCol} AS nama_santri, s.tingkatan
        FROM keuangan_pembayaran p
        INNER JOIN santri s ON s.id = p.santri_id
        WHERE p.id = :id AND p.santri_id = :sid
        LIMIT 1
    ");
    $st->execute(['id' => $pembayaranId, 'sid' => $santriId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** @return list<array<string, mixed>> */
function wali_portal_cashless_transactions(PDO $pdo, int $santriId, int $limit = 80): array
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_transactions')) {
        return [];
    }
    $limit = max(10, min(200, $limit));
    $st = $pdo->prepare("
        SELECT id, tanggal, jenis, nominal, keterangan, ref_pembayaran_id
        FROM cashless_transactions
        WHERE santri_id = :sid
        ORDER BY tanggal DESC, id DESC
        LIMIT {$limit}
    ");
    $st->execute(['sid' => $santriId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function wali_portal_cashless_saldo(PDO $pdo, int $santriId): ?float
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_accounts')) {
        return null;
    }
    $st = $pdo->prepare('SELECT balance FROM cashless_accounts WHERE santri_id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0.0;
    }

    return (float) ($row['balance'] ?? 0);
}

/** Total belanja (DEBIT) hari ini untuk santri. */
function wali_portal_cashless_debit_hari_ini(PDO $pdo, int $santriId): int
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_transactions')) {
        return 0;
    }
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(nominal), 0)
        FROM cashless_transactions
        WHERE santri_id = :sid AND jenis = 'DEBIT' AND DATE(tanggal) = CURDATE()
    ");
    $st->execute(['sid' => $santriId]);

    return (int) round((float) ($st->fetchColumn() ?: 0));
}

/**
 * Tagihan bulanan kumulatif bulan 1 TA s.d. bulan berjalan (wajib + makan opsional).
 *
 * @return array{
 *   berjalan: array<string, mixed>,
 *   wajib: array<string, mixed>,
 *   rows: list<array<string, mixed>>,
 *   expected_total: int,
 *   paid_total: int,
 *   sisa_total: int,
 *   sy_expected: int,
 *   sy_paid: int,
 *   sy_sisa: int,
 *   mk_expected: int,
 *   mk_paid: int,
 *   mk_sisa: int,
 *   per_bulan_tunggakan: list<array<string, mixed>>,
 *   status: string,
 *   statusClass: string
 * }
 */
function wali_portal_tagihan_sampai_bulan_berjalan(PDO $pdo, int $santriId, string $kelasKategori): array
{
    if (!function_exists('keuangan_periode_berjalan')) {
        require_once __DIR__ . '/keuangan_transaksi.php';
    }
    if (!function_exists('tagihan_wajib_status_kumulatif_ta')) {
        require_once __DIR__ . '/tagihan_bulanan.php';
    }

    $berjalan = keuangan_periode_berjalan($pdo);
    $bulanAkhir = max(1, min(12, (int) ($berjalan['bulan'] ?? 1)));
    $tm = (int) ($berjalan['mulai'] ?? 0);
    $ts = (int) ($berjalan['selesai'] ?? 0);

    $wajib = tagihan_wajib_status_kumulatif_ta($pdo, $santriId, $bulanAkhir, $tm, $ts, $kelasKategori);

    $allRows = keuangan_tagihan_bulanan_rows($pdo, $santriId, $kelasKategori, $bulanAkhir);
    $rows = array_values(array_filter(
        $allRows,
        static fn(array $r): bool => (int) ($r['bulan'] ?? 0) >= 1 && (int) ($r['bulan'] ?? 0) <= $bulanAkhir
    ));

    $mkExpected = 0;
    $mkPaid = 0;
    foreach ($rows as $rw) {
        $mkExpected += (int) ($rw['mk_expected'] ?? 0);
        $mkPaid += (int) ($rw['mk_paid'] ?? 0);
    }
    $mkSisa = max(0, $mkExpected - $mkPaid);

    $syPos = (array) (($wajib['per_pos'] ?? [])['syahriyah'] ?? []);
    $syExpected = (int) ($syPos['expected'] ?? ($wajib['expected_total'] ?? 0));
    $syPaid = (int) ($syPos['paid'] ?? ($wajib['paid_total'] ?? 0));
    $sySisa = (int) ($syPos['sisa'] ?? ($wajib['sisa_total'] ?? 0));

    $expectedTotal = (int) ($wajib['expected_total'] ?? 0) + $mkExpected;
    $paidTotal = (int) ($wajib['paid_total'] ?? 0) + $mkPaid;
    $sisaTotal = (int) ($wajib['sisa_total'] ?? 0) + $mkSisa;

    $perBulanTunggakan = (array) ($wajib['per_bulan'] ?? []);
    foreach ($rows as $rw) {
        $mkSisaBulan = max(0, (int) ($rw['mk_expected'] ?? 0) - (int) ($rw['mk_paid'] ?? 0));
        if ($mkSisaBulan <= 0) {
            continue;
        }
        $bulan = (int) ($rw['bulan'] ?? 0);
        $found = false;
        foreach ($perBulanTunggakan as &$tb) {
            if ((int) ($tb['bulan'] ?? 0) === $bulan) {
                $tb['sisa_total'] = (int) ($tb['sisa_total'] ?? 0) + $mkSisaBulan;
                $found = true;
                break;
            }
        }
        unset($tb);
        if (!$found) {
            $perBulanTunggakan[] = [
                'bulan' => $bulan,
                'label' => (string) ($rw['label'] ?? ('Bulan ' . $bulan)),
                'sisa_total' => $mkSisaBulan,
            ];
        }
    }
    usort($perBulanTunggakan, static fn(array $a, array $b): int => (int) ($a['bulan'] ?? 0) <=> (int) ($b['bulan'] ?? 0));

    if ($expectedTotal <= 0) {
        $status = '—';
        $statusClass = 'secondary';
    } elseif ($sisaTotal <= 0 && $expectedTotal > 0) {
        $status = 'Lunas';
        $statusClass = 'success';
    } elseif ($paidTotal <= 0) {
        $status = 'Belum';
        $statusClass = 'danger';
    } else {
        $status = 'Sebagian';
        $statusClass = 'warning';
    }

    return [
        'berjalan' => $berjalan,
        'wajib' => $wajib,
        'rows' => $rows,
        'expected_total' => $expectedTotal,
        'paid_total' => $paidTotal,
        'sisa_total' => $sisaTotal,
        'sy_expected' => $syExpected,
        'sy_paid' => $syPaid,
        'sy_sisa' => $sySisa,
        'mk_expected' => $mkExpected,
        'mk_paid' => $mkPaid,
        'mk_sisa' => $mkSisa,
        'per_bulan_tunggakan' => $perBulanTunggakan,
        'status' => $status,
        'statusClass' => $statusClass,
    ];
}

/**
 * Parse filter bulan Hijriyah portal wali keaktivan.
 *
 * @param array<string,mixed> $get
 * @return array{year:int,month:int,start:string,end:string,label:string,value:string}
 */
function wali_portal_keaktifan_bulan_parse(PDO $pdo, array $get = []): array
{
    require_once __DIR__ . '/akademik.php';
    require_once __DIR__ . '/hijri_kalender.php';

    $anchor = akademik_hijri_anchor_hari_ini($pdo);
    $year = (int) ($get['tahun_h'] ?? $get['year'] ?? 0);
    $month = (int) ($get['bulan_h'] ?? $get['month'] ?? 0);

    $legacy = trim((string) ($get['bulan'] ?? ''));
    if ($legacy !== '' && preg_match('/^(\d{4})-(\d{2})$/', $legacy, $m)) {
        $year = (int) $m[1];
        $month = (int) $m[2];
    }

    if ($year <= 0) {
        $year = (int) $anchor['y'];
    }
    if ($month <= 0) {
        $month = (int) $anchor['m'];
    }

    $year = max(1300, min(1700, $year));
    $month = max(1, min(12, $month));

    [$start, $end] = akademik_gregorian_range_from_hijri_month($pdo, $year, $month);
    $label = hijri_indeks_ke_nama($month) . ' ' . $year . ' H';

    return [
        'year' => $year,
        'month' => $month,
        'start' => $start,
        'end' => $end,
        'label' => $label,
        'value' => sprintf('%04d-%02d', $year, $month),
    ];
}

/**
 * Rekap keaktivan santri per kegiatan dalam rentang tanggal (hanya kegiatan jadwal tingkatan santri).
 *
 * @return array{
 *   tingkatan:string,
 *   totals: array{hadir:int,izin:int,sakit:int,alpa:int,total:int},
 *   kegiatan: list<array{kegiatan_id:int,nama_kegiatan:string,hadir:int,izin:int,sakit:int,alpa:int,total:int}>
 * }
 */
function wali_portal_keaktifan_per_kegiatan(PDO $pdo, int $santriId, string $startDate, string $endDate, string $tingkatan = ''): array
{
    $empty = [
        'tingkatan' => '',
        'totals' => ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0],
        'kegiatan' => [],
    ];
    if ($santriId <= 0 || !table_exists($pdo, 'presensi')) {
        return $empty;
    }

    $tingkatan = trim($tingkatan);
    if ($tingkatan === '' && table_exists($pdo, 'santri')) {
        $stTg = $pdo->prepare('SELECT tingkatan FROM santri WHERE id = :id LIMIT 1');
        $stTg->execute(['id' => $santriId]);
        $tingkatan = trim((string) ($stTg->fetchColumn() ?: ''));
    }
    if ($tingkatan === '') {
        return array_merge($empty, ['tingkatan' => '']);
    }

    require_once __DIR__ . '/presensi_jadwal.php';

    $st = $pdo->prepare('
        SELECT
            p.kegiatan_id,
            p.tanggal_presensi,
            p.status_presensi,
            COALESCE(NULLIF(TRIM(k.nama_kegiatan), ""), "Tanpa kegiatan") AS nama_kegiatan
        FROM presensi p
        LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
        WHERE p.santri_id = :sid
          AND p.tanggal_presensi BETWEEN :start_date AND :end_date
        ORDER BY p.tanggal_presensi ASC, p.id ASC
    ');
    $st->execute([
        'sid' => $santriId,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);
    $rawRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rawRows as &$rawRow) {
        $rawRow['tingkatan'] = $tingkatan;
    }
    unset($rawRow);

    $rows = presensi_filter_rows_eligible($pdo, $rawRows, $startDate, $endDate);

    $byKeg = [];
    $totals = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0];
    foreach ($rows as $row) {
        $kid = (int) ($row['kegiatan_id'] ?? 0);
        if ($kid <= 0) {
            continue;
        }
        $label = (string) ($row['nama_kegiatan'] ?? 'Tanpa kegiatan');
        $key = $kid . '|' . $label;
        if (!isset($byKeg[$key])) {
            $byKeg[$key] = [
                'kegiatan_id' => $kid,
                'nama_kegiatan' => $label,
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'total' => 0,
            ];
        }
        $status = strtoupper((string) ($row['status_presensi'] ?? ''));
        $byKeg[$key]['total']++;
        $totals['total']++;
        if ($status === 'HADIR') {
            $byKeg[$key]['hadir']++;
            $totals['hadir']++;
        } elseif ($status === 'IZIN') {
            $byKeg[$key]['izin']++;
            $totals['izin']++;
        } elseif ($status === 'SAKIT') {
            $byKeg[$key]['sakit']++;
            $totals['sakit']++;
        } elseif ($status === 'ALPA') {
            $byKeg[$key]['alpa']++;
            $totals['alpa']++;
        }
    }

    $kegiatan = array_values($byKeg);
    usort($kegiatan, static fn(array $a, array $b): int => strcasecmp((string) $a['nama_kegiatan'], (string) $b['nama_kegiatan']));

    return [
        'tingkatan' => $tingkatan,
        'totals' => $totals,
        'kegiatan' => $kegiatan,
    ];
}

/**
 * Penilaian keaktifan tahunan (Baik / Sedang / Buruk) untuk portal wali.
 *
 * @return array{
 *   tahun:int,
 *   row: array<string,mixed>|null,
 *   riwayat: list<array<string,mixed>>
 * }
 */
function wali_portal_keaktifan_penilaian(PDO $pdo, int $santriId, ?int $tahun = null): array
{
    require_once __DIR__ . '/santri_keaktifan_nilai.php';

    $tahun = $tahun ?? (int) date('Y');
    if ($santriId <= 0) {
        return ['tahun' => $tahun, 'scope' => 'tahun', 'row' => null, 'riwayat' => []];
    }

    ensure_santri_nilai_keaktifan_table($pdo);

    return [
        'tahun' => $tahun,
        'scope' => 'tahun',
        'row' => santri_keaktifan_tampilan_tahun($pdo, $santriId, $tahun),
        'riwayat' => santri_keaktifan_tampilan_per_tahun($pdo, $santriId),
    ];
}

/** @return array{0:int,1:int} */
function wali_portal_hijri_month_step_back(int $tahunH, int $bulanH): array
{
    if ($bulanH > 1) {
        return [$tahunH, $bulanH - 1];
    }

    return [$tahunH - 1, 12];
}

/**
 * Baris penilaian Baik/Sedang/Buruk dari total presensi.
 *
 * @param array{hadir:int,izin:int,sakit:int,alpa:int,total:int} $totals
 * @return array<string,mixed>|null
 */
function wali_portal_keaktifan_penilaian_row_dari_totals(PDO $pdo, array $totals): ?array
{
    require_once __DIR__ . '/santri_riwayat.php';

    $hadir = (int) ($totals['hadir'] ?? 0);
    $izin = (int) ($totals['izin'] ?? 0);
    $sakit = (int) ($totals['sakit'] ?? 0);
    $alpa = (int) ($totals['alpa'] ?? 0);
    $total = (int) ($totals['total'] ?? 0);
    if ($total <= 0) {
        return null;
    }

    $goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
    $mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');
    $persen = round($hadir / $total * 100, 1);
    $kategori = santri_category($alpa, $goodMax, $mediumMax);
    $label = santri_riwayat_keaktifan_label_ringkas($kategori);

    return [
        'hadir' => $hadir,
        'izin' => $izin,
        'sakit' => $sakit,
        'alpa' => $alpa,
        'total' => $total,
        'persen_hadir' => $persen,
        'kategori' => $kategori,
        'label' => $label,
        'sumber' => 'presensi',
        'catatan_pengasuh' => '',
        'keterangan' => sprintf(
            'Kehadiran %s%% · Hadir %d · Izin %d · Sakit %d · ALPA %d (dari %d presensi)',
            number_format($persen, 1, ',', '.'),
            $hadir,
            $izin,
            $sakit,
            $alpa,
            $total
        ),
    ];
}

/**
 * Penilaian keaktifan per bulan Hijriyah (Baik / Sedang / Buruk) untuk portal wali.
 *
 * @param array{year:int,month:int,start:string,end:string,label:string} $bulanFilter
 * @return array{
 *   scope:string,
 *   year:int,
 *   month:int,
 *   label_bulan:string,
 *   row: array<string,mixed>|null,
 *   riwayat: list<array<string,mixed>>
 * }
 */
function wali_portal_keaktifan_penilaian_bulan(
    PDO $pdo,
    int $santriId,
    array $bulanFilter,
    string $tingkatan = '',
    bool $withRiwayat = false,
    int $riwayatCount = 6
): array {
    $year = (int) ($bulanFilter['year'] ?? 0);
    $month = (int) ($bulanFilter['month'] ?? 0);
    $labelBulan = (string) ($bulanFilter['label'] ?? '');

    if ($santriId <= 0) {
        return [
            'scope' => 'bulan',
            'year' => $year,
            'month' => $month,
            'label_bulan' => $labelBulan,
            'row' => null,
            'riwayat' => [],
        ];
    }

    $rekap = wali_portal_keaktifan_per_kegiatan(
        $pdo,
        $santriId,
        (string) ($bulanFilter['start'] ?? ''),
        (string) ($bulanFilter['end'] ?? ''),
        $tingkatan
    );

    $riwayat = [];
    if ($withRiwayat && $year > 0 && $month > 0) {
        $riwayat = wali_portal_keaktifan_penilaian_bulan_riwayat(
            $pdo,
            $santriId,
            $year,
            $month,
            $riwayatCount,
            $tingkatan
        );
    }

    return [
        'scope' => 'bulan',
        'year' => $year,
        'month' => $month,
        'label_bulan' => $labelBulan,
        'row' => wali_portal_keaktifan_penilaian_row_dari_totals($pdo, $rekap['totals']),
        'riwayat' => $riwayat,
    ];
}

/**
 * Riwayat penilaian bulanan mundur dari bulan acuan.
 *
 * @return list<array<string,mixed>>
 */
function wali_portal_keaktifan_penilaian_bulan_riwayat(
    PDO $pdo,
    int $santriId,
    int $yearH,
    int $monthH,
    int $count = 6,
    string $tingkatan = ''
): array {
    $count = max(1, min(12, $count));
    $out = [];
    $y = $yearH;
    $m = $monthH;

    for ($i = 0; $i < $count; $i++) {
        $filter = wali_portal_keaktifan_bulan_parse($pdo, ['tahun_h' => $y, 'bulan_h' => $m]);
        $rekap = wali_portal_keaktifan_per_kegiatan(
            $pdo,
            $santriId,
            (string) $filter['start'],
            (string) $filter['end'],
            $tingkatan
        );
        $row = wali_portal_keaktifan_penilaian_row_dari_totals($pdo, $rekap['totals']);
        $out[] = $row !== null
            ? array_merge($row, [
                'year' => $y,
                'month' => $m,
                'label_bulan' => (string) $filter['label'],
            ])
            : [
                'year' => $y,
                'month' => $m,
                'label_bulan' => (string) $filter['label'],
                'label' => 'Belum ada',
                'total' => 0,
            ];

        [$y, $m] = wali_portal_hijri_month_step_back($y, $m);
    }

    return $out;
}
