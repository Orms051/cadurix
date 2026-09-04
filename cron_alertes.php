<?php
// © Cadurix v5.4 — Digest d'alertes HEBDOMADAIRE (médicaments + stupéfiants).
// CLI : php cron_alertes.php [site_id]
// Web : /cron_alertes.php?token=XXX[&site_id=X]   (jeton = parametres.cron_token)
// À planifier : 1x/semaine (lundi matin) via le cron o2switch.

if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/includes/auth.php';
    $token  = $_GET['token'] ?? '';
    $stored = $pdo->query("SELECT valeur FROM parametres WHERE cle='cron_token'")->fetchColumn();
    if (!$stored || !hash_equals((string)$stored, (string)$token)) { http_response_code(403); die('Accès refusé'); }
    header('Content-Type: text/plain; charset=utf-8');
} else {
    require_once __DIR__ . '/config/db.php';
}
require_once __DIR__ . '/includes/email_layout.php';
require_once __DIR__ . '/includes/mailer.php';

$target_site  = isset($argv[1]) ? (int)$argv[1] : (int)($_GET['site_id'] ?? 0);
// Sécurité de test : $dry = ne rien envoyer (affiche seulement) ; $target_email = n'envoyer qu'à cette adresse.
$dry          = in_array('dry', $argv, true) || (($_GET['dry'] ?? '') === '1');
$target_email = $argv[2] ?? ($_GET['email'] ?? null);

// Rend une liste de produits en tableau email-safe
$renderList = function (array $rows): string {
    if (!$rows) return '';
    $E = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eef2f7;border-radius:10px;border-collapse:separate;overflow:hidden;margin:4px 0 6px">';
    foreach ($rows as $i => $r) {
        $bb = $i < count($rows) - 1 ? 'border-bottom:1px solid #eef2f7;' : '';
        $h .= '<tr>'
            . '<td style="padding:9px 12px;'.$bb.'font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#1e293b"><strong>'.$E($r['nom']).'</strong>'
            . ($r['lot'] ? ' <span style="color:#94a3b8;font-size:12px">lot '.$E($r['lot']).'</span>' : '')
            . '<br><span style="color:#64748b;font-size:12px">'.$E($r['loc']).'</span></td>'
            . '<td style="padding:9px 12px;'.$bb.'font-family:Arial,Helvetica,sans-serif;font-size:12.5px;color:#475569;text-align:right;white-space:nowrap">'.$E($r['info']).'</td>'
            . '</tr>';
    }
    return $h . '</table>';
};

// Sites (tous les actifs, ou un seul)
if ($target_site) {
    $q = $pdo->prepare("SELECT s.*, o.nom AS org_nom FROM sites s JOIN organisations o ON o.id=s.organisation_id WHERE s.id=? AND s.actif=1");
    $q->execute([$target_site]); $sites = $q->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sites = $pdo->query("SELECT s.*, o.nom AS org_nom FROM sites s JOIN organisations o ON o.id=s.organisation_id WHERE s.actif=1 ORDER BY o.nom, s.nom")->fetchAll(PDO::FETCH_ASSOC);
}

$semaine = date('d/m/Y');
$nbEnvois = 0;

foreach ($sites as $site) {
    $sid = (int)$site['id'];

    // Destinataires : admins du site avec email
    $ad = $pdo->prepare("SELECT DISTINCT u.email, u.prenom FROM utilisateurs u JOIN user_site_permissions usp ON usp.user_id=u.id WHERE usp.site_id=? AND u.actif=1 AND u.email IS NOT NULL AND u.email<>'' AND usp.role IN ('administrateur','super_admin','gestionnaire')");
    $ad->execute([$sid]);
    $admins = $ad->fetchAll(PDO::FETCH_ASSOC);
    if (!$admins) continue;

    // ── Médicaments ──
    $mkList = function (string $extra, callable $info) use ($pdo, $sid): array {
        $st = $pdo->prepare("SELECT p.nom, p.numero_lot AS lot, p.date_peremption, p.quantite, p.quantite_min, p.unite,
                                    e.nom AS empl_nom, ep.nom AS empl_parent
                             FROM produits p JOIN emplacements e ON e.id=p.emplacement_id
                             LEFT JOIN emplacements ep ON ep.id=e.parent_id
                             WHERE e.site_id=? AND p.statut='actif' AND $extra
                             ORDER BY p.date_peremption ASC LIMIT 20");
        $st->execute([$sid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['nom'=>$r['nom'], 'lot'=>$r['lot'],
                      'loc'=>($r['empl_parent'] ? $r['empl_parent'].' › ' : '').$r['empl_nom'],
                      'info'=>$info($r)];
        }
        return $out;
    };
    $perimes   = $mkList("p.date_peremption < CURDATE()", fn($r) => 'Périmé le '.date('d/m/Y', strtotime($r['date_peremption'])));
    $critiques = $mkList("DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 7", fn($r) => 'Périme dans '.(int)((strtotime($r['date_peremption'])-time())/86400).' j');
    $stockBas  = $mkList("p.quantite_min>0 AND p.quantite<=p.quantite_min", fn($r) => 'Qté '.rtrim(rtrim(number_format((float)$r['quantite'],2,'.',''),'0'),'.').' / min '.$r['quantite_min']);

    // ── Stupéfiants (coffre) ──
    $stupNote = '';
    try {
        $solde = (float)$pdo->query("SELECT COALESCE(SUM(p.quantite),0) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.stupefiant=1 AND e.type='coffre' AND e.site_id=$sid")->fetchColumn();
        if ($solde > 0) {
            $last = $pdo->query("SELECT MAX(sc.cree_le) FROM stupefiants_controles sc JOIN produits p ON p.id=sc.produit_id JOIN emplacements e ON e.id=p.emplacement_id WHERE e.type='coffre' AND e.site_id=$sid")->fetchColumn();
            $days = $last ? floor((time()-strtotime($last))/86400) : null;
            if ($days === null)      $stupNote = "🔒 Le coffre à stupéfiants (solde ".rtrim(rtrim(number_format($solde,2,'.',''),'0'),'.')." ampoule(s)) n'a <b>jamais</b> été contrôlé — contrôle recommandé.";
            elseif ($days > 30)      $stupNote = "🔒 Le coffre à stupéfiants n'a pas été contrôlé depuis <b>".(int)$days." jours</b> — contrôle recommandé.";
        }
    } catch (Throwable $e) {}

    $nbA = count($perimes) + count($critiques) + count($stockBas) + ($stupNote ? 1 : 0);

    // ── Corps de l'email ──
    $c = '<p style="margin:0 0 6px">Voici le point hebdomadaire des alertes pour <strong>'.htmlspecialchars($site['nom']).'</strong>.</p>';
    if ($nbA === 0) {
        $c .= cdx_email_note('✅ Rien à signaler cette semaine — tout est en ordre.', 'ok');
    } else {
        if ($perimes)   { $c .= cdx_email_note('🔴 <b>'.count($perimes).'</b> produit(s) <b>périmé(s)</b> à retirer :', 'danger') . $renderList($perimes); }
        if ($critiques) { $c .= cdx_email_note('⚠️ <b>'.count($critiques).'</b> produit(s) <b>critique(s)</b> (≤ 7 jours) :', 'warn') . $renderList($critiques); }
        if ($stockBas)  { $c .= cdx_email_note('📦 <b>'.count($stockBas).'</b> produit(s) en <b>stock bas</b> :', 'info') . $renderList($stockBas); }
        if ($stupNote)  { $c .= cdx_email_note($stupNote, 'warn'); }
    }

    $preheader = $nbA > 0 ? ($nbA.' alerte(s) à traiter cette semaine') : 'Rien à signaler cette semaine';
    $html = cdx_email_layout(
        $preheader,
        'Alertes hebdomadaires — semaine du '.$semaine,
        $c,
        ['text' => 'Ouvrir les alertes', 'url' => 'https://cadurix.fr/alerts.php'],
        'Vous recevez ce message car vous êtes responsable de ce site.',
        $nbA > 0 ? '#ef4444' : '#16a34a'
    );
    $subject = ($nbA > 0 ? '🔔 ' : '✅ ') . 'Alertes Cadurix — ' . $site['nom'] . ' (semaine du ' . $semaine . ')';

    // Destinataires finaux : soit une adresse de test unique, soit les admins du site.
    $envois = $target_email ? [['email' => $target_email, 'prenom' => '']] : $admins;
    foreach ($envois as $a) {
        if ($dry) { echo "🧪 [dry] $subject → {$a['email']} — $nbA alerte(s)\n"; continue; }
        $ok = cadurix_send_mail($pdo, $a['email'], (string)($a['prenom'] ?? ''), $subject, $html, 'alerte_hebdo');
        $nbEnvois += $ok ? 1 : 0;
        if (php_sapi_name() === 'cli') echo ($ok ? '✅' : '❌') . " {$a['email']} ({$site['nom']}) — $nbA alerte(s)\n";
    }
}

echo $dry ? "Terminé (mode test, aucun envoi).\n" : "Terminé — $nbEnvois email(s) envoyé(s).\n";
