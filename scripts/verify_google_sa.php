<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/google_sheets_client.php';

$path = __DIR__ . '/../config/google_service_account.json';

try {
    $credentials = google_sa_load_credentials($path);
    $token = google_sa_access_token($credentials);
    echo (strlen($token) > 20 ? 'gcp_token_ok' : 'gcp_token_fail') . PHP_EOL;
} catch (Throwable $e) {
    echo 'error=' . $e->getMessage() . PHP_EOL;
    exit(1);
}
