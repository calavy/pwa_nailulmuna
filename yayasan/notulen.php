<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/yayasan_timeline.php';

require_roles(['admin', 'pengurus']);

yayasan_ensure_tables($pdo);

$filterRapatId = (int) ($_GET['rapat_id'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;

$rapatList = $pdo->query('
    SELECT id, judul, tanggal_rapat, nomor_rapat
    FROM yayasan_rapat
    ORDER BY tanggal_rapat DESC, id DESC
')->fetchAll(PDO::FETCH_ASSOC);

if ($editId > 0) {
    $st = $pdo->prepare('
        SELECT n.*, r.judul AS rapat_judul, r.tanggal_rapat
        FROM yayasan_notulen n
        INNER JOIN yayasan_rapat r ON r.id = n.rapat_id
        WHERE n.id = :id LIMIT 1
    ');
    $st->execute(['id' => $editId]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editRow) {
        $filterRapatId = (int) $editRow['rapat_id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $taskIds = $pdo->prepare('SELECT id FROM yayasan_tugas WHERE notulen_id = :id');
            $taskIds->execute(['id' => $id]);
            foreach ($taskIds->fetchAll(PDO::FETCH_COLUMN) ?: [] as $tid) {
                yayasan_tugas_delete($pdo, (int) $tid);
            }
            $pdo->prepare('DELETE FROM yayasan_notulen WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Notulen dihapus.');
        }
        header('Location: ' . app_href('/yayasan/notulen.php' . ($filterRapatId > 0 ? '?rapat_id=' . $filterRapatId : '')));
        exit;
    }

    $rapatId = (int) ($_POST['rapat_id'] ?? 0);
    $judul = trim((string) ($_POST['judul'] ?? ''));
    if ($rapatId <= 0) {
        set_flash('error', 'Pilih rapat terlebih dahulu.');
        header('Location: ' . app_href('/yayasan/notulen.php'));
        exit;
    }

    $chk = $pdo->prepare('SELECT id FROM yayasan_rapat WHERE id = :id LIMIT 1');
    $chk->execute(['id' => $rapatId]);
    if (!$chk->fetchColumn()) {
        set_flash('error', 'Rapat tidak ditemukan.');
        header('Location: ' . app_href('/yayasan/notulen.php'));
        exit;
    }

    $data = [
        'rapat_id' => $rapatId,
        'judul' => $judul !== '' ? $judul : null,
        'isi' => trim((string) ($_POST['isi'] ?? '')) ?: null,
        'ringkasan' => trim((string) ($_POST['ringkasan'] ?? '')) ?: null,
        'keputusan' => trim((string) ($_POST['keputusan'] ?? '')) ?: null,
        'tindak_lanjut' => trim((string) ($_POST['tindak_lanjut'] ?? '')) ?: null,
        'hadir' => trim((string) ($_POST['hadir'] ?? '')) ?: null,
        'diinput_oleh' => (int) ($_SESSION['user']['id'] ?? 0) ?: null,
    ];

    $idPost = (int) ($_POST['id'] ?? 0);
    $rapatRow = $pdo->prepare('SELECT tanggal_rapat FROM yayasan_rapat WHERE id = :id LIMIT 1');
    $rapatRow->execute(['id' => $rapatId]);
    $rapatDate = (string) ($rapatRow->fetchColumn() ?: date('Y-m-d'));
    $userId = (int) ($_SESSION['user']['id'] ?? 0);

    if ($idPost > 0) {
        $pdo->prepare('
            UPDATE yayasan_notulen
            SET rapat_id = :rapat_id, judul = :judul, isi = :isi, ringkasan = :ringkasan,
                keputusan = :keputusan, tindak_lanjut = :tindak_lanjut, hadir = :hadir,
                diinput_oleh = :diinput_oleh
            WHERE id = :id
        ')->execute($data + ['id' => $idPost]);
        yayasan_tugas_sync_from_notulen($pdo, $idPost, $rapatId, (string) ($data['tindak_lanjut'] ?? ''), $rapatDate, $userId);
        set_flash('success', 'Notulen diperbarui. Tugas timeline disinkronkan.');
    } else {
        $pdo->prepare('
            INSERT INTO yayasan_notulen (rapat_id, judul, isi, ringkasan, keputusan, tindak_lanjut, hadir, diinput_oleh)
            VALUES (:rapat_id, :judul, :isi, :ringkasan, :keputusan, :tindak_lanjut, :hadir, :diinput_oleh)
        ')->execute($data);
        $newId = (int) $pdo->lastInsertId();
        $nTask = yayasan_tugas_sync_from_notulen($pdo, $newId, $rapatId, (string) ($data['tindak_lanjut'] ?? ''), $rapatDate, $userId);
        set_flash('success', 'Notulen disimpan.' . ($nTask > 0 ? ' ' . $nTask . ' tugas timeline tercatat.' : ''));
    }
    header('Location: ' . app_href('/yayasan/notulen.php?rapat_id=' . $rapatId));
    exit;
}

$sql = '
    SELECT n.id, n.rapat_id, n.judul, n.ringkasan, n.created_at, n.updated_at,
           r.judul AS rapat_judul, r.tanggal_rapat, r.nomor_rapat
    FROM yayasan_notulen n
    INNER JOIN yayasan_rapat r ON r.id = n.rapat_id
';
$params = [];
if ($filterRapatId > 0) {
    $sql .= ' WHERE n.rapat_id = :rid';
    $params['rid'] = $filterRapatId;
}
$sql .= ' ORDER BY r.tanggal_rapat DESC, n.id DESC';
$stList = $pdo->prepare($sql);
$stList->execute($params);
$rows = $stList->fetchAll(PDO::FETCH_ASSOC);

$rapatTerpilih = null;
if ($filterRapatId > 0) {
    foreach ($rapatList as $r) {
        if ((int) $r['id'] === $filterRapatId) {
            $rapatTerpilih = $r;
            break;
        }
    }
}

$hubYayasan = '/menu/menu_hub.php?id=menu-grp-yayasan';
$pageTitle = 'Notulen Rapat';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href($hubYayasan)) ?>">Yayasan</a> · Notulen</p>
    <h1 class="h4 mb-1">Notulen rapat</h1>
    <p class="text-muted mb-0">Catatan, keputusan, dan tindak lanjut rapat yayasan.</p>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-8">
                <label class="form-label small mb-0">Filter rapat</label>
                <select name="rapat_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0">Semua rapat</option>
                    <?php foreach ($rapatList as $r): ?>
                        <option value="<?= (int) $r['id'] ?>" <?= $filterRapatId === (int) $r['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars(yayasan_format_tanggal_rapat((string) $r['tanggal_rapat']) . ' — ' . (string) $r['judul']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <a href="<?= htmlspecialchars(app_href('/yayasan/rapat.php')) ?>" class="btn btn-sm btn-outline-secondary w-100">+ Rapat baru</a>
            </div>
        </form>
        <?php if ($rapatTerpilih): ?>
            <p class="small text-muted mb-0 mt-2">
                Rapat terpilih: <strong><?= htmlspecialchars((string) $rapatTerpilih['judul']) ?></strong>
                (<?= htmlspecialchars(yayasan_format_tanggal_rapat((string) $rapatTerpilih['tanggal_rapat'])) ?>)
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?= $editRow ? 'Ubah notulen' : 'Tambah notulen' ?></h2>
                <?php if ($editRow): ?>
                    <p class="small text-muted">
                        <a href="<?= htmlspecialchars(app_href('/yayasan/notulen.php' . ($filterRapatId > 0 ? '?rapat_id=' . $filterRapatId : ''))) ?>">← Batal edit</a>
                    </p>
                <?php endif; ?>
                <?php if ($rapatList === []): ?>
                    <p class="text-muted small mb-0">Buat rapat terlebih dahulu di menu <a href="<?= htmlspecialchars(app_href('/yayasan/rapat.php')) ?>">Rapat</a>.</p>
                <?php else: ?>
                    <form method="post" class="row g-2">
                        <?php if ($editRow): ?>
                            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label small mb-0">Rapat</label>
                            <select class="form-select" name="rapat_id" required>
                                <?php foreach ($rapatList as $r): ?>
                                    <option value="<?= (int) $r['id'] ?>" <?= ($filterRapatId === (int) $r['id'] || (int) ($editRow['rapat_id'] ?? 0) === (int) $r['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) $r['judul']) ?> (<?= htmlspecialchars((string) $r['tanggal_rapat']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Judul notulen</label>
                            <input class="form-control" name="judul" placeholder="Opsional" value="<?= htmlspecialchars((string) ($editRow['judul'] ?? '')) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Yang hadir</label>
                            <textarea class="form-control" name="hadir" rows="2" placeholder="Nama pengurus, satu per baris"><?= htmlspecialchars((string) ($editRow['hadir'] ?? '')) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Ringkasan</label>
                            <textarea class="form-control" name="ringkasan" rows="2"><?= htmlspecialchars((string) ($editRow['ringkasan'] ?? '')) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Isi notulen</label>
                            <textarea class="form-control" name="isi" rows="5"><?= htmlspecialchars((string) ($editRow['isi'] ?? '')) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Keputusan</label>
                            <textarea class="form-control" name="keputusan" rows="3"><?= htmlspecialchars((string) ($editRow['keputusan'] ?? '')) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Tindak lanjut</label>
                            <textarea class="form-control font-monospace" name="tindak_lanjut" rows="4" placeholder="- Judul tugas | 2025-06-30 | PJ: Nama&#10;- [25%] Tugas berjalan | sampai 15/07/2025"><?= htmlspecialchars((string) ($editRow['tindak_lanjut'] ?? '')) ?></textarea>
                            <div class="form-text">Satu baris = satu tugas timeline. Tanggal target otomatis tercatat &amp; tersinkron ke <a href="<?= htmlspecialchars(app_href('/yayasan/timeline.php')) ?>">Timeline Yayasan</a> + kalender.</div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-success w-100"><?= $editRow ? 'Simpan perubahan' : 'Simpan notulen' ?></button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Arsip notulen</h2>
                <?php if ($rows === []): ?>
                    <p class="text-muted mb-0">Belum ada notulen<?= $filterRapatId > 0 ? ' untuk rapat ini' : '' ?>.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($rows as $row): ?>
                            <div class="list-group-item px-0">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <div>
                                        <div class="fw-semibold">
                                            <?= htmlspecialchars((string) ($row['judul'] ?: $row['rapat_judul'])) ?>
                                        </div>
                                        <div class="small text-muted">
                                            <?= htmlspecialchars(yayasan_format_tanggal_rapat((string) $row['tanggal_rapat'])) ?>
                                            <?php if (!empty($row['nomor_rapat'])): ?>
                                                · <?= htmlspecialchars((string) $row['nomor_rapat']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($row['ringkasan'])): ?>
                                            <p class="small mb-1 mt-2"><?= nl2br(htmlspecialchars((string) $row['ringkasan'])) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-nowrap">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/yayasan/notulen.php?edit=' . (int) $row['id'])) ?>">Edit</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Hapus notulen ini?');">
                                            <input type="hidden" name="action" value="hapus">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
