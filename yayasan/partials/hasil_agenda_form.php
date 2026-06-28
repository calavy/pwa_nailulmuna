<?php

declare(strict_types=1);

/**
 * Form uraian per poin agenda ringkas (hasil musyawarah).
 *
 * @var int $hasilAgendaRapatId
 * @var list<array{agenda:string,uraian:string}> $hasilAgendaRows
 * @var string $hasilAgendaFormAction
 * @var bool $hasilAgendaEmbedded
 */
$hasilAgendaFormAction = (string) ($hasilAgendaFormAction ?? 'simpan_hasil_agenda');
$hasilAgendaEmbedded = !empty($hasilAgendaEmbedded);
?>
<?php if ($hasilAgendaRows === []): ?>
    <p class="text-muted small mb-0">Isi <strong>Agenda ringkas</strong> di menu Rapat terlebih dahulu agar form uraian per agenda muncul di sini.</p>
<?php else: ?>
    <?php if (!$hasilAgendaEmbedded): ?>
    <form method="post" class="d-grid gap-3">
        <input type="hidden" name="action" value="<?= htmlspecialchars($hasilAgendaFormAction) ?>">
    <?php endif; ?>
        <?php foreach ($hasilAgendaRows as $i => $row): ?>
            <div class="border rounded p-3 bg-light">
                <div class="small text-uppercase text-muted fw-semibold mb-1">Agenda <?= (int) $i + 1 ?></div>
                <div class="fw-semibold mb-2"><?= htmlspecialchars((string) ($row['agenda'] ?? '')) ?></div>
                <label class="form-label small mb-1">Uraian hasil musyawarah</label>
                <textarea
                    class="form-control form-control-sm"
                    name="hasil_agenda_uraian[<?= (int) $i ?>]"
                    rows="3"
                    placeholder="Ringkasan pembahasan, keputusan, atau catatan untuk agenda ini…"
                ><?= htmlspecialchars((string) ($row['uraian'] ?? '')) ?></textarea>
            </div>
        <?php endforeach; ?>
    <?php if (!$hasilAgendaEmbedded): ?>
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-floppy-disk me-1"></i>Simpan hasil per agenda
        </button>
    </form>
    <?php endif; ?>
<?php endif; ?>
