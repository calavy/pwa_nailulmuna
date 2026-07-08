<?php

declare(strict_types=1);

/** @var array<string, mixed> $checklistKas */
/** @var callable(int): string $formatRupiah */

$semuaCocok = !empty($checklistKas['semua_cocok']);
$collapseId = 'keuChecklistKasCollapse';
$fmt = $formatRupiah;

$renderCocok = static function (bool $ok, int $selisih) use ($fmt): string {
    if ($ok) {
        return '<span class="text-success"><i class="fa-solid fa-circle-check me-1"></i>Cocok</span>';
    }

    return '<span class="text-danger"><i class="fa-solid fa-circle-xmark me-1"></i>Selisih ' . htmlspecialchars($fmt(abs($selisih))) . '</span>';
};

?>
<div class="card border-0 shadow-sm mb-3 keu-checklist-kas">
    <div class="card-header bg-white py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <button class="btn btn-link text-decoration-none text-dark p-0 fw-semibold text-start collapsed flex-grow-1"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#<?= htmlspecialchars($collapseId) ?>"
            aria-expanded="<?= $semuaCocok ? 'false' : 'true' ?>"
            aria-controls="<?= htmlspecialchars($collapseId) ?>">
            <i class="fa-solid fa-list-check me-1 text-primary"></i>
            Checklist keselarasan kas
            <?php if ($semuaCocok): ?>
                <span class="badge text-bg-success ms-2">Sehat</span>
            <?php else: ?>
                <span class="badge text-bg-warning text-dark ms-2">Perlu cek</span>
            <?php endif; ?>
        </button>
        <span class="small text-muted">Toleransi selisih &lt; <?= htmlspecialchars($fmt((int) ($checklistKas['toleransi'] ?? 1000))) ?></span>
    </div>
    <div id="<?= htmlspecialchars($collapseId) ?>" class="collapse <?= $semuaCocok ? '' : 'show' ?>">
        <div class="card-body pt-2 pb-3">
            <p class="small text-muted mb-3">
                Tiga angka berikut harus selaras (uang nyata di kas/bank). Piutang tagihan santri <strong>tidak</strong> perlu sama dengan angka ini.
            </p>

            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle mb-0 keu-checklist-kas-table">
                    <thead class="table-light">
                        <tr>
                            <th>Sumber</th>
                            <th class="text-end">Nominal</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <span class="badge text-bg-success me-1">1</span>
                                Saldo likuid (dashboard)
                            </td>
                            <td class="text-end font-monospace fw-semibold"><?= htmlspecialchars($fmt((int) ($checklistKas['angka_dashboard'] ?? 0))) ?></td>
                            <td class="small text-muted">
                                Kas + bank per akun
                                <?php if (trim((string) ($checklistKas['kas_as_of'] ?? '')) !== ''): ?>
                                    · <?= htmlspecialchars((string) $checklistKas['kas_as_of']) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <span class="badge text-bg-primary me-1">2</span>
                                <a href="<?= htmlspecialchars(app_href('/keuangan/rekap-kas-bulan.php')) ?>">Saldo uang nyata (rekap TA)</a>
                            </td>
                            <td class="text-end font-monospace fw-semibold"><?= htmlspecialchars($fmt((int) ($checklistKas['angka_rekap'] ?? 0))) ?></td>
                            <td class="small text-muted">
                                <?= htmlspecialchars((string) ($checklistKas['rekap_ta_label'] ?? 'TA aktif')) ?>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <span class="badge text-bg-secondary me-1">3</span>
                                <a href="<?= htmlspecialchars(app_href('/keuangan/neraca.php')) ?>">Kas neraca</a>
                            </td>
                            <td class="text-end font-monospace fw-semibold"><?= htmlspecialchars($fmt((int) ($checklistKas['angka_neraca'] ?? 0))) ?></td>
                            <td class="small text-muted">
                                Kas &amp; setara kas (buku)
                                <?php if (trim((string) ($checklistKas['neraca_as_of'] ?? '')) !== ''): ?>
                                    · <?= htmlspecialchars((string) $checklistKas['neraca_as_of']) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="row g-2 mb-3 small">
                <div class="col-md-4">
                    <div class="border rounded px-2 py-1 h-100">
                        <div class="text-muted">Dashboard vs Rekap</div>
                        <?= $renderCocok((bool) ($checklistKas['cocok_dashboard_rekap'] ?? false), (int) ($checklistKas['selisih_dashboard_rekap'] ?? 0)) ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded px-2 py-1 h-100">
                        <div class="text-muted">Dashboard vs Neraca</div>
                        <?= $renderCocok((bool) ($checklistKas['cocok_dashboard_neraca'] ?? false), (int) ($checklistKas['selisih_dashboard_neraca'] ?? 0)) ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded px-2 py-1 h-100">
                        <div class="text-muted">Rekap vs Neraca</div>
                        <?= $renderCocok((bool) ($checklistKas['cocok_rekap_neraca'] ?? false), (int) ($checklistKas['selisih_rekap_neraca'] ?? 0)) ?>
                    </div>
                </div>
            </div>

            <p class="small text-muted mb-2">
                <strong>Rumus rekap:</strong> Saldo awal TA + masuk (iuran + saku + donasi + lain) − keluar operasional = saldo hitung.
                Saldo hitung harus ≈ saldo fisik (uang nyata).
            </p>

            <p class="small fw-semibold mb-2">Langkah operator jika ada selisih</p>
            <ol class="small mb-0 ps-3">
                <?php foreach ((array) ($checklistKas['langkah'] ?? []) as $langkah): ?>
                    <?php
                    $selesai = $langkah['selesai'] ?? null;
                    $icon = $selesai === true ? 'fa-check text-success' : ($selesai === false ? 'fa-circle text-warning' : 'fa-arrow-right text-muted');
                    ?>
                    <li class="mb-1">
                        <i class="fa-solid <?= $icon ?> me-1" style="width:1rem"></i>
                        <a href="<?= htmlspecialchars((string) ($langkah['href'] ?? '#')) ?>"><?= htmlspecialchars((string) ($langkah['label'] ?? '')) ?></a>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
</div>
<style>
.keu-checklist-kas .card-header .btn-link::after { content: none; }
.keu-checklist-kas-table td, .keu-checklist-kas-table th { font-size: 0.875rem; }
</style>
