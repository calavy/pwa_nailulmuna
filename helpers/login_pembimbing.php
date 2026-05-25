<?php

declare(strict_types=1);

/**
 * Izin default akun pembimbing setelah login (Ikhtibar + perizinan pembimbing).
 */
function login_pembimbing_ensure_acl(PDO $pdo, int $userId): void
{
    if ($userId <= 0 || !table_exists($pdo, 'user_access_permissions')) {
        return;
    }
    $keys = ['akademik_ikhtibar', 'pembimbing_perizinan'];
    $st = $pdo->prepare(
        'INSERT IGNORE INTO user_access_permissions (user_id, permission_key) VALUES (:uid, :pk)'
    );
    foreach ($keys as $key) {
        $st->execute(['uid' => $userId, 'pk' => $key]);
    }
}
