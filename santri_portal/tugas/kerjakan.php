<?php



declare(strict_types=1);



require_once __DIR__ . '/../inc_portal.php';

require_once __DIR__ . '/../../helpers/akademik_ikhtibar.php';

require_once __DIR__ . '/../../helpers/akademik_pkpps_tugas.php';

require_once __DIR__ . '/../../helpers/ikhtibar_preview.php';

require_once __DIR__ . '/../../helpers/ikhtibar_kerjakan_portal.php';



ensure_akademik_ikhtibar_tables($pdo);



$tugasId = (int) ($_GET['id'] ?? 0);

$santriId = (int) ($santriPortalRow['id'] ?? 0);

$tugas = ikhtibar_tugas_by_id($pdo, $tugasId);



if (!$tugas || (string) ($tugas['status'] ?? '') !== 'published') {

    set_flash('error', 'Tugas tidak ditemukan atau belum dipublikasikan.');

    header('Location: ' . app_href('/santri_portal/tugas/index.php'));

    exit;

}

if (pkpps_tugas_is_row($tugas)) {

    header('Location: ' . app_href(pkpps_tugas_santri_base_path() . '/kerjakan.php?id=' . $tugasId));

    exit;

}



$kerjakanUrl = app_href('/santri_portal/tugas/kerjakan.php?id=' . $tugasId);

$sesi = ikhtibar_sesi_get($pdo, $tugasId, $santriId);

$pakaiToken = (int) ($tugas['pakai_token'] ?? 0) === 1;

$tokenOk = !$pakaiToken || !empty($_SESSION['ikhtibar_token_ok'][$tugasId]);



ikhtibar_kerjakan_handle_post($pdo, $tugasId, $santriId, $tokenOk, [

    'redirect' => $kerjakanUrl,

    'hasil_detail' => app_href('/santri_portal/tugas/hasil_detail.php?sesi_id='),

    'hasil_index' => app_href('/santri_portal/tugas/index.php'),

]);



$sesi = ikhtibar_sesi_get($pdo, $tugasId, $santriId);

$statusSesi = (string) ($sesi['status'] ?? 'menunggu');

$berjalan = $statusSesi === 'berjalan';

$selesai = $statusSesi === 'selesai';

$drafTerkunci = $berjalan && $sesi && ikhtibar_sesi_draf_terkunci($sesi);

$perluBuatPin = $berjalan && $sesi && !$drafTerkunci && !ikhtibar_sesi_punya_pin_draf($sesi);



$navActive = $berjalan ? null : 'tugas';

$kerjakanHeadHtml = $berjalan ? ikhtibar_kerjakan_portal_head_html() : '';

$kerjakanBodyClass = $berjalan ? 'santri-portal--kerjakan' : '';

require_once __DIR__ . '/../includes/layout.php';

santri_portal_layout_head((string) ($tugas['judul'] ?? 'Tugas'), $navActive, $kerjakanHeadHtml, $kerjakanBodyClass);



$headerSisaDetik = null;

$headerStatus = $selesai ? 'selesai' : ($berjalan ? 'berjalan' : 'menunggu');

if ($berjalan) {

    $headerSisaDetik = ikhtibar_sesi_sisa_detik($sesi ?? []);

}

?>

<div class="ikhtibar-kerjakan-page">

<?php

echo ikhtibar_render_kerjakan_header_html($tugas, $santriPortalRow, [

    'sisa_detik' => $headerSisaDetik,

    'durasi_menit' => (int) ($tugas['durasi_menit'] ?? 0),

    'status' => $headerStatus,

]);

?>

<?php



if ($selesai):

    $sesiSelesaiId = (int) ($sesi['id'] ?? 0);

    ?>

    <link href="<?= htmlspecialchars(app_href('/assets/css/ikhtibar-hasil.css')) ?>" rel="stylesheet">

    <p class="text-center mb-3">Anda sudah menyelesaikan tugas ini.</p>

    <?php if ($sesiSelesaiId > 0): ?>

        <a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/hasil_detail.php?sesi_id=' . $sesiSelesaiId)) ?>" class="btn btn-auth-primary w-100 mb-2"><i class="fa-solid fa-chart-simple me-1"></i> Lihat hasil &amp; nilai</a>

    <?php endif; ?>

    <a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/hasil.php')) ?>" class="btn btn-outline-secondary w-100 mb-2">Semua hasil tugas</a>

    <a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/index.php')) ?>" class="btn btn-link w-100 small">Daftar tugas</a>

<?php elseif (!$tokenOk): ?>

    <p class="small text-muted">Masukkan <strong>Token Kunci</strong> dari pembimbing untuk membuka soal.</p>

    <form method="post" class="d-grid gap-2">

        <input type="hidden" name="action" value="verifikasi_token">

        <input type="text" name="token" class="form-control form-control-lg text-center text-uppercase" maxlength="12" required autocomplete="off" placeholder="TOKEN">

        <button type="submit" class="btn btn-auth-primary">Verifikasi token</button>

    </form>

<?php elseif (!$berjalan): ?>

    <p class="small">Setelah menekan <strong>Mulai Tugas</strong>, waktu <?= (int) ($tugas['durasi_menit'] ?? 0) ?> menit akan mulai dihitung mundur.</p>

    <form method="post">

        <input type="hidden" name="action" value="mulai">

        <button type="submit" class="btn btn-auth-primary w-100 btn-lg">Mulai Tugas</button>

    </form>

<?php elseif ($drafTerkunci):

    echo ikhtibar_kerjakan_render_pin_buka_html();

else:

    $soalList = ikhtibar_soal_sanitize_for_santri(ikhtibar_soal_urut_sesi($pdo, $sesi));

    $sisaDetik = ikhtibar_sesi_sisa_detik($sesi);

    $simpanDiblokir = ikhtibar_simpan_sementara_diblokir($sisaDetik);

    ?>

    <?php if ($simpanDiblokir): ?>

        <div class="alert alert-danger py-2 small mb-2" id="alert-simpan-blokir">

            Sisa waktu 5 menit atau kurang — <strong>simpan sementara tidak diizinkan</strong>. Selesaikan dan kirim jawaban jika sudah yakin.

        </div>

    <?php else: ?>

        <div class="alert alert-danger py-2 small mb-2 d-none" id="alert-simpan-blokir">

            Sisa waktu 5 menit atau kurang — <strong>simpan sementara tidak diizinkan</strong>. Selesaikan dan kirim jawaban jika sudah yakin.

        </div>

    <?php endif; ?>

    <form method="post" id="form-jawab">

        <?php if ($perluBuatPin): ?>

            <?= ikhtibar_kerjakan_render_pin_buat_html() ?>

        <?php endif; ?>

        <?php $no = 0; foreach ($soalList as $soal):

            $no++;

            $sid = (int) $soal['id'];

            $jenis = (string) ($soal['jenis'] ?? 'PG');

            $jStmt = $pdo->prepare('SELECT jawaban_santri FROM ikhtibar_jawaban WHERE sesi_id = :s AND soal_id = :q LIMIT 1');

            $jStmt->execute(['s' => (int) $sesi['id'], 'q' => $sid]);

            $jawabSaved = (string) ($jStmt->fetchColumn() ?: '');

            ?>

            <div class="card mb-2 border-0 shadow-sm ikhtibar-soal-card">

                <div class="card-body py-2">

                    <div class="small text-muted mb-1"><?= $jenis === 'PG' ? 'PG' : 'Esai' ?> · Soal <?= $no ?></div>

                    <div class="mb-2"><?= ikhtibar_soal_teks_html((string) ($soal['teks_soal'] ?? '')) ?></div>

                    <?php if ($jenis === 'PG'): ?>

                        <?php foreach (ikhtibar_pg_opsi_huruf_list(ikhtibar_pg_jumlah_opsi_dari_row($soal)) as $huruf):

                            $col = 'opsi_' . strtolower($huruf);

                            if (trim((string) ($soal[$col] ?? '')) === '') {

                                continue;

                            }

                            ?>

                            <div class="form-check ikhtibar-soal-text" dir="auto">

                                <input class="form-check-input" type="radio" name="jawaban_<?= $sid ?>" value="<?= $huruf ?>" id="j<?= $sid ?>_<?= $huruf ?>" <?= $jawabSaved === $huruf ? 'checked' : '' ?>>

                                <label class="form-check-label" for="j<?= $sid ?>_<?= $huruf ?>"><?= $huruf ?>. <?= htmlspecialchars((string) $soal[$col]) ?></label>

                            </div>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <textarea name="jawaban_<?= $sid ?>" class="form-control form-control-sm ikhtibar-soal-input" dir="auto" rows="3"><?= htmlspecialchars($jawabSaved) ?></textarea>

                    <?php endif; ?>

                </div>

            </div>

        <?php endforeach; ?>

        <?= ikhtibar_kerjakan_render_text_toolbar_html(true) ?>

        <div class="d-grid gap-2 mt-3 ikhtibar-kerjakan-actions">

            <button type="submit" name="action" value="simpan" id="btn-simpan-sementara" class="btn btn-outline-secondary"<?= $simpanDiblokir ? ' disabled' : '' ?>>Simpan sementara</button>

            <button type="submit" name="action" value="selesai" class="btn btn-auth-primary" onclick="return confirm('Kirim jawaban final?');">Selesai &amp; kirim</button>

        </div>

    </form>

    <script>

    (function () {

        var sisa = <?= (int) $sisaDetik ?>;

        var el = document.getElementById('timer-display');

        var menitEl = document.getElementById('sisa-menit-display');

        var box = document.getElementById('timer-box');

        var form = document.getElementById('form-jawab');

        var btnSimpan = document.getElementById('btn-simpan-sementara');

        var alertBlokir = document.getElementById('alert-simpan-blokir');

        var batasSimpan = 300;

        function updateSimpanBlokir() {

            var blokir = sisa > 0 && sisa <= batasSimpan;

            if (btnSimpan) {

                btnSimpan.disabled = blokir;

            }

            if (alertBlokir) {

                alertBlokir.classList.toggle('d-none', !blokir);

            }

        }

        function tick() {

            if (sisa <= 0) {

                if (el) el.textContent = '00:00';

                if (menitEl) menitEl.textContent = '0';

                if (box) {

                    box.classList.remove('alert-warning');

                    box.classList.add('alert-danger');

                }

                if (form) {

                    var inp = document.createElement('input');

                    inp.type = 'hidden'; inp.name = 'action'; inp.value = 'selesai';

                    form.appendChild(inp);

                    form.submit();

                }

                return;

            }

            var m = Math.floor(sisa / 60);

            var s = sisa % 60;

            if (el) el.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;

            if (menitEl) menitEl.textContent = String(Math.ceil(sisa / 60));

            if (box) {

                if (sisa <= batasSimpan) {

                    box.classList.remove('alert-warning');

                    box.classList.add('alert-danger');

                } else {

                    box.classList.remove('alert-danger');

                    box.classList.add('alert-warning');

                }

            }

            updateSimpanBlokir();

            sisa--;

            setTimeout(tick, 1000);

        }

        updateSimpanBlokir();

        tick();

    })();

    </script>

<?php endif; ?>



<?php if (!$berjalan): ?>

<p class="text-center mt-3 mb-0"><a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/index.php')) ?>" class="small">← Daftar tugas</a></p>

<?php endif; ?>

</div>

<?php

santri_portal_layout_foot($navActive);


