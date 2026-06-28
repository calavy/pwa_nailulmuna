<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Kunci pengaturan kop surat (disimpan di app_settings).
 *
 * @return list<string>
 */
function surat_cetak_kop_setting_keys(): array
{
    return [
        'telp_ponpes',
        'website_ponpes',
        'kota_ponpes',
        'kop_accent_color',
        'kop_jenis_fallback',
    ];
}

/** Warna aksen kop default. */
function surat_cetak_kop_accent_default(): string
{
    return '#065f46';
}

/**
 * @return array<string, string>
 */
function surat_cetak_kop_defaults(): array
{
    return [
        'telp_ponpes' => '',
        'website_ponpes' => '',
        'kota_ponpes' => 'Muntilan',
        'kop_accent_color' => surat_cetak_kop_accent_default(),
        'kop_jenis_fallback' => 'Lembaga Pondok Pesantren',
    ];
}

/**
 * @return array<string, string>
 */
function surat_cetak_kop_values(PDO $pdo): array
{
    $defaults = surat_cetak_kop_defaults();
    $out = [];
    foreach (surat_cetak_kop_setting_keys() as $key) {
        $out[$key] = trim((string) app_setting($pdo, $key, $defaults[$key] ?? ''));
    }
    if ($out['kop_accent_color'] === '') {
        $out['kop_accent_color'] = surat_cetak_kop_accent_default();
    }
    if ($out['kop_jenis_fallback'] === '') {
        $out['kop_jenis_fallback'] = 'Lembaga Pondok Pesantren';
    }
    if ($out['kota_ponpes'] === '') {
        $out['kota_ponpes'] = 'Muntilan';
    }

    return $out;
}

function surat_cetak_kop_accent_color(PDO $pdo): string
{
    $val = trim((string) app_setting($pdo, 'kop_accent_color', surat_cetak_kop_accent_default()));

    return $val !== '' ? $val : surat_cetak_kop_accent_default();
}

function surat_cetak_kop_jenis_label(PDO $pdo, array $kop): string
{
    $jenis = trim((string) ($kop['jenis_pendidikan'] ?? ''));
    if ($jenis !== '') {
        return $jenis;
    }
    $fallback = trim((string) app_setting($pdo, 'kop_jenis_fallback', 'Lembaga Pondok Pesantren'));

    return $fallback !== '' ? $fallback : 'Lembaga Pondok Pesantren';
}

/**
 * Daftar template isian surat cetak yang dapat diedit admin.
 *
 * @return array<string, array{group:string, label:string, hint:string, placeholders:string, default:string}>
 */
function surat_cetak_template_definitions(): array
{
    return [
        'izin_judul' => [
            'group' => 'Surat Izin Santri',
            'label' => 'Judul surat',
            'hint' => 'Judul utama di bagian atas isi surat izin individu.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'SURAT IZIN SANTRI',
        ],
        'izin_pembuka' => [
            'group' => 'Surat Izin Santri',
            'label' => 'Paragraf pembuka',
            'hint' => 'Teks pengantar sebelum tabel data santri.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Yang bertanda tangan di bawah ini, pengurus pondok pesantren, menerangkan bahwa santri berikut memperoleh izin resmi sesuai ketentuan pondok.',
        ],
        'izin_penutup' => [
            'group' => 'Surat Izin Santri',
            'label' => 'Paragraf penutup',
            'hint' => 'Teks penutup sebelum blok tanda tangan.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Demikian surat izin ini dibuat agar dapat dipergunakan sebagaimana mestinya dan dipatuhi waktu kembalinya.',
        ],
        'izin_nb_tugas' => [
            'group' => 'Surat Izin Santri',
            'label' => 'Catatan NB (izin tugas)',
            'hint' => 'Kotak kuning catatan untuk kategori izin tugas/pulang.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Jika ingin melakukan perpanjangan izin tugas, harap sowan pengasuh terlebih dahulu.',
        ],
        'izin_nb_sakit_keluar' => [
            'group' => 'Surat Izin Santri',
            'label' => 'Catatan NB (izin sakit/keluar)',
            'hint' => 'Kotak kuning catatan untuk kategori sakit atau keluar.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Jika ingin melakukan perpanjangan izin sakit/keluar, harap konfirmasi kepada petugas.',
        ],
        'rombongan_judul' => [
            'group' => 'Surat Izin Rombongan',
            'label' => 'Judul surat',
            'hint' => 'Judul surat izin keluar rombongan.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Surat Keterangan Izin Keluar Rombongan Santri',
        ],
        'rombongan_pembuka' => [
            'group' => 'Surat Izin Rombongan',
            'label' => 'Paragraf pembuka',
            'hint' => 'Pengantar sebelum daftar santri rombongan.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Yang bertanda tangan di bawah ini, Pengurus {nama_ponpes}, dengan ini menerangkan bahwa santri-santri yang namanya tercantum dalam daftar berikut diizinkan untuk keluar pesantren secara rombongan dengan ketentuan dan masa berlaku sebagaimana disebutkan di bawah ini.',
        ],
        'rombongan_catatan' => [
            'group' => 'Surat Izin Rombongan',
            'label' => 'Catatan rombongan',
            'hint' => 'Kotak catatan tentang QR dan scan kembali.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Surat ini berlaku untuk seluruh rombongan. Saat tiba di pesantren, scan QR kartu masing-masing santri di halaman Scan Presensi — izin otomatis selesai per santri.',
        ],
        'rombongan_penutup' => [
            'group' => 'Surat Izin Rombongan',
            'label' => 'Paragraf penutup',
            'hint' => 'Teks penutup sebelum tanda tangan.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Demikian surat keterangan izin keluar rombongan ini dibuat dengan sebenarnya untuk dapat dipergunakan sebagaimana mestinya. Seluruh santri rombongan wajib mematuhi tata tertib pondok dan kembali tepat pada waktu yang ditetapkan.',
        ],
        'izin_tetap_judul' => [
            'group' => 'Surat Izin Tetap',
            'label' => 'Judul surat',
            'hint' => 'Judul surat keterangan izin tetap.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'SURAT KETERANGAN IZIN TETAP SANTRI',
        ],
        'izin_tetap_pembuka' => [
            'group' => 'Surat Izin Tetap',
            'label' => 'Paragraf pembuka',
            'hint' => 'Gunakan {uraian_kalimat} untuk kalimat jenis izin tetap.',
            'placeholders' => '{nama_ponpes}, {uraian_kalimat}',
            'default' => 'Yang bertanda tangan di bawah ini, Pengurus {nama_ponpes} menerangkan bahwa santri berikut memperoleh izin tetap untuk melaksanakan {uraian_kalimat} pada hari dan waktu yang tercantum.',
        ],
        'izin_tetap_gabungan_pembuka' => [
            'group' => 'Surat Izin Tetap',
            'label' => 'Paragraf pembuka (surat gabungan)',
            'hint' => 'Dipakai jika satu surat berisi lebih dari satu santri. Tabel santri mengikuti paragraf ini.',
            'placeholders' => '{nama_ponpes}, {uraian_kalimat}, {jumlah_santri}',
            'default' => 'Dengan ini pengurus memintakan izin kepada pengasuh untuk santri-santri di bawah ini:',
        ],
        'izin_tetap_catatan' => [
            'group' => 'Surat Izin Tetap',
            'label' => 'Catatan umum',
            'hint' => 'Kotak catatan di bawah data izin tetap.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Santri wajib mematuhi tata tertib pondok dan menjaga nama baik lembaga.',
        ],
        'izin_tetap_penutup' => [
            'group' => 'Surat Izin Tetap',
            'label' => 'Paragraf penutup',
            'hint' => 'Teks penutup sebelum tanda tangan.',
            'placeholders' => '{nama_ponpes}, {kota_ponpes}',
            'default' => 'Demikian surat keterangan ini dibuat dengan sebenarnya untuk dipergunakan sebagaimana mestinya.',
        ],
        'keluar_judul' => [
            'group' => 'Surat Keluar Santri',
            'label' => 'Judul surat',
            'hint' => 'Judul surat keterangan keluar.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'SURAT KETERANGAN KELUAR SANTRI',
        ],
        'keluar_pembuka' => [
            'group' => 'Surat Keluar Santri',
            'label' => 'Paragraf pembuka',
            'hint' => 'Pengantar sebelum tabel biodata santri.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Yang bertanda tangan di bawah ini, pengurus {nama_ponpes}, menerangkan bahwa:',
        ],
        'keluar_administrasi' => [
            'group' => 'Surat Keluar Santri',
            'label' => 'Paragraf administrasi keuangan',
            'hint' => 'Pernyataan penyelesaian administrasi keuangan.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Administrasi keuangan pondok atas nama santri tersebut telah diselesaikan sesuai ketentuan yang berlaku pada tanggal diterbitkannya surat ini.',
        ],
        'keluar_penutup' => [
            'group' => 'Surat Keluar Santri',
            'label' => 'Paragraf penutup',
            'hint' => 'Kalimat penutup surat keluar.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Demikian surat keterangan ini dibuat untuk dipergunakan sebagaimana mestinya.',
        ],
        'tanggungan_judul' => [
            'group' => 'Surat Tanggungan',
            'label' => 'Judul surat',
            'hint' => 'Judul surat pernyataan penyelesaian tanggungan.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'SURAT PERNYATAAN PENYELESAIAN TANGGUNGAN',
        ],
        'tanggungan_pembuka' => [
            'group' => 'Surat Tanggungan',
            'label' => 'Paragraf pembuka',
            'hint' => 'Pengantar sebelum data santri.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Bersama ini kami sampaikan pernyataan penyelesaian tanggihan keuangan santri:',
        ],
        'tanggungan_penutup' => [
            'group' => 'Surat Tanggungan',
            'label' => 'Paragraf penutup',
            'hint' => 'Kalimat penutup surat tanggungan.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Demikian pernyataan ini dibuat dengan sebenarnya untuk dipergunakan seperlunya.',
        ],
        'sp1_judul' => [
            'group' => 'Surat Peringatan (SP)',
            'label' => 'Judul SP1',
            'hint' => 'Judul surat peringatan tingkat 1.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Surat Peringatan 1 (SP1)',
        ],
        'sp1_pembuka' => [
            'group' => 'Surat Peringatan (SP)',
            'label' => 'Paragraf pembuka SP1',
            'hint' => 'Pengantar sebelum tabel data poin SP1.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Yang bertanda tangan di bawah ini, pengurus {nama_ponpes}, menerangkan bahwa:',
        ],
        'sp1_isi' => [
            'group' => 'Surat Peringatan (SP)',
            'label' => 'Isi SP1',
            'hint' => 'Paragraf penjelasan pemberian SP1. Gunakan {judul_sp}.',
            'placeholders' => '{nama_ponpes}, {judul_sp}, {total_poin}, {periode}',
            'default' => 'Santri tersebut telah mencapai akumulasi poin kedisiplinan sesuai ketentuan pondok, sehingga diberikan {judul_sp}.',
        ],
        'sp1_sanksi' => [
            'group' => 'Surat Peringatan (SP)',
            'label' => 'Tindak lanjut SP1',
            'hint' => 'Teks kotak tindak lanjut SP1.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Pembinaan kedisiplinan tahap awal sesuai ketentuan pondok.',
        ],
        'sp1_penutup' => [
            'group' => 'Surat Peringatan (SP)',
            'label' => 'Paragraf penutup SP1',
            'hint' => 'Kalimat penutup SP1.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Demikian surat ini dibuat untuk dipergunakan sebagaimana mestinya.',
        ],
        'sp2_judul' => [
            'group' => 'Surat Peringatan (SP)',
            'label' => 'Judul SP2',
            'hint' => 'Judul surat peringatan tingkat 2.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Surat Peringatan 2 (SP2)',
        ],
        'sp2_pembuka' => [
            'group' => 'Surat Peringatan (SP)',
            'label' => 'Paragraf pembuka SP2',
            'hint' => 'Pengantar sebelum tabel data poin SP2.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Yang bertanda tangan di bawah ini, pengurus {nama_ponpes}, menerangkan bahwa:',
        ],
        'sp2_isi' => [
            'group' => 'Surat Peringatan (SP)',
            'label' => 'Isi SP2',
            'hint' => 'Paragraf penjelasan pemberian SP2.',
            'placeholders' => '{nama_ponpes}, {judul_sp}, {total_poin}, {periode}',
            'default' => 'Santri tersebut telah mencapai akumulasi poin kedisiplinan sesuai ketentuan pondok, sehingga diberikan {judul_sp}.',
        ],
        'sp2_sanksi' => [
            'group' => 'Surat Peringatan (SP)',
            'label' => 'Tindak lanjut SP2',
            'hint' => 'Teks kotak tindak lanjut SP2.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Pemanggilan orang tua/wali dan pembinaan kedisiplinan lanjutan sesuai ketentuan pondok.',
        ],
        'sp2_penutup' => [
            'group' => 'Surat Peringatan (SP)',
            'label' => 'Paragraf penutup SP2',
            'hint' => 'Kalimat penutup SP2.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Demikian surat ini dibuat untuk dipergunakan sebagaimana mestinya.',
        ],
        'notulen_judul' => [
            'group' => 'Notulen Rapat Yayasan',
            'label' => 'Judul dokumen',
            'hint' => 'Judul di bawah nama pondok pada cetak notulen.',
            'placeholders' => '{nama_ponpes}',
            'default' => 'Notulen Rapat',
        ],
        'rapor_judul' => [
            'group' => 'Rapor Akademik',
            'label' => 'Judul rapor pesantren',
            'hint' => 'Judul utama pada halaman cetak rapor pesantren (bukan PKPPS).',
            'placeholders' => '{nama_ponpes}, {tahun_ajaran}',
            'default' => 'Rapor Akademik Santri',
        ],
        'skbt_judul' => [
            'group' => 'SKBT (Laporan Santri)',
            'label' => 'Judul dokumen SKBT',
            'hint' => 'Judul pada halaman pertama cetak SKBT.',
            'placeholders' => '{nama_ponpes}, {nama_santri}',
            'default' => 'SKBT {nama_ponpes}',
        ],
    ];
}

function surat_cetak_template_setting_key(string $slug): string
{
    return 'surat_tpl_' . preg_replace('/[^a-z0-9_]/', '', strtolower($slug));
}

function surat_cetak_template_get(PDO $pdo, string $slug): string
{
    $defs = surat_cetak_template_definitions();
    if (!isset($defs[$slug])) {
        return '';
    }
    $custom = trim((string) app_setting($pdo, surat_cetak_template_setting_key($slug), ''));
    if ($slug === 'izin_tetap_gabungan_pembuka' && $custom === 'Yang bertanda tangan di bawah ini, Pengurus {nama_ponpes} menerangkan bahwa santri-santri berikut ({jumlah_santri} orang) memperoleh izin tetap untuk melaksanakan {uraian_kalimat} pada hari dan waktu yang tercantum.') {
        $custom = '';
    }

    return $custom !== '' ? $custom : (string) $defs[$slug]['default'];
}

/**
 * @param array<string, string> $vars
 */
function surat_cetak_template_render(PDO $pdo, string $slug, array $vars = []): string
{
    $tpl = surat_cetak_template_get($pdo, $slug);
    foreach ($vars as $key => $value) {
        $tpl = str_replace('{' . $key . '}', (string) $value, $tpl);
    }

    return $tpl;
}

/**
 * @return array<string, string>
 */
function surat_cetak_template_values(PDO $pdo): array
{
    $out = [];
    foreach (surat_cetak_template_definitions() as $slug => $meta) {
        $out[$slug] = surat_cetak_template_get($pdo, $slug);
    }

    return $out;
}

/**
 * @return array{ok:bool, message:string}
 */
function surat_cetak_kop_save(PDO $pdo, array $post): array
{
    $accent = trim((string) ($post['kop_accent_color'] ?? ''));
    if ($accent !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        return ['ok' => false, 'message' => 'Warna aksen kop harus format hex (#RRGGBB).'];
    }

    foreach (surat_cetak_kop_setting_keys() as $key) {
        if (!array_key_exists($key, $post)) {
            continue;
        }
        $val = trim((string) $post[$key]);
        $default = surat_cetak_kop_defaults()[$key] ?? '';
        if ($val === '' || $val === $default) {
            $st = $pdo->prepare('DELETE FROM app_settings WHERE setting_key = :k LIMIT 1');
            $st->execute(['k' => $key]);
        } else {
            save_setting($pdo, $key, $val);
        }
    }

    if (function_exists('app_settings_cache_reset')) {
        app_settings_cache_reset($pdo);
    }

    return ['ok' => true, 'message' => 'Pengaturan kop surat disimpan.'];
}

/**
 * @return array{ok:bool, message:string}
 */
function surat_cetak_template_save_all(PDO $pdo, array $post): array
{
    foreach (surat_cetak_template_definitions() as $slug => $meta) {
        $field = 'surat_tpl_' . $slug;
        if (!array_key_exists($field, $post)) {
            continue;
        }
        $val = trim((string) $post[$field]);
        if ($val === '' || $val === (string) $meta['default']) {
            $st = $pdo->prepare('DELETE FROM app_settings WHERE setting_key = :k LIMIT 1');
            $st->execute(['k' => surat_cetak_template_setting_key($slug)]);
        } else {
            save_setting($pdo, surat_cetak_template_setting_key($slug), $val);
        }
    }
    if (function_exists('app_settings_cache_reset')) {
        app_settings_cache_reset($pdo);
    }

    return ['ok' => true, 'message' => 'Template isian surat cetak disimpan.'];
}

/**
 * Kelompokkan definisi template per grup untuk tampilan form.
 *
 * @return array<string, array<string, array{group:string, label:string, hint:string, placeholders:string, default:string}>>
 */
function surat_cetak_template_groups(): array
{
    $groups = [];
    foreach (surat_cetak_template_definitions() as $slug => $meta) {
        $group = (string) ($meta['group'] ?? 'Lainnya');
        if (!isset($groups[$group])) {
            $groups[$group] = [];
        }
        $groups[$group][$slug] = $meta;
    }

    return $groups;
}
