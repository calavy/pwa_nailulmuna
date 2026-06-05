<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pengaturan_acl.php';
require_once __DIR__ . '/../helpers/perizinan_approval.php';
require_once __DIR__ . '/../helpers/wa_templates.php';

require_roles(['admin', 'pengurus']);
migrate_legacy_permissions_to_pengaturan($pdo);
ensure_pondok_settings_defaults($pdo);
perizinan_approval_ensure_schema($pdo);

$fields = [
    'izin_alpa_batas_enabled',
    'izin_alpa_keluar_max',
    'izin_alpa_keluar_hari',
    'izin_alpa_pulang_max',
    'izin_alpa_pulang_hari',
    'wa_izin_pembimbing_enabled',
    'wa_izin_pembimbing_kirim_grup',
    'wa_izin_pembimbing_grup',
    'wa_izin_grup_fonte_enabled',
    'wa_izin_grup_fonte',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_perizinan_settings') {
    save_setting($pdo, 'izin_alpa_batas_enabled', isset($_POST['izin_alpa_batas_enabled']) ? '1' : '0');
    save_setting($pdo, 'izin_alpa_keluar_max', (string) max(0, (int) ($_POST['izin_alpa_keluar_max'] ?? 3)));
    save_setting($pdo, 'izin_alpa_keluar_hari', (string) max(1, (int) ($_POST['izin_alpa_keluar_hari'] ?? 4)));
    save_setting($pdo, 'izin_alpa_pulang_max', (string) max(0, (int) ($_POST['izin_alpa_pulang_max'] ?? 3)));
    save_setting($pdo, 'izin_alpa_pulang_hari', (string) max(1, (int) ($_POST['izin_alpa_pulang_hari'] ?? 4)));
    save_setting($pdo, 'wa_izin_pembimbing_enabled', isset($_POST['wa_izin_pembimbing_enabled']) ? '1' : '0');
    save_setting($pdo, 'wa_izin_pembimbing_kirim_grup', isset($_POST['wa_izin_pembimbing_kirim_grup']) ? '1' : '0');
    save_setting($pdo, 'wa_izin_pembimbing_grup', trim((string) ($_POST['wa_izin_pembimbing_grup'] ?? '')));
    save_setting($pdo, 'wa_izin_grup_fonte_enabled', isset($_POST['wa_izin_grup_fonte_enabled']) ? '1' : '0');
    $grupFonte = trim((string) ($_POST['wa_izin_grup_fonte'] ?? ''));
    if ($grupFonte === '') {
        $grupFonte = trim((string) ($_POST['wa_izin_pembimbing_grup'] ?? ''));
    }
    save_setting($pdo, 'wa_izin_grup_fonte', $grupFonte);
    $doaSakit = trim((string) ($_POST['wa_tpl_izin_sakit_doa'] ?? ''));
    $doaDefault = (string) (wa_template_definitions()['izin_sakit_doa']['default'] ?? '');
    if ($doaSakit === '' || $doaSakit === $doaDefault) {
        $st = $pdo->prepare('DELETE FROM app_settings WHERE setting_key = :k LIMIT 1');
        $st->execute(['k' => wa_template_setting_key('izin_sakit_doa')]);
    } else {
        save_setting($pdo, wa_template_setting_key('izin_sakit_doa'), $doaSakit);
    }
    if (function_exists('app_settings_cache_reset')) {
        app_settings_cache_reset($pdo);
    }
    set_flash('success', 'Pengaturan perizinan disimpan.');
    header('Location: ' . app_href('/settings/perizinan.php'));
    exit;
}

$cfg = perizinan_alpa_settings($pdo);
$waIzinEnabled = trim((string) app_setting($pdo, 'wa_izin_pembimbing_enabled', '1')) === '1';
$waIzinGrup = trim((string) app_setting($pdo, 'wa_izin_pembimbing_grup', ''));
$waIzinKirimGrup = trim((string) app_setting($pdo, 'wa_izin_pembimbing_kirim_grup', '0')) === '1';
$waIzinGrupFonteEnabled = trim((string) app_setting($pdo, 'wa_izin_grup_fonte_enabled', $waIzinKirimGrup ? '1' : '0')) === '1';
$waIzinGrupFonte = trim((string) app_setting($pdo, 'wa_izin_grup_fonte', ''));
if ($waIzinGrupFonte === '') {
    $waIzinGrupFonte = $waIzinGrup;
}
$doaSakitTpl = wa_template_get($pdo, 'izin_sakit_doa');
$doaSakitMeta = wa_template_definitions()['izin_sakit_doa'] ?? ['placeholders' => '', 'default' => ''];

$pageTitle = 'Pengaturan Perizinan';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/perizinan.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">Perizinan santri</h1>
    <p class="text-muted mb-0 small">Syarat ALPA, notifikasi WA ke pembimbing/grup, doa izin sakit, dan pengiriman tunggal untuk izin rombongan.</p>
</div>

<form method="post" class="row g-3">
    <input type="hidden" name="action" value="save_perizinan_settings">

    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h2 class="h6 mb-2">Syarat ALPA sebelum disetujui</h2>
                <p class="small text-muted">Izin <strong>sakit</strong> tidak terkena batas ini. Admin super dapat melewati syarat saat menyetujui.</p>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="izin_alpa_batas_enabled" name="izin_alpa_batas_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="izin_alpa_batas_enabled">Aktifkan pembatasan berdasarkan riwayat ALPA</label>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="fw-semibold mb-2">Izin keluar</div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small mb-0">Blokir jika ALPA ≥</label>
                                    <input type="number" min="0" max="99" class="form-control form-control-sm" name="izin_alpa_keluar_max" value="<?= (int) $cfg['keluar_max'] ?>">
                                    <div class="form-text">0 = tanpa batas</div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-0">Dalam (hari)</label>
                                    <input type="number" min="1" max="90" class="form-control form-control-sm" name="izin_alpa_keluar_hari" value="<?= (int) $cfg['keluar_hari'] ?>">
                                </div>
                            </div>
                            <p class="small text-muted mb-0 mt-2">Contoh: ≥ 3 ALPA dalam 4 hari → tolak (kecuali bypass super admin).</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="fw-semibold mb-2">Izin pulang / tugas</div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small mb-0">Blokir jika ALPA ≥</label>
                                    <input type="number" min="0" max="99" class="form-control form-control-sm" name="izin_alpa_pulang_max" value="<?= (int) $cfg['pulang_max'] ?>">
                                    <div class="form-text">0 = tanpa batas</div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-0">Dalam (hari)</label>
                                    <input type="number" min="1" max="90" class="form-control form-control-sm" name="izin_alpa_pulang_hari" value="<?= (int) $cfg['pulang_hari'] ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h2 class="h6 mb-2">Notifikasi WA saat izin disetujui</h2>
                <p class="small text-muted">
                    Token &amp; URL gateway di <a href="<?= htmlspecialchars(app_href('/settings/wa_gateway.php')) ?>">WA Gateway (Fonte/Fonnte)</a>.
                    Template pesan di <a href="<?= htmlspecialchars(app_href('/settings/wa_pesan.php')) ?>">Template Pesan WA</a>
                    (grup: <em>Izin disetujui → grup WA Fonte</em>).
                </p>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="wa_izin_pembimbing_enabled" name="wa_izin_pembimbing_enabled" value="1" <?= $waIzinEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_izin_pembimbing_enabled">Kirim WA ke pembimbing terkait</label>
                </div>
                <p class="small text-muted mb-2">Toggle per pembimbing di <a href="<?= htmlspecialchars(app_href('/pembimbing/index.php')) ?>">Data Pembimbing → Edit</a>.</p>
                <div class="border rounded-3 p-3 bg-light">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="wa_izin_grup_fonte_enabled" name="wa_izin_grup_fonte_enabled" value="1" <?= $waIzinGrupFonteEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="wa_izin_grup_fonte_enabled">Kirim ke grup WA (Fonte)</label>
                    </div>
                    <label class="form-label small mb-1">Kode / ID grup Fonte</label>
                    <input type="text" class="form-control font-monospace" name="wa_izin_grup_fonte" value="<?= htmlspecialchars($waIzinGrupFonte) ?>" placeholder="Contoh: 120363xxxxx@g.us atau ID grup dari panel Fonte">
                    <div class="form-text mb-0">Salin dari dashboard Fonte/Fonnte → Device → Grup. Bisa juga beberapa target dipisah koma.</div>
                </div>
                <details class="mt-2 small">
                    <summary class="text-muted">Pengaturan lama (nomor grup tambahan)</summary>
                    <div class="form-check mt-2 mb-2">
                        <input class="form-check-input" type="checkbox" id="wa_izin_pembimbing_kirim_grup" name="wa_izin_pembimbing_kirim_grup" value="1" <?= $waIzinKirimGrup ? 'checked' : '' ?>>
                        <label class="form-check-label" for="wa_izin_pembimbing_kirim_grup">Mode lama: juga kirim ke nomor di bawah</label>
                    </div>
                    <input type="text" class="form-control form-control-sm" name="wa_izin_pembimbing_grup" value="<?= htmlspecialchars($waIzinGrup) ?>" placeholder="628xxx">
                </details>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h2 class="h6 mb-2">Doa tambahan izin sakit</h2>
                <p class="small text-muted mb-2">
                    Ditambahkan otomatis di akhir pesan WA saat izin <strong>sakit</strong> disetujui (ke pembimbing &amp; grup).
                    Kosongkan untuk menonaktifkan. Template pesan utama di
                    <a href="<?= htmlspecialchars(app_href('/settings/wa_pesan.php')) ?>">Template Pesan WA</a>.
                </p>
                <p class="small mb-2"><strong>Placeholder:</strong> <code><?= htmlspecialchars((string) ($doaSakitMeta['placeholders'] ?? '')) ?></code></p>
                <textarea class="form-control font-monospace" name="wa_tpl_izin_sakit_doa" rows="7"><?= htmlspecialchars($doaSakitTpl) ?></textarea>
                <details class="mt-2">
                    <summary class="small text-muted">Reset ke default</summary>
                    <pre class="small bg-light p-2 rounded mt-1 mb-0"><?= htmlspecialchars((string) ($doaSakitMeta['default'] ?? '')) ?></pre>
                </details>
            </div>
        </div>
    </div>

    <div class="col-12">
        <button type="submit" class="btn btn-primary">Simpan pengaturan</button>
    </div>
</form>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
