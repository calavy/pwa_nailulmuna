<?php

declare(strict_types=1);

/**
 * @var PDO $pdo
 * @var list<string> $tingkatanList
 * @var string $tkPeran pembimbing|munawib
 * @var list<array<string,mixed>> $tkPenerimaRows
 * @var int $tkSelectedId
 * @var list<string> $tkSelectedList
 */

$tkPeran = $tkPeran ?? 'pembimbing';
$tkPenerimaRows = $tkPenerimaRows ?? [];
$tkSelectedId = (int) ($tkSelectedId ?? 0);
$tkSelectedList = $tkSelectedList ?? [];
$idParam = $tkPeran === 'pembimbing' ? 'pembimbing_id' : 'munawib_id';
$simpanAction = $tkPeran === 'pembimbing' ? 'simpan_pembimbing_tingkatan' : 'simpan_munawib_tingkatan';
?>
<ul class="nav nav-pills nav-fill mb-3">
    <li class="nav-item">
        <a class="nav-link<?= $tkPeran === 'pembimbing' ? ' active' : '' ?>"
           href="<?= htmlspecialchars(app_href('/akademik/setoran_penerima.php?tab=tingkatan&peran=pembimbing')) ?>">Pembimbing</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $tkPeran === 'munawib' ? ' active' : '' ?>"
           href="<?= htmlspecialchars(app_href('/akademik/setoran_penerima.php?tab=tingkatan&peran=munawib')) ?>">Munawib</a>
    </li>
</ul>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold"><?= $tkPeran === 'pembimbing' ? 'Pembimbing' : 'Munawib' ?> ditugaskan</div>
            <div class="list-group list-group-flush">
                <?php if ($tkPenerimaRows === []): ?>
                    <div class="list-group-item text-muted small">
                        Belum ada petugas. <a href="<?= htmlspecialchars(app_href('/akademik/setoran_penerima.php?tab=tambah')) ?>">Tugaskan baru</a>.
                    </div>
                <?php else: ?>
                    <?php foreach ($tkPenerimaRows as $row): ?>
                        <?php
                        $rid = (int) ($row['id'] ?? $row['ref_id'] ?? 0);
                        $nama = (string) ($row['nama'] ?? $row['nama_pembimbing'] ?? '');
                        ?>
                        <a class="list-group-item list-group-item-action<?= $rid === $tkSelectedId ? ' active' : '' ?>"
                           href="<?= htmlspecialchars(app_href('/akademik/setoran_penerima.php?tab=tingkatan&peran=' . $tkPeran . '&ref_id=' . $rid)) ?>">
                            <div class="fw-semibold"><?= htmlspecialchars($nama) ?></div>
                            <div class="small opacity-75"><?= htmlspecialchars((string) ($row['nip'] ?? '')) ?></div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <?php if ($tkSelectedId > 0): ?>
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Tingkatan yang boleh diterima setoran</div>
            <div class="card-body">
                <form method="post" class="d-grid gap-3">
                    <input type="hidden" name="action" value="<?= htmlspecialchars($simpanAction) ?>">
                    <input type="hidden" name="<?= htmlspecialchars($idParam) ?>" value="<?= $tkSelectedId ?>">
                    <p class="small text-muted mb-0">Opsional saat tugas baru; wajib agar scan santri sesuai tingkatan. Terdaftar di penerima setoran sudah bisa login portal.</p>
                    <div class="row g-2">
                        <?php foreach ($tingkatanList as $tk): ?>
                            <div class="col-6 col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="tingkatan[]" value="<?= htmlspecialchars($tk) ?>"
                                           id="penerima-tk-<?= htmlspecialchars(md5($tkPeran . $tk)) ?>"
                                           <?= in_array($tk, $tkSelectedList, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="penerima-tk-<?= htmlspecialchars(md5($tkPeran . $tk)) ?>"><?= htmlspecialchars($tk) ?></label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($tingkatanList === []): ?>
                        <div class="alert alert-warning small mb-0">Belum ada data tingkatan di sistem.</div>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary">Simpan tingkatan</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
