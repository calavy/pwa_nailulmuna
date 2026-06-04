<?php

declare(strict_types=1);

require_once __DIR__ . '/pembimbing_dashboard.php';

/** Halaman jadwal yang boleh diakses pembimbing (bukan admin penuh). */
function jadwal_pembimbing_self_service_paths(): array
{
    return [
        '/jadwal/index.php',
        '/jadwal/tambah.php',
        '/jadwal/kegiatan.php',
        '/jadwal/tambah_kegiatan.php',
    ];
}

function jadwal_require_module_access(): void
{
    require_login();
    if (is_super_admin()) {
        return;
    }

    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/app_path.php';

    $path = app_normalize_request_path((string) ($_SERVER['REQUEST_URI'] ?? ''));
    if (in_array($path, jadwal_pembimbing_self_service_paths(), true)) {
        if (user_can_access_permission_key('jadwal') || user_can_access_permission_key('pembimbing_jadwal')) {
            return;
        }
    }

    require_roles(['admin', 'pengurus']);
}

function jadwal_is_pembimbing_scope(): bool
{
    if (is_super_admin()) {
        return false;
    }
    if (user_can_access_permission_key('jadwal')) {
        return false;
    }

    return user_can_access_permission_key('pembimbing_jadwal');
}

function jadwal_current_pembimbing_id(PDO $pdo): int
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    $pembimbingInfo = pembimbing_dashboard_current_pembimbing($pdo, $userId);
    $pembimbingId = $pembimbingInfo !== null ? (int) ($pembimbingInfo['id'] ?? 0) : 0;
    if ($pembimbingId <= 0 && table_exists($pdo, 'pembimbing')) {
        $nipLogin = trim((string) ($_SESSION['user']['username'] ?? ''));
        if ($nipLogin !== '') {
            $stPbId = $pdo->prepare('SELECT id FROM pembimbing WHERE TRIM(nip) = :nip LIMIT 1');
            $stPbId->execute(['nip' => $nipLogin]);
            $pembimbingId = (int) ($stPbId->fetchColumn() ?: 0);
        }
    }

    $cached = max(0, $pembimbingId);

    return $cached;
}

function jadwal_slot_owned_by_pembimbing(PDO $pdo, int $jadwalId, int $pembimbingId): bool
{
    if ($jadwalId <= 0 || $pembimbingId <= 0) {
        return false;
    }
    $st = $pdo->prepare('SELECT pembimbing_id FROM jadwal_kegiatan WHERE id = :id LIMIT 1');
    $st->execute(['id' => $jadwalId]);
    $ownerId = (int) ($st->fetchColumn() ?: 0);

    return $ownerId === $pembimbingId;
}
