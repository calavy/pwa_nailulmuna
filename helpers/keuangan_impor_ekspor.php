<?php

declare(strict_types=1);

require_once __DIR__ . '/excel.php';
require_once __DIR__ . '/keuangan_defs.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_pembayaran_admin.php';
require_once __DIR__ . '/keuangan_jurnal.php';
require_once __DIR__ . '/keuangan_alokasi.php';
require_once __DIR__ . '/operasional_audit.php';
require_once __DIR__ . '/pembayaran_edit_token.php';

/** @return list<string> */
function keuangan_impor_ekspor_masuk_headers(): array
{
    return [
        'grup_key',
        'tanggal_bayar',
        'nis',
        'jenis_periode',
        'bulan_tagihan',
        'tahun_ajaran_mulai',
        'tahun_ajaran_selesai',
        'metode_bayar',
        'pos_slug',
        'pos_nama',
        'nominal',
        'keterangan',
    ];
}

/** @return list<string> */
function keuangan_impor_ekspor_keluar_headers(): array
{
    return [
        'tanggal',
        'penanggung_jawab',
        'pos',
        'alokasi_nama',
        'nominal',
        'metode_keluar',
        'keterangan',
        'no_bukti',
    ];
}

/** Super admin atau pemegang token koreksi pembayaran. */
function keuangan_impor_ekspor_boleh_destruktif(PDO $pdo): bool
{
    if (function_exists('is_super_admin') && is_super_admin()) {
        return true;
    }
    pembayaran_edit_token_ensure_schema($pdo);

    return pembayaran_edit_token_user_boleh_edit($pdo);
}

/** @return list<list<string|int|null>> */
function keuangan_impor_ekspor_template_masuk(): array
{
    return [
        keuangan_impor_ekspor_masuk_headers(),
        [
            'M1',
            '2026-07-01',
            '2024001',
            'BULANAN',
            '2026-07',
            2025,
            2026,
            'KAS',
            'syahriyah',
            'Syahriyah',
            350000,
            '',
        ],
    ];
}

/** @return list<list<string|int|null>> */
function keuangan_impor_ekspor_template_keluar(): array
{
    return [
        keuangan_impor_ekspor_keluar_headers(),
        [
            '2026-07-01',
            'Bendahara',
            'Operasional',
            'Dana Umum',
            100000,
            'KAS',
            'Contoh',
            '',
        ],
    ];
}

function keuangan_impor_ekspor_parse_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return $raw;
    }
    if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $raw, $m)) {
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        if ($d > 12 && $mo <= 12) {
            // d/m/Y
        } elseif ($mo > 12 && $d <= 12) {
            $tmp = $d;
            $d = $mo;
            $mo = $tmp;
        }
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }
    if (is_numeric($raw)) {
        $n = (float) $raw;
        if ($n > 20000 && $n < 80000) {
            $ts = (int) round(($n - 25569) * 86400);

            return gmdate('Y-m-d', $ts);
        }
    }

    return null;
}

/**
 * @return array{bulan:?int, label:string}
 */
function keuangan_impor_ekspor_parse_bulan_tagihan(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['bulan' => null, 'label' => ''];
    }
    if (preg_match('/^(\d{4})-(\d{1,2})$/', $raw, $m)) {
        $bulan = (int) $m[2];
        if ($bulan >= 1 && $bulan <= 12) {
            return ['bulan' => $bulan, 'label' => sprintf('%04d-%02d', (int) $m[1], $bulan)];
        }
    }
    if (ctype_digit($raw)) {
        $bulan = (int) $raw;
        if ($bulan >= 1 && $bulan <= 12) {
            return ['bulan' => $bulan, 'label' => (string) $bulan];
        }
    }

    return ['bulan' => null, 'label' => $raw];
}

function keuangan_impor_ekspor_format_bulan_export(?int $bulan, string $tanggalBayar, int $tahunMulai): string
{
    if ($bulan === null || $bulan < 1 || $bulan > 12) {
        return '';
    }
    $tahun = $tahunMulai > 0 ? $tahunMulai : (int) substr($tanggalBayar, 0, 4);
    if (preg_match('/^(\d{4})-/', $tanggalBayar, $m)) {
        $tahun = (int) $m[1];
    }

    return sprintf('%04d-%02d', $tahun, $bulan);
}

function keuangan_impor_ekspor_default_akun_id(PDO $pdo, string $metode): int
{
    $metode = strtoupper(trim($metode));
    $akunRows = keuangan_fetch_akun_aktif($pdo);
    if ($akunRows === []) {
        return 0;
    }
    $preferJenis = $metode === 'TRANSFER' ? 'BANK' : 'KAS';
    $fallback = 0;
    foreach ($akunRows as $a) {
        $id = (int) ($a['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if ($fallback === 0) {
            $fallback = $id;
        }
        $jenis = strtoupper((string) ($a['jenis_akun'] ?? ''));
        $isDefault = (int) ($a['is_default'] ?? 0) === 1;
        if ($jenis === $preferJenis && $isDefault) {
            return $id;
        }
    }
    foreach ($akunRows as $a) {
        $id = (int) ($a['id'] ?? 0);
        $jenis = strtoupper((string) ($a['jenis_akun'] ?? ''));
        if ($id > 0 && $jenis === $preferJenis) {
            return $id;
        }
    }

    return $fallback;
}

/**
 * @return list<list<string|int|null>>
 */
function keuangan_impor_ekspor_build_masuk_rows(PDO $pdo): array
{
    $out = [keuangan_impor_ekspor_masuk_headers()];
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return $out;
    }
    ensure_santri_identity_columns($pdo);
    $hasMetode = column_exists($pdo, 'keuangan_pembayaran', 'metode_bayar');
    $metodeSel = $hasMetode ? 'p.metode_bayar' : "'KAS' AS metode_bayar";
    $sql = "
        SELECT p.id, p.tanggal_bayar, p.jenis_periode, p.bulan_tagihan,
               p.tahun_ajaran_mulai, p.tahun_ajaran_selesai, p.keterangan,
               {$metodeSel},
               s.nis,
               d.pos_slug, d.pos_nama, d.nominal
        FROM keuangan_pembayaran p
        INNER JOIN keuangan_pembayaran_detail d ON d.pembayaran_id = p.id
        LEFT JOIN santri s ON s.id = p.santri_id
        ORDER BY p.tanggal_bayar ASC, p.id ASC, d.id ASC
    ";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int) ($r['id'] ?? 0);
        $bulan = isset($r['bulan_tagihan']) && $r['bulan_tagihan'] !== null && $r['bulan_tagihan'] !== ''
            ? (int) $r['bulan_tagihan']
            : null;
        $tgl = (string) ($r['tanggal_bayar'] ?? '');
        $out[] = [
            'P' . $pid,
            $tgl,
            (string) ($r['nis'] ?? ''),
            (string) ($r['jenis_periode'] ?? 'BULANAN'),
            keuangan_impor_ekspor_format_bulan_export($bulan, $tgl, (int) ($r['tahun_ajaran_mulai'] ?? 0)),
            (int) ($r['tahun_ajaran_mulai'] ?? 0),
            (int) ($r['tahun_ajaran_selesai'] ?? 0),
            strtoupper((string) ($r['metode_bayar'] ?? 'KAS')),
            (string) ($r['pos_slug'] ?? ''),
            (string) ($r['pos_nama'] ?? ''),
            (int) round((float) ($r['nominal'] ?? 0)),
            (string) ($r['keterangan'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @return list<list<string|int|null>>
 */
function keuangan_impor_ekspor_build_keluar_rows(PDO $pdo): array
{
    $out = [keuangan_impor_ekspor_keluar_headers()];
    if (!table_exists($pdo, 'keuangan_pengeluaran')) {
        return $out;
    }
    $hasMetode = column_exists($pdo, 'keuangan_pengeluaran', 'metode_keluar');
    $hasBukti = column_exists($pdo, 'keuangan_pengeluaran', 'no_bukti');
    $metodeSel = $hasMetode ? 'metode_keluar' : "'KAS' AS metode_keluar";
    $buktiSel = $hasBukti ? 'no_bukti' : "NULL AS no_bukti";
    $sql = "
        SELECT tanggal, penanggung_jawab, pos, alokasi_nama, nominal, keterangan,
               {$metodeSel}, {$buktiSel}
        FROM keuangan_pengeluaran
        ORDER BY tanggal ASC, id ASC
    ";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            (string) ($r['tanggal'] ?? ''),
            (string) ($r['penanggung_jawab'] ?? ''),
            (string) ($r['pos'] ?? ''),
            (string) ($r['alokasi_nama'] ?? ''),
            (int) round((float) ($r['nominal'] ?? 0)),
            strtoupper((string) ($r['metode_keluar'] ?? 'KAS')),
            (string) ($r['keterangan'] ?? ''),
            (string) ($r['no_bukti'] ?? ''),
        ];
    }

    return $out;
}

/**
 * Eksekusi hapus data keuangan pondok (syahriyah, pengeluaran, pemasukan) — saku & cashless tetap.
 *
 * @return array{ok:bool,message:string,counts?:array<string,int|array<string,int>>}
 */
function keuangan_wipe_pondok_execute(PDO $pdo, int $userId, string $alasan): array
{
    ensure_keuangan_transaksi_tables($pdo);
    ensure_operasional_audit_table($pdo);
    require_once __DIR__ . '/cashless_koperasi.php';
    if (function_exists('cashless_koperasi_ensure_schema')) {
        cashless_koperasi_ensure_schema($pdo);
    }

    $cntKeluar = table_exists($pdo, 'keuangan_pengeluaran')
        ? (int) $pdo->query('SELECT COUNT(*) FROM keuangan_pengeluaran')->fetchColumn()
        : 0;
    $cntPemasukan = table_exists($pdo, 'keuangan_pemasukan')
        ? (int) $pdo->query('SELECT COUNT(*) FROM keuangan_pemasukan')->fetchColumn()
        : 0;
    $cntJurnalPondok = 0;
    $pembayaranCounts = [
        'deleted_headers' => 0,
        'kept_saku' => 0,
        'stripped_mixed' => 0,
        'detail_non_saku' => 0,
    ];

    try {
        $pdo->beginTransaction();

        if (table_exists($pdo, 'akuntansi_jurnal_umum')) {
            $cntJurnalPondok = (int) $pdo->query("
                SELECT COUNT(*) FROM akuntansi_jurnal_umum
                WHERE ref_type IN ('pengeluaran','pemasukan')
            ")->fetchColumn();
            $pdo->exec("
                DELETE FROM akuntansi_jurnal_umum
                WHERE ref_type IN ('pengeluaran','pemasukan')
            ");
        }

        if (table_exists($pdo, 'keuangan_pengeluaran')) {
            $pdo->exec('DELETE FROM keuangan_pengeluaran');
        }
        if (table_exists($pdo, 'keuangan_pemasukan')) {
            $pdo->exec('DELETE FROM keuangan_pemasukan');
        }

        $pembayaranCounts = keuangan_wipe_pondok_pembayaran($pdo);

        operasional_audit_log(
            $pdo,
            OPERASIONAL_AUDIT_MODUL_KEUANGAN,
            'DELETE',
            0,
            [
                'aksi' => 'wipe_pondok_tanpa_saku',
                'pengeluaran' => $cntKeluar,
                'pemasukan' => $cntPemasukan,
                'pembayaran' => $pembayaranCounts,
                'jurnal_pondok' => $cntJurnalPondok,
            ],
            null,
            $userId,
            $alasan
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menghapus: ' . $e->getMessage()];
    }

    if (function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
        keuangan_dashboard_cache_invalidate();
    }

    $pb = $pembayaranCounts;

    return [
        'ok' => true,
        'message' => sprintf(
            'Keuangan pondok dihapus: %d pengeluaran, %d pemasukan, %d pembayaran pondok dihapus, %d pembayaran saku dipertahankan (%d dicampur disesuaikan). Saldo cashless & transaksi saku tidak diubah.',
            $cntKeluar,
            $cntPemasukan,
            (int) ($pb['deleted_headers'] ?? 0),
            (int) ($pb['kept_saku'] ?? 0) + (int) ($pb['stripped_mixed'] ?? 0),
            (int) ($pb['stripped_mixed'] ?? 0)
        ),
        'counts' => [
            'pengeluaran' => $cntKeluar,
            'pemasukan' => $cntPemasukan,
            'pembayaran' => $pembayaranCounts,
            'jurnal_pondok' => $cntJurnalPondok,
        ],
    ];
}

/**
 * @return array{ok:bool,message:string,counts?:array<string,int|array<string,int>>}
 */
function keuangan_impor_ekspor_wipe_all(PDO $pdo, int $userId, string $alasan, string $konfirmasi): array
{
    if (!keuangan_impor_ekspor_boleh_destruktif($pdo)) {
        return ['ok' => false, 'message' => 'Hanya super admin (atau pemegang token koreksi) yang boleh menghapus seluruh data.'];
    }
    if (trim($konfirmasi) !== 'HAPUS SEMUA') {
        return ['ok' => false, 'message' => 'Konfirmasi harus diketik persis: HAPUS SEMUA'];
    }
    $alasan = trim($alasan);
    if ($alasan === '') {
        return ['ok' => false, 'message' => 'Alasan penghapusan wajib diisi.'];
    }

    return keuangan_wipe_pondok_execute($pdo, $userId, $alasan);
}

/**
 * @param list<array<string, string>> $rows
 * @return array{ok:bool,message:string,errors:list<string>,groups:array<string,array<string,mixed>>,row_ok:int,row_err:int}
 */
function keuangan_impor_ekspor_validate_masuk(PDO $pdo, array $rows): array
{
    $errors = [];
    $groups = [];
    $rowOk = 0;
    $rowErr = 0;
    $nisCache = [];

    $stNis = null;
    if (table_exists($pdo, 'santri')) {
        ensure_santri_identity_columns($pdo);
        $stNis = $pdo->prepare('SELECT id FROM santri WHERE nis = :nis LIMIT 1');
    }

    foreach ($rows as $i => $raw) {
        $line = $i + 2;
        $grup = trim((string) ($raw['grup_key'] ?? ''));
        $nis = trim((string) ($raw['nis'] ?? ''));
        $tgl = keuangan_impor_ekspor_parse_date((string) ($raw['tanggal_bayar'] ?? ''));
        $jenis = strtoupper(trim((string) ($raw['jenis_periode'] ?? 'BULANAN')));
        $bulanParsed = keuangan_impor_ekspor_parse_bulan_tagihan((string) ($raw['bulan_tagihan'] ?? ''));
        $tahunMulai = (int) ($raw['tahun_ajaran_mulai'] ?? 0);
        $tahunSelesai = (int) ($raw['tahun_ajaran_selesai'] ?? 0);
        $metode = strtoupper(trim((string) ($raw['metode_bayar'] ?? 'KAS')));
        $posSlug = keuangan_pembayaran_pos_slug_normalize((string) ($raw['pos_slug'] ?? ''));
        $posNama = trim((string) ($raw['pos_nama'] ?? ''));
        $nominal = keuangan_money_input_to_int((string) ($raw['nominal'] ?? '0'));
        $ket = trim((string) ($raw['keterangan'] ?? ''));

        $rowErrors = [];
        if ($grup === '') {
            $rowErrors[] = 'grup_key kosong';
        }
        if ($nis === '') {
            $rowErrors[] = 'nis kosong';
        }
        if ($tgl === null) {
            $rowErrors[] = 'tanggal_bayar tidak valid';
        }
        if (!in_array($jenis, ['BULANAN', 'AWAL_TAHUN'], true)) {
            $rowErrors[] = 'jenis_periode harus BULANAN/AWAL_TAHUN';
        }
        if ($jenis === 'BULANAN' && $bulanParsed['bulan'] === null && trim((string) ($raw['bulan_tagihan'] ?? '')) !== '') {
            $rowErrors[] = 'bulan_tagihan tidak valid';
        }
        if ($tahunMulai <= 0 || $tahunSelesai <= 0) {
            $rowErrors[] = 'tahun_ajaran wajib';
        }
        if (!in_array($metode, ['KAS', 'TRANSFER'], true)) {
            $rowErrors[] = 'metode_bayar harus KAS/TRANSFER';
        }
        if ($posSlug === '') {
            $rowErrors[] = 'pos_slug kosong';
        }
        if ($nominal <= 0) {
            $rowErrors[] = 'nominal harus > 0';
        }

        $santriId = 0;
        if ($nis !== '' && $stNis !== null) {
            if (!array_key_exists($nis, $nisCache)) {
                $stNis->execute(['nis' => $nis]);
                $nisCache[$nis] = (int) ($stNis->fetchColumn() ?: 0);
            }
            $santriId = $nisCache[$nis];
            if ($santriId <= 0) {
                $rowErrors[] = 'NIS tidak ditemukan: ' . $nis;
            }
        } elseif ($nis !== '') {
            $rowErrors[] = 'Tabel santri tidak tersedia';
        }

        if ($rowErrors !== []) {
            $rowErr++;
            $errors[] = 'Baris ' . $line . ': ' . implode('; ', $rowErrors);
            continue;
        }

        if ($posNama === '') {
            $posNama = $posSlug;
        }
        if ($jenis !== 'BULANAN') {
            $bulanParsed['bulan'] = null;
        }

        if (!isset($groups[$grup])) {
            $groups[$grup] = [
                'santri_id' => $santriId,
                'nis' => $nis,
                'tanggal_bayar' => $tgl,
                'jenis_periode' => $jenis,
                'bulan_tagihan' => $bulanParsed['bulan'],
                'tahun_ajaran_mulai' => $tahunMulai,
                'tahun_ajaran_selesai' => $tahunSelesai,
                'metode_bayar' => $metode,
                'keterangan' => $ket,
                'details' => [],
                'lines' => [],
            ];
        } else {
            $g = &$groups[$grup];
            if ((int) $g['santri_id'] !== $santriId) {
                $rowErr++;
                $errors[] = 'Baris ' . $line . ': grup_key ' . $grup . ' punya NIS berbeda';
                unset($g);
                continue;
            }
            if ((string) $g['tanggal_bayar'] !== (string) $tgl
                || (string) $g['jenis_periode'] !== $jenis
                || (string) $g['metode_bayar'] !== $metode
                || (int) $g['tahun_ajaran_mulai'] !== $tahunMulai
                || (int) $g['tahun_ajaran_selesai'] !== $tahunSelesai
                || (int) ($g['bulan_tagihan'] ?? 0) !== (int) ($bulanParsed['bulan'] ?? 0)
            ) {
                $rowErr++;
                $errors[] = 'Baris ' . $line . ': grup_key ' . $grup . ' header tidak konsisten';
                unset($g);
                continue;
            }
            if ($ket !== '' && (string) $g['keterangan'] === '') {
                $g['keterangan'] = $ket;
            }
            unset($g);
        }

        $groups[$grup]['details'][] = [
            'slug' => $posSlug,
            'nama' => $posNama,
            'nominal' => $nominal,
        ];
        $groups[$grup]['lines'][] = $line;
        $rowOk++;
    }

    return [
        'ok' => $rowErr === 0 && $rowOk > 0,
        'message' => $rowOk > 0
            ? sprintf('Validasi masuk: %d baris OK, %d error, %d grup pembayaran.', $rowOk, $rowErr, count($groups))
            : 'Tidak ada baris masuk yang valid.',
        'errors' => $errors,
        'groups' => $groups,
        'row_ok' => $rowOk,
        'row_err' => $rowErr,
    ];
}

/**
 * @param list<array<string, string>> $rows
 * @return array{ok:bool,message:string,errors:list<string>,items:list<array<string,mixed>>,row_ok:int,row_err:int}
 */
function keuangan_impor_ekspor_validate_keluar(PDO $pdo, array $rows): array
{
    $errors = [];
    $items = [];
    $rowOk = 0;
    $rowErr = 0;

    foreach ($rows as $i => $raw) {
        $line = $i + 2;
        $tgl = keuangan_impor_ekspor_parse_date((string) ($raw['tanggal'] ?? ''));
        $pj = trim((string) ($raw['penanggung_jawab'] ?? ''));
        $pos = trim((string) ($raw['pos'] ?? ''));
        $alokasi = trim((string) ($raw['alokasi_nama'] ?? ''));
        $nominal = keuangan_money_input_to_int((string) ($raw['nominal'] ?? '0'));
        $metode = strtoupper(trim((string) ($raw['metode_keluar'] ?? 'KAS')));
        $ket = trim((string) ($raw['keterangan'] ?? ''));
        $bukti = trim((string) ($raw['no_bukti'] ?? ''));

        $rowErrors = [];
        if ($tgl === null) {
            $rowErrors[] = 'tanggal tidak valid';
        }
        if ($pj === '') {
            $rowErrors[] = 'penanggung_jawab kosong';
        }
        if ($pos === '') {
            $rowErrors[] = 'pos kosong';
        }
        if ($nominal <= 0) {
            $rowErrors[] = 'nominal harus > 0';
        }
        if (!in_array($metode, ['KAS', 'TRANSFER'], true)) {
            $rowErrors[] = 'metode_keluar harus KAS/TRANSFER';
        }
        $alokasiErr = keuangan_validasi_alokasi_pengeluaran($pdo, $alokasi);
        if ($alokasiErr !== null) {
            $rowErrors[] = $alokasiErr;
        }

        if ($rowErrors !== []) {
            $rowErr++;
            $errors[] = 'Baris ' . $line . ': ' . implode('; ', $rowErrors);
            continue;
        }

        $items[] = [
            'tanggal' => $tgl,
            'penanggung_jawab' => $pj,
            'pos' => $pos,
            'alokasi_nama' => $alokasi,
            'nominal' => $nominal,
            'metode_keluar' => $metode,
            'keterangan' => $ket,
            'no_bukti' => $bukti,
            'line' => $line,
        ];
        $rowOk++;
    }

    return [
        'ok' => $rowErr === 0 && $rowOk > 0,
        'message' => $rowOk > 0
            ? sprintf('Validasi keluar: %d baris OK, %d error.', $rowOk, $rowErr)
            : 'Tidak ada baris keluar yang valid.',
        'errors' => $errors,
        'items' => $items,
        'row_ok' => $rowOk,
        'row_err' => $rowErr,
    ];
}

/**
 * @param array{groups:array<string,array<string,mixed>>,row_ok:int,row_err:int,errors:list<string>} $validated
 * @return array{ok:bool,message:string,errors:list<string>,imported:int}
 */
function keuangan_impor_ekspor_commit_masuk(PDO $pdo, array $validated, int $userId, bool $allowAppend): array
{
    if (!keuangan_impor_ekspor_boleh_destruktif($pdo)) {
        return ['ok' => false, 'message' => 'Hanya super admin (atau token koreksi) yang boleh mengunggah isi ulang.', 'errors' => [], 'imported' => 0];
    }
    $groups = $validated['groups'] ?? [];
    if ($groups === []) {
        return ['ok' => false, 'message' => 'Tidak ada data valid untuk diimpor.', 'errors' => $validated['errors'] ?? [], 'imported' => 0];
    }
    if (($validated['row_err'] ?? 0) > 0) {
        return ['ok' => false, 'message' => 'Perbaiki error validasi sebelum commit.', 'errors' => $validated['errors'] ?? [], 'imported' => 0];
    }

    ensure_keuangan_transaksi_tables($pdo);
    $existing = (int) $pdo->query('SELECT COUNT(*) FROM keuangan_pembayaran')->fetchColumn();
    if ($existing > 0 && !$allowAppend) {
        return [
            'ok' => false,
            'message' => 'Tabel pembayaran masih berisi data. Hapus seluruh dulu, atau centang “izinkan tambah”.',
            'errors' => [],
            'imported' => 0,
        ];
    }

    keuangan_transaksi_bootstrap_jurnal();
    if (!function_exists('keuangan_pembayaran_apply_cashless_saku')) {
        require_once __DIR__ . '/keuangan_pembayaran_admin.php';
    }
    // DDL harus di luar transaksi (MySQL implicit commit).
    keuangan_ensure_cashless_schema($pdo);
    ensure_keuangan_jurnal_tables($pdo);

    $hasMetode = column_exists($pdo, 'keuangan_pembayaran', 'metode_bayar');
    $hasAkun = column_exists($pdo, 'keuangan_pembayaran', 'akun_id');
    $hasStatus = column_exists($pdo, 'keuangan_pembayaran', 'status_lunas');
    $imported = 0;
    $errors = [];

    try {
        $pdo->beginTransaction();

        $insertDetail = $pdo->prepare('
            INSERT INTO keuangan_pembayaran_detail (pembayaran_id, pos_slug, pos_nama, nominal)
            VALUES (:pembayaran_id, :pos_slug, :pos_nama, :nominal)
        ');

        foreach ($groups as $grupKey => $g) {
            $details = $g['details'] ?? [];
            if (!is_array($details) || $details === []) {
                continue;
            }
            $total = 0;
            $detailNorm = [];
            foreach ($details as $d) {
                $nom = (int) ($d['nominal'] ?? 0);
                if ($nom <= 0) {
                    continue;
                }
                $slug = keuangan_pembayaran_pos_slug_normalize((string) ($d['slug'] ?? ''));
                $detailNorm[] = [
                    'slug' => $slug,
                    'nama' => (string) ($d['nama'] ?? $slug),
                    'nominal' => $nom,
                ];
                $total += $nom;
            }
            if ($detailNorm === [] || $total <= 0) {
                continue;
            }

            $metode = (string) ($g['metode_bayar'] ?? 'KAS');
            $akunId = keuangan_impor_ekspor_default_akun_id($pdo, $metode);
            if ($akunId <= 0) {
                throw new RuntimeException('Akun kas/bank default tidak ditemukan. Atur akun di pengaturan keuangan.');
            }

            $cols = ['santri_id', 'jenis_periode', 'tahun_ajaran_mulai', 'tahun_ajaran_selesai', 'bulan_tagihan', 'tanggal_bayar', 'total_nominal', 'keterangan', 'created_by'];
            $vals = [':santri_id', ':jenis_periode', ':mulai', ':selesai', ':bulan_tagihan', ':tanggal_bayar', ':total_nominal', ':keterangan', ':created_by'];
            $bulan = isset($g['bulan_tagihan']) && $g['bulan_tagihan'] !== null ? (int) $g['bulan_tagihan'] : 0;
            $params = [
                'santri_id' => (int) $g['santri_id'],
                'jenis_periode' => (string) $g['jenis_periode'],
                'mulai' => (int) $g['tahun_ajaran_mulai'],
                'selesai' => (int) $g['tahun_ajaran_selesai'],
                'bulan_tagihan' => $bulan > 0 ? $bulan : null,
                'tanggal_bayar' => (string) $g['tanggal_bayar'],
                'total_nominal' => $total,
                'keterangan' => (string) ($g['keterangan'] ?? ''),
                'created_by' => $userId > 0 ? $userId : null,
            ];
            if ($hasMetode) {
                $cols[] = 'metode_bayar';
                $vals[] = ':metode_bayar';
                $params['metode_bayar'] = $metode;
            }
            if ($hasAkun) {
                $cols[] = 'akun_id';
                $vals[] = ':akun_id';
                $params['akun_id'] = $akunId;
            }
            if ($hasStatus) {
                $cols[] = 'status_lunas';
                $vals[] = ':status_lunas';
                $params['status_lunas'] = 'LUNAS';
            }

            $sql = 'INSERT INTO keuangan_pembayaran (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
            $pdo->prepare($sql)->execute($params);
            $pembayaranId = (int) $pdo->lastInsertId();

            foreach ($detailNorm as $dr) {
                $insertDetail->execute([
                    'pembayaran_id' => $pembayaranId,
                    'pos_slug' => $dr['slug'],
                    'pos_nama' => $dr['nama'],
                    'nominal' => $dr['nominal'],
                ]);
            }

            $sakuOk = keuangan_pembayaran_apply_cashless_saku(
                $pdo,
                $pembayaranId,
                (int) $g['santri_id'],
                $detailNorm,
                $userId,
                (string) $g['tanggal_bayar'],
                false
            );
            if (!$sakuOk) {
                throw new RuntimeException(
                    'Top-up cashless (pos Saku) gagal untuk grup ' . (string) $grupKey
                    . ' (NIS ' . (string) ($g['nis'] ?? '') . '). Import dibatalkan.'
                );
            }

            $kategoriFilter = ((string) $g['jenis_periode'] === 'AWAL_TAHUN') ? 'Awal Tahun' : 'Bulanan';
            keuangan_jurnal_pembayaran(
                $pdo,
                $pembayaranId,
                (string) $g['tanggal_bayar'],
                $akunId,
                $total,
                $detailNorm,
                $kategoriFilter,
                $userId
            );
            $imported++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'message' => 'Import masuk gagal: ' . $e->getMessage(),
            'errors' => $errors,
            'imported' => 0,
        ];
    }

    require_once __DIR__ . '/cashless_koperasi.php';
    if (function_exists('cashless_sync_all_account_balances')) {
        cashless_sync_all_account_balances($pdo);
    }

    if (function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
        keuangan_dashboard_cache_invalidate();
    }

    return [
        'ok' => true,
        'message' => 'Import masuk selesai: ' . $imported . ' pembayaran (jurnal + saldo saku tersinkron).',
        'errors' => $errors,
        'imported' => $imported,
    ];
}

/**
 * @param array{items:list<array<string,mixed>>,row_ok:int,row_err:int,errors:list<string>} $validated
 * @return array{ok:bool,message:string,errors:list<string>,imported:int}
 */
function keuangan_impor_ekspor_commit_keluar(PDO $pdo, array $validated, int $userId, bool $allowAppend): array
{
    if (!keuangan_impor_ekspor_boleh_destruktif($pdo)) {
        return ['ok' => false, 'message' => 'Hanya super admin (atau token koreksi) yang boleh mengunggah isi ulang.', 'errors' => [], 'imported' => 0];
    }
    $items = $validated['items'] ?? [];
    if ($items === []) {
        return ['ok' => false, 'message' => 'Tidak ada data valid untuk diimpor.', 'errors' => $validated['errors'] ?? [], 'imported' => 0];
    }
    if (($validated['row_err'] ?? 0) > 0) {
        return ['ok' => false, 'message' => 'Perbaiki error validasi sebelum commit.', 'errors' => $validated['errors'] ?? [], 'imported' => 0];
    }

    ensure_keuangan_transaksi_tables($pdo);
    $existing = (int) $pdo->query('SELECT COUNT(*) FROM keuangan_pengeluaran')->fetchColumn();
    if ($existing > 0 && !$allowAppend) {
        return [
            'ok' => false,
            'message' => 'Tabel pengeluaran masih berisi data. Hapus seluruh dulu, atau centang “izinkan tambah”.',
            'errors' => [],
            'imported' => 0,
        ];
    }

    keuangan_transaksi_bootstrap_jurnal();
    ensure_keuangan_jurnal_tables($pdo);
    $hasMetode = column_exists($pdo, 'keuangan_pengeluaran', 'metode_keluar');
    $hasAkun = column_exists($pdo, 'keuangan_pengeluaran', 'akun_id');
    $hasBukti = column_exists($pdo, 'keuangan_pengeluaran', 'no_bukti');
    $imported = 0;

    try {
        $pdo->beginTransaction();

        foreach ($items as $item) {
            $metode = (string) ($item['metode_keluar'] ?? 'KAS');
            $akunId = keuangan_impor_ekspor_default_akun_id($pdo, $metode);
            if ($akunId <= 0) {
                throw new RuntimeException('Akun kas/bank default tidak ditemukan.');
            }

            $cols = ['tanggal', 'penanggung_jawab', 'pos', 'alokasi_nama', 'nominal', 'keterangan', 'created_by'];
            $vals = [':tanggal', ':penanggung_jawab', ':pos', ':alokasi_nama', ':nominal', ':keterangan', ':created_by'];
            $params = [
                'tanggal' => (string) $item['tanggal'],
                'penanggung_jawab' => (string) $item['penanggung_jawab'],
                'pos' => (string) $item['pos'],
                'alokasi_nama' => (string) $item['alokasi_nama'],
                'nominal' => (int) $item['nominal'],
                'keterangan' => (string) ($item['keterangan'] ?? ''),
                'created_by' => $userId > 0 ? $userId : null,
            ];
            if ($hasMetode) {
                $cols[] = 'metode_keluar';
                $vals[] = ':metode_keluar';
                $params['metode_keluar'] = $metode;
            }
            if ($hasAkun) {
                $cols[] = 'akun_id';
                $vals[] = ':akun_id';
                $params['akun_id'] = $akunId;
            }
            if ($hasBukti) {
                $cols[] = 'no_bukti';
                $vals[] = ':no_bukti';
                $bukti = trim((string) ($item['no_bukti'] ?? ''));
                $params['no_bukti'] = $bukti !== '' ? $bukti : null;
            }

            $sql = 'INSERT INTO keuangan_pengeluaran (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
            $pdo->prepare($sql)->execute($params);
            $pengeluaranId = (int) $pdo->lastInsertId();
            $pos = (string) $item['pos'];

            keuangan_jurnal_pengeluaran(
                $pdo,
                $pengeluaranId,
                (string) $item['tanggal'],
                $akunId,
                (int) $item['nominal'],
                $pos,
                $userId
            );
            // Belanja Modal sengaja tanpa jurnal operasional; lainnya wajib punya jurnal.
            if (stripos($pos, 'Belanja Modal') !== 0
                && !keuangan_jurnal_ref_exists($pdo, 'pengeluaran', $pengeluaranId)
            ) {
                throw new RuntimeException(
                    'Jurnal pengeluaran gagal (pos: ' . $pos . ', tanggal '
                    . (string) $item['tanggal'] . '). Import dibatalkan.'
                );
            }
            $imported++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'message' => 'Import keluar gagal: ' . $e->getMessage(),
            'errors' => [],
            'imported' => 0,
        ];
    }

    if (function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
        keuangan_dashboard_cache_invalidate();
    }

    return [
        'ok' => true,
        'message' => 'Import keluar selesai: ' . $imported . ' baris.',
        'errors' => [],
        'imported' => $imported,
    ];
}

/**
 * @return list<array<string, string>>
 */
function keuangan_impor_ekspor_parse_upload_rows(string $tmpPath, string $originalName): array
{
    if (!import_upload_is_xlsx($originalName, $tmpPath)) {
        throw new RuntimeException('Format harus .xlsx');
    }
    $maxBytes = 8 * 1024 * 1024;
    $size = @filesize($tmpPath);
    if ($size !== false && $size > $maxBytes) {
        throw new RuntimeException('Ukuran file terlalu besar (maks 8 MB).');
    }

    return normalize_import_rows(parse_xlsx_rows($tmpPath));
}
