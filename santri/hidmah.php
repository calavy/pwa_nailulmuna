<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_riwayat.php';

require_roles(['admin', 'pengurus']);
ensure_santri_riwayat_tables($pdo);

$q = trim((string) ($_GET['q'] ?? ''));
$santriId = (int) ($_GET['santri_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $santriId = (int) ($_POST['santri_id'] ?? 0);
    if ($santriId <= 0) {
        set_flash('error', 'Pilih santri terlebih dahulu.');
    } elseif (santri_riwayat_hidmah_save($pdo, $_POST, $santriId)) {
        set_flash('success', 'Data hidmah berhasil disimpan.');
        header('Location: /santri/riwayat.php?id=' . $santriId . '&tab=hidmah');
        exit;
    } else {
        set_flash('error', 'Nama hidmah wajib diisi.');
    }
}

$santriList = [];
if ($q !== '') {
    $st = $pdo->prepare('
        SELECT id, nis, nama_santri, tingkatan
        FROM santri
        WHERE LOWER(nama_santri) LIKE :q OR LOWER(nis) LIKE :q2
        ORDER BY nama_santri ASC
        LIMIT 30
    ');
    $st->execute(['q' => '%' . strtolower($q) . '%', 'q2' => '%' . strtolower($q) . '%']);
    $santriList = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$selected = null;
if ($santriId > 0) {
    $st = $pdo->prepare('SELECT id, nis, nama_santri, tingkatan FROM santri WHERE id = :id');
    $st->execute(['id' => $santriId]);
    $selected = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$recentHidmah = $pdo->query('
    SELECT h.*, s.nis, s.nama_santri
    FROM santri_riwayat_hidmah h
    INNER JOIN santri s ON s.id = h.santri_id
    ORDER BY h.created_at DESC
    LIMIT 50
')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$taAktif = santri_tahun_ajaran_for_date($pdo);

$pageTitle = 'Input hidmah santri';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <p class="page-intro-kicker mb-1">Manajemen SDM</p>
        <h1 class="h3 mb-1">Input hidmah santri</h1>
        <p class="text-muted small mb-0">Catat peran hidmah, pengurus santri, atau pembantu usaha pondok per tahun ajaran.</p>
    </div>
    <a href="/santri/semua_jati.php" class="btn btn-outline-secondary btn-sm">Data induk</a>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header py-2"><strong>1. Pilih santri</strong></div>
            <div class="card-body">
                <form method="get" class="row g-2 mb-3">
                    <div class="col-8">
                        <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control form-control-sm" placeholder="Cari nama atau NIS">
                    </div>
                    <div class="col-4">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Cari</button>
                    </div>
                </form>
                <?php if ($santriList !== []): ?>
                <div class="list-group list-group-flush border rounded mb-0" style="max-height:220px;overflow:auto">
                    <?php foreach ($santriList as $s): ?>
                    <a href="/santri/hidmah.php?santri_id=<?= (int) $s['id'] ?>&q=<?= urlencode($q) ?>"
                       class="list-group-item list-group-item-action py-2<?= $santriId === (int) $s['id'] ? ' active' : '' ?>">
                        <span class="fw-semibold"><?= htmlspecialchars((string) $s['nama_santri']) ?></span>
                        <span class="text-muted small"> · <?= htmlspecialchars((string) $s['nis']) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php elseif ($q !== ''): ?>
                <p class="text-muted small mb-0">Tidak ditemukan.</p>
                <?php endif; ?>

                <?php if ($selected): ?>
                <div class="alert alert-success py-2 mt-3 mb-0 small">
                    Terpilih: <strong><?= htmlspecialchars((string) $selected['nama_santri']) ?></strong>
                    (<?= htmlspecialchars((string) $selected['nis']) ?>)
                    · <a href="/santri/riwayat.php?id=<?= (int) $selected['id'] ?>">Lihat riwayat lengkap</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($selected): ?>
        <div class="card shadow-sm mt-3">
            <div class="card-header py-2"><strong>2. Data hidmah</strong></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="santri_id" value="<?= (int) $selected['id'] ?>">
                    <div class="col-12">
                        <label class="form-label">Jenis peran</label>
                        <select name="jenis_peran" class="form-select form-select-sm" required>
                            <?php foreach (santri_hidmah_jenis_options() as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars(santri_hidmah_jenis_label($opt)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Nama hidmah / jabatan</label>
                        <input type="text" name="nama_hidmah" class="form-control form-control-sm" required placeholder="Mis. Pengurus asrama, Toko pondok">
                    </div>
                    <div class="col-6">
                        <label class="form-label">TA mulai</label>
                        <input type="number" name="tahun_ajaran_mulai" class="form-control form-control-sm" min="2000" max="2100" value="<?= (int) $taAktif['mulai'] ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">TA selesai (ops.)</label>
                        <input type="number" name="tahun_ajaran_selesai" class="form-control form-control-sm" min="2000" max="2100">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Keterangan</label>
                        <textarea name="keterangan" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Simpan hidmah</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header py-2"><strong>Entri hidmah terbaru</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Santri</th>
                                <th>Jenis</th>
                                <th>Nama hidmah</th>
                                <th>TA</th>
                                <th class="text-end pe-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentHidmah as $rh): ?>
                            <tr>
                                <td class="ps-3">
                                    <span class="fw-semibold"><?= htmlspecialchars((string) $rh['nama_santri']) ?></span>
                                    <span class="text-muted small d-block"><?= htmlspecialchars((string) $rh['nis']) ?></span>
                                </td>
                                <td class="small"><?= htmlspecialchars(santri_hidmah_jenis_label((string) $rh['jenis_peran'])) ?></td>
                                <td><?= htmlspecialchars((string) $rh['nama_hidmah']) ?></td>
                                <td class="small"><?= (int) $rh['tahun_ajaran_mulai'] ?>/<?= !empty($rh['tahun_ajaran_selesai']) ? (int) $rh['tahun_ajaran_selesai'] : ((int) $rh['tahun_ajaran_mulai'] + 1) ?></td>
                                <td class="text-end pe-3">
                                    <a href="/santri/riwayat.php?id=<?= (int) $rh['santri_id'] ?>&tab=hidmah" class="btn btn-outline-primary btn-sm">Riwayat</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($recentHidmah === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Belum ada data hidmah.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
