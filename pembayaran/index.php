<?php

declare(strict_types=1);

/** Halaman hub bendahara lama — arahkan ke dashboard keuangan tunggal. */
header('Location: ' . app_rewrite_internal_url('/keuangan/index.php'), true, 302);
exit;
