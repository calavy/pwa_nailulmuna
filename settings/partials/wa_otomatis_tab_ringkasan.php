<?php

declare(strict_types=1);

?>
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-3">Status singkat</h2>
        <div class="row g-2">
            <div class="col-6 col-md-3">
                <div class="border rounded-3 p-2 h-100">
                    <div class="small text-muted">Gateway</div>
                    <div class="fw-semibold <?= $waGatewayErr === null ? 'text-success' : 'text-warning' ?>">
                        <?= $waGatewayErr === null ? 'Siap kirim' : 'Perlu perbaikan' ?>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded-3 p-2 h-100">
                    <div class="small text-muted">Master WA</div>
                    <div class="fw-semibold"><?= $waMasterOn ? 'Aktif' : 'Nonaktif' ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded-3 p-2 h-100">
                    <div class="small text-muted">Mode notifikasi</div>
                    <div class="fw-semibold"><?= htmlspecialchars(match ($notifyMode) { 'push' => 'Push saja', 'wa' => 'WA saja', default => 'WA + Push' }) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded-3 p-2 h-100">
                    <div class="small text-muted">Tagihan otomatis</div>
                    <div class="fw-semibold"><?= ($values['wa_tagihan_auto_enabled'] ?? '') === '1' ? 'Aktif' : 'Nonaktif' ?></div>
                </div>
            </div>
        </div>
        <?php if ($waGatewayErr !== null): ?>
            <div class="alert alert-warning py-2 small mt-3 mb-0"><?= htmlspecialchars($waGatewayErr) ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-clock me-1"></i> Cron otomatis</h2>
        <p class="small text-muted mb-2">Jadwalkan <code>cron/wa_auto.php</code> setiap <strong>1 menit</strong>. Tick ringan: scan pembimbing. Tick berat (~5 menit): tagihan, alpa, kelas kosong.</p>
        <ul class="small mb-3 ps-3">
            <li>Terakhir jalan: <strong><?= $waCronLastRun !== '' ? htmlspecialchars($waCronLastRun) : 'Belum pernah' ?></strong></li>
            <li>Tick berat terakhir: <strong><?= $waLastHeavy !== '' ? htmlspecialchars($waLastHeavy) : '—' ?></strong></li>
        </ul>
        <div class="input-group input-group-sm mb-2" style="max-width:36rem">
            <span class="input-group-text">URL cron</span>
            <input type="text" class="form-control font-monospace" readonly value="<?= htmlspecialchars($cronUrl) ?>" id="wa-cron-url">
            <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('wa-cron-url').value)">Salin</button>
        </div>
        <p class="small text-muted mb-0">Atur kunci cron di tab <a href="?tab=gateway">Gateway</a>. <?= $waCronKey === '' ? '<span class="text-warning">Belum ada kunci.</span>' : '<span class="text-success">Kunci aktif.</span>' ?></p>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <h2 class="h6 mb-2">Alur kerja (mudah dipahami)</h2>
        <ol class="small text-muted mb-0 ps-3">
            <li class="mb-1"><strong>Gateway</strong> — isi token Fonnte &amp; tes kirim ke satu nomor.</li>
            <li class="mb-1"><strong>Tagihan Wali</strong> — atur jadwal &amp; aktifkan pengingat syahriyah.</li>
            <li class="mb-1"><strong>Presensi</strong> — pengingat scan, munawib, laporan kelas kosong.</li>
            <li class="mb-1"><strong>Alpa</strong> — tier penerima saat santri alpa bertambah.</li>
            <li class="mb-1"><strong>Izin</strong> — notifikasi ke pembimbing &amp; grup saat izin disetujui.</li>
            <li><strong>Template</strong> — sesuaikan teks pesan bila perlu.</li>
        </ol>
    </div>
</div>
