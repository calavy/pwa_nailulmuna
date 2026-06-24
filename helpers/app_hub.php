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
                ['path' => '/keuangan/riwayat_pemasukan.php', 'label' => 'Riwayat masuk'],
                ['path' => '/keuangan/riwayat_pengeluaran.php', 'label' => 'Riwayat keluar'],
            ],
        ],
        'keuangan_cashless' => [
            'title' => 'Cashless',
            'landing' => '/keuangan/cashless.php',
            'tabs' => [
                ['path' => '/keuangan/cashless_scan.php', 'label' => 'Top up / scan'],
                ['path' => '/keuangan/cashless_setor.php', 'label' => 'Setor'],
                ['path' => '/keuangan/cashless_laporan.php', 'label' => 'Laporan koperasi'],
                ['path' => '/keuangan/cashless_pin.php', 'label' => 'Saldo & PIN'],
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
                ['path' => '/settings/ikhtibar_kriteria.php', 'label' => 'Kriteria nilai'],
            ],
        ],
        'pkpps_hub' => [
            'title' => 'PKPPS',
            'landing' => '/pkpps/index.php',
            'match_prefixes' => ['/pkpps/', '/rekap/pkpps_', '/pembimbing/pkpps_'],
            'match_paths' => ['/pembayaran/laporan_pkpps_syahriyah.php'],
            'tabs' => [
                ['path' => '/pkpps/index.php', 'label' => 'Dashboard'],
                ['path' => '/pkpps/santri.php', 'label' => 'Santri'],
                ['path' => '/pkpps/jadwal.php', 'label' => 'Jadwal'],
                ['path' => '/pkpps/tugas/index.php', 'label' => 'Tugas & soal'],
                ['path' => '/rekap/pkpps_keaktifan_hari.php', 'label' => 'Keaktifan hari ini'],
                ['path' => '/rekap/pkpps_keaktivan.php', 'label' => 'Rekap keaktivan'],
                ['path' => '/pembimbing/pkpps_santri.php', 'label' => 'Portal pembimbing'],
                ['path' => '/pembayaran/laporan_pkpps_syahriyah.php', 'label' => 'Syahriyah'],
            ],
        ],
        'rekap_presensi' => [
            'title' => 'Rekap Presensi',
            'landing' => '/rekap/presensi.php',
            'tabs' => [
                ['path' => '/rekap/index.php', 'label' => 'Periode'],
                ['path' => '/rekap/keaktifan_hari.php', 'label' => 'Harian'],
                ['path' => '/yayasan/operasional.php', 'label' => 'Keaktifan bulanan'],
                ['path' => '/rekap/keaktivan_sdm.php', 'label' => 'SDM'],
                ['path' => '/rekap/munawib.php', 'label' => 'Munawib'],
                ['path' => '/rekap/pembimbing.php', 'label' => 'Pembimbing'],
                ['path' => '/rekap/izin_telat.php', 'label' => 'Telat'],
                ['path' => '/rekap/kegiatan_khusus.php', 'label' => 'Khusus'],
                ['path' => '/presensi/rekap_tanpa_scan.php', 'label' => 'Tanpa scan'],
            ],
        ],
        'perizinan_hub' => [
            'title' => 'Perizinan Santri',
            'landing' => '/perizinan/hub.php',
            'tabs' => [
                ['path' => '/perizinan/permohonan.php', 'label' => 'Pengajuan'],
                ['path' => '/perizinan/index.php', 'label' => 'Persetujuan'],
                ['path' => '/pengasuh/perizinan.php', 'label' => 'Pengasuh'],
                ['path' => '/perizinan/rekap_aktif.php', 'label' => 'Rekap aktif'],
                ['path' => '/perizinan/izin_tetap.php', 'label' => 'Izin tetap'],
            ],
        ],
        'kalender_ta' => [
            'title' => 'Kalender & TA',
            'landing' => '/settings/kalender_ta.php',
            'tabs' => [
                ['path' => '/settings/kalender.php', 'label' => 'Kalender Pondok'],
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
        foreach ((array) ($hub['match_paths'] ?? []) as $extraPath) {
            $extraPath = app_hub_normalize_path((string) $extraPath);
            if ($requestPath === $extraPath) {
                return ['hub' => $hub, 'active' => ['path' => $extraPath, 'label' => '']];
            }
        }
        foreach ((array) ($hub['match_prefixes'] ?? []) as $prefix) {
            $prefix = app_hub_normalize_path((string) $prefix);
            if ($prefix !== '' && str_starts_with($requestPath, $prefix)) {
                $active = ['path' => $requestPath, 'label' => ''];
                foreach ($hub['tabs'] as $tab) {
                    $tabPath = app_hub_normalize_path((string) $tab['path']);
                    if ($requestPath === $tabPath || str_starts_with($requestPath, $tabPath . '/')) {
                        $active = $tab;
                        break;
                    }
                }
                return ['hub' => $hub, 'active' => $active];
            }
        }
        foreach ($hub['tabs'] as $tab) {
            $tabPath = app_hub_normalize_path((string) $tab['path']);
            if ($requestPath === $tabPath || str_starts_with($requestPath, $tabPath . '/')) {
                return ['hub' => $hub, 'active' => $tab];
            }
        }
    }

    return null;
}

/**
 * @param array<string, string> $permissionPathMap
 */
function app_hub_tab_allowed(PDO $pdo, string $tabPath, array $permissionPathMap): bool
{
    if (!function_exists('get_allowed_permission_key_map')) {
        require_once __DIR__ . '/app.php';
    }
    $allowedMap = get_allowed_permission_key_map($pdo);
    if ($allowedMap === null) {
        return true;
    }
    if (!function_exists('app_acl_menu_path_allowed')) {
        require_once __DIR__ . '/app.php';
    }

    return app_acl_menu_path_allowed(app_hub_normalize_path($tabPath), $permissionPathMap, $allowedMap);
}

/**
 * @param array<string, string> $permissionPathMap
 */
function app_hub_render_tabs_for_path(PDO $pdo, string $requestPath, array $permissionPathMap = []): void
{
    $match = app_hub_match_path($requestPath);
    if ($match === null) {
        return;
    }
    if ($permissionPathMap === []) {
        if (!function_exists('user_permission_path_map')) {
            require_once __DIR__ . '/user_permissions.php';
        }
        $permissionPathMap = user_permission_path_map();
    }
    $hub = $match['hub'];
    $activePath = app_hub_normalize_path((string) $match['active']['path']);
    $visibleTabs = [];
    foreach ($hub['tabs'] as $tab) {
        $tabPath = app_hub_normalize_path((string) $tab['path']);
        if (app_hub_tab_allowed($pdo, $tabPath, $permissionPathMap)) {
            $visibleTabs[] = $tab;
        }
    }
    if ($visibleTabs === []) {
        return;
    }
    ?>
<nav class="app-hub-tabs mb-3" aria-label="<?= htmlspecialchars((string) ($hub['title'] ?? 'Sub-menu')) ?>">
    <span class="app-hub-tabs__title"><?= htmlspecialchars((string) ($hub['title'] ?? '')) ?></span>
    <div class="app-hub-tabs__links">
        <?php foreach ($visibleTabs as $tab):
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
