<?php

declare(strict_types=1);

require_once __DIR__ . '/app_path.php';

/** @return array<string, array{title:string,landing:string,tabs:list<array{path:string,label:string}>}> */
function app_hub_registry(): array
{
    return [
        'keuangan_transaksi' => [
            'title' => 'Tagihan & Pembayaran',
            'landing' => '/keuangan/transaksi.php',
            'tabs' => [
                ['path' => '/keuangan/pembayaran.php', 'label' => 'Input bayar'],
                ['path' => '/pembayaran/tagihan_syahriyah.php', 'label' => 'Tagihan'],
                ['path' => '/pembayaran/riwayat.php', 'label' => 'Riwayat'],
            ],
        ],
        'keuangan_kas' => [
            'title' => 'Kas umum',
            'landing' => '/keuangan/kas.php',
            'tabs' => [
                ['path' => '/keuangan/pemasukan.php', 'label' => 'Pemasukan'],
                ['path' => '/keuangan/pengeluaran.php', 'label' => 'Pengeluaran'],
                ['path' => '/keuangan/riwayat_pengeluaran.php', 'label' => 'Riwayat keluar'],
            ],
        ],
        'keuangan_cashless' => [
            'title' => 'Cashless',
            'landing' => '/keuangan/cashless.php',
            'tabs' => [
                ['path' => '/keuangan/cashless_scan.php', 'label' => 'Top up / scan'],
                ['path' => '/keuangan/cashless_laporan.php', 'label' => 'Laporan'],
            ],
        ],
        'setoran_hafalan' => [
            'title' => 'Setoran Hafalan',
            'landing' => '/akademik/setoran.php',
            'tabs' => [
                ['path' => '/akademik/setoran_dashboard.php', 'label' => 'Dashboard'],
                ['path' => '/akademik/hafalan.php', 'label' => 'Input setoran'],
                ['path' => '/pembimbing/setoran.php', 'label' => 'Scan'],
                ['path' => '/akademik/setoran_rekap.php', 'label' => 'Rekap'],
                ['path' => '/akademik/setoran_rekap_kitab.php', 'label' => 'Rekap kitab'],
                ['path' => '/akademik/bait_kitab.php', 'label' => 'Bait & kitab'],
                ['path' => '/akademik/setoran_penerima.php', 'label' => 'Penerima'],
            ],
        ],
        'ikhtibar' => [
            'title' => 'Tugas Ikhtibar',
            'landing' => '/akademik/ikhtibar.php',
            'tabs' => [
                ['path' => '/pembimbing/tugas/index.php', 'label' => 'Daftar tugas'],
                ['path' => '/pembimbing/tugas/rekap.php', 'label' => 'Rekap'],
                ['path' => '/akademik/ikhtibar_rekap.php', 'label' => 'Rekap admin'],
                ['path' => '/pembimbing/nilai_manual.php', 'label' => 'Nilai manual'],
            ],
        ],
        'rekap_presensi' => [
            'title' => 'Rekap Presensi',
            'landing' => '/rekap/presensi.php',
            'tabs' => [
                ['path' => '/rekap/index.php', 'label' => 'Periode'],
                ['path' => '/rekap/keaktifan_hari.php', 'label' => 'Harian'],
                ['path' => '/rekap/santri_bagus.php', 'label' => 'Keaktifan santri'],
                ['path' => '/rekap/keaktivan_sdm.php', 'label' => 'SDM'],
                ['path' => '/rekap/munawib.php', 'label' => 'Munawib'],
                ['path' => '/rekap/pembimbing.php', 'label' => 'Pembimbing'],
                ['path' => '/rekap/izin_telat.php', 'label' => 'Telat'],
                ['path' => '/rekap/kegiatan_khusus.php', 'label' => 'Khusus'],
            ],
        ],
        'perizinan_hub' => [
            'title' => 'Perizinan Santri',
            'landing' => '/perizinan/hub.php',
            'tabs' => [
                ['path' => '/perizinan/index.php', 'label' => 'Persetujuan'],
                ['path' => '/perizinan/permohonan.php', 'label' => 'Pengajuan'],
                ['path' => '/pengasuh/perizinan.php', 'label' => 'Pengasuh'],
                ['path' => '/perizinan/rekap_aktif.php', 'label' => 'Rekap aktif'],
                ['path' => '/perizinan/izin_tetap.php', 'label' => 'Izin tetap'],
            ],
        ],
        'kalender_ta' => [
            'title' => 'Kalender & TA',
            'landing' => '/settings/kalender_ta.php',
            'tabs' => [
                ['path' => '/settings/kalender.php', 'label' => 'Tagihan TA'],
                ['path' => '/settings/hijri_mappings.php', 'label' => 'Hijriyah'],
                ['path' => '/akademik/kalender.php', 'label' => 'Akademik'],
            ],
        ],
    ];
}

function app_hub_normalize_path(string $path): string
{
    $path = app_normalize_request_path($path);
    if ($path !== '' && $path[0] !== '/') {
        $path = '/' . $path;
    }

    return $path;
}

/** @return array{hub:array,active:array{path:string,label:string}}|null */
function app_hub_match_path(string $requestPath): ?array
{
    $requestPath = app_hub_normalize_path($requestPath);
    foreach (app_hub_registry() as $hub) {
        foreach ($hub['tabs'] as $tab) {
            $tabPath = app_hub_normalize_path((string) $tab['path']);
            if ($requestPath === $tabPath || str_starts_with($requestPath, $tabPath . '/')) {
                return ['hub' => $hub, 'active' => $tab];
            }
        }
    }

    return null;
}

function app_hub_render_tabs_for_path(string $requestPath): void
{
    $match = app_hub_match_path($requestPath);
    if ($match === null) {
        return;
    }
    $hub = $match['hub'];
    $activePath = app_hub_normalize_path((string) $match['active']['path']);
    ?>
<nav class="app-hub-tabs mb-3" aria-label="<?= htmlspecialchars((string) ($hub['title'] ?? 'Sub-menu')) ?>">
    <span class="app-hub-tabs__title"><?= htmlspecialchars((string) ($hub['title'] ?? '')) ?></span>
    <div class="app-hub-tabs__links">
        <?php foreach ($hub['tabs'] as $tab):
            $tabPath = app_hub_normalize_path((string) $tab['path']);
            ?>
        <a href="<?= htmlspecialchars(app_href($tabPath)) ?>" class="app-hub-tabs__link<?= $tabPath === $activePath ? ' active' : '' ?>"><?= htmlspecialchars((string) $tab['label']) ?></a>
        <?php endforeach; ?>
    </div>
</nav>
    <?php
}

function app_hub_redirect_landing(string $hubKey): void
{
    $hubs = app_hub_registry();
    if (!isset($hubs[$hubKey])) {
        header('Location: ' . app_href('/dashboard.php'));
        exit;
    }
    $first = $hubs[$hubKey]['tabs'][0]['path'] ?? '/dashboard.php';
    header('Location: ' . app_href((string) $first), true, 302);
    exit;
}
