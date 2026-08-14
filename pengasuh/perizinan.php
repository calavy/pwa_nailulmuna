<?php



declare(strict_types=1);



require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../helpers/app.php';

require_once __DIR__ . '/../helpers/perizinan_approval.php';

require_once __DIR__ . '/../helpers/perizinan_rombongan.php';

require_once __DIR__ . '/../helpers/perizinan_syari_kategori.php';



require_roles(['admin', 'pengurus', 'kiai']);



perizinan_approval_ensure_schema($pdo);

perizinan_rombongan_ensure_schema($pdo);

perizinan_syari_kategori_ensure_schema($pdo);



$userId = (int) ($_SESSION['user']['id'] ?? 0);

$isPengasuhOnly = user_is_pengasuh_kiai() && !is_super_admin() && strtolower((string) ($_SESSION['user']['role'] ?? '')) === 'kiai';

$bolehBypassAlpaPengasuh = perizinan_pengasuh_boleh_bypass_alpa($pdo);



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string) ($_POST['action'] ?? '');

    $bypassAlpa = perizinan_request_bypass_alpa_pengasuh($pdo, $_POST);

    if ($action === 'setujui_pengasuh') {

        $res = perizinan_pengasuh_setujui($pdo, (int) ($_POST['izin_id'] ?? 0), $userId, $bypassAlpa);

        set_flash($res['ok'] ? 'success' : 'error', $res['message']);

        header('Location: ' . app_href('/pengasuh/perizinan.php'));

        exit;

    }

    if ($action === 'tolak_pengasuh') {

        $res = perizinan_tolak_izin_satu($pdo, (int) ($_POST['izin_id'] ?? 0), $userId, 'pengasuh');

        set_flash($res['ok'] ? 'success' : 'error', $res['message']);

        header('Location: ' . app_href('/pengasuh/perizinan.php'));

        exit;

    }

    if ($action === 'setujui_rombongan_pengasuh') {

        $res = perizinan_pengasuh_setujui_rombongan($pdo, (int) ($_POST['rombongan_id'] ?? 0), $userId, $bypassAlpa);

        set_flash($res['ok'] ? 'success' : 'error', $res['message']);

        header('Location: ' . app_href('/pengasuh/perizinan.php'));

        exit;

    }

    if ($action === 'tolak_rombongan_pengasuh') {

        $res = perizinan_rombongan_tolak($pdo, (int) ($_POST['rombongan_id'] ?? 0), $userId, 'pengasuh');

        set_flash($res['ok'] ? 'success' : 'error', $res['message']);

        header('Location: ' . app_href('/pengasuh/perizinan.php'));

        exit;

    }

}



$pendingRows = perizinan_pengasuh_pending_list($pdo, 100);

$izinAlpaMap = [];

foreach ($pendingRows as $rowAlpa) {

    $sidAlpa = (int) ($rowAlpa['santri_id'] ?? 0);

    $syariKatAlpa = trim((string) ($rowAlpa['syari_kategori'] ?? ''));
    $izinAlpaMap[(int) ($rowAlpa['id'] ?? 0)] = perizinan_alpa_cek_approval(

        $pdo,

        $sidAlpa,

        (string) ($rowAlpa['jenis_izin'] ?? 'SYARI'),

        null,

        $syariKatAlpa !== '' ? $syariKatAlpa : null

    );

}



$rombonganPending = [];

$rombonganSeen = [];

$rombonganAlpaMap = [];

foreach ($pendingRows as $row) {

    $rid = (int) ($row['rombongan_id'] ?? 0);

    if ($rid > 0 && !isset($rombonganSeen[$rid])) {

        $rombonganSeen[$rid] = true;

        $meta = perizinan_rombongan_meta($pdo, $rid);

        if ($meta && strtoupper((string) ($meta['approval_status'] ?? '')) === 'PENDING') {

            $anggota = perizinan_rombongan_anggota($pdo, $rid);

            $jenisR = strtoupper((string) ($meta['jenis_izin'] ?? 'SYARI'));

            $blokirRombongan = false;
            $blokirNama = [];
            $blokirDetail = [];
            foreach ($anggota as $ang) {
                $cekAng = perizinan_alpa_cek_approval($pdo, (int) ($ang['santri_id'] ?? 0), $jenisR);
                if (!empty($cekAng['subject']) && empty($cekAng['allowed'])) {
                    $blokirRombongan = true;
                    $namaAng = (string) ($ang['nama_santri'] ?? 'Santri');
                    $blokirNama[] = $namaAng;
                    $blokirDetail[] = ['nama' => $namaAng, 'cek' => $cekAng];
                }
            }
            $rombonganAlpaMap[$rid] = [
                'blokir' => $blokirRombongan,
                'nama_blokir' => $blokirNama,
                'detail_blokir' => $blokirDetail,
                'jumlah' => count($anggota),
            ];

            $rombonganPending[] = [

                'id' => $rid,

                'jenis_izin' => (string) ($meta['jenis_izin'] ?? ''),

                'tanggal_mulai' => (string) ($meta['tanggal_mulai'] ?? ''),

                'tanggal_selesai' => (string) ($meta['tanggal_selesai'] ?? ''),

                'jam_mulai' => (string) ($meta['jam_mulai'] ?? ''),

                'jam_selesai' => (string) ($meta['jam_selesai'] ?? ''),

                'alasan' => (string) ($meta['alasan'] ?? ''),

                'jumlah' => count($anggota),

            ];

        }

    }

}



$pageTitle = 'Persetujuan Izin — Pengasuh';

require_once __DIR__ . '/../includes/header.php';

?>



<div class="page-intro mb-3">

    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/pengasuh/dashboard.php')) ?>">Pengasuh</a> · Perizinan</p>

    <h1 class="h4 mb-1">Persetujuan Izin</h1>

    <p class="text-muted mb-0">

        Hanya <strong>Izin</strong> yang memerlukan persetujuan pengasuh di halaman ini.

        Setelah disetujui, izin langsung aktif (QR &amp; notifikasi) — pengurus hanya mencetak surat.

        <?php if ($bolehBypassAlpaPengasuh): ?>

            Santri yang terhalang syarat ALPA dapat disetujui dengan centang <strong>Lewati ALPA</strong>.

        <?php endif; ?>

        <?php if (!$isPengasuhOnly): ?>

            <a href="<?= htmlspecialchars(app_href('/perizinan/index.php')) ?>">Modul perizinan pengurus</a>

        <?php endif; ?>

    </p>
</div>

<div class="alert alert-light border small py-2 mb-3">
    <div class="izin-alpa-glosarium mb-0">
        <strong>ALPA</strong> = santri tidak hadir ke kegiatan wajib (tanpa izin/sakit resmi).
        Angka di bawah = berapa kali ALPA dalam periode hitung pondok.
        <strong>Terhalang</strong> berarti sudah melewati batas — setujui dengan centang <strong>Lewati ALPA</strong> bila pengasuh mengizinkan.
    </div>
</div>



<?php if ($rombonganPending !== []): ?>

<div class="card shadow-sm mb-3 border-warning">

    <div class="card-header fw-semibold bg-warning-subtle">Izin rombongan menunggu</div>

    <div class="card-body p-0">

        <div class="table-responsive">

            <table class="table table-sm mb-0 align-middle">

                <thead class="table-light">

                    <tr>

                        <th>Rombongan</th>

                        <th>Jenis</th>

                        <th>Periode</th>

                        <th>ALPA</th>

                        <th class="text-end">Aksi</th>

                    </tr>

                </thead>

                <tbody>

                <?php foreach ($rombonganPending as $rm):

                    $rid = (int) $rm['id'];

                    $alpaR = $rombonganAlpaMap[$rid] ?? ['blokir' => false, 'nama_blokir' => []];

                    $blokirR = !empty($alpaR['blokir']);

                ?>

                    <tr>

                        <td>

                            <strong>#<?= $rid ?></strong>

                            <div class="small text-muted"><?= (int) $rm['jumlah'] ?> santri</div>

                        </td>

                        <td><?= htmlspecialchars(jenis_izin_label((string) $rm['jenis_izin'])) ?></td>

                        <td class="small">

                            <?= htmlspecialchars(app_format_izin_rentang(

                                (string) $rm['tanggal_mulai'],

                                (string) $rm['tanggal_selesai'],

                                substr((string) $rm['jam_mulai'], 0, 5),

                                substr((string) $rm['jam_selesai'], 0, 5)

                            )) ?>

                        </td>

                        <td class="small">
                            <?php if ($blokirR): ?>
                                <span class="badge text-bg-danger d-inline-block mb-1">
                                    <?= count($alpaR['nama_blokir'] ?? []) ?> dari <?= (int) ($alpaR['jumlah'] ?? $rm['jumlah']) ?> santri terhalang
                                </span>
                                <?php
                                $firstBlocked = ($alpaR['detail_blokir'][0]['cek'] ?? null);
                                if (is_array($firstBlocked)):
                                    $alpaCek = $firstBlocked;
                                    $mode = 'detail';
                                    require __DIR__ . '/../includes/partials/perizinan_alpa_ringkas.php';
                                endif;
                                ?>
                                <?php if (count($alpaR['nama_blokir'] ?? []) > 1): ?>
                                    <div class="text-muted mt-1">Juga: <?= htmlspecialchars(implode(', ', array_slice($alpaR['nama_blokir'], 1, 3))) ?><?= count($alpaR['nama_blokir']) > 4 ? '…' : '' ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge text-bg-success mb-1">Semua santri memenuhi syarat</span>
                                <?php
                                $sampleAng = perizinan_rombongan_anggota($pdo, $rid);
                                if ($sampleAng !== []):
                                    $sampleCek = perizinan_alpa_cek_approval($pdo, (int) ($sampleAng[0]['santri_id'] ?? 0), strtoupper((string) ($rm['jenis_izin'] ?? 'SYARI')));
                                    if (!empty($sampleCek['subject'])):
                                        $alpaCek = $sampleCek;
                                        $mode = 'table';
                                        require __DIR__ . '/../includes/partials/perizinan_alpa_ringkas.php';
                                    endif;
                                endif;
                                ?>
                            <?php endif; ?>
                        </td>

                        <td class="text-end">

                            <form method="post" class="d-inline pg-pengasuh-approve-form" data-alpa-blocked="<?= $blokirR ? '1' : '0' ?>">

                                <input type="hidden" name="action" value="setujui_rombongan_pengasuh">

                                <input type="hidden" name="rombongan_id" value="<?= $rid ?>">

                                <?php if ($blokirR && $bolehBypassAlpaPengasuh): ?>

                                    <label class="small d-block mb-1 text-start">

                                        <input type="checkbox" name="bypass_alpa" value="1" class="pg-pengasuh-bypass-alpa me-1">

                                        Lewati syarat ALPA

                                    </label>

                                <?php endif; ?>

                                <button type="submit" class="btn btn-sm btn-success pg-pengasuh-submit" <?= ($blokirR && !$bolehBypassAlpaPengasuh) ? 'disabled title="Tidak memenuhi syarat ALPA"' : '' ?>>Setujui rombongan</button>

                            </form>

                            <form method="post" class="d-inline ms-1" onsubmit="return confirm('Tolak izin rombongan ini?');">

                                <input type="hidden" name="action" value="tolak_rombongan_pengasuh">

                                <input type="hidden" name="rombongan_id" value="<?= $rid ?>">

                                <button type="submit" class="btn btn-sm btn-outline-danger">Tolak</button>

                            </form>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php endif; ?>



<div class="card shadow-sm">

    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">

        <span>Permohonan izin syar'i menunggu persetujuan</span>

        <span class="badge text-bg-warning"><?= count($pendingRows) ?></span>

    </div>

    <div class="card-body p-0">

        <?php if ($pendingRows === []): ?>

            <div class="text-muted text-center py-4">Tidak ada izin syar'i yang menunggu persetujuan pengasuh.</div>

        <?php else: ?>

        <div class="table-responsive">

            <table class="table table-sm table-hover mb-0 align-middle">

                <thead class="table-light">

                    <tr>

                        <th>Santri</th>

                        <th>Jenis</th>

                        <th>Keperluan</th>

                        <th>Periode</th>

                        <th>ALPA</th>

                        <th>Alasan</th>

                        <th>Tujuan</th>

                        <th class="text-end">Aksi</th>

                    </tr>

                </thead>

                <tbody>

                <?php foreach ($pendingRows as $iz): ?>

                    <?php if ((int) ($iz['rombongan_id'] ?? 0) > 0) {

                        continue;

                    } ?>

                    <?php

                    $alpaCek = $izinAlpaMap[(int) $iz['id']] ?? ['subject' => false, 'allowed' => true];

                    $blokirAlpa = !empty($alpaCek['subject']) && empty($alpaCek['allowed']);

                    ?>

                    <tr>

                        <td>

                            <div class="fw-semibold"><?= htmlspecialchars((string) ($iz['nama_santri'] ?? '')) ?></div>

                            <div class="small text-muted"><?= htmlspecialchars((string) ($iz['tingkatan'] ?? '-')) ?> · <?= htmlspecialchars((string) ($iz['nis'] ?? '')) ?></div>

                        </td>

                        <td><?= htmlspecialchars(jenis_izin_label((string) ($iz['jenis_izin'] ?? 'KELUAR'))) ?></td>

                        <td class="small">
                            <?php
                            $katKode = trim((string) ($iz['syari_kategori'] ?? ''));
                            if ($katKode !== ''):
                                ?>
                                <span class="d-block fw-semibold"><?= htmlspecialchars(perizinan_syari_kategori_label($pdo, $katKode)) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <td class="small">

                            <?= htmlspecialchars(app_format_izin_rentang(

                                (string) ($iz['tanggal_mulai'] ?? ''),

                                (string) ($iz['tanggal_selesai'] ?? ''),

                                substr((string) ($iz['jam_mulai'] ?? ''), 0, 5),

                                substr((string) ($iz['jam_selesai'] ?? ''), 0, 5)

                            )) ?>

                        </td>

                        <td class="small">
                            <?php $mode = 'detail'; require __DIR__ . '/../includes/partials/perizinan_alpa_ringkas.php'; ?>
                        </td>

                        <td class="small"><?= htmlspecialchars(mb_strimwidth((string) ($iz['alasan'] ?? ''), 0, 80, '…')) ?></td>

                        <td class="small"><?= htmlspecialchars(mb_strimwidth((string) ($iz['tujuan'] ?? ''), 0, 60, '…')) ?></td>

                        <td class="text-end">

                            <form method="post" class="d-inline pg-pengasuh-approve-form" data-alpa-blocked="<?= $blokirAlpa ? '1' : '0' ?>">

                                <input type="hidden" name="action" value="setujui_pengasuh">

                                <input type="hidden" name="izin_id" value="<?= (int) $iz['id'] ?>">

                                <?php if ($blokirAlpa && $bolehBypassAlpaPengasuh): ?>

                                    <label class="small d-block mb-1 text-start">

                                        <input type="checkbox" name="bypass_alpa" value="1" class="pg-pengasuh-bypass-alpa me-1">

                                        Lewati syarat ALPA

                                    </label>

                                <?php endif; ?>

                                <button type="submit" class="btn btn-sm btn-success pg-pengasuh-submit" <?= ($blokirAlpa && !$bolehBypassAlpaPengasuh) ? 'disabled title="Tidak memenuhi syarat ALPA"' : '' ?>>Setujui</button>

                            </form>

                            <form method="post" class="d-inline ms-1" onsubmit="return confirm('Tolak permohonan izin ini?');">

                                <input type="hidden" name="action" value="tolak_pengasuh">

                                <input type="hidden" name="izin_id" value="<?= (int) $iz['id'] ?>">

                                <button type="submit" class="btn btn-sm btn-outline-danger">Tolak</button>

                            </form>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

        <?php endif; ?>

    </div>

</div>



<script>

(function () {

    function syncSubmit(form) {

        var blocked = form.getAttribute('data-alpa-blocked') === '1';

        var bypass = form.querySelector('.pg-pengasuh-bypass-alpa');

        var btn = form.querySelector('.pg-pengasuh-submit');

        if (!btn) {

            return;

        }

        if (!blocked) {

            btn.disabled = false;

            btn.removeAttribute('title');

            return;

        }

        var checked = bypass && bypass.checked;

        btn.disabled = !checked;

        btn.title = checked ? '' : 'Centang Lewati syarat ALPA untuk menyetujui';

    }



    document.querySelectorAll('.pg-pengasuh-approve-form').forEach(function (form) {

        syncSubmit(form);

        var bypass = form.querySelector('.pg-pengasuh-bypass-alpa');

        if (bypass) {

            bypass.addEventListener('change', function () {

                syncSubmit(form);

            });

        }

        form.addEventListener('submit', function (e) {

            if (form.getAttribute('data-submitting') === '1') {

                e.preventDefault();

                return;

            }

            if (form.getAttribute('data-alpa-blocked') === '1') {

                var bypassBox = form.querySelector('.pg-pengasuh-bypass-alpa');

                if (!bypassBox || !bypassBox.checked) {

                    e.preventDefault();

                    alert('Centang Lewati syarat ALPA terlebih dahulu karena santri terhalang syarat ALPA.');

                    return;

                }

                if (!confirm('Setujui meski syarat ALPA tidak terpenuhi?')) {

                    e.preventDefault();

                    return;

                }

            }

            form.setAttribute('data-submitting', '1');

            var btn = form.querySelector('.pg-pengasuh-submit');

            if (btn) {

                btn.disabled = true;

                btn.textContent = 'Memproses…';

            }

        });

    });

})();

</script>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>

