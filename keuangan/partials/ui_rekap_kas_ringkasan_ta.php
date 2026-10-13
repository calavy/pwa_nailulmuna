<?php

declare(strict_types=1);

/**
 * Kartu Ringkasan TA — rekap kas bulanan (UI ringkas).
 *
 * @var array<string, mixed> $rekap
 * @var array<string, mixed> $tagTot
 * @var int $selisihRekap
 * @var callable(int): string $fmt
 */
$rekap = is_array($rekap ?? null) ? $rekap : [];
$tagTot = is_array($tagTot ?? null) ? $tagTot : [];
$selisihRekap = (int) ($selisihRekap ?? 0);
if (!is_callable($fmt ?? null)) {
    return;
}

$bulanBerjalan = (int) ($rekap['bulan_berjalan'] ?? 0);
$saldoAkhirFisik = (int) ($rekap['saldo_akhir_uang_nyata'] ?? $rekap['saldo_akhir_fisik'] ?? 0);
$saldoAkhirBuku = (int) ($rekap['saldo_akhir_hitung'] ?? $rekap['saldo_akhir'] ?? 0);
$saldoAkhirHint = 'Buku ' . $fmt($saldoAkhirBuku)
    . ' · selisih ' . ($selisihRekap !== 0 ? $fmt(abs($selisihRekap)) : '0');

$kasItems = [
    [
        'label' => 'Saldo awal TA',
        'value' => htmlspecialchars($fmt((int) ($rekap['saldo_awal_ta'] ?? 0))),
        'hint' => '',
        'value_class' => 'keu-money',
    ],
    [
        'label' => 'Total masuk',
        'value' => htmlspecialchars($fmt((int) ($rekap['total']['masuk_total'] ?? 0))),
        'hint' => '',
        'value_class' => 'text-success keu-money',
    ],
    [
        'label' => 'Total keluar',
        'value' => htmlspecialchars($fmt((int) ($rekap['total']['keluar'] ?? 0))),
        'hint' => '',
        'value_class' => 'text-danger keu-money',
    ],
    [
        'label' => 'Saldo akhir',
        'value' => htmlspecialchars($fmt($saldoAkhirFisik)),
        'hint' => $saldoAkhirHint,
        'value_class' => 'keu-money',
    ],
];

$tagItems = [
    [
        'label' => 'Target tagihan',
        'value' => htmlspecialchars($fmt((int) ($tagTot['expected'] ?? 0))),
        'hint' => '',
        'mod' => 'tagihan',
        'value_class' => 'keu-money',
    ],
    [
        'label' => 'Terbayar',
        'value' => htmlspecialchars($fmt((int) ($tagTot['paid'] ?? 0))),
        'hint' => (int) ($tagTot['pct'] ?? 0) . '%',
        'mod' => 'tagihan',
        'value_class' => 'text-success keu-money',
    ],
    [
        'label' => 'Sisa tagihan',
        'value' => htmlspecialchars($fmt((int) ($tagTot['sisa'] ?? 0))),
        'hint' => '',
        'mod' => 'tagihan',
        'value_class' => 'text-warning keu-money',
    ],
];

$selisihValue = $selisihRekap !== 0
    ? htmlspecialchars($fmt($selisihRekap))
    : '0';
$selisihHint = $selisihRekap !== 0
    ? 'Perlu dicek'
    : 'Selaras buku';

?>
<div class="card border rekap-kas-ringkasan-ta rekap-kas-ringkasan-ta--compact mb-2" id="rekap-ringkasan-ta">
    <div class="card-header bg-white border-bottom py-1 d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold small mb-0">Ringkasan TA</span>
        <span class="badge text-bg-light border fw-normal">Bulan 1–<?= $bulanBerjalan > 0 ? $bulanBerjalan : '—' ?></span>
    </div>
    <div class="card-body p-2">
        <div class="rekap-kas-ringkasan-ta__bands">
            <div class="rekap-kas-ringkasan-ta__group rekap-kas-ringkasan-ta__group--kas">
                <div class="rekap-kas-ringkasan-ta__group-head">
                    <h3 class="rekap-kas-ringkasan-ta__group-title">Mutasi kas operasional</h3>
                    <p class="rekap-kas-ringkasan-ta__group-note rekap-kas-ringkasan-ta__group-note--placeholder small text-muted mb-0" aria-hidden="true">&#8203;</p>
                </div>
                <div class="rekap-kas-ringkasan-ta__stats rekap-kas-ringkasan-ta__stats--kas">
                    <?php foreach ($kasItems as $item):
                        $statMod = (string) ($item['mod'] ?? 'kas');
                        $valueClass = trim((string) ($item['value_class'] ?? 'keu-money'));
                        $hint = (string) ($item['hint'] ?? '');
                        ?>
                    <div class="rekap-kas-stat rekap-kas-stat--<?= htmlspecialchars($statMod) ?>">
                        <span class="rekap-kas-stat__label"><?= htmlspecialchars((string) $item['label']) ?></span>
                        <span class="rekap-kas-stat__value <?= htmlspecialchars($valueClass) ?>"><?= (string) $item['value'] ?></span>
                        <span class="rekap-kas-stat__hint"><?= $hint !== '' ? htmlspecialchars($hint) : '' ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="rekap-kas-ringkasan-ta__group rekap-kas-ringkasan-ta__group--tagihan">
                <div class="rekap-kas-ringkasan-ta__group-head">
                    <h3 class="rekap-kas-ringkasan-ta__group-title">Tagihan santri</h3>
                    <p class="rekap-kas-ringkasan-ta__group-note small text-muted mb-0">Bukan kas masuk/keluar.</p>
                </div>
                <div class="rekap-kas-ringkasan-ta__stats rekap-kas-ringkasan-ta__stats--tagihan">
                    <?php foreach ($tagItems as $item):
                        $statMod = (string) ($item['mod'] ?? 'tagihan');
                        $valueClass = trim((string) ($item['value_class'] ?? 'keu-money'));
                        $hint = (string) ($item['hint'] ?? '');
                        ?>
                    <div class="rekap-kas-stat rekap-kas-stat--<?= htmlspecialchars($statMod) ?>">
                        <span class="rekap-kas-stat__label"><?= htmlspecialchars((string) $item['label']) ?></span>
                        <span class="rekap-kas-stat__value <?= htmlspecialchars($valueClass) ?>"><?= (string) $item['value'] ?></span>
                        <span class="rekap-kas-stat__hint"><?= $hint !== '' ? htmlspecialchars($hint) : '' ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="rekap-kas-ringkasan-ta__footer<?= $selisihRekap !== 0 ? ' rekap-kas-ringkasan-ta__footer--warn' : '' ?>">
            <span class="rekap-kas-ringkasan-ta__footer-label">Selisih fisik vs buku</span>
            <span class="rekap-kas-ringkasan-ta__footer-value keu-money<?= $selisihRekap !== 0 ? ' text-warning' : '' ?>"><?= $selisihValue ?></span>
            <span class="rekap-kas-ringkasan-ta__footer-hint small text-muted"><?= htmlspecialchars($selisihHint) ?></span>
        </div>
    </div>
</div>
