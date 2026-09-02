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
            'label' => 'Tagihan syahriyah & makan ke wali',
            'hint' => 'Dikirim otomatis / manual ke wali santri yang masih punya kekurangan syahriyah dan/atau makan (tunggakan TA jika kumulatif aktif).',
            'placeholders' => '{nama_santri}, {nama_ponpes}, {label_kekurangan}, {total_sisa}, {periode_tagihan}, {rincian_per_bulan}, {keterangan_keuangan}',
            'default' => "Assalamu'alaikum Wr. Wb.\n"
                . 'Nyuwun pangapunten, kepareng matur dateng Bpk/Ibu wali saking *{nama_santri}*\n'
                . 'Atasnama Pengurus *{nama_ponpes}* *Pengurus Bidang Keuangan*,\n'
                . '{keterangan_keuangan}'
                . 'memberitahukan bahwa putra/putri Bapak/Ibu masih mempunyai kekurangan{periode_tagihan} '
                . '{label_kekurangan}, dan jumlah total *{total_sisa}*.'
                . '{rincian_per_bulan}'
                . "\nBerkenaan dengan hal tersebut, kami mohon maaf baru saat ini dapat melaporkan kepada Bapak/Ibu. "
                . 'Atas pengertian dan kerja samanya kami ucapkan terima kasih 🙏.',
        ],
        'pembimbing_belum_scan' => [
            'label' => 'Pembimbing / munawib belum scan',
            'hint' => 'Dikirim ~10 menit sebelum kegiatan selesai jika belum scan kehadiran.',
            'placeholders' => '{nama_pembimbing}, {nama_kegiatan}',
            'default' => 'Nyuwun pangapunten, {nama_pembimbing} ngemutaken bilih panjenengan dereng scan kehadiran {nama_kegiatan}.',
        ],
        'kelas_kosong_pengurus' => [
            'label' => 'Laporan kegiatan kosong → petugas / pengurus',
            'hint' => 'Dikirim setelah jam kegiatan selesai jika slot kosong (tanpa scan santri dan/atau pembimbing belum hadir). Blok {baris_eskalasi}, {baris_kelas}, {baris_tempat}, {detail} menyesuaikan jumlah tingkatan.',
            'placeholders' => '{counter}, {batas_kali}, {tanggal}, {nama_kegiatan}, {jam}, {jam_mulai}, {jam_selesai}, {tingkatan}, {tempat}, {nama_pembimbing}, {alasan}, {id_jadwal}, {nama_ponpes}, {baris_eskalasi}, {baris_kelas}, {baris_tempat}, {detail}',
            'default' => "⚠️ Laporan kegiatan kosong (deteksi ke-{counter})\n"
                . '{baris_eskalasi}'
                . "Tanggal: {tanggal}\n"
                . "Kegiatan: {nama_kegiatan}\n"
                . "Jam: {jam}\n"
                . "{baris_kelas}\n"
                . '{baris_tempat}'
                . '{detail}',
        ],
        'kelas_kosong_pembimbing' => [
            'label' => 'Laporan kegiatan kosong → pembimbing jadwal',
            'hint' => 'Dikirim ke pembimbing yang slotnya kosong, bersamaan dengan laporan ke petugas/pengurus. {baris_eskalasi} kosong jika belum eskalasi.',
            'placeholders' => '{counter}, {batas_kali}, {tanggal}, {nama_kegiatan}, {jam}, {jam_mulai}, {jam_selesai}, {tingkatan}, {alasan}, {baris_eskalasi}, {nama_pembimbing}, {nama_ponpes}',
            'default' => "⚠️ Jadwal Anda belum terpenuhi (deteksi ke-{counter})\n"
                . '{baris_eskalasi}'
                . "Tanggal: {tanggal}\n"
                . "Kegiatan: {nama_kegiatan}\n"
                . "Jam: {jam}\n"
                . "Tingkatan: {tingkatan}\n"
                . "Alasan: {alasan}\n"
                . 'Silakan koordinasi scan/hadir segera.',
        ],
        'rekap_alpa' => [
            'label' => 'Laporan ALPA kelipatan → pengurus',
            'hint' => 'Pesan WA otomatis saat santri mencapai ambang/kelipatan poin ALPA. Placeholder {daftar_santri} atau alias {daftar_santri_alpa} diisi otomatis (dikelompokkan per tingkatan). Jangan hapus salah satu placeholder jika ingin daftar ikut terkirim.',
            'placeholders' => '{kelipatan}, {ambang}, {tanggal}, {periode}, {daftar_santri}, {daftar_santri_alpa}, {nama_ponpes}',
            'default' => "*LAPORAN SANTRI ALPA (KELIPATAN {kelipatan} POIN)*\n"
                . "Tanggal: {tanggal}\n\n"
                . "Berikut adalah daftar santri yang telah mencapai akumulasi {kelipatan} poin:\n\n"
                . "{daftar_santri}\n\n"
                . 'Mohon segera diproses atau tindakan disiplin sesuai aturan. Terima kasih.',
        ],
        'poin_ambang_pengurus' => [
            'label' => 'Ambang poin kedisiplinan → pengurus',
            'hint' => 'Dikirim otomatis ke pengurus saat total poin bulan santri mencapai ambang (5, 10, 15, …) sesuai jam kirim tiap ambang.',
            'placeholders' => '{nama_santri}, {nis}, {tingkatan}, {ambang}, {total_poin}, {periode}, {label_tier}, {nama_ponpes}',
            'default' => "Assalamu'alaikum Wr. Wb.\n\n"
                . "*NOTIFIKASI POIN KEDISIPLINAN*\n"
                . "Perihal: Santri mencapai ambang *{ambang}* poin\n"
                . "Tier: {label_tier}\n"
                . "Periode: *{periode}*\n\n"
                . "• Nama: *{nama_santri}*\n"
                . "• NIS: *{nis}*\n"
                . "• Tingkatan: *{tingkatan}*\n"
                . "• Total poin bulan ini: *{total_poin}*\n\n"
                . "Mohon ditindaklanjuti sesuai kewenangan.\n\n"
                . "_Hormat kami,_\n"
                . "_{nama_ponpes}_",
        ],
        'pengajuan_izin_baru' => [
            'label' => 'Pengajuan izin baru ke pengurus',
            'hint' => 'Notifikasi saat ada permohonan izin santri baru (PENDING). Baris opsional: {nis_baris}, {tingkatan_baris}, {tujuan_baris}.',
            'placeholders' => '{nama_santri}, {nis}, {nis_baris}, {tingkatan}, {tingkatan_baris}, {jenis_izin}, {label_alasan}, {tanggal_mulai}, {tanggal_selesai}, {jam_mulai}, {jam_selesai}, {alasan}, {tujuan}, {tujuan_baris}, {nama_ponpes}',
            'default' => "*PEMBERITAHUAN RESMI*\n"
                . "Perihal: Pengajuan perizinan santri (menunggu persetujuan)\n\n"
                . "Dengan hormat diinformasikan bahwa telah masuk permohonan izin dengan rincian:\n\n"
                . "• Nama santri: *{nama_santri}*\n"
                . "{nis_baris}{tingkatan_baris}"
                . "• Jenis izin: *{jenis_izin}*\n"
                . "• Tanggal: *{tanggal_mulai}* s/d *{tanggal_selesai}*\n"
                . "• Waktu: *{jam_mulai}* – *{jam_selesai}*\n"
                . "• {label_alasan}: _{alasan}_\n"
                . "{tujuan_baris}\n"
                . "Mohon segera ditinjau melalui panel perizinan.\n"
                . "Demikian disampaikan.\n\n"
                . "_Hormat kami,_\n"
                . "_{nama_ponpes}_",
        ],
        'izin_disetujui_pengasuh_info' => [
            'label' => 'Izin disetujui pengurus → pengasuh (info)',
            'hint' => 'Notifikasi informatif ke pengasuh saat izin non-wali disetujui pengurus (bukan antrean persetujuan).',
            'placeholders' => '{nama_santri}, {nis}, {tingkatan}, {jenis_izin}, {label_alasan}, {tanggal_mulai}, {tanggal_selesai}, {jam_mulai}, {jam_selesai}, {alasan}, {nama_penyetuju}, {nama_ponpes}',
            'default' => "*INFO IZIN DISETUJUI*\n\n"
                . "Izin berikut telah disetujui pengurus (bukan pengajuan wali):\n\n"
                . "• Santri: *{nama_santri}* ({nis})\n"
                . "• Tingkatan: {tingkatan}\n"
                . "• Jenis: *{jenis_izin}*\n"
                . "• Periode: {tanggal_mulai} s/d {tanggal_selesai}\n"
                . "• Waktu: {jam_mulai} – {jam_selesai}\n"
                . "• {label_alasan}: _{alasan}_\n"
                . "• Disetujui oleh: *{nama_penyetuju}*\n\n"
                . "_Hormat kami,_\n"
                . "_{nama_ponpes}_",
        ],
        'pembayaran_masuk_wali' => [
            'label' => 'Pembayaran tercatat → wali santri',
            'hint' => 'Dikirim otomatis ke wali saat admin input pembayaran. Status: DITERIMA atau BELUM DITERIMA · DI CICIL. Placeholder {sisa_tagihan_baris} otomatis terisi jika masih ada sisa tagihan.',
            'placeholders' => '{nama_santri}, {nominal_total}, {tanggal_bayar}, {metode_bayar}, {periode_tagihan}, {rincian_pembayaran}, {status_lunas}, {sisa_tagihan}, {sisa_tagihan_baris}, {no_kuitansi}, {keterangan}, {nama_ponpes}',
            'default' => '*Yth. Wali santri {nama_santri}*\n\n'
                . 'Kami informasikan bahwa pembayaran putra/putri Anda telah *tercatat* di *{nama_ponpes}*:\n\n'
                . 'Tanggal: *{tanggal_bayar}*\n'
                . 'Periode: *{periode_tagihan}*\n'
                . 'Metode: *{metode_bayar}*\n'
                . 'Total dibayar: *{nominal_total}*\n'
                . 'Status: *{status_lunas}*\n'
                . '{sisa_tagihan_baris}'
                . '{rincian_pembayaran}'
                . '{keterangan}'
                . 'No. kuitansi: *{no_kuitansi}*\n\n'
                . 'Terima kasih atas kepercayaan dan kerja samanya.\n\n'
                . '_{nama_ponpes}_',
        ],
        'izin_disetujui_pembimbing_sakit' => [
            'label' => 'Izin sakit disetujui → pembimbing',
            'hint' => 'Template khusus izin sakit ke pembimbing. Placeholder {doa} ditambahkan otomatis jika kosong di template.',
            'placeholders' => '{judul_disetujui}, {nama_santri}, {daftar_santri}, {nis}, {tingkatan}, {jenis_izin}, {label_alasan}, {tanggal_mulai}, {tanggal_selesai}, {jam_mulai}, {jam_selesai}, {alasan}, {nama_pembimbing}, {nama_ponpes}, {nama_penyetuju}, {ttd_penyetuju}, {doa}',
            'default' => '🤒 {judul_disetujui} *{nama_santri}* ({nis}) · {tingkatan} telah *DISETUJUI*.\n'
                . '{daftar_santri}'
                . 'Pembimbing: *{nama_pembimbing}*\n'
                . 'Jenis: *{jenis_izin}*\n'
                . 'Periode: {tanggal_mulai} s/d {tanggal_selesai}\n'
                . 'Waktu: {jam_mulai} – {jam_selesai}\n'
                . '{label_alasan}: {alasan}\n'
                . 'Mohon pantau kondisi kesehatan santri binaan.'
                . '{doa}'
                . "\n\n_Hormat kami,_\n"
                . '_{nama_penyetuju}_',
        ],
        'izin_disetujui_pembimbing_lainnya' => [
            'label' => 'Izin (bukan sakit) disetujui → pembimbing',
            'hint' => 'Template keluar, izin, tugas, dll. ke pembimbing. Izin rombongan: {daftar_santri}.',
            'placeholders' => '{judul_disetujui}, {nama_santri}, {daftar_santri}, {nis}, {tingkatan}, {jenis_izin}, {label_alasan}, {tanggal_mulai}, {tanggal_selesai}, {jam_mulai}, {jam_selesai}, {alasan}, {nama_pembimbing}, {nama_ponpes}, {nama_penyetuju}, {ttd_penyetuju}',
            'default' => '{judul_disetujui} *{nama_santri}* ({nis}) · {tingkatan} telah *DISETUJUI*.\n'
                . '{daftar_santri}'
                . 'Pembimbing: *{nama_pembimbing}*\n'
                . 'Jenis: *{jenis_izin}*\n'
                . 'Periode: {tanggal_mulai} s/d {tanggal_selesai}\n'
                . 'Waktu: {jam_mulai} – {jam_selesai}\n'
                . '{label_alasan}: {alasan}'
                . "\n\n_Hormat kami,_\n"
                . '_{nama_penyetuju}_',
        ],
        'izin_grup_fonte_sakit' => [
            'label' => 'Izin sakit disetujui → grup WA (Fonte)',
            'hint' => 'Template khusus izin sakit ke grup Fonte. {doa} opsional.',
            'placeholders' => '{judul_grup}, {nama_santri}, {daftar_santri}, {nis}, {tingkatan}, {jenis_izin}, {label_alasan}, {tanggal_mulai}, {tanggal_selesai}, {jam_mulai}, {jam_selesai}, {alasan}, {nama_ponpes}, {nama_penyetuju}, {ttd_penyetuju}, {doa}',
            'default' => "{judul_grup}\n\n"
                . '*{nama_santri}* ({nis}) · {tingkatan}\n'
                . '{daftar_santri}'
                . 'Jenis: *{jenis_izin}* · {tanggal_mulai} s/d {tanggal_selesai}\n'
                . 'Jam: {jam_mulai} – {jam_selesai}\n'
                . '{label_alasan}: {alasan}\n'
                . '{disetujui_oleh_baris}'
                . "\n\n_Hormat kami,_\n"
                . '_{nama_penyetuju}_'
                . '{doa}',
        ],
        'izin_grup_fonte_lainnya' => [
            'label' => 'Izin (bukan sakit) disetujui → grup WA (Fonte)',
            'hint' => 'Template keluar, izin, tugas, dll. ke grup Fonte.',
            'placeholders' => '{judul_grup}, {nama_santri}, {daftar_santri}, {nis}, {tingkatan}, {jenis_izin}, {label_alasan}, {tanggal_mulai}, {tanggal_selesai}, {jam_mulai}, {jam_selesai}, {alasan}, {nama_ponpes}, {nama_penyetuju}, {ttd_penyetuju}, {disetujui_oleh_baris}',
            'default' => "{judul_grup}\n\n"
                . '*{nama_santri}* ({nis}) · {tingkatan}\n'
                . '{daftar_santri}'
                . 'Jenis: *{jenis_izin}* · {tanggal_mulai} s/d {tanggal_selesai}\n'
                . 'Jam: {jam_mulai} – {jam_selesai}\n'
                . '{label_alasan}: {alasan}\n'
                . '{disetujui_oleh_baris}'
                . "\n\n_Hormat kami,_\n"
                . '_{nama_penyetuju}_',
        ],
        'izin_disetujui_wali_sakit' => [
            'label' => 'Izin sakit disetujui → wali santri',
            'hint' => 'Template khusus izin sakit ke wali. Field alasan ditampilkan sebagai {label_alasan}.',
            'placeholders' => '{nama_santri}, {jenis_izin}, {label_alasan}, {tanggal_mulai}, {tanggal_selesai}, {jam_mulai}, {jam_selesai}, {periode}, {waktu}, {alasan}, {nama_ponpes}, {nama_penyetuju}, {ttd_penyetuju}, {instruksi_wali}',
            'default' => '*Yth. Wali santri {nama_santri}*\n\n'
                . '*PEMBERITAHUAN IZIN SAKIT (digital)*\n\n'
                . 'Permohonan *{jenis_izin}* atas nama *{nama_santri}* telah *DISETUJUI* oleh pengurus *{nama_penyetuju}*.\n\n'
                . 'Periode: *{periode}*\n'
                . 'Waktu: *{waktu}*\n'
                . '{label_alasan}: _{alasan}_\n\n'
                . '{instruksi_wali}\n\n'
                . "_Hormat kami,_\n"
                . '_{nama_penyetuju}_',
        ],
        'izin_disetujui_wali_lainnya' => [
            'label' => 'Izin (bukan sakit) disetujui → wali santri',
            'hint' => 'Template keluar, izin, tugas, dll. ke wali. Field keperluan ditampilkan sebagai {label_alasan}.',
            'placeholders' => '{nama_santri}, {jenis_izin}, {label_alasan}, {tanggal_mulai}, {tanggal_selesai}, {jam_mulai}, {jam_selesai}, {periode}, {waktu}, {alasan}, {nama_ponpes}, {nama_penyetuju}, {ttd_penyetuju}, {instruksi_wali}',
            'default' => '*Yth. Wali santri {nama_santri}*\n\n'
                . '*SURAT PEMBERITAHUAN (digital)*\n\n'
                . 'Permohonan *{jenis_izin}* atas nama *{nama_santri}* telah *DISETUJUI* oleh pengurus *{nama_penyetuju}*.\n\n'
                . 'Periode: *{periode}*\n'
                . 'Waktu: *{waktu}*\n'
                . '{label_alasan}: _{alasan}_\n\n'
                . '{instruksi_wali}\n\n'
                . "_Hormat kami,_\n"
                . '_{nama_penyetuju}_',
        ],
        'cashless_saldo_rendah_wali' => [
            'label' => 'Saldo cashless rendah → wali santri',
            'hint' => 'Dikirim otomatis ke wali saat saldo cashless turun ke ambang atau di bawahnya.',
            'placeholders' => '{nama_santri}, {saldo_tersisa}, {ambang}, {nama_ponpes}',
            'default' => '*Yth. Wali santri {nama_santri}*\n\n'
                . 'Saldo *cashless* (saku) putra/putri Anda di *{nama_ponpes}* tersisa *{saldo_tersisa}* '
                . '(ambang peringatan: {ambang}).\n\n'
                . 'Mohon segera melakukan top-up agar kegiatan belanja harian tidak terganggu.\n\n'
                . '_{nama_ponpes}_',
        ],
        'cashless_transaksi_sukses_wali' => [
            'label' => 'Transaksi cashless berhasil → wali santri',
            'hint' => 'Dikirim ke wali setiap transaksi scan cashless berhasil.',
            'placeholders' => '{nama_santri}, {nominal}, {nama_koperasi}, {saldo_keseluruhan}, {sisa_jatah_hari}, {limit_harian}, {terpakai_hari}, {nama_ponpes}',
            'default' => '*Yth. Wali santri {nama_santri}*\n\n'
                . 'Transaksi *cashless* berhasil di *{nama_koperasi}*:\n'
                . 'Nominal: *{nominal}*\n\n'
                . 'Saldo keseluruhan: *{saldo_keseluruhan}*\n'
                . 'Jatah belanja hari ini (sisa): *{sisa_jatah_hari}* dari {limit_harian} (terpakai {terpakai_hari})\n\n'
                . '_{nama_ponpes}_',
        ],
        'cashless_laporan_harian_pengurus' => [
            'label' => 'Laporan harian transaksi cashless → pengurus',
            'hint' => 'Rekap total transaksi debit cashless satu hari, dipecah per koperasi.',
            'placeholders' => '{tanggal}, {total_transaksi}, {total_nominal}, {rincian_koperasi}, {nama_ponpes}',
            'default' => "Assalamu'alaikum Wr. Wb.\n\n"
                . '*LAPORAN CASHLESS HARIAN*\n'
                . 'Tanggal: *{tanggal}*\n'
                . 'Total: *{total_transaksi}* transaksi · *{total_nominal}*\n\n'
                . "*Rincian per koperasi:*\n{rincian_koperasi}\n\n"
                . '— {nama_ponpes}',
        ],
        'izin_disetujui_pengurus_sakit' => [
            'label' => 'Izin sakit disetujui → pengurus (petugas surat)',
            'hint' => 'Template khusus izin sakit ke pengurus — surat siap dicetak.',
            'placeholders' => '{judul_pengurus}, {nama_santri}, {daftar_santri}, {nis}, {tingkatan}, {jenis_izin}, {label_alasan}, {tanggal_mulai}, {tanggal_selesai}, {jam_mulai}, {jam_selesai}, {alasan}, {nama_pengurus}, {nama_ponpes}, {nama_penyetuju}, {ttd_penyetuju}',
            'default' => "{judul_pengurus}\n\n"
                . '*{nama_santri}* ({nis}) · {tingkatan}\n'
                . '{daftar_santri}'
                . 'Jenis: *{jenis_izin}*\n'
                . 'Periode: {tanggal_mulai} s/d {tanggal_selesai}\n'
                . 'Jam: {jam_mulai} – {jam_selesai}\n'
                . '{label_alasan}: {alasan}\n'
                . 'Disetujui oleh: *{nama_pengurus}*\n'
                . "_Hormat kami,_\n"
                . '_{nama_penyetuju}_',
        ],
        'izin_disetujui_pengurus_lainnya' => [
            'label' => 'Izin (bukan sakit) disetujui → pengurus (petugas surat)',
            'hint' => 'Template keluar, izin, tugas, dll. ke pengurus. Izin rombongan: {daftar_santri}.',
            'placeholders' => '{judul_pengurus}, {nama_santri}, {daftar_santri}, {nis}, {tingkatan}, {jenis_izin}, {label_alasan}, {tanggal_mulai}, {tanggal_selesai}, {jam_mulai}, {jam_selesai}, {alasan}, {nama_pengurus}, {nama_ponpes}, {nama_penyetuju}, {ttd_penyetuju}',
            'default' => "{judul_pengurus}\n\n"
                . '*{nama_santri}* ({nis}) · {tingkatan}\n'
                . '{daftar_santri}'
                . 'Jenis: *{jenis_izin}*\n'
                . 'Periode: {tanggal_mulai} s/d {tanggal_selesai}\n'
                . 'Jam: {jam_mulai} – {jam_selesai}\n'
                . '{label_alasan}: {alasan}\n'
                . 'Disetujui oleh: *{nama_pengurus}*\n'
                . "_Hormat kami,_\n"
                . '_{nama_penyetuju}_',
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
        'yayasan_tugas_baru' => [
            'label' => 'Penugasan baru → PJ / pembantu',
            'hint' => 'Dikirim ke setiap PJ dan pembantu saat tugas timeline dibuat.',
            'placeholders' => '{nama_pembimbing}, {peran}, {tim_penugasan}, {judul_tugas}, {kategori}, {tanggal_mulai}, {tanggal_tenggat}, {deskripsi}, {link_tugas}, {nama_ponpes}',
            'default' => "Assalamu'alaikum Wr. Wb.\n\n"
                . '*{nama_pembimbing}*, Anda ditunjuk sebagai *{peran}* tugas:\n\n'
                . '*{judul_tugas}* ({kategori})\n'
                . '{tim_penugasan}'
                . 'Mulai: {tanggal_mulai}\n'
                . 'Tenggat: *{tanggal_tenggat}*\n'
                . '{deskripsi}'
                . 'Lapor progres di aplikasi:\n{link_tugas}\n\n'
                . '— {nama_ponpes}',
        ],
        'yayasan_tugas_diubah' => [
            'label' => 'Tugas diubah → PJ / pembantu',
            'hint' => 'Dikirim ke setiap PJ dan pembantu saat data tugas diperbarui.',
            'placeholders' => '{nama_pembimbing}, {peran}, {tim_penugasan}, {judul_tugas}, {kategori}, {tanggal_mulai}, {tanggal_tenggat}, {deskripsi}, {link_tugas}, {progres}, {nama_ponpes}',
            'default' => "Assalamu'alaikum Wr. Wb.\n\n"
                . '*{nama_pembimbing}*, tugas Anda sebagai *{peran}* telah *diperbarui*:\n\n'
                . '*{judul_tugas}* ({kategori})\n'
                . '{tim_penugasan}'
                . 'Mulai: {tanggal_mulai}\n'
                . 'Tenggat: *{tanggal_tenggat}*\n'
                . 'Progres saat ini: {progres}\n'
                . '{deskripsi}'
                . 'Detail & laporan:\n{link_tugas}\n\n'
                . '— {nama_ponpes}',
        ],
        'yayasan_tugas_belum_progres' => [
            'label' => 'Pengingat belum ada progres → PJ / pembantu',
            'hint' => 'Dikirim otomatis (maks. 1×/hari per orang per tugas) jika progres masih 0%.',
            'placeholders' => '{nama_pembimbing}, {peran}, {tim_penugasan}, {judul_tugas}, {kategori}, {tanggal_mulai}, {tanggal_tenggat}, {link_tugas}, {nama_ponpes}',
            'default' => "Assalamu'alaikum Wr. Wb.\n\n"
                . '*{nama_pembimbing}* ({peran}), pengingat tugas *belum ada progres*:\n\n'
                . '*{judul_tugas}* ({kategori})\n'
                . '{tim_penugasan}'
                . 'Tenggat: *{tanggal_tenggat}*\n\n'
                . 'Mohon segera update status & unggah bukti di:\n{link_tugas}\n\n'
                . '— {nama_ponpes}',
        ],
        'tagihan_khusus_wali' => [
            'label' => 'Tagihan khusus ke wali (berobat, dll.)',
            'hint' => 'Dikirim saat admin membuat tagihan khusus ke santri (pinjaman dari alokasi syahriyah).',
            'placeholders' => '{nama_santri}, {judul}, {kategori}, {nominal}, {sisa}, {tanggal}, {keterangan}, {alokasi}, {portal_url}, {nama_ponpes}',
            'default' => '*Yth. Wali santri {nama_santri}*\n\n'
                . 'Kami informasikan tagihan *{judul}* ({kategori}):\n'
                . 'Nominal: *{nominal}*\n'
                . 'Tanggal: {tanggal}\n'
                . 'Sumber dana: pinjaman alokasi syahriyah ({alokasi})\n'
                . 'Keterangan: {keterangan}\n\n'
                . 'Silakan cek detail di portal wali: {portal_url}\n\n'
                . '_{nama_ponpes}_',
        ],
        'rapor_terbit_pesantren' => [
            'label' => 'Rapor pesantren diterbitkan → wali santri',
            'hint' => 'Dikirim otomatis ke wali saat rapor pesantren diterbitkan (jika fitur aktif di bawah).',
            'placeholders' => '{nama_santri}, {judul_periode}, {tanggal_terbit}, {nis}, {portal_url}, {jenis_rapor}, {nama_ponpes}',
            'default' => 'Kami informasikan rapor akademik untuk *{nama_santri}* ({judul_periode}).\n'
                . 'Silakan cek di portal wali: {portal_url}\n\n'
                . 'Terima kasih.\n'
                . '_{nama_ponpes}_',
        ],
        'rapor_terbit_pkpps' => [
            'label' => 'Rapor PKPPS diterbitkan → wali santri',
            'hint' => 'Dikirim otomatis ke wali saat rapor PKPPS diterbitkan (jika fitur aktif di bawah).',
            'placeholders' => '{nama_santri}, {judul_periode}, {tanggal_terbit}, {nis}, {portal_url}, {jenis_rapor}, {nama_ponpes}',
            'default' => 'Kami informasikan rapor PKPPS untuk *{nama_santri}* ({judul_periode}).\n'
                . 'Silakan cek di portal wali: {portal_url}\n\n'
                . 'Terima kasih.\n'
                . '_{nama_ponpes}_',
        ],
        'kedatangan_libur_wali' => [
            'label' => 'Kedatangan setelah libur → wali santri',
            'hint' => 'Dikirim otomatis sekali per santri per sesi saat kartu discan. {tanggal} = hari dan tanggal Indonesia; {jam} tanpa WIB (kata WIB ada di template).',
            'placeholders' => '{nama_santri}, {nis}, {tingkatan}, {nama_libur}, {tanggal}, {jam}, {nama_ponpes}',
            'default' => "✨ INFO KEDATANGAN SANTRI ✨\n\n"
                . "Ananda *{nama_santri}* ({nis}) telah tiba di pondok dengan selamat setelah *{nama_libur}* pada:\n\n"
                . "📅 Hari/Tgl: {tanggal}\n\n"
                . "⏰ Pukul: {jam} WIB\n\n"
                . "🌟 Kondisi: Sehat walafiat\n\n"
                . "Mohon doa Bapak/Ibu Wali Santri sekalian, semoga Ananda senantiasa diberikan kemudahan dan kelancaran dalam menuntut ilmu di *{nama_ponpes}*. 🤲✨\n\n"
                . "Terima kasih banyak atas kerja sama dan kepercayaan Bapak/Ibu. 🙏😊\n\n"
                . "— Pengurus {nama_ponpes}\n\n"
                . 'NB: Pesan ini dikirim otomatis oleh SIPNA (Sistem Informasi Pesantren Nailul Muna), mohon untuk tidak membalas pesan ini.',
        ],
        'kedatangan_libur_pengurus' => [
            'label' => 'Kedatangan libur — sudah datang → pengurus',
            'hint' => 'Dikirim petugas dari tombol “Kirim yang sudah datang”. Putra/putri terpisah. Baris: Nama (NIS) — jam hadir; jika setelah jam selesai sesi: · telat … (durasi).',
            'placeholders' => '{nama_libur}, {tanggal}, {jam_mulai}, {jam_selesai}, {jumlah_datang}, {daftar_datang}, {nama_ponpes}',
            'default' => "Laporan kedatangan *{nama_libur}*\n"
                . "Tanggal {tanggal} · jam {jam_mulai}–{jam_selesai}\n"
                . "Sudah datang: *{jumlah_datang}*\n\n"
                . "{daftar_datang}\n\n"
                . '_{nama_ponpes}_',
        ],
        'kedatangan_libur_belum_pengurus' => [
            'label' => 'Kedatangan libur — belum datang → pengurus',
            'hint' => 'Dikirim petugas dari tombol “Kirim yang belum datang”. Santri aktif tanpa scan. Baris: Nama (NIS) · tingkatan.',
            'placeholders' => '{nama_libur}, {tanggal}, {jam_mulai}, {jam_selesai}, {jumlah_belum}, {daftar_belum}, {nama_ponpes}',
            'default' => "Laporan belum datang *{nama_libur}*\n"
                . "Tanggal {tanggal} · jam {jam_mulai}–{jam_selesai}\n"
                . "Belum datang: *{jumlah_belum}*\n\n"
                . "{daftar_belum}\n\n"
                . '_{nama_ponpes}_',
        ],
    ];
}

function wa_template_setting_key(string $slug): string
{
    return 'wa_tpl_' . preg_replace('/[^a-z0-9_]/', '', strtolower($slug));
}

/** Slug template izin disetujui: sakit vs izin lainnya. */
function wa_template_slug_izin_disetujui(string $baseSlug, string $jenisRaw): string
{
    if (!function_exists('perizinan_jenis_izin_normalize')) {
        require_once __DIR__ . '/perizinan_jenis.php';
    }
    $suffix = perizinan_jenis_izin_normalize($jenisRaw) === 'SAKIT' ? '_sakit' : '_lainnya';

    return $baseSlug . $suffix;
}

function wa_template_get_izin_disetujui(PDO $pdo, string $baseSlug, string $jenisRaw): string
{
    $slug = wa_template_slug_izin_disetujui($baseSlug, $jenisRaw);
    $customNew = trim((string) app_setting($pdo, wa_template_setting_key($slug), ''));
    if ($customNew !== '') {
        return $customNew;
    }
    $customLegacy = trim((string) app_setting($pdo, wa_template_setting_key($baseSlug), ''));
    if ($customLegacy !== '') {
        return $customLegacy;
    }
    $defs = wa_template_definitions();

    return (string) ($defs[$slug]['default'] ?? '');
}

/**
 * @param array<string, string> $vars
 */
function wa_template_render_izin_disetujui(PDO $pdo, string $baseSlug, string $jenisRaw, array $vars): string
{
    $slug = wa_template_slug_izin_disetujui($baseSlug, $jenisRaw);
    $tpl = wa_template_get_izin_disetujui($pdo, $baseSlug, $jenisRaw);
    foreach ($vars as $key => $value) {
        $tpl = str_replace('{' . $key . '}', (string) $value, $tpl);
    }

    return $tpl;
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

/** Pindahkan template rapor dari pengaturan lama ke wa_tpl_* (sekali). */
function wa_template_migrate_rapor_legacy(PDO $pdo): void
{
    if (trim((string) app_setting($pdo, 'wa_tpl_rapor_migrated', '')) === '1') {
        return;
    }

    $pesantrenOld = trim((string) app_setting($pdo, 'surat_tpl_rapor_wa_pesan', ''));
    if ($pesantrenOld !== '' && trim((string) app_setting($pdo, wa_template_setting_key('rapor_terbit_pesantren'), '')) === '') {
        save_setting($pdo, wa_template_setting_key('rapor_terbit_pesantren'), $pesantrenOld);
    }

    $pkppsOld = trim((string) app_setting($pdo, 'pkpps_rapor_wa_pesan', ''));
    if ($pkppsOld !== '' && trim((string) app_setting($pdo, wa_template_setting_key('rapor_terbit_pkpps'), '')) === '') {
        save_setting($pdo, wa_template_setting_key('rapor_terbit_pkpps'), $pkppsOld);
    }

    if (trim((string) app_setting($pdo, 'wa_rapor_pesantren_enabled', '')) === '') {
        $legacy = trim((string) app_setting($pdo, 'akademik_rapor_wa_auto_pesantren', '1'));
        save_setting($pdo, 'wa_rapor_pesantren_enabled', $legacy !== '' ? $legacy : '1');
    }
    if (trim((string) app_setting($pdo, 'wa_rapor_pkpps_enabled', '')) === '') {
        $legacy = trim((string) app_setting($pdo, 'pkpps_rapor_wa_auto', '1'));
        save_setting($pdo, 'wa_rapor_pkpps_enabled', $legacy !== '' ? $legacy : '1');
    }

    save_setting($pdo, 'wa_tpl_rapor_migrated', '1');
    if (function_exists('app_settings_cache_reset')) {
        app_settings_cache_reset($pdo);
    }
}

function wa_rapor_auto_enabled(PDO $pdo, string $jenis): bool
{
    wa_template_migrate_rapor_legacy($pdo);
    $jenis = strtolower(trim($jenis)) === 'pkpps' ? 'pkpps' : 'pesantren';
    if ($jenis === 'pkpps') {
        $v = trim((string) app_setting($pdo, 'wa_rapor_pkpps_enabled', ''));
        if ($v === '') {
            $v = trim((string) app_setting($pdo, 'pkpps_rapor_wa_auto', '1'));
        }

        return $v === '1';
    }

    $v = trim((string) app_setting($pdo, 'wa_rapor_pesantren_enabled', ''));
    if ($v === '') {
        $v = trim((string) app_setting($pdo, 'akademik_rapor_wa_auto_pesantren', '1'));
    }

    return $v === '1';
}

function wa_rapor_template_slug(string $jenis): string
{
    return strtolower(trim($jenis)) === 'pkpps' ? 'rapor_terbit_pkpps' : 'rapor_terbit_pesantren';
}
