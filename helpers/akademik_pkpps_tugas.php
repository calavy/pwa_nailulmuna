<?php

declare(strict_types=1);

require_once __DIR__ . '/akademik_ikhtibar.php';
require_once __DIR__ . '/pkpps.php';

const PKPPS_TUGAS_SUMBER = 'PKPPS';
const IKHTIBAR_TUGAS_SUMBER = 'IKHTIBAR';

function pkpps_tugas_base_path(): string
{
    return '/pkpps/tugas';
}

function pkpps_tugas_santri_base_path(): string
{
    return '/santri_portal/pkpps/tugas';
}

function pkpps_tugas_is_row(array $tugas): bool
{
    return strtoupper((string) ($tugas['sumber'] ?? IKHTIBAR_TUGAS_SUMBER)) === PKPPS_TUGAS_SUMBER;
}

function ikhtibar_tugas_is_row(array $tugas): bool
{
    return !pkpps_tugas_is_row($tugas);
}

function pkpps_tugas_require_access(): void
{
    require_once __DIR__ . '/../includes/auth.php';
    require_login();
    require_once __DIR__ . '/munawib_portal.php';
    munawib_portal_guard_halaman();
    if (is_super_admin()) {
        return;
    }
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if (in_array($role, ['admin', 'pengurus', 'pembimbing'], true)) {
        return;
    }
    global $pdo;
    if ($pdo instanceof PDO && ikhtibar_user_matches_pembimbing_nip($pdo)) {
        return;
    }
    require_once __DIR__ . '/app_path.php';
    if (user_has_current_page_permission()) {
        return;
    }
    set_flash('error', 'Anda tidak memiliki akses modul Tugas PKPPS.');
    auth_redirect_access_denied();
}

/** @return list<array<string,mixed>> */
function pkpps_tugas_list(PDO $pdo, int $userId): array
{
    return ikhtibar_tugas_list_pembimbing($pdo, $userId, PKPPS_TUGAS_SUMBER);
}

/** @return list<array<string,mixed>> */
function pkpps_tugas_tersedia_santri(PDO $pdo, int $santriId, string $tingkatan): array
{
    return ikhtibar_tugas_tersedia_santri($pdo, $santriId, $tingkatan, PKPPS_TUGAS_SUMBER);
}

/** @return list<array<string,mixed>> */
function pkpps_tugas_riwayat_santri(PDO $pdo, int $santriId): array
{
    return ikhtibar_riwayat_hasil_santri($pdo, $santriId, PKPPS_TUGAS_SUMBER);
}

/** @return list<array<string,mixed>> */
function pkpps_tugas_rekap(PDO $pdo, int $userId): array
{
    return ikhtibar_rekap_tugas_pembimbing($pdo, $userId, PKPPS_TUGAS_SUMBER);
}

/**
 * @return array{ok:bool,message:string,id?:int}
 */
function pkpps_tugas_simpan_dari_post(PDO $pdo, array $post, array $files, int $userId): array
{
    $post['sumber'] = PKPPS_TUGAS_SUMBER;
    unset($post['jadwal_kegiatan_id']);

    return ikhtibar_simpan_tugas_dari_post($pdo, $post, $files, $userId);
}

function pkpps_tugas_redirect_jika_bukan(array $tugas): void
{
    if ($tugas !== [] && !pkpps_tugas_is_row($tugas)) {
        set_flash('error', 'Tugas ini bukan modul PKPPS.');
        header('Location: ' . app_href('/pembimbing/tugas/buat.php?id=' . (int) ($tugas['id'] ?? 0)));
        exit;
    }
}

function ikhtibar_tugas_redirect_jika_pkpps(?array $tugas): void
{
    if (is_array($tugas) && pkpps_tugas_is_row($tugas)) {
        set_flash('error', 'Tugas PKPPS dikelola di menu PKPPS → Tugas.');
        header('Location: ' . app_href(pkpps_tugas_base_path() . '/buat.php?id=' . (int) ($tugas['id'] ?? 0)));
        exit;
    }
}

function santri_portal_pkpps_aktif(PDO $pdo, int $santriId): bool
{
    if ($santriId <= 0 || !table_exists($pdo, 'pkpps_santri')) {
        return false;
    }
    pkpps_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT 1 FROM pkpps_santri WHERE santri_id = :id AND is_aktif = 1 LIMIT 1');
    $st->execute(['id' => $santriId]);

    return (bool) $st->fetchColumn();
}

function santri_portal_pkpps_tugas_guard(PDO $pdo): void
{
    global $santriPortalId;
    if (!santri_portal_pkpps_aktif($pdo, (int) $santriPortalId)) {
        set_flash('error', 'Anda belum terdaftar sebagai santri PKPPS.');
        header('Location: ' . app_href('/santri_portal/index.php'));
        exit;
    }
}
