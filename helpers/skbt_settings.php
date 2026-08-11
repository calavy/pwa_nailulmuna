<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function skbt_settings_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS skbt_nomor_dokumen (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            tahun_syawal INT NOT NULL,
            periode_ke INT NOT NULL,
            nomor_urut INT NOT NULL,
            nomor_penuh VARCHAR(120) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_skbt_nomor_santri_ta (santri_id, tahun_syawal, periode_ke),
            KEY idx_skbt_nomor_urut (nomor_urut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    $defaults = [
        'skbt_nomor_mulai' => '1',
        'skbt_nomor_prefix' => 'SKBT',
        'skbt_nomor_format' => '{prefix}/{urut}/{periode_prefix}{periode}/{hijri}-{masehi}',
        'skbt_nomor_urut_padding' => '0',
        'skbt_nomor_periode_prefix' => 'P',
        'skbt_nomor_reset_tahun' => '0',
        'skbt_ttd_defaults' => '{}',
        'skbt_ttd_tingkatan' => '{}',
    ];
    foreach ($defaults as $key => $val) {
        if (trim((string) app_setting($pdo, $key, '')) === '') {
            save_setting($pdo, $key, $val);
        }
    }

    $done = true;
}

function skbt_settings_nomor_mulai(PDO $pdo): int
{
    skbt_settings_ensure_schema($pdo);

    return max(1, (int) app_setting($pdo, 'skbt_nomor_mulai', '1'));
}

/** @return array{prefix:string,format:string,urut_padding:int,periode_prefix:string,reset_per_tahun:bool,mulai:int} */
function skbt_nomor_config(PDO $pdo): array
{
    skbt_settings_ensure_schema($pdo);

    return [
        'prefix' => skbt_nomor_sanitize_token((string) app_setting($pdo, 'skbt_nomor_prefix', 'SKBT'), 'SKBT'),
        'format' => skbt_nomor_sanitize_format((string) app_setting($pdo, 'skbt_nomor_format', '{prefix}/{urut}/{periode_prefix}{periode}/{hijri}-{masehi}')),
        'urut_padding' => max(0, min(8, (int) app_setting($pdo, 'skbt_nomor_urut_padding', '0'))),
        'periode_prefix' => skbt_nomor_sanitize_token((string) app_setting($pdo, 'skbt_nomor_periode_prefix', 'P'), 'P'),
        'reset_per_tahun' => (string) app_setting($pdo, 'skbt_nomor_reset_tahun', '0') === '1',
        'mulai' => skbt_settings_nomor_mulai($pdo),
    ];
}

function skbt_nomor_sanitize_token(string $value, string $fallback): string
{
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }
    $value = preg_replace('/[^\p{L}\p{N}.\-_]/u', '', $value) ?? $fallback;

    return $value !== '' ? $value : $fallback;
}

function skbt_nomor_sanitize_format(string $format): string
{
    $format = trim($format);
    if ($format === '' || !str_contains($format, '{urut}')) {
        return '{prefix}/{urut}/{periode_prefix}{periode}/{hijri}-{masehi}';
    }

    return $format;
}

/** @return list<string> */
function skbt_nomor_format_placeholders(): array
{
    return [
        '{prefix}',
        '{urut}',
        '{periode}',
        '{periode_prefix}',
        '{hijri}',
        '{masehi}',
        '{ta}',
        '{tahun}',
        '{bulan_romawi}',
    ];
}

/**
 * @return array<string,string>
 */
function skbt_settings_ttd_defaults(PDO $pdo): array
{
    skbt_settings_ensure_schema($pdo);
    $raw = (string) app_setting($pdo, 'skbt_ttd_defaults', '{}');
    $data = json_decode($raw, true);

    return is_array($data) ? array_map('strval', $data) : [];
}

/**
 * @return array<string,array<string,string>>
 */
function skbt_settings_ttd_per_tingkatan(PDO $pdo): array
{
    skbt_settings_ensure_schema($pdo);
    $raw = (string) app_setting($pdo, 'skbt_ttd_tingkatan', '{}');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $tingkatan => $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[(string) $tingkatan] = [
            'wali_kelas' => trim((string) ($row['wali_kelas'] ?? '')),
            'pengasuh' => trim((string) ($row['pengasuh'] ?? '')),
            'kepala_pondok' => trim((string) ($row['kepala_pondok'] ?? '')),
        ];
    }

    return $out;
}

/** @return list<string> */
function skbt_settings_tingkatan_list(PDO $pdo): array
{
    if (!table_exists($pdo, 'tingkatan')) {
        return [];
    }
    $rows = $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY urutan ASC, nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($rows !== []) {
        return array_values(array_filter(array_map('strval', $rows)));
    }
    if (table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'tingkatan')) {
        return $pdo->query('SELECT DISTINCT tingkatan FROM santri WHERE tingkatan IS NOT NULL AND tingkatan <> "" ORDER BY tingkatan')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    return [];
}

/** @return array{hijri:string,masehi:string,ta:string,tahun:string} */
function skbt_nomor_tahun_parts(int $tahunSyawal, ?string $taMasehiLabel = null): array
{
    $hijri = (string) $tahunSyawal;
    $masehi = (string) ($tahunSyawal + 1);
    if ($taMasehiLabel !== null && trim($taMasehiLabel) !== '') {
        $label = trim($taMasehiLabel);
        if (preg_match('/(\d{4})\s*-\s*(\d{4})/', $label, $m)) {
            $masehi = $m[2];
        } elseif (preg_match('/^\d{4}$/', $label)) {
            $masehi = $label;
        } else {
            $masehi = $label;
        }
    }

    return [
        'hijri' => $hijri,
        'masehi' => $masehi,
        'ta' => $hijri . '-' . $masehi,
        'tahun' => $masehi,
    ];
}

function skbt_nomor_format_urut(int $urut, int $padding): string
{
    if ($padding <= 0) {
        return (string) max(0, $urut);
    }

    return str_pad((string) max(0, $urut), $padding, '0', STR_PAD_LEFT);
}

function skbt_nomor_bulan_romawi(int $bulan): string
{
    $map = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    return $map[max(1, min(12, $bulan)) - 1] ?? 'I';
}

function skbt_nomor_format_string(
    PDO $pdo,
    int $urut,
    int $periodeKe,
    int $tahunSyawal,
    ?string $taMasehiLabel = null
): string {
    $cfg = skbt_nomor_config($pdo);
    $pk = $periodeKe > 0 ? $periodeKe : 1;
    $tahun = skbt_nomor_tahun_parts($tahunSyawal, $taMasehiLabel);
    $urutStr = skbt_nomor_format_urut($urut, $cfg['urut_padding']);
    $replacements = [
        '{prefix}' => $cfg['prefix'],
        '{urut}' => $urutStr,
        '{periode}' => (string) $pk,
        '{periode_prefix}' => $cfg['periode_prefix'],
        '{hijri}' => $tahun['hijri'],
        '{masehi}' => $tahun['masehi'],
        '{ta}' => $tahun['ta'],
        '{tahun}' => $tahun['tahun'],
        '{bulan_romawi}' => skbt_nomor_bulan_romawi((int) date('n')),
    ];

    return strtr($cfg['format'], $replacements);
}

function skbt_nomor_next_urut(PDO $pdo, int $tahunSyawal = 0): int
{
    skbt_settings_ensure_schema($pdo);
    $cfg = skbt_nomor_config($pdo);
    $mulai = $cfg['mulai'];
    if ($cfg['reset_per_tahun'] && $tahunSyawal > 0) {
        $st = $pdo->prepare('SELECT COALESCE(MAX(nomor_urut), 0) FROM skbt_nomor_dokumen WHERE tahun_syawal = :ts AND santri_id > 0');
        $st->execute(['ts' => $tahunSyawal]);
        $max = (int) $st->fetchColumn();
    } else {
        $max = (int) $pdo->query('SELECT COALESCE(MAX(nomor_urut), 0) FROM skbt_nomor_dokumen WHERE santri_id > 0')->fetchColumn();
    }
    $max = max($max, skbt_nomor_manual_floor($pdo, $tahunSyawal));

    return max($mulai, $max + 1);
}

function skbt_nomor_manual_floor(PDO $pdo, int $tahunSyawal = 0): int
{
    $raw = (string) app_setting($pdo, 'skbt_nomor_urut_manual', '');
    if ($raw === '') {
        return 0;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return 0;
    }
    $urut = max(0, (int) ($data['urut'] ?? 0));
    $cfg = skbt_nomor_config($pdo);
    $manualTa = max(0, (int) ($data['tahun_syawal'] ?? 0));
    if ($cfg['reset_per_tahun']) {
        if ($tahunSyawal <= 0 || $manualTa !== $tahunSyawal) {
            return 0;
        }
    }

    return $urut;
}

function skbt_nomor_set_urut_terakhir(PDO $pdo, int $urutTerakhir, int $tahunSyawal = 0): void
{
    skbt_settings_ensure_schema($pdo);
    $payload = [
        'urut' => max(0, $urutTerakhir),
        'tahun_syawal' => max(0, $tahunSyawal),
        'updated_at' => date('c'),
    ];
    save_setting($pdo, 'skbt_nomor_urut_manual', json_encode($payload, JSON_UNESCAPED_UNICODE));
}

/** Nomor surat SKBT — reuse per santri+TA+periode, urut otomatis dari pengaturan. */
function skbt_nomor_surat_allocate(
    PDO $pdo,
    int $santriId,
    int $tahunSyawal,
    int $periodeKe = 0,
    ?string $taMasehiLabel = null
): string {
    skbt_settings_ensure_schema($pdo);
    if ($santriId <= 0) {
        return skbt_nomor_format_string($pdo, 0, $periodeKe, $tahunSyawal, $taMasehiLabel);
    }
    $pk = max(1, $periodeKe);

    $st = $pdo->prepare('
        SELECT nomor_penuh FROM skbt_nomor_dokumen
        WHERE santri_id = :sid AND tahun_syawal = :ts AND periode_ke = :pk
        LIMIT 1
    ');
    $st->execute(['sid' => $santriId, 'ts' => $tahunSyawal, 'pk' => $pk]);
    $existing = $st->fetchColumn();
    if (is_string($existing) && $existing !== '') {
        return $existing;
    }

    $urut = skbt_nomor_next_urut($pdo, $tahunSyawal);
    $nomor = skbt_nomor_format_string($pdo, $urut, $pk, $tahunSyawal, $taMasehiLabel);

    $ins = $pdo->prepare('
        INSERT INTO skbt_nomor_dokumen (santri_id, tahun_syawal, periode_ke, nomor_urut, nomor_penuh)
        VALUES (:sid, :ts, :pk, :urut, :nomor)
    ');
    try {
        $ins->execute([
            'sid' => $santriId,
            'ts' => $tahunSyawal,
            'pk' => $pk,
            'urut' => $urut,
            'nomor' => $nomor,
        ]);
    } catch (PDOException $e) {
        $st->execute(['sid' => $santriId, 'ts' => $tahunSyawal, 'pk' => $pk]);
        $retry = $st->fetchColumn();
        if (is_string($retry) && $retry !== '') {
            return $retry;
        }
        throw $e;
    }

    return $nomor;
}

/**
 * Resolve TTD untuk cetak SKBT (global + per tingkatan + fallback santri/kop).
 *
 * @param array<string,mixed> $santri
 * @param array<string,mixed> $kop
 * @return array{wali_kamar:string,wali_kelas:string,pengasuh:string,kepala_pondok:string}
 */
function skbt_ttd_resolve(PDO $pdo, array $santri, array $kop): array
{
    skbt_settings_ensure_schema($pdo);

    $tingkatan = trim((string) ($santri['tingkatan'] ?? ''));
    $defaults = skbt_settings_ttd_defaults($pdo);
    $perTingkatan = skbt_settings_ttd_per_tingkatan($pdo);
    $row = $tingkatan !== '' ? ($perTingkatan[$tingkatan] ?? []) : [];

    $waliKamar = trim((string) ($santri['nama_kamar'] ?? ''));

    $waliKelas = trim((string) ($row['wali_kelas'] ?? ''));
    if ($waliKelas === '') {
        $waliKelas = trim((string) ($santri['wali_kelas'] ?? ''));
    }

    $pengasuh = trim((string) ($row['pengasuh'] ?? ''));
    if ($pengasuh === '') {
        $pengasuh = trim((string) ($defaults['pengasuh'] ?? ''));
    }
    if ($pengasuh === '') {
        $pengasuh = trim((string) ($kop['nama_pengasuh'] ?? ''));
    }

    $kepala = trim((string) ($row['kepala_pondok'] ?? ''));
    if ($kepala === '') {
        $kepala = trim((string) ($defaults['kepala_pondok'] ?? ''));
    }
    if ($kepala === '') {
        $kepala = trim((string) app_setting($pdo, 'nama_kepala_pondok', ''));
    }
    if ($kepala === '') {
        $kepala = trim((string) ($kop['nama_ketua_yayasan'] ?? ''));
    }

    return [
        'wali_kamar' => $waliKamar,
        'wali_kelas' => $waliKelas,
        'pengasuh' => $pengasuh,
        'kepala_pondok' => $kepala,
    ];
}

/**
 * @return array{ok:bool,message:string}
 */
function skbt_settings_save(PDO $pdo, array $post): array
{
    skbt_settings_ensure_schema($pdo);
    $action = trim((string) ($post['action'] ?? ''));

    if ($action === 'save_nomor') {
        $mulai = max(1, (int) ($post['skbt_nomor_mulai'] ?? 1));
        $prefix = skbt_nomor_sanitize_token(trim((string) ($post['skbt_nomor_prefix'] ?? 'SKBT')), 'SKBT');
        $format = skbt_nomor_sanitize_format(trim((string) ($post['skbt_nomor_format'] ?? '')));
        $padding = max(0, min(8, (int) ($post['skbt_nomor_urut_padding'] ?? 0)));
        $periodePrefix = skbt_nomor_sanitize_token(trim((string) ($post['skbt_nomor_periode_prefix'] ?? 'P')), 'P');
        $resetTahun = isset($post['skbt_nomor_reset_tahun']) ? '1' : '0';

        save_setting($pdo, 'skbt_nomor_mulai', (string) $mulai);
        save_setting($pdo, 'skbt_nomor_prefix', $prefix);
        save_setting($pdo, 'skbt_nomor_format', $format);
        save_setting($pdo, 'skbt_nomor_urut_padding', (string) $padding);
        save_setting($pdo, 'skbt_nomor_periode_prefix', $periodePrefix);
        save_setting($pdo, 'skbt_nomor_reset_tahun', $resetTahun);
        if (function_exists('app_settings_cache_reset')) {
            app_settings_cache_reset($pdo);
        }

        $next = skbt_nomor_next_urut($pdo);
        $contoh = skbt_nomor_format_string($pdo, $next, 52, 1444, '2023-2024');

        return [
            'ok' => true,
            'message' => 'Pengaturan nomor surat disimpan. Contoh berikutnya: ' . $contoh,
        ];
    }

    if ($action === 'set_urut_terakhir') {
        $urut = max(0, (int) ($post['urut_terakhir'] ?? 0));
        $tahunSyawal = max(0, (int) ($post['tahun_syawal'] ?? 0));
        skbt_nomor_set_urut_terakhir($pdo, $urut, $tahunSyawal);
        if (function_exists('app_settings_cache_reset')) {
            app_settings_cache_reset($pdo);
        }
        $next = skbt_nomor_next_urut($pdo, $tahunSyawal);

        return [
            'ok' => true,
            'message' => 'Urut terakhir diset ke ' . $urut . '. Nomor berikutnya: ' . $next . '.',
        ];
    }

    if ($action === 'save_ttd') {
        $defaults = [
            'pengasuh' => trim((string) ($post['ttd_default_pengasuh'] ?? '')),
            'kepala_pondok' => trim((string) ($post['ttd_default_kepala'] ?? '')),
        ];
        save_setting($pdo, 'skbt_ttd_defaults', json_encode($defaults, JSON_UNESCAPED_UNICODE));

        $tingkatanNames = (array) ($post['ttd_tingkatan'] ?? []);
        $waliKelas = (array) ($post['ttd_wali_kelas'] ?? []);
        $pengasuh = (array) ($post['ttd_pengasuh'] ?? []);
        $kepala = (array) ($post['ttd_kepala'] ?? []);
        $map = [];
        foreach ($tingkatanNames as $idx => $nama) {
            $nama = trim((string) $nama);
            if ($nama === '') {
                continue;
            }
            $map[$nama] = [
                'wali_kelas' => trim((string) ($waliKelas[$idx] ?? '')),
                'pengasuh' => trim((string) ($pengasuh[$idx] ?? '')),
                'kepala_pondok' => trim((string) ($kepala[$idx] ?? '')),
            ];
        }
        save_setting($pdo, 'skbt_ttd_tingkatan', json_encode($map, JSON_UNESCAPED_UNICODE));

        if (function_exists('app_settings_cache_reset')) {
            app_settings_cache_reset($pdo);
        }

        return ['ok' => true, 'message' => 'Pengaturan TTD SKBT per tingkatan disimpan.'];
    }

    return ['ok' => false, 'message' => 'Aksi tidak dikenali.'];
}

/** Statistik nomor surat untuk halaman pengaturan. */
function skbt_nomor_stats(PDO $pdo, int $tahunSyawal = 0): array
{
    skbt_settings_ensure_schema($pdo);
    $cfg = skbt_nomor_config($pdo);
    $mulai = $cfg['mulai'];

    if ($cfg['reset_per_tahun'] && $tahunSyawal > 0) {
        $stMax = $pdo->prepare('SELECT COALESCE(MAX(nomor_urut), 0) FROM skbt_nomor_dokumen WHERE tahun_syawal = :ts AND santri_id > 0');
        $stMax->execute(['ts' => $tahunSyawal]);
        $maxUrut = (int) $stMax->fetchColumn();
        $stTotal = $pdo->prepare('SELECT COUNT(*) FROM skbt_nomor_dokumen WHERE tahun_syawal = :ts AND santri_id > 0');
        $stTotal->execute(['ts' => $tahunSyawal]);
        $total = (int) $stTotal->fetchColumn();
        $next = skbt_nomor_next_urut($pdo, $tahunSyawal);
    } else {
        $maxUrut = (int) $pdo->query('SELECT COALESCE(MAX(nomor_urut), 0) FROM skbt_nomor_dokumen WHERE santri_id > 0')->fetchColumn();
        $total = (int) $pdo->query('SELECT COUNT(*) FROM skbt_nomor_dokumen WHERE santri_id > 0')->fetchColumn();
        $next = skbt_nomor_next_urut($pdo);
    }

    return [
        'mulai' => $mulai,
        'max_urut' => $maxUrut,
        'total_terbit' => $total,
        'nomor_berikutnya' => $next,
        'config' => $cfg,
    ];
}

/** @return list<array{tahun_syawal:int,max_urut:int,total:int}> */
function skbt_nomor_stats_per_tahun(PDO $pdo): array
{
    skbt_settings_ensure_schema($pdo);

    return $pdo->query('
        SELECT tahun_syawal, MAX(nomor_urut) AS max_urut, COUNT(*) AS total
        FROM skbt_nomor_dokumen
        WHERE santri_id > 0
        GROUP BY tahun_syawal
        ORDER BY tahun_syawal DESC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
