<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/app.php';

header('Location: ' . app_href('/pembayaran/kartu_syahriyah_santri.php'), true, 302);
exit;
