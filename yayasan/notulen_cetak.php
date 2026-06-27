<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/yayasan_notulen.php';

require_roles(['admin', 'pengurus']);

yayasan_notulen_ensure_schema($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit('Notulen tidak ditemukan.');
}

$st = $pdo->prepare('
    SELECT n.*, r.judul AS rapat_judul, r.tanggal_rapat, r.nomor_rapat, r.waktu_mulai, r.waktu_selesai, r.lokasi
    FROM yayasan_notulen n
    INNER JOIN yayasan_rapat r ON r.id = n.rapat_id
    WHERE n.id = :id LIMIT 1
');
$st->execute(['id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Notulen tidak ditemukan.');
}

$timelineRows = yayasan_notulen_timeline_rows_from_json((string) ($row['timeline_json'] ?? ''));
$ponpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren Nailul Muna'));

$pageTitle = 'Cetak Notulen Rapat';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-notulen.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3 yn-no-print d-flex flex-wrap justify-content-between gap-2">
    <div>
        <h1 class="h4 mb-1">Cetak notulen rapat</h1>
        <p class="text-muted mb-0"><?= htmlspecialchars((string) ($row['judul'] ?: $row['rapat_judul'])) ?></p>
    </div>
    <div class="text-nowrap">
        <button type="button" class="btn btn-primary" onclick="window.print()">Cetak</button>
        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(app_href('/yayasan/notulen.php?edit=' . $id)) ?>">Edit</a>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="text-center mb-4">
            <div class="fw-bold"><?= htmlspecialchars($ponpes) ?></div>
            <div class="h5 mb-1">Notulen Rapat</div>
            <div><?= htmlspecialchars((string) ($row['judul'] ?: $row['rapat_judul'])) ?></div>
            <?php if (!empty($row['nomor_rapat'])): ?>
                <div class="small text-muted">No. <?= htmlspecialchars((string) $row['nomor_rapat']) ?></div>
            <?php endif; ?>
        </div>

        <table class="table table-sm table-borderless mb-4">
            <tr>
                <td class="text-muted" style="width:120px">Tanggal</td>
                <td>
                    <?= htmlspecialchars(yayasan_format_tanggal_rapat(
                        (string) $row['tanggal_rapat'],
                        $row['waktu_mulai'] !== null ? (string) $row['waktu_mulai'] : null,
                        $row['waktu_selesai'] !== null ? (string) $row['waktu_selesai'] : null
                    )) ?>
                </td>
            </tr>
            <?php if (!empty($row['lokasi'])): ?>
            <tr>
                <td class="text-muted">Lokasi</td>
                <td><?= htmlspecialchars((string) $row['lokasi']) ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <?php if (!empty($row['hadir'])): ?>
            <h2 class="h6">Yang hadir</h2>
            <div class="mb-3"><?= nl2br(htmlspecialchars((string) $row['hadir'])) ?></div>
        <?php endif; ?>

        <?php if (!empty($row['ringkasan'])): ?>
            <h2 class="h6">Ringkasan</h2>
            <div class="mb-3"><?= nl2br(htmlspecialchars((string) $row['ringkasan'])) ?></div>
        <?php endif; ?>

        <?php if (!empty($row['isi'])): ?>
            <h2 class="h6">Isi notulen</h2>
            <div class="mb-3"><?= yayasan_notulen_format_hasil_rapat((string) $row['isi']) ?></div>
        <?php endif; ?>

        <?php if (!empty($row['keputusan'])): ?>
            <h2 class="h6">Keputusan</h2>
            <div class="mb-3"><?= yayasan_notulen_format_hasil_rapat((string) $row['keputusan']) ?></div>
        <?php endif; ?>

        <?php if ($timelineRows !== []): ?>
            <h2 class="h6">Timeline tindak lanjut</h2>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered yn-timeline-table">
                    <thead>
                        <tr>
                            <th>Bagian</th>
                            <th>Keputusan</th>
                            <th>Penanggung jawab</th>
                            <th>Waktu mulai</th>
                            <th>Batas waktu</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($timelineRows as $tr): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $tr['bagian']) ?></td>
                                <td><?= htmlspecialchars((string) $tr['keputusan']) ?></td>
                                <td><?= htmlspecialchars((string) $tr['penanggung_jawab']) ?></td>
                                <td><?= htmlspecialchars(yayasan_rapat_format_jam_24($tr['waktu_mulai'] ?: null) ?: (string) $tr['waktu_mulai']) ?></td>
                                <td><?= htmlspecialchars((string) $tr['batas_waktu']) ?></td>
                                <td><?= htmlspecialchars((string) $tr['keterangan']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if (!empty($row['foto_path'])): ?>
            <h2 class="h6">Dokumentasi foto</h2>
            <img src="<?= htmlspecialchars(app_href('/' . ltrim((string) $row['foto_path'], '/'))) ?>" alt="Foto rapat" class="img-fluid rounded mb-3" style="max-height:420px">
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
