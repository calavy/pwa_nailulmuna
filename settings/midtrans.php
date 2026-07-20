<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/midtrans.php';

require_roles(['admin', 'pengurus']);

midtrans_ensure_schema($pdo);
ensure_keuangan_transaksi_tables($pdo);
$akunRows = keuangan_fetch_akun_aktif($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'simpan_midtrans') {
        save_setting($pdo, 'midtrans_enabled', isset($_POST['midtrans_enabled']) ? '1' : '0');
        $mode = strtolower(trim((string) ($_POST['midtrans_mode'] ?? 'sandbox')));
        $serverKey = trim((string) ($_POST['midtrans_server_key'] ?? ''));
        $clientKey = trim((string) ($_POST['midtrans_client_key'] ?? ''));

        $looksSandbox = str_starts_with($serverKey, 'SB-') || str_starts_with($clientKey, 'SB-');
        $looksProd = $serverKey !== '' && !str_starts_with($serverKey, 'SB-');
        if ($looksSandbox) {
            $mode = 'sandbox';
        } elseif ($looksProd) {
            $mode = 'production';
        }

        save_setting($pdo, 'midtrans_mode', $mode === 'production' ? 'production' : 'sandbox');
        save_setting($pdo, 'midtrans_server_key', $serverKey);
        save_setting($pdo, 'midtrans_client_key', $clientKey);
        $akunId = (int) ($_POST['midtrans_akun_id'] ?? 0);
        if ($akunId <= 0 && $akunRows !== []) {
            $akunId = (int) ($akunRows[0]['id'] ?? 0);
        }
        save_setting($pdo, 'midtrans_akun_id', $akunId > 0 ? (string) $akunId : '0');
        set_flash('success', 'Pengaturan Midtrans disimpan. Mode: ' . ($mode === 'production' ? 'Production' : 'Sandbox') . '.');
        header('Location: ' . app_href('/settings/midtrans.php'));
        exit;
    }
    if ($action === 'pakai_sandbox') {
        // Paksa mode sandbox untuk uji coba (key production harus diganti SB-)
        save_setting($pdo, 'midtrans_mode', 'sandbox');
        save_setting($pdo, 'midtrans_enabled', '1');
        set_flash('success', 'Mode disetel ke Sandbox. Ganti Server/Client Key ke key berawalan SB- dari dashboard sandbox, lalu Simpan.');
        header('Location: ' . app_href('/settings/midtrans.php'));
        exit;
    }
    if ($action === 'test_snap') {
        $test = midtrans_test_snap_connection($pdo);
        set_flash($test['ok'] ? 'success' : 'error', $test['message']);
        header('Location: ' . app_href('/settings/midtrans.php'));
        exit;
    }
}

$v = static fn(string $k, string $d = '') => (string) app_setting($pdo, $k, $d);
$notifUrl = midtrans_notification_url();
$enabled = $v('midtrans_enabled', '0') === '1';
$mode = $v('midtrans_mode', 'sandbox');
$akunId = (int) $v('midtrans_akun_id', '0');
if ($akunId <= 0 && $akunRows !== []) {
    $akunId = (int) ($akunRows[0]['id'] ?? 0);
}
$ready = midtrans_readiness_checklist($pdo);
$keyCheck = midtrans_key_mode_check($pdo);
$isLocalHost = (bool) preg_match('/localhost|127\.0\.0\.1/i', $notifUrl);
$isNgrok = (bool) preg_match('/ngrok/i', $notifUrl);
$serverKeyNow = midtrans_server_key($pdo);
$hasProdKeys = $serverKeyNow !== '' && !str_starts_with($serverKeyNow, 'SB-');
$hasSandboxKeys = str_starts_with($serverKeyNow, 'SB-');

$pageTitle = 'Pembayaran Midtrans';
$settingsNavActive = '/settings/midtrans.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/settings_nav.php';
?>

<div class="mb-3">
    <h1 class="h4 mb-1">Pembayaran Midtrans</h1>
    <p class="text-muted small mb-0">
        Wali bayar online lewat popup Snap (QRIS / VA). Setelah lunas, tercatat otomatis —
        bendahara tidak perlu input ulang.
    </p>
</div>

<?php if ($hasProdKeys): ?>
<div class="alert alert-danger small">
    <strong>Key Production terdeteksi</strong> (<code>Mid-server-…</code>), sementara uji coba biasanya di
    <a href="https://dashboard.sandbox.midtrans.com" target="_blank" rel="noopener">dashboard Sandbox</a>.
    Ganti ke key berawalan <code>SB-</code> dari menu <strong>INTEGRASI</strong> di dashboard sandbox.
</div>
<?php endif; ?>

<div class="card shadow-sm mb-3 border-warning">
    <div class="card-header bg-white small fw-semibold">Sinkron dengan dashboard Sandbox</div>
    <div class="card-body small">
        <ol class="mb-3 ps-3">
            <li>Buka <a href="https://dashboard.sandbox.midtrans.com" target="_blank" rel="noopener">dashboard.sandbox.midtrans.com</a> (Bak pasir).</li>
            <li>Sidebar <strong>INTEGRASI</strong> → salin <strong>Server Key</strong> &amp; <strong>Client Key</strong> (harus <code>SB-Mid-…</code>).</li>
            <li>Tempel di form di bawah → pastikan mode <strong>Sandbox</strong> → Aktifkan → <strong>Simpan</strong>.</li>
            <li>Salin <strong>Payment Notification URL</strong> di bawah ke Midtrans → Settings → Configuration (pakai URL <strong>ngrok</strong> yang sama dengan portal wali).</li>
            <li>Klik <strong>Uji koneksi Snap</strong>. Jika OK, uji di portal wali: Bayar online → Lanjut bayar → QRIS/VA.</li>
        </ol>
        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="pakai_sandbox">
            <button type="submit" class="btn btn-sm btn-outline-warning">1) Setel mode Sandbox sekarang</button>
        </form>
        <form method="post" class="d-inline ms-1">
            <input type="hidden" name="action" value="test_snap">
            <button type="submit" class="btn btn-sm btn-outline-primary" <?= $ready['ready'] ? '' : 'disabled title="Lengkapi key SB- dulu"' ?>>
                2) Uji koneksi Snap
            </button>
        </form>
        <?php if ($hasSandboxKeys): ?>
            <span class="badge text-bg-success ms-2 align-middle">Key SB- terdeteksi</span>
        <?php elseif ($hasProdKeys): ?>
            <span class="badge text-bg-danger ms-2 align-middle">Masih key Production</span>
        <?php else: ?>
            <span class="badge text-bg-secondary ms-2 align-middle">Key belum diisi</span>
        <?php endif; ?>
    </div>
</div>

<?php if ($ready['ready']): ?>
    <div class="alert alert-success py-2 small">Midtrans siap. Portal wali menampilkan tombol <strong>Bayar online</strong> jika ada sisa tagihan.</div>
<?php elseif ($enabled): ?>
    <div class="alert alert-warning py-2 small">Belum siap dipakai. Periksa checklist (sering karena key sandbox/production tidak cocok).</div>
<?php else: ?>
    <div class="alert alert-secondary py-2 small">Midtrans nonaktif. Centang aktifkan setelah mengisi key SB-.</div>
<?php endif; ?>

<?php if (!$keyCheck['ok'] && midtrans_server_key($pdo) !== ''): ?>
    <div class="alert alert-danger py-2 small"><?= htmlspecialchars($keyCheck['message']) ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white small fw-semibold">Checklist kesiapan uji coba</div>
    <ul class="list-group list-group-flush small">
        <?php foreach ($ready['items'] as $it): ?>
            <li class="list-group-item d-flex align-items-start gap-2">
                <span class="<?= !empty($it['ok']) ? 'text-success' : 'text-danger' ?>">
                    <i class="fa-solid <?= !empty($it['ok']) ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
                </span>
                <span><?= htmlspecialchars((string) $it['label']) ?></span>
            </li>
        <?php endforeach; ?>
        <li class="list-group-item d-flex align-items-start gap-2">
            <span class="<?= $isNgrok ? 'text-success' : ($isLocalHost ? 'text-danger' : 'text-warning') ?>">
                <i class="fa-solid <?= $isNgrok ? 'fa-circle-check' : 'fa-circle-info' ?>"></i>
            </span>
            <span>
                Notification URL
                <?php if ($isNgrok): ?>
                    memakai ngrok — tempel ke dashboard Midtrans.
                <?php elseif ($isLocalHost): ?>
                    masih localhost — buka settings lewat ngrok lalu salin ulang.
                <?php else: ?>
                    publik — tempel ke dashboard Midtrans.
                <?php endif; ?>
            </span>
        </li>
    </ul>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="simpan_midtrans">
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="midtrans_enabled" name="midtrans_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="midtrans_enabled">Aktifkan pembayaran Midtrans di portal wali</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Mode</label>
                <select name="midtrans_mode" class="form-select">
                    <option value="sandbox" <?= $mode !== 'production' ? 'selected' : '' ?>>Sandbox (uji coba)</option>
                    <option value="production" <?= $mode === 'production' ? 'selected' : '' ?>>Production</option>
                </select>
                <div class="form-text">Otomatis Sandbox jika key berawalan <code>SB-</code>.</div>
            </div>
            <div class="col-md-8">
                <label class="form-label">Akun kas/bank penerima (jurnal)</label>
                <select name="midtrans_akun_id" class="form-select" required>
                    <option value="">— Pilih akun —</option>
                    <?php foreach ($akunRows as $ak): ?>
                        <option value="<?= (int) $ak['id'] ?>" <?= $akunId === (int) $ak['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $ak['jenis_akun']) ?> — <?= htmlspecialchars((string) $ak['nama_akun']) ?>
                            <?php if (!empty($ak['nama_bank'])): ?>
                                (<?= htmlspecialchars((string) $ak['nama_bank']) ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Server Key (Sandbox)</label>
                <input type="password" name="midtrans_server_key" class="form-control font-monospace" value="<?= htmlspecialchars($v('midtrans_server_key')) ?>" autocomplete="off" placeholder="SB-Mid-server-…">
                <div class="form-text">Dari INTEGRASI dashboard sandbox — awalan <code>SB-</code>.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Client Key (Sandbox)</label>
                <input type="text" name="midtrans_client_key" class="form-control font-monospace" value="<?= htmlspecialchars($v('midtrans_client_key')) ?>" autocomplete="off" placeholder="SB-Mid-client-…">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">Simpan pengaturan</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white small fw-semibold">Webhook — Payment Notification URL</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            Tempel di dashboard Midtrans (sandbox) → <strong>Settings → Configuration → Payment Notification URL</strong>.
            Harus domain yang sama dengan akses portal wali (ngrok).
        </p>
        <div class="input-group">
            <input type="text" class="form-control font-monospace small" id="midtransNotifUrl" readonly value="<?= htmlspecialchars($notifUrl) ?>">
            <button type="button" class="btn btn-outline-secondary" id="btnCopyNotif">Salin</button>
        </div>
        <?php if ($isLocalHost): ?>
            <div class="alert alert-warning py-2 small mt-2 mb-0">
                URL masih <strong>localhost</strong>. Buka halaman ini lewat
                <code>https://….ngrok-free.dev/…/settings/midtrans.php</code> lalu salin ulang.
            </div>
        <?php elseif ($isNgrok): ?>
            <div class="alert alert-success py-2 small mt-2 mb-0">
                URL <strong>ngrok</strong> siap ditempel ke dashboard Midtrans sandbox.
            </div>
        <?php else: ?>
            <p class="small text-muted mt-2 mb-0">URL publik terdeteksi.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white small fw-semibold">Uji di portal wali</div>
    <div class="card-body small">
        <ol class="mb-0 ps-3">
            <li>Pastikan checklist hijau + <em>Uji koneksi Snap</em> sukses.</li>
            <li>Login portal wali (santri ada sisa tagihan) lewat URL ngrok yang sama.</li>
            <li><strong>Keuangan → Tagihan</strong> → Bayar online → pilih bulan → <strong>Lanjut bayar</strong>.</li>
            <li>Di Snap pilih QRIS atau VA. Panduan simulator:
                <a href="https://docs.midtrans.com/docs/sandbox-testing" target="_blank" rel="noopener">sandbox testing</a>.
            </li>
        </ol>
    </div>
</div>

<script>
document.getElementById('btnCopyNotif')?.addEventListener('click', function () {
    const el = document.getElementById('midtransNotifUrl');
    if (!el) return;
    navigator.clipboard.writeText(el.value).then(function () {
        alert('URL disalin. Tempel ke dashboard Midtrans sandbox.');
    }).catch(function () {
        el.select();
        document.execCommand('copy');
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
