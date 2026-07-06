<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_defs.php';
require_once __DIR__ . '/keuangan_alokasi.php';
require_once __DIR__ . '/keuangan_ta_context.php';

/** @return array{ok:bool,message:string} */
function keuangan_save_periode_settings(PDO $pdo, array $post): array
{
    require_once __DIR__ . '/pondok_kalender.php';
    $ta = pondok_normalisasi_tahun_ajaran_input(
        $pdo,
        (int) ($post['keuangan_periode_mulai'] ?? 0),
        (int) ($post['keuangan_periode_selesai'] ?? 0)
    );
    $mulai = $ta['mulai'];
    $selesai = $ta['selesai'];
    $min = pondok_ta_tahun_min($pdo);
    $max = pondok_ta_tahun_max($pdo);
    if ($mulai < $min || $mulai > $max) {
        return ['ok' => false, 'message' => 'Tahun ajaran mulai tidak valid (' . $min . '–' . $max . ').'];
    }
    save_setting($pdo, 'keuangan_periode_mulai', (string) $mulai);
    save_setting($pdo, 'keuangan_periode_selesai', (string) $selesai);
    pondok_ta_persist_session($ta);
    pondok_ta_clear_browse_session();
    if (function_exists('pondok_bulan_slots_cache_invalidate')) {
        require_once __DIR__ . '/pondok_kalender.php';
        pondok_bulan_slots_cache_invalidate();
    }
    if (function_exists('keuangan_schema_cache_clear')) {
        require_once __DIR__ . '/keuangan_transaksi.php';
        keuangan_schema_cache_clear();
    } elseif (function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
        keuangan_dashboard_cache_invalidate();
    }

    return [
        'ok' => true,
        'message' => 'Tahun ajaran aktif disimpan (' . pondok_tahun_ajaran_label($pdo, $ta) . '). Semua modul keuangan & tagihan mengikuti periode ini.',
    ];
}

/** @return array{ok:bool,message:string} */
function keuangan_save_tarif_settings(PDO $pdo, array $post): array
{
    $fees = $post['fee'] ?? [];
    if (!is_array($fees)) {
        return ['ok' => false, 'message' => 'Data tarif tidak valid.'];
    }
    $tiers = ['muadalah', 'wustho', 'ulya'];
    $defs = keuangan_biaya_definitions();
    $slugValid = [];
    foreach ($defs as $def) {
        $slugValid[(string) $def['slug']] = true;
    }

    foreach ($fees as $slug => $tierRows) {
        if (!isset($slugValid[$slug]) || !is_array($tierRows)) {
            continue;
        }
        foreach ($tiers as $tier) {
            if (!array_key_exists($tier, $tierRows)) {
                continue;
            }
            $nom = keuangan_money_input_to_int((string) $tierRows[$tier]);
            save_setting($pdo, 'keuangan_fee_' . $slug . '_' . $tier, (string) max(0, $nom));
        }
    }

    app_settings_cache($pdo, true);
    if (!function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
    }
    keuangan_dashboard_cache_invalidate();
    foreach (array_keys($fees) as $slugSaved) {
        if (in_array((string) $slugSaved, keuangan_biaya_slugs_syahriyah_makan(), true)) {
            if (!function_exists('keuangan_tarif_bulanan_invalidate')) {
                require_once __DIR__ . '/keuangan_tarif_bulanan.php';
            }
            keuangan_tarif_bulanan_invalidate();
            break;
        }
    }

    return ['ok' => true, 'message' => 'Tarif komponen biaya berhasil disimpan.'];
}

const KEUNGAN_KAS_MODE_TRANSAKSI = 'transaksi';
const KEUNGAN_KAS_MODE_LEGACY = 'legacy';

function keuangan_kas_saldo_mode(PDO $pdo): string
{
    $mode = strtolower(trim((string) app_setting($pdo, 'keuangan_kas_saldo_mode', KEUNGAN_KAS_MODE_TRANSAKSI)));
    if ($mode === KEUNGAN_KAS_MODE_LEGACY) {
        return KEUNGAN_KAS_MODE_LEGACY;
    }

    return KEUNGAN_KAS_MODE_TRANSAKSI;
}

function keuangan_kas_uses_opening_balance(PDO $pdo): bool
{
    return keuangan_kas_saldo_mode($pdo) === KEUNGAN_KAS_MODE_LEGACY;
}

/** Ekspresi SQL saldo awal akun — mengikuti mode kas (transaksi vs legacy). */
function keuangan_sql_opening_balance_expr(PDO $pdo): string
{
    return keuangan_kas_uses_opening_balance($pdo) ? 'COALESCE(a.opening_balance, 0)' : '0';
}

function keuangan_kas_saldo_mode_label(string $mode): string
{
    return match ($mode) {
        KEUNGAN_KAS_MODE_LEGACY => 'Ada saldo sebelumnya',
        default => 'Mulai dari nol (transaksi)',
    };
}

/** @return array{ok:bool,message:string} */
function keuangan_save_kas_saldo_mode(PDO $pdo, array $post): array
{
    $mode = strtolower(trim((string) ($post['keuangan_kas_saldo_mode'] ?? KEUNGAN_KAS_MODE_TRANSAKSI)));
    if (!in_array($mode, [KEUNGAN_KAS_MODE_TRANSAKSI, KEUNGAN_KAS_MODE_LEGACY], true)) {
        return ['ok' => false, 'message' => 'Mode saldo kas tidak valid.'];
    }

    $prev = keuangan_kas_saldo_mode($pdo);

    save_setting($pdo, 'keuangan_kas_saldo_mode', $mode);
    app_settings_cache($pdo, true);

    if ($mode === KEUNGAN_KAS_MODE_TRANSAKSI && !empty($post['reset_opening']) && table_exists($pdo, 'keuangan_akun')) {
        $pdo->exec('UPDATE keuangan_akun SET opening_balance = 0');
    }

    if (!function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
    }
    keuangan_dashboard_cache_invalidate();

    $msg = 'Mode saldo kas disimpan: ' . keuangan_kas_saldo_mode_label($mode) . '.';
    if ($mode === KEUNGAN_KAS_MODE_TRANSAKSI && !empty($post['reset_opening'])) {
        $msg .= ' Saldo awal semua akun direset ke 0.';
    } elseif ($mode === KEUNGAN_KAS_MODE_LEGACY && $prev === KEUNGAN_KAS_MODE_TRANSAKSI) {
        $msg .= ' Isi saldo awal per akun jika ada kas sebelum transaksi tercatat.';
    }

    return [
        'ok' => true,
        'message' => $msg,
    ];
}

function keuangan_kas_total_opening_balance(PDO $pdo): int
{
    if (!table_exists($pdo, 'keuangan_akun')) {
        return 0;
    }

    return (int) round((float) ($pdo->query('SELECT COALESCE(SUM(opening_balance), 0) FROM keuangan_akun')->fetchColumn() ?: 0));
}

function keuangan_count_transaksi_tanpa_akun(PDO $pdo, ?string $asOf = null): int
{
    if (!function_exists('keuangan_neraca_hitung_transaksi_tanpa_akun')) {
        require_once __DIR__ . '/keuangan_neraca_perbaikan.php';
    }
    $asOf = $asOf !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? $asOf : date('Y-m-d');
    $total = 0;
    foreach (
        [
            'keuangan_pembayaran' => 'tanggal_bayar',
            'keuangan_pemasukan' => 'tanggal',
            'keuangan_pengeluaran' => 'tanggal',
        ] as $table => $dateCol
    ) {
        $res = keuangan_neraca_hitung_transaksi_tanpa_akun($pdo, $table, $dateCol, $asOf);
        $total += (int) ($res['jumlah'] ?? 0);
    }

    return $total;
}

/** @return list<array<string, mixed>> */
function keuangan_fetch_akun_all_with_saldo(PDO $pdo, ?string $asOf = null): array
{
    $rows = keuangan_fetch_akun_all($pdo);
    if ($rows === []) {
        return [];
    }
    if (!function_exists('keuangan_sql_subquery_masuk_per_akun')) {
        require_once __DIR__ . '/keuangan_akun_mutasi.php';
    }
    $asOf = $asOf !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? $asOf : date('Y-m-d');
    $openingExpr = keuangan_sql_opening_balance_expr($pdo);
    $masukSub = keuangan_sql_subquery_masuk_per_akun($pdo);
    $stmt = $pdo->prepare("
        SELECT a.id,
               ({$openingExpr} + COALESCE(inc.total_masuk, 0) - COALESCE(exp.total_keluar, 0)) AS saldo_berjalan
        FROM keuangan_akun a
        LEFT JOIN ( {$masukSub} ) inc ON inc.akun_id = a.id
        LEFT JOIN (
            SELECT akun_id, SUM(nominal) AS total_keluar
            FROM keuangan_pengeluaran
            WHERE akun_id IS NOT NULL AND tanggal <= :as_of2
            GROUP BY akun_id
        ) exp ON exp.akun_id = a.id
    ");
    $stmt->execute(['as_of' => $asOf, 'as_of2' => $asOf]);
    $saldoMap = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $sr) {
        $saldoMap[(int) ($sr['id'] ?? 0)] = (int) round((float) ($sr['saldo_berjalan'] ?? 0));
    }
    foreach ($rows as &$row) {
        $row['saldo_berjalan'] = $saldoMap[(int) ($row['id'] ?? 0)] ?? 0;
    }
    unset($row);

    return $rows;
}

/** @return list<array<string, mixed>> */
function keuangan_fetch_akun_all(PDO $pdo): array
{
    if (!table_exists($pdo, 'keuangan_akun')) {
        return [];
    }

    return $pdo->query('
        SELECT id, jenis_akun, nama_akun, nama_bank, no_rekening, atas_nama,
               opening_balance, is_default, is_active
        FROM keuangan_akun
        ORDER BY is_default DESC, is_active DESC, jenis_akun ASC, id ASC
    ')->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array{ok:bool,message:string} */
function keuangan_save_akun(PDO $pdo, array $post): array
{
    if (!table_exists($pdo, 'keuangan_akun')) {
        require_once __DIR__ . '/keuangan_transaksi.php';
        keuangan_ensure_schema_deferred($pdo);
    }
    $id = (int) ($post['akun_id'] ?? 0);
    $jenis = strtoupper(trim((string) ($post['jenis_akun'] ?? 'KAS')));
    $nama = trim((string) ($post['nama_akun'] ?? ''));
    $namaBank = trim((string) ($post['nama_bank'] ?? ''));
    $noRek = trim((string) ($post['no_rekening'] ?? ''));
    $atasNama = trim((string) ($post['atas_nama'] ?? ''));
    $opening = keuangan_money_input_to_int((string) ($post['opening_balance'] ?? '0'));
    if (!keuangan_kas_uses_opening_balance($pdo)) {
        $opening = 0;
    }
    $isDefault = !empty($post['is_default']);
    $isActive = !isset($post['is_active']) || (string) $post['is_active'] === '1';

    if (!in_array($jenis, ['KAS', 'BANK', 'E-WALLET'], true)) {
        $jenis = 'KAS';
    }
    if ($nama === '') {
        return ['ok' => false, 'message' => 'Nama akun wajib diisi.'];
    }

    if ($isDefault) {
        $pdo->exec('UPDATE keuangan_akun SET is_default = 0');
    }

    if ($id > 0) {
        $pdo->prepare('
            UPDATE keuangan_akun SET
                jenis_akun = :jenis, nama_akun = :nama, nama_bank = :nama_bank,
                no_rekening = :no_rekening, atas_nama = :atas_nama,
                opening_balance = :opening, is_default = :is_default, is_active = :is_active
            WHERE id = :id
        ')->execute([
            'jenis' => $jenis,
            'nama' => $nama,
            'nama_bank' => $namaBank !== '' ? $namaBank : null,
            'no_rekening' => $noRek !== '' ? $noRek : null,
            'atas_nama' => $atasNama !== '' ? $atasNama : null,
            'opening' => $opening,
            'is_default' => $isDefault ? 1 : 0,
            'is_active' => $isActive ? 1 : 0,
            'id' => $id,
        ]);

        if (!function_exists('keuangan_dashboard_cache_invalidate')) {
            require_once __DIR__ . '/keuangan_dashboard.php';
        }
        keuangan_dashboard_cache_invalidate();

        return ['ok' => true, 'message' => 'Akun kas/bank diperbarui.'];
    }

    $pdo->prepare('
        INSERT INTO keuangan_akun (jenis_akun, nama_akun, nama_bank, no_rekening, atas_nama, opening_balance, is_default, is_active)
        VALUES (:jenis, :nama, :nama_bank, :no_rekening, :atas_nama, :opening, :is_default, 1)
    ')->execute([
        'jenis' => $jenis,
        'nama' => $nama,
        'nama_bank' => $namaBank !== '' ? $namaBank : null,
        'no_rekening' => $noRek !== '' ? $noRek : null,
        'atas_nama' => $atasNama !== '' ? $atasNama : null,
        'opening' => $opening,
        'is_default' => $isDefault ? 1 : 0,
    ]);

    if (!function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
    }
    keuangan_dashboard_cache_invalidate();

    return ['ok' => true, 'message' => 'Akun kas/bank ditambahkan.'];
}

/** @return list<array<string, mixed>> */
function keuangan_fetch_alokasi_all(PDO $pdo): array
{
    if (!table_exists($pdo, 'keuangan_alokasi')) {
        return [];
    }

    return $pdo->query('
        SELECT id, nama_komponen, kategori, jenis_dana, persen, urutan, is_active
        FROM keuangan_alokasi
        ORDER BY jenis_dana ASC, urutan ASC, nama_komponen ASC
    ')->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array{ok:bool,message:string} */
function keuangan_save_alokasi(PDO $pdo, array $post): array
{
    if (!table_exists($pdo, 'keuangan_alokasi')) {
        require_once __DIR__ . '/keuangan_transaksi.php';
        keuangan_ensure_schema_deferred($pdo);
    }
    $id = (int) ($post['alokasi_id'] ?? 0);
    $nama = trim((string) ($post['nama_komponen'] ?? ''));
    $kategori = trim((string) ($post['kategori'] ?? ''));
    $jenisDana = keuangan_alokasi_normalize_jenis((string) ($post['jenis_dana'] ?? KEUNGAN_ALOKASI_JENIS_SYAHRIYAH));
    $persen = (float) str_replace(',', '.', (string) ($post['persen'] ?? '0'));
    $urutan = (int) ($post['urutan'] ?? 0);
    $isActive = !isset($post['is_active']) || (string) $post['is_active'] === '1';
    $label = keuangan_alokasi_label_jenis($jenisDana);

    if ($nama === '' || $kategori === '') {
        return ['ok' => false, 'message' => 'Nama komponen dan kategori wajib diisi.'];
    }
    if ($persen < 0) {
        return ['ok' => false, 'message' => 'Persentase tidak boleh negatif.'];
    }

    $validasi = keuangan_alokasi_validate_persen($pdo, $persen, $id, $isActive, $jenisDana);
    if (!$validasi['ok']) {
        return ['ok' => false, 'message' => $validasi['message']];
    }

    if ($id > 0) {
        $pdo->prepare('
            UPDATE keuangan_alokasi
            SET nama_komponen = :nama, kategori = :kat, jenis_dana = :jenis, persen = :persen, urutan = :urutan, is_active = :aktif
            WHERE id = :id
        ')->execute([
            'nama' => $nama,
            'kat' => $kategori,
            'jenis' => $jenisDana,
            'persen' => $persen,
            'urutan' => $urutan,
            'aktif' => $isActive ? 1 : 0,
            'id' => $id,
        ]);

        return ['ok' => true, 'message' => 'Alokasi ' . $label . ' diperbarui.', 'jenis_dana' => $jenisDana];
    }

    $pdo->prepare('
        INSERT INTO keuangan_alokasi (nama_komponen, kategori, jenis_dana, persen, urutan, is_active)
        VALUES (:nama, :kat, :jenis, :persen, :urutan, 1)
    ')->execute([
        'nama' => $nama,
        'kat' => $kategori,
        'jenis' => $jenisDana,
        'persen' => $persen,
        'urutan' => $urutan,
    ]);

    return ['ok' => true, 'message' => 'Alokasi ' . $label . ' ditambahkan.', 'jenis_dana' => $jenisDana];
}
