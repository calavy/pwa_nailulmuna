<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pembayaran_edit_token.php';

require_super_admin();

pembayaran_edit_token_ensure_schema($pdo);

$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'buat_token') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $expiresAt = trim((string) ($_POST['expires_at'] ?? ''));
        // Form input datetime-local → "YYYY-MM-DDTHH:MM"
        if ($expiresAt !== '') {
            $expiresAt = str_replace('T', ' ', $expiresAt);
            if (strlen($expiresAt) === 16) {
                $expiresAt .= ':00';
            }
        }
        try {
            $result = pembayaran_edit_token_buat($pdo, $currentUserId, $label !== '' ? $label : null, $expiresAt !== '' ? $expiresAt : null);
            set_flash('success', 'Token baru: ' . $result['token'] . ' — salin dan berikan ke admin/pengurus yang akan mengedit.');
        } catch (Throwable $e) {
            set_flash('error', 'Gagal membuat token: ' . $e->getMessage());
        }
        header('Location: ' . app_href('/pembayaran/edit_token.php'));
        exit;
    }

    if ($action === 'batal_token') {
        $tokenId = (int) ($_POST['token_id'] ?? 0);
        if (pembayaran_edit_token_revoke($pdo, $tokenId, $currentUserId)) {
            set_flash('success', 'Token #' . $tokenId . ' dibatalkan.');
        } else {
            set_flash('error', 'Token tidak bisa dibatalkan (sudah habis / tidak ada).');
        }
        header('Location: ' . app_href('/pembayaran/edit_token.php'));
        exit;
    }
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
if (!in_array($statusFilter, ['aktif', 'dipakai', 'habis', 'batal'], true)) {
    $statusFilter = '';
}
$tokens = pembayaran_edit_token_list($pdo, $statusFilter !== '' ? $statusFilter : null);
$summary = pembayaran_edit_token_summary($pdo);

$pageTitle = 'Token Edit Pembayaran';
require_once __DIR__ . '/../includes/header.php';

$flashOk = get_flash('success');
$flashErr = get_flash('error');

function pet_status_badge(string $status): string
{
    switch ($status) {
        case 'aktif':
            return '<span class="badge text-bg-primary-subtle text-primary border border-primary-subtle"><i class="fa-solid fa-key me-1"></i>Aktif</span>';
        case 'dipakai':
            return '<span class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="fa-solid fa-lock-open me-1"></i>Dipakai</span>';
        case 'habis':
            return '<span class="badge text-bg-secondary-subtle text-secondary border border-secondary-subtle"><i class="fa-solid fa-circle-check me-1"></i>Habis</span>';
        case 'batal':
            return '<span class="badge text-bg-danger-subtle text-danger border border-danger-subtle"><i class="fa-solid fa-ban me-1"></i>Dibatalkan</span>';
    }
    return '<span class="badge text-bg-light text-dark border">' . htmlspecialchars($status) . '</span>';
}
?>

<div class="page-intro mb-3 d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
    <div class="flex-grow-1">
        <p class="page-intro-kicker mb-1">
            <a href="<?= htmlspecialchars(app_href('/pembayaran/riwayat.php')) ?>">Riwayat Pembayaran</a>
            · <span class="text-danger">Super Admin</span>
        </p>
        <h1 class="h4 mb-1"><i class="fa-solid fa-key me-1 text-warning"></i>Token edit pembayaran</h1>
        <p class="text-muted mb-0 small">
            Terbitkan token 1× pakai untuk membuka mode edit pembayaran. Admin/pengurus yang ditugaskan
            mengoreksi pembayaran wajib memasukkan token sebelum bisa mengedit. Token otomatis
            <strong>habis saat user yang memakainya logout</strong>.
        </p>
    </div>
    <a href="<?= htmlspecialchars(app_href('/pembayaran/riwayat.php')) ?>" class="btn btn-sm btn-outline-secondary flex-shrink-0">
        <i class="fa-solid fa-arrow-left me-1"></i>Kembali ke Riwayat
    </a>
</div>

<?php if ($flashOk): ?><div class="alert alert-success py-2 small mb-3"><i class="fa-solid fa-circle-check me-1"></i><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="alert alert-danger py-2 small mb-3"><i class="fa-solid fa-circle-exclamation me-1"></i><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label"><i class="fa-solid fa-key me-1 text-primary"></i>Aktif (siap pakai)</div>
            <div class="app-mini-stat-value text-primary"><?= (int) $summary['aktif'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label"><i class="fa-solid fa-lock-open me-1 text-warning"></i>Sedang dipakai</div>
            <div class="app-mini-stat-value text-warning"><?= (int) $summary['dipakai'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label"><i class="fa-solid fa-circle-check me-1 text-secondary"></i>Habis</div>
            <div class="app-mini-stat-value text-secondary"><?= (int) $summary['habis'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label"><i class="fa-solid fa-ban me-1 text-danger"></i>Dibatalkan</div>
            <div class="app-mini-stat-value text-danger"><?= (int) $summary['batal'] ?></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card shadow-sm border-primary-subtle h-100">
            <div class="card-header bg-primary-subtle border-0 py-2">
                <h2 class="h6 mb-0 text-primary-emphasis">
                    <i class="fa-solid fa-plus me-1"></i>Terbitkan token baru
                </h2>
            </div>
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="buat_token">
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Label / catatan <span class="text-muted">(opsional)</span></label>
                        <input class="form-control form-control-sm" name="label" placeholder="Mis. Untuk koreksi Pak Budi - Jumat" maxlength="160">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted mb-1">Kedaluwarsa <span class="text-muted">(opsional)</span></label>
                        <input class="form-control form-control-sm" type="datetime-local" name="expires_at">
                        <div class="form-text small">Kosongkan kalau hanya bergantung pada logout user.</div>
                    </div>
                    <button class="btn btn-primary w-100">
                        <i class="fa-solid fa-key me-1"></i>Buat token
                    </button>
                </form>
                <hr class="my-3">
                <div class="small text-muted">
                    <p class="mb-1"><i class="fa-solid fa-circle-info me-1"></i><strong>Cara pakai:</strong></p>
                    <ol class="mb-0 ps-3">
                        <li>Klik <em>Buat token</em>.</li>
                        <li>Salin token dari pesan sukses / tabel di kanan.</li>
                        <li>Berikan ke admin / pengurus yang akan mengoreksi.</li>
                        <li>Setelah dipakai (redeem), token jadi <em>dipakai</em>; saat user logout otomatis jadi <em>habis</em>.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-transparent border-bottom-0 pt-3 pb-0">
                <div class="d-flex flex-column flex-sm-row justify-content-sm-between align-items-sm-center gap-2">
                    <div>
                        <h2 class="h5 mb-0">Daftar token</h2>
                        <p class="small text-muted mb-0"><?= count($tokens) ?> token <?= $statusFilter !== '' ? '(status: ' . htmlspecialchars($statusFilter) . ')' : '(semua status)' ?></p>
                    </div>
                    <div class="btn-group btn-group-sm" role="group">
                        <a class="btn <?= $statusFilter === '' ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="?">Semua</a>
                        <a class="btn <?= $statusFilter === 'aktif' ? 'btn-primary' : 'btn-outline-primary' ?>" href="?status=aktif">Aktif</a>
                        <a class="btn <?= $statusFilter === 'dipakai' ? 'btn-warning' : 'btn-outline-warning' ?>" href="?status=dipakai">Dipakai</a>
                        <a class="btn <?= $statusFilter === 'habis' ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="?status=habis">Habis</a>
                    </div>
                </div>
            </div>
            <div class="card-body pt-2">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr class="text-uppercase small text-muted">
                                <th>#</th>
                                <th>Token</th>
                                <th>Label</th>
                                <th>Status</th>
                                <th>Dibuat</th>
                                <th>Dipakai oleh</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tokens as $t):
                            $tid = (int) ($t['id'] ?? 0);
                            $tStatus = (string) ($t['status'] ?? '');
                            $tokenPlain = (string) ($t['token_plain'] ?? '');
                            $label = (string) ($t['label'] ?? '');
                            $creator = trim((string) ($t['creator_nama'] ?? '')) !== ''
                                ? (string) $t['creator_nama']
                                : (string) ($t['creator_username'] ?? '#' . (int) ($t['created_by'] ?? 0));
                            $redeemer = trim((string) ($t['redeemer_nama'] ?? '')) !== ''
                                ? (string) $t['redeemer_nama']
                                : (string) ($t['redeemer_username'] ?? '');
                        ?>
                            <tr>
                                <td class="small font-monospace text-muted">#<?= $tid ?></td>
                                <td>
                                    <code class="user-select-all small fw-semibold"><?= htmlspecialchars($tokenPlain) ?></code>
                                </td>
                                <td class="small"><?= $label !== '' ? htmlspecialchars($label) : '<span class="text-muted">—</span>' ?></td>
                                <td><?= pet_status_badge($tStatus) ?></td>
                                <td class="small">
                                    <div class="font-monospace text-muted" style="font-size:.75rem;"><?= htmlspecialchars((string) ($t['created_at'] ?? '')) ?></div>
                                    <div class="small">oleh <?= htmlspecialchars($creator) ?></div>
                                    <?php if (!empty($t['expires_at'])): ?>
                                        <div class="small text-warning-emphasis"><i class="fa-solid fa-hourglass-half me-1"></i>kedaluwarsa: <?= htmlspecialchars((string) $t['expires_at']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <?php if ($redeemer !== ''): ?>
                                        <div class="fw-semibold"><?= htmlspecialchars($redeemer) ?></div>
                                        <div class="font-monospace text-muted" style="font-size:.7rem;"><?= htmlspecialchars((string) ($t['redeemed_at'] ?? '')) ?></div>
                                        <?php if (!empty($t['consumed_at'])): ?>
                                            <div class="small text-muted"><i class="fa-solid fa-right-from-bracket me-1"></i>habis: <?= htmlspecialchars((string) $t['consumed_at']) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if (in_array($tStatus, ['aktif', 'dipakai'], true)): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Batalkan token #<?= $tid ?>? <?= $tStatus === 'dipakai' ? 'Mode edit user yang sedang memakainya akan langsung tertutup.' : '' ?>');">
                                            <input type="hidden" name="action" value="batal_token">
                                            <input type="hidden" name="token_id" value="<?= $tid ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Batalkan token">
                                                <i class="fa-solid fa-ban"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($tokens === []): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">
                                <i class="fa-regular fa-folder-open fa-2x text-muted mb-2 d-block"></i>
                                Belum ada token. Buat token baru di kiri.
                            </td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
