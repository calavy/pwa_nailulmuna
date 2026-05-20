<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/mukimin.php';
require_once __DIR__ . '/../helpers/mukimin_portal.php';
require_once __DIR__ . '/../helpers/excel.php';

require_roles(['admin', 'pengurus']);
ensure_akademik_alumni_table($pdo);
ensure_mukimin_portal_columns($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM akademik_alumni WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Data mukimin dihapus.');
        }
        header('Location: ' . mukimin_page_url([], mukimin_filters_from_post()));
        exit;
    }
    if ($action === 'simpan') {
        $id = (int) ($_POST['id'] ?? 0);
        $nis = trim((string) ($_POST['nis'] ?? ''));
        $nama = trim((string) ($_POST['nama'] ?? ''));
        if ($nis === '' || $nama === '') {
            set_flash('error', 'NIS dan nama wajib diisi.');
            header('Location: ' . mukimin_page_url($id > 0 ? ['edit' => (string) $id] : [], mukimin_filters_from_post()));
            exit;
        }
        $thMasuk = trim((string) ($_POST['th_masuk'] ?? ''));
        $thKeluar = trim((string) ($_POST['th_keluar'] ?? ''));
        $params = [
            'nis' => mb_substr($nis, 0, 32),
            'nama' => mb_substr($nama, 0, 200),
            'dusun' => mb_substr(trim((string) ($_POST['dusun'] ?? '')), 0, 120) ?: null,
            'rt_rw' => mb_substr(trim((string) ($_POST['rt_rw'] ?? '')), 0, 20) ?: null,
            'desa_kelurahan' => mb_substr(trim((string) ($_POST['desa_kelurahan'] ?? '')), 0, 120) ?: null,
            'kecamatan' => mb_substr(trim((string) ($_POST['kecamatan'] ?? '')), 0, 120) ?: null,
            'kabupaten' => mb_substr(trim((string) ($_POST['kabupaten'] ?? '')), 0, 120) ?: null,
            'propinsi' => mb_substr(trim((string) ($_POST['propinsi'] ?? '')), 0, 120) ?: null,
            'th_masuk' => alumni_parse_year_cell($thMasuk),
            'th_keluar' => alumni_parse_year_cell($thKeluar),
            'keterangan' => trim((string) ($_POST['keterangan'] ?? '')) ?: null,
            'sektor' => mb_substr(trim((string) ($_POST['sektor'] ?? '')), 0, 120) ?: null,
        ];
        if ($id > 0) {
            $params['id'] = $id;
            try {
                $pdo->prepare('
                    UPDATE akademik_alumni SET
                        nis = :nis, nama = :nama, dusun = :dusun, rt_rw = :rt_rw,
                        desa_kelurahan = :desa_kelurahan, kecamatan = :kecamatan,
                        kabupaten = :kabupaten, propinsi = :propinsi,
                        th_masuk = :th_masuk, th_keluar = :th_keluar, keterangan = :keterangan,
                        sektor = :sektor
                    WHERE id = :id
                ')->execute($params);
            } catch (PDOException $e) {
                set_flash('error', 'NIS sudah dipakai mukimin lain.');
                header('Location: ' . mukimin_page_url(['edit' => (string) $id], mukimin_filters_from_post()));
                exit;
            }
            set_flash('success', 'Data mukimin diperbarui.');
        } else {
            $params['urutan'] = alumni_next_urutan($pdo);
            $pdo->prepare('
                INSERT INTO akademik_alumni (urutan, nis, nama, dusun, rt_rw, desa_kelurahan, kecamatan, kabupaten, propinsi, th_masuk, th_keluar, keterangan, sektor)
                VALUES (:urutan, :nis, :nama, :dusun, :rt_rw, :desa_kelurahan, :kecamatan, :kabupaten, :propinsi, :th_masuk, :th_keluar, :keterangan, :sektor)
            ')->execute($params);
            set_flash('success', 'Data mukimin ditambahkan.');
        }
        header('Location: ' . mukimin_page_url([], mukimin_filters_from_post()));
        exit;
    }
}

function mukimin_render_filter_hiddens(array $filters): void
{
    foreach ($filters as $key => $value) {
        if ($value === '') {
            continue;
        }
        echo '<input type="hidden" name="_f_' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
    }
}

/** @param array<string, string> $extra */
function mukimin_page_url(array $extra = [], ?array $filters = null): string
{
    $keys = ['cari', 'dusun', 'desa_kelurahan', 'kecamatan', 'kabupaten', 'th_masuk', 'th_keluar', 'keterangan', 'edit'];
    $source = $filters ?? $_GET;
    $q = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $extra)) {
            $v = trim((string) $extra[$key]);
            if ($v !== '') {
                $q[$key] = $v;
            }
            continue;
        }
        $v = trim((string) ($source[$key] ?? ''));
        if ($v !== '') {
            $q[$key] = $v;
        }
    }
    $url = '/pwa_nailulmuna/santri/mukimin.php';

    return $q ? $url . '?' . http_build_query($q) : $url;
}

/** @return array<string, string> */
function mukimin_filters_from_post(): array
{
    return [
        'cari' => trim((string) ($_POST['_f_cari'] ?? '')),
        'dusun' => trim((string) ($_POST['_f_dusun'] ?? '')),
        'desa_kelurahan' => trim((string) ($_POST['_f_desa_kelurahan'] ?? '')),
        'kecamatan' => trim((string) ($_POST['_f_kecamatan'] ?? '')),
        'kabupaten' => trim((string) ($_POST['_f_kabupaten'] ?? '')),
        'th_masuk' => trim((string) ($_POST['_f_th_masuk'] ?? '')),
        'th_keluar' => trim((string) ($_POST['_f_th_keluar'] ?? '')),
        'keterangan' => trim((string) ($_POST['_f_keterangan'] ?? '')),
    ];
}

$filters = [
    'cari' => trim((string) ($_GET['cari'] ?? '')),
    'dusun' => trim((string) ($_GET['dusun'] ?? '')),
    'desa_kelurahan' => trim((string) ($_GET['desa_kelurahan'] ?? '')),
    'kecamatan' => trim((string) ($_GET['kecamatan'] ?? '')),
    'kabupaten' => trim((string) ($_GET['kabupaten'] ?? '')),
    'th_masuk' => trim((string) ($_GET['th_masuk'] ?? '')),
    'th_keluar' => trim((string) ($_GET['th_keluar'] ?? '')),
    'keterangan' => trim((string) ($_GET['keterangan'] ?? '')),
];
$rows = alumni_fetch_rows($pdo, $filters);
$totalAll = mukimin_count($pdo);
$total = count($rows);
$dusunOptions = alumni_distinct_alamat($pdo, 'dusun');
$desaOptions = alumni_distinct_alamat($pdo, 'desa_kelurahan');
$kecamatanOptions = alumni_distinct_alamat($pdo, 'kecamatan');
$kabupatenOptions = alumni_distinct_alamat($pdo, 'kabupaten');
$thMasukOptions = alumni_distinct_tahun($pdo, 'th_masuk');
$thKeluarOptions = alumni_distinct_tahun($pdo, 'th_keluar');
$keteranganOptions = mukimin_distinct_keterangan($pdo);
$exportQs = http_build_query(array_filter($filters, static fn(string $v): bool => $v !== ''));
$exportUrl = '/pwa_nailulmuna/santri/mukimin_export.php' . ($exportQs !== '' ? '?' . $exportQs : '');
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $e = $pdo->prepare('SELECT * FROM akademik_alumni WHERE id = :id LIMIT 1');
    $e->execute(['id' => $editId]);
    $editRow = $e->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editRow === null) {
        set_flash('error', 'Data mukimin untuk diedit tidak ditemukan.');
        header('Location: ' . mukimin_page_url(['edit' => ''], $filters));
        exit;
    }
}

$showFormPanel = $editRow !== null || (($_GET['tambah'] ?? '') === '1');

$pageTitle = 'Data Mukimin';
$pageStylesheets = ['/pwa_nailulmuna/assets/css/mukimin.css'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3 mukimin-page-intro">
    <p class="page-intro-kicker mb-1">Manajemen SDM</p>
    <h1 class="h3 mb-1">Data Mukimin</h1>
    <p class="text-muted mb-0 small">Arsip santri yang sudah non aktif (muqim/keluar). Otomatis masuk saat dinonaktifkan dari daftar santri aktif. Biodata lengkap tetap di <a href="/pwa_nailulmuna/santri/semua_jati.php">Data induk</a>.</p>
</div>

<div class="d-flex flex-wrap gap-2 mb-3 mukimin-toolbar">
    <?php if ($editRow): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('mukimin-form-panel')?.scrollIntoView({behavior:'smooth',block:'start'})">
            <i class="fa-solid fa-pen me-1"></i> Ubah mukimin
        </button>
    <?php else: ?>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#mukimin-form-panel" aria-expanded="<?= $showFormPanel ? 'true' : 'false' ?>" aria-controls="mukimin-form-panel">
            <i class="fa-solid fa-plus me-1"></i> Tambah manual
        </button>
    <?php endif; ?>
    <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($exportUrl) ?>">Unduh Excel</a>
    <a class="btn btn-outline-success btn-sm" href="/pwa_nailulmuna/santri/mukimin_import.php">Import</a>
    <a class="btn btn-outline-secondary btn-sm" href="/pwa_nailulmuna/settings/akses_mukimin.php"><i class="fa-solid fa-user-lock me-1"></i> Akses portal</a>
</div>

<div class="mb-3">
    <?php require __DIR__ . '/partials/mukimin_form.php'; ?>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?php require __DIR__ . '/partials/mukimin_filter.php'; ?>

        <?php if (!$rows): ?>
            <p class="text-muted text-center py-4 mb-0"><?= $totalAll === 0 ? 'Belum ada data mukimin. Data akan masuk otomatis saat santri dinonaktifkan dari daftar aktif, atau <a href="/pwa_nailulmuna/santri/mukimin_import.php">import Excel</a>.' : 'Tidak ada data mukimin yang cocok dengan filter.' ?></p>
        <?php else: ?>
            <div class="mukimin-mobile-list">
                <?php foreach ($rows as $r): ?>
                    <?php
                    $alamatLabel = alumni_format_alamat_label($r);
                    $thMasuk = $r['th_masuk'] !== null && $r['th_masuk'] !== '' ? (string) (int) $r['th_masuk'] : '—';
                    $thKeluar = $r['th_keluar'] !== null && $r['th_keluar'] !== '' ? (string) (int) $r['th_keluar'] : '—';
                    ?>
                    <article class="mukimin-card">
                        <div class="mukimin-card-name"><?= htmlspecialchars((string) $r['nama']) ?></div>
                        <div class="mukimin-card-nis">NIS <?= htmlspecialchars((string) $r['nis']) ?></div>
                        <dl class="mukimin-card-meta">
                            <div><dt>Th. masuk</dt><dd><?= htmlspecialchars($thMasuk) ?></dd></div>
                            <div><dt>Th. keluar</dt><dd><?= htmlspecialchars($thKeluar) ?></dd></div>
                        </dl>
                        <div class="mukimin-card-alamat"><?= htmlspecialchars($alamatLabel) ?></div>
                        <?php if (trim((string) ($r['keterangan'] ?? '')) !== ''): ?>
                            <div class="mukimin-card-alamat mt-1"><strong>Ket:</strong> <?= htmlspecialchars((string) $r['keterangan']) ?></div>
                        <?php endif; ?>
                        <div class="mukimin-card-actions">
                            <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(mukimin_page_url(['edit' => (string) $r['id']], $filters)) ?>#mukimin-form-panel">Edit</a>
                            <form method="post" class="d-inline flex-grow-1" onsubmit="return confirm('Hapus data mukimin ini?');">
                                <input type="hidden" name="action" value="hapus">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <?php mukimin_render_filter_hiddens($filters); ?>
                                <button type="submit" class="btn btn-outline-danger btn-sm w-100">Hapus</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="table-responsive mukimin-desktop-table">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>NIS</th>
                            <th>Nama</th>
                            <th>Alamat</th>
                            <th>Th. masuk</th>
                            <th>Th. keluar</th>
                            <th>Keterangan</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php $alamatLabel = alumni_format_alamat_label($r); ?>
                        <tr>
                            <td class="font-monospace small"><?= htmlspecialchars((string) $r['nis']) ?></td>
                            <td class="fw-semibold small"><?= htmlspecialchars((string) $r['nama']) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($alamatLabel) ?></td>
                            <td class="small"><?= $r['th_masuk'] !== null && $r['th_masuk'] !== '' ? (int) $r['th_masuk'] : '—' ?></td>
                            <td class="small"><?= $r['th_keluar'] !== null && $r['th_keluar'] !== '' ? (int) $r['th_keluar'] : '—' ?></td>
                            <td class="small"><?= htmlspecialchars((string) ($r['keterangan'] ?: '—')) ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(mukimin_page_url(['edit' => (string) $r['id']], $filters)) ?>#mukimin-form-panel">Edit</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Hapus data mukimin ini?');">
                                    <input type="hidden" name="action" value="hapus">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <?php mukimin_render_filter_hiddens($filters); ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($showFormPanel): ?>
<script>
(function () {
    var panel = document.getElementById('mukimin-form-panel');
    if (!panel) {
        return;
    }
    if (typeof bootstrap !== 'undefined' && !panel.classList.contains('show')) {
        bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false }).show();
    }
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
})();
</script>
<?php endif; ?>

<datalist id="mukimin-sektor-suggest">
    <?php foreach (mukimin_portal_sektor_suggest() as $ss): ?>
        <option value="<?= htmlspecialchars($ss) ?>"></option>
    <?php endforeach; ?>
</datalist>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
