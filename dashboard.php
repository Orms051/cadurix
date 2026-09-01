<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.
require_once 'includes/auth.php';
$pageTitle = 'Tableau de bord';

// ── Site actif ───────────────────────────────────────────
$sites_user = sites_accessibles($pdo);
if (isset($_GET['site_id'])) {
    definir_site_actif((int)$_GET['site_id'], $pdo);
    echo '<script>window.location.href="dashboard.php";</script>'; exit;
}
if (!site_actif() && !empty($sites_user)) definir_site_actif($sites_user[0]['id'], $pdo);
$site_id = site_actif();

// ── Données ──────────────────────────────────────────────
$total=$expired=$critical=$warning=$lowStock=$emplCount=0;
$urgent=$chart_mois=$recent_remp=$recent_fiches=$recent_inter=[];
$biomed_hs=$biomed_maint=$biomed_total=0;
$sse_alerte=$sse_conso_perime=0;
$rappels_actifs=[];
$msg_non_lus=0;
$dot_sacs=[]; $dot_prets=0; $dot_incomplets=0;

if ($site_id) {
    $fs = "e.site_id=".intval($site_id);
    $fe = sql_filtre_emplacements('e');

    // ── Produits médicaux ──
    $total    = (int)$pdo->query("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND $fs AND $fe")->fetchColumn();
    $expired  = (int)$pdo->query("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND p.date_peremption < CURDATE() AND $fs AND $fe")->fetchColumn();
    $critical = (int)$pdo->query("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 7 AND $fs AND $fe")->fetchColumn();
    $warning  = (int)$pdo->query("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 8 AND 30 AND $fs AND $fe")->fetchColumn();
    $lowStock = (int)$pdo->query("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND p.quantite_min>0 AND p.quantite<=p.quantite_min AND $fs AND $fe")->fetchColumn();
    $emplCount= (int)$pdo->query("SELECT COUNT(*) FROM emplacements e WHERE e.actif=1 AND $fs AND $fe")->fetchColumn();

    // ── Biomédical ──
    try {
        $biomed_total = (int)$pdo->query("SELECT COUNT(*) FROM materiels WHERE site_id=".intval($site_id)." AND statut NOT IN ('reforme','archive')")->fetchColumn();
        $biomed_hs    = (int)$pdo->query("SELECT COUNT(*) FROM materiels WHERE site_id=".intval($site_id)." AND statut='hors_service'")->fetchColumn();
        $biomed_maint = (int)$pdo->query("SELECT COUNT(*) FROM materiels WHERE site_id=".intval($site_id)." AND statut='maintenance'")->fetchColumn();
    } catch(Exception $e){}


    // ── SSE/NRBC ──
    try {
        $sse_alerte = (int)$pdo->prepare("SELECT COUNT(*) FROM sse_consommables WHERE site_id=? AND statut='actif' AND (date_peremption<CURDATE() OR quantite<=quantite_min)")->execute([$site_id]) ? (int)$pdo->prepare("SELECT COUNT(*) FROM sse_consommables WHERE site_id=? AND statut='actif' AND (date_peremption<CURDATE() OR quantite<=quantite_min)")->execute([$site_id]) : 0;
        $s_conso = $pdo->prepare("SELECT COUNT(*) FROM sse_consommables WHERE site_id=? AND statut='actif' AND date_peremption<CURDATE()");
        $s_conso->execute([$site_id]);
        $sse_conso_perime = (int)$s_conso->fetchColumn();
        $s_sse2 = $pdo->prepare("SELECT COUNT(*) FROM sse_consommables WHERE site_id=? AND statut='actif' AND (date_peremption<CURDATE() OR quantite<=quantite_min)");
        $s_sse2->execute([$site_id]);
        $sse_alerte = (int)$s_sse2->fetchColumn();
    } catch(Exception $e){}

    // ── Rappels de lots actifs ──
    try {
        $r_stmt = $pdo->prepare("SELECT * FROM lots_rappels WHERE site_id=? AND actif=1 ORDER BY date_rappel DESC LIMIT 5");
        $r_stmt->execute([$site_id]);
        $rappels_actifs = $r_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e){}

    // ── Messages non lus ──
    try {
        $uid = currentUserId();
        $m_stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (destinataire_id=? OR destinataire_id IS NULL) AND expediteur_id!=? AND lu=0 AND brouillon=0");
        $m_stmt->execute([$uid,$uid]);
        $msg_non_lus = (int)$m_stmt->fetchColumn();
    } catch(Exception $e){}

    // ── Produits urgents ──
    $urgent = $pdo->query("
        SELECT p.nom, p.date_peremption, p.quantite, p.quantite_min,
               e.id AS empl_id, e.nom AS empl_nom, ep.nom AS empl_parent,
               DATEDIFF(p.date_peremption, CURDATE()) AS jours
        FROM produits p
        JOIN emplacements e ON e.id=p.emplacement_id
        LEFT JOIN emplacements ep ON ep.id=e.parent_id
        WHERE p.statut='actif' AND $fs AND $fe
          AND (p.date_peremption <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            OR (p.quantite_min>0 AND p.quantite<=p.quantite_min))
        ORDER BY p.date_peremption ASC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ── Graphique péremptions ──
    for ($i = 0; $i <= 11; $i++) {
        $debut = date('Y-m-01', strtotime("+$i months"));
        $fin   = date('Y-m-t',  strtotime("+$i months"));
        $nb = (int)$pdo->query("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND $fs AND $fe AND p.date_peremption BETWEEN '$debut' AND '$fin'")->fetchColumn();
        $chart_mois[] = ['label'=>date('M', strtotime("+$i months")), 'nb'=>$nb, 'mois'=>$i];
    }

    // ── Activité récente ──
    try {
        $recent_remp = $pdo->query("SELECT r.cree_le, e.nom AS empl_nom, u.prenom, COUNT(ra.id) AS nb FROM rempotages r LEFT JOIN emplacements e ON e.id=r.emplacement_id LEFT JOIN utilisateurs u ON u.id=r.user_id LEFT JOIN rempotage_articles ra ON ra.rempotage_id=r.id WHERE r.site_id=".intval($site_id)." GROUP BY r.id ORDER BY r.cree_le DESC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e){}
    try {
        $recent_fiches = $pdo->query("SELECT f.cree_le, m.nom AS modele_nom, u.prenom, f.statut FROM fiches_remplies f JOIN modeles_fiches m ON m.id=f.modele_id JOIN utilisateurs u ON u.id=f.user_id WHERE f.site_id=".intval($site_id)." ORDER BY f.cree_le DESC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e){}
    try {
        $recent_inter = $pdo->query("SELECT im.cree_le, im.reference, im.statut, u.prenom, COUNT(il.id) AS nb FROM interventions_materiel im JOIN utilisateurs u ON u.id=im.user_id LEFT JOIN interventions_lignes il ON il.intervention_id=im.id WHERE im.site_id=".intval($site_id)." GROUP BY im.id ORDER BY im.cree_le DESC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e){}

    // ── Cockpit dotation « prêt à partir » ──
    if (!function_exists('action_permise') || action_permise('dotation_voir')) {
        try {
            require_once __DIR__ . '/includes/dotation.php';
            $dot_sacs       = dotation_cockpit($pdo, (int)$site_id);
            $dot_prets      = count(array_filter($dot_sacs, fn($s) => $s['completude'] >= 100));
            $dot_incomplets = count($dot_sacs) - $dot_prets;
        } catch (Throwable $e) {}
    }
}

$site_info = null;
if ($site_id) {
    $s = $pdo->prepare("SELECT s.*,o.nom AS org_nom FROM sites s JOIN organisations o ON o.id=s.organisation_id WHERE s.id=?");
    $s->execute([$site_id]);
    $site_info = $s->fetch(PDO::FETCH_ASSOC);
}

$score_global = 100;
if ($expired > 0) $score_global -= 30;
if ($critical > 0) $score_global -= 15;
if ($biomed_hs > 0) $score_global -= 15;
if (!empty($rappels_actifs)) $score_global -= 20;
$score_global = max(0, $score_global);
$score_color = $score_global >= 80 ? '#34d399' : ($score_global >= 60 ? '#fbbf24' : '#f87171');
$score_label = $score_global >= 80 ? 'Bon' : ($score_global >= 60 ? 'Moyen' : 'Critique');

// ── Date en français (sans dépendre de setlocale, peu fiable sur mutualisé) ──
$jours_fr = ['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi'];
$mois_fr  = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
$date_fr  = $jours_fr[(int)date('w')].' '.(int)date('j').' '.$mois_fr[(int)date('n')].' '.date('Y');

// ══════════════════════════════════════════════════════════
//  AJAX (avant tout affichage) — perso du tableau de bord + Copilote IA
// ══════════════════════════════════════════════════════════
$_KNOWN_W = ['ai','todo','kpi','surv','chart','meteo','conso','ready','act','agenda','stup','biomed'];

// ── Sauvegarde de la disposition personnalisée ──
if (($_GET['ajax'] ?? '') === 'save_layout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!function_exists('csrf_verifier') || !csrf_verifier()) { echo json_encode(['error'=>'csrf']); exit; }
    $order  = json_decode($_POST['order']  ?? '[]', true); if (!is_array($order))  $order  = [];
    $hidden = json_decode($_POST['hidden'] ?? '[]', true); if (!is_array($hidden)) $hidden = [];
    $order  = array_values(array_intersect($order,  $_KNOWN_W));
    $hidden = array_values(array_intersect($hidden, $_KNOWN_W));
    $density = (($_POST['density'] ?? 'cosy') === 'compact') ? 'compact' : 'cosy';
    $layout = json_encode(['order'=>$order,'hidden'=>$hidden,'density'=>$density], JSON_UNESCAPED_UNICODE);
    try {
        $pdo->prepare("INSERT INTO user_dashboard_prefs (user_id,layout,updated_le) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE layout=VALUES(layout), updated_le=NOW()")
            ->execute([currentUserId(), $layout]);
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) { echo json_encode(['error'=>'db']); }
    exit;
}

// ── Copilote IA : résumé du jour + questions (ne voit que les données autorisées) ──
if (($_GET['ajax'] ?? '') === 'copilote') {
    header('Content-Type: application/json');
    $ai_actif = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='actif'")->fetchColumn();
    $api_key  = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='api_key'")->fetchColumn();
    $model    = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='model'")->fetchColumn() ?: 'claude-haiku-4-5-20251001';
    if ($ai_actif !== '1' || !$api_key) { echo json_encode(['error'=>"IA non configurée (Admin → IA)."]); exit; }
    $canReg = ($_SESSION['super_admin'] ?? false) ? true : false;
    if (!$canReg) { try { $rr=$pdo->prepare("SELECT niveau FROM role_permissions WHERE role=? AND module='stupefiants_registre'"); $rr->execute([role_sur_site()]); $canReg=((int)$rr->fetchColumn())>=1; } catch(Throwable $e){} }
    $ctx  = "Site : ".($site_info['org_nom'] ?? '')." — ".($site_info['nom'] ?? '').". Score opérationnel : {$score_global}%.\n";
    $ctx .= "Produits en stock : $total. Périmés : $expired. Critiques (<=7j) : $critical. A <=30j : $warning. Stock bas : $lowStock.\n";
    if (!empty($rappels_actifs)) $ctx .= "Rappels ANSM actifs : ".count($rappels_actifs).".\n";
    $ctx .= "Sacs de dotation incomplets : $dot_incomplets.\n";
    if ($urgent) { $ctx .= "Produits urgents :\n"; foreach (array_slice($urgent,0,8) as $u){ $j=(int)($u['jours']??0); $ctx .= "- ".$u['nom']." (".($u['empl_parent']?$u['empl_parent'].' > ':'').$u['empl_nom'].") : ".($j<0?'perime':($j.'j')).".\n"; } }
    if ($biomed_hs>0) $ctx .= "Biomedical hors service : $biomed_hs.\n";
    if ($canReg && $site_id) { try { $cs=$pdo->query("SELECT COALESCE(SUM(p.quantite),0) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.stupefiant=1 AND e.type='coffre' AND e.site_id=".intval($site_id))->fetchColumn(); $ctx .= "Coffre stupefiants : ".(float)$cs." ampoule(s).\n"; } catch(Throwable $e){} }
    $q = trim((string)($_POST['q'] ?? ''));
    if ($q !== '') {
        $prompt = "Tu es le copilote de Cadurix (gestion de materiel medical SDIS/SSSM). Reponds en francais, court et concret, UNIQUEMENT a partir des donnees ci-dessous (n'invente rien, pas de conseil medical, pas de markdown).\n\nDONNEES :\n$ctx\nQUESTION : $q";
        $maxtok = 400;
    } else {
        $prompt = "Tu es le copilote de Cadurix (gestion de materiel medical SDIS/SSSM). A partir des donnees ci-dessous, redige un resume du jour en francais : 1 phrase d'intro puis 3 a 5 points d'attention concrets et priorises. Court, professionnel, sans markdown, sans rien inventer, pas de conseil medical.\n\nDONNEES :\n$ctx";
        $maxtok = 500;
    }
    $payload = ['model'=>$model,'max_tokens'=>$maxtok,'messages'=>[['role'=>'user','content'=>$prompt]]];
    $is_prod = str_contains($_SERVER['HTTP_HOST'] ?? '', 'cadurix.fr');
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-api-key: '.$api_key,'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT=>40,CURLOPT_SSL_VERIFYPEER=>$is_prod,CURLOPT_SSL_VERIFYHOST=>$is_prod?2:0]);
    $resp=curl_exec($ch); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); $cerr=curl_error($ch); curl_close($ch);
    if ($resp===false||$cerr){ echo json_encode(['error'=>"Reseau IA : $cerr"]); exit; }
    $jr=json_decode($resp,true);
    if ($http!==200||empty($jr['content'][0]['text'])){ echo json_encode(['error'=>($jr['error']['message']??"Erreur IA HTTP $http")]); exit; }
    echo json_encode(['ok'=>true,'texte'=>$jr['content'][0]['text']], JSON_UNESCAPED_UNICODE); exit;
}

require_once 'includes/header.php';
?>
<style>
/* ── Dashboard layout ── */
.db-hero     { display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px;flex-wrap:wrap; }
.db-score    { display:flex;align-items:center;gap:14px;background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:14px 20px; }
.db-score-circle { width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:800;flex-shrink:0; }

.db-kpi-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px; }
.db-kpi      { background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:14px 16px;border-top:3px solid transparent;transition:all .15s;text-decoration:none;display:block; }
.db-kpi:hover{ transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.15); }
.db-kpi-n    { font-size:1.8rem;font-weight:800;line-height:1.1;font-variant-numeric:tabular-nums; }
.db-kpi-l    { font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);margin-top:4px; }
.db-kpi-icon { font-size:1.1rem;margin-bottom:8px; }

.db-section-title { font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--text3);margin-bottom:10px;display:flex;align-items:center;gap:6px; }
.db-section-title::after { content:'';flex:1;height:1px;background:var(--border); }

.db-cols     { display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start; }
@media(max-width:900px){ .db-kpi-grid{grid-template-columns:repeat(2,1fr)} .db-cols{grid-template-columns:1fr} }

.db-card     { background:var(--bg2);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:14px; }
.db-card-head{ padding:11px 16px;background:var(--bg3);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between; }
.db-card-title{ font-size:.78rem;font-weight:700; }

.db-row      { padding:.65rem 1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem; }
.db-row:last-child { border-bottom:none; }
.db-row:hover { background:var(--glass); }
.db-dot      { width:8px;height:8px;border-radius:50%;flex-shrink:0; }

/* ── Urgent table ── */
.db-urg-table{ width:100%;border-collapse:collapse; }
.db-urg-table th{ padding:7px 12px;font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);border-bottom:1px solid var(--border);text-align:left;background:var(--bg3); }
.db-urg-table td{ padding:9px 12px;border-bottom:1px solid var(--border);font-size:.83rem;vertical-align:middle; }
.db-urg-table tr:last-child td{ border-bottom:none; }
.db-urg-table tr:hover td{ background:var(--glass); }

/* ── Conformité bar ── */
.conf-wrap   { display:flex;align-items:center;gap:10px; }
.conf-bar    { flex:1;height:8px;background:var(--border);border-radius:4px;overflow:hidden; }
.conf-fill   { height:100%;border-radius:4px;transition:width .5s; }

/* ── Rappels ── */
.rappel-strip{ background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:10px; }

/* ── À traiter maintenant ── */
.db-todo{ background:var(--bg2);border:1px solid var(--border);border-left:4px solid #ef4444;border-radius:14px;overflow:hidden;margin-bottom:22px; }
.db-todo-head{ display:flex;align-items:center;gap:10px;padding:12px 16px;background:rgba(239,68,68,.08); }
.db-todo-head .t{ font-weight:800;font-size:.95rem; }
.db-todo-head .c{ margin-left:auto;font-size:.72rem;font-weight:700;color:#ef4444;background:var(--bg2);border:1px solid var(--border);border-radius:20px;padding:2px 10px; }
.db-todo-item{ display:flex;align-items:center;gap:13px;padding:12px 16px;border-top:1px solid var(--border);text-decoration:none;color:var(--text);transition:background .12s; }
.db-todo-item:hover{ background:var(--glass); }
.db-todo-ic{ width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0; }
.db-todo-tx{ flex:1;min-width:0; }
.db-todo-tt{ font-weight:700;font-size:.9rem; }
.db-todo-sb{ font-size:.76rem;color:var(--text2);margin-top:1px; }
.db-todo-go{ font-size:.78rem;font-weight:700;color:var(--primary);white-space:nowrap; }

/* ── Graphique ── */
.db-chart    { display:flex;align-items:flex-end;gap:5px;height:80px; }
.db-bar      { flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;height:100%; }
.db-bar-fill { width:100%;border-radius:3px 3px 0 0;transition:opacity .2s;cursor:default; }
.db-bar-lbl  { font-size:.55rem;color:var(--text3);text-align:center; }
</style>

<?php if (!$site_id): ?>
<div style="text-align:center;padding:4rem;color:var(--text2)">
  <div style="font-size:3rem;margin-bottom:1rem">🏥</div>
  <div style="font-size:1.1rem;font-weight:600">Aucun site sélectionné</div>
</div>
<?php require_once 'includes/footer.php'; exit; ?>
<?php endif; ?>

<!-- Sélecteur site (si plusieurs) -->
<?php if (count($sites_user) > 1): ?>
<div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap">
  <?php foreach ($sites_user as $s): ?>
  <a href="?site_id=<?= $s['id'] ?>" style="padding:5px 14px;border-radius:99px;font-size:.78rem;font-weight:600;text-decoration:none;border:1px solid <?= $s['id']==$site_id?'var(--primary)':'var(--border)' ?>;background:<?= $s['id']==$site_id?'var(--primary-bg)':'transparent' ?>;color:<?= $s['id']==$site_id?'var(--primary)':'var(--text2)' ?>"><?= h($s['nom']) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════
     DASHBOARD MOBILE — Tap & Go
════════════════════════════════════════════════════ -->
<style>
.m-site-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.m-site-name{font-size:1.15rem;font-weight:800;color:var(--text);line-height:1.2}
.m-site-org{font-size:.72rem;color:var(--text3);margin-top:2px}
.m-score-pill{display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:99px;font-size:.78rem;font-weight:700;border:1px solid}
.m-alert-banner{display:flex;align-items:center;gap:10px;padding:12px 14px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);border-radius:14px;margin-bottom:16px;text-decoration:none;color:#dc2626;font-size:.875rem;font-weight:600}
.m-alert-banner .m-alert-icon{font-size:1.3rem;flex-shrink:0}
.m-kpi-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.m-kpi{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:14px;text-decoration:none;display:block;border-top:3px solid transparent;transition:transform .15s;-webkit-tap-highlight-color:transparent}
.m-kpi:active{transform:scale(.97)}
.m-kpi-icon{font-size:1.1rem;margin-bottom:6px}
.m-kpi-val{font-size:1.6rem;font-weight:800;line-height:1;font-variant-numeric:tabular-nums}
.m-kpi-lbl{font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);margin-top:4px;font-weight:600}
.m-section-title{font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--text3);margin:18px 0 10px;display:flex;align-items:center;gap:8px}
.m-section-title::after{content:'';flex:1;height:1px;background:var(--border)}
.m-activity-list{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.m-activity-item{display:flex;align-items:center;gap:12px;padding:12px 14px;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text);transition:background .15s;-webkit-tap-highlight-color:transparent}
.m-activity-item:last-child{border-bottom:none}
.m-activity-item:active{background:var(--bg3)}
.m-activity-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.m-activity-info{flex:1;min-width:0}
.m-activity-name{font-size:.85rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text)}
.m-activity-sub{font-size:.72rem;color:var(--text3);margin-top:1px}
.m-activity-meta{font-size:.7rem;color:var(--text3);flex-shrink:0}
.mobile-actions-grid{display:grid !important;grid-template-columns:1fr 1fr !important;gap:10px;margin-bottom:20px}
.mobile-action-btn{display:flex !important;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:18px 10px;background:var(--bg2);border:1px solid var(--border);border-radius:14px;text-decoration:none;color:var(--text);font-weight:700;font-size:.8rem;text-align:center;min-height:88px;cursor:pointer;transition:all .15s;-webkit-tap-highlight-color:transparent}
.mobile-action-btn:active{transform:scale(.95);background:var(--bg3)}
.mobile-action-btn .mab-icon{font-size:1.75rem;line-height:1}
.mobile-action-btn .mab-label{font-size:.7rem;color:var(--text3);font-weight:500}
.mobile-action-btn.mab-primary{grid-column:1/-1;flex-direction:row !important;justify-content:flex-start !important;gap:14px;padding:16px 18px;min-height:64px;background:rgba(59,130,246,.1);border-color:rgba(59,130,246,.3);color:var(--primary)}
.mobile-action-btn.mab-primary .mab-icon{font-size:1.5rem}
.mobile-action-btn.mab-primary .mab-title{font-size:.9rem;font-weight:700;display:block}
.mobile-action-btn.mab-primary .mab-label{color:var(--primary);opacity:.7;font-size:.75rem;display:block}
.mobile-action-btn.mab-primary .mab-text{text-align:left}
.mobile-action-btn.mab-admin{background:rgba(100,116,139,.12);border-color:rgba(100,116,139,.32);color:var(--text)}
.mobile-action-btn.mab-admin .mab-title{color:var(--text)}
.mobile-action-btn.mab-admin .mab-label{color:var(--text3)}
/* Tuiles d'accès rapide (mobile) */
.m-tiles{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px}
.m-tile{position:relative;display:flex;flex-direction:column;padding:16px 14px;border-radius:18px;text-decoration:none;background:var(--bg2);border:1px solid var(--border);border-top:3px solid var(--tile-c,var(--border));overflow:hidden;-webkit-tap-highlight-color:transparent;transition:transform .12s}
.m-tile:active{transform:scale(.96)}
.m-tile::before{content:'';position:absolute;inset:0;background:var(--tile-c,transparent);opacity:.09;pointer-events:none}
.m-tile>*{position:relative;z-index:1}
.m-tile-ic{font-size:1.9rem;line-height:1;margin-bottom:9px;filter:drop-shadow(0 2px 4px rgba(0,0,0,.18))}
.m-tile-t{font-size:.95rem;font-weight:800;color:var(--text);line-height:1.15}
.m-tile-s{font-size:.72rem;color:var(--text3);font-weight:500;margin-top:2px}
.m-tile-badge{position:absolute;top:10px;right:10px;min-width:20px;height:20px;padding:0 6px;border-radius:99px;background:#ef4444;color:#fff;font-size:.7rem;font-weight:800;display:flex;align-items:center;justify-content:center;z-index:2}
.t-stup{--tile-c:#ef4444}
.t-remp{--tile-c:#3b82f6}
.t-fiche{--tile-c:#10b981}
.t-scan{--tile-c:#8b5cf6}
.t-msg{--tile-c:#0ea5e9}
.t-admin{--tile-c:#64748b}
</style>
<div class="mobile-only">

  <!-- Header site + score -->
  <?php if ($site_info): ?>
  <div class="m-site-header">
    <div>
      <div class="m-site-name"><?= h($site_info['nom']) ?></div>
      <div class="m-site-org"><?= h($site_info['org_nom']) ?> · <?= date('d/m/Y') ?></div>
    </div>
    <div class="m-score-pill" style="background:<?= $score_color ?>18;border-color:<?= $score_color ?>44;color:<?= $score_color ?>">
      <span style="font-size:.85rem">●</span> <?= $score_global ?>%
    </div>
  </div>
  <?php endif; ?>

  <!-- KPIs : Médicaments + Alertes (accès Médicaments requis) -->
  <?php if (nav_peut($nav_perms,'medicaments')): ?>
  <div class="m-kpi-row">
    <a href="/products.php" class="m-kpi" style="border-top-color:#10b981">
      <div class="m-kpi-icon">💊</div>
      <div class="m-kpi-val" style="color:#10b981"><?= $total ?></div>
      <div class="m-kpi-lbl">Médicaments</div>
    </a>
    <a href="/products.php?filtre=critique" class="m-kpi" style="border-top-color:<?= ($expired+$critical)>0?'#ef4444':'#10b981' ?>">
      <div class="m-kpi-icon"><?= ($expired+$critical)>0?'⚠️':'✅' ?></div>
      <div class="m-kpi-val" style="color:<?= ($expired+$critical)>0?'#f87171':'#34d399' ?>"><?= $expired+$critical ?></div>
      <div class="m-kpi-lbl">Alertes</div>
    </a>
  </div>
  <?php endif; ?>

  <!-- Accès rapide -->
  <div class="m-section-title">Accès rapide</div>
  <div class="m-tiles">

    <?php if (aff_mob('stupefiants')): ?>
    <a href="/stupefiants.php" class="m-tile t-stup" onclick="cdxTrack&&cdxTrack('stupefiants')">
      <span class="m-tile-ic">🔒</span>
      <span class="m-tile-t">Stupéfiants</span>
      <span class="m-tile-s">Coffre &amp; registre</span>
    </a>
    <?php endif; ?>

    <?php if (aff_mob('rempotages')): ?>
    <a href="/rempotage.php" class="m-tile t-remp" onclick="cdxTrack&&cdxTrack('rempotages')">
      <span class="m-tile-ic">🔄</span>
      <span class="m-tile-t">Rempotage</span>
      <span class="m-tile-s">Refaire un sac</span>
    </a>
    <?php endif; ?>

    <?php if (aff_mob('fiches')): ?>
    <a href="/fiches.php" class="m-tile t-fiche" onclick="cdxTrack&&cdxTrack('fiches')">
      <span class="m-tile-ic">🚒</span>
      <span class="m-tile-t">Contrôle véhicule</span>
      <span class="m-tile-s">Fiches de vérification</span>
    </a>
    <?php endif; ?>

    <?php if (aff_mob('scanner')): ?>
    <a href="/scan.php" class="m-tile t-scan" onclick="cdxTrack&&cdxTrack('scanner')">
      <span class="m-tile-ic">📷</span>
      <span class="m-tile-t">Scanner</span>
      <span class="m-tile-s">DataMatrix · EAN · QR</span>
    </a>
    <?php endif; ?>

    <?php if (aff_mob('messagerie')): ?>
    <a href="/messaging.php" class="m-tile t-msg" onclick="cdxTrack&&cdxTrack('messagerie')">
      <?php if ($msg_non_lus>0): ?><span class="m-tile-badge"><?= $msg_non_lus ?></span><?php endif; ?>
      <span class="m-tile-ic">💬</span>
      <span class="m-tile-t">Messages</span>
      <span class="m-tile-s"><?= $msg_non_lus>0 ? $msg_non_lus.' non lu(s)' : 'Messagerie' ?></span>
    </a>
    <?php endif; ?>

    <?php if ((isAdmin() || nav_peut($nav_perms,'admin')) && (($aff_map['admin']['mobile'] ?? 1) == 1)): ?>
    <a href="/admin/" class="m-tile t-admin" onclick="cdxTrack&&cdxTrack('admin')">
      <span class="m-tile-ic">⚙️</span>
      <span class="m-tile-t">Administration</span>
      <span class="m-tile-s">Gestion du système</span>
    </a>
    <?php endif; ?>

  </div>


</div><!-- /mobile-only -->

<!-- ── Contenu desktop uniquement (masqué sur mobile) ── -->
<div class="desktop-only">
<?php
// ══════════ Données supplémentaires (widgets pro) ══════════
$m7=$m30=$m90=0; $conso=[];
if ($site_id && nav_peut($nav_perms,'medicaments')) {
  $m7  = (int)$pdo->query("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 7 AND $fs AND $fe")->fetchColumn();
  $m30 = (int)$pdo->query("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 30 AND $fs AND $fe")->fetchColumn();
  $m90 = (int)$pdo->query("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 90 AND $fs AND $fe")->fetchColumn();
  try { $conso = $pdo->query("SELECT p.nom, SUM(ABS(ms.quantite_delta)) AS q FROM mouvements_stock ms JOIN produits p ON p.id=ms.produit_id JOIN emplacements e ON e.id=p.emplacement_id WHERE ms.type='sortie' AND ms.cree_le >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND $fs AND $fe GROUP BY p.nom ORDER BY q DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
}
$conso_max = 1; foreach ($conso as $c) $conso_max = max($conso_max,(float)$c['q']);

// ── Stupéfiants (gaté) ──
$dbStup = nav_peut($nav_perms,'stupefiants');
$dbStupReg = false;
if ($dbStup) { if (($_SESSION['super_admin']??false)) { $dbStupReg=true; } else { try { $rr=$pdo->prepare("SELECT niveau FROM role_permissions WHERE role=? AND module='stupefiants_registre'"); $rr->execute([role_sur_site()]); $dbStupReg=((int)$rr->fetchColumn())>=1; } catch(Throwable $e){} } }
$stx_solde=0; $stx_ctl=null; $stx_mv=[];
if ($dbStup && $site_id) {
  try { $stx_solde=(float)$pdo->query("SELECT COALESCE(SUM(p.quantite),0) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.stupefiant=1 AND e.type='coffre' AND e.site_id=".intval($site_id))->fetchColumn(); } catch(Throwable $e){}
  try { $stx_ctl=$pdo->query("SELECT MAX(sc.cree_le) FROM stupefiants_controles sc JOIN produits p ON p.id=sc.produit_id JOIN emplacements e ON e.id=p.emplacement_id WHERE e.type='coffre' AND e.site_id=".intval($site_id))->fetchColumn(); } catch(Throwable $e){}
  if ($dbStupReg) { try { $stx_mv=$pdo->query("SELECT sr.type, sr.qte_ampoules, sr.cree_le, TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) AS agent FROM stupefiants_registre sr JOIN produits p ON p.id=sr.produit_id JOIN emplacements e ON e.id=p.emplacement_id LEFT JOIN utilisateurs u ON u.id=sr.user_id WHERE e.type='coffre' AND e.site_id=".intval($site_id)." ORDER BY sr.cree_le DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){} }
}
$stx_ctl_days = $stx_ctl ? floor((time()-strtotime($stx_ctl))/86400) : null;

// ── Échéancier des contrôles ──
$agenda=[];
if ($dbStup) {
  if ($stx_ctl===null && $stx_solde>0) $agenda[]=['ic'=>'🔒','t'=>'Coffre à stupéfiants','s'=>'Aucun contrôle enregistré','due'=>'À faire','cls'=>'late'];
  elseif ($stx_ctl_days!==null && $stx_ctl_days>30) $agenda[]=['ic'=>'🔒','t'=>'Coffre à stupéfiants','s'=>'Contrôle du coffre','due'=>'En retard '.$stx_ctl_days.' j','cls'=>'late'];
}
if ($site_id && nav_peut($nav_perms,'emplacements')) {
  try { $sc=$pdo->query("SELECT nom, controle_le FROM emplacements WHERE site_id=".intval($site_id)." AND actif=1 AND scelle_numero IS NOT NULL AND scelle_numero<>'' ORDER BY controle_le IS NULL DESC, controle_le ASC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sc as $s){ $d = $s['controle_le'] ? floor((time()-strtotime($s['controle_le']))/86400) : null; $late=($d===null||$d>30); $agenda[]=['ic'=>'🛡️','t'=>'Scellé — '.$s['nom'],'s'=>'Contrôle du scellé','due'=>($d===null?'Jamais':($late?'En retard':'OK')),'cls'=>($late?'late':'ok')]; }
  } catch(Throwable $e){}
}

// ── À traiter (actions urgentes) ──
$todo=[];
if (!empty($rappels_actifs)) { $n=count($rappels_actifs); $todo[]=['ic'=>'🧯','cls'=>'','tt'=>"$n lot".($n>1?'s':'')." rappelé".($n>1?'s':'')." (ANSM)",'sb'=>'À isoler','lien'=>'tracabilite.php']; }
if (nav_peut($nav_perms,'medicaments') && $expired>0) $todo[]=['ic'=>'🔴','cls'=>'','tt'=>"$expired produit".($expired>1?'s':'')." périmé".($expired>1?'s':''),'sb'=>'À retirer du stock','lien'=>'alerts.php'];
if ($dbStup && (($stx_ctl_days!==null && $stx_ctl_days>30) || ($stx_ctl===null && $stx_solde>0))) $todo[]=['ic'=>'🔒','cls'=>'vio','tt'=>'Contrôle coffre en retard','sb'=>'Stupéfiants — à contrôler','lien'=>'stupefiants.php'];
if ($dot_incomplets>0) { $_pire=$dot_sacs[0]['nom']??''; $todo[]=['ic'=>'🎯','cls'=>'amber','tt'=>"$dot_incomplets sac".($dot_incomplets>1?'s':'')." incomplet".($dot_incomplets>1?'s':''),'sb'=>'Dotation à compléter'.($_pire!==''?" — ex. « ".h($_pire)." »":''),'lien'=>'locations.php']; }
if (nav_peut($nav_perms,'biomed') && $biomed_hs>0) $todo[]=['ic'=>'🔧','cls'=>'','tt'=>"$biomed_hs matériel".($biomed_hs>1?'s':'')." HS",'sb'=>'Intervention nécessaire','lien'=>'materiel.php'];

// ── Activité récente (fusion) ──
$activite=[];
if (nav_peut($nav_perms,'rempotages')) foreach ($recent_remp as $r) $activite[]=['ic'=>'🔄','col'=>'#10b981','d'=>$r['cree_le'],'tt'=>'Rempotage — '.h($r['empl_nom']??'—'),'sb'=>((int)$r['nb']).' article(s) · '.h($r['prenom']??'')];
if (nav_peut($nav_perms,'fiches')) foreach ($recent_fiches as $f) $activite[]=['ic'=>'📋','col'=>($f['statut']==='anomalie'?'#ef4444':'#10b981'),'d'=>$f['cree_le'],'tt'=>h($f['modele_nom']),'sb'=>h($f['prenom']??'').' · '.($f['statut']==='anomalie'?'Anomalie':'OK')];
if (nav_peut($nav_perms,'terrain')) foreach ($recent_inter as $it) $activite[]=['ic'=>'🚑','col'=>($it['statut']==='termine'?'#10b981':'#f59e0b'),'d'=>$it['cree_le'],'tt'=>'Intervention '.($it['reference']?h($it['reference']):'sans réf.'),'sb'=>((int)$it['nb']).' article(s) · '.h($it['prenom']??'')];
usort($activite, fn($a,$b)=>strtotime($b['d'])<=>strtotime($a['d']));
$activite=array_slice($activite,0,6);

// ── KPI (max 4) ──
$kpis=[];
if (nav_peut($nav_perms,'medicaments')) {
  $kpis[]=['v'=>$total,'l'=>'Produits en stock','i'=>'💊','c'=>'#3b82f6','link'=>'products.php','alert'=>false,'sub'=>($lowStock>0?$lowStock.' en stock bas':'niveaux OK')];
  $al=$expired+$critical; $kpis[]=['v'=>$al,'l'=>'Alertes péremption','i'=>'⚠️','c'=>$al>0?'#ef4444':'#10b981','link'=>'alerts.php','alert'=>$al>0,'sub'=>($al>0?$expired.' périmé'.($expired>1?'s':'').' · '.$critical.' sous 7j':'rien à signaler')];
}
if ($dbStup) $kpis[]=['v'=>rtrim(rtrim(number_format($stx_solde,2,'.',''),'0'),'.'),'l'=>'Ampoules coffre','i'=>'🔒','c'=>'#8b5cf6','link'=>'stupefiants.php','alert'=>false,'sub'=>($stx_ctl_days!==null?'contrôlé il y a '.$stx_ctl_days.' j':'jamais contrôlé')];
if (count($kpis)<4 && !empty($dot_sacs)) { $cf = count($dot_sacs)>0 ? round($dot_prets/max(count($dot_sacs),1)*100) : 0; $kpis[]=['v'=>$cf.'%','l'=>'Dotation conforme','i'=>'🎯','c'=>'#10b981','link'=>'locations.php','alert'=>false,'sub'=>$dot_prets.'/'.count($dot_sacs).' sac'.(count($dot_sacs)>1?'s':'').' prêt'.($dot_prets>1?'s':'')]; }
if (count($kpis)<4 && nav_peut($nav_perms,'biomed') && $biomed_total>0) $kpis[]=['v'=>$biomed_hs,'l'=>'Biomédical HS','i'=>'🩺','c'=>$biomed_hs>0?'#ef4444':'#10b981','link'=>'materiel.php','alert'=>$biomed_hs>0,'sub'=>'sur '.$biomed_total.' équipement'.($biomed_total>1?'s':'')];
$kpis=array_slice($kpis,0,4);

// ── Préférences perso ──
$prefHidden=[]; $prefDensity='cosy';
try { $pl=$pdo->prepare("SELECT layout FROM user_dashboard_prefs WHERE user_id=?"); $pl->execute([currentUserId()]); $raw=$pl->fetchColumn();
  if ($raw){ $jj=json_decode($raw,true); if(is_array($jj)){ if(!empty($jj['hidden'])&&is_array($jj['hidden'])) $prefHidden=$jj['hidden']; if(!empty($jj['density'])&&$jj['density']==='compact') $prefDensity='compact'; } }
} catch(Throwable $e){}
$aiOn=false; try { $aiOn=($pdo->query("SELECT valeur FROM ai_settings WHERE cle='actif'")->fetchColumn()==='1'); } catch(Throwable $e){}

// ── Gate + visibilité par widget ──
$wGate = [
  'ai'=>$aiOn,'todo'=>!empty($todo),'kpi'=>!empty($kpis),
  'surv'=>(nav_peut($nav_perms,'medicaments') && !empty($urgent)),
  'chart'=>(nav_peut($nav_perms,'medicaments') && !empty($chart_mois)),
  'meteo'=>nav_peut($nav_perms,'medicaments'),
  'conso'=>(nav_peut($nav_perms,'medicaments') && !empty($conso)),
  'ready'=>!empty($dot_sacs),'act'=>!empty($activite),'agenda'=>!empty($agenda),
  'stup'=>$dbStup,'biomed'=>(nav_peut($nav_perms,'biomed') && $biomed_total>0),
];
$wShow = function($k) use ($wGate,$prefHidden){ return (($wGate[$k]??false) && !in_array($k,$prefHidden,true)); };
$secShow = function(array $ks) use ($wShow){ foreach ($ks as $k) if ($wShow($k)) return true; return false; };
$WLABEL = ['ai'=>['🤖','Copilote IA (résumé du jour)'],'todo'=>['🚨','À traiter maintenant'],'kpi'=>['📊','Chiffres clés'],'surv'=>['⚠️','Produits à surveiller'],'chart'=>['📅','Péremptions par mois'],'meteo'=>['🌡️','Météo des péremptions'],'conso'=>['🔥','Top consommés'],'ready'=>['🎯','Prêt à partir'],'act'=>['🕑','Activité récente'],'agenda'=>['🗓️','Échéancier des contrôles'],'stup'=>['🔒','Stupéfiants — coffre'],'biomed'=>['🩺','Biomédical']];

// Insights du Copilote — résumé déterministe et coloré (affiché tout de suite, comme la démo)
$aiIns = [];
if ($expired > 0) $aiIns[] = ['r','🔴',"<b>$expired produit".($expired>1?'s':'')." périmé".($expired>1?'s':'')."</b> encore en stock — à retirer.",'Retirer','alerts.php'];
if ($dbStup && (($stx_ctl_days!==null && $stx_ctl_days>30) || ($stx_ctl===null && $stx_solde>0))) $aiIns[] = ['v','🔒',"Le <b>coffre à stupéfiants</b> ".($stx_ctl_days!==null?"n'a pas été contrôlé depuis <b>".$stx_ctl_days." j</b>":"n'a <b>jamais</b> été contrôlé")." — contrôle recommandé.",'Contrôler','stupefiants.php'];
if ($critical > 0) $aiIns[] = ['a','⚠️',"<b>$critical produit".($critical>1?'s':'')."</b> périme".($critical>1?'nt':'')." sous 7 jours.",'Voir','alerts.php'];
if ($dot_incomplets > 0) $aiIns[] = ['a','🎒',"<b>$dot_incomplets sac".($dot_incomplets>1?'s':'')."</b> sous dotation à compléter.",'Compléter','locations.php'];
if ($lowStock > 0) $aiIns[] = ['b','📦',"<b>$lowStock produit".($lowStock>1?'s':'')."</b> en stock bas.",'Voir','alerts.php'];
$aiIns = array_slice($aiIns, 0, 5);
// Salutation personnalisée
$__h = (int)date('G'); $salut = $__h < 18 ? 'Bonjour' : 'Bonsoir';
$prenomU = '';
try { $pp = $pdo->prepare("SELECT prenom FROM utilisateurs WHERE id=?"); $pp->execute([currentUserId()]); $prenomU = (string)($pp->fetchColumn() ?: ''); } catch (Throwable $e) {}
$nbTodo = count($todo);
?>
<style>
#dbx{--line:var(--border);--line2:var(--border2);--vio:#8b5cf6;--grn:#10b981;--amb:#f59e0b;--rd:#ef4444}
#dbx .sec{display:flex;align-items:center;gap:9px;margin:22px 2px 13px}
#dbx .sec .bar{width:4px;height:16px;border-radius:3px;background:var(--primary)}
#dbx .sec.vio .bar{background:var(--vio)} #dbx .sec.grn .bar{background:var(--grn)} #dbx .sec.red .bar{background:var(--rd)}
#dbx .sec .t{font-size:.73rem;font-weight:800;text-transform:uppercase;letter-spacing:.09em;color:var(--text2)}
#dbx .sec .line{flex:1;height:1px;background:var(--border)}
#dbx .sec .lk{font-size:.74rem;font-weight:700;color:var(--primary);text-decoration:none}
#dbx .card{background:var(--bg2);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow);overflow:hidden;margin-bottom:14px}
#dbx .card-h{display:flex;align-items:center;gap:10px;padding:14px 17px;border-bottom:1px solid var(--border)}
#dbx .card-h .cic{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.95rem;background:var(--bg3)}
#dbx .card-h .ttl{font-weight:800;font-size:.92rem}
#dbx .card-h .link{margin-left:auto;font-size:.74rem;color:var(--primary);font-weight:700;text-decoration:none}
#dbx .card-b{padding:15px 17px}
#dbx .grid{display:grid;grid-template-columns:1.55fr 1fr;gap:16px;align-items:start}
#dbx .col{display:flex;flex-direction:column}
#dbx .ai,#dbx .todo,#dbx .kpis,#dbx .grid{margin-top:18px}
#dbx.compact .card-b{padding:11px 13px} #dbx.compact .sec{margin:15px 2px 9px}
/* KPI */
#dbx .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:13px}
#dbx .kpi{position:relative;display:flex;align-items:center;gap:12px;background:var(--bg2);border:1px solid var(--border);border-radius:13px;padding:11px 15px 11px 16px;box-shadow:var(--shadow);text-decoration:none;transition:transform .15s,box-shadow .15s,border-color .15s;overflow:hidden}
#dbx .kpi::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--kc,var(--primary))}
#dbx .kpi:hover{transform:translateY(-2px);box-shadow:0 10px 22px -14px rgba(20,30,60,.3)}
#dbx .kpi .ic{width:36px;height:36px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.05rem}
#dbx .kpi .meta{display:flex;flex-direction:column;min-width:0}
#dbx .kpi .n{font-size:1.5rem;font-weight:800;line-height:1;font-variant-numeric:tabular-nums}
#dbx .kpi .l{font-size:.72rem;color:var(--text2);font-weight:700;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#dbx .kpi .sub{margin-left:auto;text-align:right;font-size:.7rem;color:var(--text3);font-weight:600;line-height:1.35;max-width:44%;flex-shrink:0}
#dbx .kpi.alert{border-color:rgba(239,68,68,.3);background:linear-gradient(180deg,rgba(239,68,68,.05),var(--bg2))}
#dbx .kpi.alert .sub{color:var(--rd)}
#dbx.compact .kpi{padding:8px 13px} #dbx.compact .kpi .n{font-size:1.3rem} #dbx.compact .kpi .sub{display:none}
/* Copilote */
#dbx .ai{position:relative;overflow:hidden;border-radius:18px;border:1px solid var(--border);background:linear-gradient(135deg,rgba(59,130,246,.09),rgba(139,92,246,.07)),var(--bg2);box-shadow:var(--shadow);padding:18px 20px}
#dbx .ai-head{display:flex;align-items:center;gap:13px}
#dbx .ai-orb{width:48px;height:48px;border-radius:15px;flex-shrink:0;background:linear-gradient(135deg,#3b82f6,#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:23px;box-shadow:0 10px 26px -6px rgba(139,92,246,.6)}
#dbx .ai-title{font-weight:800;font-size:1.1rem;display:flex;align-items:center;gap:9px;color:var(--text)}
#dbx .ai-tag{font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--vio);background:rgba(139,92,246,.14);padding:3px 9px;border-radius:20px}
#dbx .ai-sub{font-size:.75rem;color:var(--text3);font-weight:600;margin-top:2px}
#dbx .ai-refresh{margin-left:auto;background:var(--bg2);border:1px solid var(--border);border-radius:10px;font-family:inherit;font-size:.74rem;font-weight:700;color:var(--text2);padding:8px 13px;cursor:pointer}
#dbx .ai-chips{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 2px}
#dbx .ai-chip{display:inline-flex;align-items:center;gap:6px;background:var(--bg2);border:1px solid var(--border2);border-radius:20px;padding:7px 13px;font-size:.78rem;font-weight:700;color:var(--text2);cursor:pointer}
#dbx .ai-chip:hover{border-color:var(--vio);color:var(--vio)}
#dbx .ai-body{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:15px 16px;margin-top:12px}
#dbx .ai-ins{margin:11px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:9px}
#dbx .ai-ins li{display:flex;gap:11px;align-items:flex-start;padding:10px 12px;border-radius:11px;background:var(--bg3);border:1px solid var(--border)}
#dbx .ai-ins li .dd{width:26px;height:26px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.9rem}
#dbx .ai-ins li.r .dd{background:rgba(239,68,68,.13)} #dbx .ai-ins li.v .dd{background:rgba(139,92,246,.13)} #dbx .ai-ins li.a .dd{background:rgba(245,158,11,.15)} #dbx .ai-ins li.b .dd{background:rgba(59,130,246,.13)}
#dbx .ai-ins .ix{flex:1;font-size:.83rem;color:var(--text2);line-height:1.45} #dbx .ai-ins .ix b{color:var(--text)}
#dbx .ai-ins .go{font-size:.72rem;font-weight:800;color:var(--primary);white-space:nowrap;align-self:center;text-decoration:none}
#dbx .db-hello{margin:0 2px 14px}
#dbx .db-hello .hi{font-size:1.4rem;font-weight:800;letter-spacing:-.01em}
#dbx .db-hello .sub{font-size:.85rem;color:var(--text3);font-weight:600;margin-top:2px}
#dbx .db-hello .sub b{color:var(--primary)}
#dbx .db-lab{display:block;font-size:1rem;font-weight:800;letter-spacing:-.01em;color:var(--text);margin:26px 2px 12px}
#dbx .ai-txt{font-size:.87rem;color:var(--text2);line-height:1.6;white-space:pre-wrap}
#dbx .ai-thread{display:flex;flex-direction:column;gap:10px;margin-top:12px}
#dbx .ai-thread:empty{display:none}
#dbx .msg{display:flex;gap:9px;max-width:94%} #dbx .msg.u{align-self:flex-end;flex-direction:row-reverse}
#dbx .msg .av{width:28px;height:28px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.85rem;color:#fff}
#dbx .msg.a .av{background:linear-gradient(135deg,#3b82f6,#8b5cf6)} #dbx .msg.u .av{background:var(--border2);color:var(--text)}
#dbx .msg .bub{padding:10px 13px;border-radius:13px;font-size:.84rem;line-height:1.5;white-space:pre-wrap}
#dbx .msg.a .bub{background:var(--bg3);border:1px solid var(--border);border-top-left-radius:4px}
#dbx .msg.u .bub{background:var(--primary);color:#fff;border-top-right-radius:4px;font-weight:600}
#dbx .dots i{display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--text3);margin:0 1px;animation:dbxb 1.2s infinite}
#dbx .dots i:nth-child(2){animation-delay:.15s} #dbx .dots i:nth-child(3){animation-delay:.3s}
@keyframes dbxb{0%,60%,100%{transform:translateY(0);opacity:.4}30%{transform:translateY(-4px);opacity:1}}
#dbx .ai-ask{display:flex;gap:9px;margin-top:12px}
#dbx .ai-ask input{flex:1;border:1px solid var(--border2);background:var(--bg2);border-radius:12px;padding:11px 14px;font-family:inherit;font-size:.85rem;color:var(--text);outline:none}
#dbx .ai-ask input:focus{border-color:var(--primary)}
#dbx .ai-ask button{background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;border:none;border-radius:12px;padding:11px 18px;font-family:inherit;font-weight:800;font-size:.85rem;cursor:pointer}
/* todo */
#dbx .todo{display:flex;gap:12px;flex-wrap:wrap}
#dbx .todo-card{flex:1;min-width:210px;display:flex;align-items:center;gap:12px;background:var(--bg2);border:1px solid var(--border);border-left:4px solid var(--rd);border-radius:14px;padding:13px 15px;box-shadow:var(--shadow);text-decoration:none;color:var(--text);transition:transform .15s}
#dbx .todo-card:hover{transform:translateY(-2px)}
#dbx .todo-card.amber{border-left-color:var(--amb)} #dbx .todo-card.vio{border-left-color:var(--vio)}
#dbx .todo-card .ic{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;background:rgba(239,68,68,.12)}
#dbx .todo-card.amber .ic{background:rgba(245,158,11,.14)} #dbx .todo-card.vio .ic{background:rgba(139,92,246,.13)}
#dbx .todo-card .tt{font-size:.9rem;font-weight:800} #dbx .todo-card .sb{font-size:.72rem;color:var(--text3);font-weight:600;margin-top:1px}
#dbx .todo-card .go{margin-left:auto;color:var(--text3)}
/* tables & divers */
#dbx table{width:100%;border-collapse:collapse;font-size:.82rem}
#dbx th{text-align:left;font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text3);font-weight:800;padding:9px 17px;background:var(--bg3)}
#dbx td{padding:11px 17px;border-top:1px solid var(--border)}
#dbx .badge{font-size:.7rem;font-weight:800;padding:3px 9px;border-radius:7px;white-space:nowrap}
/* Liste "à surveiller" (lignes propres) */
#dbx .surv{display:flex;flex-direction:column}
#dbx .surv-row{display:flex;align-items:center;gap:12px;padding:10px 16px;border-top:1px solid var(--border);text-decoration:none;color:var(--text);transition:background .12s}
#dbx .surv-row:first-child{border-top:none}
#dbx .surv-row:hover{background:var(--bg3)}
#dbx .surv-row .sdot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
#dbx .surv-row .info{min-width:0;flex:1}
#dbx .surv-row .nm{font-weight:700;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#dbx .surv-row .loc{font-size:.72rem;color:var(--text3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}
#dbx .surv-row .badge{margin-left:auto;flex-shrink:0}
#dbx .b-red{background:rgba(239,68,68,.14);color:var(--rd)} #dbx .b-org{background:rgba(245,158,11,.16);color:#B4700A}
#dbx .b-vio{background:rgba(139,92,246,.13);color:var(--vio)}
#dbx .chart{display:flex;align-items:flex-end;gap:8px;height:120px;padding-top:10px}
#dbx .bar{flex:1;display:flex;flex-direction:column;align-items:center;gap:5px;height:100%;justify-content:flex-end}
#dbx .bar .fl{width:100%;border-radius:6px 6px 3px 3px} #dbx .bar .n{font-size:.62rem;font-weight:700;color:var(--text2)} #dbx .bar .lb{font-size:.6rem;color:var(--text3);font-weight:600}
#dbx .meteo{display:flex;gap:10px} #dbx .meteo a{flex:1;text-align:center;padding:13px 8px;border-radius:12px;border:1px solid var(--border);text-decoration:none}
#dbx .meteo .v{font-size:1.5rem;font-weight:800;font-variant-numeric:tabular-nums} #dbx .meteo .k{font-size:.64rem;color:var(--text3);font-weight:800;text-transform:uppercase;margin-top:2px}
#dbx .m-red{background:rgba(239,68,68,.07)} #dbx .m-red .v{color:var(--rd)} #dbx .m-org{background:rgba(245,158,11,.08)} #dbx .m-org .v{color:#B4700A} #dbx .m-blu{background:rgba(59,130,246,.07)} #dbx .m-blu .v{color:var(--primary)}
#dbx .conso-row{display:flex;align-items:center;gap:11px;padding:9px 17px;border-top:1px solid var(--border)}
#dbx .conso-row .nm{width:150px;font-size:.8rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#dbx .conso-row .track{flex:1;height:8px;background:var(--bg3);border-radius:5px;overflow:hidden} #dbx .conso-row .track i{display:block;height:100%;border-radius:5px;background:linear-gradient(90deg,#3b82f6,#8b5cf6)}
#dbx .conso-row .qc{width:40px;text-align:right;font-size:.82rem;font-weight:800}
#dbx .rt-row{display:flex;gap:11px;padding:14px 17px}
#dbx .rt{flex:1;text-align:center;padding:11px;border-radius:11px} #dbx .rt .v{font-size:1.4rem;font-weight:800} #dbx .rt .k{font-size:.64rem;color:var(--text3);font-weight:800}
#dbx .rt.g{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2)} #dbx .rt.a{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2)} #dbx .rt.n{background:var(--bg3);border:1px solid var(--border)}
/* Mini-stats compactes (météo, biomédical, prêt à partir) */
#dbx .mini{display:flex;gap:9px}
#dbx .mini a,#dbx .mini .t{flex:1;position:relative;display:flex;align-items:center;gap:9px;padding:10px 12px;border-radius:11px;background:var(--bg3);border:1px solid var(--border);text-decoration:none;overflow:hidden;transition:transform .12s}
#dbx .mini a:hover{transform:translateY(-1px)}
#dbx .mini a::before,#dbx .mini .t::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--mc,var(--primary))}
#dbx .mini .v{font-size:1.35rem;font-weight:800;line-height:1;font-variant-numeric:tabular-nums;color:var(--mc,var(--text))}
#dbx .mini .k{font-size:.66rem;color:var(--text3);font-weight:800;text-transform:uppercase;letter-spacing:.03em;line-height:1.2}
#dbx.compact .mini .v{font-size:1.15rem}
#dbx .row{display:flex;align-items:center;gap:11px;padding:10px 17px;border-top:1px solid var(--border);text-decoration:none;color:var(--text)}
#dbx .row .nm{flex:1;min-width:0;font-size:.82rem;font-weight:600} #dbx .row .nm small{display:block;color:var(--text3);font-weight:500;font-size:.7rem}
#dbx .pbar{width:70px;height:6px;background:var(--border);border-radius:4px;overflow:hidden} #dbx .pbar i{display:block;height:100%}
#dbx .pc{width:40px;text-align:right;font-weight:800;font-size:.78rem}
#dbx .tl{display:flex;align-items:center;gap:11px;padding:9px 16px;border-top:1px solid var(--border)}
#dbx .tl .ic{width:30px;height:30px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.9rem}
#dbx .tl .info{flex:1;min-width:0}
#dbx .tl .tt{font-size:.83rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#dbx .tl .sb{font-size:.71rem;color:var(--text3);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}
#dbx .tl .dd{font-size:.68rem;color:var(--text3);white-space:nowrap;flex-shrink:0;font-weight:600}
#dbx .agr{display:flex;align-items:center;gap:11px;padding:11px 17px;border-top:1px solid var(--border)}
#dbx .agr .ic{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.9rem;background:var(--bg3)}
#dbx .agr .t{flex:1;font-size:.82rem;font-weight:700} #dbx .agr .t small{display:block;color:var(--text3);font-weight:500;font-size:.7rem}
#dbx .due{font-size:.7rem;font-weight:800;padding:3px 9px;border-radius:7px;white-space:nowrap}
#dbx .due.late{background:rgba(239,68,68,.14);color:var(--rd)} #dbx .due.ok{background:rgba(59,130,246,.12);color:var(--primary)}
/* stup */
#dbx .stup{border-top:3px solid var(--vio)}
#dbx .stx-seg{margin-left:auto;display:flex;gap:2px;background:var(--bg3);border:1px solid var(--border);border-radius:9px;padding:2px}
#dbx .stx-seg button{border:none;background:none;font-family:inherit;font-size:.68rem;font-weight:800;color:var(--text3);padding:5px 9px;border-radius:7px;cursor:pointer}
#dbx .stx-seg button.on{background:var(--bg2);color:var(--vio)}
#dbx .stup-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
#dbx .stup-tile{background:var(--bg3);border:1px solid var(--border);border-radius:12px;padding:13px}
#dbx .stup-tile .v{font-size:1.5rem;font-weight:800} #dbx .stup-tile .k{font-size:.7rem;color:var(--text3);font-weight:700;margin-top:2px}
#dbx .chip{display:inline-flex;align-items:center;gap:5px;font-size:.72rem;font-weight:800;padding:3px 10px;border-radius:20px}
#dbx .chip.ok{background:rgba(16,185,129,.13);color:var(--grn)} #dbx .chip.late{background:rgba(239,68,68,.13);color:var(--rd)}
#dbx .stup-mv{display:flex;align-items:center;gap:10px;padding:9px 0;border-top:1px solid var(--border);font-size:.8rem}
#dbx .stup-mv .d{color:var(--text3);font-size:.7rem;white-space:nowrap} #dbx .stup-mv .t{flex:1;font-weight:600} #dbx .stup-mv .t small{color:var(--text3);font-weight:500}
#dbx .stup-mv .q{font-weight:800} #dbx .stup-mv .q.neg{color:var(--rd)} #dbx .stup-mv .q.pos{color:var(--grn)}
#dbx .locknote{background:var(--bg3);border:1px dashed var(--border2);border-radius:11px;padding:12px 13px;font-size:.78rem;color:var(--text2);line-height:1.55}
/* toolbar */
#dbx .db-tools{display:flex;align-items:center;gap:10px;margin:0 2px 6px}
#dbx .db-btn{display:inline-flex;align-items:center;gap:7px;background:var(--bg2);border:1px solid var(--border);border-radius:11px;padding:8px 13px;font-family:inherit;font-size:.78rem;font-weight:700;color:var(--text2);cursor:pointer;box-shadow:var(--shadow)}
#dbx .db-btn:hover{border-color:var(--primary);color:var(--primary)}
@keyframes dbxrise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
#dbx .rise{animation:dbxrise .5s cubic-bezier(.16,1,.3,1) both}
@media(max-width:900px){#dbx .grid{grid-template-columns:1fr}#dbx .kpis{grid-template-columns:repeat(2,1fr)}}
/* drawer perso */
#dbx-scrim{position:fixed;inset:0;background:rgba(15,20,35,.5);opacity:0;pointer-events:none;transition:.2s;z-index:1200}
#dbx-scrim.open{opacity:1;pointer-events:auto}
#dbx-drawer{position:fixed;top:0;right:0;bottom:0;width:340px;max-width:92vw;background:var(--bg2);box-shadow:-16px 0 50px -20px rgba(0,0,0,.5);transform:translateX(100%);transition:transform .25s cubic-bezier(.4,0,.2,1);z-index:1201;display:flex;flex-direction:column}
#dbx-drawer.open{transform:none}
#dbx-drawer .dh{padding:18px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;font-weight:800}
#dbx-drawer .dh button{margin-left:auto;background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text3)}
#dbx-drawer .db{padding:14px 16px;overflow-y:auto;flex:1}
#dbx-drawer .note{font-size:.76rem;color:var(--text3);margin:0 0 12px;line-height:1.5}
#dbx-drawer .presets{display:flex;gap:7px;margin-bottom:11px}
#dbx-drawer .preset{flex:1;padding:9px 6px;border:1px solid var(--border);border-radius:10px;background:var(--bg2);font-family:inherit;font-size:.73rem;font-weight:800;color:var(--text2);cursor:pointer}
#dbx-drawer .densrow{display:flex;align-items:center;gap:8px;margin-bottom:14px;font-size:.8rem;font-weight:700;color:var(--text2)}
#dbx-drawer .seg{margin-left:auto;display:flex;background:var(--bg3);border:1px solid var(--border);border-radius:9px;padding:2px}
#dbx-drawer .seg button{border:none;background:none;font-family:inherit;font-size:.72rem;font-weight:800;color:var(--text3);padding:5px 10px;border-radius:7px;cursor:pointer}
#dbx-drawer .seg button.on{background:var(--bg2);color:var(--primary)}
#dbx-drawer .wt{display:flex;align-items:center;gap:12px;padding:11px 12px;border:1px solid var(--border);border-radius:12px;margin-bottom:9px;cursor:pointer}
#dbx-drawer .wt .ic{width:32px;height:32px;border-radius:9px;background:var(--bg3);display:flex;align-items:center;justify-content:center}
#dbx-drawer .wt .nm{flex:1;font-weight:700;font-size:.85rem}
#dbx-drawer .sw{width:40px;height:23px;border-radius:20px;background:var(--border2);position:relative;transition:.2s;flex-shrink:0}
#dbx-drawer .sw::after{content:'';position:absolute;top:2px;left:2px;width:19px;height:19px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:.2s}
#dbx-drawer .wt.on .sw{background:var(--primary)} #dbx-drawer .wt.on .sw::after{left:19px}
#dbx-drawer .df{padding:14px 16px;border-top:1px solid var(--border);display:flex;gap:10px}
#dbx-drawer .df button{flex:1;padding:11px;border-radius:11px;border:none;font-family:inherit;font-weight:800;cursor:pointer}
#dbx-drawer .df .g{background:var(--bg3);color:var(--text2)} #dbx-drawer .df .p{background:var(--primary);color:#fff}
</style>

<div id="dbx" class="<?= $prefDensity==='compact'?'compact':'' ?>">

  <!-- Salutation -->
  <div class="db-hello">
    <div class="hi"><?= $salut ?> <?= h($prenomU) ?> 👋</div>
    <div class="sub"><?= $nbTodo>0 ? '<b>'.$nbTodo.' point'.($nbTodo>1?'s':'').'</b> à traiter '.($__h<12?'ce matin':'aujourd\'hui').'.' : 'Tout est en ordre — bonne journée.' ?></div>
  </div>

  <!-- Barre : titre + score + personnaliser -->
  <div class="db-tools">
    <div style="font-size:1.15rem;font-weight:800"><?= $site_info ? h($site_info['org_nom'].' — '.$site_info['nom']) : '' ?></div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:10px">
      <?php if ($msg_non_lus>0): ?><a class="db-btn" href="messaging.php" style="text-decoration:none">💬 <?= $msg_non_lus ?> non lu<?= $msg_non_lus>1?'s':'' ?></a><?php endif; ?>
      <span class="db-btn" style="cursor:default">📅 <?= $date_fr ?></span>
      <span class="db-btn" style="cursor:default;color:<?= $score_color ?>">● État <?= $score_global ?>%</span>
      <button class="db-btn" onclick="dbxDrawer(true)">⚙️ Personnaliser</button>
    </div>
  </div>

  <?php if ($wShow('ai')): ?>
  <!-- COPILOTE -->
  <div class="ai" data-w="ai">
    <div class="ai-head">
      <div class="ai-orb">🤖</div>
      <div><div class="ai-title">Copilote Cadurix <span class="ai-tag">IA</span></div><div class="ai-sub">Voici l'essentiel du jour — demande une analyse IA ou pose une question</div></div>
      <button class="ai-refresh" onclick="dbxCopilote('')">🤖 Analyse IA</button>
    </div>
    <div class="ai-chips">
      <span class="ai-chip" onclick="dbxCopilote('Que dois-je commander en priorité ?')">📦 Que commander ?</span>
      <span class="ai-chip" onclick="dbxCopilote('Quels sacs sont à compléter ?')">🎒 Sacs à compléter</span>
      <span class="ai-chip" onclick="dbxCopilote('Quels produits périment bientôt ?')">💀 Périmés à venir</span>
      <?php if ($dbStup): ?><span class="ai-chip" onclick="dbxCopilote('Fais le point sur le coffre à stupéfiants')">🔒 État du coffre</span><?php endif; ?>
    </div>
    <div class="ai-body">
      <div class="ai-txt" id="dbx-ai-txt" style="font-weight:700;color:var(--text)"><?= $salut ?> <?= h($prenomU) ?>, voici l'essentiel aujourd'hui :</div>
      <ul class="ai-ins">
        <?php foreach ($aiIns as $x): ?>
        <li class="<?= $x[0] ?>"><span class="dd"><?= $x[1] ?></span><span class="ix"><?= $x[2] ?></span><a class="go" href="<?= $x[4] ?>"><?= $x[3] ?> →</a></li>
        <?php endforeach; ?>
        <?php if (!$aiIns): ?><li class="b"><span class="dd">✅</span><span class="ix">Rien d'urgent — tout est en ordre aujourd'hui.</span></li><?php endif; ?>
      </ul>
      <div class="ai-thread" id="dbx-ai-thread"></div>
      <div class="ai-ask"><input id="dbx-ai-input" placeholder="Pose une question sur ton stock, tes sacs, tes péremptions…" onkeydown="if(event.key==='Enter')dbxAsk()"><button onclick="dbxAsk()">Demander</button></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($wShow('todo')): ?>
  <div class="db-lab" data-sec="todo">🚨 À traiter</div>
  <div class="todo" data-w="todo">
    <?php foreach ($todo as $t): ?>
    <a class="todo-card <?= $t['cls'] ?>" href="<?= $t['lien'] ?>"><span class="ic"><?= $t['ic'] ?></span><div><div class="tt"><?= $t['tt'] ?></div><div class="sb"><?= $t['sb'] ?></div></div><span class="go">→</span></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($wShow('kpi')): ?>
  <div class="db-lab" data-sec="kpi">📊 Vue d'ensemble</div>
  <div class="kpis" data-w="kpi">
    <?php foreach ($kpis as $k): ?>
    <a class="kpi <?= $k['alert']?'alert':'' ?>" href="<?= $k['link'] ?>" style="--kc:<?= $k['c'] ?>"><span class="ic" style="background:<?= $k['c'] ?>1f;color:<?= $k['c'] ?>"><?= $k['i'] ?></span><span class="meta"><span class="n" style="color:<?= $k['c'] ?>"><?= $k['v'] ?></span><span class="l"><?= $k['l'] ?></span></span><?php if(!empty($k['sub'])): ?><span class="sub"><?= h($k['sub']) ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($secShow(['surv','chart','meteo','conso','biomed'])): ?>
  <div class="db-lab" data-sec="surv,chart,meteo,conso,biomed">💊 Stock &amp; péremptions</div>
  <div class="grid">
    <div class="col">
      <?php if ($wShow('surv')): ?>
      <div class="card" data-w="surv">
        <div class="card-h"><span class="cic">⚠️</span><span class="ttl">Produits à surveiller</span><a class="link" href="alerts.php">Tout voir →</a></div>
        <div class="surv">
        <?php foreach (array_slice($urgent,0,6) as $u): $j=(int)($u['jours']??0); $low=($u['quantite_min']>0 && $u['quantite']<=$u['quantite_min']);
          $bd=$j<0?'b-red':'b-org'; $lbl=$j<0?'Périmé':($j===0?'Auj.':$j.' j');
          $sd=$low?'var(--vio)':($j<0?'var(--rd)':'var(--amb)');
          $href=!empty($u['empl_id'])?'locations.php?sel='.(int)$u['empl_id']:'alerts.php'; ?>
        <a class="surv-row" href="<?= $href ?>"><span class="sdot" style="background:<?= $sd ?>"></span><span class="info"><div class="nm"><?= h($u['nom']) ?></div><div class="loc"><?= $u['empl_parent']?h($u['empl_parent']).' › ':'' ?><?= h($u['empl_nom']) ?></div></span><?php if($low):?><span class="badge b-vio">Stock bas</span><?php else:?><span class="badge <?= $bd ?>"><?= $lbl ?></span><?php endif;?></a>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($wShow('chart')): ?>
      <div class="card" data-w="chart">
        <div class="card-h"><span class="cic">📅</span><span class="ttl">Péremptions par mois</span></div>
        <div class="card-b"><div class="chart">
          <?php $cmax=max(max(array_column($chart_mois,'nb')),1); foreach (array_slice($chart_mois,0,6) as $m): $pct=round($m['nb']/$cmax*100); $c=$m['mois']===0?'var(--rd)':($m['mois']<=1?'var(--amb)':($m['mois']<=2?'#EAB308':'rgba(var(--primary-rgb),.45)')); ?>
          <div class="bar"><div class="n"><?= $m['nb'] ?></div><div class="fl" style="height:<?= max($pct,3) ?>%;background:<?= $c ?>"></div><div class="lb"><?= $m['label'] ?></div></div>
          <?php endforeach; ?>
        </div></div>
      </div>
      <?php endif; ?>
    </div>
    <div class="col">
      <?php if ($wShow('meteo')): ?>
      <div class="card" data-w="meteo">
        <div class="card-h"><span class="cic">🌡️</span><span class="ttl">Météo des péremptions</span></div>
        <div class="card-b"><div class="mini">
          <a href="alerts.php" style="--mc:var(--rd)"><span class="v"><?= $m7 ?></span><span class="k">≤ 7 jours</span></a>
          <a href="alerts.php" style="--mc:var(--amb)"><span class="v"><?= $m30 ?></span><span class="k">≤ 30 jours</span></a>
          <a href="products.php" style="--mc:var(--primary)"><span class="v"><?= $m90 ?></span><span class="k">≤ 90 jours</span></a>
        </div></div>
      </div>
      <?php endif; ?>
      <?php if ($wShow('conso')): ?>
      <div class="card" data-w="conso">
        <div class="card-h"><span class="cic">🔥</span><span class="ttl">Top consommés (30 j)</span></div>
        <div class="card-b" style="padding:8px 0">
        <?php foreach ($conso as $c): $w=round((float)$c['q']/$conso_max*100); ?>
          <div class="conso-row"><span class="nm"><?= h($c['nom']) ?></span><span class="track"><i style="width:<?= max($w,4) ?>%"></i></span><span class="qc"><?= rtrim(rtrim(number_format((float)$c['q'],2,'.',''),'0'),'.') ?></span></div>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($wShow('biomed')): ?>
      <div class="card" data-w="biomed">
        <div class="card-h"><span class="cic">🩺</span><span class="ttl">Biomédical</span><a class="link" href="biomed/dashboard.php">Voir →</a></div>
        <div class="card-b"><div class="mini">
          <div class="t" style="--mc:var(--text3)"><span class="v" style="color:var(--text)"><?= $biomed_total ?></span><span class="k">Total</span></div>
          <div class="t" style="--mc:<?= $biomed_hs>0?'var(--rd)':'var(--grn)' ?>"><span class="v" style="color:<?= $biomed_hs>0?'var(--rd)':'var(--text)' ?>"><?= $biomed_hs ?></span><span class="k">HS</span></div>
          <div class="t" style="--mc:var(--amb)"><span class="v" style="color:<?= $biomed_maint>0?'var(--amb)':'var(--text)' ?>"><?= $biomed_maint ?></span><span class="k">Maint.</span></div>
        </div></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($secShow(['ready','act','agenda'])): ?>
  <div class="db-lab" data-sec="ready,act,agenda">🎒 Terrain &amp; dotation</div>
  <div class="grid">
    <div class="col">
      <?php if ($wShow('ready')): ?>
      <div class="card" data-w="ready">
        <div class="card-h"><span class="cic">🎯</span><span class="ttl">Prêt à partir</span><a class="link" href="locations.php">Emplacements →</a></div>
        <div class="card-b" style="padding-bottom:8px"><div class="mini">
          <div class="t" style="--mc:var(--grn)"><span class="v" style="color:var(--grn)"><?= $dot_prets ?></span><span class="k">✅ Prêts</span></div>
          <div class="t" style="--mc:<?= $dot_incomplets>0?'var(--amb)':'var(--grn)' ?>"><span class="v" style="color:<?= $dot_incomplets>0?'var(--amb)':'var(--text)' ?>"><?= $dot_incomplets ?></span><span class="k">À compléter</span></div>
        </div></div>
        <?php foreach (array_slice(array_values(array_filter($dot_sacs, fn($s)=>$s['completude']<100)),0,5) as $s): $c=$s['completude']>=70?'var(--amb)':'var(--rd)'; ?>
        <a class="row" href="locations.php?sel=<?= $s['id'] ?>"><div class="nm"><?= h($s['nom']) ?><small><?= $s['manquant'] ?> manquant<?= $s['manquant']>1?'s':'' ?><?= $s['insuffisant']>0?' · '.$s['insuffisant'].' insuff.':'' ?></small></div><div class="pbar"><i style="width:<?= $s['completude'] ?>%;background:<?= $c ?>"></i></div><div class="pc" style="color:<?= $c ?>"><?= $s['completude'] ?>%</div></a>
        <?php endforeach; ?>
        <?php if ($dot_incomplets===0): ?><div style="padding:14px 17px;text-align:center;color:var(--grn);font-weight:700;font-size:.85rem">✅ Tous les sacs sont conformes</div><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="col">
      <?php if ($wShow('act')): ?>
      <div class="card" data-w="act">
        <div class="card-h"><span class="cic">🕑</span><span class="ttl">Activité récente</span></div>
        <?php foreach ($activite as $a): ?>
        <div class="tl"><span class="ic" style="background:<?= $a['col'] ?>1f;color:<?= $a['col'] ?>"><?= $a['ic'] ?></span><span class="info"><div class="tt"><?= $a['tt'] ?></div><div class="sb"><?= $a['sb'] ?></div></span><span class="dd"><?= date('d/m · H:i',strtotime($a['d'])) ?></span></div>
        <?php endforeach; ?>
        <?php if (empty($activite)): ?><div style="padding:16px;text-align:center;color:var(--text3);font-size:.82rem">Aucune activité récente.</div><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($wShow('agenda')): ?>
      <div class="card" data-w="agenda">
        <div class="card-h"><span class="cic">🗓️</span><span class="ttl">Prochains contrôles</span></div>
        <?php foreach ($agenda as $a): ?>
        <div class="agr"><span class="ic"><?= $a['ic'] ?></span><span class="t"><?= h($a['t']) ?><small><?= h($a['s']) ?></small></span><span class="due <?= $a['cls'] ?>"><?= h($a['due']) ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($wShow('stup')): ?>
  <div class="db-lab" data-sec="stup">🔒 Réglementé — Stupéfiants</div>
  <div class="grid">
    <div class="col" style="grid-column:1 / -1;max-width:640px">
      <div class="card stup" data-w="stup">
        <div class="card-h"><span class="cic" style="background:rgba(139,92,246,.13);color:var(--vio)">🔒</span><span class="ttl">Coffre à stupéfiants</span><a class="link" href="stupefiants.php">Ouvrir →</a></div>
        <div class="card-b">
          <?php if ($dbStupReg): ?>
          <div class="stup-grid">
            <div class="stup-tile"><div class="v" style="color:var(--vio)"><?= rtrim(rtrim(number_format($stx_solde,2,'.',''),'0'),'.') ?></div><div class="k">Ampoules en coffre</div></div>
            <div class="stup-tile"><div class="k" style="margin-bottom:6px">Dernier contrôle</div><?php if($stx_ctl_days===null):?><span class="chip late">⏰ Jamais</span><?php elseif($stx_ctl_days>30):?><span class="chip late">⏰ En retard (<?= $stx_ctl_days ?> j)</span><?php else:?><span class="chip ok">✅ Il y a <?= $stx_ctl_days ?> j</span><?php endif;?></div>
          </div>
          <?php if ($stx_mv): ?>
          <div class="k" style="font-size:.7rem;color:var(--text3);font-weight:700">Derniers mouvements</div>
          <?php foreach ($stx_mv as $m): $lbl=['administration'=>'💉 Administration','entree'=>'➕ Réappro','casse'=>'💥 Casse','perte'=>'❓ Perte','vol'=>'🚨 Vol','destruction'=>'🗑️ Destruction'][$m['type']]??$m['type']; $qq=(float)$m['qte_ampoules']; ?>
          <div class="stup-mv"><span class="d"><?= date('d/m H:i',strtotime($m['cree_le'])) ?></span><span class="t"><?= $lbl ?> <small>· <?= h($m['agent']?:'—') ?></small></span><span class="q <?= $qq>=0?'pos':'neg' ?>"><?= ($qq>0?'+':'').rtrim(rtrim(number_format($qq,2,'.',''),'0'),'.') ?></span></div>
          <?php endforeach; ?>
          <?php else: ?><div class="locknote">Aucun mouvement enregistré pour l'instant.</div><?php endif; ?>
          <?php else: ?>
          <div class="stup-grid">
            <div class="stup-tile"><div class="v" style="color:var(--vio)"><?= rtrim(rtrim(number_format($stx_solde,2,'.',''),'0'),'.') ?></div><div class="k">Dernier solde publié</div></div>
            <div class="stup-tile"><div class="k" style="margin-bottom:6px">Contrôle du coffre</div><?php if($stx_ctl_days!==null && $stx_ctl_days<=30):?><span class="chip ok">✅ Suivi à jour</span><?php else:?><span class="chip late">⏰ À contrôler</span><?php endif;?></div>
          </div>
          <div class="locknote">🔒 Le <b>détail du registre</b> (mouvements, administrations, agents) est réservé aux personnes ayant le droit <b>« Voir le registre »</b>.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /#dbx -->

<!-- Drawer perso -->
<div id="dbx-scrim" onclick="dbxDrawer(false)"></div>
<aside id="dbx-drawer">
  <div class="dh">⚙️ <span>Mon tableau de bord</span><button onclick="dbxDrawer(false)">✕</button></div>
  <div class="db">
    <p class="note">Choisis les widgets à afficher et la densité. Préférences propres à ton compte.</p>
    <div class="presets">
      <button class="preset" onclick="dbxPreset('lecteur')">👁️ Lecteur</button>
      <button class="preset" onclick="dbxPreset('gestionnaire')">🗂️ Gestion.</button>
      <button class="preset" onclick="dbxPreset('tout')">👑 Tout</button>
    </div>
    <div class="densrow">Densité <div class="seg"><button class="<?= $prefDensity!=='compact'?'on':'' ?>" onclick="dbxDensity('cosy',this)">Confort</button><button class="<?= $prefDensity==='compact'?'on':'' ?>" onclick="dbxDensity('compact',this)">Compact</button></div></div>
    <div id="dbx-wlist"></div>
  </div>
  <div class="df"><button class="g" onclick="dbxDrawer(false)">Fermer</button><button class="p" onclick="dbxSave()">💾 Enregistrer</button></div>
</aside>

<script>
(function(){
  var CSRF = (document.querySelector('meta[name=csrf-token]')||{}).content || '';
  var CAT = <?= json_encode(array_map(fn($k)=>['k'=>$k,'ic'=>$WLABEL[$k][0],'nm'=>$WLABEL[$k][1],'gate'=>(bool)($wGate[$k]??false)], array_keys($WLABEL))) ?>;
  var hidden = <?= json_encode(array_values($prefHidden)) ?>;
  var density = '<?= $prefDensity ?>';
  var dbx = document.getElementById('dbx');
  function isHidden(k){return hidden.indexOf(k)>=0;}
  function apply(){
    CAT.forEach(function(w){ document.querySelectorAll('[data-w="'+w.k+'"]').forEach(function(el){ el.style.display=(!isHidden(w.k))?'':'none'; }); });
    // étiquettes de section : visibles seulement si au moins un de leurs widgets l'est
    document.querySelectorAll('#dbx .db-lab[data-sec]').forEach(function(lab){
      var ks=(lab.getAttribute('data-sec')||'').split(',');
      var any=ks.some(function(k){ return !isHidden(k); });
      lab.style.display = any ? '' : 'none';
    });
    dbx.className = density==='compact'?'compact':'';
  }
  window.dbxDrawer=function(o){document.getElementById('dbx-drawer').classList.toggle('open',o);document.getElementById('dbx-scrim').classList.toggle('open',o);if(o)renderList();};
  function renderList(){
    document.getElementById('dbx-wlist').innerHTML = CAT.map(function(w){
      var dis = w.gate ? '' : ' style="opacity:.4;pointer-events:none"';
      return '<div class="wt '+(!isHidden(w.k)&&w.gate?'on':'')+'"'+dis+' onclick="dbxToggle(\''+w.k+'\')"><span class="ic">'+w.ic+'</span><span class="nm">'+w.nm+(w.gate?'':' <small style=\'color:var(--text3)\'>(non autorisé)</small>')+'</span><span class="sw"></span></div>';
    }).join('');
  }
  window.dbxToggle=function(k){ var i=hidden.indexOf(k); if(i>=0)hidden.splice(i,1); else hidden.push(k); apply(); renderList(); };
  window.dbxDensity=function(m,btn){ density=m; btn.parentNode.querySelectorAll('button').forEach(function(b){b.classList.remove('on');}); btn.classList.add('on'); apply(); };
  window.dbxPreset=function(role){
    var sets={lecteur:['ai','todo','kpi','surv','chart','meteo'],gestionnaire:['ai','todo','kpi','surv','chart','meteo','conso','ready','act','agenda','biomed'],tout:CAT.map(function(w){return w.k;})};
    var on=sets[role]||[]; hidden = CAT.filter(function(w){return on.indexOf(w.k)<0;}).map(function(w){return w.k;}); apply(); renderList();
  };
  window.dbxSave=function(){
    var body='order=[]&hidden='+encodeURIComponent(JSON.stringify(hidden))+'&density='+density+'&csrf_token='+encodeURIComponent(CSRF);
    fetch('dashboard.php?ajax=save_layout',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF},body:body})
      .then(function(r){return r.json();}).then(function(d){ if(d.ok){ location.reload(); } else { if(typeof showToast==='function')showToast('Erreur d\'enregistrement','error'); } })
      .catch(function(){ if(typeof showToast==='function')showToast('Erreur réseau','error'); });
  };
  // Copilote
  function esc(s){return (s+'').replace(/&/g,'&amp;').replace(/</g,'&lt;');}
  window.dbxCopilote=function(q){
    var box=document.getElementById('dbx-ai-txt'); if(!box)return;
    box.style.color='var(--text3)'; box.innerHTML='<span class="dots"><i></i><i></i><i></i></span> le copilote analyse…';
    var body = q?('q='+encodeURIComponent(q)):''; body += (body?'&':'')+'csrf_token='+encodeURIComponent(CSRF);
    fetch('dashboard.php?ajax=copilote',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF},body:body})
      .then(function(r){return r.json();}).then(function(d){ if(d.ok){box.style.color='var(--text2)';box.textContent=d.texte;}else{box.style.color='var(--rd)';box.textContent=d.error||'Erreur';} })
      .catch(function(){ box.style.color='var(--rd)'; box.textContent='Erreur réseau.'; });
  };
  window.dbxAsk=function(){
    var i=document.getElementById('dbx-ai-input'); var q=(i.value||'').trim(); if(!q)return;
    var th=document.getElementById('dbx-ai-thread');
    th.insertAdjacentHTML('beforeend','<div class="msg u"><span class="av">🧑</span><div class="bub">'+esc(q)+'</div></div>');
    var think=document.createElement('div'); think.className='msg a'; think.innerHTML='<span class="av">🤖</span><div class="bub dots"><i></i><i></i><i></i></div>'; th.appendChild(think);
    i.value='';
    var body='q='+encodeURIComponent(q)+'&csrf_token='+encodeURIComponent(CSRF);
    fetch('dashboard.php?ajax=copilote',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF},body:body})
      .then(function(r){return r.json();}).then(function(d){ think.innerHTML='<span class="av">🤖</span><div class="bub">'+(d.ok?esc(d.texte):('⚠️ '+esc(d.error||'Erreur')))+'</div>'; think.scrollIntoView({block:'nearest'}); })
      .catch(function(){ think.innerHTML='<span class="av">🤖</span><div class="bub">⚠️ Erreur réseau.</div>'; });
  };
  // count-up + apparition
  function countUp(el){var m=(el.textContent||'').trim().match(/^(\D*)(\d+)(\D*)$/);if(!m)return;var pre=m[1],tg=parseInt(m[2],10),su=m[3],t0=null;el.textContent=pre+'0'+su;function st(ts){if(!t0)t0=ts;var p=Math.min((ts-t0)/800,1);var e=p<.5?2*p*p:1-Math.pow(-2*p+2,2)/2;el.textContent=pre+Math.round(tg*e)+su;if(p<1)requestAnimationFrame(st);}requestAnimationFrame(st);}
  window.addEventListener('load',function(){
    document.querySelectorAll('#dbx .kpi .n, #dbx .mini .v, #dbx .stup-tile .v').forEach(countUp);
    document.querySelectorAll('#dbx .ai, #dbx .todo, #dbx .kpis, #dbx .grid').forEach(function(b,i){ b.style.animationDelay=(i*60)+'ms'; b.classList.add('rise'); });
  });
  apply();
})();
</script>
</div><!-- /desktop-only -->

<?php require_once 'includes/footer.php'; ?>
