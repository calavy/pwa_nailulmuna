<?php

declare(strict_types=1);

/** @var PDO $pdo */
/** @var array<string, mixed> $waliSantriRow */

require_once __DIR__ . '/../../helpers/akademik_ikhtibar.php';

ensure_akademik_ikhtibar_tables($pdo);

$santriId = (int) ($waliSantriRow['id'] ?? 0);
$nilaiGroups = $santriId > 0 ? ikhtibar_nilai_tugas_wali($pdo, $santriId) : [];
?>
<p class="small text-muted mb-3">
    Nilai tugas/ujian ikhtibar yang sudah <strong>dipublikasikan</strong> oleh admin.
    Dikelompokkan menurut pembimbing dan mata pelajaran/kegiatan.
</p>

<?php if ($nilaiGroups === []): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center text-muted py-4">
            <i class="fa-solid fa-clipboard-list fa-2x mb-2 opacity-50"></i>
            <p class="mb-0 small">Belum ada nilai tugas yang dipublikasikan untuk anak Anda.</p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($nilaiGroups as $grp): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-2">
                <div class="fw-semibold small"><?= htmlspecialchars((string) ($grp['pembimbing_nama'] ?? 'Pembimbing')) ?></div>
                <div class="text-muted small"><?= htmlspecialchars((string) ($grp['mapel_label'] ?? '')) ?></div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Tugas</th>
                                <th>Tanggal</th>
                                <th class="text-end">PG</th>
                                <th class="text-end">Esai</th>
                                <th class="text-end">Total</th>
                                <th>Predikat</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($grp['tugas'] ?? []) as $t): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($t['judul'] ?? '')) ?></td>
                                <td class="text-nowrap small"><?= htmlspecialchars((string) ($t['tanggal'] ?? '')) ?></td>
                                <td class="text-end"><?= $t['skor_pg'] !== null && $t['skor_pg'] !== '' ? htmlspecialchars((string) $t['skor_pg']) . '%' : '—' ?></td>
                                <td class="text-end"><?= $t['skor_esai'] !== null && $t['skor_esai'] !== '' ? htmlspecialchars((string) $t['skor_esai']) : '—' ?></td>
                                <td class="text-end fw-bold"><?= $t['nilai_total'] !== null && $t['nilai_total'] !== '' ? htmlspecialchars((string) $t['nilai_total']) : '—' ?></td>
                                <td>
                                    <?php if (!empty($t['predikat'])): ?>
                                        <span class="badge text-bg-<?= htmlspecialchars((string) ($t['predikat_class'] ?? 'secondary')) ?>"><?= htmlspecialchars((string) $t['predikat']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
