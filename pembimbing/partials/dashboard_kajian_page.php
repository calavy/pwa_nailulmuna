<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $pbJadwalSlots */
/** @var array<int,string> $pbHariLabels */

$pbJadwalSlots = is_array($pbJadwalSlots ?? null) ? $pbJadwalSlots : [];
$pbHariLabels = is_array($pbHariLabels ?? null) ? $pbHariLabels : [];
?>
<section class="pb-dash-subpage" aria-label="Jadwal kajian saya">
    <div class="pb-dash-subpage__card">
        <h2 class="pb-dash-subpage__title h6 mb-3">Jadwal kajian saya</h2>
        <?php if ($pbJadwalSlots === []): ?>
            <p class="small text-muted mb-0">Belum ada jadwal kajian terdaftar untuk Anda.</p>
        <?php else: ?>
            <ul class="pb-dash-simple-jadwal list-unstyled mb-0">
                <?php foreach ($pbJadwalSlots as $slot): ?>
                    <?php
                    $hariKe = (int) ($slot['hari_ke'] ?? 0);
                    $hariLabel = $pbHariLabels[$hariKe] ?? ('Hari ' . $hariKe);
                    $jamMulai = substr((string) ($slot['jam_mulai'] ?? ''), 0, 5);
                    $jamSelesai = substr((string) ($slot['jam_selesai'] ?? ''), 0, 5);
                    ?>
                    <li class="pb-dash-simple-jadwal__item">
                        <span class="pb-dash-simple-jadwal__hari"><?= htmlspecialchars($hariLabel) ?></span>
                        <span class="pb-dash-simple-jadwal__jam"><?= htmlspecialchars($jamMulai) ?><?= $jamSelesai !== '' ? '–' . htmlspecialchars($jamSelesai) : '' ?></span>
                        <span class="pb-dash-simple-jadwal__nama"><?= htmlspecialchars((string) ($slot['nama_kegiatan'] ?? '')) ?></span>
                        <?php if (trim((string) ($slot['tingkatan'] ?? '')) !== ''): ?>
                            <span class="pb-dash-simple-jadwal__tk text-muted"><?= htmlspecialchars((string) $slot['tingkatan']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
