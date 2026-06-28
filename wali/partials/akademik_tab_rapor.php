<?php

declare(strict_types=1);

/** @var PDO $pdo */
/** @var int $waliSantriId */
/** @var array<string, mixed> $waliSantriRow */
/** @var string $raporJenisFilter pesantren|pkpps */

require_once __DIR__ . '/../../helpers/pkpps_rapor.php';

$raporJenisFilter = strtolower(trim((string) ($raporJenisFilter ?? 'pesantren'))) === 'pkpps' ? 'pkpps' : 'pesantren';
$isPkpps = $raporJenisFilter === 'pkpps';
$tabKey = $isPkpps ? 'rapor_pkpps' : 'rapor_pesantren';

$waPengurus = wa_permohonan_izin_target($pdo);
$namaAnak = (string) ($waliSantriRow['nama_tampil'] ?? '');
$pesanTanya = 'Assalamu\'alaikum, saya wali dari *' . $namaAnak . '* (NIS ' . ($waliSantriRow['nis'] ?? '') . '). Mohon penjelasan terkait rapor ' . ($isPkpps ? 'PKPPS' : 'pesantren') . ' di portal wali. Terima kasih.';
$waAdminUrl = $waPengurus !== '' ? wa_me_chat_url($waPengurus, $pesanTanya) : null;

$infoPortal = $isPkpps
    ? pkpps_rapor_setting($pdo, 'pkpps_rapor_info_portal', '')
    : 'Rapor akademik pesantren: presensi, setoran hafalan, dan tugas Ikhtibar.';

$rows = [];
if (table_exists($pdo, 'akademik_rapor')) {
    $st = $pdo->prepare('
        SELECT id, santri_id, jenis_rapor, judul_periode, tanggal_terbit, narasi, predikat_akhlak,
               catatan_pondok, pdf_path, pdf_original_name, periode_mode, periode_bulan, periode_tahun
        FROM akademik_rapor
        WHERE santri_id = :sid AND is_published = 1 AND jenis_rapor = :jenis
        ORDER BY tanggal_terbit DESC, id DESC
        LIMIT 15
    ');
    $st->execute(['sid' => $waliSantriId, 'jenis' => $raporJenisFilter]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<p class="small text-muted"><?= htmlspecialchars($infoPortal) ?> Hanya rapor yang sudah <strong>diterbitkan</strong> pengurus.</p>

<?php if ($waAdminUrl): ?>
    <a class="btn btn-success w-100 mb-3" target="_blank" rel="noopener" href="<?= htmlspecialchars($waAdminUrl) ?>">Chat WhatsApp pengurus</a>
<?php endif; ?>

<?php if (!$rows): ?>
    <div class="card shadow-sm wali-card"><div class="card-body text-muted small text-center py-4">Belum ada rapor <?= $isPkpps ? 'PKPPS' : 'pesantren' ?> yang diterbitkan.</div></div>
<?php else: ?>
    <div class="d-flex flex-column gap-3">
        <?php foreach ($rows as $r):
            $raporId = (int) ($r['id'] ?? 0);
            $adaPdf = trim((string) ($r['pdf_path'] ?? '')) !== '';
            $pdfLihatUrl = app_href('/wali/rapor_pdf.php?id=' . $raporId);
            $pdfUnduhUrl = app_href('/wali/rapor_pdf.php?id=' . $raporId . '&dl=1');
            $raporDetailUrl = app_href('/wali/rapor_detail.php?tab=' . rawurlencode($tabKey) . '&id=' . $raporId);
            $raporShowDetailLink = !$adaPdf;
            $raporCompact = true;
            require __DIR__ . '/akademik_rapor_card.php';
            ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
