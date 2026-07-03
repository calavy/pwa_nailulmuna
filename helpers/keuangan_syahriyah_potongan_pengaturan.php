<?php

declare(strict_types=1);

require_once __DIR__ . '/keuangan_syahriyah_potongan.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_ta_context.php';
require_once __DIR__ . '/santri_list_sort.php';
require_once __DIR__ . '/keuangan_pengaturan_sections.php';

function keuangan_syahriyah_potongan_pengaturan_url(array $extra = []): string
{
    return keuangan_pengaturan_url('santri_bulanan', array_merge(['sub' => 'potongan'], $extra));
}

/** @return array{ok:bool,message:string,redirect:string} */
function keuangan_syahriyah_potongan_pengaturan_handle_post(PDO $pdo, array $post, int $userId): array
{
    $base = keuangan_syahriyah_potongan_pengaturan_url();
    $action = trim((string) ($post['action'] ?? ''));
    $santriId = (int) ($post['santri_id'] ?? 0);
    $q = trim((string) ($post['_redir_q'] ?? ''));

    $buildRedirect = static function (int $sid = 0) use ($q): string {
        $extra = [];
        if ($sid > 0) {
            $extra['santri_id'] = $sid;
        }
        if ($q !== '') {
            $extra['q'] = $q;
        }

        return keuangan_syahriyah_potongan_pengaturan_url($extra);
    };

    if ($action === 'simpan_potongan') {
        $result = keuangan_syahriyah_potongan_simpan($pdo, $post, $userId);

        return [
            'ok' => $result['ok'],
            'message' => $result['message'],
            'redirect' => $buildRedirect((int) ($post['santri_id'] ?? 0)),
        ];
    }
    if ($action === 'hapus_potongan') {
        $result = keuangan_syahriyah_potongan_hapus($pdo, $santriId);

        return [
            'ok' => $result['ok'],
            'message' => $result['message'],
            'redirect' => $buildRedirect(),
        ];
    }
    if ($action === 'tambah_jeda') {
        $result = keuangan_syahriyah_potongan_jeda_tambah($pdo, $post);

        return [
            'ok' => $result['ok'],
            'message' => $result['message'],
            'redirect' => $buildRedirect($santriId),
        ];
    }
    if ($action === 'hapus_jeda') {
        $result = keuangan_syahriyah_potongan_jeda_hapus(
            $pdo,
            (int) ($post['jeda_id'] ?? 0),
            $santriId
        );

        return [
            'ok' => $result['ok'],
            'message' => $result['message'],
            'redirect' => $buildRedirect($santriId),
        ];
    }

    return ['ok' => false, 'message' => 'Aksi potongan tidak dikenali.', 'redirect' => $base];
}

/**
 * @return array<string, mixed>
 */
function keuangan_syahriyah_potongan_pengaturan_load(PDO $pdo, array $get): array
{
    keuangan_ensure_schema_deferred($pdo);
    santri_list_sort_mode($get['santri_sort'] ?? null);

    $q = trim((string) ($get['q'] ?? ''));
    $editSantriId = (int) ($get['santri_id'] ?? 0);
    $tampilSemua = isset($get['semua']) && (string) $get['semua'] === '1';
    $berjalan = keuangan_periode_berjalan($pdo);
    $keuanganTa = keuangan_ta_resolve($pdo);
    $bulanMap = keuangan_bulan_map($pdo);
    $bulanBerjalan = (int) $berjalan['bulan'];
    $taMulai = (int) $keuanganTa['mulai'];
    $taSelesai = (int) $keuanganTa['selesai'];
    $bulkCtx = keuangan_syahriyah_bulk_context($pdo, $bulanBerjalan, $taMulai, $taSelesai);
    $tierTarifMap = $bulkCtx['tarifByTier'];

    $santriAktif = [];
    if (table_exists($pdo, 'santri')) {
        $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
        $katExpr = column_exists($pdo, 'santri', 'kategori_kelas') ? 'kategori_kelas' : (column_exists($pdo, 'santri', 'tingkatan') ? 'tingkatan' : "''");
        $activeExpr = column_exists($pdo, 'santri', 'is_aktif') ? ' WHERE COALESCE(is_aktif, 1) = 1 ' : '';
        $santriAktif = $pdo->query("SELECT id, nis, {$nameExpr} AS nama, tingkatan, {$katExpr} AS kategori FROM santri {$activeExpr} ORDER BY " . santri_list_order_sql('santri'))->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $tierByKategori = [];
        foreach ($santriAktif as &$santriRow) {
            $katKey = trim((string) ($santriRow['kategori'] ?? ''));
            if (!isset($tierByKategori[$katKey])) {
                $tierByKategori[$katKey] = keuangan_tier_key_from_kelas($katKey, $pdo);
            }
            $santriRow['tier'] = $tierByKategori[$katKey];
        }
        unset($santriRow);
    }

    $editRow = null;
    $editPotongan = null;
    if ($editSantriId > 0) {
        foreach ($santriAktif as $s) {
            if ((int) ($s['id'] ?? 0) === $editSantriId) {
                $editRow = $s;
                break;
            }
        }
        if ($editRow) {
            $editPotongan = keuangan_syahriyah_potongan_for_santri($pdo, $editSantriId);
        }
    }

    $jedaRows = $editSantriId > 0 ? keuangan_syahriyah_potongan_jeda_list($pdo, $editSantriId) : [];

    $listRows = keuangan_syahriyah_potongan_list_rows($pdo, $q, false, !$tampilSemua && $q === '');
    $keteranganSuggest = keuangan_syahriyah_potongan_keterangan_suggest();
    $jumlahAktif = 0;
    foreach ($listRows as &$lr) {
        if ((int) ($lr['potongan_aktif'] ?? 0) === 1 && (float) ($lr['persen'] ?? 0) > 0) {
            $jumlahAktif++;
        }
        $sid = (int) ($lr['id'] ?? 0);
        $kat = trim((string) ($lr['kategori_kelas'] ?? ''));
        $sim = keuangan_syahriyah_simulasi($pdo, $sid, $kat, $bulanBerjalan, $taMulai, $taSelesai, $bulkCtx);
        $lr['sim_dasar'] = (int) ($sim['expected_dasar'] ?? 0);
        $lr['sim_expected'] = (int) ($sim['expected'] ?? 0);
        $lr['sim_dijeda'] = !empty($sim['potongan_dijeda']);
    }
    unset($lr);

    $potonganBaseUrl = keuangan_syahriyah_potongan_pengaturan_url();
    $potonganQs = static function (array $extra = []) use ($q): string {
        $params = array_merge(['bagian' => 'santri_bulanan', 'sub' => 'potongan'], $extra);
        if ($q !== '' && !isset($params['q'])) {
            $params['q'] = $q;
        }
        $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');

        return '?' . http_build_query($params);
    };

    return compact(
        'q',
        'editSantriId',
        'tampilSemua',
        'berjalan',
        'keuanganTa',
        'bulanMap',
        'bulanBerjalan',
        'taMulai',
        'taSelesai',
        'tierTarifMap',
        'santriAktif',
        'editRow',
        'editPotongan',
        'jedaRows',
        'listRows',
        'keteranganSuggest',
        'jumlahAktif',
        'potonganBaseUrl',
        'potonganQs'
    );
}
