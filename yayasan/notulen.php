<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/yayasan_notulen.php';

require_roles(['admin', 'pengurus']);

yayasan_notulen_ensure_schema($pdo);

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
            $fotoSt = $pdo->prepare('SELECT foto_path FROM yayasan_notulen WHERE id = :id LIMIT 1');
            $fotoSt->execute(['id' => $id]);
            $fotoOld = (string) ($fotoSt->fetchColumn() ?: '');
            $taskIds = $pdo->prepare('SELECT id FROM yayasan_tugas WHERE notulen_id = :id');
            $taskIds->execute(['id' => $id]);
            foreach ($taskIds->fetchAll(PDO::FETCH_COLUMN) ?: [] as $tid) {
                yayasan_tugas_delete($pdo, (int) $tid);
            }
            $pdo->prepare('DELETE FROM yayasan_notulen WHERE id = :id')->execute(['id' => $id]);
            if ($fotoOld !== '') {
                $full = dirname(__DIR__) . '/' . ltrim($fotoOld, '/');
                if (is_file($full)) {
                    @unlink($full);
                }
            }
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

    $timelineRows = yayasan_notulen_timeline_rows_from_json(trim((string) ($_POST['timeline_json'] ?? '')));
    $tindakLegacy = yayasan_notulen_timeline_rows_to_legacy_text($timelineRows);
    if ($tindakLegacy === '') {
        $tindakLegacy = trim((string) ($_POST['tindak_lanjut'] ?? '')) ?: null;
    }

    $data = [
        'rapat_id' => $rapatId,
        'judul' => $judul !== '' ? $judul : null,
        'isi' => trim((string) ($_POST['isi'] ?? '')) ?: null,
        'ringkasan' => trim((string) ($_POST['ringkasan'] ?? '')) ?: null,
        'keputusan' => trim((string) ($_POST['keputusan'] ?? '')) ?: null,
        'tindak_lanjut' => $tindakLegacy,
        'timeline_json' => $timelineRows !== [] ? json_encode($timelineRows, JSON_UNESCAPED_UNICODE) : null,
        'hadir' => trim((string) ($_POST['hadir'] ?? '')) ?: null,
        'diinput_oleh' => (int) ($_SESSION['user']['id'] ?? 0) ?: null,
    ];

    $idPost = (int) ($_POST['id'] ?? 0);
    $fotoOld = '';
    if ($idPost > 0) {
        $fotoSt = $pdo->prepare('SELECT foto_path FROM yayasan_notulen WHERE id = :id LIMIT 1');
        $fotoSt->execute(['id' => $idPost]);
        $fotoOld = (string) ($fotoSt->fetchColumn() ?: '');
    }
    if (!empty($_POST['hapus_foto']) && $fotoOld !== '') {
        $full = dirname(__DIR__) . '/' . ltrim($fotoOld, '/');
        if (is_file($full)) {
            @unlink($full);
        }
        $data['foto_path'] = null;
        $fotoOld = '';
    } else {
        $data['foto_path'] = $fotoOld !== '' ? $fotoOld : null;
    }
    if (!empty($_FILES['foto_rapat']) && is_array($_FILES['foto_rapat'])) {
        $upload = yayasan_notulen_handle_foto_upload($_FILES['foto_rapat'], $fotoOld !== '' ? $fotoOld : null);
        if (!$upload['ok']) {
            set_flash('error', (string) ($upload['error'] ?? 'Upload foto gagal.'));
            header('Location: ' . app_href('/yayasan/notulen.php' . ($idPost > 0 ? '?edit=' . $idPost : '?rapat_id=' . $rapatId)));
            exit;
        }
        if (!empty($upload['path'])) {
            $data['foto_path'] = $upload['path'];
        }
    }

    $rapatRow = $pdo->prepare('SELECT tanggal_rapat FROM yayasan_rapat WHERE id = :id LIMIT 1');
    $rapatRow->execute(['id' => $rapatId]);
    $rapatDate = (string) ($rapatRow->fetchColumn() ?: date('Y-m-d'));
    $userId = (int) ($_SESSION['user']['id'] ?? 0);

    if ($idPost > 0) {
        $pdo->prepare('
            UPDATE yayasan_notulen
            SET rapat_id = :rapat_id, judul = :judul, isi = :isi, ringkasan = :ringkasan,
                keputusan = :keputusan, tindak_lanjut = :tindak_lanjut, timeline_json = :timeline_json,
                hadir = :hadir, foto_path = :foto_path, diinput_oleh = :diinput_oleh
            WHERE id = :id
        ')->execute($data + ['id' => $idPost]);
        $notulenId = $idPost;
        set_flash('success', 'Notulen diperbarui. Tugas timeline disinkronkan.');
    } else {
        $pdo->prepare('
            INSERT INTO yayasan_notulen (rapat_id, judul, isi, ringkasan, keputusan, tindak_lanjut, timeline_json, hadir, foto_path, diinput_oleh)
            VALUES (:rapat_id, :judul, :isi, :ringkasan, :keputusan, :tindak_lanjut, :timeline_json, :hadir, :foto_path, :diinput_oleh)
        ')->execute($data);
        $notulenId = (int) $pdo->lastInsertId();
        set_flash('success', 'Notulen disimpan.');
    }

    if ($timelineRows !== []) {
        $items = yayasan_notulen_timeline_to_task_items($timelineRows, $rapatDate);
        $nTask = yayasan_tugas_sync_from_timeline_table($pdo, $notulenId, $rapatId, $items, $userId);
    } else {
        $nTask = yayasan_tugas_sync_from_notulen($pdo, $notulenId, $rapatId, (string) ($data['tindak_lanjut'] ?? ''), $rapatDate, $userId);
    }
    if ($nTask > 0) {
        set_flash('success', ($idPost > 0 ? 'Notulen diperbarui.' : 'Notulen disimpan.') . ' ' . $nTask . ' tugas timeline tercatat.');
    }

    header('Location: ' . app_href('/yayasan/notulen.php?rapat_id=' . $rapatId));
    exit;
}

$sql = '
    SELECT n.id, n.rapat_id, n.judul, n.ringkasan, n.foto_path, n.created_at, n.updated_at,
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

$timelineInitial = [];
if ($editRow) {
    $timelineInitial = yayasan_notulen_timeline_rows_from_json((string) ($editRow['timeline_json'] ?? ''));
}
if ($timelineInitial === [] && $editRow && !empty($editRow['tindak_lanjut'])) {
    $timelineInitial = [['bagian' => '', 'keputusan' => '', 'penanggung_jawab' => '', 'waktu_mulai' => '', 'batas_waktu' => '', 'keterangan' => '']];
}

$pageTitle = 'Notulen Rapat';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-notulen.css')];
$pageScripts = [app_asset_href('/assets/js/yayasan-notulen.js')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <?php $yayasanCrumbTail = 'Notulen'; require __DIR__ . '/../includes/partials/yayasan_crumb.php'; ?>
    <h1 class="h4 mb-1">Notulen rapat</h1>
    <p class="text-muted mb-0">Hasil rapat dengan format nomor/bullet, tabel timeline, dan foto dokumentasi.</p>
</div>

<div class="card shadow-sm mb-3 yn-no-print">
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
                    <form method="post" class="row g-2" enctype="multipart/form-data" id="ynNotulenForm">
                        <?php if ($editRow): ?>
                            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                        <?php endif; ?>
                        <input type="hidden" name="timeline_json" id="timeline_json" value="<?= htmlspecialchars(json_encode($timelineInitial, JSON_UNESCAPED_UNICODE)) ?>">
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
                            <div class="yn-format-bar yn-no-print">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-yn-format="num" data-target="ynIsi">1. Nomor</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-yn-format="bullet" data-target="ynIsi">• Bullet</button>
                                <button type="button" class="btn btn-sm btn-outline-info" data-yn-preview="1" data-target="ynIsi" data-preview="ynIsiPreview">Pratinjau</button>
                            </div>
                            <textarea class="form-control" name="isi" id="ynIsi" rows="5" placeholder="Gunakan 1. atau • di awal baris"><?= htmlspecialchars((string) ($editRow['isi'] ?? '')) ?></textarea>
                            <div id="ynIsiPreview" class="yn-preview-box d-none mt-2"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Keputusan</label>
                            <div class="yn-format-bar yn-no-print">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-yn-format="num" data-target="ynKeputusan">1. Nomor</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-yn-format="bullet" data-target="ynKeputusan">• Bullet</button>
                                <button type="button" class="btn btn-sm btn-outline-info" data-yn-preview="1" data-target="ynKeputusan" data-preview="ynKeputusanPreview">Pratinjau</button>
                            </div>
                            <textarea class="form-control" name="keputusan" id="ynKeputusan" rows="3"><?= htmlspecialchars((string) ($editRow['keputusan'] ?? '')) ?></textarea>
                            <div id="ynKeputusanPreview" class="yn-preview-box d-none mt-2"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0 fw-semibold">Timeline tindak lanjut</label>
                            <p class="form-text small mb-1">Waktu mulai format <strong>24 jam</strong>. Otomatis tersinkron ke <a href="<?= htmlspecialchars(app_href('/yayasan/timeline.php')) ?>">Timeline Yayasan</a>.</p>
                            <div class="yn-timeline-wrap border rounded">
                                <table class="table table-sm table-bordered mb-0 yn-timeline-table" id="ynTimelineTable">
                                    <thead>
                                        <tr>
                                            <th>Bagian</th>
                                            <th>Keputusan</th>
                                            <th>Penanggung jawab</th>
                                            <th>Waktu mulai</th>
                                            <th>Batas waktu</th>
                                            <th>Keterangan</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="ynTimelineAddRow">+ Baris timeline</button>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Foto rapat</label>
                            <?php if (!empty($editRow['foto_path'])): ?>
                                <div class="mb-2">
                                    <img src="<?= htmlspecialchars(app_href('/' . ltrim((string) $editRow['foto_path'], '/'))) ?>" alt="Foto rapat" class="yn-foto-thumb">
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" name="hapus_foto" value="1" id="hapusFoto">
                                        <label class="form-check-label small" for="hapusFoto">Hapus foto saat simpan</label>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" name="foto_rapat" accept="image/jpeg,image/png,image/webp">
                            <div class="form-text">JPG, PNG, atau WEBP — maks. 3 MB.</div>
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
                                        <?php if (!empty($row['foto_path'])): ?>
                                            <span class="badge text-bg-light border">📷 Foto</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-nowrap">
                                        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= htmlspecialchars(app_href('/yayasan/notulen_cetak.php?id=' . (int) $row['id'])) ?>">Cetak</a>
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
