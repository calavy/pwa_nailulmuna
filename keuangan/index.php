<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_dashboard.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_riwayat_pembayaran.php';

require_login();
require_roles(['admin', 'pengurus']);

/** Redirect tab lama ke halaman yang masih ada (tanpa rebuild modul besar). */
$tabRedirects = [
    'a' => '/keuangan/pengaturan.php',
    'b' => '/keuangan/pembayaran.php',
    'c' => '/pembayaran/laporan.php',
    'd' => '/keuangan/pengaturan.php?bagian=tarif',
    'e' => '/keuangan/pengeluaran.php',
    'f' => '/pembayaran/riwayat.php',
    'g' => '/keuangan/cashless_scan.php',
    'h' => '/admin/cek_update.php',
    'j' => '/keuangan/inventaris.php',
    'k' => '/keuangan/gaji_pembimbing.php',
];

$tab = trim((string) ($_GET['tab'] ?? 'dashboard'));
if ($tab !== 'dashboard' && $tab !== 'i' && isset($tabRedirects[$tab])) {
    $target = $tabRedirects[$tab];
    $extra = $_GET;
    unset($extra['tab']);
    if ($extra !== []) {
        $target .= (str_contains($target, '?') ? '&' : '?') . http_build_query($extra);
    }
    app_redirect_path($target);
}

$formatRupiah = static fn(int $n): string => keuangan_format_rupiah($n);
$needsImport = !table_exists($pdo, 'santri') || !table_exists($pdo, 'keuangan_pembayaran');

$neracaRingkas = null;
$lakRingkas = null;
$dashSnap = null;
if (!$needsImport) {
    if (!empty($_GET['refresh'])) {
        keuangan_dashboard_cache_invalidate();
    }
    keuangan_preload_session_caches($pdo);
    $dashSnap = keuangan_dashboard_snapshot_cached($pdo);
}

$pageTitle = 'Keuangan';
$bodyClass = keuangan_body_class('keuangan-hub-page');
require_once __DIR__ . '/../includes/header.php';
?>

<?php
$periodeHeader = '';
if ($dashSnap !== null) {
    $tagHdr = $dashSnap['tagihan_bulan'];
    $periodeHeader = 'TA ' . htmlspecialchars((string) $tagHdr['ta_label'])
        . ' · tagihan ' . htmlspecialchars((string) $tagHdr['bulan_label']);
}
$keuUiHeader = [
    'kicker' => 'Modul Keuangan',
    'title' => 'Dashboard Keuangan',
    'subtitle' => 'Ringkasan kas operasional dan tagihan bulan berjalan.'
        . ($periodeHeader !== '' ? ' <span class="text-nowrap">' . $periodeHeader . '</span>' : ''),
    'actions' => ($dashSnap !== null && !$needsImport)
        ? '<a href="' . htmlspecialchars(app_href('/keuangan/index.php?refresh=1')) . '" class="btn btn-sm btn-outline-secondary" title="Muat ulang angka dari cache server">'
            . '<i class="fa-solid fa-rotate me-1" aria-hidden="true"></i> Perbarui angka</a>'
        : '',
];
require __DIR__ . '/partials/ui_page_header.php';
?>

<?php if ($dashSnap !== null && !$needsImport): ?>
<nav class="keu-quick-links mb-4" aria-label="Aksi cepat keuangan">
    <a class="btn btn-sm btn-success" href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php')) ?>">
        <i class="fa-solid fa-circle-plus me-1" aria-hidden="true"></i> Input pembayaran
    </a>
    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/keuangan/rekap-kas-bulan.php')) ?>">
        <i class="fa-solid fa-table-list me-1" aria-hidden="true"></i> Rekap kas
    </a>
    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/pembayaran/tagihan_syahriyah.php')) ?>">
        <i class="fa-solid fa-receipt me-1" aria-hidden="true"></i> Tagihan syahriyah
    </a>
</nav>
<?php endif; ?>

<?php if ($needsImport): ?>
<div class="alert alert-info border-info mb-4">
    <h2 class="h6 alert-heading mb-2"><i class="fa-solid fa-database me-1"></i> Pertama kali / database kosong?</h2>
    <p class="mb-2 small">Import <strong>sekali saja</strong> lewat phpMyAdmin (tidak perlu upload file PHP):</p>
    <ol class="small mb-2 ps-3">
        <li>Buka <a href="http://localhost/phpmyadmin" target="_blank" rel="noopener">phpMyAdmin</a></li>
        <li>Pilih database <code>pwa_nailulmuna</code> (atau buat baru)</li>
        <li>Tab <strong>Impor</strong> â†’ pilih file <code>impor_lengkap_pwa_nailulmuna.sql</code> di folder proyek</li>
        <li>Klik <strong>Kirim / Go</strong>, lalu refresh halaman ini</li>
    </ol>
    <p class="small text-muted mb-0">File ada di: <code>c:\xampp\htdocs\pwa_nailulmuna\impor_lengkap_pwa_nailulmuna.sql</code></p>
</div>
<?php endif; ?>

<?php if ($dashSnap !== null):
    $ner = $dashSnap['neraca'];
    $rekapKas = $dashSnap['rekap_kas'] ?? [];
    $tag = $dashSnap['tagihan_bulan'];
    $wa = $dashSnap['wa_tagihan'];
    $kasBank = $dashSnap['kas_bank'] ?? ['total' => 0, 'total_kas' => 0, 'total_bank' => 0, 'akun' => [], 'as_of_label' => ''];
    $mutasiHariIni = $dashSnap['mutasi_hari_ini'] ?? ['masuk' => 0, 'keluar' => 0, 'bersih' => 0];
    $kesehatanNeraca = $dashSnap['kesehatan_neraca'] ?? [];
    $transaksiTanpaAkun = (int) ($dashSnap['transaksi_tanpa_akun'] ?? 0);
    $seimbang = !empty($ner['seimbang']);
    $neracaSehat = !empty($ner['sehat']);
    $selisihNeraca = abs((int) ($ner['selisih'] ?? 0));
    $selisihRekapKas = (int) ($rekapKas['selisih_saldo'] ?? 0);
    $tagihanUrl = '/pembayaran/tagihan_syahriyah.php?bulan=' . (int) $tag['bulan'];
?>
<section class="keu-dash-snapshot mb-4" aria-label="Kondisi keuangan terkini">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <div>
            <h2 class="h5 mb-1">Kondisi terkini</h2>
            <p class="small text-muted mb-0">
                Neraca per <?= htmlspecialchars((string) $ner['as_of_label']) ?>
                <?php if (!empty($dashSnap['kas_saldo_mode_label'])): ?>
                    · Saldo kas: <span class="badge text-bg-light border"><?= htmlspecialchars((string) $dashSnap['kas_saldo_mode_label']) ?></span>
                <?php endif; ?>
                <span class="text-muted">· Angka dari snapshot server (bukan real-time).</span>
            </p>
        </div>
        <a href="<?= htmlspecialchars($tagihanUrl) ?>" class="btn btn-sm btn-outline-primary">
            <i class="fa-solid fa-receipt me-1" aria-hidden="true"></i> Detail tagihan
        </a>
    </div>

    <?php
    $hintKas = 'Per ' . (string) ($kasBank['as_of_label'] ?? '') . ' · kas ' . $formatRupiah((int) ($kasBank['total_kas'] ?? 0)) . ' + bank ' . $formatRupiah((int) ($kasBank['total_bank'] ?? 0));
    $hintTag = (string) $tag['bulan_label'] . ' TA ' . (string) $tag['ta_label'];
    ?>
    <p class="keu-section-kicker mb-2">Prioritas — kas &amp; tagihan bulan berjalan</p>
    <div class="row g-3 mb-3 keu-dash-kpi-primary">
        <div class="col-6 col-lg">
            <?php
            $keuUiKpi = [
                'label' => 'Likuid operasional (kas + bank)',
                'value' => htmlspecialchars($formatRupiah((int) ($kasBank['total'] ?? 0))),
                'hint' => $hintKas,
                'variant' => 'kas',
                'icon' => 'fa-wallet',
                'value_class' => 'text-success',
            ];
            require __DIR__ . '/partials/ui_kpi_card.php';
            ?>
        </div>
        <div class="col-6 col-lg">
            <?php
            $keuUiKpi = [
                'label' => 'Total tagihan (wajib)',
                'value' => htmlspecialchars($formatRupiah((int) $tag['total_tagihan'])),
                'hint' => $hintTag . ' · status tagihan',
                'variant' => 'tagihan',
                'icon' => 'fa-file-invoice-dollar',
            ];
            require __DIR__ . '/partials/ui_kpi_card.php';
            ?>
        </div>
        <div class="col-6 col-lg">
            <?php
            $keuUiKpi = [
                'label' => 'Terbayar bulan ini',
                'value' => htmlspecialchars($formatRupiah((int) ($tag['total_terbayar'] ?? 0))),
                'hint' => 'Valid terhadap tagihan · bukan seluruh kas masuk',
                'variant' => 'ok',
                'icon' => 'fa-circle-check',
                'value_class' => 'text-success',
            ];
            require __DIR__ . '/partials/ui_kpi_card.php';
            ?>
        </div>
        <div class="col-6 col-lg">
            <?php
            $keuUiKpi = [
                'label' => 'Sisa tagihan (piutang)',
                'value' => htmlspecialchars($formatRupiah((int) $tag['total_piutang'])),
                'hint' => (int) $tag['jumlah_penunggak'] . ' penunggak',
                'variant' => 'piutang',
                'icon' => 'fa-hand-holding-dollar',
            ];
            require __DIR__ . '/partials/ui_kpi_card.php';
            ?>
        </div>
        <div class="col-6 col-lg">
            <?php
            $keuUiKpi = [
                'label' => 'Tertagih',
                'value' => htmlspecialchars((string) $tag['persen_tertagih']) . '%',
                'hint' => 'Persen terbayar valid vs tagihan',
                'variant' => 'tagihan',
                'icon' => 'fa-chart-line',
                'extra_html' => '<div class="progress mt-2" style="height:5px" role="progressbar" aria-label="Persen tertagih" aria-valuenow="' . (int) $tag['persen_tertagih'] . '" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-success" style="width:' . min(100, max(0, (float) $tag['persen_tertagih'])) . '%"></div></div>',
            ];
            require __DIR__ . '/partials/ui_kpi_card.php';
            ?>
        </div>
    </div>

    <details class="small mb-3">
        <summary class="fw-semibold">Indikator tambahan (neraca, mutasi hari ini)</summary>
        <div class="row g-3 mt-1">
            <div class="col-md-4">
                <?php
                $neracaVal = $neracaSehat ? 'Seimbang' : (!$seimbang ? 'Belum seimbang' : 'Perlu cek');
                $nerHint = !$neracaSehat && !$seimbang ? 'Selisih ' . $formatRupiah($selisihNeraca) : 'Per ' . (string) ($ner['as_of_label'] ?? '');
                $keuUiKpi = [
                    'label' => 'Neraca pondok',
                    'value' => htmlspecialchars($neracaVal),
                    'hint' => $nerHint,
                    'variant' => $neracaSehat ? 'ok' : 'warn',
                    'icon' => $neracaSehat ? 'fa-scale-balanced' : 'fa-scale-unbalanced',
                    'extra_html' => '<a href="/keuangan/neraca.php" class="small">Buka neraca</a>',
                ];
                require __DIR__ . '/partials/ui_kpi_card.php';
                ?>
            </div>
            <div class="col-md-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body py-2">
                        <div class="small fw-semibold mb-2">Mutasi hari ini</div>
                        <div class="d-flex justify-content-between small mb-1"><span class="text-muted">Masuk</span><span class="text-success keu-money"><?= htmlspecialchars($formatRupiah((int) ($mutasiHariIni['masuk'] ?? 0))) ?></span></div>
                        <div class="d-flex justify-content-between small mb-1"><span class="text-muted">Keluar</span><span class="text-danger keu-money"><?= htmlspecialchars($formatRupiah((int) ($mutasiHariIni['keluar'] ?? 0))) ?></span></div>
                        <div class="d-flex justify-content-between small border-top pt-1"><span class="text-muted">Bersih</span><span class="keu-money"><?= htmlspecialchars($formatRupiah((int) ($mutasiHariIni['bersih'] ?? 0))) ?></span></div>
                        <a class="small d-inline-block mt-2" href="<?= htmlspecialchars(keuangan_riwayat_pembayaran_href(date('Y-m-d'), date('Y-m-d'))) ?>">Detail hari ini</a>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <?php
                $keuUiKpi = [
                    'label' => 'Penunggak (jumlah santri)',
                    'value' => (string) (int) $tag['jumlah_penunggak'],
                    'hint' => (int) $tag['jumlah_lunas'] . ' lunas · ' . (int) $tag['jumlah_sebagian'] . ' sebagian',
                    'variant' => 'piutang',
                    'icon' => 'fa-user-clock',
                    'extra_html' => '<a href="' . htmlspecialchars($tagihanUrl) . '" class="small">Daftar tagihan</a>',
                ];
                require __DIR__ . '/partials/ui_kpi_card.php';
                ?>
            </div>
        </div>
    </details>

    <?php
    $checklistKas = keuangan_dashboard_checklist_kas($dashSnap);
    require __DIR__ . '/partials/checklist_kas_bulanan.php';
    ?>

    <?php if ($selisihRekapKas !== 0 || $transaksiTanpaAkun > 0): ?>
    <div class="alert alert-warning py-2 small mb-3">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>
        <strong>Perlu perhatian:</strong>
        <?php if ($selisihRekapKas !== 0): ?>
            Selisih rekap kas <?= htmlspecialchars($formatRupiah(abs($selisihRekapKas))) ?>.
        <?php endif; ?>
        <?php if ($transaksiTanpaAkun > 0): ?>
            <?= $transaksiTanpaAkun ?> transaksi tanpa akun kas/bank.
        <?php endif; ?>
        <a href="<?= htmlspecialchars(app_href('/keuangan/perbaikan-kas.php')) ?>" class="alert-link ms-1">Perbaikan Kas →</a>
    </div>
    <?php endif; ?>

    <details class="mb-3">
        <summary class="small fw-semibold">Rincian akun operasional (terkini)</summary>
        <div class="card border-0 shadow-sm mt-2">
                <div class="card-body py-2">
                    <?php if (($kasBank['akun'] ?? []) === []): ?>
                        <p class="small text-muted mb-0">Belum ada akun kas/rekening aktif. <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=akun')) ?>">Buat akun</a></p>
                    <?php else: ?>
                        <ul class="list-unstyled small mb-0">
                            <?php foreach ($kasBank['akun'] as $ak): ?>
                                <li class="d-flex justify-content-between gap-2 py-1 border-bottom border-light flex-wrap">
                                    <span>
                                        <span class="badge text-bg-<?= strtoupper((string) ($ak['jenis'] ?? '')) === 'BANK' ? 'primary' : 'success' ?> me-1"><?= htmlspecialchars((string) ($ak['jenis'] ?? 'KAS')) ?></span>
                                        <?= htmlspecialchars((string) ($ak['nama'] ?? '-')) ?>
                                        <?php if (trim((string) ($ak['nomor'] ?? '')) !== ''): ?>
                                            <span class="text-muted">· <?= htmlspecialchars((string) $ak['nomor']) ?></span>
                                        <?php endif; ?>
                                        <span class="text-muted ms-1">(masuk bln <?= htmlspecialchars($formatRupiah((int) ($ak['masuk_bulan'] ?? 0))) ?> · keluar <?= htmlspecialchars($formatRupiah((int) ($ak['keluar_bulan'] ?? 0))) ?>)</span>
                                    </span>
                                    <strong><?= htmlspecialchars($formatRupiah((int) ($ak['saldo'] ?? 0))) ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
    </details>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card shadow-sm border-warning border-opacity-50 h-100">
                <div class="card-header bg-warning bg-opacity-10 fw-semibold">
                    <i class="fa-solid fa-list-check me-1"></i> Perlu segera dilakukan
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush keu-dash-tindakan">
                        <?php foreach ($dashSnap['tindakan'] as $t): ?>
                            <?php
                            $lvl = (string) ($t['level'] ?? 'secondary');
                            $badge = match ($lvl) {
                                'danger' => 'danger',
                                'warning' => 'warning',
                                'success' => 'success',
                                default => 'secondary',
                            };
                            ?>
                            <li class="list-group-item">
                                <a href="<?= htmlspecialchars((string) $t['href']) ?>" class="d-flex gap-3 align-items-start text-decoration-none text-body">
                                    <span class="badge text-bg-<?= $badge ?> mt-1"><i class="fa-solid <?= htmlspecialchars((string) $t['icon']) ?>"></i></span>
                                    <span>
                                        <span class="fw-semibold d-block"><?= htmlspecialchars((string) $t['judul']) ?></span>
                                        <span class="small text-muted"><?= htmlspecialchars((string) $t['deskripsi']) ?></span>
                                    </span>
                                    <i class="fa-solid fa-chevron-right ms-auto text-muted small mt-1"></i>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm h-100 border-success border-opacity-25">
                <div class="card-header bg-success bg-opacity-10 fw-semibold text-success">
                    <i class="fa-brands fa-whatsapp me-1"></i> Tagihan WA ke wali
                </div>
                <div class="card-body">
                    <dl class="row small mb-0 g-2 keu-dash-wa-dl">
                        <dt class="col-5 text-muted">Status auto</dt>
                        <dd class="col-7 mb-0">
                            <?php if (!empty($wa['enabled'])): ?>
                                <span class="badge text-bg-success">Aktif</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Nonaktif</span>
                            <?php endif; ?>
                        </dd>
                        <dt class="col-5 text-muted">Hari ini</dt>
                        <dd class="col-7 mb-0">
                            Hari ke-<?= (int) ($wa['today_day'] ?? 0) ?>
                            <?php if (!empty($wa['hari_ini_jadwal_kirim'])): ?>
                                <span class="badge text-bg-warning">Jadwal kirim</span>
                            <?php endif; ?>
                            <?php if (!empty($wa['send_time_ok'])): ?>
                                <span class="text-muted">· jam kirim sudah lewat</span>
                            <?php elseif (!empty($wa['hari_ini_jadwal_kirim'])): ?>
                                <span class="text-muted">· menunggu jam <?= htmlspecialchars((string) ($wa['send_time'] ?? '')) ?></span>
                            <?php endif; ?>
                        </dd>
                        <dt class="col-5 text-muted">Cron</dt>
                        <dd class="col-7 mb-0">
                            <?php
                            $cronOk = false;
                            if (!empty($wa['cron_last_run'])) {
                                $cronTs = strtotime((string) $wa['cron_last_run']);
                                $cronOk = $cronTs !== false && (time() - $cronTs) < 600;
                            }
                            ?>
                            <?php if ($cronOk): ?>
                                <span class="text-success">Aktif</span> (<?= htmlspecialchars((string) $wa['cron_last_run']) ?>)
                            <?php elseif (!empty($wa['cron_last_run'])): ?>
                                <span class="text-danger">Lambat</span> (<?= htmlspecialchars((string) $wa['cron_last_run']) ?>)
                            <?php else: ?>
                                <span class="text-danger">Belum pernah jalan</span>
                            <?php endif; ?>
                        </dd>
                        <?php if (!empty($wa['partial_fail_at'])): ?>
                            <dt class="col-5 text-muted">Gagal sebagian</dt>
                            <dd class="col-7 mb-0 text-warning"><?= htmlspecialchars((string) $wa['partial_fail_at']) ?></dd>
                        <?php endif; ?>
                        <dt class="col-5 text-muted">Jadwal kirim</dt>
                        <dd class="col-7 mb-0">Tgl <?= (int) $wa['due_day'] ?>
                            <?php if ((string) ($wa['send_time'] ?? '') !== ''): ?>
                                · jam <?= htmlspecialchars((string) $wa['send_time']) ?>
                            <?php endif; ?>
                            (<?= htmlspecialchars((string) $wa['calendar']) ?>)
                        </dd>
                        <dt class="col-5 text-muted">Periode ini</dt>
                        <dd class="col-7 mb-0">
                            <?= !empty($wa['period_sudah_kirim'])
                                ? '<span class="text-success">Sudah terkirim</span>'
                                : '<span class="text-warning">Belum terkirim</span>' ?>
                        </dd>
                        <?php if (!empty($wa['last_sent_at'])): ?>
                            <dt class="col-5 text-muted">Terakhir kirim</dt>
                            <dd class="col-7 mb-0"><?= htmlspecialchars((string) $wa['last_sent_at']) ?></dd>
                        <?php endif; ?>
                        <dt class="col-5 text-muted">Siap WA</dt>
                        <dd class="col-7 mb-0"><?= (int) $wa['penunggak_dengan_wa'] ?> wali
                            <?php if ((int) ($wa['penunggak_tanpa_wa'] ?? 0) > 0): ?>
                                <span class="text-danger">· <?= (int) $wa['penunggak_tanpa_wa'] ?> tanpa nomor</span>
                            <?php endif; ?>
                        </dd>
                    </dl>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <a href="/settings/index.php" class="btn btn-sm btn-outline-success">
                            <i class="fa-solid fa-gear me-1"></i> Pengaturan WA
                        </a>
                        <?php if (!empty($wa['enabled']) && !empty($wa['hari_ini_jadwal_kirim']) && empty($wa['period_sudah_kirim'])): ?>
                            <span class="small text-danger align-self-center">
                                <i class="fa-solid fa-circle-exclamation me-1"></i> Hari ini jadwal kirim — pastikan cron aktif
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($dashSnap !== null): ?>
<?php
    $nerSnap = $dashSnap['neraca'];
    $rekapSnap = $dashSnap['rekap_kas'] ?? [];
    $lakRingkas = $dashSnap['arus_kas_ringkas'] ?? ['kenaikan_kas' => 0, 'periode_label' => ''];
    $selisihNeracaSnap = abs((int) ($nerSnap['selisih'] ?? 0));
    $neracaSnapSeimbang = !empty($nerSnap['seimbang']);
?>
<div class="row g-3 mb-4 keu-laporan-ringkas" id="laporan">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100 border-success border-2">
            <div class="card-body">
                <h2 class="h6 mb-2">
                    <i class="fa-solid fa-table-list text-success me-1"></i> Rekap kas bulanan
                    <span class="badge bg-success ms-1" style="font-size:0.65rem">Laporan utama</span>
                </h2>
                <p class="small text-muted mb-2">TA <?= htmlspecialchars((string) ($rekapSnap['ta_label'] ?? '')) ?> · s.d. <?= htmlspecialchars((string) ($rekapSnap['bulan_berjalan_label'] ?? '')) ?></p>
                <p class="mb-1">Saldo akhir (uang nyata): <strong class="fs-5"><?= htmlspecialchars($formatRupiah((int) ($rekapSnap['saldo_akhir_uang_nyata'] ?? $rekapSnap['saldo_akhir_fisik'] ?? 0))) ?></strong></p>
                <?php if ((int) ($rekapSnap['selisih_saldo'] ?? 0) !== 0): ?>
                <p class="small text-warning mb-1">Hitung buku <?= htmlspecialchars($formatRupiah((int) ($rekapSnap['saldo_akhir_hitung'] ?? $rekapSnap['saldo_akhir'] ?? 0))) ?> · selisih <?= htmlspecialchars($formatRupiah(abs((int) $rekapSnap['selisih_saldo']))) ?></p>
                <p class="small mb-2"><a href="/keuangan/perbaikan-kas.php">Perbaiki transaksi kas</a></p>
                <?php else: ?>
                <p class="small text-muted mb-2">Masuk <?= htmlspecialchars($formatRupiah((int) ($rekapSnap['masuk_total'] ?? 0))) ?> · Keluar <?= htmlspecialchars($formatRupiah((int) ($rekapSnap['keluar'] ?? 0))) ?></p>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2">
                    <a href="/keuangan/rekap-kas-bulan.php" class="btn btn-success btn-sm">Buka rekap</a>
                    <a href="/keuangan/rekap-kas-bulan.php?print=1" target="_blank" class="btn btn-outline-secondary btn-sm">Cetak PDF</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100 border-primary border-opacity-25">
            <div class="card-body">
                <h2 class="h6 mb-2"><i class="fa-solid fa-scale-balanced text-primary me-1"></i> Neraca</h2>
                <p class="small text-muted mb-2">Per <?= htmlspecialchars((string) ($nerSnap['as_of_label'] ?? date('d/m/Y'))) ?></p>
                <p class="mb-1">Total aset: <strong class="fs-5"><?= htmlspecialchars($formatRupiah((int) ($nerSnap['total_aset'] ?? 0))) ?></strong></p>
                <?php if (!$neracaSnapSeimbang): ?>
                <p class="small text-warning mb-2">Selisih neraca: <?= htmlspecialchars($formatRupiah($selisihNeracaSnap)) ?></p>
                <?php elseif ((int) ($nerSnap['jumlah_tanpa_jurnal'] ?? 0) > 0): ?>
                <p class="small text-warning mb-2"><?= (int) $nerSnap['jumlah_tanpa_jurnal'] ?> transaksi tanpa jurnal</p>
                <?php else: ?>
                <p class="small text-muted mb-2">Neraca seimbang</p>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2">
                    <a href="/keuangan/neraca.php" class="btn btn-primary btn-sm">Buka neraca</a>
                    <a href="/keuangan/offline-data.php" class="btn btn-outline-secondary btn-sm" title="Unduh data untuk baca offline">
                        <i class="fa-solid fa-download me-1"></i> Offline
                    </a>
                    <?php if (!$neracaSnapSeimbang || (int) ($nerSnap['jumlah_tanpa_jurnal'] ?? 0) > 0): ?>
                    <a href="/keuangan/neraca-perbaikan.php" class="btn btn-warning btn-sm">Analisis</a>
                    <?php endif; ?>
                    <a href="/keuangan/neraca.php?print=1" target="_blank" class="btn btn-outline-secondary btn-sm">Cetak PDF</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100 border-success border-opacity-25">
            <div class="card-body">
                <h2 class="h6 mb-2"><i class="fa-solid fa-money-bill-transfer text-success me-1"></i> Arus kas</h2>
                <p class="small text-muted mb-2"><?= htmlspecialchars((string) $lakRingkas['periode_label']) ?></p>
                <p class="mb-3">Kenaikan kas: <strong class="fs-5"><?= htmlspecialchars($formatRupiah((int) $lakRingkas['kenaikan_kas'])) ?></strong></p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="/keuangan/arus-kas.php" class="btn btn-success btn-sm">Buka arus kas</a>
                    <a href="/keuangan/arus-kas.php?print=1" target="_blank" class="btn btn-outline-secondary btn-sm">Cetak PDF</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 keu-modern-nav">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100 border-0 keu-modern-section keu-modern-section--laporan">
            <div class="card-header fw-semibold">Laporan</div>
            <div class="card-body d-grid gap-2">
                <a class="keu-modern-link keu-modern-link--featured" href="/keuangan/rekap-kas-bulan.php"><i class="fa-solid fa-table-list"></i><span>Rekap kas bulanan <span class="badge bg-success ms-1">utama</span></span></a>
                <a class="keu-modern-link" href="/keuangan/neraca.php"><i class="fa-solid fa-scale-balanced"></i><span>Neraca</span></a>
                <a class="keu-modern-link" href="/keuangan/arus-kas.php"><i class="fa-solid fa-money-bill-transfer"></i><span>Arus kas</span></a>
                <a class="keu-modern-link" href="/pembayaran/rekap_pos.php"><i class="fa-solid fa-chart-pie"></i><span>Rekap per POS</span></a>
                <a class="keu-modern-link" href="/pembayaran/laporan.php"><i class="fa-solid fa-chart-column"></i><span>Laporan syahriyah</span></a>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100 border-0 keu-modern-section keu-modern-section--transaksi">
            <div class="card-header fw-semibold">Transaksi</div>
            <div class="card-body d-grid gap-2">
                <a class="keu-modern-link" href="/keuangan/pembayaran.php"><i class="fa-solid fa-circle-plus"></i><span>Input pembayaran</span></a>
                <a class="keu-modern-link" href="/pembayaran/tagihan_syahriyah.php"><i class="fa-solid fa-receipt"></i><span>Status tagihan</span></a>
                <a class="keu-modern-link" href="/pembayaran/riwayat.php"><i class="fa-solid fa-clock-rotate-left"></i><span>Riwayat pembayaran</span></a>
                <a class="keu-modern-link" href="/keuangan/pemasukan.php"><i class="fa-solid fa-hand-holding-dollar"></i><span>Pemasukan lain</span></a>
                <a class="keu-modern-link" href="/keuangan/pengeluaran.php"><i class="fa-solid fa-minus-circle"></i><span>Input pengeluaran</span></a>
                <a class="keu-modern-link" href="/keuangan/talangan.php"><i class="fa-solid fa-arrows-left-right"></i><span>Dana talangan antar-POS</span></a>
                <a class="keu-modern-link" href="/keuangan/impor-ekspor.php"><i class="fa-solid fa-file-excel"></i><span>Impor / ekspor Excel</span></a>
                <a class="keu-modern-link" href="/keuangan/gaji_pembimbing.php"><i class="fa-solid fa-chalkboard-user"></i><span>Gaji pembimbing</span></a>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100 border-0 keu-modern-section keu-modern-section--pengaturan">
            <div class="card-header fw-semibold">Pengaturan</div>
            <div class="card-body d-grid gap-2">
                <a class="keu-modern-link" href="<?= htmlspecialchars(app_href('/keuangan/panduan.php')) ?>"><i class="fa-solid fa-book-open"></i><span>Panduan &amp; rumus keuangan</span></a>
                <a class="keu-modern-link" href="/keuangan/pengaturan.php"><i class="fa-solid fa-sliders"></i><span>Pengaturan keuangan &amp; tarif</span></a>
                <a class="keu-modern-link" href="/keuangan/pengaturan.php?bagian=tarif"><i class="fa-solid fa-tags"></i><span>Tarif &amp; komponen</span></a>
                <a class="keu-modern-link" href="/keuangan/pengaturan.php?bagian=alokasi"><i class="fa-solid fa-chart-pie"></i><span>Alokasi dana</span></a>
                <a class="keu-modern-link" href="/keuangan/pengaturan.php?bagian=santri_bulanan"><i class="fa-solid fa-user-gear"></i><span>Override per santri (bulanan)</span></a>
                <a class="keu-modern-link" href="/settings/kelas_keuangan.php"><i class="fa-solid fa-layer-group"></i><span>Kelas keuangan</span></a>
                <a class="keu-modern-link" href="/keuangan/inventaris.php"><i class="fa-solid fa-warehouse"></i><span>Inventaris aset</span></a>
            </div>
        </div>
    </div>
</div>

<p class="text-center text-muted small mt-2 mb-0">
    <a href="<?= htmlspecialchars(app_href('/keuangan/saku.php')) ?>" class="text-decoration-none">
        <i class="fa-solid fa-coins me-1"></i>Modul Saku &amp; Cashless →
    </a>
</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
