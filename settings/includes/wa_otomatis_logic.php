<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/wa_otomatis.php';
require_once __DIR__ . '/../../helpers/wa_tagihan.php';
require_once __DIR__ . '/../../helpers/wa_templates.php';
require_once __DIR__ . '/../../helpers/wa_pembimbing_scan.php';
require_once __DIR__ . '/../../helpers/alpa_tier.php';
require_once __DIR__ . '/../../helpers/poin_wa.php';
require_once __DIR__ . '/../../helpers/kalender_pengaturan.php';
require_once __DIR__ . '/../../helpers/push_fcm.php';

ensure_alpa_tier_tables($pdo);
ensure_poin_tier_tables($pdo);
pembimbing_ensure_wa_scan_reminder_column($pdo);

ensure_pondok_settings_defaults($pdo);
$pondokDefaults = pondok_settings_defaults();
$appNama = app_brand_nama_ponpes($pdo);
$pondokWaFields = [
        'wa_gateway_url', 'wa_gateway_token', 'wa_sender', 'wa_fonnte_queue_offline', 'wa_fonnte_api_delay', 'wa_dispatch_strict_mode',
        'wa_delay_tagihan', 'wa_delay_cashless', 'wa_delay_presensi', 'wa_delay_alpa', 'wa_delay_poin', 'wa_delay_izin', 'wa_delay_rapor',
        'wa_pengurus', 'wa_permohonan_izin', 'wa_permohonan_izin_enabled',
    'wa_petugas_pendidikan',
    'wa_notif_mudabir_enabled', 'mudabir_batas_menit', 'wa_kelas_kosong_enabled', 'wa_kelas_kosong_batas_menit', 'wa_kelas_kosong_batas_kali',
    'wa_kelas_kosong_target_1', 'wa_kelas_kosong_target_3', 'jam_kirim_wa_auto', 'wa_tagihan_auto_enabled',
    'wa_musyawarah_enabled', 'wa_musyawarah_target', 'wa_musyawarah_auto_selesai',
    'keterangan_pengurus_bidang_keuangan', 'batas_alpa_notif', 'batas_telat_menit',
];
$values = [];
foreach (array_merge($pondokWaFields, ['wa_kelas_kosong_last_sent_at', 'wa_kelas_kosong_last_level']) as $key) {
    $values[$key] = app_setting($pdo, $key, $pondokDefaults[$key] ?? '');
}
$values['wa_tagihan_auto_enabled'] = ($values['wa_tagihan_auto_enabled'] ?? '') === '1' ? '1' : '0';
$values['wa_notif_mudabir_enabled'] = ($values['wa_notif_mudabir_enabled'] ?? '') === '1' ? '1' : '0';
$values['wa_kelas_kosong_enabled'] = ($values['wa_kelas_kosong_enabled'] ?? '') === '1' ? '1' : '0';
$values['wa_fonnte_queue_offline'] = ($values['wa_fonnte_queue_offline'] ?? '') === '1' ? '1' : '0';
$values['wa_dispatch_strict_mode'] = ($values['wa_dispatch_strict_mode'] ?? '1') === '1' ? '1' : '0';
$pengurusWaCount = trim((string) ($values['wa_pengurus'] ?? '')) === ''
    ? 0
    : count(preg_split('/[\s,;]+/', (string) $values['wa_pengurus'], -1, PREG_SPLIT_NO_EMPTY) ?: []);
$values['wa_permohonan_izin_jenis'] = app_setting(
    $pdo,
    'wa_permohonan_izin_jenis',
    $pondokDefaults['wa_permohonan_izin_jenis'] ?? 'SYARI'
);
$waPermohonanIzinJenisAllowed = wa_permohonan_izin_jenis_allowed_list($pdo);
$permohonanIzinWaCount = trim((string) ($values['wa_permohonan_izin'] ?? '')) === ''
    ? 0
    : count(preg_split('/[\s,;]+/', (string) $values['wa_permohonan_izin'], -1, PREG_SPLIT_NO_EMPTY) ?: []);

/** @var array<string, mixed>|null $waTestResult */
$waTestResult = null;
$waActiveTab = 'ringkasan';

$waTabs = [
    'ringkasan' => ['label' => 'Ringkasan', 'icon' => 'fa-gauge-high', 'desc' => 'Status & cron'],
    'gateway' => ['label' => 'Gateway', 'icon' => 'fa-plug', 'desc' => 'Token & tes kirim'],
    'tagihan' => ['label' => 'Tagihan Wali', 'icon' => 'fa-hand-holding-dollar', 'desc' => 'Jadwal & kirim manual'],
    'cashless' => ['label' => 'Cashless', 'icon' => 'fa-wallet', 'desc' => 'Saku, transaksi & laporan'],
    'presensi' => ['label' => 'Presensi', 'icon' => 'fa-qrcode', 'desc' => 'Scan, munawib, kelas kosong'],
    'alpa' => ['label' => 'Alpa', 'icon' => 'fa-tower-broadcast', 'desc' => 'Tier penerima'],
    'poin' => ['label' => 'Poin', 'icon' => 'fa-scale-balanced', 'desc' => 'Ambang & jam kirim'],
    'izin' => ['label' => 'Izin', 'icon' => 'fa-person-walking', 'desc' => 'Permohonan baru & disetujui'],
    'template' => ['label' => 'Template', 'icon' => 'fa-message', 'desc' => 'Teks pesan'],
    'log' => ['label' => 'Riwayat', 'icon' => 'fa-clipboard-list', 'desc' => 'Log pengiriman'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $redirectTab = trim((string) ($_POST['redirect_tab'] ?? 'ringkasan'));
    if (!isset($waTabs[$redirectTab])) {
        $redirectTab = 'ringkasan';
    }
    $redirectUrl = app_href('/settings/wa_otomatis.php?tab=' . rawurlencode($redirectTab));

    if ($action === 'test_wa') {
        $testTarget = trim((string) ($_POST['wa_test_target'] ?? ''));
        $testMessage = trim((string) ($_POST['wa_test_message'] ?? ('Tes WA dari ' . $appNama . '.')));
        $override = [
            'endpoint' => trim((string) ($_POST['wa_gateway_url'] ?? app_setting($pdo, 'wa_gateway_url', ''))),
            'token' => trim((string) ($_POST['wa_gateway_token'] ?? app_setting($pdo, 'wa_gateway_token', ''))),
            'sender' => trim((string) ($_POST['wa_sender'] ?? app_setting($pdo, 'wa_sender', ''))),
            'skip_dedup' => true,
        ];
        $waTestResult = send_wa_message_with_result($pdo, $testTarget, $testMessage, $override);
        $waActiveTab = 'gateway';
    } elseif ($action === 'save_gateway') {
        foreach (['wa_gateway_url', 'wa_gateway_token', 'wa_sender'] as $field) {
            if (array_key_exists($field, $_POST)) {
                save_setting($pdo, $field, trim((string) $_POST[$field]));
            }
        }
        save_setting($pdo, 'wa_otomatis_master_enabled', isset($_POST['wa_otomatis_master_enabled']) ? '1' : '0');
        save_setting($pdo, 'wa_fonnte_queue_offline', isset($_POST['wa_fonnte_queue_offline']) ? '1' : '0');
        $delayRaw = trim((string) ($_POST['wa_fonnte_api_delay'] ?? ''));
        if ($delayRaw === '' || $delayRaw === '0') {
            save_setting($pdo, 'wa_fonnte_api_delay', '');
            $delayInvalid = false;
        } else {
            $delayValidated = wa_otomatis_fonnte_api_delay($pdo, ['fonnte_delay' => $delayRaw]);
            save_setting($pdo, 'wa_fonnte_api_delay', $delayValidated);
            $delayInvalid = $delayValidated === '';
        }
        $mode = strtolower(trim((string) ($_POST['fcm_notify_mode'] ?? 'both')));
        if (!in_array($mode, ['push', 'wa', 'both'], true)) {
            $mode = 'both';
        }
        save_setting($pdo, 'fcm_notify_mode', $mode);
        $cronKey = trim((string) ($_POST['wa_auto_cron_key'] ?? ''));
        save_setting($pdo, 'wa_auto_cron_key', $cronKey);
        save_setting($pdo, 'wa_dispatch_strict_mode', isset($_POST['wa_dispatch_strict_mode']) ? '1' : '0');
        set_flash('success', $delayInvalid ?? false
            ? 'Pengaturan gateway disimpan. Format delay Fonnte tidak valid — gunakan contoh 3 atau 3-8 (delay dinonaktifkan).'
            : 'Pengaturan gateway disimpan.');
        header('Location: ' . $redirectUrl);
        exit;
    } elseif ($action === 'save_wa_delay') {
        $kind = trim((string) ($_POST['delay_kind'] ?? ''));
        $kinds = wa_otomatis_delay_kinds();
        if (!isset($kinds[$kind])) {
            set_flash('error', 'Kategori delay tidak valid.');
        } else {
            $invalid = wa_otomatis_save_delay_from_post($pdo, $kind);
            set_flash($invalid ? 'warning' : 'success', $invalid
                ? 'Format delay tidak valid — gunakan contoh 3 atau 3-8 (delay dinonaktifkan).'
                : 'Pengaturan delay disimpan.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    } elseif ($action === 'save_penerima') {
        foreach (['wa_petugas_pendidikan', 'keterangan_pengurus_bidang_keuangan', 'batas_telat_menit'] as $field) {
            if (array_key_exists($field, $_POST)) {
                save_setting($pdo, $field, trim((string) $_POST[$field]));
            }
        }
        set_flash('success', 'Pengaturan presensi & petugas pendidikan disimpan.');
        header('Location: ' . $redirectUrl);
        exit;
    } elseif ($action === 'save_alpa_penerima') {
        foreach (['wa_pengurus', 'jam_kirim_wa_auto', 'batas_alpa_notif'] as $field) {
            if (array_key_exists($field, $_POST)) {
                save_setting($pdo, $field, trim((string) $_POST[$field]));
            }
        }
        wa_otomatis_save_delay_from_post($pdo, 'alpa');
        set_flash('success', 'Penerima notifikasi alpa disimpan.');
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=alpa'));
        exit;
    } elseif ($action === 'save_presensi') {
        foreach (['wa_notif_mudabir_enabled', 'mudabir_batas_menit', 'wa_kelas_kosong_enabled', 'wa_kelas_kosong_batas_menit', 'wa_kelas_kosong_batas_kali', 'wa_kelas_kosong_target_1', 'wa_kelas_kosong_target_3', 'wa_musyawarah_target'] as $field) {
            if (array_key_exists($field, $_POST)) {
                if ($field === 'wa_kelas_kosong_batas_kali') {
                    save_setting($pdo, $field, (string) max(2, min(10, (int) $_POST[$field])));
                } else {
                    save_setting($pdo, $field, trim((string) $_POST[$field]));
                }
            }
        }
        save_setting($pdo, 'wa_pembimbing_scan_enabled', isset($_POST['wa_pembimbing_scan_enabled']) ? '1' : '0');
        save_setting($pdo, 'wa_pembimbing_scan_menit_sebelum', (string) max(5, min(30, (int) ($_POST['wa_pembimbing_scan_menit_sebelum'] ?? 10))));
        save_setting($pdo, 'wa_musyawarah_enabled', isset($_POST['wa_musyawarah_enabled']) ? '1' : '0');
        save_setting($pdo, 'wa_musyawarah_auto_selesai', isset($_POST['wa_musyawarah_auto_selesai']) ? '1' : '0');
        save_setting($pdo, 'wa_yayasan_tugas_enabled', isset($_POST['wa_yayasan_tugas_enabled']) ? '1' : '0');
        save_setting($pdo, 'wa_yayasan_tugas_noprogress_enabled', isset($_POST['wa_yayasan_tugas_noprogress_enabled']) ? '1' : '0');
        save_setting($pdo, 'wa_yayasan_tugas_noprogress_jam', (string) max(1, min(72, (int) ($_POST['wa_yayasan_tugas_noprogress_jam'] ?? 6))));
        wa_otomatis_save_delay_from_post($pdo, 'presensi');
        set_flash('success', 'Pengaturan presensi & kelas kosong disimpan.');
        header('Location: ' . $redirectUrl);
        exit;
    } elseif ($action === 'save_tagihan_jadwal') {
        wa_otomatis_save_delay_from_post($pdo, 'tagihan');
        $res = wa_tagihan_jadwal_simpan($pdo, $_POST);
        set_flash($res['ok'] ? 'success' : 'error', (string) ($res['message'] ?? ''));
        header('Location: ' . $redirectUrl);
        exit;
    } elseif ($action === 'save_pembayaran_wali_wa') {
        save_setting($pdo, 'wa_pembayaran_wali_enabled', isset($_POST['wa_pembayaran_wali_enabled']) ? '1' : '0');
        wa_otomatis_save_delay_from_post($pdo, 'tagihan');
        set_flash('success', 'Pengaturan WA pembayaran ke wali disimpan.');
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=tagihan'));
        exit;
    } elseif ($action === 'jalankan_wa_tagihan') {
        $bulanPaksa = max(0, (int) ($_POST['bulan_tagihan'] ?? 0));
        $res = wa_tagihan_jalankan_kirim($pdo, true, $bulanPaksa > 0 ? $bulanPaksa : null);
        set_flash($res['ok'] ? 'success' : 'warning', (string) ($res['message'] ?? ''));
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=tagihan'));
        exit;
    } elseif ($action === 'save_wa_templates') {
        $res = wa_template_save_all($pdo, $_POST);
        save_setting($pdo, 'wa_rapor_pesantren_enabled', isset($_POST['wa_rapor_pesantren_enabled']) ? '1' : '0');
        save_setting($pdo, 'wa_rapor_pkpps_enabled', isset($_POST['wa_rapor_pkpps_enabled']) ? '1' : '0');
        wa_otomatis_save_delay_from_post($pdo, 'rapor');
        if (function_exists('app_settings_cache_reset')) {
            app_settings_cache_reset($pdo);
        }
        set_flash($res['ok'] ? 'success' : 'error', (string) ($res['message'] ?? ''));
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=template'));
        exit;
    } elseif ($action === 'save_permohonan_izin_wa') {
        save_setting($pdo, 'wa_permohonan_izin_enabled', isset($_POST['wa_permohonan_izin_enabled']) ? '1' : '0');
        if (array_key_exists('wa_permohonan_izin', $_POST)) {
            save_setting($pdo, 'wa_permohonan_izin', trim((string) $_POST['wa_permohonan_izin']));
        }
        require_once __DIR__ . '/../../helpers/perizinan_jenis.php';
        $jenisSelected = [];
        foreach ((array) ($_POST['wa_permohonan_izin_jenis'] ?? []) as $jenisRaw) {
            $kode = perizinan_jenis_izin_normalize((string) $jenisRaw);
            if (in_array($kode, perizinan_jenis_izin_kodes(), true)) {
                $jenisSelected[$kode] = $kode;
            }
        }
        save_setting($pdo, 'wa_permohonan_izin_jenis', implode(',', array_values($jenisSelected)));
        if (function_exists('app_settings_cache_reset')) {
            app_settings_cache_reset($pdo);
        }
        set_flash('success', 'Pengaturan WA permohonan izin disimpan.');
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=izin'));
        exit;
    } elseif ($action === 'save_izin_grup_wa') {
        save_setting($pdo, 'wa_izin_grup_fonte_enabled', isset($_POST['wa_izin_grup_fonte_enabled']) ? '1' : '0');
        save_setting($pdo, 'wa_izin_grup_fonte', trim((string) ($_POST['wa_izin_grup_fonte'] ?? '')));
        set_flash('success', 'Pengaturan grup WA izin disimpan.');
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=izin'));
        exit;
    } elseif ($action === 'save_izin_wa') {
        save_setting($pdo, 'wa_izin_pembimbing_enabled', isset($_POST['wa_izin_pembimbing_enabled']) ? '1' : '0');
        save_setting($pdo, 'wa_izin_pembimbing_kirim_grup', isset($_POST['wa_izin_pembimbing_kirim_grup']) ? '1' : '0');
        save_setting($pdo, 'wa_izin_pembimbing_grup', trim((string) ($_POST['wa_izin_pembimbing_grup'] ?? '')));
        set_flash('success', 'Pengaturan WA pembimbing izin disimpan.');
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=izin'));
        exit;
    } elseif ($action === 'save_izin_pengurus_wa') {
        save_setting($pdo, 'wa_izin_pengurus_enabled', isset($_POST['wa_izin_pengurus_enabled']) ? '1' : '0');
        save_setting($pdo, 'wa_izin_selesai_enabled', isset($_POST['wa_izin_selesai_enabled']) ? '1' : '0');
        if (array_key_exists('wa_izin_pengurus', $_POST)) {
            save_setting($pdo, 'wa_izin_pengurus', trim((string) $_POST['wa_izin_pengurus']));
        }
        if (array_key_exists('wa_izin_pengurus_putra', $_POST)) {
            save_setting($pdo, 'wa_izin_pengurus_putra', trim((string) $_POST['wa_izin_pengurus_putra']));
        }
        if (array_key_exists('wa_izin_pengurus_putri', $_POST)) {
            save_setting($pdo, 'wa_izin_pengurus_putri', trim((string) $_POST['wa_izin_pengurus_putri']));
        }
        set_flash('success', 'Pengaturan WA pengurus izin disimpan.');
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=izin'));
        exit;
    } elseif ($action === 'save_izin_wali_wa') {
        save_setting($pdo, 'wa_izin_wali_enabled', isset($_POST['wa_izin_wali_enabled']) ? '1' : '0');
        set_flash('success', 'Pengaturan WA wali izin disimpan.');
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=izin'));
        exit;
    } elseif ($action === 'save_cashless_saldo_wa' || $action === 'save_cashless_wa_settings') {
        save_setting($pdo, 'cashless_saldo_rendah_wa_enabled', isset($_POST['cashless_saldo_rendah_wa_enabled']) ? '1' : '0');
        save_setting($pdo, 'cashless_saldo_rendah_wa_ambang', (string) max(0, (int) ($_POST['cashless_saldo_rendah_wa_ambang'] ?? 30000)));
        save_setting($pdo, 'cashless_transaksi_wa_enabled', isset($_POST['cashless_transaksi_wa_enabled']) ? '1' : '0');
        save_setting($pdo, 'cashless_laporan_harian_wa_enabled', isset($_POST['cashless_laporan_harian_wa_enabled']) ? '1' : '0');
        $jamRaw = trim((string) ($_POST['cashless_laporan_harian_wa_jam'] ?? '20:00'));
        if (preg_match('/^(\d{1,2}):(\d{2})/', $jamRaw, $jm)) {
            $jamRaw = sprintf('%02d:%02d', (int) $jm[1], (int) $jm[2]);
        }
        save_setting($pdo, 'cashless_laporan_harian_wa_jam', $jamRaw);
        save_setting($pdo, 'cashless_laporan_harian_wa_targets', trim((string) ($_POST['cashless_laporan_harian_wa_targets'] ?? '')));
        wa_otomatis_save_delay_from_post($pdo, 'cashless');
        set_flash('success', 'Pengaturan WA cashless disimpan.');
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=cashless'));
        exit;
    } elseif ($action === 'jalankan_cashless_laporan_harian') {
        require_once __DIR__ . '/../../helpers/cashless_wa.php';
        $res = cashless_wa_jalankan_laporan_harian($pdo, true);
        set_flash($res['ok'] ? 'success' : 'warning', (string) ($res['message'] ?? ''));
        $cashlessRedirect = trim((string) ($_POST['redirect_tab'] ?? 'cashless'));
        if (!isset($waTabs[$cashlessRedirect])) {
            $cashlessRedirect = 'cashless';
        }
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=' . rawurlencode($cashlessRedirect)));
        exit;
    } elseif ($action === 'save_awal_tahun_wa') {
        save_setting($pdo, 'wa_awal_tahun_auto_enabled', isset($_POST['wa_awal_tahun_auto_enabled']) ? '1' : '0');
        $jamRaw = trim((string) ($_POST['wa_awal_tahun_send_time'] ?? '09:00'));
        if (preg_match('/^(\d{1,2}):(\d{2})/', $jamRaw, $jm)) {
            $jamRaw = sprintf('%02d:%02d', (int) $jm[1], (int) $jm[2]);
        }
        save_setting($pdo, 'wa_awal_tahun_send_time', $jamRaw);
        wa_otomatis_save_delay_from_post($pdo, 'tagihan');
        set_flash('success', 'Pengaturan WA pengingat awal tahun disimpan.');
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=tagihan'));
        exit;
    } elseif ($action === 'jalankan_wa_awal_tahun') {
        require_once __DIR__ . '/../../helpers/wa_awal_tahun.php';
        $res = wa_awal_tahun_jalankan_kirim($pdo, true);
        set_flash($res['ok'] ? 'success' : 'warning', (string) ($res['message'] ?? ''));
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=tagihan'));
        exit;
    } elseif ($action === 'save_periode') {
        $mode = strtolower(trim((string) ($_POST['periode_mode'] ?? 'monthly')));
        if (!in_array($mode, ['weekly', 'monthly', 'default'], true)) {
            $mode = 'monthly';
        }
        save_setting($pdo, 'alpa_notif_periode_mode', $mode);
        set_flash('success', 'Periode notifikasi alpa disimpan.');
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=alpa'));
        exit;
    } elseif ($action === 'save_tier') {
        $id = (int) ($_POST['id'] ?? 0);
        $threshold = max(1, (int) ($_POST['threshold'] ?? 0));
        $label = trim((string) ($_POST['label'] ?? ''));
        $wa = trim((string) ($_POST['wa'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($threshold < 1) {
            set_flash('error', 'Ambang harus angka ≥ 1.');
        } elseif ($wa === '' && $isActive === 1) {
            set_flash('error', 'Nomor WA penerima wajib diisi untuk tier aktif.');
        } elseif ($id > 0) {
            $st = $pdo->prepare('UPDATE alpa_tier_notif SET threshold = :t, label = :l, wa = :w, is_active = :a WHERE id = :id');
            $st->execute(['t' => $threshold, 'l' => $label, 'w' => $wa, 'a' => $isActive, 'id' => $id]);
            set_flash('success', 'Tier diperbarui.');
        } else {
            $st = $pdo->prepare('INSERT INTO alpa_tier_notif (threshold, label, wa, is_active) VALUES (:t, :l, :w, :a)');
            $st->execute(['t' => $threshold, 'l' => $label, 'w' => $wa, 'a' => $isActive]);
            set_flash('success', 'Tier baru ditambahkan.');
        }
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=alpa'));
        exit;
    } elseif ($action === 'delete_tier') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM alpa_tier_notif WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Tier dihapus.');
        }
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=alpa'));
        exit;
    } elseif ($action === 'reset_log') {
        $pdo->exec('TRUNCATE TABLE alpa_tier_dispatch_log');
        set_flash('success', 'Log dispatch alpa direset.');
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=alpa'));
        exit;
    } elseif ($action === 'save_poin_wa_enabled') {
        save_setting($pdo, 'poin_wa_notif_enabled', isset($_POST['poin_wa_notif_enabled']) ? '1' : '0');
        wa_otomatis_save_delay_from_post($pdo, 'poin');
        set_flash('success', 'Status WA ambang poin disimpan.');
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=poin'));
        exit;
    } elseif ($action === 'save_poin_tier') {
        ensure_poin_tier_tables($pdo);
        $id = (int) ($_POST['id'] ?? 0);
        $threshold = max(1, (int) ($_POST['threshold'] ?? 0));
        $label = trim((string) ($_POST['label'] ?? ''));
        $wa = trim((string) ($_POST['wa'] ?? ''));
        $jam = poin_tier_normalize_jam((string) ($_POST['jam_kirim'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($threshold < 1) {
            set_flash('error', 'Ambang harus angka ≥ 1.');
        } elseif ($id > 0) {
            $st = $pdo->prepare('
                UPDATE poin_tier_notif
                SET threshold = :t, label = :l, wa = :w, jam_kirim = :j, is_active = :a
                WHERE id = :id
            ');
            $st->execute(['t' => $threshold, 'l' => $label, 'w' => $wa, 'j' => $jam, 'a' => $isActive, 'id' => $id]);
            set_flash('success', 'Ambang poin diperbarui.');
        } else {
            $st = $pdo->prepare('
                INSERT INTO poin_tier_notif (threshold, label, wa, jam_kirim, is_active)
                VALUES (:t, :l, :w, :j, :a)
            ');
            $st->execute(['t' => $threshold, 'l' => $label, 'w' => $wa, 'j' => $jam, 'a' => $isActive]);
            set_flash('success', 'Ambang poin ditambahkan.');
        }
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=poin'));
        exit;
    } elseif ($action === 'delete_poin_tier') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM poin_tier_notif WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Ambang poin dihapus.');
        }
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=poin'));
        exit;
    } elseif ($action === 'reset_poin_tier_log') {
        if (table_exists($pdo, 'poin_tier_dispatch_log')) {
            $pdo->exec('TRUNCATE TABLE poin_tier_dispatch_log');
        }
        set_flash('success', 'Log kirim ambang poin direset.');
        header('Location: ' . app_href('/settings/wa_otomatis.php?tab=poin'));
        exit;
    }
}

$tabReq = trim((string) ($_GET['tab'] ?? ''));
if ($tabReq !== '' && isset($waTabs[$tabReq])) {
    $waActiveTab = $tabReq;
}

// Data ringkasan
$waJadwal = wa_tagihan_jadwal_context($pdo);
$waCronLastRun = trim((string) app_setting($pdo, 'wa_auto_last_run_at', ''));
$waLastHeavy = trim((string) app_setting($pdo, 'wa_auto_last_heavy_at', ''));
$waScheduledLastAt = trim((string) app_setting($pdo, 'wa_auto_scheduled_last_at', ''));
$waScheduledLastRaw = trim((string) app_setting($pdo, 'wa_auto_scheduled_last_result', ''));
$waScheduledLast = $waScheduledLastRaw !== '' ? json_decode($waScheduledLastRaw, true) : null;
if (!is_array($waScheduledLast)) {
    $waScheduledLast = null;
}
$waAlpaLastRaw = trim((string) app_setting($pdo, 'wa_auto_alpa_last_result', ''));
$waAlpaLast = $waAlpaLastRaw !== '' ? json_decode($waAlpaLastRaw, true) : null;
if (!is_array($waAlpaLast)) {
    $waAlpaLast = null;
}
$waTagihanLastRun = trim((string) app_setting($pdo, 'wa_tagihan_last_run_at', ''));
$waCronKey = trim((string) app_setting($pdo, 'wa_auto_cron_key', ''));
$cronUrl = app_href('/cron/wa_auto.php') . ($waCronKey !== '' ? ('?key=' . rawurlencode($waCronKey)) : '');
$waLastStatsRaw = trim((string) app_setting($pdo, 'wa_tagihan_last_run_stats', ''));
$waLastStats = $waLastStatsRaw !== '' ? json_decode($waLastStatsRaw, true) : null;
if (!is_array($waLastStats)) {
    $waLastStats = null;
}
$waGatewayErr = wa_otomatis_gateway_error($pdo);
$waGatewayLastErr = trim((string) app_setting($pdo, 'wa_auto_last_gateway_error', ''));
$waPartialFailRaw = trim((string) app_setting($pdo, 'wa_tagihan_last_partial_fail_stats', ''));
$waPartialFail = $waPartialFailRaw !== '' ? json_decode($waPartialFailRaw, true) : null;
if (!is_array($waPartialFail)) {
    $waPartialFail = null;
}
$waMasterOn = trim((string) app_setting($pdo, 'wa_otomatis_master_enabled', '1')) === '1';
$notifyMode = push_notify_mode($pdo);
$kalenderV = kalender_pengaturan_load($pdo);

// Template
wa_template_migrate_rapor_legacy($pdo);
$tplDefs = wa_template_definitions();
$tplValues = [];
foreach ($tplDefs as $slug => $meta) {
    $tplValues[$slug] = wa_template_get($pdo, $slug);
}
$waRaporPesantrenOn = wa_rapor_auto_enabled($pdo, 'pesantren');
$waRaporPkppsOn = wa_rapor_auto_enabled($pdo, 'pkpps');

// Alpa
$periodeMode = alpa_tier_periode_mode($pdo);
$tanggalMulaiAlpa = alpa_tier_tanggal_mulai($pdo);
$tiers = alpa_tier_list($pdo, false);
$logTotalAlpa = (int) ($pdo->query('SELECT COUNT(*) FROM alpa_tier_dispatch_log')->fetchColumn() ?: 0);
$alpaModeLabel = match ($periodeMode) {
    'weekly' => 'Mingguan',
    'default' => 'Akumulatif',
    default => 'Bulanan',
};

$poinWaEnabled = poin_wa_notif_enabled($pdo);
$poinTiers = poin_tier_list($pdo, false);
$poinLogTotal = table_exists($pdo, 'poin_tier_dispatch_log')
    ? (int) ($pdo->query('SELECT COUNT(*) FROM poin_tier_dispatch_log')->fetchColumn() ?: 0)
    : 0;
$poinWaLastCronAt = trim((string) app_setting($pdo, 'poin_wa_last_cron_at', ''));
$poinWaLastCronStats = json_decode((string) app_setting($pdo, 'poin_wa_last_cron_stats', ''), true);
if (!is_array($poinWaLastCronStats)) {
    $poinWaLastCronStats = null;
}

// Izin WA
$waIzinEnabled = trim((string) app_setting($pdo, 'wa_izin_pembimbing_enabled', '1')) === '1';
$waIzinGrup = trim((string) app_setting($pdo, 'wa_izin_pembimbing_grup', ''));
$waIzinKirimGrup = trim((string) app_setting($pdo, 'wa_izin_pembimbing_kirim_grup', '0')) === '1';
$waIzinGrupFonteEnabled = trim((string) app_setting($pdo, 'wa_izin_grup_fonte_enabled', '1')) !== '0';
$waIzinGrupFonte = trim((string) app_setting($pdo, 'wa_izin_grup_fonte', ''));
if ($waIzinGrupFonte === '') {
    $waIzinGrupFonte = trim((string) app_setting($pdo, 'wa_izin_pembimbing_grup', ''));
}
$waIzinGrupAktifOtomatis = $waIzinGrupFonte !== '' && $waIzinGrupFonteEnabled;
$waIzinPengurusEnabled = trim((string) app_setting($pdo, 'wa_izin_pengurus_enabled', '1')) === '1';
$waIzinSelesaiEnabled = trim((string) app_setting($pdo, 'wa_izin_selesai_enabled', '1')) === '1';
$waIzinWaliEnabled = trim((string) app_setting($pdo, 'wa_izin_wali_enabled', '1')) === '1';
$waIzinPengurus = trim((string) app_setting($pdo, 'wa_izin_pengurus', ''));
$waIzinPengurusPutra = trim((string) app_setting($pdo, 'wa_izin_pengurus_putra', ''));
$waIzinPengurusPutri = trim((string) app_setting($pdo, 'wa_izin_pengurus_putri', ''));
$waPembayaranWaliEnabled = trim((string) app_setting($pdo, 'wa_pembayaran_wali_enabled', '1')) === '1';
$waAwalTahunEnabled = trim((string) app_setting($pdo, 'wa_awal_tahun_auto_enabled', '0')) === '1';
$waAwalTahunJam = trim((string) app_setting($pdo, 'wa_awal_tahun_send_time', '09:00'));
$waAwalTahunLastAt = trim((string) app_setting($pdo, 'wa_awal_tahun_last_sent_at', ''));
$waAwalTahunLastStats = json_decode((string) app_setting($pdo, 'wa_awal_tahun_last_stats', ''), true);
if (!is_array($waAwalTahunLastStats)) {
    $waAwalTahunLastStats = null;
}
$cashlessSaldoRendahWaEnabled = trim((string) app_setting($pdo, 'cashless_saldo_rendah_wa_enabled', '1')) === '1';
$cashlessSaldoRendahWaAmbang = max(0, (int) app_setting($pdo, 'cashless_saldo_rendah_wa_ambang', '30000'));
require_once __DIR__ . '/../../helpers/cashless_wa.php';
$cashlessTransaksiWaEnabled = cashless_wa_transaksi_sukses_enabled($pdo);
$cashlessLaporanHarianWaEnabled = cashless_wa_laporan_harian_enabled($pdo);
$cashlessLaporanHarianWaJam = cashless_wa_laporan_harian_jam($pdo);
$cashlessLaporanHarianWaTargets = trim((string) app_setting($pdo, 'cashless_laporan_harian_wa_targets', ''));
$cashlessLaporanStatus = cashless_wa_laporan_status_hari_ini($pdo);

// Presensi
$scanEnabled = trim((string) app_setting($pdo, 'wa_pembimbing_scan_enabled', '1')) === '1';
$scanMenit = max(5, min(30, (int) app_setting($pdo, 'wa_pembimbing_scan_menit_sebelum', '10')));
$ytTugasWaEnabled = trim((string) app_setting($pdo, 'wa_yayasan_tugas_enabled', '1')) === '1';
$ytTugasNoProgressEnabled = trim((string) app_setting($pdo, 'wa_yayasan_tugas_noprogress_enabled', '1')) === '1';
$ytTugasNoProgressJam = max(1, min(72, (int) app_setting($pdo, 'wa_yayasan_tugas_noprogress_jam', '6')));

// Log terbaru
$waLogRecent = [];
if (table_exists($pdo, 'wa_logs')) {
    $stLog = $pdo->query('SELECT id, target_phone, LEFT(message, 80) AS message_short, is_success, created_at FROM wa_logs ORDER BY id DESC LIMIT 30');
    $waLogRecent = $stLog ? ($stLog->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}
$waDispatchRecent = function_exists('wa_dispatch_recent_rows') ? wa_dispatch_recent_rows($pdo, 30) : [];
