<?php

declare(strict_types=1);

/** TTL cache menu pembimbing (detik). */
const PB_MENU_CACHE_TTL = 300;

/**
 * @return array{setoran_portal_ok:bool,pkpps_has_jadwal:bool,pembimbing_id:int,cached_at:int}|null
 */
function pembimbing_menu_cache_get(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $key = 'pb_menu_flags_v1_' . $userId;
    $cached = $_SESSION[$key] ?? null;
    if (!is_array($cached)) {
        return null;
    }
    $at = (int) ($cached['cached_at'] ?? 0);
    if ($at <= 0 || (time() - $at) > PB_MENU_CACHE_TTL) {
        unset($_SESSION[$key]);

        return null;
    }

    return $cached;
}

/** @param array{setoran_portal_ok:bool,pkpps_has_jadwal:bool,pembimbing_id:int} $flags */
function pembimbing_menu_cache_set(int $userId, array $flags): void
{
    if ($userId <= 0) {
        return;
    }
    $_SESSION['pb_menu_flags_v1_' . $userId] = [
        'setoran_portal_ok' => !empty($flags['setoran_portal_ok']),
        'pkpps_has_jadwal' => !empty($flags['pkpps_has_jadwal']),
        'pembimbing_id' => (int) ($flags['pembimbing_id'] ?? 0),
        'cached_at' => time(),
    ];
}

function pembimbing_menu_cache_invalidate(int $userId): void
{
    if ($userId > 0) {
        unset($_SESSION['pb_menu_flags_v1_' . $userId]);
    }
}

/**
 * @return array{setoran_portal_ok:bool,pkpps_has_jadwal:bool,pembimbing_id:int}
 */
function pembimbing_menu_cache_resolve(PDO $pdo, int $userId, bool $forceRefresh = false): array
{
    if (!$forceRefresh) {
        $cached = pembimbing_menu_cache_get($userId);
        if ($cached !== null) {
            return [
                'setoran_portal_ok' => !empty($cached['setoran_portal_ok']),
                'pkpps_has_jadwal' => !empty($cached['pkpps_has_jadwal']),
                'pembimbing_id' => (int) ($cached['pembimbing_id'] ?? 0),
            ];
        }
    }

    require_once __DIR__ . '/akademik_setoran.php';
    require_once __DIR__ . '/pembimbing_dashboard.php';
    require_once __DIR__ . '/pembimbing_pkpps.php';

    $pbInfo = $userId > 0 ? pembimbing_dashboard_current_pembimbing($pdo, $userId) : null;
    $pbId = is_array($pbInfo) ? (int) ($pbInfo['id'] ?? 0) : 0;
    $setoranSt = akademik_setoran_portal_access_status($pdo);
    $flags = [
        'setoran_portal_ok' => !empty($setoranSt['ok']),
        'pkpps_has_jadwal' => $pbId > 0 && pembimbing_pkpps_has_jadwal($pdo, $pbId),
        'pembimbing_id' => $pbId,
    ];
    pembimbing_menu_cache_set($userId, $flags);

    return $flags;
}
