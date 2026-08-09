<?php
require_once 'includes/auth.php';
if (!($_SESSION['super_admin'] ?? false)) die('Accès refusé');

$f = '/tmp/scan_debug_cadurix.log';

if (isset($_GET['vider'])) {
    file_put_contents($f, '');
    die('Log vidé. <a href="lire_log.php">Retour</a>');
}

header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Scan Debug Log</title>
<style>
body{font-family:monospace;background:#1a1a2e;color:#e2e8f0;padding:20px}
h2{color:#60a5fa}
pre{background:#0f0f1a;border:1px solid #334155;border-radius:8px;padding:16px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;font-size:.85rem}
.btn{display:inline-block;padding:8px 16px;border-radius:6px;text-decoration:none;margin-bottom:16px;font-family:sans-serif;color:#fff}
.empty{color:#f59e0b}
</style></head><body>';

echo '<h2>🔍 Scan Debug Log</h2>';
echo '<a class="btn" style="background:#ef4444" href="lire_log.php?vider=1">🗑 Vider</a> ';
echo '<a class="btn" style="background:#3b82f6" href="lire_log.php">🔄 Rafraîchir</a>';

if (!file_exists($f) || filesize($f) === 0) {
    echo '<p class="empty">⚠️ Log vide — scanne un médicament puis rafraîchis.</p>';
} else {
    echo '<pre>' . htmlspecialchars(file_get_contents($f)) . '</pre>';
}

echo '</body></html>';
