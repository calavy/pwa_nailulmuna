<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pondok_ta.php';
require_once __DIR__ . '/../helpers/santri_riwayat.php';
require_once __DIR__ . '/../helpers/santri_ta.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/santri_list_sort.php';

require_roles(['admin', 'pengurus']);
santri_list_sort_mode($_GET['santri_sort'] ?? null);
ensure_santri_riwayat_tables($pdo);

$pondokTa = pondok_ta_resolve($pdo);
$tahunAjaranMulai = (int) $pondokTa['mulai'];
$tahunAjaranSelesai = (int) $pondokTa['selesai'];
$q = trim((string) ($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $tmPost = (int) ($_POST['tahun_ajaran_mulai'] ?? $tahunAjaranMulai);
    $tsPost = (int) ($_POST['tahun_ajaran_selesai'] ?? $tahunAjaranSelesai);

    if ($action === 'salin_ta') {
        $res = santri_tingkatan_salin_dari_ta_sebelumnya($pdo, $tmPost, $tsPost, false);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($action === 'salin_naik') {
        $res = santri_tingkatan_salin_dari_ta_sebelumnya($pdo, $tmPost, $tsPost, true);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($action === 'simpan_grid') {
        $rows = $_POST['ting'] ?? [];
        $payload = [];
        if (is_array($rows)) {
            foreach ($rows as $sid => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $payload[(int) $sid] = [
                    'tingkatan' => trim((string) ($row['tingkatan'] ?? '')),
                    'kategori_kelas' => trim((string) ($row['kategori_kelas'] ?? '')),
                    'status_akademik' => trim((string) ($row['status_akademik'] ?? 'BERJALAN')),
                    'wali_kelas' => trim((string) ($row['wali_kelas'] ?? '')),
                    'catatan' => trim((string) ($row['catatan'] ?? '')),
                ];
            }
        }
        $res = santri_tingkatan_bulk_save($pdo, $tmPost, $tsPost, $payload);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        if ($res['ok'] && function_exists('keuangan_dashboard_cache_invalidate')) {
            require_once __DIR__ . '/../helpers/keuangan_dashboard.php';
            keuangan_dashboard_cache_invalidate();
        }
    }

    $redir = '/santri/tingkatan_ta.php';
    if ($q !== '') {
        $redir .= '?q=' . rawurlencode($q);
    }
    header('Location: ' . app_href($redir));
    exit;
}

$tingkatanMap = santri_tingkatan_map_for_ta($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
$tingkatanMaster = [];
if (table_exists($pdo, 'tingkatan')) {
    $tingkatanMaster = $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
}
$kelasOpts = kelas_keuangan_all_rows($pdo);
$statusOpts = santri_riwayat_status_akademik_options();

$sql = 'SELECT id, nis, nama_santri, tingkatan, kategori_kelas FROM santri';
if (column_exists($pdo, 'santri', 'is_aktif')) {
    $sql .= ' WHERE COALESCE(is_aktif, 1) = 1';
}
$sql .= ' ORDER BY ' . santri_list_order_sql('santri');
$santriRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Tingkatan per Tahun Ajaran';
$bodyClass = 'santri-module-page';
require_once __DIR__ . '/../includes/header.php';

$err = get_flash('error');
$ok = get_flash('success');
?>
<?php require __DIR__ . '/../includes/partials/santri_sort_toolbar.php'; ?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><i class="fa-solid fa-layer-group me-1"></i> Data Santri</p>
    <h1 class="h4 page-intro-title mb-1">Tingkatan per Tahun Ajaran</h1>
    <p class="text-muted small mb-0">Kelas/tingkatan per tahun ajaran aktif pondok. Mengikuti pengaturan di <a href="<?= htmlspecialchars(pondok_ta_central_settings_href()) ?>">Keuangan → Umum &amp; periode</a>.</p>
</div>

<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<?php
$pondokTa = $pondokTa;
$pondokTaSettingsHref = pondok_ta_central_settings_href();
require __DIR__ . '/../includes/partials/pondok_ta_toolbar.php';
?>

<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="tm" value="<?= $tahunAjaranMulai ?>">
            <input type="hidden" name="ts" value="<?= $tahunAjaranSelesai ?>">
            <div class="col-md-6">
                <label class="form-label small mb-0">Cari santri</label>
                <input type="search" name="q" class="form-control form-control-sm" value="<?= htmlspecialchars($q) ?>" placeholder="NIS atau nama">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <form method="post" class="d-inline" onsubmit="return confirm('Salin tingkatan dari TA sebelumnya ke TA ini?');">
        <input type="hidden" name="action" value="salin_ta">
        <input type="hidden" name="tahun_ajaran_mulai" value="<?= $tahunAjaranMulai ?>">
        <input type="hidden" name="tahun_ajaran_selesai" value="<?= $tahunAjaranSelesai ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-copy me-1"></i> Salin dari TA lalu</button>
    </form>
    <form method="post" class="d-inline" onsubmit="return confirm('Salin dan naik satu tingkatan master untuk semua yang punya urutan di master?');">
        <input type="hidden" name="action" value="salin_naik">
        <input type="hidden" name="tahun_ajaran_mulai" value="<?= $tahunAjaranMulai ?>">
        <input type="hidden" name="tahun_ajaran_selesai" value="<?= $tahunAjaranSelesai ?>">
        <button type="submit" class="btn btn-sm btn-outline-warning"><i class="fa-solid fa-arrow-up me-1"></i> Salin + naik tingkatan</button>
    </form>
    <a href="<?= htmlspecialchars(app_href('/santri/semua_jati.php')) ?>" class="btn btn-sm btn-link ms-auto">Data induk santri</a>
</div>

<form method="post">
    <input type="hidden" name="action" value="simpan_grid">
    <input type="hidden" name="tahun_ajaran_mulai" value="<?= $tahunAjaranMulai ?>">
    <input type="hidden" name="tahun_ajaran_selesai" value="<?= $tahunAjaranSelesai ?>">
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center py-2">
            <span class="fw-semibold small"><?= htmlspecialchars($pondokTa['label'] ?? '') ?> — <?= count($santriRows) ?> santri aktif</span>
            <button type="submit" class="btn btn-sm btn-success"><i class="fa-solid fa-save me-1"></i> Simpan perubahan</button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>NIS</th>
                        <th>Nama</th>
                        <th>Tingkatan TA</th>
                        <th>Kelas keuangan</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $shown = 0;
                foreach ($santriRows as $s):
                    $sid = (int) $s['id'];
                    $cari = strtolower((string) ($s['nama_santri'] ?? '') . ' ' . (string) ($s['nis'] ?? ''));
                    if ($q !== '' && !str_contains($cari, strtolower($q))) {
                        continue;
                    }
                    $shown++;
                    $riw = $tingkatanMap[$sid] ?? null;
                    $tingVal = $riw['tingkatan'] ?? trim((string) ($s['tingkatan'] ?? ''));
                    $katVal = $riw['kategori_kelas'] ?? trim((string) ($s['kategori_kelas'] ?? ''));
                    $stAk = $riw['status_akademik'] ?? 'BERJALAN';
                    ?>
                    <tr>
                        <td class="text-nowrap small"><?= htmlspecialchars((string) ($s['nis'] ?? '')) ?></td>
                        <td>
                            <a href="<?= htmlspecialchars(app_href('/santri/riwayat.php?id=' . $sid . '&tab=tingkatan')) ?>" class="text-decoration-none"><?= htmlspecialchars((string) ($s['nama_santri'] ?? '')) ?></a>
                        </td>
                        <td>
                            <input type="text" name="ting[<?= $sid ?>][tingkatan]" class="form-control form-control-sm" list="dl-tingkatan" value="<?= htmlspecialchars($tingVal) ?>" required>
                        </td>
                        <td>
                            <select name="ting[<?= $sid ?>][kategori_kelas]" class="form-select form-select-sm">
                                <option value="">—</option>
                                <?php foreach ($kelasOpts as $kk): ?>
                                    <?php $kode = trim((string) ($kk['kode'] ?? '')); ?>
                                    <option value="<?= htmlspecialchars($kode) ?>" <?= strcasecmp($katVal, $kode) === 0 ? 'selected' : '' ?>><?= htmlspecialchars((string) ($kk['nama_tampilan'] ?? $kode)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="ting[<?= $sid ?>][status_akademik]" class="form-select form-select-sm">
                                <?php foreach ($statusOpts as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= $stAk === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($shown === 0): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4 small">Tidak ada santri untuk filter ini.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($shown > 0): ?>
        <div class="card-footer py-2 text-end">
            <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-save me-1"></i> Simpan</button>
        </div>
        <?php endif; ?>
    </div>
</form>

<datalist id="dl-tingkatan">
    <?php foreach ($tingkatanMaster as $t): ?>
        <option value="<?= htmlspecialchars((string) $t) ?>">
    <?php endforeach; ?>
</datalist>

<script src="<?= htmlspecialchars(app_href('/assets/js/pondok-ta-fields.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
