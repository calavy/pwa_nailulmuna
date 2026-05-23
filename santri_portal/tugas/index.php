<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc_portal.php';
require_once __DIR__ . '/../../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../../helpers/app_path.php';

ensure_akademik_ikhtibar_tables($pdo);

$santriId = (int) ($santriPortalRow['id'] ?? 0);
$tingkatan = (string) ($santriPortalRow['tingkatan'] ?? '');
$tugasList = ikhtibar_tugas_tersedia_santri($pdo, $santriId, $tingkatan);

$namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));
require_once __DIR__ . '/../../includes/auth_portal_layout.php';

auth_portal_layout_begin([
    'title' => 'Tugas Ikhtibar',
    'welcome' => 'Tugas & Ujian',
    'subtitle' => (string) ($santriPortalRow['nama_santri'] ?? ''),
    'nama_ponpes' => $namaPonpes,
    'max_width' => '520px',
    'accent' => 'teal',
]);
$ok = get_flash('success');
$err = get_flash('error');
?>
<?php if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if ($tugasList === []): ?>
    <p class="text-muted small text-center">Belum ada tugas yang dipublikasikan untuk Anda.</p>
<?php else: ?>
    <div class="d-grid gap-2">
        <?php foreach ($tugasList as $t):
            $tid = (int) $t['id'];
            $st = (string) ($t['sesi_status'] ?? 'menunggu');
            $label = match ($st) {
                'selesai' => 'Selesai',
                'berjalan' => 'Lanjutkan',
                default => 'Mulai',
            };
            $btnClass = $st === 'selesai' ? 'btn-outline-secondary' : 'btn-auth-primary';
            ?>
            <a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/kerjakan.php?id=' . $tid)) ?>" class="btn <?= $btnClass ?> text-start py-3">
                <strong><?= htmlspecialchars((string) $t['judul']) ?></strong>
                <span class="d-block small opacity-75"><?= htmlspecialchars(ikhtibar_hari_label((int) ($t['hari_ke'] ?? 0))) ?> · <?= htmlspecialchars((string) $t['tanggal']) ?> · <?= (int) ($t['durasi_menit'] ?? 0) ?> menit</span>
                <span class="badge bg-light text-dark mt-1"><?= htmlspecialchars($label) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<p class="text-center mt-3 mb-0">
    <a href="<?= htmlspecialchars(app_href('/santri_portal/index.php')) ?>" class="small">← Beranda portal</a>
</p>
<?php
auth_portal_layout_end([], true);
