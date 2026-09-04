<?php
/**
 * Cadurix v5 — Administration Système
 * Maintenance, sauvegarde, sessions, nettoyage, paramètres
 */
if (!defined('ADMIN_HUB')) require_once '../includes/auth.php';
if (!isAdmin()) { accessDenied(); }
$pageTitle = 'Administration système';

$message = '';
$erreur  = '';
$sec  = $_GET['sec'] ?? 'infos';


// ── Fichiers config ───────────────────────────────────────
$maint_file    = __DIR__ . '/../config/maintenance.json';
$settings_file = __DIR__ . '/../config/settings.json';
$backup_dir    = __DIR__ . '/../backups/';
$log_file      = ini_get('error_log') ?: '';

function read_json(string $file): array {
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?? [];
}
function write_json(string $file, array $data): bool {
    return (bool)file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$maint    = read_json($maint_file);
$settings = read_json($settings_file);

// Statut sécurité
$lockdown = 0;
$nb_ban   = 0;
try {
    $lockdown = (int)$pdo->query("SELECT COUNT(*) FROM security_lockdown WHERE actif=1")->fetchColumn();
    $nb_ban   = (int)$pdo->query("SELECT COUNT(*) FROM security_ips WHERE statut='banni'")->fetchColumn();
} catch(Exception $e) {}

// ── Traitement POST ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // --- Maintenance ---
    if ($action === 'save_maintenance') {
        $actif  = isset($_POST['actif']);
        $avant  = $maint['actif'] ?? false;
        $maint['actif']          = $actif;
        $maint['message']        = trim($_POST['message'] ?? 'Site en maintenance.');
        $maint['retour_estime']  = trim($_POST['retour_estime'] ?? '');
        $maint['ips_bypass']     = array_filter(array_map('trim', explode("\n", $_POST['ips_bypass'] ?? '')));

        if ($actif && !$avant) {
            $maint['debut'] = date('c');
            $maint['historique'][] = ['debut' => date('c'), 'message' => $maint['message']];
            if (count($maint['historique']) > 10) array_shift($maint['historique']);
        }
        if (!$actif && $avant) {
            $maint['debut'] = null;
            if (!empty($maint['historique'])) {
                $maint['historique'][count($maint['historique'])-1]['fin'] = date('c');
            }
        }
        write_json($maint_file, $maint);
        audit_log($pdo, $actif ? 'MAINTENANCE_ON' : 'MAINTENANCE_OFF', 'system');
        $message = $actif ? "Mode maintenance activé." : "Mode maintenance désactivé.";
        $sec = 'maintenance';
    }

    // --- Toggle scan photo ---
    if ($action === 'toggle_scan_photo') {
        $val = ($_POST['scan_photo_actif'] ?? '0') === '1' ? '1' : '0';
        $pdo->prepare("INSERT INTO parametres (cle,valeur,type,label,groupe) VALUES('scan_photo_actif',?,'bool','Reconnaissance photo','ia') ON DUPLICATE KEY UPDATE valeur=?")
            ->execute([$val, $val]);
        $message = $val === '1' ? "📷 Reconnaissance photo activée." : "📷 Reconnaissance photo désactivée.";
        $sec = 'settings';
    }

    // --- Paramètres ---
    if ($action === 'save_settings') {
        $settings['app_nom']                  = trim($_POST['app_nom'] ?? 'Cadurix');
        $settings['app_description']          = trim($_POST['app_description'] ?? '');
        $settings['alerte_jours_defaut']      = max(1, (int)$_POST['alerte_jours_defaut']);
        $settings['max_tentatives_connexion'] = max(3, (int)$_POST['max_tentatives']);
        $settings['blocage_minutes']          = max(5, (int)$_POST['blocage_minutes']);
        $settings['audit_retention_jours']    = max(30, (int)$_POST['audit_retention']);
        $settings['backup_garder_nombre']     = max(1, (int)$_POST['backup_garder']);
        $settings['session_timeout_minutes']  = max(5, min(480, (int)($_POST['session_timeout'] ?? 30)));
        write_json($settings_file, $settings);
        audit_log($pdo, 'MODIF_SETTINGS', 'system');
        $message = "Paramètres enregistrés.";
        $sec = 'settings';
    }

    // --- Sauvegarde SQL ---
    if ($action === 'backup') {
        if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);
        $filename = 'cadurix_backup_' . date('Ymd_His') . '.sql';
        $filepath = $backup_dir . $filename;

        try {
            $sql = "-- Cadurix Backup " . date('Y-m-d H:i:s') . "\n";
            $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                // Structure
                $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                $sql .= "DROP TABLE IF EXISTS `$table`;\n";
                $sql .= array_values($create)[1] . ";\n\n";
                // Données
                $rows = $pdo->query("SELECT * FROM `$table` LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
                    $sql .= "INSERT INTO `$table` ($cols) VALUES\n";
                    $vals = [];
                    foreach ($rows as $row) {
                        $escaped = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), $row);
                        $vals[] = '(' . implode(', ', $escaped) . ')';
                    }
                    $sql .= implode(",\n", $vals) . ";\n\n";
                }
            }
            $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
            file_put_contents($filepath, $sql);

            // Garder seulement N derniers backups
            $backups = glob($backup_dir . '*.sql');
            usort($backups, fn($a,$b) => filemtime($b) - filemtime($a));
            $keep = $settings['backup_garder_nombre'] ?? 5;
            foreach (array_slice($backups, $keep) as $old) unlink($old);

            audit_log($pdo, 'BACKUP_SQL', 'system', null, null, ['fichier' => $filename]);
            // Téléchargement direct
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;
        } catch (Exception $e) {
            $erreur = "Erreur backup : " . $e->getMessage();
        }
    }

    // --- Déconnexion forcée d'un user ---
    if ($action === 'kick_session') {
        $sid = $_POST['session_id'] ?? '';
        if ($sid) {
            $pdo->prepare("DELETE FROM sessions_actives WHERE session_id = ?")->execute([$sid]);
            audit_log($pdo, 'KICK_SESSION', 'system', null, null, ['session' => substr($sid, 0, 16).'...']);
            $message = "Session terminée.";
        }
        $sec = 'sessions';
    }

    // --- Nettoyage ---
    if ($action === 'clean_audit') {
        $jours = max(30, (int)($_POST['jours'] ?? 90));
        $stmt = $pdo->prepare("DELETE FROM audit_log WHERE cree_le < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$jours]);
        $nb = $stmt->rowCount();
        audit_log($pdo, 'NETTOYAGE_AUDIT', 'system', null, null, ['supprime' => $nb, 'jours' => $jours]);
        $message = "$nb entrée(s) supprimée(s) du journal d'audit.";
        $sec = 'nettoyage';
    }
    if ($action === 'clean_attempts') {
        $stmt = $pdo->query("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $message = $stmt->rowCount() . " tentative(s) supprimée(s).";
        $sec = 'nettoyage';
    }
    if ($action === 'unblock_all') {
        $pdo->query("UPDATE utilisateurs SET tentatives_echec=0, bloque_jusqu=NULL WHERE bloque_jusqu IS NOT NULL");
        audit_log($pdo, 'DEBLOCAGE_GLOBAL', 'system');
        $message = "Tous les comptes bloqués ont été débloqués.";
        $sec = 'nettoyage';
    }
    if ($action === 'clean_sessions') {
        $pdo->query("DELETE FROM sessions_actives WHERE derniere_activite < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $message = "Sessions expirées supprimées.";
        $sec = 'sessions';
    }

    // --- Test email ---
    if ($action === 'test_mail') {
        $dest = trim($_POST['test_email'] ?? '');
        if (filter_var($dest, FILTER_VALIDATE_EMAIL)) {
            $html = "<div style='font-family:Arial,sans-serif;max-width:500px;padding:30px;background:#f9fafb;border-radius:12px;'>"
                  . "<h2 style='color:#EF4444'>💊 Cadurix — Test mail</h2>"
                  . "<p>Ce message confirme que la configuration email fonctionne correctement.</p>"
                  . "<p style='color:#6B7280;font-size:13px;'>Envoyé le " . date('d/m/Y à H:i') . "</p>"
                  . "</div>";
            require_once __DIR__ . '/../includes/mailer.php';
            if (function_exists('cadurix_send_mail')) {
                $ok = cadurix_send_mail($pdo, $dest, '', "✅ Cadurix — Test de configuration mail", $html, 'test');
            } else {
                $ok = mail($dest, "✅ Cadurix — Test de configuration mail", $html,
                    "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: Cadurix <" . ($settings['mail_from'] ?? 'noreply@cadurix.fr') . ">");
            }
            $message = $ok ? "Mail de test envoyé à $dest (via SMTP)." : "Échec de l'envoi. Vérifiez la config mail (Admin → Emails).";
        } else {
            $erreur = "Email invalide.";
        }
        $sec = 'settings';
    }
}

// ── Données ───────────────────────────────────────────────
// Infos système
$sys = [
    'php'       => PHP_VERSION,
    'mysql'     => $pdo->query("SELECT VERSION()")->fetchColumn(),
    'os'        => PHP_OS,
    'disk_free' => disk_free_space('/'),
    'disk_total'=> disk_total_space('/'),
    'memory'    => ini_get('memory_limit'),
    'upload'    => ini_get('upload_max_filesize'),
    'post'      => ini_get('post_max_size'),
];

// Taille base de données
$db_size = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();

// Statistiques
$stats = [
    'utilisateurs' => $pdo->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn(),
    'produits'     => $pdo->query("SELECT COUNT(*) FROM produits WHERE statut='actif'")->fetchColumn(),
    'emplacements' => $pdo->query("SELECT COUNT(*) FROM emplacements WHERE actif=1")->fetchColumn(),
    'sites'        => $pdo->query("SELECT COUNT(*) FROM sites WHERE actif=1")->fetchColumn(),
    'audit_logs'   => $pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn(),
    'messages'     => $pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn(),
    'alertes'      => $pdo->query("SELECT COUNT(*) FROM produits WHERE statut='actif' AND date_peremption < DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn(),
    'expires'      => $pdo->query("SELECT COUNT(*) FROM produits WHERE statut='actif' AND date_peremption < CURDATE()")->fetchColumn(),
];

// Sessions actives
try {
    $_is_sa_sys = (bool)($_SESSION['super_admin'] ?? false);
$_sys_site  = site_actif();
if ($_is_sa_sys) {
    $sessions = $pdo->query("
        SELECT sa.*, CONCAT(u.prenom,' ',u.nom) AS user_nom, u.login,
               s.nom AS site_nom
        FROM sessions_actives sa
        JOIN utilisateurs u ON u.id = sa.user_id
        LEFT JOIN sites s ON s.id = sa.site_actif_id
        WHERE sa.derniere_activite > DATE_SUB(NOW(), INTERVAL 2 HOUR)
        ORDER BY sa.derniere_activite DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt_sess = $pdo->prepare("
        SELECT sa.*, CONCAT(u.prenom,' ',u.nom) AS user_nom, u.login,
               s.nom AS site_nom
        FROM sessions_actives sa
        JOIN utilisateurs u ON u.id = sa.user_id
        LEFT JOIN sites s ON s.id = sa.site_actif_id
        JOIN user_site_permissions usp ON usp.user_id = u.id
        WHERE sa.derniere_activite > DATE_SUB(NOW(), INTERVAL 2 HOUR)
          AND usp.site_id = ?
        ORDER BY sa.derniere_activite DESC
    ");
    $stmt_sess->execute([$_sys_site]);
    $sessions = $stmt_sess->fetchAll(PDO::FETCH_ASSOC);
}
} catch (Exception $e) { $sessions = []; }

// Comptes bloqués
$bloques = $pdo->query("SELECT id, CONCAT(prenom,' ',nom) AS nom, login, tentatives_echec, bloque_jusqu FROM utilisateurs WHERE bloque_jusqu > NOW()")->fetchAll(PDO::FETCH_ASSOC);

// Backups existants
$backups = [];
if (is_dir($backup_dir)) {
    $files = glob($backup_dir . '*.sql');
    usort($files, fn($a,$b) => filemtime($b) - filemtime($a));
    foreach ($files as $f) {
        $backups[] = ['nom' => basename($f), 'taille' => filesize($f), 'date' => filemtime($f)];
    }
}

// Dernières erreurs PHP
$php_errors = [];
if ($log_file && file_exists($log_file) && is_readable($log_file)) {
    $lines = array_reverse(file($log_file));
    $php_errors = array_slice($lines, 0, 20);
}

$ip_courante = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

if (!defined('ADMIN_HUB')) require_once '../includes/header.php';
?>

<style>
/* sys-tabs gérés par le hub */
.sys-section { display:none; }
.sys-section.active { display:block; }

.metric-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:1.5rem; }
@media(max-width:900px) { .metric-grid { grid-template-columns:1fr 1fr; } }
.metric-card {
  position:relative; display:flex; align-items:center; gap:12px;
  background:var(--bg2); border:1px solid var(--border); border-radius:13px; padding:12px 15px;
  overflow:hidden; box-shadow:0 1px 2px rgba(20,30,60,.04); transition:transform .15s;
}
.metric-card::before { content:""; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--mc,var(--primary)); }
.metric-card:hover { transform:translateY(-2px); }
.metric-card .ic { width:36px; height:36px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:1.05rem; }
.metric-val { font-size:1.5rem; font-weight:800; letter-spacing:-.5px; line-height:1; font-variant-numeric:tabular-nums; }
.metric-lbl { font-size:.68rem; color:var(--text3); text-transform:uppercase; letter-spacing:.04em; font-weight:700; margin-top:3px; }
.metric-sub { font-size:.68rem; color:var(--text3); margin-top:1px; }

.sys-info-row { display:flex; align-items:center; justify-content:space-between; padding:.625rem 0; border-bottom:1px solid rgba(255,255,255,.04); font-size:.875rem; }
.sys-info-row:last-child { border-bottom:none; }
.sys-info-lbl { color:var(--text2); }
.sys-info-val { font-weight:600; font-family:var(--mono); font-size:.8rem; }

.status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; margin-right:6px; }
.status-on   { background:#10b981; box-shadow:0 0 6px #10b981; animation:pulse-g 2s infinite; }
.status-warn { background:#ef4444; box-shadow:0 0 6px #ef4444; animation:pulse-g 2s infinite; }
.status-off  { background:#6b7280; }
@keyframes pulse-g { 0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.5)} 50%{box-shadow:0 0 0 4px rgba(16,185,129,0)} }

.toggle-big { display:flex; align-items:center; gap:16px; padding:20px; background:var(--glass); border:1px solid var(--border); border-radius:12px; margin-bottom:1.25rem; }
.toggle-big-slider { width:52px; height:28px; background:var(--border2); border-radius:14px; cursor:pointer; position:relative; transition:background .2s; flex-shrink:0; }
.toggle-big-slider.on { background:#10b981; }
.toggle-big-slider::after { content:''; position:absolute; width:20px; height:20px; background:white; border-radius:50%; top:4px; left:4px; transition:transform .2s; }
.toggle-big-slider.on::after { transform:translateX(24px); }

.session-card { display:flex; align-items:center; justify-content:space-between; padding:.875rem 1rem; background:var(--glass); border:1px solid var(--border); border-radius:.5rem; margin-bottom:.5rem; }
.progress-disk { height:6px; background:var(--border2); border-radius:99px; overflow:hidden; margin-top:6px; }
.progress-disk-fill { height:100%; background:linear-gradient(90deg,#10b981,#3b82f6); border-radius:99px; }

.backup-item { display:flex; align-items:center; justify-content:space-between; padding:.75rem 1rem; background:var(--glass); border:1px solid var(--border); border-radius:.5rem; margin-bottom:.5rem; font-size:.85rem; }
.log-line { font-family:var(--mono); font-size:.72rem; color:#f87171; background:rgba(239,68,68,.06); padding:4px 8px; border-radius:4px; margin-bottom:2px; white-space:pre-wrap; word-break:break-all; }

.danger-zone { border:1px solid rgba(239,68,68,.2); border-radius:12px; padding:1.25rem; background:rgba(239,68,68,.04); }
.danger-title { font-size:.75rem; text-transform:uppercase; letter-spacing:1px; color:var(--red); font-weight:700; margin-bottom:1rem; }
</style>

<!-- Statut maintenance compact -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px">
  <div style="font-size:.9rem;font-weight:700;color:var(--text)">⚙️ Système</div>
  <?php
  $is_maint    = $maint['actif'] ?? false;
  $is_lockdown = $lockdown > 0;
  $dot_class   = $is_maint ? 'status-warn' : ($is_lockdown ? 'status-warn' : 'status-on');
  $border_col  = $is_maint ? 'rgba(239,68,68,.3)' : ($is_lockdown ? 'rgba(245,158,11,.3)' : 'rgba(16,185,129,.3)');
  $text_col    = $is_maint ? '#f87171' : ($is_lockdown ? '#fbbf24' : '#34d399');
  $label       = $is_maint ? 'Maintenance ON' : ($is_lockdown ? '🔒 Sécurité renforcée' : 'Système opérationnel');
  ?>
  <div style="display:flex;align-items:center;gap:8px;padding:6px 14px;background:var(--glass);border:1px solid <?= $border_col ?>;border-radius:99px">
    <span class="status-dot <?= $dot_class ?>"></span>
    <span style="font-size:.78rem;font-weight:600;color:<?= $text_col ?>"><?= $label ?></span>
  </div>
</div>

<?php if ($message): ?><div class="flash flash-success">✓ <?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur):  ?><div class="flash flash-error">✗ <?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<!-- Métriques rapides -->
<?php $mAl = $stats['expires']>0 ? '#ef4444' : ($stats['alertes']>0 ? '#f59e0b' : '#10b981'); ?>
<div class="metric-grid">
  <div class="metric-card" style="--mc:#3b82f6">
    <span class="ic" style="background:#3b82f61f;color:#3b82f6">👥</span>
    <div><div class="metric-val" style="color:#3b82f6"><?= $stats['utilisateurs'] ?></div><div class="metric-lbl">Utilisateurs</div><div class="metric-sub"><?= count($sessions) ?> connecté(s)</div></div>
  </div>
  <div class="metric-card" style="--mc:<?= $mAl ?>">
    <span class="ic" style="background:<?= $mAl ?>1f;color:<?= $mAl ?>">⚠️</span>
    <div><div class="metric-val" style="color:<?= $mAl ?>"><?= $stats['alertes'] ?></div><div class="metric-lbl">Alertes actives</div><div class="metric-sub"><?= $stats['expires'] ?> périmé(s)</div></div>
  </div>
  <div class="metric-card" style="--mc:#8b5cf6">
    <span class="ic" style="background:#8b5cf61f;color:#8b5cf6">🗄️</span>
    <div><div class="metric-val" style="color:#8b5cf6"><?= $db_size ?> Mo</div><div class="metric-lbl">Base de données</div><div class="metric-sub"><?= $stats['audit_logs'] ?> logs audit</div></div>
  </div>
  <div class="metric-card" style="--mc:#10b981">
    <span class="ic" style="background:#10b9811f;color:#10b981">💊</span>
    <div><div class="metric-val" style="color:#10b981"><?= $stats['produits'] ?></div><div class="metric-lbl">Produits actifs</div><div class="metric-sub"><?= $stats['emplacements'] ?> emplacements</div></div>
  </div>
</div>

<style>
.sys-subnav{display:flex;gap:6px;flex-wrap:wrap;margin:4px 0 22px}
.sys-subtab{padding:8px 14px;border-radius:10px;font-size:13px;font-weight:600;color:var(--text2);text-decoration:none;background:var(--glass);border:1px solid var(--border);white-space:nowrap}
.sys-subtab:hover{color:var(--text)}
.sys-subtab.on{background:var(--primary);color:#fff;border-color:var(--primary)}
</style>
<div class="sys-subnav">
  <a href="?tab=dashboard&sec=infos"       class="sys-subtab <?= $sec==='infos'?'on':'' ?>">🖥️ Infos serveur</a>
  <a href="?tab=dashboard&sec=maintenance" class="sys-subtab <?= $sec==='maintenance'?'on':'' ?>">🔧 Maintenance</a>
  <a href="?tab=dashboard&sec=backup"      class="sys-subtab <?= $sec==='backup'?'on':'' ?>">💾 Sauvegardes</a>
  <a href="?tab=dashboard&sec=sessions"    class="sys-subtab <?= $sec==='sessions'?'on':'' ?>">👥 Sessions</a>
  <a href="?tab=dashboard&sec=nettoyage"   class="sys-subtab <?= $sec==='nettoyage'?'on':'' ?>">🧹 Nettoyage</a>
  <a href="?tab=dashboard&sec=settings"    class="sys-subtab <?= $sec==='settings'?'on':'' ?>">⚙️ Paramètres</a>
</div>



<!-- ═══════════════════════════════════════════════════════
     DASHBOARD SYSTÈME
═══════════════════════════════════════════════════════ -->
<div class="sys-section <?= $sec==='infos'?'active':'' ?>">
  <div class="grid-2">
    <div class="card">
      <div class="card-title">🖥️ Environnement serveur</div>
      <div class="sys-info-row"><span class="sys-info-lbl">PHP</span><span class="sys-info-val"><?= $sys['php'] ?></span></div>
      <div class="sys-info-row"><span class="sys-info-lbl">MySQL / MariaDB</span><span class="sys-info-val"><?= $sys['mysql'] ?></span></div>
      <div class="sys-info-row"><span class="sys-info-lbl">Système</span><span class="sys-info-val"><?= $sys['os'] ?></span></div>
      <div class="sys-info-row"><span class="sys-info-lbl">Mémoire PHP</span><span class="sys-info-val"><?= $sys['memory'] ?></span></div>
      <div class="sys-info-row"><span class="sys-info-lbl">Upload max</span><span class="sys-info-val"><?= $sys['upload'] ?></span></div>
      <div class="sys-info-row"><span class="sys-info-lbl">POST max</span><span class="sys-info-val"><?= $sys['post'] ?></span></div>
      <?php if ($sys['disk_total']): ?>
      <div class="sys-info-row">
        <span class="sys-info-lbl">Disque</span>
        <span class="sys-info-val"><?= round($sys['disk_free']/1073741824,1) ?> Go libres / <?= round($sys['disk_total']/1073741824,1) ?> Go</span>
      </div>
      <div class="progress-disk">
        <div class="progress-disk-fill" style="width:<?= round((1-$sys['disk_free']/$sys['disk_total'])*100) ?>%"></div>
      </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-title">📦 Base de données</div>
      <div class="sys-info-row"><span class="sys-info-lbl">Taille totale</span><span class="sys-info-val"><?= $db_size ?> Mo</span></div>
      <div class="sys-info-row"><span class="sys-info-lbl">Utilisateurs</span><span class="sys-info-val"><?= $stats['utilisateurs'] ?></span></div>
      <div class="sys-info-row"><span class="sys-info-lbl">Sites actifs</span><span class="sys-info-val"><?= $stats['sites'] ?></span></div>
      <div class="sys-info-row"><span class="sys-info-lbl">Emplacements</span><span class="sys-info-val"><?= $stats['emplacements'] ?></span></div>
      <div class="sys-info-row"><span class="sys-info-lbl">Produits actifs</span><span class="sys-info-val"><?= $stats['produits'] ?></span></div>
      <div class="sys-info-row"><span class="sys-info-lbl">Messages</span><span class="sys-info-val"><?= $stats['messages'] ?></span></div>
      <div class="sys-info-row"><span class="sys-info-lbl">Entrées audit</span><span class="sys-info-val"><?= number_format($stats['audit_logs']) ?></span></div>
      <div style="margin-top:1rem">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="backup">
          <button class="btn btn-primary btn-sm">💾 Sauvegarde maintenant</button>
        </form>
      </div>
    </div>
  </div>

  <?php if (!empty($bloques)): ?>
  <div class="card" style="margin-top:1rem">
    <div class="card-title" style="color:var(--red)">🔒 Comptes bloqués (<?= count($bloques) ?>)</div>
    <?php foreach ($bloques as $b): ?>
    <div class="sys-info-row">
      <span><?= htmlspecialchars($b['nom']) ?> <span style="color:var(--text3);font-size:.8rem">(<?= htmlspecialchars($b['login']) ?>)</span></span>
      <span style="color:var(--red);font-size:.8rem">Bloqué jusqu'au <?= date('d/m H:i', strtotime($b['bloque_jusqu'])) ?> · <?= $b['tentatives_echec'] ?> tentatives</span>
    </div>
    <?php endforeach; ?>
    <div style="margin-top:1rem">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="unblock_all">
        <button class="btn btn-ghost btn-sm">🔓 Débloquer tous les comptes</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     MAINTENANCE
═══════════════════════════════════════════════════════ -->
<div class="sys-section <?= $sec==='maintenance'?'active':'' ?>">
  <div class="grid-2">
    <div class="card">
      <div class="card-title">🔧 Mode maintenance</div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_maintenance">

        <!-- Toggle visuel -->
        <div class="toggle-big">
          <div class="toggle-big-slider <?= ($maint['actif']??false)?'on':'' ?>" id="maint-slider" onclick="toggleMaint()"></div>
          <input type="checkbox" name="actif" id="maint-cb" <?= ($maint['actif']??false)?'checked':'' ?> style="display:none">
          <div>
            <div style="font-weight:700;font-size:.95rem">
              <?= ($maint['actif']??false) ? '🔴 Maintenance ACTIVE' : '🟢 Site opérationnel' ?>
            </div>
            <div style="font-size:.8rem;color:var(--text2);margin-top:2px">
              <?= ($maint['actif']??false) ? 'Les visiteurs voient la page de maintenance' : 'Tout le monde peut accéder au site' ?>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Message affiché aux visiteurs</label>
          <textarea name="message" class="form-control" rows="3"><?= htmlspecialchars($maint['message'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Heure de retour estimée</label>
          <input type="text" name="retour_estime" class="form-control"
                 value="<?= htmlspecialchars($maint['retour_estime'] ?? '') ?>"
                 placeholder="Ex: Aujourd'hui à 18h00">
        </div>
        <div class="form-group">
          <label class="form-label">IPs en bypass (une par ligne — ces IPs voient le site normalement)</label>
          <textarea name="ips_bypass" class="form-control" rows="4" placeholder="192.168.1.1&#10;82.x.x.x"><?= htmlspecialchars(implode("\n", $maint['ips_bypass'] ?? [])) ?></textarea>
          <div class="form-hint">Votre IP actuelle : <strong style="color:var(--primary)"><?= htmlspecialchars($ip_courante) ?></strong>
            <button type="button" class="btn btn-ghost btn-xs" style="margin-left:8px"
              onclick="document.querySelector('[name=ips_bypass]').value += '\n<?= htmlspecialchars($ip_courante) ?>'">
              + Ajouter mon IP
            </button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </form>
    </div>

    <div class="card">
      <div class="card-title">📋 Historique des maintenances</div>
      <?php if (empty($maint['historique'])): ?>
        <p style="color:var(--text3);font-size:.875rem">Aucune maintenance enregistrée.</p>
      <?php else: ?>
        <?php foreach (array_reverse($maint['historique'] ?? []) as $h): ?>
        <div style="padding:.75rem;background:var(--glass);border:1px solid var(--border);border-radius:.5rem;margin-bottom:.5rem;font-size:.8rem">
          <div style="font-weight:600">🔧 <?= date('d/m/Y à H:i', strtotime($h['debut'])) ?></div>
          <?php if (!empty($h['fin'])): ?>
          <div style="color:var(--text2)">✓ Fin : <?= date('d/m/Y à H:i', strtotime($h['fin'])) ?></div>
          <?php else: ?>
          <div style="color:var(--red)">⚡ En cours</div>
          <?php endif; ?>
          <div style="color:var(--text3);margin-top:4px"><?= htmlspecialchars($h['message'] ?? '') ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div style="margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid var(--border)">
        <div style="font-size:.8rem;color:var(--text2)">
          <div>📄 Page maintenance : <a href="/maintenance.php" target="_blank" style="color:var(--primary)">Aperçu →</a></div>
          <div style="margin-top:6px">✅ Votre IP (<strong><?= htmlspecialchars($ip_courante) ?></strong>) a toujours accès en tant que Super Admin.</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     SAUVEGARDES
═══════════════════════════════════════════════════════ -->
<div class="sys-section <?= $sec==='backup'?'active':'' ?>">
  <div class="grid-2">
    <div class="card">
      <div class="card-title">💾 Créer une sauvegarde</div>
      <p style="font-size:.875rem;color:var(--text2);margin-bottom:1.25rem">
        Génère un fichier SQL complet de toute la base de données. Le fichier sera téléchargé immédiatement et stocké dans <code style="color:var(--primary)">/backups/</code>.
      </p>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="backup">
        <button class="btn btn-primary">⬇️ Lancer la sauvegarde</button>
      </form>
      <div style="margin-top:1.25rem;padding:1rem;background:var(--glass);border-radius:.5rem;font-size:.8rem;color:var(--text2)">
        <div>📦 Backups conservés : <strong><?= $settings['backup_garder_nombre'] ?? 5 ?></strong> derniers</div>
        <div style="margin-top:4px">📁 Dossier : <code style="color:var(--primary)">/backups/</code></div>
        <div style="margin-top:4px">💡 Les plus anciens sont supprimés automatiquement</div>
      </div>
    </div>

    <div class="card">
      <div class="card-title">📁 Sauvegardes existantes (<?= count($backups) ?>)</div>
      <?php if (empty($backups)): ?>
        <p style="color:var(--text3);font-size:.875rem">Aucune sauvegarde trouvée dans <code>/backups/</code>.</p>
      <?php else: ?>
        <?php foreach ($backups as $b): ?>
        <div class="backup-item">
          <div>
            <div style="font-weight:600;font-size:.8rem"><?= htmlspecialchars($b['nom']) ?></div>
            <div style="color:var(--text3);font-size:.75rem"><?= date('d/m/Y à H:i', $b['date']) ?> · <?= round($b['taille']/1024) ?> Ko</div>
          </div>
          <a href="../backups/<?= urlencode($b['nom']) ?>" download class="btn btn-ghost btn-xs">⬇️</a>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     SESSIONS
═══════════════════════════════════════════════════════ -->
<div class="sys-section <?= $sec==='sessions'?'active':'' ?>">
  <div class="card" style="margin-bottom:1rem">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
      <div class="card-title" style="margin-bottom:0">👥 Sessions actives (<?= count($sessions) ?>)</div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="clean_sessions">
        <button class="btn btn-ghost btn-sm">🧹 Purger les sessions expirées</button>
      </form>
    </div>
    <?php if (empty($sessions)): ?>
      <p style="color:var(--text3);font-size:.875rem;text-align:center;padding:2rem">Aucune session active.</p>
    <?php else: ?>
      <?php foreach ($sessions as $s): ?>
      <div class="session-card">
        <div style="display:flex;align-items:center;gap:12px">
          <div style="width:36px;height:36px;border-radius:50%;background:rgba(59,130,246,.2);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem">
            <?= strtoupper(substr($s['user_nom'],0,1)) ?>
          </div>
          <div>
            <div style="font-weight:600;font-size:.875rem"><?= htmlspecialchars($s['user_nom']) ?> <span style="color:var(--text3);font-weight:400">(<?= htmlspecialchars($s['login']) ?>)</span></div>
            <div style="font-size:.75rem;color:var(--text2)">
              🌐 <?= htmlspecialchars($s['ip'] ?? '—') ?>
              <?php if ($s['site_nom']): ?> · 🏥 <?= htmlspecialchars($s['site_nom']) ?><?php endif; ?>
            </div>
            <div style="font-size:.72rem;color:var(--text3)">
              Dernière activité : <?= date('d/m/Y à H:i', strtotime($s['derniere_activite'])) ?>
            </div>
          </div>
        </div>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="kick_session">
          <input type="hidden" name="session_id" value="<?= htmlspecialchars($s['session_id']) ?>">
          <button class="btn btn-danger btn-sm" onclick="return confirm('Forcer la déconnexion de <?= htmlspecialchars($s['user_nom'], ENT_QUOTES) ?> ?')">⏻ Déconnecter</button>
        </form>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     NETTOYAGE
═══════════════════════════════════════════════════════ -->
<div class="sys-section <?= $sec==='nettoyage'?'active':'' ?>">
  <div class="grid-2">
    <div style="display:flex;flex-direction:column;gap:1rem">

      <div class="card">
        <div class="card-title">📋 Journal d'audit</div>
        <p style="font-size:.875rem;color:var(--text2);margin-bottom:1rem">Supprimer les entrées plus anciennes que X jours.</p>
        <form method="POST" style="display:flex;gap:10px;align-items:flex-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="clean_audit">
          <div class="form-group" style="margin-bottom:0;flex:1">
            <label class="form-label">Supprimer les entrées de plus de</label>
            <div style="display:flex;gap:8px;align-items:center">
              <input type="number" name="jours" value="90" min="30" max="365" class="form-control" style="width:100px">
              <span style="color:var(--text2);font-size:.875rem">jours</span>
            </div>
          </div>
          <button class="btn btn-danger btn-sm" onclick="return confirm('Supprimer ces entrées ?')">🗑️ Nettoyer</button>
        </form>
        <div style="margin-top:.75rem;font-size:.8rem;color:var(--text3)">Actuellement : <?= number_format($stats['audit_logs']) ?> entrées</div>
      </div>

      <div class="card">
        <div class="card-title">🔐 Tentatives de connexion</div>
        <p style="font-size:.875rem;color:var(--text2);margin-bottom:1rem">Supprimer les tentatives de plus de 30 jours.</p>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="clean_attempts">
          <button class="btn btn-danger btn-sm" onclick="return confirm('Nettoyer les tentatives de connexion ?')">🗑️ Nettoyer</button>
        </form>
      </div>

    </div>

    <div style="display:flex;flex-direction:column;gap:1rem">

      <div class="card">
        <div class="card-title">👥 Sessions expirées</div>
        <p style="font-size:.875rem;color:var(--text2);margin-bottom:1rem">Supprimer les sessions inactives depuis plus de 24h.</p>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="clean_sessions">
          <button class="btn btn-ghost btn-sm">🧹 Purger</button>
        </form>
      </div>

      <div class="danger-zone">
        <div class="danger-title">⚠️ Zone dangereuse</div>
        <form method="POST" style="margin-bottom:.75rem">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="unblock_all">
          <div style="font-size:.875rem;margin-bottom:.5rem;font-weight:600">🔓 Débloquer tous les comptes</div>
          <div style="font-size:.8rem;color:var(--text2);margin-bottom:.75rem">Réinitialise les compteurs de tentatives et lève tous les blocages.</div>
          <button class="btn btn-ghost btn-sm">Débloquer tout</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     PARAMÈTRES
═══════════════════════════════════════════════════════ -->
<div class="sys-section <?= $sec==='settings'?'active':'' ?>">
  <div class="grid-2">
    <div class="card">
      <div class="card-title">⚙️ Paramètres généraux</div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">
        <div class="form-group">
          <label class="form-label">Nom de l'application</label>
          <input type="text" name="app_nom" class="form-control" value="<?= htmlspecialchars($settings['app_nom'] ?? 'Cadurix') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <input type="text" name="app_description" class="form-control" value="<?= htmlspecialchars($settings['app_description'] ?? '') ?>">
        </div>
        <div class="form-group" style="background:var(--glass);border:1px solid var(--border);border-radius:10px;padding:12px 14px">
          <div style="font-size:.82rem;color:var(--text2)">📧 La configuration email (expéditeur, SMTP…) se gère dans l'onglet <a href="?tab=alerts" style="color:var(--primary);font-weight:600">Emails →</a></div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Alerte jours par défaut</label>
            <input type="number" name="alerte_jours_defaut" class="form-control" value="<?= $settings['alerte_jours_defaut'] ?? 30 ?>" min="1" max="365">
          </div>
          <div class="form-group">
            <label class="form-label">Backups à conserver</label>
            <input type="number" name="backup_garder" class="form-control" value="<?= $settings['backup_garder_nombre'] ?? 5 ?>" min="1" max="20">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Max tentatives connexion</label>
            <input type="number" name="max_tentatives" class="form-control" value="<?= $settings['max_tentatives_connexion'] ?? 5 ?>" min="3" max="20">
          </div>
          <div class="form-group">
            <label class="form-label">Blocage (minutes)</label>
            <input type="number" name="blocage_minutes" class="form-control" value="<?= $settings['blocage_minutes'] ?? 15 ?>" min="5" max="1440">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Rétention audit (jours)</label>
          <input type="number" name="audit_retention" class="form-control" value="<?= $settings['audit_retention_jours'] ?? 365 ?>" min="30">
        </div>
        <div class="form-group">
          <label class="form-label">⏱️ Déconnexion automatique (minutes d'inactivité)</label>
          <input type="number" name="session_timeout" class="form-control"
                 value="<?= $settings['session_timeout_minutes'] ?? 30 ?>" min="5" max="480">
          <small style="color:var(--text3);font-size:.75rem">
            Entre 5 et 480 min. Défaut : 30 min. La page scanner est exemptée.
          </small>
        </div>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </form>
    </div>

    <div class="card">
      <div class="card-title">📧 Test email</div>
      <p style="font-size:.875rem;color:var(--text2);margin-bottom:1.25rem">Envoyer un email de test pour vérifier la configuration mail du serveur.</p>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="test_mail">
        <div class="form-group">
          <label class="form-label">Envoyer à</label>
          <input type="email" name="test_email" class="form-control"
                 placeholder="votre@email.com"
                 value="<?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>">
        </div>
        <button type="submit" class="btn btn-primary">📧 Envoyer le test</button>
      </form>

      <div style="margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid var(--border)">
        <div class="card-title">🔑 Infos session</div>
        <div class="sys-info-row"><span class="sys-info-lbl">User ID</span><span class="sys-info-val"><?= $_SESSION['user_id'] ?? '—' ?></span></div>
        <div class="sys-info-row"><span class="sys-info-lbl">Login</span><span class="sys-info-val"><?= htmlspecialchars($_SESSION['user_login'] ?? '—') ?></span></div>
        <div class="sys-info-row"><span class="sys-info-lbl">Super Admin</span><span class="sys-info-val"><?= ($_SESSION['super_admin']??false)?'✅ Oui':'Non' ?></span></div>
        <div class="sys-info-row"><span class="sys-info-lbl">IP</span><span class="sys-info-val"><?= htmlspecialchars($ip_courante) ?></span></div>
        <div class="sys-info-row"><span class="sys-info-lbl">Site actif</span><span class="sys-info-val"><?= site_actif() ?: '—' ?></span></div>
      </div>
    </div>
  </div>

</div>

<!-- ═══════════════════════════════════════════════════════
     LOGS PHP
═══════════════════════════════════════════════════════ -->
<div class="sys-section <?= $sec==='logs'?'active':'' ?>">
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
      <div class="card-title" style="margin-bottom:0">📄 Logs d'erreurs PHP</div>
      <div style="font-size:.8rem;color:var(--text3)"><?= $log_file ? htmlspecialchars($log_file) : 'Fichier log non configuré' ?></div>
    </div>
    <?php if (empty($php_errors)): ?>
      <div style="text-align:center;padding:2rem;color:var(--text3)">
        <?= $log_file ? '✅ Aucune erreur récente' : '⚠️ Fichier de log PHP non accessible (normal sur Laragon)' ?>
      </div>
    <?php else: ?>
      <div style="max-height:400px;overflow-y:auto">
        <?php foreach ($php_errors as $line): ?>
          <?php if (trim($line)): ?>
          <div class="log-line"><?= htmlspecialchars(trim($line)) ?></div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
function toggleMaint() {
  const cb  = document.getElementById('maint-cb');
  const sl  = document.getElementById('maint-slider');
  cb.checked = !cb.checked;
  sl.classList.toggle('on', cb.checked);
}
</script>

<?php if (!defined('ADMIN_HUB')) require_once '../includes/footer.php'; ?>
