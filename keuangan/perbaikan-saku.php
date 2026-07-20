<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_diagnostik.php';
require_once __DIR__ . '/../helpers/keuangan_pembayaran_admin.php';
require_once __DIR__ . '/../helpers/keuangan_riwayat_pembayaran.php';

require_login();
require_roles(['admin', 'pengurus']);

keuangan_ensure_schema_deferred($pdo);

$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'sync_cashless') {
        require_once __DIR__ . '/../helpers/cashless_koperasi.php';
        $n = cashless_sync_all_account_balances($pdo);
        set_flash('success', 'Saldo cashless disamakan untuk ' . $n . ' akun santri.');
        header('Location: ' . app_href('/keuangan/perbaikan-saku.php'));
        exit;
    }

    if ($action === 'backfill_saku_topup') {
        $res = keuangan_pembayaran_backfill_saku_topup($pdo, $currentUserId, false, 500, 10);
        $flashType = $res['ok'] ? 'success' : (($res['success'] ?? 0) > 0 ? 'warning' : 'error');
        set_flash($flashType, $res['message']);
        header('Location: ' . app_href('/keuangan/perbaikan-saku.php#saku-topup'));
        exit;
    }

    if ($action === 'backfill_saku_santri') {
        $santriId = (int) ($_POST['santri_id'] ?? 0);
        $res = keuangan_pembayaran_backfill_saku_santri($pdo, $santriId, $currentUserId);
        $flashType = ($res['success'] ?? 0) > 0 ? 'success' : (($res['failed'] ?? 0) > 0 ? 'error' : 'warning');
        set_flash($flashType, $res['message']);
        $qBack = trim((string) ($_POST['q'] ?? ''));
        $loc = '/keuangan/perbaikan-saku.php';
        if ($qBack !== '') {
            $loc .= '?q=' . rawurlencode($qBack);
        }
        $loc .= '#saku-detail-' . $santriId;
        header('Location: ' . app_href($loc));
        exit;
    }
}

$diagnostik = ['saku_tanpa_topup' => []];
try {
    $diagnostik = keuangan_diagnostik_menyeluruh($pdo, null, true, 'full', true);
} catch (Throwable $e) {
    error_log('keuangan/perbaikan-saku.php diagnostik: ' . $e->getMessage());
}
$sakuTanpaTopup = $diagnostik['saku_tanpa_topup'] ?? [];
$sakuAuditQ = trim((string) ($_GET['q'] ?? ''));
$sakuAuditPerSantri = keuangan_saku_cashless_audit_per_santri($pdo, $sakuAuditQ !== '' ? $sakuAuditQ : null, true, 500);

$pageTitle = 'Perbaikan Saku & Cashless';
$bodyClass = keuangan_body_class('keuangan-form-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/keuangan/saku.php')) ?>">Saku &amp; Cashless</a> · Audit
    </p>
    <h1 class="h4 mb-1">Perbaikan Saku &amp; Cashless</h1>
    <p class="text-muted mb-0">Audit top-up cashless vs pembayaran pos Saku — terpisah dari perbaikan kas operasional pondok.</p>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/keuangan/saku.php')) ?>"><i class="fa-solid fa-coins me-1"></i> Dashboard saku</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/keuangan/perbaikan-kas.php')) ?>"><i class="fa-solid fa-wrench me-1"></i> Perbaikan kas pondok</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/keuangan/neraca.php?view=saku')) ?>"><i class="fa-solid fa-scale-balanced me-1"></i> Status titipan saku</a>
</div>

<?php if ($sakuAuditPerSantri === [] && $sakuTanpaTopup === []): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check me-1"></i>
    Pembayaran saku dan saldo cashless selaras. Tidak ada yang perlu diperbaiki.
</div>
<?php else: ?>
<?php require __DIR__ . '/partials/perbaikan_saku_audit.php'; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
