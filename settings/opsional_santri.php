<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/tagihan_bulanan.php';
require_once __DIR__ . '/../helpers/keuangan_dashboard.php';

require_roles(['admin', 'pengurus']);
ensure_keuangan_santri_opsional_table($pdo);

$opsionalSlugs = keuangan_tagihan_opsional_bulanan_slugs();
$slugLabel = ['makan' => 'Makan', 'saku' => 'Saku'];
$tarifByTier = tagihan_wajib_tarif_cache_by_tier($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save_table');
    if ($action === 'save_table') {
        $idsRaw = (array) ($_POST['ids'] ?? []);
        $aktifIn = (array) ($_POST['aktif'] ?? []);
        $nominalIn = (array) ($_POST['nominal'] ?? []);

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
        set_flash('success', 'Pengaturan opsional santri tersimpan (' . $touched . ' entri diperbarui).');
    } elseif ($action === 'bulk_aktif' || $action === 'bulk_nonaktif') {
        $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
        $scope = strtolower(trim((string) ($_POST['scope'] ?? 'filter')));
        if (!in_array($slug, $opsionalSlugs, true)) {
            set_flash('error', 'Slug opsional tidak valid.');
        } else {
            $aktifBulk = $action === 'bulk_aktif' ? 1 : 0;
            $ids = [];
            if ($scope === 'filter') {
                $idsRaw = (array) ($_POST['ids_scope'] ?? []);
                foreach ($idsRaw as $sidRaw) {
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
                $all = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
                foreach ($all as $a) {
                    $ids[(int) $a] = true;
                }
            }
            $ids = array_keys($ids);
            if ($ids === []) {
                set_flash('error', 'Tidak ada santri yang masuk lingkup aksi massal.');
            } else {
                $up = $pdo->prepare(
                    'INSERT INTO keuangan_santri_opsional (santri_id, slug, aktif, nominal)
                     VALUES (:sid, :slug, :aktif, NULL)
                     ON DUPLICATE KEY UPDATE aktif = VALUES(aktif)'
                );
                foreach ($ids as $sid) {
                    $up->execute(['sid' => $sid, 'slug' => $slug, 'aktif' => $aktifBulk]);
                }
                keuangan_santri_opsional_cache_invalidate();
                set_flash(
                    'success',
                    'Aksi massal ' . ($aktifBulk ? 'aktifkan' : 'nonaktifkan') . ' '
                    . htmlspecialchars($slugLabel[$slug] ?? $slug) . ' diterapkan ke '
                    . count($ids) . ' santri.'
                );
            }
        }
    }

    $redir = '/settings/opsional_santri.php';
    $qs = [];
    foreach (['q', 'tingkatan', 'page', 'per_page', 'tampil'] as $k) {
        $v = (string) ($_POST['_redir_' . $k] ?? '');
        if ($v !== '') {
            $qs[$k] = $v;
        }
    }
    if ($qs !== []) {
        $redir .= '?' . http_build_query($qs);
    }
    header('Location: ' . app_href($redir));
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$tingkatanFilter = trim((string) ($_GET['tingkatan'] ?? ''));
$tampilFilter = strtolower(trim((string) ($_GET['tampil'] ?? 'semua')));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(200, max(25, (int) ($_GET['per_page'] ?? 50)));

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
        $tingkatanOptions[(string) $t] = isset($kelasLabel[(string) $t]) ? (string) $kelasLabel[(string) $t] : (string) $t;
    }
} catch (Throwable $e) {
    // abaikan; biarkan kosong jika gagal
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

$pageTitle = 'Opsional Santri (Makan & Saku)';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/opsional_santri.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/menu/menu_hub.php?id=menu-grp-pengaturan">Pengaturan</a></p>
    <h1 class="h4 mb-1">Opsional Santri — Makan &amp; Saku</h1>
    <p class="text-muted mb-0">Pilih santri yang dikenakan tagihan opsional <strong>Makan</strong> dan/atau <strong>Saku</strong>. Centang aktif &amp; isi nominal khusus bila perlu; biarkan kolom nominal kosong untuk memakai tarif default tier kelas. Default sistem: aktif memakai tarif tier (kelas keuangan).</p>
</div>

<?php $flashOk = get_flash('success'); $flashErr = get_flash('error'); ?>
<?php if ($flashOk): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
    <?php foreach ($opsionalSlugs as $slug): ?>
        <div class="col-6 col-md-3">
            <div class="app-mini-stat h-100">
                <div class="app-mini-stat-label"><?= htmlspecialchars($slugLabel[$slug] ?? $slug) ?> dinonaktifkan</div>
                <div class="app-mini-stat-value text-danger"><?= (int) $jumlahNonaktif[$slug] ?></div>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Santri sudah diatur</div>
            <div class="app-mini-stat-value text-primary"><?= (int) $totalConfigured ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total ditampilkan</div>
            <div class="app-mini-stat-value"><?= (int) count($rows) ?> / <?= (int) $totalRows ?></div>
        </div>
    </div>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-md-4 col-lg-3">
        <label class="form-label small mb-0">Cari nama / NIS</label>
        <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control form-control-sm" placeholder="Nama atau NIS">
    </div>
    <div class="col-md-3 col-lg-3">
        <label class="form-label small mb-0">Tingkatan / kelas</label>
        <select name="tingkatan" class="form-select form-select-sm">
            <option value="">Semua</option>
            <?php foreach ($tingkatanOptions as $kode => $lab): ?>
                <option value="<?= htmlspecialchars($kode) ?>"<?= $tingkatanFilter === $kode ? ' selected' : '' ?>><?= htmlspecialchars($lab) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3 col-lg-2">
        <label class="form-label small mb-0">Tampilkan</label>
        <select name="tampil" class="form-select form-select-sm">
            <option value="semua"<?= $tampilFilter === 'semua' ? ' selected' : '' ?>>Semua</option>
            <option value="sudah_diatur"<?= $tampilFilter === 'sudah_diatur' ? ' selected' : '' ?>>Sudah diatur</option>
            <option value="belum_diatur"<?= $tampilFilter === 'belum_diatur' ? ' selected' : '' ?>>Belum diatur</option>
        </select>
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label small mb-0">Per hal</label>
        <select name="per_page" class="form-select form-select-sm">
            <?php foreach ([25, 50, 100, 200] as $pp): ?>
                <option value="<?= $pp ?>"<?= $perPage === $pp ? ' selected' : '' ?>><?= $pp ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm">Terapkan</button>
        <a href="?" class="btn btn-outline-secondary btn-sm">Reset</a>
    </div>
</form>

<form method="post" class="card shadow-sm mb-3">
    <input type="hidden" name="action" value="save_table">
    <input type="hidden" name="_redir_q" value="<?= htmlspecialchars($q) ?>">
    <input type="hidden" name="_redir_tingkatan" value="<?= htmlspecialchars($tingkatanFilter) ?>">
    <input type="hidden" name="_redir_tampil" value="<?= htmlspecialchars($tampilFilter) ?>">
    <input type="hidden" name="_redir_page" value="<?= (int) $page ?>">
    <input type="hidden" name="_redir_per_page" value="<?= (int) $perPage ?>">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">NIS</th>
                        <th>Nama</th>
                        <th>Tingkatan / kelas</th>
                        <?php foreach ($opsionalSlugs as $slug): ?>
                            <th class="text-center"><?= htmlspecialchars($slugLabel[$slug] ?? $slug) ?></th>
                            <th class="text-end" style="min-width:8rem">Nominal <?= htmlspecialchars($slugLabel[$slug] ?? $slug) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="<?= 3 + 2 * count($opsionalSlugs) ?>" class="text-center text-muted py-4">Tidak ada santri pada filter ini.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $sid = (int) $r['id'];
                    $kelas = trim((string) ($r['kategori_kelas'] ?? ''));
                    if ($kelas === '') {
                        $kelas = trim((string) ($r['tingkatan'] ?? ''));
                    }
                    $tier = keuangan_tier_key_from_kelas($kelas, $pdo);
                    $labelKelas = $kelasLabel[$kelas] ?? $kelas;
                    ?>
                    <tr>
                        <td class="ps-3 font-monospace small"><?= htmlspecialchars((string) ($r['nis'] ?? '—')) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($labelKelas !== '' ? $labelKelas : '—') ?><br><span class="badge bg-light text-muted text-uppercase"><?= htmlspecialchars($tier) ?></span></td>
                        <?php foreach ($opsionalSlugs as $slug):
                            $entry = $overridesMap[$sid][$slug] ?? null;
                            $aktif = $entry === null ? true : (bool) $entry['aktif'];
                            $nomVal = $entry['nominal'] ?? null;
                            $tierTarif = max(0, (int) ($tarifByTier[$slug][$tier] ?? 0));
                            ?>
                            <td class="text-center">
                                <input type="hidden" name="ids[]" value="<?= $sid ?>">
                                <div class="form-check form-switch d-inline-block">
                                    <input type="checkbox" class="form-check-input" name="aktif[<?= $sid ?>][<?= htmlspecialchars($slug) ?>]" value="1" <?= $aktif ? 'checked' : '' ?>>
                                </div>
                            </td>
                            <td class="text-end">
                                <input type="number" min="0" step="500" inputmode="numeric"
                                    class="form-control form-control-sm text-end"
                                    name="nominal[<?= $sid ?>][<?= htmlspecialchars($slug) ?>]"
                                    value="<?= $nomVal === null ? '' : (int) $nomVal ?>"
                                    placeholder="<?= $tierTarif > 0 ? 'Default ' . number_format($tierTarif, 0, ',', '.') : 'belum diatur' ?>">
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($rows !== []): ?>
        <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
            <button type="submit" class="btn btn-primary btn-sm">Simpan halaman ini</button>
            <span class="small text-muted">Centang untuk mengaktifkan tagihan; nominal kosong = pakai default tier kelas.</span>
        </div>
    <?php endif; ?>
</form>

<?php if ($idsScope !== []): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 mb-2">Aksi massal</h2>
            <p class="small text-muted mb-2">Pengaturan cepat untuk semua santri pada lingkup yang dipilih. Untuk perubahan nominal khusus, gunakan tabel di atas.</p>
            <div class="row g-3">
                <?php foreach ($opsionalSlugs as $slug): ?>
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <h3 class="h6 mb-2"><?= htmlspecialchars($slugLabel[$slug] ?? $slug) ?></h3>
                            <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
                                <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
                                <input type="hidden" name="_redir_q" value="<?= htmlspecialchars($q) ?>">
                                <input type="hidden" name="_redir_tingkatan" value="<?= htmlspecialchars($tingkatanFilter) ?>">
                                <input type="hidden" name="_redir_tampil" value="<?= htmlspecialchars($tampilFilter) ?>">
                                <input type="hidden" name="_redir_page" value="<?= (int) $page ?>">
                                <input type="hidden" name="_redir_per_page" value="<?= (int) $perPage ?>">
                                <?php foreach ($idsScope as $sid): ?>
                                    <input type="hidden" name="ids_scope[]" value="<?= (int) $sid ?>">
                                <?php endforeach; ?>
                                <div>
                                    <label class="form-label small mb-0">Lingkup</label>
                                    <select name="scope" class="form-select form-select-sm">
                                        <option value="filter">Hasil filter halaman ini (<?= count($idsScope) ?>)</option>
                                        <option value="all_active">Semua santri aktif</option>
                                    </select>
                                </div>
                                <button type="submit" name="action" value="bulk_aktif" class="btn btn-success btn-sm" onclick="return confirm('Aktifkan <?= htmlspecialchars($slugLabel[$slug] ?? $slug) ?> untuk semua santri pada lingkup?');">Aktifkan</button>
                                <button type="submit" name="action" value="bulk_nonaktif" class="btn btn-outline-danger btn-sm" onclick="return confirm('Nonaktifkan <?= htmlspecialchars($slugLabel[$slug] ?? $slug) ?> untuk semua santri pada lingkup?');">Nonaktifkan</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($totalPages > 1):
    $pageBase = ['per_page' => $perPage];
    if ($q !== '') {
        $pageBase['q'] = $q;
    }
    if ($tingkatanFilter !== '') {
        $pageBase['tingkatan'] = $tingkatanFilter;
    }
    if ($tampilFilter !== 'semua') {
        $pageBase['tampil'] = $tampilFilter;
    }
    ?>
    <nav class="mt-2 d-flex flex-wrap justify-content-center gap-1" aria-label="Halaman opsional santri">
        <?php if ($page > 1): $prev = $pageBase; $prev['page'] = $page - 1; ?>
            <a class="btn btn-sm btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query($prev)) ?>">«</a>
        <?php endif;
        $startP = max(1, $page - 2);
        $endP = min($totalPages, $startP + 4);
        $startP = max(1, $endP - 4);
        for ($p = $startP; $p <= $endP; $p++):
            $pq = $pageBase;
            $pq['page'] = $p;
            ?>
            <a class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?<?= htmlspecialchars(http_build_query($pq)) ?>"><?= $p ?></a>
        <?php endfor;
        if ($page < $totalPages): $next = $pageBase; $next['page'] = $page + 1; ?>
            <a class="btn btn-sm btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query($next)) ?>">»</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
