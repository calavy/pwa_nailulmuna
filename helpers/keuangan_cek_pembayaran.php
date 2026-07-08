<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_defs.php';
require_once __DIR__ . '/keuangan_rekap.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/tagihan_bulanan.php';
require_once __DIR__ . '/santri_list_sort.php';

function keuangan_cek_pembayaran_cache_invalidate(): void
{
    if (isset($_SESSION['keuangan_cek_pembayaran_v1'])) {
        unset($_SESSION['keuangan_cek_pembayaran_v1']);
    }
    if (function_exists('keuangan_rekap_tagihan_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_rekap_tagihan_bulan.php';
        keuangan_rekap_tagihan_cache_invalidate();
    }
}

/**
 * Status tagihan awal tahun per santri aktif.
 *
 * @return array{
 *   body: list<array<string, mixed>>,
 *   sum_tagihan: int,
 *   sum_bayar: int,
 *   count_lunas: int,
 *   count_belum: int,
 *   count_sebagian: int,
 *   count_tidak_wajib: int,
 *   tables_ok: bool
 * }
 */
function keuangan_cek_pembayaran_awal_tahun_list_compute(
    PDO $pdo,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    string $sortMode
): array {
    $tablesOk = table_exists($pdo, 'keuangan_pembayaran') && table_exists($pdo, 'keuangan_pembayaran_detail');
    $biayaDefs = keuangan_biaya_definitions();
    $defNama = [];
    foreach ($biayaDefs as $def) {
        if ((string) ($def['kategori'] ?? '') === 'Awal Tahun') {
            $slug = (string) ($def['slug'] ?? '');
            if ($slug !== '') {
                $defNama[$slug] = (string) ($def['nama'] ?? $slug);
            }
        }
    }

    if (!function_exists('tagihan_santri_jenis_ta')) {
        require_once __DIR__ . '/tagihan_santri_masuk.php';
    }

    $rows = $tablesOk ? tagihan_santri_aktif_rows_cached($pdo, false) : [];
    $body = [];
    $sumTagihan = 0;
    $sumBayar = 0;
    $countLunas = 0;
    $countBelum = 0;
    $countSebagian = 0;
    $countTidakWajib = 0;

    foreach ($rows as $s) {
        $sid = (int) ($s['id'] ?? 0);
        $expected = 0;
        $paid = 0;
        $sisa = 0;
        $posRows = [];

        if ($tablesOk && $sid > 0) {
            $breakdown = keuangan_tagihan_breakdown_for_santri(
                $pdo,
                $sid,
                'AWAL_TAHUN',
                0,
                $tahunAjaranMulai,
                $tahunAjaranSelesai,
                $biayaDefs
            );
            foreach ($breakdown as $slug => $info) {
                $exp = (int) ($info['expected'] ?? 0);
                if ($exp <= 0) {
                    continue;
                }
                $p = (int) ($info['paid'] ?? 0);
                $si = (int) ($info['sisa'] ?? 0);
                $expected += $exp;
                $paid += min($p, $exp);
                $sisa += $si;
                $posRows[] = [
                    'slug' => (string) $slug,
                    'nama' => $defNama[(string) $slug] ?? (string) $slug,
                    'expected' => $exp,
                    'paid' => $p,
                    'sisa' => $si,
                    'status' => (string) ($info['status'] ?? '—'),
                ];
            }
        }

        if ($expected <= 0) {
            $status = 'Tidak wajib';
            $statusClass = 'secondary';
            $countTidakWajib++;
        } elseif ($sisa <= 0) {
            $status = 'Lunas';
            $statusClass = 'success';
            $countLunas++;
        } elseif ($paid <= 0) {
            $status = 'Belum';
            $statusClass = 'danger';
            $countBelum++;
        } else {
            $status = 'Sebagian';
            $statusClass = 'warning';
            $countSebagian++;
        }

        if ($expected > 0) {
            $sumTagihan += $expected;
            $sumBayar += min($paid, $expected);
        }

        $jenisTa = $tablesOk && $sid > 0
            ? tagihan_santri_jenis_ta($pdo, $sid, $tahunAjaranMulai)
            : 'semua';

        $body[] = [
            'id' => $sid,
            'nis' => (string) ($s['nis'] ?? ''),
            'nama' => (string) ($s['nama_santri'] ?? ''),
            'tingkatan' => trim((string) ($s['tingkatan'] ?? '')),
            'kategori' => trim((string) ($s['kategori_kelas'] ?? '')),
            'jenis_santri' => $jenisTa === 'baru' ? 'Baru' : ($jenisTa === 'lama' ? 'Lama' : '—'),
            'tagihan' => $expected,
            'bayar' => $paid,
            'sisa' => $sisa,
            'status' => $status,
            'statusClass' => $statusClass,
            'pos' => $posRows,
        ];
    }

    $body = santri_list_sort_rows($body, $sortMode);

    return [
        'body' => $body,
        'sum_tagihan' => $sumTagihan,
        'sum_bayar' => $sumBayar,
        'count_lunas' => $countLunas,
        'count_belum' => $countBelum,
        'count_sebagian' => $countSebagian,
        'count_tidak_wajib' => $countTidakWajib,
        'tables_ok' => $tablesOk,
    ];
}

/**
 * @return array{
 *   body: list<array<string, mixed>>,
 *   sum_tagihan: int,
 *   sum_bayar: int,
 *   count_lunas: int,
 *   count_belum: int,
 *   count_sebagian: int,
 *   count_tidak_wajib: int,
 *   tables_ok: bool
 * }
 */
function keuangan_cek_pembayaran_awal_tahun_list_cached(
    PDO $pdo,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    string $sortMode,
    int $ttlSec = 600
): array {
    static $requestCache = [];
    $cacheKey = 'awal:' . $tahunAjaranMulai . ':' . $tahunAjaranSelesai . ':' . $sortMode;
    if (isset($requestCache[$cacheKey])) {
        return $requestCache[$cacheKey];
    }

    if (isset($_SESSION['user'])) {
        $bucket = $_SESSION['keuangan_cek_pembayaran_v1'] ?? null;
        if (is_array($bucket)) {
            $hit = $bucket[$cacheKey] ?? null;
            if (is_array($hit) && ($hit['expires'] ?? 0) > time()) {
                $requestCache[$cacheKey] = $hit['data'];

                return $hit['data'];
            }
        }
    }

    $data = keuangan_cek_pembayaran_awal_tahun_list_compute($pdo, $tahunAjaranMulai, $tahunAjaranSelesai, $sortMode);
    $requestCache[$cacheKey] = $data;

    if (isset($_SESSION['user'])) {
        if (!isset($_SESSION['keuangan_cek_pembayaran_v1']) || !is_array($_SESSION['keuangan_cek_pembayaran_v1'])) {
            $_SESSION['keuangan_cek_pembayaran_v1'] = [];
        }
        $_SESSION['keuangan_cek_pembayaran_v1'][$cacheKey] = [
            'expires' => time() + max(60, $ttlSec),
            'data' => $data,
        ];
    }

    return $data;
}

/**
 * Gabungan status bulanan (bulan berjalan) + awal tahun per santri.
 *
 * @return array{
 *   body: list<array<string, mixed>>,
 *   sum_sisa: int,
 *   count_belum_lunas: int,
 *   tables_ok: bool
 * }
 */
function keuangan_cek_pembayaran_gabungan_compute(
    PDO $pdo,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    string $sortMode
): array {
    $bulanan = tagihan_syahriyah_list_cached($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, $sortMode);
    $awal = keuangan_cek_pembayaran_awal_tahun_list_cached($pdo, $tahunAjaranMulai, $tahunAjaranSelesai, $sortMode);

    $awalById = [];
    foreach ($awal['body'] ?? [] as $r) {
        $awalById[(int) ($r['id'] ?? 0)] = $r;
    }

    $body = [];
    $sumSisa = 0;
    $countBelumLunas = 0;

    foreach ($bulanan['body'] ?? [] as $b) {
        $sid = (int) ($b['id'] ?? 0);
        $a = $awalById[$sid] ?? null;
        $sisaBulanan = (int) ($b['sisa'] ?? 0);
        $sisaAwal = $a !== null ? (int) ($a['sisa'] ?? 0) : 0;
        $totalSisa = $sisaBulanan + $sisaAwal;
        $belumLunas = $totalSisa > 0
            || in_array((string) ($b['status'] ?? ''), ['Belum', 'Sebagian'], true)
            || ($a !== null && in_array((string) ($a['status'] ?? ''), ['Belum', 'Sebagian'], true));

        if ($belumLunas) {
            $countBelumLunas++;
            $sumSisa += $totalSisa;
        }

        $body[] = [
            'id' => $sid,
            'nis' => (string) ($b['nis'] ?? ''),
            'nama' => (string) ($b['nama'] ?? ''),
            'tingkatan' => (string) ($b['tingkatan'] ?? ''),
            'kategori' => (string) ($b['kategori'] ?? ''),
            'bulanan_status' => (string) ($b['status'] ?? '—'),
            'bulanan_statusClass' => (string) ($b['statusClass'] ?? 'secondary'),
            'bulanan_sisa' => $sisaBulanan,
            'awal_status' => $a !== null ? (string) ($a['status'] ?? '—') : '—',
            'awal_statusClass' => $a !== null ? (string) ($a['statusClass'] ?? 'secondary') : 'secondary',
            'awal_sisa' => $sisaAwal,
            'total_sisa' => $totalSisa,
            'belum_lunas' => $belumLunas,
        ];
    }

    $body = santri_list_sort_rows($body, $sortMode);

    return [
        'body' => $body,
        'sum_sisa' => $sumSisa,
        'count_belum_lunas' => $countBelumLunas,
        'tables_ok' => (bool) (($bulanan['tables_ok'] ?? false) && ($awal['tables_ok'] ?? false)),
    ];
}

/**
 * Ringkasan keuangan terpadu: saldo kas, piutang bulanan & awal tahun, rekap TA.
 *
 * @return array<string, mixed>
 */
function keuangan_cek_pembayaran_snapshot(
    PDO $pdo,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    int $bulanTagihan
): array {
    if (!function_exists('keuangan_dashboard_kas_bank_ringkas')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
    }
    if (!function_exists('keuangan_build_rekap_kas_bulanan')) {
        require_once __DIR__ . '/keuangan_rekap_kas_bulan.php';
    }
    if (!function_exists('keuangan_rekap_tagihan_agregat_pos')) {
        require_once __DIR__ . '/keuangan_rekap_tagihan_bulan.php';
    }

    $today = date('Y-m-d');
    $biayaDefs = keuangan_biaya_definitions();
    $kasBank = keuangan_dashboard_kas_bank_ringkas($pdo, $today);
    $rekapKas = keuangan_build_rekap_kas_bulanan($pdo, $tahunAjaranMulai, $tahunAjaranSelesai, $bulanTagihan);
    $bulananAgg = keuangan_rekap_tagihan_agregat_pos(
        $pdo,
        'BULANAN',
        $bulanTagihan,
        $tahunAjaranMulai,
        $tahunAjaranSelesai,
        $biayaDefs
    );
    $awalAgg = keuangan_rekap_tagihan_agregat_pos(
        $pdo,
        'AWAL_TAHUN',
        1,
        $tahunAjaranMulai,
        $tahunAjaranSelesai,
        $biayaDefs
    );
    $rekapTa = keuangan_rekap_tagihan_bulanan_ta($pdo, $tahunAjaranMulai, $tahunAjaranSelesai, $bulanTagihan);

    $periode = keuangan_periode_berjalan($pdo, $today);
    $bulanSlots = pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
    $slotAktif = pondok_slot_dari_bulan_tagihan($bulanSlots, $bulanTagihan);
    $bulanLabel = (string) ($slotAktif['label'] ?? ('Bulan ' . $bulanTagihan));

    $piutangTotal = (int) ($bulananAgg['sisa'] ?? 0) + (int) ($awalAgg['sisa'] ?? 0);

    return [
        'as_of' => $today,
        'as_of_label' => (string) ($kasBank['as_of_label'] ?? $today),
        'ta_label' => pondok_tahun_ajaran_label($pdo, ['mulai' => $tahunAjaranMulai, 'selesai' => $tahunAjaranSelesai]),
        'bulan' => $bulanTagihan,
        'bulan_label' => $bulanLabel,
        'kas_bank' => $kasBank,
        'rekap_kas' => [
            'saldo_akhir' => (int) ($rekapKas['saldo_akhir'] ?? 0),
            'saldo_akhir_fisik' => (int) ($rekapKas['saldo_akhir_fisik'] ?? 0),
            'selisih_saldo' => (int) ($rekapKas['selisih_saldo'] ?? 0),
            'masuk_total' => (int) ($rekapKas['total']['masuk_total'] ?? 0),
            'keluar' => (int) ($rekapKas['total']['keluar'] ?? 0),
            'bulan_berjalan_label' => (string) ($rekapKas['bulan_berjalan_label'] ?? ''),
        ],
        'bulanan' => $bulananAgg,
        'awal_tahun' => $awalAgg,
        'piutang_total' => $piutangTotal,
        'rekap_ta' => [
            'total' => $rekapTa['total'] ?? [],
            'awal_tahun' => $rekapTa['awal_tahun'] ?? [],
            'baris' => $rekapTa['baris'] ?? [],
        ],
        'periode_berjalan' => $periode,
    ];
}

/**
 * Filter baris tabel cek pembayaran.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function keuangan_cek_pembayaran_filter_rows(array $rows, string $filterStatus, string $q): array
{
    $body = $rows;
    if ($q !== '') {
        $qLower = strtolower($q);
        $body = array_values(array_filter($body, static function (array $r) use ($qLower): bool {
            $namaCari = strtolower((string) ($r['nama'] ?? '') . ' ' . (string) ($r['nis'] ?? ''));

            return str_contains($namaCari, $qLower);
        }));
    }

    if ($filterStatus === 'harus_bayar' || $filterStatus === 'belum_lunas') {
        $body = array_values(array_filter($body, static function (array $r): bool {
            if (array_key_exists('belum_lunas', $r)) {
                return (bool) $r['belum_lunas'];
            }
            $st = (string) ($r['status'] ?? '');

            return in_array($st, ['Belum', 'Sebagian'], true) || (int) ($r['sisa'] ?? 0) > 0;
        }));
    } elseif ($filterStatus === 'lunas') {
        $body = array_values(array_filter($body, static function (array $r): bool {
            if (array_key_exists('belum_lunas', $r)) {
                return !(bool) $r['belum_lunas'];
            }

            return (string) ($r['status'] ?? '') === 'Lunas';
        }));
    }

    return $body;
}

/**
 * Hitung ulang ringkasan dari baris terfilter.
 *
 * @param list<array<string, mixed>> $body
 * @return array{sum_tagihan:int,sum_bayar:int,sum_sisa:int,count_lunas:int,count_belum:int,count_sebagian:int}
 */
function keuangan_cek_pembayaran_ringkas_from_body(array $body): array
{
    $sumTagihan = 0;
    $sumBayar = 0;
    $sumSisa = 0;
    $countLunas = 0;
    $countBelum = 0;
    $countSebagian = 0;

    foreach ($body as $r) {
        if (array_key_exists('total_sisa', $r)) {
            $sumSisa += (int) ($r['total_sisa'] ?? 0);
            if (!empty($r['belum_lunas'])) {
                $countBelum++;
            } else {
                $countLunas++;
            }
            continue;
        }
        $tagihan = (int) ($r['tagihan'] ?? 0);
        $bayar = (int) ($r['bayar'] ?? 0);
        $sisa = (int) ($r['sisa'] ?? 0);
        $sumTagihan += $tagihan;
        $sumBayar += min($bayar, $tagihan);
        $sumSisa += $sisa;
        $status = (string) ($r['status'] ?? '');
        if ($status === 'Lunas') {
            $countLunas++;
        } elseif ($status === 'Belum') {
            $countBelum++;
        } elseif ($status === 'Sebagian') {
            $countSebagian++;
        }
    }

    return [
        'sum_tagihan' => $sumTagihan,
        'sum_bayar' => $sumBayar,
        'sum_sisa' => $sumSisa,
        'count_lunas' => $countLunas,
        'count_belum' => $countBelum,
        'count_sebagian' => $countSebagian,
    ];
}
