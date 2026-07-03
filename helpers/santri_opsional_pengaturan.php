<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/tagihan_bulanan.php';
require_once __DIR__ . '/keuangan_dashboard.php';

/** @return array{ok:bool,message:string,redirect:string} */
function santri_opsional_pengaturan_handle_post(PDO $pdo, array $post, string $redirectBase): array
{
    ensure_keuangan_santri_opsional_table($pdo);
    $opsionalSlugs = keuangan_tagihan_opsional_bulanan_slugs();
    $slugLabel = ['makan' => 'Makan', 'saku' => 'Saku'];
    $action = (string) ($post['action'] ?? 'save_table');

    if ($action === 'save_table') {
        $idsRaw = (array) ($post['ids'] ?? []);
        $aktifIn = (array) ($post['aktif'] ?? []);
        $nominalIn = (array) ($post['nominal'] ?? []);
        $up = $pdo->prepare(
            'INSERT INTO keuangan_santri_opsional (santri_id, slug, aktif, nominal)
             VALUES (:sid, :slug, :aktif, :nominal)
             ON DUPLICATE KEY UPDATE aktif = VALUES(aktif), nominal = VALUES(nominal)'
        );
        $touched = 0;
        foreach ($idsRaw as $sidRaw) {
            $sid = (int) $sidRaw;
            if ($sid <= 0) {
                continue;
            }
            foreach ($opsionalSlugs as $slug) {
                $aktif = !empty($aktifIn[$sid][$slug]) ? 1 : 0;
                $nomRaw = trim((string) ($nominalIn[$sid][$slug] ?? ''));
                $nominal = $nomRaw === '' ? null : max(0, (int) preg_replace('/[^0-9]/', '', $nomRaw));
                $up->execute([
                    'sid' => $sid,
                    'slug' => $slug,
                    'aktif' => $aktif,
                    'nominal' => $nominal,
                ]);
                $touched++;
            }
        }
        keuangan_santri_opsional_cache_invalidate();

        return [
            'ok' => true,
            'message' => 'Pengaturan makan & saku per santri tersimpan (' . $touched . ' entri).',
            'redirect' => santri_opsional_pengaturan_build_redirect($redirectBase, $post),
        ];
    }

    if ($action === 'bulk_aktif' || $action === 'bulk_nonaktif') {
        $slug = strtolower(trim((string) ($post['slug'] ?? '')));
        $scope = strtolower(trim((string) ($post['scope'] ?? 'filter')));
        if (!in_array($slug, $opsionalSlugs, true)) {
            return ['ok' => false, 'message' => 'Slug opsional tidak valid.', 'redirect' => $redirectBase];
        }
        $aktifBulk = $action === 'bulk_aktif' ? 1 : 0;
        $ids = [];
        if ($scope === 'filter') {
            foreach ((array) ($post['ids_scope'] ?? []) as $sidRaw) {
                $sid = (int) $sidRaw;
                if ($sid > 0) {
                    $ids[$sid] = true;
                }
            }
        } else {
            $sql = 'SELECT id FROM santri';
            if (column_exists($pdo, 'santri', 'is_aktif')) {
                $sql .= ' WHERE COALESCE(is_aktif, 1) = 1';
            }
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [] as $a) {
                $ids[(int) $a] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return ['ok' => false, 'message' => 'Tidak ada santri pada lingkup aksi massal.', 'redirect' => $redirectBase];
        }
        $up = $pdo->prepare(
            'INSERT INTO keuangan_santri_opsional (santri_id, slug, aktif, nominal)
             VALUES (:sid, :slug, :aktif, NULL)
             ON DUPLICATE KEY UPDATE aktif = VALUES(aktif)'
        );
        foreach ($ids as $sid) {
            $up->execute(['sid' => $sid, 'slug' => $slug, 'aktif' => $aktifBulk]);
        }
        keuangan_santri_opsional_cache_invalidate();

        return [
            'ok' => true,
            'message' => 'Aksi massal ' . ($aktifBulk ? 'aktifkan' : 'nonaktifkan') . ' '
                . ($slugLabel[$slug] ?? $slug) . ' diterapkan ke ' . count($ids) . ' santri.',
            'redirect' => santri_opsional_pengaturan_build_redirect($redirectBase, $post),
        ];
    }

    return ['ok' => false, 'message' => 'Aksi tidak dikenali.', 'redirect' => $redirectBase];
}

function santri_opsional_pengaturan_build_redirect(string $base, array $post): string
{
    $qs = [];
    foreach (['q', 'tingkatan', 'page', 'per_page', 'tampil'] as $k) {
        $v = (string) ($post['_redir_' . $k] ?? '');
        if ($v !== '') {
            $qs[$k] = $v;
        }
    }
    if ($qs === []) {
        return $base;
    }

    return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($qs);
}

/**
 * @return array<string, mixed>
 */
function santri_opsional_pengaturan_load(PDO $pdo, array $get): array
{
    ensure_keuangan_santri_opsional_table($pdo);
    $opsionalSlugs = keuangan_tagihan_opsional_bulanan_slugs();
    $slugLabel = ['makan' => 'Makan', 'saku' => 'Saku'];
    $tarifByTier = tagihan_wajib_tarif_cache_by_tier($pdo);

    $q = trim((string) ($get['q'] ?? ''));
    $tingkatanFilter = trim((string) ($get['tingkatan'] ?? ''));
    $tampilFilter = strtolower(trim((string) ($get['tampil'] ?? 'semua')));
    $page = max(1, (int) ($get['page'] ?? 1));
    $perPage = min(200, max(25, (int) ($get['per_page'] ?? 50)));

    $where = ' WHERE 1=1';
    $params = [];
    if (column_exists($pdo, 'santri', 'is_aktif')) {
        $where .= ' AND COALESCE(is_aktif, 1) = 1';
    }
    if ($q !== '') {
        $where .= ' AND (LOWER(nama_santri) LIKE :q OR LOWER(nis) LIKE :q2)';
        $params['q'] = '%' . strtolower($q) . '%';
        $params['q2'] = '%' . strtolower($q) . '%';
    }
    if ($tingkatanFilter !== '') {
        $where .= ' AND (TRIM(COALESCE(tingkatan, \'\')) = :tk OR TRIM(COALESCE(kategori_kelas, \'\')) = :tk2)';
        $params['tk'] = $tingkatanFilter;
        $params['tk2'] = $tingkatanFilter;
    }

    $overridesMap = keuangan_santri_opsional_map_cached($pdo);

    if ($tampilFilter === 'belum_diatur') {
        $configuredIds = array_keys($overridesMap);
        if ($configuredIds !== []) {
            $placeholders = implode(',', array_fill(0, count($configuredIds), '?'));
            $where .= " AND id NOT IN ($placeholders)";
            foreach ($configuredIds as $cid) {
                $params[] = (int) $cid;
            }
        }
    } elseif ($tampilFilter === 'sudah_diatur') {
        $configuredIds = array_keys($overridesMap);
        if ($configuredIds === []) {
            $where .= ' AND 1=0';
        } else {
            $placeholders = implode(',', array_fill(0, count($configuredIds), '?'));
            $where .= " AND id IN ($placeholders)";
            foreach ($configuredIds as $cid) {
                $params[] = (int) $cid;
            }
        }
    }

    $countSql = 'SELECT COUNT(*) FROM santri' . $where;
    $countStmt = $pdo->prepare($countSql);
    $bindIdx = 1;
    $namedParams = [];
    foreach ($params as $k => $v) {
        if (is_int($k)) {
            $countStmt->bindValue($bindIdx, $v, PDO::PARAM_INT);
            $bindIdx++;
        } else {
            $namedParams[$k] = $v;
        }
    }
    foreach ($namedParams as $nk => $nv) {
        $countStmt->bindValue(':' . $nk, $nv);
    }
    $countStmt->execute();
    $totalRows = (int) ($countStmt->fetchColumn() ?: 0);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $sql = 'SELECT id, nis, nama_santri, tingkatan, kategori_kelas FROM santri'
        . $where
        . ' ORDER BY nama_santri ASC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
    $st = $pdo->prepare($sql);
    $bindIdx = 1;
    foreach ($params as $k => $v) {
        if (is_int($k)) {
            $st->bindValue($bindIdx, $v, PDO::PARAM_INT);
            $bindIdx++;
        } else {
            $st->bindValue(':' . $k, $v);
        }
    }
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $idsScope = array_map(static fn(array $r): int => (int) $r['id'], $rows);

    $kelasLabel = kelas_keuangan_label_map($pdo);
    $tingkatanOptions = [];
    try {
        $tk = $pdo->query("SELECT DISTINCT TRIM(COALESCE(tingkatan, '')) AS t FROM santri WHERE TRIM(COALESCE(tingkatan, '')) <> '' ORDER BY t");
        foreach ($tk->fetchAll(PDO::FETCH_COLUMN) ?: [] as $t) {
            $tingkatanOptions[(string) $t] = (string) $t;
        }
        $kk = $pdo->query("SELECT DISTINCT TRIM(COALESCE(kategori_kelas, '')) AS t FROM santri WHERE TRIM(COALESCE(kategori_kelas, '')) <> '' ORDER BY t");
        foreach ($kk->fetchAll(PDO::FETCH_COLUMN) ?: [] as $t) {
            $tingkatanOptions[(string) $t] = $kelasLabel[(string) $t] ?? (string) $t;
        }
    } catch (Throwable $e) {
    }

    $totalConfigured = count($overridesMap);
    $jumlahNonaktif = ['makan' => 0, 'saku' => 0];
    foreach ($overridesMap as $row) {
        foreach ($opsionalSlugs as $slug) {
            if (isset($row[$slug]) && empty($row[$slug]['aktif'])) {
                $jumlahNonaktif[$slug]++;
            }
        }
    }

    return compact(
        'opsionalSlugs',
        'slugLabel',
        'tarifByTier',
        'q',
        'tingkatanFilter',
        'tampilFilter',
        'page',
        'perPage',
        'rows',
        'idsScope',
        'kelasLabel',
        'tingkatanOptions',
        'totalConfigured',
        'jumlahNonaktif',
        'totalRows',
        'totalPages',
        'overridesMap'
    );
}
