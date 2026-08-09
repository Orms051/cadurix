<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.
/**
 * Cadurix — Sauvegarde automatique (cron quotidien)
 *
 * Usage cron o2switch :
 *   0 2 * * * php /home/[user]/[domain]/backup_auto.php >> /dev/null 2>&1
 *
 * Ou via URL sécurisée (token) :
 *   https://cadurix.fr/backup_auto.php?token=VOTRE_CRON_TOKEN
 *
 * Ce script :
 *   1. Sauvegarde COMPLÈTE de la BDD (toutes tables)
 *   2. Sauvegarde PAR SITE (un fichier SQL par site)
 *   3. Envoie un email de confirmation au super admin
 *      → Fichier joint si < 5 Mo, sinon résumé uniquement
 *   4. Rotation : garde les 30 derniers backups complets
 *                 garde les 7 derniers backups par site
 */

// ── Accès CLI ou token web ───────────────────────────────────
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/config/db.php';
    $token   = $_GET['token'] ?? '';
    $stored  = $pdo->query("SELECT valeur FROM parametres WHERE cle='cron_token'")->fetchColumn();
    if (!$stored || !hash_equals($stored, $token)) {
        http_response_code(403);
        die('Accès refusé');
    }
} else {
    require_once __DIR__ . '/config/db.php';
}

$backup_dir = __DIR__ . '/backups/';
$max_size_attach = 5 * 1024 * 1024; // 5 Mo max pour pièce jointe

if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);

$start_time = microtime(true);
$results    = [];
$errors     = [];

// ── Fonction génération SQL d'une table ──────────────────────
function dump_full_table(PDO $pdo, string $table): string {
    try {
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return "-- Table $table ignorée : " . $e->getMessage() . "\n\n";
    }
    if (empty($rows)) return "-- $table : vide\n\n";

    $sql = "-- Table : $table (" . count($rows) . " lignes)\n";
    foreach ($rows as $row) {
        $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
        $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($row)));
        $sql .= "INSERT INTO `$table` ($cols) VALUES ($vals);\n";
    }
    return $sql . "\n";
}

// ── 1. SAUVEGARDE COMPLÈTE ───────────────────────────────────
echo "[" . date('H:i:s') . "] Démarrage sauvegarde complète...\n";

$tables_all = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$full_file  = $backup_dir . 'cadurix_full_' . date('Y-m-d_His') . '.sql';

$sql_full  = "-- ============================================================\n";
$sql_full .= "-- Cadurix v5.4 — Sauvegarde complète\n";
$sql_full .= "-- Date : " . date('d/m/Y H:i:s') . "\n";
$sql_full .= "-- Tables : " . count($tables_all) . "\n";
$sql_full .= "-- CONFIDENTIEL — Usage interne uniquement\n";
$sql_full .= "-- ============================================================\n\n";
$sql_full .= "SET NAMES utf8mb4;\n";
$sql_full .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

// Tables sensibles à exclure (données de session, logs techniques)
$exclude = ['sessions_actives', 'login_attempts', 'security_log', 'api_logs'];

foreach ($tables_all as $table) {
    if (in_array($table, $exclude)) continue;
    $sql_full .= dump_full_table($pdo, $table);
}

$sql_full .= "SET FOREIGN_KEY_CHECKS = 1;\n";
$sql_full .= "-- Fin sauvegarde complète\n";

file_put_contents($full_file, $sql_full);
$full_size = filesize($full_file);
$results['full'] = [
    'file'  => basename($full_file),
    'size'  => $full_size,
    'lines' => substr_count($sql_full, "\n"),
];
echo "[" . date('H:i:s') . "] ✅ Sauvegarde complète : " . round($full_size/1024) . " Ko\n";

// Rotation : garder les 30 derniers backups complets
$full_backups = glob($backup_dir . 'cadurix_full_*.sql');
usort($full_backups, fn($a,$b) => filemtime($b) - filemtime($a));
foreach (array_slice($full_backups, 30) as $old) {
    unlink($old);
    echo "[" . date('H:i:s') . "] 🗑️ Ancien backup supprimé : " . basename($old) . "\n";
}

// ── 2. SAUVEGARDE PAR SITE ───────────────────────────────────
echo "\n[" . date('H:i:s') . "] Démarrage sauvegardes par site...\n";

$sites = $pdo->query("
    SELECT s.id, s.nom, o.nom AS org_nom
    FROM sites s
    JOIN organisations o ON o.id = s.organisation_id
    WHERE s.actif = 1
    ORDER BY o.nom, s.nom
")->fetchAll(PDO::FETCH_ASSOC);

$site_files = [];

foreach ($sites as $site) {
    $sid      = $site['id'];
    $site_nom = preg_replace('/[^a-z0-9]/', '_', strtolower($site['nom']));
    $org_nom  = preg_replace('/[^a-z0-9]/', '_', strtolower($site['org_nom']));
    $site_file = $backup_dir . "site_{$org_nom}_{$site_nom}_" . date('Y-m-d') . '.sql';

    $sql_site  = "-- Cadurix — Sauvegarde site : {$site['nom']} ({$site['org_nom']})\n";
    $sql_site .= "-- Date : " . date('d/m/Y H:i:s') . "\n\n";
    $sql_site .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    // Tables filtrées par site_id
    $tables_site = [
        'emplacements'          => 'site_id',
        'alertes'               => 'site_id',
        'rempotages'            => 'site_id',
        'interventions_materiel'=> 'site_id',
        'fiches_remplies'       => 'site_id',
        'materiels'             => 'site_id',
    ];

    // Tables SSE et dotations (si existent)
    foreach (['sse_equipements','sse_consommables','sse_categories','dotations_reglementaires'] as $t) {
        try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $tables_site[$t] = 'site_id'; }
        catch(Exception $e) {}
    }

    foreach ($tables_site as $table => $col) {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE $col = ?");
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) { $sql_site .= "-- $table : vide\n\n"; continue; }
        $sql_site .= "-- $table : " . count($rows) . " lignes\n";
        foreach ($rows as $row) {
            $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
            $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($row)));
            $sql_site .= "INSERT INTO `$table` ($cols) VALUES ($vals);\n";
        }
        $sql_site .= "\n";
    }

    // Produits (via emplacements)
    $empl_ids = $pdo->prepare("SELECT id FROM emplacements WHERE site_id=?");
    $empl_ids->execute([$sid]);
    $empl_list = $empl_ids->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($empl_list)) {
        $in   = implode(',', array_fill(0, count($empl_list), '?'));
        $stmt = $pdo->prepare("SELECT * FROM produits WHERE emplacement_id IN ($in)");
        $stmt->execute($empl_list);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $sql_site .= "-- produits : " . count($rows) . " lignes\n";
        foreach ($rows as $row) {
            $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
            $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($row)));
            $sql_site .= "INSERT INTO `produits` ($cols) VALUES ($vals);\n";
        }
        $sql_site .= "\n";
    }

    $sql_site .= "SET FOREIGN_KEY_CHECKS = 1;\n-- Fin sauvegarde site\n";

    file_put_contents($site_file, $sql_site);
    $site_size = filesize($site_file);
    $site_files[] = ['site' => $site['nom'], 'org' => $site['org_nom'], 'file' => $site_file, 'size' => $site_size];
    $results['sites'][] = ['nom' => $site['nom'], 'org' => $site['org_nom'], 'size' => $site_size];
    echo "[" . date('H:i:s') . "] ✅ {$site['nom']} ({$site['org_nom']}) : " . round($site_size/1024) . " Ko\n";

    // Rotation : garder les 7 derniers par site
    $old_site_backups = glob($backup_dir . "site_{$org_nom}_{$site_nom}_*.sql");
    usort($old_site_backups, fn($a,$b) => filemtime($b) - filemtime($a));
    foreach (array_slice($old_site_backups, 7) as $old) {
        unlink($old);
    }
}

// ── 3. EMAIL DE CONFIRMATION ─────────────────────────────────
echo "\n[" . date('H:i:s') . "] Envoi email de confirmation...\n";

// Récupérer l'email du super admin
$super_email = $pdo->query("SELECT email FROM utilisateurs WHERE super_admin=1 AND actif=1 AND email IS NOT NULL LIMIT 1")->fetchColumn();
$app_email   = $pdo->query("SELECT valeur FROM parametres WHERE cle='mail_from'")->fetchColumn() ?: 'noreply@cadurix.fr';

if ($super_email) {
    $duration = round(microtime(true) - $start_time, 2);
    $date_label = date('d/m/Y à H:i');

    // Tableau des sauvegardes par site
    $sites_html = '';
    foreach ($results['sites'] ?? [] as $s) {
        $sites_html .= "<tr><td style='padding:6px 12px'>{$s['org']}</td><td style='padding:6px 12px'>{$s['nom']}</td><td style='padding:6px 12px'>" . round($s['size']/1024) . " Ko</td></tr>";
    }

    $html = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;color:#1e293b;background:#f8fafc;padding:20px'>
    <div style='max-width:600px;margin:0 auto;background:white;border-radius:12px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,.1)'>
      <h2 style='color:#10b981'>✅ Sauvegarde automatique réussie</h2>
      <p>La sauvegarde quotidienne de Cadurix s'est terminée avec succès.</p>
      <table style='width:100%;border-collapse:collapse;margin:16px 0;font-size:13px'>
        <tr style='background:#f1f5f9'><td style='padding:8px 12px;font-weight:700'>Date</td><td style='padding:8px 12px'>$date_label</td></tr>
        <tr><td style='padding:8px 12px;font-weight:700'>Durée</td><td style='padding:8px 12px'>{$duration}s</td></tr>
        <tr style='background:#f1f5f9'><td style='padding:8px 12px;font-weight:700'>Backup complet</td><td style='padding:8px 12px'>" . round($full_size/1024) . " Ko</td></tr>
        <tr><td style='padding:8px 12px;font-weight:700'>Sites sauvegardés</td><td style='padding:8px 12px'>" . count($site_files) . "</td></tr>
      </table>
      " . ($sites_html ? "<h3 style='color:#0f4c81'>Détail par site</h3><table style='width:100%;border-collapse:collapse;font-size:12px'><thead><tr style='background:#f1f5f9'><th style='padding:6px 12px;text-align:left'>Organisation</th><th style='padding:6px 12px;text-align:left'>Site</th><th style='padding:6px 12px;text-align:left'>Taille</th></tr></thead><tbody>$sites_html</tbody></table>" : '') . "
      <p style='font-size:12px;color:#94a3b8;margin-top:20px'>Les fichiers sont conservés 30 jours (backup complet) et 7 jours (par site) sur le serveur.</p>
      <hr style='border:none;border-top:1px solid #e2e8f0;margin:16px 0'>
      <p style='font-size:11px;color:#94a3b8'>Cadurix v5.4 — Sauvegarde automatique</p>
    </div></body></html>";

    // Envoyer avec ou sans pièce jointe selon la taille
    if ($full_size <= $max_size_attach) {
        // Pièce jointe : backup complet
        $boundary = md5(time());
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
        $headers .= "From: Cadurix Backup <$app_email>\r\n";

        $body  = "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $body .= $html . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: application/octet-stream; name=\"" . basename($full_file) . "\"\r\n";
        $body .= "Content-Disposition: attachment; filename=\"" . basename($full_file) . "\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode(file_get_contents($full_file))) . "\r\n";
        $body .= "--$boundary--";

        $sent = @mail($super_email, "✅ Cadurix — Backup quotidien $date_label (+ fichier SQL)", $body, $headers);
        echo "[" . date('H:i:s') . "] " . ($sent ? "✅" : "❌") . " Email envoyé avec backup en PJ ($app_email → $super_email)\n";
    } else {
        // Trop gros → notification seule
        $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: Cadurix Backup <$app_email>\r\n";
        $sent = @mail($super_email, "✅ Cadurix — Backup quotidien $date_label", $html, $headers);
        echo "[" . date('H:i:s') . "] " . ($sent ? "✅" : "❌") . " Email notification envoyé (backup trop grand pour PJ: " . round($full_size/1024/1024, 1) . " Mo)\n";
    }
}

// ── Log audit ────────────────────────────────────────────────
try {
    $pdo->prepare("INSERT INTO audit_log (user_id,action,table_name,description,ip) VALUES (0,'BACKUP_AUTO','sites',?,?)")
        ->execute(["Backup auto : " . count($site_files) . " sites, " . round($full_size/1024) . " Ko", '127.0.0.1']);
} catch(Exception $e) {}

// ── Résumé final ─────────────────────────────────────────────
$total_time = round(microtime(true) - $start_time, 2);
echo "\n[" . date('H:i:s') . "] ════════════════════════════════\n";
echo "[" . date('H:i:s') . "] ✅ Sauvegarde terminée en {$total_time}s\n";
echo "[" . date('H:i:s') . "] 📦 Backup complet : " . round($full_size/1024) . " Ko\n";
echo "[" . date('H:i:s') . "] 🏢 Sites sauvegardés : " . count($site_files) . "\n";
echo "[" . date('H:i:s') . "] 📁 Dossier : $backup_dir\n";
