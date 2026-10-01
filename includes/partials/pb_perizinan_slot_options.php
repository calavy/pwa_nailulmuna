<?php

declare(strict_types=1);

/**
 * Opsi dropdown nama kegiatan (jadwal pembimbing) untuk Perizinan.
 *
 * @var list<array<string, mixed>> $slotsHariIni
 * @var string $slotOptionAct pindah|munawib
 */

if (!isset($slotsHariIni) || !is_array($slotsHariIni)) {
    return;
}

$slotOptionAct = isset($slotOptionAct) ? (string) $slotOptionAct : 'pindah';
if (!in_array($slotOptionAct, ['pindah', 'munawib'], true)) {
    $slotOptionAct = 'pindah';
}

$enabledRows = [];
$disabledRows = [];
foreach ($slotsHariIni as $sl) {
    if (!is_array($sl)) {
        continue;
    }
    $jadwalId = (int) ($sl['jadwal_id'] ?? 0);
    if ($jadwalId <= 0) {
        continue;
    }
    $state = pb_perizinan_slot_option_state($sl, $slotOptionAct);
    if ($state['disabled']) {
        $disabledRows[] = [$sl, $state];
    } else {
        $enabledRows[] = [$sl, $state];
    }
}

if ($enabledRows !== []) {
    echo '<optgroup label="Dapat diajukan">';
    foreach ($enabledRows as $row) {
        echo pb_perizinan_slot_option_html($row[0], $slotOptionAct, $row[1]);
    }
    echo '</optgroup>';
}

if ($disabledRows !== []) {
    $lockedLabel = $slotOptionAct === 'munawib'
        ? 'Kurang dari ' . (int) PB_JADWAL_BATAS_HARI_PENGAJUAN . ' hari sebelum jadwal'
        : 'Tidak dapat dipindah';
    echo '<optgroup label="' . htmlspecialchars($lockedLabel, ENT_QUOTES, 'UTF-8') . '">';
    foreach ($disabledRows as $row) {
        echo pb_perizinan_slot_option_html($row[0], $slotOptionAct, $row[1]);
    }
    echo '</optgroup>';
}
