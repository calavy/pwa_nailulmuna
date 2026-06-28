<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/akademik_rapor.php';
require_once __DIR__ . '/../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';
require_once __DIR__ . '/../helpers/santri_list_sort.php';
require_once __DIR__ . '/../helpers/pkpps.php';

pkpps_ensure_schema($pdo);
ensure_akademik_ikhtibar_tables($pdo);
santri_list_sort_mode($_GET['santri_sort'] ?? null);

require_roles(['admin', 'pengurus']);
ensure_santri_identity_columns($pdo);
ensure_akademik_rapor_columns($pdo);

$raporJenis = 'pkpps';
$raporBasePath = '/pkpps/rapor.php';
$raporPageKicker = '<a href="' . htmlspecialchars(app_href('/pkpps/index.php')) . '">PKPPS</a> · Rapor';
$raporPageTitle = 'Rapor PKPPS';
$raporPageDesc = 'Rapor program PKPPS untuk santri terdaftar PKPPS: keaktivan, setoran, dan nilai tugas PKPPS.';
$raporSettingsPath = '/pkpps/pengaturan_rapor.php';
$raporSettingsLabel = 'Pengaturan rapor PKPPS';
$raporExtraIntroHtml = 'Rapor pesantren umum ada di <a href="' . htmlspecialchars(app_href('/akademik/rapor.php')) . '">Akademik → Rapor Pesantren</a>.';

require __DIR__ . '/../includes/partials/rapor_admin_module.php';
