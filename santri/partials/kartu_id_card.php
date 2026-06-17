<?php

declare(strict_types=1);

/**
 * Kartu ID santri portrait 54×86 mm — kotak konten ~10 mm dari atas.
 *
 * @var array<string,mixed> $row Sudah lewat santri_kartu_prepare_row
 * @var array{nama_ponpes:string,alamat:string,telp:string,motto:string} $brand
 * @var string $cardDomId Opsional id elemen kartu (untuk download JPG)
 */
$row = is_array($row ?? null) ? $row : [];
$brand = is_array($brand ?? null) ? $brand : ['nama_ponpes' => '', 'alamat' => '', 'telp' => '', 'motto' => ''];
$cardDomId = trim((string) ($cardDomId ?? ''));
$kartuVariant = (string) ($kartuVariant ?? 'utama');
$isSementara = $kartuVariant === 'sementara';

$nama = strtoupper(trim((string) ($row['nama_santri'] ?? '-')));
$nis = trim((string) ($row['nis'] ?? ''));
$nisTampil = $nis !== '' ? $nis : trim((string) ($row['kode_qr_final'] ?? ''));
$fotoUrl = trim((string) ($row['foto_url'] ?? ''));
$binLabel = trim((string) ($row['bin_label'] ?? ''));
$qrUrl = trim((string) ($row['qr_url'] ?? ''));

$ponpes = trim((string) ($brand['nama_ponpes'] ?? ''));
$ponpesMod = santri_kartu_text_size_class($ponpes, 'st-kartu-card__ponpes', 22, 32, 42);
$ponpesSuffix = str_replace('st-kartu-card__ponpes', '', $ponpesMod);
if ($ponpesSuffix === '--lg') {
    $ponpesSuffix = '';
}

$addrLine = trim((string) ($brand['alamat'] ?? ''));
$telp = trim((string) ($brand['telp'] ?? ''));
if ($addrLine !== '' && $telp !== '') {
    $addrLine .= ' | ☎ ' . $telp;
} elseif ($telp !== '') {
    $addrLine = '☎ ' . $telp;
}
$addrClass = mb_strlen($addrLine) > 48 ? 'st-kartu-card__addr st-kartu-card__addr--sm' : 'st-kartu-card__addr';

$nameClass = 'st-kartu-card__name-pill ' . santri_kartu_text_size_class($nama, 'st-kartu-card__name-pill', 16, 24, 32);
$binClass = 'st-kartu-card__bin';
if ($binLabel !== '') {
    $binMod = str_replace('st-kartu-card__name-pill', 'st-kartu-card__bin', santri_kartu_text_size_class($binLabel, 'st-kartu-card__name-pill', 20, 28, 36));
    $binMod = str_replace(['st-kartu-card__bin--lg', 'st-kartu-card__bin--md'], 'st-kartu-card__bin', $binMod);
    $binClass .= ' ' . trim(str_replace('st-kartu-card__bin st-kartu-card__bin', 'st-kartu-card__bin', $binMod));
}

$initials = '';
foreach (preg_split('/\s+/', $nama) ?: [] as $part) {
    if ($part !== '') {
        $initials .= mb_substr($part, 0, 1);
    }
    if (mb_strlen($initials) >= 2) {
        break;
    }
}
$initials = $initials !== '' ? $initials : 'S';
?>
<div class="st-kartu-card<?= $isSementara ? ' st-kartu-card--sementara' : '' ?>"<?= $cardDomId !== '' ? ' id="' . htmlspecialchars($cardDomId) . '"' : '' ?>>
    <div class="st-kartu-card__waves" aria-hidden="true"></div>
    <div class="st-kartu-card__inner">
        <div class="st-kartu-card__content-box<?= $isSementara ? ' st-kartu-card__content-box--tmp' : '' ?>">
            <header class="st-kartu-card__head">
                <?php if ($isSementara): ?>
                    <span class="st-kartu-card__badge-tmp">Kartu Sementara</span>
                <?php endif; ?>
                <h2 class="st-kartu-card__ponpes<?= $ponpesSuffix !== '' ? ' st-kartu-card__ponpes' . htmlspecialchars($ponpesSuffix) : '' ?>"><?= htmlspecialchars($ponpes) ?></h2>
                <?php if ($addrLine !== ''): ?>
                    <p class="<?= htmlspecialchars($addrClass) ?>"><?= htmlspecialchars($addrLine) ?></p>
                <?php endif; ?>
                <hr class="st-kartu-card__head-rule">
            </header>

            <div class="st-kartu-card__body">
                <div class="st-kartu-card__photo-ring">
                    <?php if ($fotoUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($fotoUrl) ?>" alt="" class="st-kartu-card__photo" crossorigin="anonymous">
                    <?php else: ?>
                        <span class="st-kartu-card__photo-ph" aria-hidden="true"><?= htmlspecialchars($initials) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($nisTampil !== ''): ?>
                    <div class="st-kartu-card__nis"><?= htmlspecialchars($nisTampil) ?></div>
                <?php endif; ?>

                <div class="st-kartu-card__name-wrap">
                    <div class="<?= htmlspecialchars($nameClass) ?>" data-kartu-nama><?= htmlspecialchars($nama) ?></div>
                </div>
                <div class="<?= htmlspecialchars($binClass) ?>" data-kartu-bin><?php if ($binLabel !== ''): ?><?= htmlspecialchars($binLabel) ?><?php else: ?><span aria-hidden="true">&nbsp;</span><?php endif; ?></div>

                <div class="st-kartu-card__spacer" aria-hidden="true"></div>

                <div class="st-kartu-card__footer">
                    <?php if ($qrUrl !== ''): ?>
                        <div class="st-kartu-card__qr-frame">
                            <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR Santri" class="st-kartu-card__qr" crossorigin="anonymous" data-kartu-qr>
                        </div>
                    <?php endif; ?>

                    <?php if (!$isSementara && trim((string) ($brand['motto'] ?? '')) !== ''): ?>
                        <div class="st-kartu-card__motto"><?= htmlspecialchars((string) $brand['motto']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
