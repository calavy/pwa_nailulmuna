<?php

declare(strict_types=1);

/**
 * Isi dokumen cetak hasil musyawarah — struktur kop sama seperti surat perizinan.
 *
 * @var array<string, mixed> $docRow
 * @var PDO $pdo
 * @var string $ponpes
 * @var string $dokumenJudul
 * @var array<string, mixed> $kop
 * @var string $headerColor
 */
if (!function_exists('pondok_kop_surat_html')) {
    require_once __DIR__ . '/../../helpers/pondok_cetak.php';
}
if (!function_exists('surat_cetak_template_render')) {
    require_once __DIR__ . '/../../helpers/surat_cetak_templates.php';
}

$ponpes = (string) ($ponpes ?? trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren Nailul Muna')));
if (!isset($kop) || !is_array($kop)) {
    $kop = pondok_kop_data($pdo);
}
if (!isset($headerColor) || $headerColor === '') {
    $headerColor = surat_cetak_kop_accent_color($pdo);
}
if (!isset($dokumenJudul)) {
    $dokumenJudul = surat_cetak_template_render($pdo, 'notulen_judul', ['nama_ponpes' => $ponpes]);
}

$agendaRowsCetak = yayasan_notulen_agenda_uraian_rows($pdo, (int) ($docRow['rapat_id'] ?? $docRow['id'] ?? 0), $docRow);
$judulRapat = (string) ($docRow['judul'] ?? $docRow['rapat_judul'] ?? 'Musyawarah');
?>
<div class="sheet-wrap">
    <div class="sheet sheet--kop-watermark">
        <?= pondok_kop_surat_html($kop, $headerColor) ?>

        <div class="title">
            <strong><?= htmlspecialchars($dokumenJudul) ?></strong>
            <div class="doc-subtitle"><?= htmlspecialchars($judulRapat) ?></div>
            <?php if (!empty($docRow['nomor_rapat'])): ?>
                <span class="doc-num">No. <?= htmlspecialchars((string) $docRow['nomor_rapat']) ?></span>
            <?php endif; ?>
        </div>

        <div class="content">
            <table class="info">
                <tr>
                    <td>Tanggal</td>
                    <td>
                        : <?= htmlspecialchars(yayasan_format_tanggal_rapat(
                            (string) ($docRow['tanggal_rapat'] ?? ''),
                            $docRow['waktu_mulai'] !== null ? (string) $docRow['waktu_mulai'] : null,
                            $docRow['waktu_selesai'] !== null ? (string) $docRow['waktu_selesai'] : null
                        )) ?>
                    </td>
                </tr>
                <?php if (!empty($docRow['lokasi'])): ?>
                <tr>
                    <td>Lokasi</td>
                    <td>: <?= htmlspecialchars((string) $docRow['lokasi']) ?></td>
                </tr>
                <?php endif; ?>
            </table>

            <?php if ($agendaRowsCetak !== []): ?>
                <h2 class="section-title">Hasil musyawarah per agenda</h2>
                <div class="mb-4">
                    <?php foreach ($agendaRowsCetak as $i => $ar): ?>
                        <div class="agenda-item">
                            <div class="agenda-label"><?= (int) $i + 1 ?>. <?= htmlspecialchars((string) ($ar['agenda'] ?? '')) ?></div>
                            <?php if (trim((string) ($ar['uraian'] ?? '')) !== ''): ?>
                                <div class="agenda-uraian"><?= nl2br(htmlspecialchars((string) $ar['uraian'])) ?></div>
                            <?php else: ?>
                                <div class="agenda-uraian text-muted small">—</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else:
                $agendaCetak = yayasan_rapat_agenda_teks($docRow);
                if ($agendaCetak !== ''):
                    ?>
                <h2 class="section-title">Agenda ringkas</h2>
                <div class="text-block mb-4"><?= nl2br(htmlspecialchars($agendaCetak)) ?></div>
                <?php endif;
            endif; ?>

            <?php if (!empty($docRow['hadir'])): ?>
                <h2 class="section-title">Yang hadir</h2>
                <div class="text-block"><?= nl2br(htmlspecialchars((string) $docRow['hadir'])) ?></div>
            <?php endif; ?>

            <?php if (!empty($docRow['ringkasan'])): ?>
                <h2 class="section-title">Ringkasan</h2>
                <div class="text-block"><?= nl2br(htmlspecialchars((string) $docRow['ringkasan'])) ?></div>
            <?php endif; ?>

            <?php if (!empty($docRow['keputusan'])): ?>
                <h2 class="section-title">Keputusan</h2>
                <div class="text-block"><?= yayasan_notulen_format_hasil_rapat((string) $docRow['keputusan']) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>
