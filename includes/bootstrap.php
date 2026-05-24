<?php

declare(strict_types=1);

/**
 * Bootstrap web standar — penataan request yang benar untuk respons cepat.
 *
 * Lapisan (urutan):
 * 1. Session + output buffer (ringan)
 * 2. Database PDO saja (tanpa migrasi CREATE TABLE)
 * 3. Helper inti app.php
 * 4. Auth (opsional)
 * 5. Skema deferred + ACL + maintenance — sekali per sesi / modul ringan (via header)
 *
 * Halaman cukup: require bootstrap → require helper modul → logika halaman → header/footer.
 */

/**
 * @param array{
 *   auth?: bool,
 *   roles?: list<string>|null,
 *   helpers?: list<string>
 * } $options
 */
function pondok_bootstrap(array $options = []): PDO
{
    require_once __DIR__ . '/../config/session.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../helpers/app.php';

    $pdo = pondok_pdo();

    $needAuth = $options['auth'] ?? true;
    if ($needAuth) {
        require_once __DIR__ . '/auth.php';
        if (isset($options['roles']) && is_array($options['roles']) && $options['roles'] !== []) {
            require_roles($options['roles']);
        } else {
            require_login();
        }
    }

    foreach ($options['helpers'] ?? [] as $helperFile) {
        $path = __DIR__ . '/../helpers/' . ltrim((string) $helperFile, '/');
        if (is_file($path)) {
            require_once $path;
        }
    }

    return $pdo;
}
