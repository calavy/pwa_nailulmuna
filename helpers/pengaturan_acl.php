<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Satu izin `pengaturan` untuk pusat pengaturan. Migrasi sekali dari izin lama terkait.
 */
function migrate_legacy_permissions_to_pengaturan(PDO $pdo): void
{
    if (!table_exists($pdo, 'user_access_permissions') || !table_exists($pdo, 'app_settings')) {
        return;
    }
    if (app_setting($pdo, 'acl_pengaturan_migrated', '') === '1') {
        return;
    }
    $legacyKeys = ['settings_umum', 'akademik_hafalan', 'poin_settings', 'keuangan'];
    $placeholders = implode(',', array_fill(0, count($legacyKeys), '?'));
    $stmt = $pdo->prepare("SELECT DISTINCT user_id FROM user_access_permissions WHERE permission_key IN ($placeholders)");
    $stmt->execute($legacyKeys);
    $userIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $ins = $pdo->prepare('INSERT IGNORE INTO user_access_permissions (user_id, permission_key) VALUES (:uid, \'pengaturan\')');
    foreach ($userIds as $uid) {
        if ($uid > 0) {
            $ins->execute(['uid' => $uid]);
        }
    }
    save_setting($pdo, 'acl_pengaturan_migrated', '1');
}
