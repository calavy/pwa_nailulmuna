<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/app.php';

/** Dashboard yayasan lama — arahkan ke dashboard operasional baru. */
header('Location: ' . app_rewrite_internal_url('/yayasan/operasional.php'), true, 302);
exit;
