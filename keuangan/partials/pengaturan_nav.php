<?php

declare(strict_types=1);

/** @var string $section */
/** @var string $santriBulananSub */
$santriBulananSub = $santriBulananSub ?? 'opsional';
$alokasiJenisKey = $alokasiJenisKey ?? 'syahriyah';
?>
<ul class="nav nav-tabs mb-3 flex-wrap">
    <li class="nav-item">
        <a class="nav-link <?= $section === 'umum' ? 'active' : '' ?>" href="?bagian=umum">Umum &amp; periode</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $section === 'tarif' ? 'active' : '' ?>" href="?bagian=tarif">Tarif &amp; komponen</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $section === 'alokasi' ? 'active' : '' ?>" href="?bagian=alokasi">Alokasi dana</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $section === 'santri_bulanan' ? 'active' : '' ?>" href="?bagian=santri_bulanan">Per santri (bulanan)</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $section === 'akun' ? 'active' : '' ?>" href="?bagian=akun">Akun kas/bank</a>
    </li>
</ul>

<?php if ($section === 'tarif'): ?>
<ul class="nav nav-pills nav-fill flex-wrap gap-1 mb-3 small">
    <li class="nav-item"><a class="nav-link" href="#syahriyah-pokok">Syahriyah &amp; makan</a></li>
    <li class="nav-item"><a class="nav-link" href="#tarif-per-bulan">Tarif per bulan</a></li>
    <li class="nav-item"><a class="nav-link" href="#tambahan-pkpps">Tambahan PKPPS</a></li>
    <li class="nav-item"><a class="nav-link" href="#makan-kelas">Makan per kelas</a></li>
    <li class="nav-item"><a class="nav-link" href="#tarif-saku-awal">Saku &amp; awal tahun</a></li>
</ul>
<?php endif; ?>

<?php if ($section === 'alokasi'): ?>
<ul class="nav nav-pills nav-fill flex-wrap gap-1 mb-3 small">
    <?php foreach (['syahriyah' => 'Syahriyah', 'awal_tahun' => 'Awal tahun', 'makan' => 'Makan'] as $jk => $jl): ?>
        <li class="nav-item">
            <a class="nav-link <?= $alokasiJenisKey === $jk ? 'active' : '' ?>" href="?bagian=alokasi&amp;alokasi_jenis=<?= htmlspecialchars($jk) ?>"><?= htmlspecialchars($jl) ?></a>
        </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($section === 'santri_bulanan'): ?>
<ul class="nav nav-pills nav-fill flex-wrap gap-1 mb-3 small">
    <li class="nav-item">
        <a class="nav-link <?= $santriBulananSub === 'opsional' ? 'active' : '' ?>" href="?bagian=santri_bulanan&amp;sub=opsional">Makan &amp; saku per santri</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $santriBulananSub === 'potongan' ? 'active' : '' ?>" href="?bagian=santri_bulanan&amp;sub=potongan">Potongan syahriyah (%)</a>
    </li>
</ul>
<?php endif; ?>
