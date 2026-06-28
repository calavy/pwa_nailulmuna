<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/yayasan_musyawarah.php';

require_roles(['admin', 'pengurus']);

yayasan_musyawarah_ensure_schema($pdo);

$tab = strtoupper(trim((string) ($_GET['tab'] ?? 'YAYASAN')));
if (!in_array($tab, ['YAYASAN', 'LEMBAGA'], true)) {
    $tab = 'YAYASAN';
}
$jabatanOpsi = yayasan_sdm_jabatan_saran($pdo, $tab);
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $st = $pdo->prepare('SELECT * FROM yayasan_pengurus WHERE id = :id LIMIT 1');
    $st->execute(['id' => $editId]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editRow) {
        $tab = strtoupper((string) ($editRow['kategori'] ?? 'YAYASAN'));
        $jabatanOpsi = yayasan_sdm_jabatan_saran($pdo, $tab);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $postTab = strtoupper(trim((string) ($_POST['kategori'] ?? $tab)));
    if (!in_array($postTab, ['YAYASAN', 'LEMBAGA'], true)) {
        $postTab = 'YAYASAN';
    }

    if ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM yayasan_pengurus WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Data SDM dihapus.');
        }
        header('Location: ' . app_href('/yayasan/sdm.php?tab=' . strtolower($postTab)));
        exit;
    }
    if ($action === 'toggle_aktif') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE yayasan_pengurus SET is_aktif = IF(is_aktif = 1, 0, 1) WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Status SDM diperbarui.');
        }
        header('Location: ' . app_href('/yayasan/sdm.php?tab=' . strtolower($postTab)));
        exit;
    }
    if ($action === 'generate_qr') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $code = 'YY-' . $id;
            $pdo->prepare('UPDATE yayasan_pengurus SET qr = :qr WHERE id = :id')->execute(['qr' => $code, 'id' => $id]);
            set_flash('success', 'Kode QR dihasilkan: ' . $code);
        }
        header('Location: ' . app_href('/yayasan/sdm.php?tab=' . strtolower($postTab)));
        exit;
    }

    $nama = trim((string) ($_POST['nama'] ?? ''));
    $jabatan = yayasan_sdm_normalize_jabatan((string) ($_POST['jabatan'] ?? ''), $tab === 'LEMBAGA' ? 'Anggota' : 'Anggota');
    $periodeMulai = trim((string) ($_POST['periode_mulai'] ?? ''));
    $periodeSelesai = trim((string) ($_POST['periode_selesai'] ?? ''));
    $data = [
        'nama' => $nama,
        'jabatan' => $jabatan,
        'kategori' => $postTab,
        'lembaga_nama' => $postTab === 'LEMBAGA' ? (trim((string) ($_POST['lembaga_nama'] ?? '')) ?: null) : null,
        'qr' => trim((string) ($_POST['qr'] ?? '')) ?: null,
        'no_wa' => trim((string) ($_POST['no_wa'] ?? '')) ?: null,
        'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
        'urutan' => max(0, (int) ($_POST['urutan'] ?? 0)),
        'catatan' => trim((string) ($_POST['catatan'] ?? '')) ?: null,
        'periode_mulai' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodeMulai) ? $periodeMulai : null,
        'periode_selesai' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodeSelesai) ? $periodeSelesai : null,
    ];
    if ($nama === '') {
        set_flash('error', 'Nama wajib diisi.');
        header('Location: ' . app_href('/yayasan/sdm.php?tab=' . strtolower($postTab) . ($editId > 0 ? '&edit=' . $editId : '')));
        exit;
    }

    $idPost = (int) ($_POST['id'] ?? 0);
    if ($idPost > 0) {
        $pdo->prepare('
            UPDATE yayasan_pengurus
            SET nama = :nama, jabatan = :jabatan, kategori = :kategori, lembaga_nama = :lembaga_nama,
                qr = :qr, no_wa = :no_wa, email = :email, urutan = :urutan, catatan = :catatan,
                periode_mulai = :periode_mulai, periode_selesai = :periode_selesai
            WHERE id = :id
        ')->execute($data + ['id' => $idPost]);
        if ($data['qr'] === null) {
            $pdo->prepare('UPDATE yayasan_pengurus SET qr = :qr WHERE id = :id AND (qr IS NULL OR qr = "")')
                ->execute(['qr' => 'YY-' . $idPost, 'id' => $idPost]);
        }
        set_flash('success', 'Data SDM diperbarui.');
    } else {
        $pdo->prepare('
            INSERT INTO yayasan_pengurus (nama, jabatan, kategori, lembaga_nama, qr, no_wa, email, urutan, catatan, periode_mulai, periode_selesai)
            VALUES (:nama, :jabatan, :kategori, :lembaga_nama, :qr, :no_wa, :email, :urutan, :catatan, :periode_mulai, :periode_selesai)
        ')->execute($data);
        $newId = (int) $pdo->lastInsertId();
        if ($data['qr'] === null && $newId > 0) {
            $pdo->prepare('UPDATE yayasan_pengurus SET qr = :qr WHERE id = :id')->execute(['qr' => 'YY-' . $newId, 'id' => $newId]);
        }
        set_flash('success', 'SDM ditambahkan. Kode QR otomatis dibuat.');
    }
    if ($postTab === 'YAYASAN' && strcasecmp($jabatan, 'Ketua Yayasan') === 0 && table_exists($pdo, 'app_settings')) {
        $pdo->prepare('DELETE FROM app_settings WHERE setting_key = :k LIMIT 1')->execute(['k' => 'nama_ketua_yayasan']);
        if (function_exists('app_settings_cache_reset')) {
            app_settings_cache_reset($pdo);
        }
    }
    header('Location: ' . app_href('/yayasan/sdm.php?tab=' . strtolower($postTab)));
    exit;
}

$rows = $pdo->prepare('
    SELECT id, nama, jabatan, kategori, lembaga_nama, qr, no_wa, email, urutan, is_aktif, catatan,
           periode_mulai, periode_selesai
    FROM yayasan_pengurus
    WHERE kategori = :kat
    ORDER BY urutan ASC, jabatan ASC, nama ASC
');
$rows->execute(['kat' => $tab]);
$list = $rows->fetchAll(PDO::FETCH_ASSOC);
$total = count($list);
$aktif = count(array_filter($list, static fn(array $r): bool => (int) ($r['is_aktif'] ?? 0) === 1));


$pageTitle = 'SDM Kepengurusan';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
require_once __DIR__ . '/../includes/header.php';
$tabLower = strtolower($tab);
?>

<div class="page-intro mb-3">
    <?php $yayasanCrumbTail = 'SDM Musyawarah'; require __DIR__ . '/../includes/partials/yayasan_crumb.php'; ?>
    <h1 class="h4 mb-1">SDM Kepengurusan &amp; Lembaga</h1>
    <p class="text-muted mb-0">Data pengurus yayasan dan lembaga untuk presensi musyawarah — scan QR di halaman Scan Musyawarah (menu Yayasan).</p>
</div>

<ul class="nav nav-pills mb-3 gap-2">
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'YAYASAN' ? ' active' : '' ?>" href="<?= htmlspecialchars(app_href('/yayasan/sdm.php?tab=yayasan')) ?>">Kepengurusan Yayasan</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'LEMBAGA' ? ' active' : '' ?>" href="<?= htmlspecialchars(app_href('/yayasan/sdm.php?tab=lembaga')) ?>">Lembaga</a>
    </li>
</ul>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total <?= $tab === 'LEMBAGA' ? 'lembaga' : 'yayasan' ?></div>
            <div class="app-mini-stat-value"><?= $total ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Aktif</div>
            <div class="app-mini-stat-value text-success"><?= $aktif ?></div>
        </div>
    </div>
    <div class="col-12 col-md-6 d-flex align-items-end justify-content-md-end">
        <?php if ($list !== []): ?>
            <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/yayasan/kartu_sdm.php?kategori=' . $tabLower . '&ids=' . implode(',', array_column($list, 'id')))) ?>">
                <i class="fa-solid fa-print me-1"></i>Cetak semua kartu
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?= $editRow ? 'Ubah data' : 'Tambah data' ?></h2>
                <?php if ($editRow): ?>
                    <p class="small text-muted"><a href="<?= htmlspecialchars(app_href('/yayasan/sdm.php?tab=' . $tabLower)) ?>">← Batal edit</a></p>
                <?php endif; ?>
                <form method="post" class="row g-2">
                    <input type="hidden" name="kategori" value="<?= htmlspecialchars($tab) ?>">
                    <?php if ($editRow): ?>
                        <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label small mb-0">Nama</label>
                        <input class="form-control" name="nama" required value="<?= htmlspecialchars((string) ($editRow['nama'] ?? '')) ?>">
                    </div>
                    <?php if ($tab === 'LEMBAGA'): ?>
                    <div class="col-12">
                        <label class="form-label small mb-0">Nama lembaga</label>
                        <input class="form-control" name="lembaga_nama" placeholder="Mis. SDIT, MTs, dll." value="<?= htmlspecialchars((string) ($editRow['lembaga_nama'] ?? '')) ?>">
                    </div>
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label small mb-0">Jabatan</label>
                        <input class="form-control" name="jabatan" list="jabatanSdmList" required
                            placeholder="Ketik manual atau pilih saran"
                            value="<?= htmlspecialchars((string) ($editRow['jabatan'] ?? '')) ?>">
                        <datalist id="jabatanSdmList">
                            <?php foreach ($jabatanOpsi as $j): ?>
                                <option value="<?= htmlspecialchars($j) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <div class="form-text">Boleh diketik bebas, mis. &quot;Wakil Bidang Ta&apos;lim&quot;.</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Periode mulai</label>
                        <input type="date" class="form-control" name="periode_mulai" value="<?= htmlspecialchars((string) ($editRow['periode_mulai'] ?? '')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Periode selesai</label>
                        <input type="date" class="form-control" name="periode_selesai" value="<?= htmlspecialchars((string) ($editRow['periode_selesai'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Kode QR</label>
                        <input class="form-control font-monospace" name="qr" placeholder="Otomatis YY-{id}" value="<?= htmlspecialchars((string) ($editRow['qr'] ?? '')) ?>">
                        <div class="form-text">Kosongkan untuk kode otomatis. Dipakai di scan presensi utama.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">No. WA</label>
                        <input class="form-control" name="no_wa" value="<?= htmlspecialchars((string) ($editRow['no_wa'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= htmlspecialchars((string) ($editRow['email'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Urutan tampil</label>
                        <input type="number" class="form-control" name="urutan" min="0" value="<?= (int) ($editRow['urutan'] ?? 0) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Catatan</label>
                        <textarea class="form-control" name="catatan" rows="2"><?= htmlspecialchars((string) ($editRow['catatan'] ?? '')) ?></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success w-100"><?= $editRow ? 'Simpan perubahan' : 'Tambah' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Daftar <?= $tab === 'LEMBAGA' ? 'SDM lembaga' : 'pengurus yayasan' ?></h2>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Jabatan</th>
                                <th>QR / Periode</th>
                                <th>Status</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($list === []): ?>
                            <tr><td colspan="5" class="text-muted text-center py-4">Belum ada data.</td></tr>
                        <?php else: ?>
                            <?php foreach ($list as $row): ?>
                                <?php
                                $kodeQr = yayasan_sdm_resolve_qr($row);
                                $periode = '';
                                if (!empty($row['periode_mulai']) || !empty($row['periode_selesai'])) {
                                    $periode = ($row['periode_mulai'] ?? '?') . ' s.d. ' . ($row['periode_selesai'] ?? 'sekarang');
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['nama']) ?></strong>
                                        <?php if ($tab === 'LEMBAGA' && !empty($row['lembaga_nama'])): ?>
                                            <br><span class="small text-muted"><?= htmlspecialchars((string) $row['lembaga_nama']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string) $row['jabatan']) ?></td>
                                    <td class="small">
                                        <code><?= htmlspecialchars($kodeQr) ?></code>
                                        <?php if ($periode !== ''): ?>
                                            <br><span class="text-muted"><?= htmlspecialchars($periode) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-<?= (int) $row['is_aktif'] === 1 ? 'success' : 'secondary' ?>">
                                            <?= (int) $row['is_aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?>
                                        </span>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a class="btn btn-sm btn-outline-info" href="<?= htmlspecialchars(app_href('/yayasan/kartu_sdm.php?ids=' . (int) $row['id'])) ?>" title="Cetak kartu"><i class="fa-solid fa-print"></i></a>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/yayasan/sdm.php?tab=' . $tabLower . '&edit=' . (int) $row['id'])) ?>">Edit</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Ubah status aktif?');">
                                            <input type="hidden" name="action" value="toggle_aktif">
                                            <input type="hidden" name="kategori" value="<?= htmlspecialchars($tab) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Status</button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Hapus data ini?');">
                                            <input type="hidden" name="action" value="hapus">
                                            <input type="hidden" name="kategori" value="<?= htmlspecialchars($tab) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
