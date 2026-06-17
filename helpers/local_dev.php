<?php

declare(strict_types=1);

/**
 * Bootstrap ringan untuk pengembangan lokal (akun admin default).
 */
function local_dev_ensure_admin_user(PDO $pdo): ?array
{
    if (!function_exists('app_is_local_dev') || !app_is_local_dev()) {
        return null;
    }
    if (!function_exists('table_exists')) {
        require_once __DIR__ . '/../config/database.php';
    }
    if (!table_exists($pdo, 'users')) {
        return null;
    }

    try {
        $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin TINYINT(1) NOT NULL DEFAULT 0');
    } catch (PDOException $e) {
        /* abaikan */
    }

    $st = $pdo->prepare('SELECT id, nama, username, password, role, is_super_admin, foto_profil FROM users WHERE username = :u LIMIT 1');
    $st->execute(['u' => 'admin']);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        if ((int) ($row['is_super_admin'] ?? 0) !== 1) {
            $pdo->prepare('UPDATE users SET is_super_admin = 1, role = :r WHERE id = :id')
                ->execute(['r' => 'admin', 'id' => (int) $row['id']]);
            $row['is_super_admin'] = 1;
            $row['role'] = 'admin';
        }

        return $row;
    }

    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->prepare('
        INSERT INTO users (nama, username, password, role, is_super_admin)
        VALUES (:nama, :username, :password, :role, 1)
    ')->execute([
        'nama' => 'Administrator',
        'username' => 'admin',
        'password' => $hash,
        'role' => 'admin',
    ]);

    $st->execute(['u' => 'admin']);

    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
