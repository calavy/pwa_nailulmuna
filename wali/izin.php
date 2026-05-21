<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/wali_perizinan.php';

wali_perizinan_ensure_tables($pdo);

$defaultPemohon = wali_portal_resolve_nama_wali($pdo, $waliSantriRow);
if ($defaultPemohon === '') {
    $defaultPemohon = 'Wali santri';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetSantriId = (int) ($_POST['santri_id'] ?? $waliSantriId);
    $result = wali_perizinan_ajukan(
        $pdo,
        $targetSantriId,
        $waliAnakIds,
        (string) ($_POST['jenis_izin'] ?? 'KELUAR'),
        trim((string) ($_POST['tanggal_mulai'] ?? '')),
        trim((string) ($_POST['tanggal_selesai'] ?? '')),
        trim((string) ($_POST['jam_mulai'] ?? '')),
        trim((string) ($_POST['jam_selesai'] ?? '')),
        (float) ($_POST['durasi_jam'] ?? 0),
        trim((string) ($_POST['alasan'] ?? '')),
        trim((string) ($_POST['pemberi_izin'] ?? $defaultPemohon)),
        trim((string) ($_POST['gejala'] ?? '')),
        ($_POST['suhu_tubuh'] ?? '') !== '' && is_numeric($_POST['suhu_tubuh']) ? (float) $_POST['suhu_tubuh'] : null
    );
    if ($result['ok']) {
        set_flash('success', $result['message']);
    } else {
        set_flash('error', $result['message']);
    }
    header('Location: ' . app_href('/wali/izin.php'));
    exit;
}

$riwayatIzin = wali_perizinan_list_for_santri($pdo, $waliAnakIds, 50);

require_once __DIR__ . '/includes/layout.php';
wali_layout_head('Ajukan izin — Portal Wali', true, 'izin');
require __DIR__ . '/partials/greeting.php';
?>

        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h1 class="h5 mb-0 wali-brand fw-bold">Ajukan izin keluar</h1>
                <p class="small text-muted mb-0">Permohonan masuk antrian pengurus. Status dapat dilihat di bawah.</p>
            </div>
            <a class="btn btn-sm btn-outline-secondary flex-shrink-0" href="<?= htmlspecialchars(app_href('/wali/logout.php')) ?>">Keluar</a>
        </div>

        <div class="card wali-card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6 mb-3">Form permohonan</h2>
                <form method="post" class="row g-2" id="form-wali-izin">
                    <?php if (count($waliAnakRows) > 1): ?>
                    <div class="col-12">
                        <label class="form-label small mb-0">Anak</label>
                        <select name="santri_id" class="form-select form-select-sm" required>
                            <?php foreach ($waliAnakRows as $anak): ?>
                                <option value="<?= (int) $anak['id'] ?>" <?= (int) $anak['id'] === $waliSantriId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $anak['nama_tampil']) ?> (<?= htmlspecialchars((string) $anak['nis']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="santri_id" value="<?= $waliSantriId ?>">
                    <?php endif; ?>
                    <div class="col-6">
                        <label class="form-label small mb-0">Jenis izin</label>
                        <select name="jenis_izin" id="jenis-izin-wali" class="form-select form-select-sm" required>
                            <option value="KELUAR" selected>Keluar</option>
                            <option value="PULANG">Pulang</option>
                            <option value="SAKIT">Sakit</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Pemohon</label>
                        <input type="text" name="pemberi_izin" class="form-control form-control-sm" value="<?= htmlspecialchars($defaultPemohon) ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Tanggal mulai</label>
                        <input type="date" name="tanggal_mulai" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Tanggal selesai</label>
                        <input type="date" name="tanggal_selesai" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-0">Jam mulai</label>
                        <input type="time" name="jam_mulai" class="form-control form-control-sm" value="<?= date('H:i') ?>" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-0">Jam selesai</label>
                        <input type="time" name="jam_selesai" class="form-control form-control-sm" value="<?= date('H:i') ?>" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-0">Durasi (jam)</label>
                        <input type="number" step="0.25" min="0" name="durasi_jam" class="form-control form-control-sm" placeholder="3">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Alasan</label>
                        <textarea name="alasan" class="form-control form-control-sm" rows="2" required placeholder="Contoh: keperluan keluarga"></textarea>
                    </div>
                    <div id="wali-ehealth-block" class="col-12 border-top pt-2 d-none">
                        <p class="small fw-semibold mb-1">Data sakit</p>
                        <textarea name="gejala" class="form-control form-control-sm mb-2" rows="2" placeholder="Gejala"></textarea>
                        <input type="number" step="0.1" name="suhu_tubuh" class="form-control form-control-sm" placeholder="Suhu °C (wajib jika sakit)">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-teal btn-sm w-100">Kirim permohonan</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card wali-card shadow-sm">
            <div class="card-header bg-white small fw-semibold">Riwayat permohonan izin</div>
            <div class="card-body p-0">
                <?php if ($riwayatIzin === []): ?>
                    <p class="small text-muted text-center py-4 mb-0">Belum ada permohonan.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($riwayatIzin as $iz): ?>
                            <?php
                            $st = (string) ($iz['approval_status'] ?? 'PENDING');
                            $badge = wali_perizinan_status_badge($st);
                            ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="small">
                                        <div class="fw-semibold"><?= htmlspecialchars(jenis_izin_label((string) ($iz['jenis_izin'] ?? 'KELUAR'))) ?></div>
                                        <div class="text-muted"><?= htmlspecialchars((string) ($iz['nama_santri'] ?? '')) ?> · <?= htmlspecialchars((string) ($iz['tanggal_mulai'] ?? '')) ?> – <?= htmlspecialchars((string) ($iz['tanggal_selesai'] ?? '')) ?></div>
                                        <div class="text-muted"><?= htmlspecialchars(substr((string) ($iz['jam_mulai'] ?? ''), 0, 5)) ?>–<?= htmlspecialchars(substr((string) ($iz['jam_selesai'] ?? ''), 0, 5)) ?></div>
                                    </div>
                                    <span class="badge text-bg-<?= $badge ?>"><?= htmlspecialchars($st) ?></span>
                                </div>
                                <div class="small mt-1"><?= htmlspecialchars((string) ($iz['alasan'] ?? '')) ?></div>
                                <?php if ($st === 'DITOLAK' && trim((string) ($iz['rejected_reason'] ?? '')) !== ''): ?>
                                    <div class="small text-danger mt-1"><?= htmlspecialchars((string) $iz['rejected_reason']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

<script>
(function () {
    const sel = document.getElementById('jenis-izin-wali');
    const block = document.getElementById('wali-ehealth-block');
    if (!sel || !block) return;
    const toggle = function () {
        block.classList.toggle('d-none', sel.value !== 'SAKIT');
    };
    sel.addEventListener('change', toggle);
    toggle();
})();
</script>

<?php
wali_layout_foot(true, 'izin');
