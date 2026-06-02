<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Daftar template pesan WA otomatis yang bisa diedit admin.
 *
 * @return array<string, array{label:string, hint:string, placeholders:string, default:string}>
 */
function wa_template_definitions(): array
{
    return [
        'tagihan_wali' => [
            'label' => 'Tagihan syahriyah ke wali',
            'hint' => 'Dikirim otomatis / manual ke wali santri yang masih punya tagihan.',
            'placeholders' => '{nama_santri}, {nama_ponpes}, {label_kekurangan}, {total_sisa}, {keterangan_keuangan}',
            'default' => "Assalamu'alaikum Wr. Wb.\n"
                . 'Nyuwun pangapunten, kepareng matur dateng Bpk/Ibu wali saking *{nama_santri}*\n'
                . 'Atasnama Pengurus *{nama_ponpes}* *Pengurus Bidang Keuangan*,\n'
                . '{keterangan_keuangan}'
                . 'memberitahukan bahwa putra/putri Bapak/Ibu masih mempunyai kekurangan '
                . '{label_kekurangan}, dan jumlah total *{total_sisa}*.\n'
                . 'Berkenaan dengan hal tersebut, kami mohon maaf baru saat ini dapat melaporkan kepada Bapak/Ibu. '
                . 'Atas pengertian dan kerja samanya kami ucapkan terima kasih 🙏.',
        ],
        'pembimbing_belum_scan' => [
            'label' => 'Pembimbing / munawib belum scan',
            'hint' => 'Dikirim ~10 menit sebelum kegiatan selesai jika belum scan kehadiran.',
            'placeholders' => '{nama_pembimbing}, {nama_kegiatan}',
            'default' => 'Nyuwun pangapunten, {nama_pembimbing} ngemutaken bilih panjenengan dereng scan kehadiran {nama_kegiatan}.',
        ],
        'rekap_alpa' => [
            'label' => 'Rekap ALPA ke pengurus',
            'hint' => 'Isi utama rekap; daftar santri ditambahkan otomatis setelah template.',
            'placeholders' => '{periode}, {ambang}',
            'default' => "Assalamu'alaikum Wr. Wb.\n\n"
                . '*PEMBERITAHUAN RESMI*\n'
                . "Perihal: Rekapitulasi ketidakhadiran (*ALPA*)\n"
                . 'Periode data: {periode}\n'
                . 'Kriteria: jumlah ALPA ≥ *{ambang}* per santri per kegiatan',
        ],
        'pengajuan_izin_baru' => [
            'label' => 'Pengajuan izin baru ke pengurus',
            'hint' => 'Notifikasi saat ada permohonan izin santri baru.',
            'placeholders' => '{nama_santri}, {jenis_izin}, {tanggal_mulai}, {tanggal_selesai}',
            'default' => 'Assalamu\'alaikum. Ada pengajuan izin baru: *{nama_santri}* — {jenis_izin} ({tanggal_mulai} s/d {tanggal_selesai}). Mohon ditinjau di aplikasi.',
        ],
    ];
}

function wa_template_setting_key(string $slug): string
{
    return 'wa_tpl_' . preg_replace('/[^a-z0-9_]/', '', strtolower($slug));
}

function wa_template_get(PDO $pdo, string $slug): string
{
    $defs = wa_template_definitions();
    if (!isset($defs[$slug])) {
        return '';
    }
    $custom = trim((string) app_setting($pdo, wa_template_setting_key($slug), ''));

    return $custom !== '' ? $custom : (string) $defs[$slug]['default'];
}

/**
 * @param array<string, string> $vars
 */
function wa_template_render(PDO $pdo, string $slug, array $vars): string
{
    $tpl = wa_template_get($pdo, $slug);
    foreach ($vars as $key => $value) {
        $tpl = str_replace('{' . $key . '}', (string) $value, $tpl);
    }

    return $tpl;
}

/**
 * @return array{ok:bool, message:string}
 */
function wa_template_save_all(PDO $pdo, array $post): array
{
    foreach (wa_template_definitions() as $slug => $meta) {
        $field = 'wa_tpl_' . $slug;
        if (!array_key_exists($field, $post)) {
            continue;
        }
        $val = trim((string) $post[$field]);
        if ($val === '' || $val === (string) $meta['default']) {
            $st = $pdo->prepare('DELETE FROM app_settings WHERE setting_key = :k LIMIT 1');
            $st->execute(['k' => wa_template_setting_key($slug)]);
        } else {
            save_setting($pdo, wa_template_setting_key($slug), $val);
        }
    }
    if (function_exists('app_settings_cache_reset')) {
        app_settings_cache_reset($pdo);
    }

    return ['ok' => true, 'message' => 'Template pesan WA otomatis disimpan.'];
}
