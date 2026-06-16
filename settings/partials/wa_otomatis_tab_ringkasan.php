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
        <p class="small text-muted mb-2">Jadwalkan <code>cron/wa_auto.php</code> setiap <strong>1 menit</strong> (paling akurat). Tanpa cron, job terjadwal tetap bisa jalan saat staf login dan membuka aplikasi (throttle ~1 menit ringan / ~5 menit berat).</p>
        <ul class="small mb-3 ps-3">
            <li>Terakhir jalan: <strong><?= $waCronLastRun !== '' ? htmlspecialchars($waCronLastRun) : 'Belum pernah' ?></strong></li>
            <li>Tick berat terakhir: <strong><?= $waLastHeavy !== '' ? htmlspecialchars($waLastHeavy) : '—' ?></strong></li>
            <li>Job WA terjadwal terakhir: <strong><?= $waScheduledLastAt !== '' ? htmlspecialchars($waScheduledLastAt) : 'Belum pernah' ?></strong></li>
            <?php if ($waScheduledLast && !empty($waScheduledLast['skipped'])): ?>
                <li class="text-warning">Job terjadwal dilewati: <?= htmlspecialchars((string) ($waScheduledLast['gateway_error'] ?? $waScheduledLast['reason'] ?? 'gateway')) ?></li>
            <?php elseif ($waAlpaLast): ?>
                <li>Alpa terakhir: <?= (int) ($waAlpaLast['sent'] ?? 0) ?> terkirim · <?= (int) ($waAlpaLast['rows'] ?? 0) ?> baris</li>
            <?php endif; ?>
        </ul>
        <h3 class="h6 mb-2">Checklist otomatisasi WA</h3>
        <ul class="small mb-3 ps-3">
            <li>Gateway: <?= $waGatewayErr === null ? '<span class="text-success">OK</span>' : '<span class="text-danger">Error</span>' ?></li>
            <li>Master WA: <?= $waMasterOn ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>Mode notifikasi: <?= htmlspecialchars(match ($notifyMode) { 'push' => 'Push saja (izin WA off)', 'wa' => 'WA saja', default => 'WA + Push' }) ?></li>
            <li>Tagihan wali: <?= ($values['wa_tagihan_auto_enabled'] ?? '') === '1' ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>Scan pembimbing: <?= trim((string) app_setting($pdo, 'wa_pembimbing_scan_enabled', '1')) === '1' ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>Kelas kosong: <?= trim((string) app_setting($pdo, 'wa_kelas_kosong_enabled', '1')) === '1' ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>Cashless laporan: <?= $cashlessLaporanHarianWaEnabled ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>Cashless transaksi → wali: <?= $cashlessTransaksiWaEnabled ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>Fallback tanpa cron: <?= trim((string) app_setting($pdo, 'wa_auto_web_fallback_enabled', '1')) === '1' ? '<span class="text-success">Aktif</span> (saat staf buka app)' : '<span class="text-muted">Nonaktif</span>' ?></li>
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
            <li class="mb-1"><strong>Cashless</strong> — transaksi/saldo rendah ke wali &amp; laporan harian ke pengurus.</li>
            <li class="mb-1"><strong>Presensi</strong> — pengingat scan, munawib, laporan kelas kosong.</li>
            <li class="mb-1"><strong>Alpa</strong> — tier penerima &amp; nomor fallback alpa otomatis.</li>
            <li class="mb-1"><strong>Izin</strong> — permohonan baru (PENDING) &amp; notifikasi saat izin disetujui.</li>
            <li><strong>Template</strong> — sesuaikan teks pesan bila perlu.</li>
        </ol>
    </div>
</div>
