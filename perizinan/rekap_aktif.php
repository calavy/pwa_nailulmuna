<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/perizinan_aktif.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);

if (!table_exists($pdo, 'perizinan')) {
    set_flash('error', 'Tabel perizinan belum ada.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'izin_selesai') {
    $izinId = (int) ($_POST['izin_id'] ?? 0);
    $res = perizinan_tandai_kembali_manual($pdo, $izinId, (int) ($_SESSION['user']['id'] ?? 0));
    set_flash($res['ok'] ? 'success' : 'error', $res['message']);
    $qs = $_GET;
    unset($qs['ok']);
    header('Location: ' . app_href('/perizinan/rekap_aktif.php?' . http_build_query($qs)));
    exit;
}

$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
$rows = perizinan_aktif_belum_kembali($pdo, $tingkatan !== '' ? $tingkatan : null);

$tingkatanList = table_exists($pdo, 'tingkatan')
    ? $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN)
    : [];

$pageTitle = 'Rekap Izin Aktif';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/perizinan/index.php')) ?>">Perizinan</a>
    </p>
    <h1 class="h4 mb-1">Santri sedang izin (belum kembali)</h1>
    <p class="text-muted small mb-0">
        Daftar izin yang sudah disetujui tetapi belum tercatat kembali.
        Izin selesai otomatis saat santri discan di menu <strong>Scan Presensi</strong>.
        Gunakan tombol <strong>Izin selesai</strong> bila santri sudah tiba tanpa scan kartu.
    </p>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Filter tingkatan</label>
                <select name="tingkatan" class="form-select form-select-sm">
                    <option value="">Semua tingkatan</option>
                    <?php foreach ($tingkatanList as $tk): ?>
                        <option value="<?= htmlspecialchars((string) $tk) ?>" <?= $tingkatan === (string) $tk ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $tk) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-primary btn-sm w-100">Filter</button>
            </div>
            <div class="col-md-6 text-md-end">
                <span class="badge text-bg-warning fs-6"><?= count($rows) ?> santri belum kembali</span>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Santri</th>
                    <th>Jenis</th>
                    <th>Periode</th>
                    <th>Batas kembali</th>
                    <th class="text-end" style="width:11rem">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">Tidak ada santri izin yang menunggu pencatatan kembali.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $iid = (int) ($r['id'] ?? 0);
                    $rombonganId = (int) ($r['rombongan_id'] ?? 0);
                    $batas = trim((string) ($r['tanggal_selesai'] ?? '') . ' ' . substr((string) ($r['jam_selesai'] ?? ''), 0, 5));
                    $lewatBatas = strtotime($batas) !== false && time() > strtotime($batas);
                    ?>
                    <tr class="<?= $lewatBatas ? 'table-warning' : '' ?>">
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars((string) $r['nama_santri']) ?></div>
                            <div class="small text-muted">
                                <?= htmlspecialchars((string) ($r['nis'] ?: '-')) ?> · <?= htmlspecialchars((string) ($r['tingkatan'] ?: '-')) ?>
                                <?php if ($rombonganId > 0): ?>
                                    <span class="badge text-bg-secondary ms-1">Rombongan #<?= $rombonganId ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="small"><?= htmlspecialchars(jenis_izin_label((string) ($r['jenis_izin'] ?? 'KELUAR'))) ?></td>
                        <td class="small text-nowrap">
                            <?= htmlspecialchars((string) $r['tanggal_mulai']) ?>
                            <span class="text-muted">s/d</span>
                            <?= htmlspecialchars((string) $r['tanggal_selesai']) ?>
                            <?php if (trim((string) ($r['jam_mulai'] ?? '')) !== ''): ?>
                                <span class="d-block text-muted"><?= htmlspecialchars(substr((string) ($r['jam_mulai'] ?? ''), 0, 5)) ?>–<?= htmlspecialchars(substr((string) ($r['jam_selesai'] ?? ''), 0, 5)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?= htmlspecialchars(substr((string) ($r['jam_selesai'] ?? ''), 0, 5)) ?>
                            <?php if ($lewatBatas): ?>
                                <span class="badge text-bg-danger ms-1">Lewat</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-outline-secondary btn-sm py-0" href="<?= htmlspecialchars(app_rewrite_internal_url('/perizinan/surat.php?id=' . $iid)) ?>" target="_blank" rel="noopener" title="Surat izin">
                                <i class="fa-solid fa-file-lines"></i>
                            </a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Tandai <?= htmlspecialchars((string) $r['nama_santri']) ?> sudah kembali?');">
                                <input type="hidden" name="action" value="izin_selesai">
                                <input type="hidden" name="izin_id" value="<?= $iid ?>">
                                <button type="submit" class="btn btn-success btn-sm">
                                    <i class="fa-solid fa-check me-1"></i> Izin selesai
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
