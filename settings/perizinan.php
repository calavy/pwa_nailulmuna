<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pengaturan_acl.php';
require_once __DIR__ . '/../helpers/perizinan_approval.php';
require_once __DIR__ . '/../helpers/wa_templates.php';
require_once __DIR__ . '/../helpers/perizinan_syari_kategori.php';

require_roles(['admin', 'pengurus']);
migrate_legacy_permissions_to_pengaturan($pdo);
ensure_pondok_settings_defaults($pdo);
perizinan_approval_ensure_schema($pdo);
perizinan_syari_kategori_ensure_schema($pdo);

$fields = [
    'izin_alpa_batas_enabled',
    'izin_alpa_keluar_max',
    'izin_alpa_keluar_hari',
    'izin_alpa_pulang_max',
    'izin_alpa_pulang_hari',
    'izin_alpa_bypass_user_ids',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_perizinan_settings') {
    save_setting($pdo, 'izin_alpa_batas_enabled', isset($_POST['izin_alpa_batas_enabled']) ? '1' : '0');
    save_setting($pdo, 'izin_alpa_keluar_max', (string) max(0, (int) ($_POST['izin_alpa_keluar_max'] ?? 3)));
    save_setting($pdo, 'izin_alpa_keluar_hari', (string) max(1, (int) ($_POST['izin_alpa_keluar_hari'] ?? 4)));
    save_setting($pdo, 'izin_alpa_pulang_max', (string) max(0, (int) ($_POST['izin_alpa_pulang_max'] ?? 3)));
    save_setting($pdo, 'izin_alpa_pulang_hari', (string) max(1, (int) ($_POST['izin_alpa_pulang_hari'] ?? 4)));
    $bypassIds = [];
    foreach ((array) ($_POST['izin_alpa_bypass_user_ids'] ?? []) as $uidRaw) {
        $uid = (int) $uidRaw;
        if ($uid > 0) {
            $bypassIds[$uid] = $uid;
        }
    }
    save_setting($pdo, 'izin_alpa_bypass_user_ids', implode(',', array_values($bypassIds)));
    perizinan_syari_kategori_save_from_post($pdo, $_POST);
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
$syariKategoriCfg = perizinan_syari_kategori_settings($pdo);
$bypassUserIds = perizinan_alpa_bypass_user_ids($pdo);
$bypassAdminCandidates = $pdo->query("
    SELECT id, nama, username, role, COALESCE(is_super_admin, 0) AS is_super_admin
    FROM users
    WHERE role IN ('admin', 'pengurus')
    ORDER BY nama ASC, username ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
        <div class="card shadow-sm border-0 border-start border-3 border-warning">
            <div class="card-body">
                <h2 class="h6 mb-2">Keperluan izin syar'i (portal wali)</h2>
                <p class="small text-muted mb-3">
                    Centang keperluan yang boleh dipilih wali saat mengajukan izin syar'i.
                    Atur <strong>durasi maksimal</strong> (hari) dan <strong>batas ALPA</strong> per keperluan.
                    Item yang dicentang akan muncul di form portal wali sebagai pilihan alasan.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:2.5rem">Aktif</th>
                                <th>Keperluan syar'i</th>
                                <th style="min-width:7rem">Durasi max<br><span class="fw-normal text-muted">(hari)</span></th>
                                <th style="min-width:7rem">Blokir ALPA ≥<br><span class="fw-normal text-muted">(kali)</span></th>
                                <th style="min-width:7rem">Hitung ALPA<br><span class="fw-normal text-muted">(hari)</span></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($syariKategoriCfg as $kode => $row): ?>
                            <tr>
                                <td>
                                    <input class="form-check-input" type="checkbox" name="syari_kat_<?= htmlspecialchars((string) $kode) ?>_enabled" value="1" id="syari-kat-<?= htmlspecialchars((string) $kode) ?>" <?= !empty($row['enabled']) ? 'checked' : '' ?>>
                                </td>
                                <td>
                                    <label class="mb-0 fw-semibold small" for="syari-kat-<?= htmlspecialchars((string) $kode) ?>">
                                        <?= htmlspecialchars((string) ($row['label'] ?? $kode)) ?>
                                    </label>
                                </td>
                                <td>
                                    <input type="number" min="1" max="90" class="form-control form-control-sm" name="syari_kat_<?= htmlspecialchars((string) $kode) ?>_durasi" value="<?= (int) ($row['durasi_hari'] ?? 1) ?>">
                                </td>
                                <td>
                                    <input type="number" min="0" max="99" class="form-control form-control-sm" name="syari_kat_<?= htmlspecialchars((string) $kode) ?>_alpa_max" value="<?= (int) ($row['alpa_max'] ?? 0) ?>">
                                    <div class="form-text">0 = tanpa batas</div>
                                </td>
                                <td>
                                    <input type="number" min="1" max="90" class="form-control form-control-sm" name="syari_kat_<?= htmlspecialchars((string) $kode) ?>_alpa_hari" value="<?= (int) ($row['alpa_hari'] ?? 4) ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mb-0 mt-2">
                    Contoh: Walimah durasi 2 hari, blokir jika ALPA ≥ 3 kali dalam 4 hari terakhir.
                    Batas ALPA per keperluan ini dipakai saat pengasuh/pengurus meninjau permohonan (menggantikan batas umum izin keluar untuk baris yang memilih keperluan tersebut).
                </p>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h2 class="h6 mb-2">Syarat ALPA sebelum disetujui</h2>
                <p class="small text-muted mb-3">
                    <strong>Izin syar'i</strong> wajib persetujuan pengasuh (termasuk cek ALPA). Pengasuh dapat centang <strong>Lewati ALPA</strong> bila santri terhalang. Setelah disetujui pengasuh, pengurus hanya mencetak surat.
                    <strong>Izin sakit</strong> tidak terkena batas ALPA.
                    Jika ALPA tidak memenuhi syarat, tombol setujui diblokir kecuali oleh <strong>admin super</strong> atau <strong>admin ditunjuk</strong> di bawah.
                </p>
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
                            <p class="small text-muted mb-0 mt-2">Contoh: ≥ 3 ALPA dalam 4 hari → tolak (kecuali bypass admin super / admin ditunjuk).</p>
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
                <div class="border rounded-3 p-3 mt-3">
                    <div class="fw-semibold mb-2">Admin yang boleh lewati syarat ALPA</div>
                    <p class="small text-muted mb-2">Admin super selalu boleh. Centang pengguna admin/pengurus tambahan yang ditunjuk untuk menyetujui izin meski ALPA tidak memenuhi syarat.</p>
                    <?php if ($bypassAdminCandidates === []): ?>
                        <p class="small text-muted mb-0">Belum ada akun admin/pengurus.</p>
                    <?php else: ?>
                        <div class="row g-2">
                            <?php foreach ($bypassAdminCandidates as $u):
                                $uid = (int) ($u['id'] ?? 0);
                                if ($uid <= 0) {
                                    continue;
                                }
                                $isSuper = (int) ($u['is_super_admin'] ?? 0) === 1;
                            ?>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="izin_alpa_bypass_user_ids[]" value="<?= $uid ?>" id="bypass-user-<?= $uid ?>" <?= in_array($uid, $bypassUserIds, true) ? 'checked' : '' ?> <?= $isSuper ? 'disabled checked' : '' ?>>
                                        <label class="form-check-label" for="bypass-user-<?= $uid ?>">
                                            <?= htmlspecialchars((string) ($u['nama'] ?? $u['username'] ?? ('#' . $uid))) ?>
                                            <span class="text-muted small">(@<?= htmlspecialchars((string) ($u['username'] ?? '')) ?> · <?= htmlspecialchars((string) ($u['role'] ?? '')) ?>)</span>
                                            <?php if ($isSuper): ?><span class="badge text-bg-danger ms-1">Super</span><?php endif; ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card shadow-sm border-0 border-start border-3 border-success">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 class="h6 mb-1">Notifikasi WA izin</h2>
                    <p class="small text-muted mb-0">Pembimbing, grup Fonte, dan gateway dikelola terpusat di halaman WA Otomatis.</p>
                </div>
                <a class="btn btn-outline-success btn-sm" href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=izin')) ?>">Buka tab Izin</a>
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
                    <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=template')) ?>">Template WA</a>.
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
