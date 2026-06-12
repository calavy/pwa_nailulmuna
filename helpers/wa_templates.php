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
        'izin_disetujui_pembimbing' => [
            'label' => 'Izin disetujui → pembimbing',
            'hint' => 'Dikirim otomatis ke pembimbing terkait saat izin santri disetujui. Izin rombongan: {daftar_santri} berisi semua nama.',
            'placeholders' => '{nama_santri}, {daftar_santri}, {nis}, {tingkatan}, {jenis_izin}, {tanggal_mulai}, {tanggal_selesai}, {jam_mulai}, {jam_selesai}, {alasan}, {nama_pembimbing}, {nama_ponpes}, {doa}',
            'default' => "Assalamu'alaikum.\n\n"
                . 'Izin santri binaan *{nama_santri}* ({nis}) · {tingkatan} telah *DISETUJUI*.\n'
                . '{daftar_santri}'
                . 'Pembimbing: *{nama_pembimbing}*\n'
                . 'Jenis: {jenis_izin}\n'
                . 'Periode: {tanggal_mulai} s/d {tanggal_selesai}\n'
                . 'Waktu: {jam_mulai} – {jam_selesai}\n'
                . 'Keperluan: {alasan}'
                . '{doa}',
        ],
        'izin_grup_fonte' => [
            'label' => 'Izin disetujui → grup WA (Fonte)',
            'hint' => 'Dikirim ke ID grup Fonte saat izin santri disetujui (pengaturan Perizinan). Izin rombongan: {daftar_santri} berisi semua nama.',
            'placeholders' => '{nama_santri}, {daftar_santri}, {nis}, {tingkatan}, {jenis_izin}, {tanggal_mulai}, {tanggal_selesai}, {jam_mulai}, {jam_selesai}, {alasan}, {nama_ponpes}, {doa}',
            'default' => "📋 *Izin santri disetujui*\n\n"
                . '*{nama_santri}* ({nis}) · {tingkatan}\n'
                . '{daftar_santri}'
                . '{jenis_izin} · {tanggal_mulai} s/d {tanggal_selesai}\n'
                . 'Jam: {jam_mulai} – {jam_selesai}\n'
                . 'Keperluan: {alasan}\n'
                . '— {nama_ponpes}'
                . '{doa}',
        ],
        'izin_disetujui_wali' => [
            'label' => 'Izin disetujui → wali santri',
            'hint' => 'Dikirim otomatis ke nomor WA wali saat permohonan izin disetujui pengurus.',
            'placeholders' => '{nama_santri}, {jenis_izin}, {tanggal_mulai}, {tanggal_selesai}, {jam_mulai}, {jam_selesai}, {periode}, {waktu}, {alasan}, {nama_ponpes}',
            'default' => "Assalamu'alaikum warahmatullahi wabarakatuh.\n\n"
                . '*Yth. Wali santri {nama_santri}*\n\n'
                . '*SURAT PEMBERITAHUAN (digital)*\n\n'
                . 'Permohonan *{jenis_izin}* atas nama *{nama_santri}* telah *DISETUJUI* oleh pengurus *{nama_ponpes}*.\n\n'
                . 'Periode: *{periode}*\n'
                . 'Waktu: *{waktu}*\n'
                . 'Keterangan: _{alasan}_\n\n'
                . 'Mohon putra/putri Anda kembali tepat waktu sesuai ketentuan yang berlaku.\n\n'
                . "Wassalamu'alaikum warahmatullahi wabarakatuh.\n"
                . '_{nama_ponpes}_',
        ],
        'cashless_saldo_rendah_wali' => [
            'label' => 'Saldo cashless rendah → wali santri',
            'hint' => 'Dikirim otomatis ke wali saat saldo cashless turun ke ambang atau di bawahnya.',
            'placeholders' => '{nama_santri}, {saldo_tersisa}, {ambang}, {nama_ponpes}',
            'default' => "Assalamu'alaikum warahmatullahi wabarakatuh.\n\n"
                . '*Yth. Wali santri {nama_santri}*\n\n'
                . 'Saldo *cashless* (saku) putra/putri Anda di *{nama_ponpes}* tersisa *{saldo_tersisa}* '
                . '(ambang peringatan: {ambang}).\n\n'
                . 'Mohon segera melakukan top-up agar kegiatan belanja harian tidak terganggu.\n\n'
                . "Wassalamu'alaikum warahmatullahi wabarakatuh.\n"
                . '_{nama_ponpes}_',
        ],
        'izin_disetujui_pengurus' => [
            'label' => 'Izin disetujui → pengurus (petugas surat)',
            'hint' => 'Dikirim ke nomor pengurus saat izin disetujui — surat siap dicetak. Izin rombongan: {daftar_santri}.',
            'placeholders' => '{nama_santri}, {daftar_santri}, {nis}, {tingkatan}, {jenis_izin}, {tanggal_mulai}, {tanggal_selesai}, {jam_mulai}, {jam_selesai}, {alasan}, {nama_pengurus}, {nama_ponpes}',
            'default' => "📄 *Izin disetujui — siap cetak surat*\n\n"
                . '*{nama_santri}* ({nis}) · {tingkatan}\n'
                . '{daftar_santri}'
                . 'Jenis: {jenis_izin}\n'
                . 'Periode: {tanggal_mulai} s/d {tanggal_selesai}\n'
                . 'Jam: {jam_mulai} – {jam_selesai}\n'
                . 'Keperluan: {alasan}\n'
                . 'Disetujui oleh: *{nama_pengurus}*\n'
                . '— {nama_ponpes}',
        ],
        'izin_selesai_pengurus' => [
            'label' => 'Izin selesai → pengurus (laporan kembali)',
            'hint' => 'Dikirim saat santri tercatat kembali (scan QR atau tandai selesai). {info_telat} kosong jika tepat waktu.',
            'placeholders' => '{nama_santri}, {nis}, {tingkatan}, {jenis_izin}, {waktu_kembali}, {info_telat}, {nama_ponpes}',
            'default' => "✅ *Laporan izin selesai*\n\n"
                . '*{nama_santri}* ({nis}) · {tingkatan}\n'
                . 'Jenis izin: {jenis_izin}\n'
                . 'Waktu kembali: {waktu_kembali}\n'
                . '{info_telat}'
                . '— {nama_ponpes}',
        ],
        'izin_sakit_doa' => [
            'label' => 'Doa tambahan izin sakit',
            'hint' => 'Ditambahkan otomatis di akhir pesan WA saat jenis izin sakit disetujui. Kosongkan untuk menonaktifkan.',
            'placeholders' => '{nama_santri}, {nama_ponpes}',
            'default' => "\n\n🤲 *Doa kesembuhan:*\n"
                . "اللَّهُمَّ رَبَّ النَّاسِ أَذْهِبِ الْبَأْسَ وَاشْفِ أَنْتَ الشَّافِي لَا شِفَاءَ إِلَّا شِفَاؤُكَ شِفَاءً لَا يُغَادِرُ سَقَمًا\n\n"
                . '_Allahumma Rabban-nas, adzhibil ba\'sa, wa syfihi, Antasy-Syafi, la syifaa\'a illa syifaa\'uka, syifaa\'an la yughadiru saqama._\n\n'
                . 'Semoga Allah Yang Maha Penyembuh memberikan kesembuhan kepada *{nama_santri}*. Aamiin.',
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
