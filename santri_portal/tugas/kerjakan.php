<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc_portal.php';
require_once __DIR__ . '/../../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../../helpers/app_path.php';

ensure_akademik_ikhtibar_tables($pdo);

$tugasId = (int) ($_GET['id'] ?? 0);
$santriId = (int) ($santriPortalRow['id'] ?? 0);
$tugas = ikhtibar_tugas_by_id($pdo, $tugasId);

if (!$tugas || (string) ($tugas['status'] ?? '') !== 'published') {
    set_flash('error', 'Tugas tidak ditemukan atau belum dipublikasikan.');
    header('Location: ' . app_href('/santri_portal/tugas/index.php'));
    exit;
}

$sesi = ikhtibar_sesi_get($pdo, $tugasId, $santriId);
$pakaiToken = (int) ($tugas['pakai_token'] ?? 0) === 1;
$tokenOk = !$pakaiToken || !empty($_SESSION['ikhtibar_token_ok'][$tugasId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'verifikasi_token') {
        $inp = trim((string) ($_POST['token'] ?? ''));
        if (ikhtibar_verify_token($pdo, $tugasId, $inp)) {
            $_SESSION['ikhtibar_token_ok'][$tugasId] = true;
            set_flash('success', 'Token benar. Silakan mulai tugas.');
        } else {
            set_flash('error', 'Token tidak valid.');
        }
        header('Location: ' . app_href('/santri_portal/tugas/kerjakan.php?id=' . $tugasId));
        exit;
    }
    if ($action === 'mulai' && $tokenOk) {
        $res = ikhtibar_mulai_sesi($pdo, $tugasId, $santriId);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/santri_portal/tugas/kerjakan.php?id=' . $tugasId));
        exit;
    }
    if ($action === 'simpan' || $action === 'selesai') {
        $sesi = ikhtibar_sesi_get($pdo, $tugasId, $santriId);
        if (!$sesi || (string) ($sesi['status'] ?? '') !== 'berjalan') {
            set_flash('error', 'Sesi tidak aktif.');
            header('Location: ' . app_href('/santri_portal/tugas/kerjakan.php?id=' . $tugasId));
            exit;
        }
        $sesiId = (int) $sesi['id'];
        $soalList = ikhtibar_soal_urut_sesi($pdo, $sesi);
        foreach ($soalList as $soal) {
            $sid = (int) $soal['id'];
            $key = 'jawaban_' . $sid;
            if (isset($_POST[$key])) {
                ikhtibar_simpan_jawaban($pdo, $sesiId, $sid, trim((string) $_POST[$key]));
            }
        }
        if ($action === 'selesai') {
            $fin = ikhtibar_selesai_sesi($pdo, $sesiId);
            if ($fin['ok'] && !empty($fin['sesi_id'])) {
                set_flash('success', 'Tugas selesai. Lihat ringkasan nilai di bawah.');
                header('Location: ' . app_href('/santri_portal/tugas/hasil_detail.php?sesi_id=' . (int) $fin['sesi_id']));
            } else {
                set_flash($fin['ok'] ? 'success' : 'error', (string) ($fin['message'] ?? ''));
                header('Location: ' . app_href('/santri_portal/tugas/index.php'));
            }
            exit;
        }
        set_flash('success', 'Jawaban tersimpan.');
        header('Location: ' . app_href('/santri_portal/tugas/kerjakan.php?id=' . $tugasId));
        exit;
    }
}

$sesi = ikhtibar_sesi_get($pdo, $tugasId, $santriId);
$statusSesi = (string) ($sesi['status'] ?? 'menunggu');
$berjalan = $statusSesi === 'berjalan';
$selesai = $statusSesi === 'selesai';

$namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', ''));
require_once __DIR__ . '/../../includes/auth_portal_layout.php';

auth_portal_layout_begin([
    'title' => (string) ($tugas['judul'] ?? 'Tugas'),
    'welcome' => (string) ($tugas['judul'] ?? 'Tugas'),
    'subtitle' => 'Durasi ' . (int) ($tugas['durasi_menit'] ?? 0) . ' menit · urutan soal diacak',
    'nama_ponpes' => $namaPonpes,
    'max_width' => '640px',
    'accent' => 'teal',
]);

$err = get_flash('error');
$ok = get_flash('success');
if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif;
if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif;

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
<?php else:
    $soalList = ikhtibar_soal_urut_sesi($pdo, $sesi);
    $waktuMulai = strtotime((string) ($sesi['waktu_mulai'] ?? 'now'));
    $durasiDetik = (int) ($sesi['durasi_menit'] ?? 60) * 60;
    $sisaDetik = max(0, $durasiDetik - (time() - $waktuMulai));
    ?>
    <div class="alert alert-warning py-2 text-center mb-3" id="timer-box">
        Sisa waktu: <strong id="timer-display">--:--</strong>
    </div>
    <form method="post" id="form-jawab">
        <?php $no = 0; foreach ($soalList as $soal):
            $no++;
            $sid = (int) $soal['id'];
            $jenis = (string) ($soal['jenis'] ?? 'PG');
            $jStmt = $pdo->prepare('SELECT jawaban_santri FROM ikhtibar_jawaban WHERE sesi_id = :s AND soal_id = :q LIMIT 1');
            $jStmt->execute(['s' => (int) $sesi['id'], 'q' => $sid]);
            $jawabSaved = (string) ($jStmt->fetchColumn() ?: '');
            ?>
            <div class="card mb-2 border-0 shadow-sm">
                <div class="card-body py-2">
                    <div class="small text-muted mb-1"><?= $jenis === 'PG' ? 'PG' : 'Esai' ?> · Soal <?= $no ?></div>
                    <div class="mb-2"><?= nl2br(htmlspecialchars((string) ($soal['teks_soal'] ?? ''))) ?></div>
                    <?php if ($jenis === 'PG'): ?>
                        <?php foreach (['A' => 'opsi_a', 'B' => 'opsi_b', 'C' => 'opsi_c', 'D' => 'opsi_d', 'E' => 'opsi_e'] as $huruf => $col):
                            if (trim((string) ($soal[$col] ?? '')) === '') {
                                continue;
                            }
                            ?>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="jawaban_<?= $sid ?>" value="<?= $huruf ?>" id="j<?= $sid ?>_<?= $huruf ?>" <?= $jawabSaved === $huruf ? 'checked' : '' ?>>
                                <label class="form-check-label" for="j<?= $sid ?>_<?= $huruf ?>"><?= $huruf ?>. <?= htmlspecialchars((string) $soal[$col]) ?></label>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <textarea name="jawaban_<?= $sid ?>" class="form-control form-control-sm" rows="3"><?= htmlspecialchars($jawabSaved) ?></textarea>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="d-grid gap-2 mt-3">
            <button type="submit" name="action" value="simpan" class="btn btn-outline-secondary">Simpan sementara</button>
            <button type="submit" name="action" value="selesai" class="btn btn-auth-primary" onclick="return confirm('Kirim jawaban final?');">Selesai &amp; kirim</button>
        </div>
    </form>
    <script>
    (function () {
        var sisa = <?= (int) $sisaDetik ?>;
        var el = document.getElementById('timer-display');
        var box = document.getElementById('timer-box');
        var form = document.getElementById('form-jawab');
        function tick() {
            if (sisa <= 0) {
                if (el) el.textContent = '00:00';
                if (box) box.classList.replace('alert-warning', 'alert-danger');
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
            sisa--;
            setTimeout(tick, 1000);
        }
        tick();
    })();
    </script>
<?php endif; ?>

<p class="text-center mt-3 mb-0"><a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/index.php')) ?>" class="small">← Daftar tugas</a></p>
<?php
auth_portal_layout_end([], true);
