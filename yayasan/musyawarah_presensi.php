<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/yayasan_musyawarah.php';
require_once __DIR__ . '/../helpers/yayasan_notulen.php';

require_roles(['admin', 'pengurus']);

yayasan_musyawarah_ensure_schema($pdo);
yayasan_notulen_ensure_schema($pdo);

$rapatId = (int) ($_GET['rapat_id'] ?? 0);
if ($rapatId <= 0) {
    set_flash('error', 'Rapat tidak ditemukan.');
    header('Location: ' . app_href('/yayasan/rapat.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'set_status') {
        $pid = (int) ($_POST['pengurus_id'] ?? 0);
        $status = strtoupper(trim((string) ($_POST['status'] ?? 'HADIR')));
        if (!in_array($status, ['HADIR', 'IZIN', 'ALPA'], true)) {
            $status = 'HADIR';
        }
        if ($pid > 0) {
            $rapatSt = $pdo->prepare('SELECT tanggal_rapat FROM yayasan_rapat WHERE id = :id LIMIT 1');
            $rapatSt->execute(['id' => $rapatId]);
            $tgl = (string) ($rapatSt->fetchColumn() ?: date('Y-m-d'));
            $uid = (int) ($_SESSION['user']['id'] ?? 0);
            $chk = $pdo->prepare('SELECT id FROM presensi_musyawarah WHERE rapat_id = :rid AND pengurus_id = :pid LIMIT 1');
            $chk->execute(['rid' => $rapatId, 'pid' => $pid]);
            if ($chk->fetchColumn()) {
                $pdo->prepare('UPDATE presensi_musyawarah SET status = :st, catatan = :cat WHERE rapat_id = :rid AND pengurus_id = :pid')
                    ->execute([
                        'st' => $status,
                        'cat' => trim((string) ($_POST['catatan'] ?? '')) ?: null,
                        'rid' => $rapatId,
                        'pid' => $pid,
                    ]);
            } else {
                $pdo->prepare('
                    INSERT INTO presensi_musyawarah (rapat_id, pengurus_id, status, tanggal, jam, catatan, created_by)
                    VALUES (:rid, :pid, :st, :tgl, :jam, :cat, :by)
                ')->execute([
                    'rid' => $rapatId,
                    'pid' => $pid,
                    'st' => $status,
                    'tgl' => $tgl,
                    'jam' => date('H:i:s'),
                    'cat' => trim((string) ($_POST['catatan'] ?? '')) ?: null,
                    'by' => $uid > 0 ? $uid : null,
                ]);
            }
            set_flash('success', 'Status presensi diperbarui.');
        }
        header('Location: ' . app_href('/yayasan/musyawarah_presensi.php?rapat_id=' . $rapatId));
        exit;
    }
    if ($action === 'kirim_wa') {
        $res = yayasan_musyawarah_kirim_wa_laporan($pdo, $rapatId);
        set_flash($res['ok'] ? 'success' : 'warning', $res['message']);
        header('Location: ' . app_href('/yayasan/musyawarah_presensi.php?rapat_id=' . $rapatId));
        exit;
    }
    if ($action === 'selesai_rapat') {
        $pdo->prepare('UPDATE yayasan_rapat SET status = "SELESAI" WHERE id = :id')->execute(['id' => $rapatId]);
        if (trim((string) app_setting($pdo, 'wa_musyawarah_auto_selesai', '0')) === '1') {
            yayasan_musyawarah_kirim_wa_laporan($pdo, $rapatId);
        }
        set_flash('success', 'Rapat ditandai selesai.');
        header('Location: ' . app_href('/yayasan/musyawarah_presensi.php?rapat_id=' . $rapatId));
        exit;
    }
    if ($action === 'simpan_hasil_agenda') {
        $res = yayasan_notulen_save_hasil_agenda($pdo, $rapatId, $_POST, (int) ($_SESSION['user']['id'] ?? 0));
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/yayasan/musyawarah_presensi.php?rapat_id=' . $rapatId . '#hasil-agenda'));
        exit;
    }
}

$rekap = yayasan_musyawarah_rekap_rapat($pdo, $rapatId);
$rapat = $rekap['rapat'] ?? null;
if (!is_array($rapat)) {
    set_flash('error', 'Rapat tidak ditemukan.');
    header('Location: ' . app_href('/yayasan/rapat.php'));
    exit;
}


$hasilAgendaRows = yayasan_notulen_agenda_uraian_rows($pdo, $rapatId, $rapat);

$pageTitle = 'Presensi Musyawarah';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <?php $yayasanCrumbTail = 'Presensi musyawarah'; require __DIR__ . '/../includes/partials/yayasan_crumb.php'; ?>
    <p class="small mb-2"><a href="<?= htmlspecialchars(app_href('/yayasan/rapat.php')) ?>">← Rapat &amp; musyawarah</a></p>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="h4 mb-1"><?= htmlspecialchars((string) ($rapat['judul'] ?? 'Musyawarah')) ?></h1>
            <p class="text-muted mb-0">
                <?= htmlspecialchars(yayasan_format_tanggal_rapat(
                    (string) ($rapat['tanggal_rapat'] ?? ''),
                    $rapat['waktu_mulai'] !== null ? (string) $rapat['waktu_mulai'] : null,
                    $rapat['waktu_selesai'] !== null ? (string) $rapat['waktu_selesai'] : null
                )) ?>
                <?= !empty($rapat['lokasi']) ? ' · ' . htmlspecialchars((string) $rapat['lokasi']) : '' ?>
            </p>
            <?php
            $agendaMusyawarah = yayasan_rapat_agenda_teks($rapat);
            if ($agendaMusyawarah !== '' && $hasilAgendaRows === []):
                ?>
                <div class="alert alert-light border small mb-0 mt-2 py-2">
                    <div class="fw-semibold mb-1">Agenda ringkas</div>
                    <div class="text-muted" style="white-space:pre-wrap"><?= nl2br(htmlspecialchars($agendaMusyawarah)) ?></div>
                </div>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars(app_href('/yayasan/scan_musyawarah.php?rapat_id=' . $rapatId)) ?>">
                <i class="fa-solid fa-qrcode me-1"></i>Scan presensi
            </a>
            <form method="post" class="d-inline">
                <input type="hidden" name="action" value="kirim_wa">
                <button type="submit" class="btn btn-sm btn-success">
                    <i class="fa-brands fa-whatsapp me-1"></i>Kirim laporan WA
                </button>
            </form>
            <?php if (($rapat['status'] ?? '') !== 'SELESAI'): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Tandai rapat selesai?');">
                <input type="hidden" name="action" value="selesai_rapat">
                <button type="submit" class="btn btn-sm btn-primary">Selesai rapat</button>
            </form>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-dark" href="<?= htmlspecialchars(app_href('/yayasan/musyawarah_hasil.php?rapat_id=' . $rapatId)) ?>">
                <i class="fa-solid fa-pen-to-square me-1"></i>Edit &amp; cetak hasil
            </a>
        </div>
    </div>
</div>

<?php if ($hasilAgendaRows !== []): ?>
<div class="card shadow-sm mb-4" id="hasil-agenda">
    <div class="card-header fw-semibold">
        <i class="fa-solid fa-clipboard-list me-1"></i> Hasil musyawarah per agenda
    </div>
    <div class="card-body">
        <p class="small text-muted">Isi uraian di bawah setiap poin agenda ringkas. Untuk edit lengkap &amp; cetak: <a href="<?= htmlspecialchars(app_href('/yayasan/musyawarah_hasil.php?rapat_id=' . $rapatId)) ?>">Edit &amp; cetak hasil</a>.</p>
        <?php
        $hasilAgendaFormAction = 'simpan_hasil_agenda';
        require __DIR__ . '/partials/hasil_agenda_form.php';
        ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-2 mb-4">
    <div class="col-4">
        <div class="yp-mini-stat">
            <div class="yp-mini-stat__label">Hadir</div>
            <div class="yp-mini-stat__value text-success"><?= count($rekap['hadir'] ?? []) ?></div>
        </div>
    </div>
    <div class="col-4">
        <div class="yp-mini-stat">
            <div class="yp-mini-stat__label">Izin</div>
            <div class="yp-mini-stat__value text-warning"><?= count($rekap['izin'] ?? []) ?></div>
        </div>
    </div>
    <div class="col-4">
        <div class="yp-mini-stat">
            <div class="yp-mini-stat__label">Belum hadir</div>
            <div class="yp-mini-stat__value text-danger"><?= count($rekap['alpa'] ?? []) ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm border-success">
            <div class="card-header bg-success text-white py-2"><strong>Hadir</strong></div>
            <ul class="list-group list-group-flush">
                <?php if (($rekap['hadir'] ?? []) === []): ?>
                    <li class="list-group-item small text-muted">Belum ada yang scan hadir.</li>
                <?php else: ?>
                    <?php foreach ($rekap['hadir'] as $h): ?>
                        <li class="list-group-item small">
                            <strong><?= htmlspecialchars((string) $h['nama']) ?></strong>
                            <br><?= htmlspecialchars((string) $h['jabatan']) ?>
                            <?php if (!empty($h['jam'])): ?>
                                <span class="text-muted"> · <?= htmlspecialchars(substr((string) $h['jam'], 0, 5)) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm border-warning">
            <div class="card-header bg-warning py-2"><strong>Izin</strong></div>
            <ul class="list-group list-group-flush">
                <?php if (($rekap['izin'] ?? []) === []): ?>
                    <li class="list-group-item small text-muted">Tidak ada.</li>
                <?php else: ?>
                    <?php foreach ($rekap['izin'] as $h): ?>
                        <li class="list-group-item small">
                            <strong><?= htmlspecialchars((string) $h['nama']) ?></strong>
                            <br><?= htmlspecialchars((string) $h['jabatan']) ?>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm border-danger">
            <div class="card-header bg-danger text-white py-2"><strong>Tidak hadir / belum scan</strong></div>
            <ul class="list-group list-group-flush">
                <?php if (($rekap['alpa'] ?? []) === []): ?>
                    <li class="list-group-item small text-muted">Semua undangan wajib sudah hadir/izin.</li>
                <?php else: ?>
                    <?php foreach ($rekap['alpa'] as $h): ?>
                        <li class="list-group-item small">
                            <strong><?= htmlspecialchars((string) $h['nama']) ?></strong>
                            <br><?= htmlspecialchars((string) $h['jabatan']) ?>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<div class="card shadow-sm mt-4">
    <div class="card-body">
        <h2 class="h6 mb-3">Kelola status manual (wajib diundang)</h2>
        <p class="small text-muted">Gunakan jika perlu menandai izin atau tidak hadir tanpa scan.</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Jabatan</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rekap['wajib'] ?? [] as $w): ?>
                    <?php
                    $pid = (int) ($w['id'] ?? 0);
                    $cur = 'ALPA';
                    foreach (array_merge($rekap['hadir'] ?? [], $rekap['izin'] ?? []) as $pr) {
                        if ((int) ($pr['pengurus_id'] ?? 0) === $pid) {
                            $cur = strtoupper((string) ($pr['status'] ?? 'HADIR'));
                            break;
                        }
                    }
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $w['nama']) ?></td>
                        <td class="small"><?= htmlspecialchars((string) $w['jabatan']) ?></td>
                        <td>
                            <span class="badge text-bg-<?= $cur === 'HADIR' ? 'success' : ($cur === 'IZIN' ? 'warning' : 'danger') ?>">
                                <?= htmlspecialchars($cur) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <form method="post" class="d-inline-flex gap-1">
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="pengurus_id" value="<?= $pid ?>">
                                <select name="status" class="form-select form-select-sm" style="width:auto">
                                    <option value="HADIR">Hadir</option>
                                    <option value="IZIN">Izin</option>
                                    <option value="ALPA">Tidak hadir</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary">Simpan</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (($rekap['wajib'] ?? []) === []): ?>
                    <tr><td colspan="4" class="text-muted text-center py-3">Belum ada jabatan wajib scan di agenda rapat.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card shadow-sm mt-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Pratinjau pesan WA</h2>
        <pre class="small bg-light border rounded p-3 mb-0" style="white-space:pre-wrap"><?= htmlspecialchars(yayasan_musyawarah_format_laporan_wa($pdo, $rapatId)) ?></pre>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
