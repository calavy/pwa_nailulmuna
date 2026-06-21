<?php

declare(strict_types=1);

/** @return list<string> */
function pembimbing_portal_banner_variants(): array
{
    return ['default', 'kajian', 'pkpps', 'jamaah'];
}

function pembimbing_portal_banner_setting_key(string $variant): string
{
    $variant = strtolower(trim($variant));
    if (!in_array($variant, pembimbing_portal_banner_variants(), true)) {
        $variant = 'default';
    }

    return 'pb_portal_banner_' . $variant;
}

/** @return array<string, mixed> */
function pembimbing_portal_banner_defaults(string $variant): array
{
    $variant = strtolower(trim($variant));
    $presets = [
        'default' => [
            'enabled' => '1',
            'kicker' => 'Portal Pembimbing',
            'title' => '',
            'subtitle' => 'Pantau santri, keaktivan, dan kegiatan hari ini.',
            'tagline' => 'Bimbing · Pantau · Tindak',
            'icon' => 'fa-chalkboard-user',
            'gradient_from' => '#0f766e',
            'gradient_via' => '#115e59',
            'gradient_to' => '#134e4a',
            'accent' => '#2dd4bf',
            'glow' => '#5eead4',
            'pattern' => 'dots',
        ],
        'kajian' => [
            'enabled' => '1',
            'kicker' => 'Kajian · Ta\'lim',
            'title' => '',
            'subtitle' => 'Ta\'lim, tugas ikhtibar, dan keaktivan santri kajian.',
            'tagline' => 'Ilmu · Amal · Keaktivan',
            'icon' => 'fa-book-open',
            'gradient_from' => '#1e3a8a',
            'gradient_via' => '#1d4ed8',
            'gradient_to' => '#312e81',
            'accent' => '#93c5fd',
            'glow' => '#60a5fa',
            'pattern' => 'grid',
        ],
        'pkpps' => [
            'enabled' => '1',
            'kicker' => 'Program PKPPS',
            'title' => '',
            'subtitle' => 'Santri PKPPS, jadwal, tugas, dan rekap keaktivan.',
            'tagline' => 'Program · Disiplin · Prestasi',
            'icon' => 'fa-graduation-cap',
            'gradient_from' => '#92400e',
            'gradient_via' => '#b45309',
            'gradient_to' => '#78350f',
            'accent' => '#fcd34d',
            'glow' => '#fbbf24',
            'pattern' => 'rays',
        ],
        'jamaah' => [
            'enabled' => '1',
            'kicker' => 'Jama\'ah',
            'title' => '',
            'subtitle' => 'Kegiatan jama\'ah, sholat berjamaah, dan kehadiran santri.',
            'tagline' => 'Sholat · Jama\'ah · Kebersamaan',
            'icon' => 'fa-people-group',
            'gradient_from' => '#065f46',
            'gradient_via' => '#047857',
            'gradient_to' => '#064e3b',
            'accent' => '#6ee7b7',
            'glow' => '#34d399',
            'pattern' => 'waves',
        ],
    ];

    return $presets[$variant] ?? $presets['default'];
}

/** @return array<string, mixed> */
function pembimbing_portal_banner_get(PDO $pdo, string $variant): array
{
    $defaults = pembimbing_portal_banner_defaults($variant);
    $raw = app_setting($pdo, pembimbing_portal_banner_setting_key($variant), '');
    if ($raw === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return array_merge($defaults, $decoded);
}

/** @param array<string, mixed> $data */
function pembimbing_portal_banner_save(PDO $pdo, string $variant, array $data): void
{
    $defaults = pembimbing_portal_banner_defaults($variant);
    $out = [];
    foreach ($defaults as $key => $defaultVal) {
        if (!array_key_exists($key, $data)) {
            $out[$key] = $defaultVal;
            continue;
        }
        $val = $data[$key];
        if ($key === 'enabled') {
            $out[$key] = !empty($val) ? '1' : '0';
            continue;
        }
        $out[$key] = is_string($val) ? trim($val) : (string) $val;
    }
    save_setting($pdo, pembimbing_portal_banner_setting_key($variant), json_encode($out, JSON_UNESCAPED_UNICODE));
}

function pembimbing_portal_banner_resolve_variant(
    bool $isMunawibPortal,
    bool $hasPkpps,
    bool $hasKajian,
    string $rekapJenis = ''
): string {
    if ($isMunawibPortal) {
        return 'default';
    }
    $rekapJenis = strtolower(trim($rekapJenis));
    if ($rekapJenis === 'pkpps' && $hasPkpps) {
        return 'pkpps';
    }
    if ($rekapJenis === 'kajian') {
        return 'kajian';
    }
    if ($hasPkpps && !$hasKajian) {
        return 'pkpps';
    }

    return 'default';
}

/** @param array<string, mixed> $cfg */
function pembimbing_portal_banner_css_vars(array $cfg): string
{
    $from = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($cfg['gradient_from'] ?? '')) ? $cfg['gradient_from'] : '#0f766e';
    $via = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($cfg['gradient_via'] ?? '')) ? $cfg['gradient_via'] : '#115e59';
    $to = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($cfg['gradient_to'] ?? '')) ? $cfg['gradient_to'] : '#134e4a';
    $accent = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($cfg['accent'] ?? '')) ? $cfg['accent'] : '#2dd4bf';
    $glow = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($cfg['glow'] ?? '')) ? $cfg['glow'] : '#5eead4';

    return '--pb-banner-from:' . $from . ';--pb-banner-via:' . $via . ';--pb-banner-to:' . $to
        . ';--pb-banner-accent:' . $accent . ';--pb-banner-glow:' . $glow . ';';
}
