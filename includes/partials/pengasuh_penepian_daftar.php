<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $penepianRows */
/** @var string $penepianTanggalAcuan */

require_once __DIR__ . '/../../helpers/santri_penepian_keaktifan.php';

$penepianRows = $penepianRows ?? [];
$penepianTanggalAcuan = trim((string) ($penepianTanggalAcuan ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $penepianTanggalAcuan)) {
    $penepianTanggalAcuan = date('Y-m-d');
}

if ($penepianRows === []) {
    ?>
    <div class="yp-empty-inline">Tidak ada santri menepi pada tanggal ini.</div>
    <?php
    return;
}
?>
<div class="table-responsive card border-0 shadow-sm">
    <table class="table table-sm mb-0 align-middle">
        <thead class="table-light">
            <tr>
                <th>Santri</th>
                <th>Kelas</th>
                <th class="text-end">Menepi</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($penepianRows as $pn):
            $hari = santri_penepian_hari_berjalan(
                (string) ($pn['tanggal_mulai'] ?? ''),
                $penepianTanggalAcuan
            );
            $hariLabel = $hari . ' hari';
            ?>
            <tr>
                <td>
                    <div class="fw-semibold"><?= htmlspecialchars((string) ($pn['nama_santri'] ?? '')) ?></div>
                    <?php if (trim((string) ($pn['nis'] ?? '')) !== ''): ?>
                        <div class="small text-muted"><?= htmlspecialchars((string) $pn['nis']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string) ($pn['tingkatan'] ?? '—')) ?></td>
                <td class="text-end text-nowrap fw-semibold"><?= htmlspecialchars($hariLabel) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
