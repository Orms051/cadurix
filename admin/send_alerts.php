<?php
/**
 * Cadurix v5.2 — Envoi alertes par email
 */
if (!defined('ADMIN_HUB')) require_once '../includes/auth.php';
if (!isAdmin()) { accessDenied(); }

// Garde-fou anti-page-blanche : tout fatal devient un message lisible
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { @ob_end_clean(); } // vide les buffers du hub pour rendre l'erreur visible
        if (!headers_sent()) { http_response_code(500); }
        echo '<div style="max-width:680px;margin:40px auto;padding:18px 22px;border:1px solid #ef4444;'
           . 'background:#fef2f2;border-radius:10px;font-family:system-ui;color:#991b1b;line-height:1.5">'
           . '<b>⚠️ Erreur serveur sur la page Emails.</b><br>'
           . htmlspecialchars($e['message']) . '<br><span style="font-size:.8rem;color:#7f1d1d">'
           . htmlspecialchars(basename($e['file'])) . ':' . (int)$e['line'] . '</span></div>';
    }
});

$pageTitle = 'Envoyer les alertes';
// Page Emails = configuration GLOBALE (SMTP, pause, DNS). Aucun site précis n'est requis.
// Un site n'est utile qu'à l'onglet Alertes → site de repli si aucun n'est actif.
// On ne renvoie JAMAIS vers le dashboard : c'est une page admin globale.
$site_id = site_actif();
if (!$site_id) {
    try { $site_id = (int)($pdo->query("SELECT id FROM sites WHERE actif=1 ORDER BY id LIMIT 1")->fetchColumn() ?: 0); }
    catch (\Throwable $e) { $site_id = 0; }
}


// ── Charger config SMTP ───────────────────────────────────
function get_param(PDO $pdo, string $cle, string $default = ''): string {
    try {
        $v = $pdo->prepare("SELECT valeur FROM parametres WHERE cle=?");
        $v->execute([$cle]);
        $r = $v->fetchColumn();
        return $r !== false ? $r : $default;
    } catch (Exception $e) { return $default; }
}

$smtp = [
    'host'      => get_param($pdo, 'smtp_host'),
    'port'      => (int)get_param($pdo, 'smtp_port', '587'),
    'user'      => get_param($pdo, 'smtp_user'),
    'pass'      => get_param($pdo, 'smtp_pass'),
    'from'      => get_param($pdo, 'smtp_from'),
    'from_name' => get_param($pdo, 'smtp_from_name', 'Cadurix'),
    'secure'    => get_param($pdo, 'smtp_secure', 'tls'),
    'auto'      => (bool)get_param($pdo, 'alert_auto', '0'),
    'freq'      => get_param($pdo, 'alert_freq', 'weekly'),
    'last_sent' => get_param($pdo, 'alert_last_sent'),
];
$sec_alert_email = get_param($pdo, 'sec_alert_email', '');
$smtp['reply_to'] = $sec_alert_email ?: ($smtp['from'] ?: $smtp['user']);
$email_paused = (get_param($pdo, 'email_paused', '0') === '1');
require_once __DIR__ . '/../includes/alert_email.php';  // buildAlertEmail (partagé avec le cron)
require_once __DIR__ . '/../includes/alertes_abonnements.php'; // abonnements alertes (rôles + personnes)

if (!function_exists('eml_back')) {
    // Redirection « intelligente » : reste dans le hub admin si on y est, sinon page standalone.
    function eml_back(string $inner = 'config', string $frag = ''): void {
        if (defined('ADMIN_HUB')) {
            $tab = preg_replace('/[^a-z_]/', '', (string)($_GET['tab'] ?? '')) ?: 'alerts';
            $loc = 'index.php?tab=' . $tab . '&eml=' . $inner . $frag;
        } else {
            $loc = 'send_alerts.php?tab=' . $inner . $frag;
        }
        while (ob_get_level() > 0) { @ob_end_clean(); } // jette le chrome déjà bufferisé → redirection propre, corps vide
        if (!headers_sent()) {
            header('Location: ' . $loc, true, 303); // 303 = après POST, va en GET — jamais de re-soumission
        } else {
            // En-têtes déjà partis (BOM / buffer plafonné) : repli client robuste
            echo '<!doctype html><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loc, ENT_QUOTES) . '">'
               . '<script>location.replace(' . json_encode($loc) . ');</script>';
        }
        exit;
    }
}

if (!function_exists('cdx_log_email')) {
    function cdx_log_email(PDO $pdo, string $type, string $to, ?string $subject, bool $ok, ?string $method = null, ?string $error = null, ?int $site = null): void {
        try {
            $st = $pdo->prepare("INSERT INTO email_log (type,destinataire,sujet,statut,methode,erreur,site_id) VALUES (?,?,?,?,?,?,?)");
            $st->execute([
                mb_substr($type, 0, 40),
                mb_substr($to, 0, 190),
                $subject !== null ? mb_substr($subject, 0, 255) : null,
                $ok ? 'ok' : 'echec',
                $method !== null ? mb_substr($method, 0, 40) : null,
                $error !== null ? mb_substr($error, 0, 500) : null,
                $site,
            ]);
        } catch (\Throwable $e) { /* un échec de log ne doit jamais casser un envoi */ }
    }
}

if (!function_exists('cdx_dns_check')) {
    function cdx_dns_check(string $domain): array {
        $out = ['domain' => $domain, 'spf' => null, 'dkim' => null, 'dmarc' => null, 'error' => null];
        try {
            foreach ((@dns_get_record($domain, DNS_TXT) ?: []) as $r) {
                $v = $r['txt'] ?? '';
                if (stripos($v, 'v=spf1') === 0) { $out['spf'] = $v; break; }
            }
            foreach ((@dns_get_record('_dmarc.' . $domain, DNS_TXT) ?: []) as $r) {
                $v = $r['txt'] ?? '';
                if (stripos($v, 'v=DMARC1') === 0) { $out['dmarc'] = $v; break; }
            }
            foreach (['default', 'dkim', 'mail', 'selector1', 's1', 'x'] as $sel) {
                foreach ((@dns_get_record($sel . '._domainkey.' . $domain, DNS_TXT) ?: []) as $r) {
                    $v = $r['txt'] ?? '';
                    if (stripos($v, 'v=DKIM1') !== false || stripos($v, 'k=rsa') !== false || stripos($v, 'p=') !== false) {
                        $out['dkim'] = $sel . ' : ' . $v; break 2;
                    }
                }
            }
        } catch (\Throwable $e) { $out['error'] = $e->getMessage(); }
        return $out;
    }
}

function smtp_diagnostic(array $smtp, string $to): array {
    $res = ['ok'=>false,'phpmailer'=>false,'method'=>'','error'=>'','log'=>'','to'=>$to];
    $pm = __DIR__.'/../libs/PHPMailer/src/PHPMailer.php';
    $res['phpmailer'] = file_exists($pm);
    $log = '';
    try {
        if ($res['phpmailer']) {
            require_once $pm;
            require_once __DIR__.'/../libs/PHPMailer/src/SMTP.php';
            require_once __DIR__.'/../libs/PHPMailer/src/Exception.php';
            $res['method'] = 'SMTP authentifie (PHPMailer)';
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = function($str,$lvl) use (&$log){ $log .= $str."\n"; };
            $mail->isSMTP();
            $mail->Host = $smtp['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $smtp['user'];
            $mail->Password = $smtp['pass'];
            $sec = $smtp['secure'] ?? 'tls';
            $mail->SMTPSecure = ($sec === 'none') ? '' : $sec;
            if ($sec === 'none') { $mail->SMTPAutoTLS = false; }
            $mail->Port = (int)$smtp['port'];
            $mail->Timeout = 12;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($smtp['from'] ?: $smtp['user'], $smtp['from_name'] ?: 'Cadurix');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = 'Test SMTP Cadurix';
            $mail->Body = '<h2>SMTP operationnel</h2><p>Test Cadurix le '.date('d/m/Y H:i').'.</p>';
            $mail->send();
            $res['ok'] = true;
        } else {
            $res['method'] = 'mail() natif (non authentifie)';
            $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: ".($smtp['from_name'] ?: 'Cadurix')." <".($smtp['from'] ?: 'noreply@cadurix.fr').">\r\n";
            $sent = @mail($to, 'Test SMTP Cadurix (mail natif)', '<p>Test via mail() le '.date('d/m/Y H:i').'.</p>', $headers);
            $res['ok'] = (bool)$sent;
            if (!$sent) { $res['error'] = "mail() a renvoye false (MTA local indisponible)."; }
        }
    } catch (Throwable $e) {
        $res['error'] = $e->getMessage() ?: 'Erreur inconnue';
    }
    $res['log'] = $log;
    return $res;
}

// ── Infos site ────────────────────────────────────────────
$s = $pdo->prepare("SELECT s.*, o.nom AS org_nom FROM sites s JOIN organisations o ON o.id=s.organisation_id WHERE s.id=?");
$s->execute([$site_id]);
$site_info = $s->fetch(PDO::FETCH_ASSOC);
if (!is_array($site_info)) { $site_info = ['org_nom' => 'Cadurix', 'nom' => '—']; }

// ── Destinataires (admins du site) ───────────────────────
$dest_stmt = $pdo->prepare("
    SELECT u.id, u.prenom, u.nom, u.email, usp.role
    FROM utilisateurs u
    JOIN user_site_permissions usp ON usp.user_id=u.id
    WHERE usp.site_id=? AND u.actif=1 AND u.email IS NOT NULL AND u.email!=''
    ORDER BY u.nom
");
$dest_stmt->execute([$site_id]);
$destinataires = $dest_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Alertes à envoyer ─────────────────────────────────────
$fs = "e.site_id=" . intval($site_id);
$fe = sql_filtre_emplacements('e');

// Seuil de péremption configurable (module Alertes) — borne haute des avertissements
$seuil_alerte = max(8, (int) module_param('alertes', 'seuil_peremption_j', 30));

$expired  = $pdo->query("SELECT p.*,e.nom AS loc FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND $fs AND $fe AND p.date_peremption < CURDATE() ORDER BY p.date_peremption")->fetchAll(PDO::FETCH_ASSOC);
$critical = $pdo->query("SELECT p.*,e.nom AS loc FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND $fs AND $fe AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 7 ORDER BY p.date_peremption")->fetchAll(PDO::FETCH_ASSOC);
$warning  = $pdo->query("SELECT p.*,e.nom AS loc FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND $fs AND $fe AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 8 AND $seuil_alerte ORDER BY p.date_peremption")->fetchAll(PDO::FETCH_ASSOC);
$lowstock = $pdo->query("SELECT p.*,e.nom AS loc FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND $fs AND $fe AND p.quantite_min>0 AND p.quantite<=p.quantite_min ORDER BY p.nom")->fetchAll(PDO::FETCH_ASSOC);

// ── TRAITEMENTS POST ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Sauvegarder config SMTP
    if ($action === 'save_smtp') {
        $fields = ['smtp_host','smtp_port','smtp_user','smtp_secure','smtp_from','smtp_from_name','alert_freq'];
        foreach ($fields as $f) {
            $val = trim($_POST[$f] ?? '');
            $pdo->prepare("INSERT INTO parametres (cle,valeur,type,groupe) VALUES(?,?,'string','smtp') ON DUPLICATE KEY UPDATE valeur=?")->execute([$f,$val,$val]);
        }
        // Mot de passe seulement si renseigné
        if (!empty($_POST['smtp_pass'])) {
            $pass = $_POST['smtp_pass'];
            $pdo->prepare("INSERT INTO parametres (cle,valeur,type,groupe) VALUES('smtp_pass',?,'string','smtp') ON DUPLICATE KEY UPDATE valeur=?")->execute([$pass,$pass]);
        }
        // Destinataire des alertes (groupe security)
        $sae = trim($_POST['sec_alert_email'] ?? '');
        if ($sae === '' || filter_var($sae, FILTER_VALIDATE_EMAIL)) {
            $pdo->prepare("INSERT INTO parametres (cle,valeur,type,groupe) VALUES('sec_alert_email',?,'string','security') ON DUPLICATE KEY UPDATE valeur=?")->execute([$sae,$sae]);
        }
        $auto = isset($_POST['alert_auto']) ? '1' : '0';
        $pdo->prepare("INSERT INTO parametres (cle,valeur,type,groupe) VALUES('alert_auto',?,'bool','smtp') ON DUPLICATE KEY UPDATE valeur=?")->execute([$auto,$auto]);

        flashSuccess("Configuration SMTP sauvegardée.");
        eml_back('config');
    }

// ── Abonnements : matrice des rôles ───────────────────────
    if ($action === 'abo_save_roles') {
        $pdo->prepare("DELETE FROM alertes_abonnements WHERE site_id=? AND cible_type='role'")->execute([$site_id]);
        $ins = $pdo->prepare("INSERT IGNORE INTO alertes_abonnements (site_id,type_alerte,cible_type,cible_valeur) VALUES (?,?,'role',?)");
        foreach (array_keys(alertes_types()) as $t)
            foreach (array_keys(alertes_roles($pdo)) as $role)
                if (!empty($_POST['r'][$t][$role])) $ins->execute([$site_id,$t,$role]);
        flashSuccess("Abonnements par rôle enregistrés.");
        eml_back('alertes');
    }
    if ($action === 'abo_add_user') {
        $t = $_POST['type'] ?? ''; $uid = (int)($_POST['user_id'] ?? 0);
        if (array_key_exists($t, alertes_types()) && $uid > 0)
            $pdo->prepare("INSERT IGNORE INTO alertes_abonnements (site_id,type_alerte,cible_type,cible_valeur) VALUES (?,?,'user',?)")->execute([$site_id,$t,$uid]);
        flashSuccess("Destinataire ajouté."); eml_back('alertes');
    }
    if ($action === 'abo_remove_user') {
        $t = $_POST['type'] ?? ''; $uid = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare("DELETE FROM alertes_abonnements WHERE site_id=? AND type_alerte=? AND cible_type='user' AND cible_valeur=?")->execute([$site_id,$t,(string)$uid]);
        flashSuccess("Destinataire retiré."); eml_back('alertes');
    }

    // Envoi des alertes — PAR TYPE, aux abonnés définis dans la matrice
    if ($action === 'send_alerts') {
        if (!module_param('alertes','email', false)) {
            flashError("L'envoi d'email est désactivé pour le module Alertes de ce site."); eml_back('alertes');
        }
        if ($email_paused) {
            flashError("⏸️ Les envois sont en pause. Réactive-les dans l'onglet Configuration."); eml_back('alertes');
        }
        if (!$smtp['host'] || !$smtp['user'] || !$smtp['from']) {
            flashError("Configurez d'abord le serveur SMTP ci-dessous."); eml_back('config');
        }
        $types_env    = $_POST['types'] ?? [];
        $sujet_custom = trim($_POST['sujet'] ?? '');
        $note_custom  = trim($_POST['note'] ?? '');
        if (empty($types_env)) { flashError("Sélectionnez au moins un type d'alerte à envoyer."); eml_back('alertes'); }

        $site_nom = $site_info['org_nom'] . ' — ' . $site_info['nom'];
        $date_gen = date('d/m/Y à H:i');
        $cat_map  = ['peremption'=>['expired','critical','warning'], 'stock_bas'=>['lowstock']];
        $libelles = alertes_types();

        $sent = 0; $errors = []; $abonnes_total = 0;
        foreach ($types_env as $type) {
            if (!isset($cat_map[$type])) continue;
            $dests = destinataires_alerte($pdo, (int)$site_id, $type);
            $abonnes_total += count($dests);
            if (!$dests) continue;
            $html  = buildAlertEmail($site_nom, $date_gen, $note_custom, $cat_map[$type], $expired, $critical, $warning, $lowstock);
            $sujet = $sujet_custom ?: (($libelles[$type] ?? 'Alertes') . " Cadurix — $site_nom — " . date('d/m/Y'));
            foreach ($dests as $u) {
                $ok = sendMailSMTP($smtp, $u['email'], $u['prenom'].' '.$u['nom'], $sujet, $html);
                cdx_log_email($pdo, 'alerte', $u['email'], $sujet, $ok, 'SMTP', $ok ? null : 'envoi refusé', $site_id);
                if ($ok) $sent++; else $errors[] = $u['email'];
            }
        }

        $pdo->prepare("INSERT INTO parametres (cle,valeur,type,groupe) VALUES('alert_last_sent',?,'string','smtp') ON DUPLICATE KEY UPDATE valeur=?")->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        audit_log($pdo, 'ENVOI_ALERTES', 'alertes', 0, null, ['nb_dest'=>$sent, 'types'=>implode(',',(array)$types_env), 'site_id'=>$site_id]);

        if ($sent > 0) flashSuccess("✅ Alertes envoyées à $sent destinataire(s)" . (!empty($errors) ? " · Échecs : ".implode(', ',$errors) : ""));
        elseif ($abonnes_total === 0) flashError("Aucun abonné pour les types choisis. Règle-les dans « Qui reçoit quoi ».");
        else flashError("Échec de l'envoi. Vérifiez la config SMTP.");
        eml_back('alertes');
    }

    // Test SMTP (diagnostic détaillé)
    if ($action === 'test_smtp') {
        $email_test = trim($_POST['test_email'] ?? '');
        if (!$email_test || !filter_var($email_test, FILTER_VALIDATE_EMAIL)) { flashError("Email de test invalide."); }
        else {
            try { $_SESSION['smtp_test'] = smtp_diagnostic($smtp, $email_test); }
            catch (Throwable $e) { $_SESSION['smtp_test'] = ['ok'=>false,'phpmailer'=>false,'method'=>'','error'=>$e->getMessage(),'log'=>'','to'=>$email_test]; }
            $tr = $_SESSION['smtp_test'];
            cdx_log_email($pdo, 'test', $email_test, 'Test SMTP Cadurix', !empty($tr['ok']), $tr['method'] ?? null, $tr['error'] ?? null, $site_id);
        }
        eml_back('config', '#smtp');
    }

    // Pause / reprise globale des envois
    if ($action === 'toggle_pause') {
        $new = (get_param($pdo, 'email_paused', '0') === '1') ? '0' : '1';
        $pdo->prepare("INSERT INTO parametres (cle,valeur,type,groupe) VALUES('email_paused',?,'bool','smtp') ON DUPLICATE KEY UPDATE valeur=?")->execute([$new, $new]);
        flashSuccess($new === '1' ? '⏸️ Envois mis en pause.' : '▶️ Envois réactivés.');
        eml_back('config');
    }

    // Vérification DNS délivrabilité (SPF / DKIM / DMARC)
    if ($action === 'check_dns') {
        $dom = trim($_POST['dns_domain'] ?? '');
        if (strpos($dom, '@') !== false) { $dom = substr(strrchr($dom, '@'), 1); }
        $dom = trim($dom);
        if ($dom !== '') { try { $_SESSION['dns_check'] = cdx_dns_check($dom); } catch (Throwable $e) {} }
        eml_back('config', '#dns');
    }

    // Vider l'historique des emails
    if ($action === 'clear_email_log') {
        try { $pdo->exec("DELETE FROM email_log"); flashSuccess("Historique des emails vide."); }
        catch (\Throwable $e) { flashError("Impossible de vider l'historique (table absente ?)."); }
        eml_back('config');
    }
}

// ── Fonctions ─────────────────────────────────────────────

function sendMailSMTP(array $smtp, string $to_email, string $to_name, string $subject, string $html): bool {
    // Utiliser mail() natif si pas de lib externe
    // En prod, installer PHPMailer via Composer pour SMTP authentifié
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . $smtp['from_name'] . " <" . $smtp['from'] . ">\r\n";
    $headers .= "Reply-To: " . ($smtp['reply_to'] ?? $smtp['from']) . "\r\n";
    $headers .= "X-Mailer: Cadurix/5.2\r\n";

    // Si PHPMailer disponible
    if (file_exists(__DIR__.'/../libs/PHPMailer/src/PHPMailer.php')) {
        require_once __DIR__.'/../libs/PHPMailer/src/PHPMailer.php';
        require_once __DIR__.'/../libs/PHPMailer/src/SMTP.php';
        require_once __DIR__.'/../libs/PHPMailer/src/Exception.php';
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $smtp['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtp['user'];
            $mail->Password   = $smtp['pass'];
            $mail->SMTPSecure = $smtp['secure'] === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $smtp['port'];
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom($smtp['from'], $smtp['from_name']);
            if (!empty($smtp['reply_to'])) { $mail->addReplyTo($smtp['reply_to']); }
            $mail->addAddress($to_email, $to_name);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->send();
            return true;
        } catch (Exception $e) { return false; }
    }

    // Fallback mail() natif
    return mail($to_email, $subject, $html, $headers);
}

$eml_tab = ((($_GET['eml'] ?? $_GET['tab'] ?? '')) === 'alertes') ? 'alertes' : 'config';
if (!empty($_SESSION['smtp_test'])) { $eml_tab = 'config'; }
if (!defined('ADMIN_HUB')) require_once '../includes/header.php';
?>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">📧 Emails</div>
    <div class="page-sub"><?= h($site_info['org_nom'].' — '.$site_info['nom']) ?></div>
  </div>
  <a href="../alerts.php" class="btn btn-ghost">← Alertes</a>
</div>

<style>
.alert-send-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
@media(max-width:900px){ .alert-send-grid{grid-template-columns:1fr} }
.dest-list { display:flex; flex-direction:column; gap:6px; max-height:240px; overflow-y:auto; }
.dest-item { display:flex; align-items:center; gap:10px; padding:8px 12px; background:var(--glass); border:1px solid var(--border); border-radius:8px; cursor:pointer; transition:all .15s; }
.dest-item:hover { border-color:var(--primary); }
.dest-item input[type=checkbox] { accent-color:var(--primary); width:16px; height:16px; flex-shrink:0; }
.dest-avatar { width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700; color:#fff; flex-shrink:0; }
.include-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.include-item { display:flex; align-items:center; gap:8px; padding:8px 12px; background:var(--glass); border:1px solid var(--border); border-radius:8px; cursor:pointer; }
.include-item input { accent-color:var(--primary); }
.smtp-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media(max-width:600px){ .smtp-grid{grid-template-columns:1fr} }
.preview-box { background:var(--bg3); border:1px solid var(--border); border-radius:8px; padding:16px; font-size:.82rem; color:var(--text2); }
.eml-tabs { display:inline-flex; gap:4px; margin-bottom:22px; padding:4px; background:var(--glass); border:1px solid var(--border); border-radius:12px; }
.eml-tab-btn { background:none; border:none; padding:9px 20px; font-size:.88rem; font-weight:600; color:var(--text3); cursor:pointer; border-radius:9px; transition:all .15s; }
.eml-tab-btn:hover { color:var(--text2); }
.eml-tab-btn.active { color:#fff; background:var(--primary); box-shadow:0 2px 10px rgba(var(--primary-rgb),.30); }
.eml-panel { max-width:1680px; }
.eml-panel[hidden] { display:none; }
.eml-grid { display:grid; grid-template-columns:1.15fr .92fr .92fr; gap:18px; align-items:start; margin-bottom:18px; }
@media(max-width:1200px){ .eml-grid{ grid-template-columns:1fr 1fr; } }
@media(max-width:760px){ .eml-grid{ grid-template-columns:1fr; } }
.eml-bottom { max-width:1480px; }
@media(max-width:880px){ .eml-bottom{ grid-template-columns:1fr; } }
.eml-hist { display:flex; flex-direction:column; gap:6px; max-height:620px; overflow-y:auto; padding-right:4px; }
.eml-hist-row { display:flex; align-items:center; gap:10px; padding:8px 10px; border:1px solid var(--border); border-radius:8px; font-size:.76rem; transition:border-color .15s; }
.eml-hist-row:hover { border-color:var(--border2); }
.eml-col { display:flex; flex-direction:column; min-width:0; }
.eml-col > :last-child { margin-bottom:0 !important; }
.eml-hero { display:flex; align-items:center; gap:14px; padding:15px 20px; border-radius:14px; margin-bottom:18px; border:1px solid var(--border); background:var(--glass); }
.eml-hero.ok { border-color:rgba(16,185,129,.35); background:rgba(16,185,129,.06); }
.eml-hero.warn { border-color:rgba(245,158,11,.35); background:rgba(245,158,11,.06); }
.eml-hero-dot { width:12px; height:12px; border-radius:50%; flex-shrink:0; }
.eml-hero.ok .eml-hero-dot { background:#10b981; box-shadow:0 0 0 4px rgba(16,185,129,.18); }
.eml-hero.warn .eml-hero-dot { background:#f59e0b; box-shadow:0 0 0 4px rgba(245,158,11,.18); }
.eml-hero-title { font-size:1.02rem; font-weight:700; }
.eml-hero-sub { font-size:.8rem; color:var(--text2); margin-top:3px; }
.eml-hero-meta { font-size:.72rem; color:var(--text3); text-align:right; flex-shrink:0; line-height:1.4; }
</style>

<div class="eml-tabs">
  <button type="button" class="eml-tab-btn<?= $eml_tab==='config'?' active':'' ?>" data-tab="config" onclick="emlTab('config')">⚙️ Configuration</button>
  <button type="button" class="eml-tab-btn<?= $eml_tab==='alertes'?' active':'' ?>" data-tab="alertes" onclick="emlTab('alertes')">🔔 Alertes</button>
</div>

<div id="tab-alertes" class="eml-panel"<?= $eml_tab==='alertes'?'':' hidden' ?>>

<!-- Stats rapides -->
<style>
.akpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
@media(max-width:900px){.akpi-grid{grid-template-columns:1fr 1fr}}
.akpi{position:relative;display:flex;align-items:center;gap:12px;background:var(--bg2);border:1px solid var(--border);border-radius:13px;padding:12px 15px;overflow:hidden;box-shadow:0 1px 2px rgba(20,30,60,.04)}
.akpi::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--kc,var(--primary))}
.akpi .ic{width:36px;height:36px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.05rem}
.akpi .n{font-size:1.5rem;font-weight:800;line-height:1;font-variant-numeric:tabular-nums}
.akpi .l{font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em;font-weight:700;margin-top:3px}
</style>
<div class="akpi-grid">
  <div class="akpi" style="--kc:<?= count($expired)>0?'#ef4444':'#94a3b8' ?>"><span class="ic" style="background:<?= count($expired)>0?'#ef4444':'#94a3b8' ?>1f;color:<?= count($expired)>0?'#ef4444':'#94a3b8' ?>">⛔</span><div><div class="n" style="color:<?= count($expired)>0?'#ef4444':'#94a3b8' ?>"><?= count($expired) ?></div><div class="l">Périmés</div></div></div>
  <div class="akpi" style="--kc:#ef4444"><span class="ic" style="background:#ef44441f;color:#ef4444">🔴</span><div><div class="n" style="color:#ef4444"><?= count($critical) ?></div><div class="l">Critiques ≤ 7j</div></div></div>
  <div class="akpi" style="--kc:#f59e0b"><span class="ic" style="background:#f59e0b1f;color:#f59e0b">🟡</span><div><div class="n" style="color:#f59e0b"><?= count($warning) ?></div><div class="l">Avertissements</div></div></div>
  <div class="akpi" style="--kc:#f59e0b"><span class="ic" style="background:#f59e0b1f;color:#f59e0b">📦</span><div><div class="n" style="color:#f59e0b"><?= count($lowstock) ?></div><div class="l">Stocks bas</div></div></div>
</div>

  <!-- ═══ Qui reçoit quoi (règles) ═══ -->
  <?php
    $abo_matrix     = abo_roles_matrix($pdo, (int)$site_id);
    $abo_site_users = abo_users_du_site($pdo, (int)$site_id);
    $ABO_TYPES = alertes_types();
    $ABO_ROLES = alertes_roles($pdo);
    $ENV_TYPES = ['peremption' => '⏰ Péremption', 'stock_bas' => '📦 Stock bas'];
    $ENV_CNT   = ['peremption' => count($expired)+count($critical)+count($warning), 'stock_bas' => count($lowstock)];
  ?>
  <style>
    .abo-table{border-collapse:collapse;width:100%}
    .abo-table th,.abo-table td{border:1px solid var(--border);padding:9px 11px;text-align:center}
    .abo-table th:first-child,.abo-table td:first-child{text-align:left;white-space:nowrap}
    .abo-table thead th{background:var(--bg2);font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--text2)}
    .abo-table input[type=checkbox]{width:17px;height:17px;cursor:pointer}
    .abo-chip{display:inline-flex;align-items:center;gap:6px;background:var(--bg2);border:1px solid var(--border);border-radius:999px;padding:3px 6px 3px 10px;font-size:12px;margin:3px 3px 0 0}
    .abo-chip button{border:none;background:transparent;color:var(--red);cursor:pointer;font-size:13px;line-height:1}
    .abo-add{display:flex;gap:6px;align-items:center;margin-top:6px}
    .abo-add select{flex:1;min-width:0}
    .abo-nominatifs{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px}
    .abo-nom-card{border:1px solid var(--border);border-radius:10px;padding:12px;background:var(--bg2)}
  </style>

  <div class="card" style="margin-bottom:16px">
    <div class="card-title" style="margin-bottom:6px">🎯 Qui reçoit quoi</div>
    <div style="color:var(--text3);font-size:.8rem;margin-bottom:12px">Règle les destinataires par rôle. Ces réglages pilotent l'envoi manuel ci-dessous <b>et</b> le cron automatique.</div>

    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="abo_save_roles">
      <div style="overflow-x:auto">
      <table class="abo-table">
        <thead>
          <tr><th>Type d'alerte</th>
            <?php foreach ($ABO_ROLES as $role => $rl): ?><th><?= h($rl) ?></th><?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ABO_TYPES as $t => $tl): ?>
          <tr><td><?= $tl ?></td>
            <?php foreach ($ABO_ROLES as $role => $rl): ?>
              <td><input type="checkbox" name="r[<?= $t ?>][<?= $role ?>]" value="1" <?= !empty($abo_matrix[$t][$role])?'checked':'' ?>></td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div style="text-align:right;margin-top:12px"><button type="submit" class="btn btn-primary">💾 Enregistrer les rôles</button></div>
    </form>

    <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
      <div style="font-weight:600;margin-bottom:10px;font-size:.9rem">Destinataires nominatifs (en plus des rôles)</div>
      <div class="abo-nominatifs">
      <?php foreach ($ABO_TYPES as $t => $tl): $us = abo_users($pdo,(int)$site_id,$t); ?>
        <div class="abo-nom-card">
          <div style="font-size:.82rem;font-weight:600;margin-bottom:4px"><?= $tl ?></div>
          <div>
            <?php if (empty($us)): ?><span style="color:var(--text3);font-size:12px">Aucun</span>
            <?php else: foreach ($us as $u): ?>
              <span class="abo-chip"><?= h(trim($u['prenom'].' '.$u['nom'])) ?>
                <form method="POST" style="display:inline"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="abo_remove_user">
                  <input type="hidden" name="type" value="<?= $t ?>">
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <button type="submit" title="Retirer">✕</button>
                </form>
              </span>
            <?php endforeach; endif; ?>
          </div>
          <form method="POST" class="abo-add"><?= csrf_field() ?>
            <input type="hidden" name="action" value="abo_add_user">
            <input type="hidden" name="type" value="<?= $t ?>">
            <select name="user_id" class="form-control" required>
              <option value="">+ Ajouter une personne…</option>
              <?php foreach ($abo_site_users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h(trim($u['prenom'].' '.$u['nom'])) ?> — <?= h($u['email']) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-ghost btn-sm">Ajouter</button>
          </form>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ═══ Envoyer maintenant (par type, aux abonnés) ═══ -->
  <div class="card">
    <div class="card-title" style="margin-bottom:6px">📨 Envoyer maintenant</div>
    <div style="color:var(--text3);font-size:.8rem;margin-bottom:12px">Chaque type part <b>uniquement</b> à ses abonnés définis ci-dessus.</div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_alerts">
      <div class="include-grid" style="margin-bottom:14px">
        <?php foreach ($ENV_TYPES as $t => $tl): $nbd = count(destinataires_alerte($pdo,(int)$site_id,$t)); ?>
        <label class="include-item">
          <input type="checkbox" name="types[]" value="<?= $t ?>" checked>
          <span><?= $tl ?> · <?= (int)$ENV_CNT[$t] ?> alerte(s) · <?= $nbd ?> destinataire(s)</span>
        </label>
        <?php endforeach; ?>
      </div>
      <div class="form-group">
        <label class="form-label">Sujet (optionnel)</label>
        <input type="text" name="sujet" class="form-control" placeholder="🔔 Alertes Cadurix — <?= h($site_info['nom']) ?> — <?= date('d/m/Y') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Note personnalisée (optionnel)</label>
        <textarea name="note" class="form-control" rows="2" placeholder="Ex: Vérification mensuelle — priorité aux périmés."></textarea>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%" <?= (!$smtp['host'] || $email_paused)?'disabled':'' ?>>
        <i class="ti ti-send"></i> Envoyer aux abonnés
      </button>
      <?php if ($email_paused): ?>
        <div style="text-align:center;margin-top:8px;font-size:.78rem;color:var(--amber)">⏸️ Envois en pause — réactive-les dans Configuration</div>
      <?php elseif (!$smtp['host']): ?>
        <div style="text-align:center;margin-top:8px;font-size:.78rem;color:var(--amber)">⚠️ Configure le SMTP dans Configuration avant d'envoyer</div>
      <?php endif; ?>
    </form>
  </div>
</div><!-- /tab-alertes -->

<div id="tab-config" class="eml-panel"<?= $eml_tab==='config'?'':' hidden' ?>>

  <!-- En-tête de statut -->
  <div class="eml-hero <?= $smtp['host'] ? 'ok' : 'warn' ?>">
    <span class="eml-hero-dot"></span>
    <div style="flex:1;min-width:0">
      <div class="eml-hero-title"><?= $smtp['host'] ? 'Emails configurés' : 'Configuration incomplète' ?><?= $email_paused ? ' <span style="font-size:.68rem;font-weight:700;color:#b45309;background:rgba(245,158,11,.15);padding:2px 9px;border-radius:10px;vertical-align:middle">⏸️ EN PAUSE</span>' : '' ?></div>
      <div class="eml-hero-sub">
        <?php if ($smtp['host']): ?>
          Expéditeur <b><?= h($smtp['from'] ?: ($smtp['user'] ?: '—')) ?></b> · alertes reçues sur <b><?= h($sec_alert_email ?: '—') ?></b>
        <?php else: ?>
          Renseigne le serveur SMTP pour activer les envois (codes 2FA, alertes de péremption…).
        <?php endif; ?>
      </div>
    </div>
    <?php if ($smtp['last_sent']): ?>
    <div class="eml-hero-meta">Dernier envoi<br><b><?= date('d/m/Y H:i', strtotime($smtp['last_sent'])) ?></b></div>
    <?php endif; ?>
  </div>

  <div class="eml-grid">
    <div class="eml-col">

    <!-- Config SMTP -->
    <div class="card" style="margin-bottom:16px">
      <div class="card-title" style="margin-bottom:14px">⚙️ Configuration SMTP</div>

      <!-- Statut connexion -->
      <div class="preview-box" style="margin-bottom:12px">
        <?php if ($smtp['host']): ?>
        <div style="display:flex;align-items:center;gap:8px">
          <span style="color:var(--primary);font-size:16px">⚙️</span>
          <div>
            <div style="font-weight:600;font-size:.82rem"><?= h($smtp['host']) ?>:<?= $smtp['port'] ?></div>
            <div style="color:var(--text3);font-size:.72rem"><?= h($smtp['user']) ?> · <?= strtoupper($smtp['secure']) ?></div>
          </div>
        </div>
        <?php else: ?>
        <div style="color:var(--amber);font-size:.82rem">⚠️ SMTP non configuré — les emails ne peuvent pas être envoyés.</div>
        <?php endif; ?>
      </div>

      <div id="smtp-form">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_smtp">
          <div class="smtp-grid">
            <div class="form-group">
              <label class="form-label">Serveur SMTP *</label>
              <input type="text" name="smtp_host" class="form-control" value="<?= h($smtp['host']) ?>" placeholder="smtp.gmail.com">
            </div>
            <div class="form-group">
              <label class="form-label">Port *</label>
              <input type="number" name="smtp_port" class="form-control" value="<?= $smtp['port'] ?>" placeholder="587">
            </div>
            <div class="form-group">
              <label class="form-label">Utilisateur *</label>
              <input type="email" name="smtp_user" class="form-control" value="<?= h($smtp['user']) ?>" placeholder="user@gmail.com">
            </div>
            <div class="form-group">
              <label class="form-label">Mot de passe</label>
              <input type="password" name="smtp_pass" class="form-control" placeholder="••••••••" autocomplete="new-password">
            </div>
            <div class="form-group">
              <label class="form-label">Email expéditeur *</label>
              <input type="email" name="smtp_from" class="form-control" value="<?= h($smtp['from']) ?>" placeholder="noreply@cadurix.fr">
            </div>
            <div class="form-group">
              <label class="form-label">Nom expéditeur</label>
              <input type="text" name="smtp_from_name" class="form-control" value="<?= h($smtp['from_name']) ?>" placeholder="Cadurix">
            </div>
            <div class="form-group">
              <label class="form-label">📥 Destinataire des alertes</label>
              <input type="email" name="sec_alert_email" class="form-control" value="<?= h($sec_alert_email) ?>" placeholder="support@cadurix.fr">
            </div>
            <div class="form-group">
              <label class="form-label">Sécurité</label>
              <select name="smtp_secure" class="form-control">
                <option value="tls" <?= $smtp['secure']==='tls'?'selected':'' ?>>TLS (port 587)</option>
                <option value="ssl" <?= $smtp['secure']==='ssl'?'selected':'' ?>>SSL (port 465)</option>
                <option value="none" <?= $smtp['secure']==='none'?'selected':'' ?>>Aucune</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Fréquence auto</label>
              <select name="alert_freq" class="form-control">
                <option value="daily" <?= $smtp['freq']==='daily'?'selected':'' ?>>Quotidien</option>
                <option value="weekly" <?= $smtp['freq']==='weekly'?'selected':'' ?>>Hebdomadaire</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
              <input type="checkbox" name="alert_auto" <?= $smtp['auto']?'checked':'' ?> style="accent-color:var(--primary)">
              <span style="font-size:.85rem">Activer l'envoi automatique</span>
            </label>
            <div style="font-size:.75rem;color:var(--text3);margin-top:4px">Nécessite un cron job : <code>php /path/to/cron/send_alerts_cron.php</code></div>
          </div>
          <div style="display:flex;gap:8px">
            <button type="submit" class="btn btn-primary" style="flex:1">
              <i class="ti ti-device-floppy"></i> Sauvegarder
            </button>
          </div>
        </form>

        <!-- Test SMTP -->
        <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border)" id="smtp">
          <div style="font-size:.78rem;color:var(--text2);margin-bottom:8px;font-weight:600">Tester la connexion</div>
          <?php if (!file_exists(__DIR__.'/../libs/PHPMailer/src/PHPMailer.php')): ?>
          <div style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);color:#b45309;border-radius:8px;padding:10px 12px;font-size:.78rem;margin-bottom:8px;line-height:1.5">
            ⚠️ <b>PHPMailer non installé.</b> Les emails partent via <code>mail()</code> local : ta configuration SMTP ci-dessus (host / identifiants) <b>n'est pas utilisée</b>. Installe PHPMailer dans <code>libs/PHPMailer/</code> pour un envoi authentifié fiable.
          </div>
          <?php endif; ?>
          <?php if (!empty($_SESSION['smtp_test'])): $r=$_SESSION['smtp_test']; unset($_SESSION['smtp_test']); ?>
          <div style="background:<?= $r['ok']?'rgba(16,185,129,.1)':'rgba(239,68,68,.1)' ?>;border:1px solid <?= $r['ok']?'rgba(16,185,129,.3)':'rgba(239,68,68,.3)' ?>;border-radius:8px;padding:11px 13px;font-size:.8rem;margin-bottom:10px">
            <div style="font-weight:700;color:<?= $r['ok']?'#059669':'#dc2626' ?>"><?= $r['ok']?'✅ Email de test envoyé':'❌ Envoi échoué' ?> → <?= h($r['to']??'') ?></div>
            <div style="color:var(--text2);margin-top:4px">Méthode : <b><?= h($r['method']) ?></b></div>
            <?php if (!empty($r['error'])): ?><div style="color:#dc2626;margin-top:4px;font-family:var(--mono);font-size:.72rem;word-break:break-word"><?= h($r['error']) ?></div><?php endif; ?>
            <?php if (!empty($r['log'])): ?><details style="margin-top:6px"><summary style="cursor:pointer;font-size:.72rem;color:var(--text3)">Journal SMTP</summary><pre style="font-size:.68rem;background:var(--bg3);padding:8px;border-radius:6px;overflow:auto;max-height:220px;white-space:pre-wrap"><?= h($r['log']) ?></pre></details><?php endif; ?>
            <?php if ($r['ok']): ?><div style="color:var(--text3);margin-top:5px;font-size:.72rem">Pense à vérifier tes spams — un envoi accepté ne garantit pas l'absence de mise en spam.</div><?php endif; ?>
          </div>
          <?php endif; ?>
          <form method="POST" style="display:flex;gap:6px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="test_smtp">
            <input type="email" name="test_email" class="form-control" placeholder="votre@email.com" required style="flex:1">
            <button type="submit" class="btn btn-ghost">Tester</button>
          </form>
        </div>
      </div>
    </div>

    </div>
    <div class="eml-col">

    <!-- Pause des envois -->
    <div class="card" style="margin-bottom:16px;<?= $email_paused ? 'border-color:rgba(245,158,11,.45);background:rgba(245,158,11,.05)' : '' ?>">
      <div style="display:flex;align-items:center;gap:12px">
        <div style="font-size:22px;flex-shrink:0"><?= $email_paused ? '⏸️' : '▶️' ?></div>
        <div style="flex:1;min-width:0">
          <div style="font-size:.9rem;font-weight:700"><?= $email_paused ? 'Envois en pause' : 'Envois actifs' ?></div>
          <div style="font-size:.74rem;color:var(--text2);line-height:1.45">
            <?= $email_paused ? 'Bienvenue, identifiants et alertes sont suspendus. Les codes 2FA continuent de partir.' : 'Suspends temporairement bienvenue / identifiants / alertes pendant tes tests (le 2FA reste actif).' ?>
          </div>
        </div>
        <form method="POST" style="margin:0;flex-shrink:0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_pause">
          <button type="submit" class="btn <?= $email_paused ? 'btn-primary' : 'btn-ghost' ?> btn-xs"><?= $email_paused ? 'Réactiver' : 'Pause' ?></button>
        </form>
      </div>
    </div>

    <!-- Ce que Cadurix envoie -->
    <div class="card" style="margin-bottom:16px">
      <div class="card-title" style="margin-bottom:10px">📨 Ce que Cadurix envoie</div>
      <div style="font-size:.78rem;color:var(--text2);line-height:1.7">
        Tous ces emails partent depuis l'<b>expéditeur</b> configuré ci-dessus :
        <div style="margin-top:8px;display:flex;flex-direction:column;gap:5px">
          <div>🔐 <b>Codes 2FA</b> — connexion en 2 étapes</div>
          <div>👋 <b>Emails de bienvenue</b> — identifiants des nouveaux comptes</div>
          <div>🔑 <b>Renvoi d'identifiants</b> — réinitialisation de mot de passe</div>
          <div>🛡️ <b>Alertes sécurité</b> — tentatives bloquées</div>
          <div>⏰ <b>Alertes de péremption</b> — produits périmés / critiques</div>
        </div>
        <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border);color:var(--text3);font-size:.74rem;line-height:1.6">
          💡 <b>Expéditeur</b> = la boîte qui envoie (ex. <code>noreply@</code>, authentifiée en SMTP).<br>
          <b>Destinataire des alertes</b> = la boîte que vous lisez (ex. <code>support@</code>) — c'est là qu'arrivent les alertes sécurité.
        </div>
      </div>
    </div>

    <!-- Délivrabilité / anti-spam -->
    <div class="card" style="margin-bottom:16px" id="dns">
      <div class="card-title" style="margin-bottom:10px">🛡️ Délivrabilité (anti-spam)</div>
      <?php
        $dns_dom = $smtp['from'] ?: $smtp['user'];
        if (strpos($dns_dom, '@') !== false) { $dns_dom = substr(strrchr($dns_dom, '@'), 1); }
      ?>
      <div style="font-size:.78rem;color:var(--text2);margin-bottom:10px;line-height:1.6">
        Vérifie les enregistrements DNS de ton domaine d'envoi. Sans <b>SPF</b> et <b>DMARC</b> valides, tes codes 2FA et tes alertes risquent le dossier spam.
      </div>
      <form method="POST" style="display:flex;gap:6px;margin-bottom:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="check_dns">
        <input type="text" name="dns_domain" class="form-control" value="<?= h($dns_dom) ?>" placeholder="cadurix.fr" style="flex:1">
        <button type="submit" class="btn btn-ghost">Vérifier</button>
      </form>
      <?php if (!empty($_SESSION['dns_check'])): $d = $_SESSION['dns_check']; unset($_SESSION['dns_check']); ?>
      <div style="display:flex;flex-direction:column;gap:8px">
        <?php
          $items = [
            ['SPF',   $d['spf'],   'Autorise ton serveur à envoyer pour ce domaine.'],
            ['DKIM',  $d['dkim'],  'Signe tes emails (sélecteurs testés : default/dkim/mail...).'],
            ['DMARC', $d['dmarc'], 'Politique à appliquer si SPF/DKIM échoue.'],
          ];
          foreach ($items as $it): $ok = !empty($it[1]); ?>
          <div style="border:1px solid <?= $ok?'rgba(16,185,129,.3)':'rgba(245,158,11,.35)' ?>;background:<?= $ok?'rgba(16,185,129,.08)':'rgba(245,158,11,.08)' ?>;border-radius:8px;padding:9px 12px">
            <div style="font-weight:700;font-size:.82rem;color:<?= $ok?'#059669':'#b45309' ?>"><?= $ok?'✅':'⚠️' ?> <?= $it[0] ?> · <?= $ok?'présent':'absent' ?></div>
            <div style="font-size:.72rem;color:var(--text3);margin-top:2px"><?= h($it[2]) ?></div>
            <?php if ($ok): ?><div style="font-size:.68rem;color:var(--text3);margin-top:3px;font-family:var(--mono);word-break:break-all"><?= h(mb_substr($it[1], 0, 130)) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (empty($d['spf']) || empty($d['dmarc'])): ?>
        <div style="font-size:.72rem;color:var(--text3);margin-top:2px;line-height:1.5">💡 Ajoute les enregistrements manquants dans la zone DNS de <code><?= h($d['domain']) ?></code> (cPanel o2switch → Éditeur de zone DNS). Le DKIM s'active souvent via cPanel → « Authentification e-mail ».</div>
        <?php endif; ?>
        <?php if (!empty($d['dmarc']) && empty($d['dkim'])): ?>
        <div style="font-size:.72rem;color:var(--text3)">ℹ️ DKIM non détecté sur les sélecteurs courants — il peut exister sous un autre nom. Vérifie côté cPanel.</div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Envoi automatique info -->
    <div class="card" style="background:var(--primary-bg);border-color:rgba(var(--primary-rgb),.2)">
      <div style="display:flex;gap:10px">
        <i class="ti ti-clock" style="color:var(--primary);font-size:18px;flex-shrink:0;margin-top:2px"></i>
        <div style="min-width:0">
          <div style="font-size:.85rem;font-weight:600;margin-bottom:4px">Envoi automatique</div>
          <div style="font-size:.78rem;color:var(--text2);line-height:1.5">Pour automatiser les alertes, ajoutez cette commande a votre cron :</div>
          <code style="display:block;margin-top:6px;padding:6px 10px;background:var(--bg3);border-radius:6px;font-size:.72rem;color:var(--primary);word-break:break-all">0 8 * * 1 php <?= dirname(dirname($_SERVER['DOCUMENT_ROOT'])) ?>/cron/send_alerts_cron.php</code>
          <div style="font-size:.72rem;color:var(--text3);margin-top:4px">Chaque lundi a 8h</div>
        </div>
      </div>
    </div>

    </div>
    <div class="eml-col">
    <!-- Derniers emails envoyes -->
    <div class="card">
      <?php
        $eml_rows = false; $eml_total = 0;
        try {
          $eml_total = (int)$pdo->query("SELECT COUNT(*) FROM email_log")->fetchColumn();
          $eml_rows  = $pdo->query("SELECT type,destinataire,sujet,statut,methode,erreur,cree_le FROM email_log ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { $eml_rows = null; }
      ?>
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px">
        <div class="card-title" style="margin:0">📜 Derniers emails envoyes<?php if ($eml_total>0): ?> <span style="color:var(--text3);font-weight:600">· <?= $eml_total ?></span><?php endif; ?></div>
        <?php if ($eml_total>0): ?>
        <form method="POST" style="margin:0" onsubmit="return confirm('Vider tout l\'historique des emails ? Action irreversible.')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="clear_email_log">
          <button type="submit" class="btn btn-ghost btn-xs">🗑️ Vider</button>
        </form>
        <?php endif; ?>
      </div>
      <?php if ($eml_rows === null): ?>
        <div style="font-size:.78rem;color:var(--text3);line-height:1.5">Table <code>email_log</code> absente — execute le script SQL fourni pour activer l'historique.</div>
      <?php elseif (!$eml_rows): ?>
        <div style="font-size:.78rem;color:var(--text3)">Aucun email enregistre pour le moment.</div>
      <?php else: ?>
        <div class="eml-hist">
          <?php foreach ($eml_rows as $e): $ok = ($e['statut'] === 'ok'); ?>
          <div class="eml-hist-row">
            <span style="font-size:14px;flex-shrink:0"><?= $ok?'✅':'❌' ?></span>
            <div style="flex:1;min-width:0">
              <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($e['destinataire']) ?></div>
              <div style="color:var(--text3);font-size:.7rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($e['type']) ?><?= $e['methode'] ? ' · '.h($e['methode']) : '' ?><?= (!$ok && $e['erreur']) ? ' · '.h(mb_substr($e['erreur'],0,40)) : '' ?></div>
            </div>
            <div style="color:var(--text3);font-size:.7rem;white-space:nowrap;flex-shrink:0"><?= date('d/m H:i', strtotime($e['cree_le'])) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    </div>
  </div>
</div><!-- /tab-config -->

<script>
function emlTab(name){
  document.querySelectorAll('.eml-panel').forEach(p=>p.hidden=(p.id!=='tab-'+name));
  document.querySelectorAll('.eml-tab-btn').forEach(b=>b.classList.toggle('active', b.dataset.tab===name));
  try{ const u=new URL(location); u.searchParams.set('tab',name); history.replaceState(null,'',u); }catch(e){}
}
function toggleSmtp() {
  const f = document.getElementById('smtp-form');
  f.style.display = f.style.display === 'none' ? 'block' : 'none';
}

function selectAllDest(val) {
  document.querySelectorAll('input[name="dest_ids[]"]').forEach(el => el.checked = val);
}

let manualEmails = [];
function addManualEmail() {
  const inp = document.getElementById('manual-email');
  const email = inp.value.trim();
  if (!email || !email.includes('@')) return;
  if (manualEmails.includes(email)) { inp.value=''; return; }
  manualEmails.push(email);
  const list = document.getElementById('manual-list');
  const tag  = document.createElement('span');
  tag.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:3px 9px;background:var(--primary-bg);border:1px solid rgba(var(--primary-rgb),.3);border-radius:99px;font-size:.72rem;color:var(--primary)';
  tag.innerHTML = email + ' <button type="button" onclick="removeManual(this,\''+email+'\')" style="background:none;border:none;cursor:pointer;color:inherit;font-size:14px;padding:0;line-height:1">&times;</button>';

  // Ajouter un input hidden
  const hid = document.createElement('input');
  hid.type = 'hidden'; hid.name = 'dest_emails[]'; hid.value = email;
  tag.appendChild(hid);
  list.appendChild(tag);
  inp.value = '';
}
function removeManual(btn, email) {
  manualEmails = manualEmails.filter(e => e !== email);
  btn.closest('span').remove();
}
</script>

<?php if (!defined('ADMIN_HUB')) require_once '../includes/footer.php'; ?>
