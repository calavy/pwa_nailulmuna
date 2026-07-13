<?php

declare(strict_types=1);

require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_defs.php';
require_once __DIR__ . '/keuangan_rekonsiliasi.php';

/**
 * @param array<string, mixed> $get
 * @return array{dari:string,sampai:string,arah:string,pos:string,santri_id:int,q:string}
 */
function keuangan_riwayat_pembayaran_parse_filter(array $get): array
{
    // Kompatibilitas URL lama (?bulan=YYYY-MM)
    if (isset($get['bulan']) && !isset($get['dari']) && !isset($get['sampai'])) {
        $bulan = trim((string) $get['bulan']);
        if (preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            $get['dari'] = $bulan . '-01';
            $get['sampai'] = date('Y-m-t', strtotime($get['dari']));
        }
    }

    $dari = trim((string) ($get['dari'] ?? date('Y-m-01')));
    $sampai = trim((string) ($get['sampai'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) {
        $dari = date('Y-m-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
        $sampai = date('Y-m-d');
    }
    if ($dari > $sampai) {
        [$dari, $sampai] = [$sampai, $dari];
    }

    $arah = strtolower(trim((string) ($get['arah'] ?? '')));
    if (!in_array($arah, ['masuk', 'keluar', ''], true)) {
        $arah = '';
    }

    $pos = trim((string) ($get['pos'] ?? ''));
    $santriId = max(0, (int) ($get['santri_id'] ?? 0));
    $q = trim((string) ($get['q'] ?? ''));
    if (mb_strlen($q) > 80) {
        $q = mb_substr($q, 0, 80);
    }

    // Filter santri hanya relevan untuk kas masuk.
    if ($santriId > 0 || $q !== '') {
        if ($arah === 'keluar') {
            $arah = 'masuk';
        }
        if ($pos !== '' && str_starts_with($pos, 'out:')) {
            $pos = '';
        }
    }

    return [
        'dari' => $dari,
        'sampai' => $sampai,
        'arah' => $arah,
        'pos' => $pos,
        'santri_id' => $santriId,
        'q' => $q,
    ];
}

/**
 * Slug pos kategori Awal Tahun dari definisi biaya.
 *
 * @return list<string>
 */
function keuangan_riwayat_pembayaran_awal_tahun_slugs(): array
{
    $slugs = [];
    foreach (keuangan_biaya_definitions() as $def) {
        if ((string) ($def['kategori'] ?? '') !== 'Awal Tahun') {
            continue;
        }
        $slug = strtolower(trim((string) ($def['slug'] ?? '')));
        if ($slug !== '') {
            $slugs[] = $slug;
        }
    }

    return $slugs;
}

/**
 * Kategori masuk utama untuk ringkasan & filter cepat.
 *
 * @return list<array{key:string,label:string,pos:string}>
 */
function keuangan_riwayat_pembayaran_kategori_masuk_utama(): array
{
    return [
        ['key' => 'syahriyah', 'label' => 'Syahriyah', 'pos' => 'kat:syahriyah'],
        ['key' => 'makan', 'label' => 'Makan', 'pos' => 'kat:makan'],
        ['key' => 'saku', 'label' => 'Saku', 'pos' => 'kat:saku'],
        ['key' => 'awal_tahun', 'label' => 'Awal Tahun', 'pos' => 'kat:awal_tahun'],
    ];
}

/**
 * @param array{tipe:string,value:string}|null $posParsed
 */
function keuangan_riwayat_pembayaran_append_pos_sql(
    string &$where,
    array &$params,
    ?array $posParsed,
    bool $detailOk,
    string $pembayaranAlias = 'p'
): void {
    if ($posParsed === null) {
        return;
    }

    if ($posParsed['tipe'] === 'pay' && $detailOk) {
        $where .= ' AND EXISTS (
            SELECT 1 FROM keuangan_pembayaran_detail dx
            WHERE dx.pembayaran_id = ' . $pembayaranAlias . '.id AND dx.pos_slug = :pos_slug
        )';
        $params['pos_slug'] = $posParsed['value'];

        return;
    }

    if ($posParsed['tipe'] === 'kat' && $detailOk) {
        $kat = strtolower(trim((string) $posParsed['value']));
        if ($kat === 'awal_tahun') {
            $where .= " AND {$pembayaranAlias}.jenis_periode = 'AWAL_TAHUN'";

            return;
        }
        if (in_array($kat, ['syahriyah', 'makan', 'saku'], true)) {
            $where .= ' AND EXISTS (
                SELECT 1 FROM keuangan_pembayaran_detail dx
                WHERE dx.pembayaran_id = ' . $pembayaranAlias . '.id AND LOWER(TRIM(dx.pos_slug)) = :pos_slug
            )';
            $params['pos_slug'] = $kat;
        }

        return;
    }

    if ($posParsed['tipe'] === 'out') {
        $val = (string) $posParsed['value'];
        if ($val === '') {
            $where .= " AND (TRIM(p.pos) = '' OR p.pos IS NULL)";
        } else {
            $where .= ' AND TRIM(p.pos) = :pos_out';
            $params['pos_out'] = $val;
        }
    }
}

/**
 * Ringkasan nominal masuk per kategori utama (dari rincian detail).
 *
 * @return array{
 *   syahriyah:int,
 *   makan:int,
 *   saku:int,
 *   awal_tahun:int,
 *   lain:int,
 *   total:int
 * }
 */
function keuangan_riwayat_pembayaran_ringkasan_masuk_kategori(PDO $pdo, string $dari, string $sampai): array
{
    $out = [
        'syahriyah' => 0,
        'makan' => 0,
        'saku' => 0,
        'awal_tahun' => 0,
        'lain' => 0,
        'total' => 0,
    ];
    if (!table_exists($pdo, 'keuangan_pembayaran_detail') || !table_exists($pdo, 'keuangan_pembayaran')) {
        return $out;
    }

    $awalSlugs = array_flip(keuangan_riwayat_pembayaran_awal_tahun_slugs());
    $st = $pdo->prepare('
        SELECT LOWER(TRIM(d.pos_slug)) AS slug, COALESCE(SUM(d.nominal), 0) AS total
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        WHERE p.tanggal_bayar BETWEEN :dari AND :sampai
        GROUP BY LOWER(TRIM(d.pos_slug))
    ');
    $st->execute(['dari' => $dari, 'sampai' => $sampai]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $nom = (int) round((float) ($row['total'] ?? 0));
        if ($nom === 0) {
            continue;
        }
        $slug = (string) ($row['slug'] ?? '');
        if ($slug === 'syahriyah') {
            $out['syahriyah'] += $nom;
        } elseif ($slug === 'makan') {
            $out['makan'] += $nom;
        } elseif ($slug === 'saku') {
            $out['saku'] += $nom;
        } elseif (isset($awalSlugs[$slug])) {
            $out['awal_tahun'] += $nom;
        } else {
            $out['lain'] += $nom;
        }
        $out['total'] += $nom;
    }

    return $out;
}

/**
 * @return list<array{value:string,label:string,group:string}>
 */
function keuangan_riwayat_pembayaran_pos_options(PDO $pdo): array
{
    $opts = [];
    $seen = [];

    $addOpt = static function (string $val, string $label, string $group) use (&$opts, &$seen): void {
        if ($val === '' || isset($seen[$val])) {
            return;
        }
        $seen[$val] = true;
        $opts[] = ['value' => $val, 'label' => $label, 'group' => $group];
    };

    $addOpt('kat:syahriyah', 'Syahriyah', 'Masuk — bulanan');
    $addOpt('kat:makan', 'Makan', 'Masuk — bulanan');
    $addOpt('kat:saku', 'Saku', 'Masuk — bulanan');
    $addOpt('kat:awal_tahun', 'Semua awal tahun', 'Masuk — awal tahun');

    foreach (keuangan_biaya_definitions() as $def) {
        $slug = (string) ($def['slug'] ?? '');
        if ($slug === '' || (string) ($def['kategori'] ?? '') !== 'Awal Tahun') {
            continue;
        }
        $addOpt('pay:' . $slug, (string) ($def['nama'] ?? $slug), 'Masuk — awal tahun');
    }

    if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
        foreach (keuangan_pembayaran_pos_options($pdo) as $po) {
            $slug = (string) ($po['pos_slug'] ?? '');
            if ($slug === '' || in_array($slug, ['syahriyah', 'makan', 'saku'], true)) {
                continue;
            }
            if (!in_array($slug, keuangan_riwayat_pembayaran_awal_tahun_slugs(), true)) {
                $addOpt('pay:' . $slug, (string) ($po['pos_nama'] ?? $slug), 'Masuk — lainnya');
                continue;
            }
            $val = 'pay:' . $slug;
            if (!isset($seen[$val])) {
                $addOpt($val, (string) ($po['pos_nama'] ?? $slug), 'Masuk — awal tahun');
            }
        }
    }

    if (table_exists($pdo, 'keuangan_pengeluaran')) {
        $rows = $pdo->query('
            SELECT DISTINCT TRIM(pos) AS pos
            FROM keuangan_pengeluaran
            WHERE TRIM(pos) <> \'\'
            ORDER BY pos ASC
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $pos = trim((string) ($row['pos'] ?? ''));
            if ($pos === '') {
                continue;
            }
            $addOpt('out:' . $pos, $pos, 'Keluar — pos pengeluaran');
        }
        $hasTanpaPos = (int) ($pdo->query("
            SELECT COUNT(*) FROM keuangan_pengeluaran
            WHERE TRIM(pos) = '' OR pos IS NULL
        ")->fetchColumn() ?: 0);
        if ($hasTanpaPos > 0) {
            $addOpt('out:', '(tanpa pos)', 'Keluar — pos pengeluaran');
        }
    }

    return $opts;
}

/**
 * @return array{tipe:string,value:string}|null
 */
function keuangan_riwayat_pembayaran_parse_pos(string $pos): ?array
{
    if ($pos === '') {
        return null;
    }
    if (str_starts_with($pos, 'pay:')) {
        return ['tipe' => 'pay', 'value' => substr($pos, 4)];
    }
    if (str_starts_with($pos, 'kat:')) {
        return ['tipe' => 'kat', 'value' => substr($pos, 4)];
    }
    if (str_starts_with($pos, 'out:')) {
        return ['tipe' => 'out', 'value' => substr($pos, 4)];
    }

    return null;
}

/**
 * Slug detail yang dipakai untuk nominal baris/total saat filter kat:/pay: (bukan total pembayaran penuh).
 * Null = tetap pakai p.total_nominal (mis. tanpa filter pos, atau kat:awal_tahun).
 *
 * @param array{tipe:string,value:string}|null $posParsed
 */
function keuangan_riwayat_pembayaran_detail_slug_filter(?array $posParsed): ?string
{
    if ($posParsed === null) {
        return null;
    }
    if ($posParsed['tipe'] === 'pay') {
        $slug = strtolower(trim((string) $posParsed['value']));

        return $slug !== '' ? $slug : null;
    }
    if ($posParsed['tipe'] === 'kat') {
        $kat = strtolower(trim((string) $posParsed['value']));
        if (in_array($kat, ['syahriyah', 'makan', 'saku'], true)) {
            return $kat;
        }
    }

    return null;
}

function keuangan_riwayat_pembayaran_label_periode(string $dari, string $sampai): string
{
    require_once __DIR__ . '/datetime_display.php';
    if ($dari === $sampai) {
        return app_format_tanggal_id($dari);
    }

    return app_format_tanggal_id($dari) . ' — ' . app_format_tanggal_id($sampai);
}

/**
 * Potong rentang tanggal menjadi blok per bulan kalender.
 *
 * @return list<array{label:string,periode_teks:string,tanggal_dari:string,tanggal_sampai:string,is_bulan_ini:bool}>
 */
function keuangan_riwayat_pembayaran_rentang_bulan(string $dari, string $sampai): array
{
    $bulanNama = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    if ($dari > $sampai) {
        [$dari, $sampai] = [$sampai, $dari];
    }

    $out = [];
    $cur = $dari;
    while ($cur <= $sampai) {
        $ts = strtotime($cur) ?: time();
        $monthFirst = date('Y-m-01', $ts);
        $monthLast = date('Y-m-t', $ts);
        $chunkDari = $cur > $monthFirst ? $cur : $monthFirst;
        $chunkSampai = $monthLast > $sampai ? $sampai : $monthLast;
        $m = (int) date('n', strtotime($chunkDari));
        $y = (int) date('Y', strtotime($chunkDari));
        $out[] = [
            'label' => ($bulanNama[$m] ?? ('Bulan ' . $m)) . ' ' . $y,
            'periode_teks' => $chunkDari . ' s/d ' . $chunkSampai,
            'tanggal_dari' => $chunkDari,
            'tanggal_sampai' => $chunkSampai,
            'is_bulan_ini' => date('Y-m') === date('Y-m', strtotime($chunkDari)),
        ];
        if ($chunkSampai >= $sampai) {
            break;
        }
        $cur = date('Y-m-d', strtotime($monthLast . ' +1 day') ?: $ts);
    }

    return $out;
}

function keuangan_riwayat_pembayaran_keluar_periode(PDO $pdo, string $dari, string $sampai): int
{
    if (!table_exists($pdo, 'keuangan_pengeluaran')) {
        return 0;
    }
    $st = $pdo->prepare('SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pengeluaran WHERE tanggal BETWEEN :dari AND :sampai');
    $st->execute(['dari' => $dari, 'sampai' => $sampai]);

    return (int) round((float) ($st->fetchColumn() ?: 0));
}

/**
 * Daftar pos pengeluaran yang dipakai dalam rentang (urut total terbesar).
 *
 * @return list<string>
 */
function keuangan_riwayat_pembayaran_pos_keluar_kolom(PDO $pdo, string $dari, string $sampai, bool $operasionalOnly = false): array
{
    if (!table_exists($pdo, 'keuangan_pengeluaran')) {
        return [];
    }

    $opsWhere = $operasionalOnly ? ' AND ' . keuangan_sql_pengeluaran_operasional_where() : '';
    $st = $pdo->prepare('
        SELECT TRIM(pos) AS pos, COALESCE(SUM(nominal), 0) AS total
        FROM keuangan_pengeluaran
        WHERE tanggal BETWEEN :dari AND :sampai' . $opsWhere . '
        GROUP BY TRIM(pos)
        HAVING COALESCE(SUM(nominal), 0) > 0
        ORDER BY total DESC, pos ASC
    ');
    $st->execute(['dari' => $dari, 'sampai' => $sampai]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pos = trim((string) ($row['pos'] ?? ''));
        $out[] = $pos !== '' ? $pos : '(tanpa pos)';
    }

    return $out;
}

/**
 * Nominal keluar per pos dalam satu periode.
 *
 * @return array<string, int>
 */
function keuangan_riwayat_pembayaran_keluar_per_pos_periode(PDO $pdo, string $dari, string $sampai, bool $operasionalOnly = false): array
{
    if (!table_exists($pdo, 'keuangan_pengeluaran')) {
        return [];
    }

    $opsWhere = $operasionalOnly ? ' AND ' . keuangan_sql_pengeluaran_operasional_where() : '';
    $st = $pdo->prepare('
        SELECT TRIM(pos) AS pos, COALESCE(SUM(nominal), 0) AS total
        FROM keuangan_pengeluaran
        WHERE tanggal BETWEEN :dari AND :sampai' . $opsWhere . '
        GROUP BY TRIM(pos)
    ');
    $st->execute(['dari' => $dari, 'sampai' => $sampai]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pos = trim((string) ($row['pos'] ?? ''));
        $key = $pos !== '' ? $pos : '(tanpa pos)';
        $nom = (int) round((float) ($row['total'] ?? 0));
        if ($nom !== 0) {
            $out[$key] = $nom;
        }
    }

    return $out;
}

/** Nilai filter pos keluar (out:...) dari label kolom tabel. */
function keuangan_riwayat_pembayaran_pos_keluar_filter_value(string $posLabel): string
{
    return $posLabel === '(tanpa pos)' ? 'out:' : 'out:' . $posLabel;
}

/**
 * Ringkasan per bulan: Syahriyah, Makan, Saku, Awal Tahun, keluar per pos.
 *
 * @return array{
 *   baris:list<array<string,mixed>>,
 *   total:array<string,int>,
 *   periode_label:string,
 *   kolom_keluar:list<string>
 * }
 */
function keuangan_riwayat_pembayaran_build_ringkasan_bulanan(PDO $pdo, string $dari, string $sampai): array
{
    $baris = [];
    $kolomKeluar = keuangan_riwayat_pembayaran_pos_keluar_kolom($pdo, $dari, $sampai);
    $tot = [
        'syahriyah' => 0,
        'makan' => 0,
        'saku' => 0,
        'awal_tahun' => 0,
        'lain' => 0,
        'total_masuk' => 0,
        'keluar' => 0,
        'bersih' => 0,
        'keluar_pos' => [],
    ];
    foreach ($kolomKeluar as $pk) {
        $tot['keluar_pos'][$pk] = 0;
    }

    foreach (keuangan_riwayat_pembayaran_rentang_bulan($dari, $sampai) as $chunk) {
        $td = (string) $chunk['tanggal_dari'];
        $ts = (string) $chunk['tanggal_sampai'];
        $kat = keuangan_riwayat_pembayaran_ringkasan_masuk_kategori($pdo, $td, $ts);
        $keluarPos = keuangan_riwayat_pembayaran_keluar_per_pos_periode($pdo, $td, $ts);
        $keluar = array_sum($keluarPos);
        $masuk = (int) $kat['total'];
        $rowKeluarPos = [];
        foreach ($kolomKeluar as $pk) {
            $rowKeluarPos[$pk] = (int) ($keluarPos[$pk] ?? 0);
        }
        $row = array_merge($chunk, [
            'syahriyah' => (int) $kat['syahriyah'],
            'makan' => (int) $kat['makan'],
            'saku' => (int) $kat['saku'],
            'awal_tahun' => (int) $kat['awal_tahun'],
            'lain' => (int) $kat['lain'],
            'total_masuk' => $masuk,
            'keluar_pos' => $rowKeluarPos,
            'keluar' => $keluar,
            'bersih' => $masuk - $keluar,
        ]);
        $baris[] = $row;
        foreach (['syahriyah', 'makan', 'saku', 'awal_tahun', 'lain', 'total_masuk', 'keluar', 'bersih'] as $k) {
            $tot[$k] += (int) ($row[$k] ?? 0);
        }
        foreach ($kolomKeluar as $pk) {
            $tot['keluar_pos'][$pk] += (int) ($rowKeluarPos[$pk] ?? 0);
        }
    }

    return [
        'baris' => $baris,
        'total' => $tot,
        'periode_label' => keuangan_riwayat_pembayaran_label_periode($dari, $sampai),
        'kolom_keluar' => $kolomKeluar,
    ];
}

/**
 * Tabel ringkasan bulanan (gaya rekap kas bulanan).
 *
 * @param callable(int): string $fmt
 */
function keuangan_riwayat_pembayaran_render_tabel_ringkasan(array $rekap, callable $fmt): void
{
    if (!function_exists('keuangan_rekap_kas_bulan_fmt_nominal')) {
        require_once __DIR__ . '/keuangan_rekap_kas_bulan.php';
    }

    $baris = $rekap['baris'] ?? [];
    if ($baris === []) {
        echo '<p class="text-muted small mb-0">Tidak ada data pada rentang tanggal ini.</p>';

        return;
    }

    $tot = $rekap['total'] ?? [];
    $kolomKeluar = $rekap['kolom_keluar'] ?? [];
    $totKeluarPos = is_array($tot['keluar_pos'] ?? null) ? $tot['keluar_pos'] : [];
    $td0 = (string) ($baris[0]['tanggal_dari'] ?? '');
    $tsN = (string) ($baris[count($baris) - 1]['tanggal_sampai'] ?? '');
    $nKeluar = count($kolomKeluar);
    $colspanKeluar = max(1, $nKeluar + ($nKeluar > 0 ? 1 : 0));

    echo '<div class="rekap-kas-table-wrap">';
    echo '<table class="table table-sm rekap-kas-table rekap-riwayat-table mb-0">';
    echo '<thead>';
    echo '<tr class="rekap-kas-head-group">';
    echo '<th rowspan="2" class="rekap-kas-col-bulan">Bulan</th>';
    echo '<th rowspan="2" class="rekap-kas-col-periode">Periode</th>';
    echo '<th colspan="5" class="rekap-kas-grp-masuk">Kas masuk</th>';
    echo '<th colspan="' . $colspanKeluar . '" class="rekap-kas-grp-keluar">Kas keluar</th>';
    echo '<th rowspan="2" class="text-end rekap-kas-grp-bersih">Bersih</th>';
    echo '</tr>';
    echo '<tr class="rekap-kas-head-detail">';
    echo '<th class="text-end">Syahriyah</th><th class="text-end">Makan</th><th class="text-end">Saku</th>';
    echo '<th class="text-end">Awal Tahun</th><th class="text-end">Total masuk</th>';
    if ($nKeluar === 0) {
        echo '<th class="text-end">Total</th>';
    } else {
        foreach ($kolomKeluar as $posLabel) {
            $short = mb_strlen($posLabel) > 14 ? mb_substr($posLabel, 0, 13) . '…' : $posLabel;
            echo '<th class="text-end rekap-kas-col-pos-keluar" title="' . htmlspecialchars($posLabel) . '">' . htmlspecialchars($short) . '</th>';
        }
        echo '<th class="text-end">Total keluar</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($baris as $row) {
        $dariBulan = (string) ($row['tanggal_dari'] ?? '');
        $sampaiBulan = (string) ($row['tanggal_sampai'] ?? '');
        $hrefSy = $dariBulan !== '' ? keuangan_riwayat_pembayaran_href($dariBulan, $sampaiBulan, 'masuk', 'kat:syahriyah') : null;
        $hrefMakan = $dariBulan !== '' ? keuangan_riwayat_pembayaran_href($dariBulan, $sampaiBulan, 'masuk', 'kat:makan') : null;
        $hrefSaku = $dariBulan !== '' ? keuangan_riwayat_pembayaran_href($dariBulan, $sampaiBulan, 'masuk', 'kat:saku') : null;
        $hrefAwal = $dariBulan !== '' ? keuangan_riwayat_pembayaran_href($dariBulan, $sampaiBulan, 'masuk', 'kat:awal_tahun') : null;
        $hrefMasuk = $dariBulan !== '' ? keuangan_riwayat_pembayaran_href($dariBulan, $sampaiBulan, 'masuk', '') : null;
        $hrefKeluar = $dariBulan !== '' ? keuangan_riwayat_pembayaran_href($dariBulan, $sampaiBulan, 'keluar', '') : null;
        $rowKeluarPos = is_array($row['keluar_pos'] ?? null) ? $row['keluar_pos'] : [];

        $cls = !empty($row['is_bulan_ini']) ? ' class="bulan-ini"' : '';
        echo '<tr' . $cls . '>';
        echo '<td class="rekap-kas-col-bulan"><strong>' . htmlspecialchars((string) ($row['label'] ?? '')) . '</strong>';
        if (!empty($row['is_bulan_ini'])) {
            echo ' <span class="badge bg-success">berjalan</span>';
        }
        echo '</td>';
        echo '<td class="rekap-kas-col-periode">' . htmlspecialchars((string) ($row['periode_teks'] ?? '')) . '</td>';
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['syahriyah'] ?? 0), $fmt, 'masuk', $hrefSy) . '</td>';
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['makan'] ?? 0), $fmt, 'masuk', $hrefMakan) . '</td>';
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['saku'] ?? 0), $fmt, 'masuk', $hrefSaku) . '</td>';
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['awal_tahun'] ?? 0), $fmt, 'masuk', $hrefAwal) . '</td>';
        echo '<td class="text-end rekap-kas-masuk rekap-kas-masuk-total">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['total_masuk'] ?? 0), $fmt, 'masuk', $hrefMasuk) . '</td>';
        if ($nKeluar === 0) {
            echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['keluar'] ?? 0), $fmt, 'keluar', $hrefKeluar) . '</td>';
        } else {
            foreach ($kolomKeluar as $posLabel) {
                $nomPos = (int) ($rowKeluarPos[$posLabel] ?? 0);
                $hrefPos = $dariBulan !== ''
                    ? keuangan_riwayat_pembayaran_href($dariBulan, $sampaiBulan, 'keluar', keuangan_riwayat_pembayaran_pos_keluar_filter_value($posLabel))
                    : null;
                echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal($nomPos, $fmt, 'keluar', $hrefPos) . '</td>';
            }
            echo '<td class="text-end rekap-kas-keluar rekap-kas-keluar-total">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['keluar'] ?? 0), $fmt, 'keluar', $hrefKeluar) . '</td>';
        }
        $bersih = (int) ($row['bersih'] ?? 0);
        echo '<td class="text-end rekap-kas-saldo' . ($bersih < 0 ? ' text-danger' : '') . '">' . htmlspecialchars($fmt($bersih)) . '</td>';
        echo '</tr>';
    }

    $hrefTaSy = $td0 !== '' && $tsN !== '' ? keuangan_riwayat_pembayaran_href($td0, $tsN, 'masuk', 'kat:syahriyah') : null;
    $hrefTaMakan = $td0 !== '' && $tsN !== '' ? keuangan_riwayat_pembayaran_href($td0, $tsN, 'masuk', 'kat:makan') : null;
    $hrefTaSaku = $td0 !== '' && $tsN !== '' ? keuangan_riwayat_pembayaran_href($td0, $tsN, 'masuk', 'kat:saku') : null;
    $hrefTaAwal = $td0 !== '' && $tsN !== '' ? keuangan_riwayat_pembayaran_href($td0, $tsN, 'masuk', 'kat:awal_tahun') : null;
    $hrefTaMasuk = $td0 !== '' && $tsN !== '' ? keuangan_riwayat_pembayaran_href($td0, $tsN, 'masuk', '') : null;
    $hrefTaKeluar = $td0 !== '' && $tsN !== '' ? keuangan_riwayat_pembayaran_href($td0, $tsN, 'keluar', '') : null;

    echo '</tbody><tfoot><tr>';
    echo '<td class="rekap-kas-col-bulan" colspan="2"><strong>Jumlah</strong></td>';
    echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['syahriyah'] ?? 0), $fmt, 'masuk', $hrefTaSy) . '</td>';
    echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['makan'] ?? 0), $fmt, 'masuk', $hrefTaMakan) . '</td>';
    echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['saku'] ?? 0), $fmt, 'masuk', $hrefTaSaku) . '</td>';
    echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['awal_tahun'] ?? 0), $fmt, 'masuk', $hrefTaAwal) . '</td>';
    echo '<td class="text-end rekap-kas-masuk rekap-kas-masuk-total">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['total_masuk'] ?? 0), $fmt, 'masuk', $hrefTaMasuk) . '</td>';
    if ($nKeluar === 0) {
        echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['keluar'] ?? 0), $fmt, 'keluar', $hrefTaKeluar) . '</td>';
    } else {
        foreach ($kolomKeluar as $posLabel) {
            $nomPos = (int) ($totKeluarPos[$posLabel] ?? 0);
            $hrefPos = $td0 !== '' && $tsN !== ''
                ? keuangan_riwayat_pembayaran_href($td0, $tsN, 'keluar', keuangan_riwayat_pembayaran_pos_keluar_filter_value($posLabel))
                : null;
            echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal($nomPos, $fmt, 'keluar', $hrefPos) . '</td>';
        }
        echo '<td class="text-end rekap-kas-keluar rekap-kas-keluar-total">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['keluar'] ?? 0), $fmt, 'keluar', $hrefTaKeluar) . '</td>';
    }
    $bersihTot = (int) ($tot['bersih'] ?? 0);
    echo '<td class="text-end rekap-kas-saldo' . ($bersihTot < 0 ? ' text-danger' : '') . '">' . htmlspecialchars($fmt($bersihTot)) . '</td>';
    echo '</tr></tfoot></table></div>';
}

function keuangan_riwayat_pembayaran_ringkasan_table_css(): string
{
    if (!function_exists('keuangan_rekap_kas_bulan_css')) {
        require_once __DIR__ . '/keuangan_rekap_kas_bulan.php';
    }

    return keuangan_rekap_kas_bulan_css() . '
body.riwayat-rekap-page .app-main .container-fluid { max-width: none; }
.rekap-riwayat-table { min-width: 960px; }
.rekap-kas-table .rekap-kas-grp-bersih { background: #1e3a8a !important; color: #fff !important; }
.rekap-kas-table .rekap-kas-col-pos-keluar { max-width: 6.5rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.72rem; }
.rekap-kas-table td.rekap-kas-keluar-total { font-weight: 700; }
.riwayat-rekap-hint { font-size: 0.8rem; color: #64748b; }
';
}

/** Label filter aktif untuk tampilan (satu baris). */
function keuangan_riwayat_pembayaran_filter_label(array $filter, array $posOptions): string
{
    $parts = [];
    if ((string) ($filter['arah'] ?? '') === 'masuk') {
        $parts[] = 'Masuk saja';
    } elseif ((string) ($filter['arah'] ?? '') === 'keluar') {
        $parts[] = 'Keluar saja';
    }

    $pos = trim((string) ($filter['pos'] ?? ''));
    if ($pos !== '') {
        if ($pos === 'out:') {
            $label = '(tanpa pos)';
        } else {
            $label = $pos;
            foreach ($posOptions as $opt) {
                if ((string) ($opt['value'] ?? '') === $pos) {
                    $label = (string) ($opt['label'] ?? $pos);
                    break;
                }
            }
        }
        $parts[] = $label;
    }

    $santriId = (int) ($filter['santri_id'] ?? 0);
    $q = trim((string) ($filter['q'] ?? ''));
    if ($santriId > 0) {
        $parts[] = 'Santri #' . $santriId;
    } elseif ($q !== '') {
        $parts[] = 'Cari: ' . $q;
    }

    return $parts === [] ? '' : implode(' · ', $parts);
}

/**
 * @param array{dari:string,sampai:string,arah:string,pos:string,santri_id?:int,q?:string} $filter
 * @return array{
 *   rows:list<array<string,mixed>>,
 *   total_masuk:int,
 *   total_keluar:int,
 *   jumlah_masuk:int,
 *   jumlah_keluar:int
 * }
 */
function keuangan_riwayat_pembayaran_fetch(PDO $pdo, array $filter, int $limit = 500): array
{
    $dari = (string) $filter['dari'];
    $sampai = (string) $filter['sampai'];
    $arah = (string) $filter['arah'];
    $posParsed = keuangan_riwayat_pembayaran_parse_pos((string) $filter['pos']);
    $santriId = max(0, (int) ($filter['santri_id'] ?? 0));
    $q = trim((string) ($filter['q'] ?? ''));
    $limit = max(50, min(2000, $limit));

    $rows = [];
    $totalMasuk = 0;
    $totalKeluar = 0;
    $jumlahMasuk = 0;
    $jumlahKeluar = 0;

    $fetchMasuk = $arah !== 'keluar';
    $fetchKeluar = $arah !== 'masuk';
    // POS masuk (pay:/kat:) hanya untuk pembayaran; POS keluar (out:) hanya untuk pengeluaran.
    if ($posParsed !== null && $posParsed['tipe'] === 'out') {
        $fetchMasuk = false;
    }
    if ($posParsed !== null && in_array($posParsed['tipe'], ['pay', 'kat'], true)) {
        $fetchKeluar = false;
    }
    if ($santriId > 0 || $q !== '') {
        $fetchKeluar = false;
        $fetchMasuk = true;
    }

    if ($fetchMasuk && table_exists($pdo, 'keuangan_pembayaran')) {
        if (!function_exists('keuangan_riwayat_pembayaran_sql_q_filter')) {
            require_once __DIR__ . '/keuangan_cek_pembayaran.php';
        }
        $detailOk = table_exists($pdo, 'keuangan_pembayaran_detail');
        $params = ['dari' => $dari, 'sampai' => $sampai];
        $where = 'WHERE p.tanggal_bayar BETWEEN :dari AND :sampai';
        if ($posParsed !== null && $posParsed['tipe'] !== 'out') {
            keuangan_riwayat_pembayaran_append_pos_sql($where, $params, $posParsed, $detailOk, 'p');
        } elseif ($posParsed !== null && $posParsed['tipe'] === 'pay' && !$detailOk) {
            $where .= ' AND 1=0';
        }
        if ($santriId > 0) {
            $where .= ' AND p.santri_id = :santri_id';
            $params['santri_id'] = $santriId;
        }
        [$qSql, $qParams] = keuangan_riwayat_pembayaran_sql_q_filter($pdo, $q, 's');
        $where .= $qSql;
        $params = array_merge($params, $qParams);

        $detailSlug = $detailOk ? keuangan_riwayat_pembayaran_detail_slug_filter($posParsed) : null;

        $posSelect = $detailOk
            ? ", (
                SELECT GROUP_CONCAT(DISTINCT d.pos_nama ORDER BY d.id SEPARATOR ', ')
                FROM keuangan_pembayaran_detail d
                WHERE d.pembayaran_id = p.id
            ) AS pos_rincian"
            : ", '' AS pos_rincian";

        if ($detailSlug !== null) {
            $nominalSelect = "(
                SELECT COALESCE(SUM(dn.nominal), 0)
                FROM keuangan_pembayaran_detail dn
                WHERE dn.pembayaran_id = p.id AND LOWER(TRIM(dn.pos_slug)) = :detail_slug
            ) AS nominal";
            $params['detail_slug'] = $detailSlug;
        } else {
            $nominalSelect = 'p.total_nominal AS nominal';
        }

        $sql = "
            SELECT p.id, p.tanggal_bayar AS tanggal, {$nominalSelect},
                   p.keterangan, p.metode_bayar, p.jenis_periode, p.santri_id,
                   s.nis, COALESCE(NULLIF(s.nama_santri, ''), s.nama) AS nama_santri
                   {$posSelect}
            FROM keuangan_pembayaran p
            INNER JOIN santri s ON s.id = p.santri_id
            {$where}
            ORDER BY p.tanggal_bayar DESC, p.id DESC
            LIMIT {$limit}
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $masukRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($masukRows as $r) {
            $nom = (int) round((float) ($r['nominal'] ?? 0));
            $rows[] = [
                'arah' => 'masuk',
                'tipe' => 'pembayaran',
                'id' => (int) ($r['id'] ?? 0),
                'santri_id' => (int) ($r['santri_id'] ?? 0),
                'tanggal' => (string) ($r['tanggal'] ?? ''),
                'subjek' => (string) ($r['nama_santri'] ?? ''),
                'subjek_extra' => (string) ($r['nis'] ?? ''),
                'pos' => trim((string) ($r['pos_rincian'] ?? '')) !== '' ? (string) $r['pos_rincian'] : '—',
                'nominal' => $nom,
                'keterangan' => (string) ($r['keterangan'] ?? ''),
                'metode' => (string) ($r['metode_bayar'] ?? 'KAS'),
                'jenis_periode' => (string) ($r['jenis_periode'] ?? ''),
            ];
        }

        if ($detailSlug !== null) {
            $sumSt = $pdo->prepare('
                SELECT COALESCE(SUM(d.nominal), 0), COUNT(DISTINCT p.id)
                FROM keuangan_pembayaran p
                INNER JOIN santri s ON s.id = p.santri_id
                INNER JOIN keuangan_pembayaran_detail d
                    ON d.pembayaran_id = p.id AND LOWER(TRIM(d.pos_slug)) = :detail_slug_sum
                ' . $where);
            $sumParams = $params;
            $sumParams['detail_slug_sum'] = $detailSlug;
            unset($sumParams['detail_slug']);
            $sumSt->execute($sumParams);
        } else {
            $sumSt = $pdo->prepare('
                SELECT COALESCE(SUM(p.total_nominal), 0), COUNT(*)
                FROM keuangan_pembayaran p
                INNER JOIN santri s ON s.id = p.santri_id
                ' . $where);
            $sumSt->execute($params);
        }
        $sumRow = $sumSt->fetch(PDO::FETCH_NUM);
        if ($sumRow) {
            $totalMasuk = (int) round((float) ($sumRow[0] ?? 0));
            $jumlahMasuk = (int) ($sumRow[1] ?? 0);
        }
    }

    if ($fetchKeluar && table_exists($pdo, 'keuangan_pengeluaran')) {
        $params = ['dari' => $dari, 'sampai' => $sampai];
        $where = 'WHERE p.tanggal BETWEEN :dari AND :sampai';
        if ($posParsed !== null && $posParsed['tipe'] === 'out') {
            keuangan_riwayat_pembayaran_append_pos_sql($where, $params, $posParsed, true, 'p');
        }

        $sql = "
            SELECT p.id, p.tanggal, p.nominal, p.keterangan, p.pos,
                   p.penanggung_jawab, p.metode_keluar, p.alokasi_nama
            FROM keuangan_pengeluaran p
            {$where}
            ORDER BY p.tanggal DESC, p.id DESC
            LIMIT {$limit}
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $keluarRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($keluarRows as $r) {
            $nom = (int) round((float) ($r['nominal'] ?? 0));
            $posLabel = trim((string) ($r['pos'] ?? ''));
            $alok = trim((string) ($r['alokasi_nama'] ?? ''));
            if ($alok !== '') {
                $posLabel .= ($posLabel !== '' ? ' · ' : '') . $alok;
            }
            $rows[] = [
                'arah' => 'keluar',
                'tipe' => 'pengeluaran',
                'id' => (int) ($r['id'] ?? 0),
                'santri_id' => 0,
                'tanggal' => (string) ($r['tanggal'] ?? ''),
                'subjek' => (string) ($r['penanggung_jawab'] ?? ''),
                'subjek_extra' => '',
                'pos' => $posLabel !== '' ? $posLabel : '—',
                'nominal' => $nom,
                'keterangan' => (string) ($r['keterangan'] ?? ''),
                'metode' => (string) ($r['metode_keluar'] ?? 'KAS'),
                'jenis_periode' => '',
            ];
        }

        $sumSt = $pdo->prepare('SELECT COALESCE(SUM(p.nominal), 0), COUNT(*) FROM keuangan_pengeluaran p ' . $where);
        $sumSt->execute($params);
        $sumRow = $sumSt->fetch(PDO::FETCH_NUM);
        if ($sumRow) {
            $totalKeluar = (int) round((float) ($sumRow[0] ?? 0));
            $jumlahKeluar = (int) ($sumRow[1] ?? 0);
        }
    }

    usort($rows, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($b['tanggal'] ?? ''), (string) ($a['tanggal'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });

    if (count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
    }

    return [
        'rows' => $rows,
        'total_masuk' => $totalMasuk,
        'total_keluar' => $totalKeluar,
        'jumlah_masuk' => $jumlahMasuk,
        'jumlah_keluar' => $jumlahKeluar,
    ];
}

/**
 * @param array<string, string|int> $filter
 */
function keuangan_riwayat_pembayaran_query_string(array $filter, array $extra = []): string
{
    $qs = array_merge([
        'dari' => $filter['dari'] ?? date('Y-m-01'),
        'sampai' => $filter['sampai'] ?? date('Y-m-d'),
        'arah' => $filter['arah'] ?? '',
        'pos' => $filter['pos'] ?? '',
        'santri_id' => (int) ($filter['santri_id'] ?? 0) > 0 ? (string) (int) $filter['santri_id'] : '',
        'q' => trim((string) ($filter['q'] ?? '')),
    ], $extra);
    $qs = array_filter($qs, static fn ($v): bool => $v !== null && $v !== '' && $v !== '0');

    return http_build_query($qs);
}

/** URL halaman rekap masuk/keluar dengan filter opsional. */
function keuangan_riwayat_pembayaran_href(
    ?string $dari = null,
    ?string $sampai = null,
    string $arah = '',
    string $pos = ''
): string {
    $filter = keuangan_riwayat_pembayaran_parse_filter([
        'dari' => $dari ?? date('Y-m-01'),
        'sampai' => $sampai ?? date('Y-m-d'),
        'arah' => $arah,
        'pos' => $pos,
    ]);

    return app_href('/keuangan/riwayat_pembayaran.php?' . keuangan_riwayat_pembayaran_query_string($filter));
}
