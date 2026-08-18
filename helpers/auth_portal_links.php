<?php

declare(strict_types=1);

/**
 * Daftar tautan portal alternatif (wali, santri, koperasi, mukimin).
 *
 * @return list<array{href:string,label:string,short_label:string,icon:string}>
 */
function auth_portal_alt_portal_links(): array
{
    require_once __DIR__ . '/app_path.php';

    return [
        [
            'href' => app_wali_login_href(),
            'label' => 'Portal wali',
            'short_label' => 'Wali',
            'icon' => 'fa-users',
        ],
        [
            'href' => '/santri_portal/login.php',
            'label' => 'Portal santri',
            'short_label' => 'Santri',
            'icon' => 'fa-user-graduate',
        ],
        [
            'href' => '/mukimin/login.php',
            'label' => 'Mukimin',
            'short_label' => 'Mukimin',
            'icon' => 'fa-book-open',
        ],
    ];
}
