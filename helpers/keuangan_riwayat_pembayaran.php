<?php

declare(strict_types=1);

require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_defs.php';

/**
 * @param array<string, mixed> $get
 * @return array{dari:string,sampai:string,arah:string,pos:string}
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

    return [
        'dari' => $dari,
        'sampai' => $sampai,
        'arah' => $arah,
        'pos' => $pos,
    ];
}

/**
 * @return list<array{value:string,label:string,group:string}>
 */
function keuangan_riwayat_pembayaran_pos_options(PDO $pdo): array
{
    $opts = [];
    $seen = [];

    foreach (keuangan_biaya_definitions() as $def) {
        $slug = (string) ($def['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $val = 'pay:' . $slug;
        $seen[$val] = true;
        $opts[] = [
            'value' => $val,
            'label' => (string) ($def['nama'] ?? $slug),
            'group' => 'Masuk — POS pembayaran',
        ];
    }

    if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
        foreach (keuangan_pembayaran_pos_options($pdo) as $po) {
            $slug = (string) ($po['pos_slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $val = 'pay:' . $slug;
            if (isset($seen[$val])) {
                continue;
            }
            $seen[$val] = true;
            $opts[] = [
                'value' => $val,
                'label' => (string) ($po['pos_nama'] ?? $slug),
                'group' => 'Masuk — POS pembayaran',
            ];
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
            $val = 'out:' . $pos;
            if (isset($seen[$val])) {
                continue;
            }
            $seen[$val] = true;
            $opts[] = [
                'value' => $val,
                'label' => $pos,
                'group' => 'Keluar — pos pengeluaran',
            ];
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
    if (str_starts_with($pos, 'out:')) {
        return ['tipe' => 'out', 'value' => substr($pos, 4)];
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
 * @param array{dari:string,sampai:string,arah:string,pos:string} $filter
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
    $limit = max(50, min(2000, $limit));

    $rows = [];
    $totalMasuk = 0;
    $totalKeluar = 0;
    $jumlahMasuk = 0;
    $jumlahKeluar = 0;

    $fetchMasuk = $arah !== 'keluar';
    $fetchKeluar = $arah !== 'masuk';

    if ($fetchMasuk && table_exists($pdo, 'keuangan_pembayaran')) {
        $detailOk = table_exists($pdo, 'keuangan_pembayaran_detail');
        $params = ['dari' => $dari, 'sampai' => $sampai];
        $where = 'WHERE p.tanggal_bayar BETWEEN :dari AND :sampai';
        if ($posParsed !== null && $posParsed['tipe'] === 'pay' && $detailOk) {
            $where .= ' AND EXISTS (
                SELECT 1 FROM keuangan_pembayaran_detail dx
                WHERE dx.pembayaran_id = p.id AND dx.pos_slug = :pos_slug
            )';
            $params['pos_slug'] = $posParsed['value'];
        }

        $posSelect = $detailOk
            ? ", (
                SELECT GROUP_CONCAT(DISTINCT d.pos_nama ORDER BY d.id SEPARATOR ', ')
                FROM keuangan_pembayaran_detail d
                WHERE d.pembayaran_id = p.id
            ) AS pos_rincian"
            : ", '' AS pos_rincian";

        $sql = "
            SELECT p.id, p.tanggal_bayar AS tanggal, p.total_nominal AS nominal,
                   p.keterangan, p.metode_bayar, p.jenis_periode,
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

        $sumSt = $pdo->prepare('SELECT COALESCE(SUM(p.total_nominal), 0), COUNT(*) FROM keuangan_pembayaran p ' . $where);
        $sumSt->execute($params);
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
            $where .= ' AND TRIM(p.pos) = :pos_out';
            $params['pos_out'] = $posParsed['value'];
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
 * @param array<string, string> $filter
 */
function keuangan_riwayat_pembayaran_query_string(array $filter, array $extra = []): string
{
    $qs = array_merge([
        'dari' => $filter['dari'] ?? date('Y-m-01'),
        'sampai' => $filter['sampai'] ?? date('Y-m-d'),
        'arah' => $filter['arah'] ?? '',
        'pos' => $filter['pos'] ?? '',
    ], $extra);
    $qs = array_filter($qs, static fn ($v): bool => $v !== null && $v !== '');

    return http_build_query($qs);
}
