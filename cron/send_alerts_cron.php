<?php
/**
 * Cadurix — Envoi automatique des alertes de péremption (CRON, headless).
 *
 * Placer ce fichier dans  cron/send_alerts_cron.php
 * Exemple de crontab (tous les lundis à 8h) :
 *   0 8 * * 1 /usr/bin/php /home/UTILISATEUR/public_html/cron/send_alerts_cron.php >> /home/UTILISATEUR/cadurix_alertes.log 2>&1
 *
 * La FRÉQUENCE est donnée par la crontab : ce script envoie le digest à chaque exécution.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI uniquement.\n"); }
date_default_timezone_set('Europe/Paris');

require_once __DIR__ . '/../config/db.php';            // $pdo
require_once __DIR__ . '/../includes/mailer.php';      // cadurix_send_mail, cdx_log_email
require_once __DIR__ . '/../includes/alert_email.php'; // buildAlertEmail

function cron_log(string $m): void { echo '[' . date('Y-m-d H:i:s') . '] ' . $m . PHP_EOL; }

if (!isset($pdo) || !($pdo instanceof PDO)) { cron_log('ERREUR: $pdo indisponible (config/db.php).'); exit(1); }

// Pause globale : on respecte le même interrupteur que l'interface
try {
    $st = $pdo->prepare("SELECT valeur FROM parametres WHERE cle='email_paused'");
    $st->execute();
    if ($st->fetchColumn() === '1') { cron_log('Envois en pause — rien envoyé.'); exit(0); }
} catch (\Throwable $e) {}

// Seuil avertissement (jours) — global, défaut 30
$seuil = 30;
try {
    $st = $pdo->prepare("SELECT valeur FROM parametres WHERE cle IN ('seuil_peremption_j','alert_seuil_j') LIMIT 1");
    $st->execute();
    $v = $st->fetchColumn();
    if ($v !== false && (int)$v > 0) { $seuil = (int)$v; }
} catch (\Throwable $e) {}

$sites = $pdo->query("SELECT id, nom FROM sites WHERE actif=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
cron_log(count($sites) . ' site(s) actif(s). Seuil avertissement : ' . $seuil . ' j.');

$total_envoyes = 0;
foreach ($sites as $site) {
    $sid = (int)$site['id'];
    $base = "SELECT p.*, e.nom AS loc FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND e.site_id=" . $sid . " AND ";

    $expired  = $pdo->query($base . "p.date_peremption < CURDATE() ORDER BY p.date_peremption")->fetchAll(PDO::FETCH_ASSOC);
    $critical = $pdo->query($base . "DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 7 ORDER BY p.date_peremption")->fetchAll(PDO::FETCH_ASSOC);
    $warning  = $pdo->query($base . "DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 8 AND " . $seuil . " ORDER BY p.date_peremption")->fetchAll(PDO::FETCH_ASSOC);
    $lowstock = $pdo->query($base . "p.quantite_min>0 AND p.quantite<=p.quantite_min ORDER BY p.nom")->fetchAll(PDO::FETCH_ASSOC);

    // Rappel stupéfiants : coffre non contrôlé depuis > 30 j (ou jamais), si solde > 0
    $stupNote = '';
    try {
        $solde = (float)$pdo->query("SELECT COALESCE(SUM(p.quantite),0) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.stupefiant=1 AND e.type='coffre' AND e.site_id=$sid")->fetchColumn();
        if ($solde > 0) {
            $last = $pdo->query("SELECT MAX(sc.cree_le) FROM stupefiants_controles sc JOIN produits p ON p.id=sc.produit_id JOIN emplacements e ON e.id=p.emplacement_id WHERE e.type='coffre' AND e.site_id=$sid")->fetchColumn();
            $days = $last ? floor((time() - strtotime($last)) / 86400) : null;
            if ($days === null)  $stupNote = "🔒 Le coffre à stupéfiants n'a jamais été contrôlé — contrôle recommandé.";
            elseif ($days > 30)  $stupNote = "🔒 Le coffre à stupéfiants n'a pas été contrôlé depuis " . (int)$days . " jours — contrôle recommandé.";
        }
    } catch (\Throwable $e) {}

    $total = count($expired) + count($critical) + count($warning) + count($lowstock);
    if ($total === 0 && $stupNote === '') { cron_log("Site $sid (" . $site['nom'] . ") : rien a signaler."); continue; }

    // Destinataires : utilisateurs actifs du site avec un email
    $ds = $pdo->prepare("
        SELECT u.prenom, u.nom, u.email
        FROM utilisateurs u
        JOIN user_site_permissions usp ON usp.user_id=u.id
        WHERE usp.site_id=? AND u.actif=1 AND u.email IS NOT NULL AND u.email!=''
        GROUP BY u.id
        ORDER BY u.nom
    ");
    $ds->execute([$sid]);
    $dest = $ds->fetchAll(PDO::FETCH_ASSOC);
    if (!$dest) { cron_log("Site $sid : $total alerte(s) mais aucun destinataire avec email."); continue; }

    $include = ['expired', 'critical', 'warning', 'lowstock'];
    $subject = '🔔 Alertes Cadurix — ' . $site['nom'] . ' — ' . date('d/m/Y');
    $html = buildAlertEmail($site['nom'], date('d/m/Y'), $stupNote, $include, $expired, $critical, $warning, $lowstock);

    $ok_site = 0;
    foreach ($dest as $u) {
        $ok = cadurix_send_mail($pdo, $u['email'], trim($u['prenom'] . ' ' . $u['nom']), $subject, $html, 'alerte');
        if ($ok) { $ok_site++; $total_envoyes++; }
    }
    cron_log("Site $sid (" . $site['nom'] . ") : $total alerte(s) -> $ok_site/" . count($dest) . " email(s) envoye(s).");
}

// Mémoriser la date du dernier envoi automatique
try {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO parametres (cle,valeur,type,groupe) VALUES('alert_last_sent',?,'string','smtp') ON DUPLICATE KEY UPDATE valeur=?")->execute([$now, $now]);
} catch (\Throwable $e) {}

cron_log("Termine. $total_envoyes email(s) envoye(s) au total.");
exit(0);
