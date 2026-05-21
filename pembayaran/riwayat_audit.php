<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/operasional_audit.php';
require_once __DIR__ . '/../helpers/bendahara_ui.php';

require_roles(['admin', 'pengurus']);
require_lihat_audit_operasional();

ensure_operasional_audit_table($pdo);

$filterModul = trim((string) ($_GET['modul'] ?? ''));
$filterEntityId = (int) ($_GET['entity_id'] ?? 0);
$legacyPid = (int) ($_GET['pembayaran_id'] ?? 0);
if ($legacyPid > 0 && $filterEntityId <= 0) {
    $filterEntityId = $legacyPid;
    if ($filterModul === '') {
        $filterModul = OPERASIONAL_AUDIT_MODUL_KEUANGAN;
    }
}
$limit = (int) ($_GET['limit'] ?? 200);
$logs = operasional_audit_list($pdo, $limit, $filterModul, $filterEntityId);

$modulOptions = [
    '' => 'Semua modul',
    OPERASIONAL_AUDIT_MODUL_KEUANGAN => operasional_audit_modul_label(OPERASIONAL_AUDIT_MODUL_KEUANGAN),
    OPERASIONAL_AUDIT_MODUL_JADWAL => operasional_audit_modul_label(OPERASIONAL_AUDIT_MODUL_JADWAL),
];

$pageTitle = 'Log Audit Operasional';
$bodyClass = keuangan_body_class('bendahara-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_url('pembayaran/riwayat.php')) ?>">Riwayat pembayaran</a>
        · Audit operasional
    </p>
    <h1 class="h3 mb-1 d-flex align-items-center gap-2 flex-wrap">
        <span class="bendahara-page-icon" aria-hidden="true"><i class="fa-solid fa-clipboard-list"></i></span>
        Log audit operasional
    </h1>
    <p class="text-muted mb-0">Riwayat edit, hapus, dan tambah data sensitif (koreksi pembayaran, jadwal kegiatan). Hanya <strong>admin super</strong> yang dapat membuka halaman ini.</p>
</div>

<form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-md-3">
        <label class="form-label small mb-0">Modul</label>
        <select name="modul" class="form-select form-select-sm">
            <?php foreach ($modulOptions as $val => $label): ?>
                <option value="<?= htmlspecialchars($val) ?>" <?= $filterModul === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small mb-0">ID entitas</label>
        <input type="number" name="entity_id" class="form-control form-control-sm" value="<?= $filterEntityId > 0 ? $filterEntityId : '' ?>" placeholder="Semua" min="1">
    </div>
    <div class="col-md-2">
        <label class="form-label small mb-0">Maks. baris</label>
        <select name="limit" class="form-select form-select-sm">
            <?php foreach ([100, 200, 500] as $lm): ?>
                <option value="<?= $lm ?>" <?= $limit === $lm ? 'selected' : '' ?>><?= $lm ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Terapkan</button>
    </div>
    <div class="col-md-2">
        <a class="btn btn-outline-secondary btn-sm w-100" href="<?= htmlspecialchars(app_url('pembayaran/riwayat_audit.php')) ?>">Reset</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Waktu</th>
                    <th>Modul</th>
                    <th>Aksi</th>
                    <th>ID</th>
                    <th>Petugas</th>
                    <th>Alasan</th>
                    <th>Ringkasan</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($logs === []): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Belum ada log audit.</td></tr>
            <?php endif; ?>
            <?php foreach ($logs as $log): ?>
                <?php
                $sebelum = json_decode((string) ($log['data_sebelum'] ?? '{}'), true);
                $sesudah = json_decode((string) ($log['data_sesudah'] ?? 'null'), true);
                $eid = (int) ($log['entity_id'] ?? 0);
                $aksi = (string) ($log['aksi'] ?? '');
                $modul = (string) ($log['modul'] ?? '');
                $ringkas = '—';
                if ($modul === OPERASIONAL_AUDIT_MODUL_KEUANGAN) {
                    $rowR = is_array($sesudah) && $sesudah !== [] ? $sesudah : (is_array($sebelum) ? $sebelum : null);
                    $ringkas = operasional_audit_ringkas_pembayaran($rowR);
                    if ($aksi === 'UPDATE' && is_array($sebelum) && is_array($sesudah)) {
                        $tS = (int) round((float) ($sebelum['total_nominal'] ?? 0));
                        $tA = (int) round((float) ($sesudah['total_nominal'] ?? 0));
                        if ($tS !== $tA) {
                            $ringkas .= ' · Total: Rp ' . number_format($tS, 0, ',', '.') . ' → Rp ' . number_format($tA, 0, ',', '.');
                        }
                    }
                } elseif ($modul === OPERASIONAL_AUDIT_MODUL_JADWAL) {
                    if ($aksi === 'CREATE' && is_array($sesudah)) {
                        $ringkas = 'Baru: ' . (int) ($sesudah['jumlah_baru'] ?? 0) . ' jadwal';
                    } elseif ($aksi === 'UPDATE' && is_array($sesudah) && is_array($sesudah['jadwal_utama'] ?? null)) {
                        $ringkas = operasional_audit_ringkas_jadwal($sesudah['jadwal_utama']);
                    } else {
                        $ringkas = operasional_audit_ringkas_jadwal(is_array($sebelum) ? $sebelum : null);
                    }
                }
                ?>
                <tr>
                    <td class="small text-nowrap"><?= htmlspecialchars((string) ($log['created_at'] ?? '')) ?></td>
                    <td class="small"><?= htmlspecialchars(operasional_audit_modul_label($modul)) ?></td>
                    <td>
                        <?php if ($aksi === 'DELETE'): ?>
                            <span class="badge text-bg-danger">Hapus</span>
                        <?php elseif ($aksi === 'CREATE'): ?>
                            <span class="badge text-bg-success">Tambah</span>
                        <?php else: ?>
                            <span class="badge text-bg-warning text-dark">Edit</span>
                        <?php endif; ?>
                    </td>
                    <td class="font-monospace small">#<?= $eid > 0 ? $eid : '—' ?></td>
                    <td class="small"><?= htmlspecialchars((string) ($log['user_nama'] ?? '—')) ?></td>
                    <td class="small" style="max-width:14rem;"><?= nl2br(htmlspecialchars((string) ($log['alasan'] ?? ''))) ?></td>
                    <td class="small" style="max-width:18rem;"><?= htmlspecialchars($ringkas) ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($modul === OPERASIONAL_AUDIT_MODUL_KEUANGAN && $eid > 0 && $aksi !== 'DELETE'): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_url('pembayaran/riwayat_edit.php?id=' . $eid)) ?>">Buka</a>
                        <?php elseif ($modul === OPERASIONAL_AUDIT_MODUL_JADWAL && $eid > 0 && $aksi !== 'DELETE'): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_url('jadwal/edit.php?id=' . $eid)) ?>">Buka</a>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#audit-detail-<?= (int) $log['id'] ?>">Detail</button>
                    </td>
                </tr>
                <tr class="collapse" id="audit-detail-<?= (int) $log['id'] ?>">
                    <td colspan="8" class="small bg-light">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <strong>Sebelum</strong>
                                <pre class="mb-0 small" style="max-height:200px;overflow:auto;"><?= htmlspecialchars(json_encode($sebelum, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                            </div>
                            <div class="col-md-6">
                                <strong>Sesudah</strong>
                                <pre class="mb-0 small" style="max-height:200px;overflow:auto;"><?= htmlspecialchars(json_encode($sesudah, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
