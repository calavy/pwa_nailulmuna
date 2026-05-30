<?php
/** @deprecated Form gabungan dipisah — gunakan pondok_identity_view + pondok_wa_settings_view */
require __DIR__ . '/pondok_theme_toggle.php';
require __DIR__ . '/pondok_identity_view.php';
echo '<p class="text-muted small">Pengaturan WA dipindah ke <a href="' . htmlspecialchars(app_href('/settings/wa_gateway.php')) . '">WA Gateway</a>.</p>';
