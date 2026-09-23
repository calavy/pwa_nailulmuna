<?php

declare(strict_types=1);

/**
 * UX dashboard home pembimbing — CTA utama, label ramah, petunjuk grup (mobile-first).
 */

/**
 * @param array<string, mixed> $pbHomeBundle
 * @return array{href: string, label: string, sublabel: string, tone: string, icon: string}
 */
function pembimbing_dashboard_home_primary_cta(array $pbHomeBundle, bool $isMunawibPortal): array
{
    $kpi = (array) ($pbHomeBundle['kpi'] ?? []);
    $scanOk = !empty($kpi['presensi_scan_ok']);
    $live = (int) ($kpi['kegiatan_live'] ?? 0);
    $pending = (int) ($kpi['penilaian_pending'] ?? 0);

    if (!$scanOk) {
        return [
            'href' => app_href('/presensi/scan.php'),
            'label' => 'Absen / scan presensi sekarang',
            'sublabel' => 'Anda belum absen hari ini',
            'tone' => 'warning',
            'icon' => 'fa-solid fa-qrcode',
        ];
    }

    if ($live > 0) {
        return [
            'href' => app_href('/presensi/scan.php'),
            'label' => 'Scan kegiatan yang berlangsung',
            'sublabel' => $live . ' kegiatan sedang berjalan',
            'tone' => 'success',
            'icon' => 'fa-solid fa-qrcode',
        ];
    }

    if (!$isMunawibPortal && $pending > 0) {
        return [
            'href' => app_href('/pembimbing/tugas/nilai.php'),
            'label' => 'Nilai santri (' . $pending . ' menunggu)',
            'sublabel' => 'Selesaikan penilaian yang belum diinput',
            'tone' => 'primary',
            'icon' => 'fa-solid fa-pen-to-square',
        ];
    }

    return [
        'href' => app_href('/pembimbing/dashboard.php?view=santri'),
        'label' => 'Lihat daftar santri',
        'sublabel' => 'Nama santri bimbingan Anda',
        'tone' => 'neutral',
        'icon' => 'fa-solid fa-user-graduate',
    ];
}

/** Label menu ramah untuk embed dashboard home (path penuh termasuk query). */
function pembimbing_dashboard_home_friendly_menu_labels(): array
{
    return [
        '/pembimbing/dashboard.php?view=santri' => 'Daftar santri saya',
        '/pembimbing/dashboard.php?view=kajian' => 'Jadwal kajian saya',
        '/pembimbing/dashboard.php?view=penilaian' => 'Penilaian santri',
        '/pembimbing/dashboard.php?view=kehadiran_saya' => 'Kehadiran saya',
        '/presensi/scan.php' => 'Absen santri (scan QR)',
        '/pembimbing/dashboard.php?view=keaktivan' => 'Kehadiran santri',
        '/perizinan/index.php' => 'Izin santri',
        '/jadwal/index.php' => 'Jadwal kegiatan',
        '/pembimbing/perizinan.php' => 'Atur kegiatan hari ini',
        '/pembimbing/tugas/nilai.php' => 'Input nilai tugas',
        '/pembimbing/tugas/index.php' => 'Daftar tugas ikhtibar',
        '/pembimbing/tugas/buat.php' => 'Buat tugas / soal',
        '/pembimbing/nilai_manual.php' => 'Nilai manual',
        '/settings/akses_saya.php' => 'Izin akses saya',
        '/settings/profil.php' => 'Profil saya',
    ];
}

function pembimbing_dashboard_home_friendly_label(string $path, string $fallback): string
{
    $map = pembimbing_dashboard_home_friendly_menu_labels();

    return $map[$path] ?? $fallback;
}

/** Petunjuk singkat per grup menu pembimbing. */
function pembimbing_dashboard_home_group_hint(string $groupId): string
{
    return match ($groupId) {
        'menu-grp-pb-santri' => 'Absen, lihat nama santri, dan izin',
        'menu-grp-pb-kegiatan' => 'Jadwal dan atur kegiatan harian',
        'menu-grp-pb-penilaian' => 'Input nilai tugas dan ujian',
        'menu-grp-pb-setoran' => 'Scan dan laporan setoran',
        'menu-grp-pb-tugas' => 'Tugas dari yayasan',
        'menu-grp-pb-catatan' => 'Buku catatan pembimbing',
        'menu-grp-pb-akun' => 'Profil dan pengaturan',
        default => '',
    };
}

/**
 * Tombol tugas harian (grid) — hanya path yang ada di menuItems (ACL).
 *
 * @param array<string, string> $menuItems
 * @return list<array{href: string, label: string, icon: string, class: string}>
 */
function pembimbing_dashboard_home_daily_tasks(array $menuItems, string $keaktivanUrl, bool $isMunawibPortal): array
{
    $candidates = [
        [
            'path' => '/presensi/scan.php',
            'href' => app_href('/presensi/scan.php'),
            'label' => 'Scan presensi',
            'icon' => 'fa-solid fa-qrcode',
            'class' => 'pb-dash-daily-task--scan',
        ],
        [
            'path' => '/pembimbing/dashboard.php?view=santri',
            'href' => app_href('/pembimbing/dashboard.php?view=santri'),
            'label' => 'Daftar santri',
            'icon' => 'fa-solid fa-address-book',
            'class' => 'pb-dash-daily-task--santri',
        ],
    ];

    if (!$isMunawibPortal) {
        $candidates[] = [
            'path' => '/pembimbing/dashboard.php?view=keaktivan',
            'href' => $keaktivanUrl,
            'label' => 'Kehadiran',
            'icon' => 'fa-solid fa-chart-line',
            'class' => 'pb-dash-daily-task--keaktifan',
        ];
        $candidates[] = [
            'path' => '/pembimbing/perizinan.php',
            'href' => app_href('/pembimbing/perizinan.php'),
            'label' => 'Atur kegiatan',
            'icon' => 'fa-solid fa-calendar-day',
            'class' => 'pb-dash-daily-task--kegiatan',
        ];
    } else {
        $candidates[] = [
            'path' => '/pembimbing/dashboard.php?view=keaktivan',
            'href' => $keaktivanUrl,
            'label' => 'Kehadiran',
            'icon' => 'fa-solid fa-chart-line',
            'class' => 'pb-dash-daily-task--keaktifan',
        ];
    }

    $out = [];
    foreach ($candidates as $row) {
        $path = (string) ($row['path'] ?? '');
        if ($path === '' || !array_key_exists($path, $menuItems)) {
            continue;
        }
        $out[] = [
            'href' => (string) $row['href'],
            'label' => (string) $row['label'],
            'icon' => (string) $row['icon'],
            'class' => (string) $row['class'],
        ];
    }

    return $out;
}

/**
 * Templat aksi penilaian per santri (hanya path yang ada di menuItems).
 *
 * @param array<string, string> $menuItems
 * @return list<array{label: string, icon: string, path: string}>
 */
function pembimbing_dashboard_home_penilaian_action_templates(
    array $menuItems,
    int $tahun,
    string $rekapJenis
): array {
    $candidates = [
        [
            'path' => '/pembimbing/tugas/nilai.php',
            'label' => 'Input nilai',
            'icon' => 'fa-solid fa-pen-to-square',
            'build' => static fn (int $santriId): string => app_href('/pembimbing/tugas/nilai.php'),
        ],
        [
            'path' => '/pembimbing/nilai_manual.php',
            'label' => 'Nilai manual',
            'icon' => 'fa-solid fa-star-half-stroke',
            'build' => static fn (int $santriId): string => app_href('/pembimbing/nilai_manual.php?santri_id=' . $santriId),
        ],
        [
            'path' => '/pembimbing/dashboard.php?view=keaktivan',
            'label' => 'Keaktivan',
            'icon' => 'fa-solid fa-chart-line',
            'build' => static fn (int $santriId): string => app_href(
                '/pembimbing/keaktifan_santri.php?santri_id=' . $santriId
                . '&tahun=' . $tahun
                . '&rekap_jenis=' . rawurlencode($rekapJenis)
            ),
        ],
    ];

    $out = [];
    foreach ($candidates as $row) {
        $path = (string) ($row['path'] ?? '');
        if ($path === '' || !array_key_exists($path, $menuItems)) {
            continue;
        }
        $out[] = [
            'label' => (string) ($row['label'] ?? ''),
            'icon' => (string) ($row['icon'] ?? 'fa-solid fa-circle'),
            'path' => $path,
            'build' => $row['build'],
        ];
    }

    if ($out === [] && array_key_exists('/pembimbing/tugas/nilai.php', $menuItems)) {
        $out[] = [
            'label' => 'Input nilai',
            'icon' => 'fa-solid fa-pen-to-square',
            'path' => '/pembimbing/tugas/nilai.php',
            'build' => static fn (int $santriId): string => app_href('/pembimbing/tugas/nilai.php'),
        ];
    }

    return $out;
}

/**
 * @param list<array{label: string, icon: string, path: string, build: callable(int): string}> $templates
 * @return list<array{label: string, icon: string, href: string}>
 */
function pembimbing_dashboard_home_penilaian_actions_for_santri(array $templates, int $santriId): array
{
    if ($santriId <= 0) {
        return [];
    }
    $out = [];
    foreach ($templates as $tpl) {
        $build = $tpl['build'] ?? null;
        if (!is_callable($build)) {
            continue;
        }
        $out[] = [
            'label' => (string) ($tpl['label'] ?? ''),
            'icon' => (string) ($tpl['icon'] ?? ''),
            'href' => (string) $build($santriId),
        ];
    }

    return $out;
}
