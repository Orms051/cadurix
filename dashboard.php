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
               e.nom AS empl_nom, ep.nom AS empl_parent,
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

  <!-- Alerte critique si nécessaire -->
  <?php if (!empty($rappels_actifs) || ($expired > 0 && nav_peut($nav_perms,'medicaments'))): ?>
  <a href="/products.php" class="m-alert-banner">
    <span class="m-alert-icon">🚨</span>
    <div>
      <?php if (!empty($rappels_actifs)): ?>
      <div><?= count($rappels_actifs) ?> lot(s) rappelé(s) ANSM — action requise</div>
      <?php elseif ($expired > 0): ?>
      <div><?= $expired ?> produit(s) périmé(s) — à retirer</div>
      <?php endif; ?>
      <div style="font-size:.72rem;opacity:.7;margin-top:2px">Appuyer pour voir →</div>
    </div>
  </a>
  <?php endif; ?>

  <!-- KPIs compacts -->
  <div class="m-kpi-row">
    <?php if (nav_peut($nav_perms,'medicaments')): ?>
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
    <?php endif; ?>
    <?php if (nav_peut($nav_perms,'biomed') && $biomed_total > 0): ?>
    <a href="/biomed/dashboard.php" class="m-kpi" style="border-top-color:#8b5cf6">
      <div class="m-kpi-icon">🔬</div>
      <div class="m-kpi-val" style="color:#a78bfa"><?= $biomed_hs ?></div>
      <div class="m-kpi-lbl">Biomed HS</div>
    </a>
    <?php elseif (nav_peut($nav_perms,'emplacements')): ?>
    <a href="/emplacements.php" class="m-kpi" style="border-top-color:#0ea5e9">
      <div class="m-kpi-icon">📍</div>
      <div class="m-kpi-val" style="color:#38bdf8"><?= $emplCount ?></div>
      <div class="m-kpi-lbl">Emplacements</div>
    </a>
    <?php endif; ?>
    <?php if (nav_peut($nav_perms,'messagerie')): ?>
    <a href="/messaging.php" class="m-kpi" style="border-top-color:<?= $msg_non_lus>0?'#3b82f6':'var(--border)' ?>">
      <div class="m-kpi-icon">💬</div>
      <div class="m-kpi-val" style="color:<?= $msg_non_lus>0?'#60a5fa':'var(--text2)' ?>"><?= $msg_non_lus ?></div>
      <div class="m-kpi-lbl">Messages</div>
    </a>
    <?php endif; ?>
  </div>

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

  <!-- Activité récente -->
  <?php if (nav_peut($nav_perms,'rempotages') && !empty($recent_remp)): ?>
  <div class="m-section-title">Activité récente</div>
  <div class="m-activity-list">
    <?php if (nav_peut($nav_perms,'rempotages')): foreach (array_slice($recent_remp, 0, 3) as $r): ?>
    <a href="/rempotages.php" class="m-activity-item">
      <div class="m-activity-icon" style="background:rgba(16,185,129,.1)">🔄</div>
      <div class="m-activity-info">
        <div class="m-activity-name">Rempotage — <?= h($r['empl_nom'] ?? '—') ?></div>
        <div class="m-activity-sub"><?= h($r['prenom'] ?? '') ?> · <?= $r['nb'] ?> article(s)</div>
      </div>
      <div class="m-activity-meta"><?= date('H:i', strtotime($r['cree_le'])) ?></div>
    </a>
    <?php endforeach; endif; ?>
  </div>
  <?php endif; ?>

  <!-- Produits urgents -->
  <?php if (nav_peut($nav_perms,'medicaments') && !empty($urgent)): ?>
  <div class="m-section-title">Urgences péremption</div>
  <div class="m-activity-list">
    <?php foreach (array_slice($urgent, 0, 4) as $u): ?>
    <?php
      $j = (int)($u['jours'] ?? 0);
      $col = $j < 0 ? '#ef4444' : ($j <= 7 ? '#f97316' : '#fbbf24');
    ?>
    <a href="/products.php" class="m-activity-item">
      <div class="m-activity-icon" style="background:<?= $col ?>18">💊</div>
      <div class="m-activity-info">
        <div class="m-activity-name"><?= h($u['nom']) ?></div>
        <div class="m-activity-sub"><?= h($u['empl_nom'] ?? '') ?></div>
      </div>
      <div class="m-activity-meta" style="color:<?= $col ?>;font-weight:700">
        <?= $j < 0 ? 'PÉRIMÉ' : ($j === 0 ? 'Auj.' : $j.'j') ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div><!-- /mobile-only -->

<!-- ── Contenu desktop uniquement (masqué sur mobile) ── -->
<div class="desktop-only">

<!-- ── Bannière score + infos ── -->
<div class="db-hero">
  <div>
    <div style="font-size:1.3rem;font-weight:800"><?= $site_info ? h($site_info['org_nom'].' — '.$site_info['nom']) : '' ?></div>
    <div style="font-size:.82rem;color:var(--text3);margin-top:2px">📅 <?= $date_fr ?> · <?= date('H:i') ?></div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <?php if ($msg_non_lus > 0): ?>
    <a href="messaging.php" style="display:flex;align-items:center;gap:7px;padding:8px 14px;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25);border-radius:9px;text-decoration:none;font-size:.82rem;font-weight:600;color:#60a5fa">
      💬 <?= $msg_non_lus ?> message<?= $msg_non_lus>1?'s':'' ?> non lu<?= $msg_non_lus>1?'s':'' ?>
    </a>
    <?php endif; ?>
    <div class="db-score">
      <div class="db-score-circle" style="background:<?= $score_color ?>22;color:<?= $score_color ?>;border:2px solid <?= $score_color ?>44"><?= $score_global ?>%</div>
      <div>
        <div style="font-weight:700;font-size:.92rem">État opérationnel</div>
        <div style="font-size:.78rem;color:<?= $score_color ?>;font-weight:600"><?= $score_label ?></div>
      </div>
    </div>
  </div>
</div>

<!-- ── À traiter maintenant (actions urgentes consolidées) ── -->
<?php
$todo = [];
if (!empty($rappels_actifs)) {
    $n = count($rappels_actifs);
    $todo[] = ['ic'=>'🧯','bg'=>'rgba(239,68,68,.12)',
        'tt'=>"$n lot".($n>1?'s':'')." rappelé".($n>1?'s':'')." (ANSM)",
        'sb'=>implode(' · ', array_map(fn($r)=>h($r['numero_lot']), array_slice($rappels_actifs,0,3))).' — à isoler',
        'go'=>'Voir','lien'=>'tracabilite.php'];
}
if (nav_peut($nav_perms,'medicaments') && $expired > 0) {
    $todo[] = ['ic'=>'🔴','bg'=>'rgba(239,68,68,.12)',
        'tt'=>"$expired produit".($expired>1?'s':'')." périmé".($expired>1?'s':''),
        'sb'=>'À retirer du stock','go'=>'Retirer','lien'=>'alerts.php'];
}
if (nav_peut($nav_perms,'biomed') && $biomed_hs > 0) {
    $todo[] = ['ic'=>'🔧','bg'=>'rgba(239,68,68,.12)',
        'tt'=>"$biomed_hs matériel".($biomed_hs>1?'s':'')." hors service",
        'sb'=>'Intervention nécessaire','go'=>'Voir','lien'=>'materiel.php'];
}
if ($dot_incomplets > 0) {
    $_pire = $dot_sacs[0]['nom'] ?? '';
    $todo[] = ['ic'=>'🎯','bg'=>'rgba(245,158,11,.14)',
        'tt'=>"$dot_incomplets sac".($dot_incomplets>1?'s':'')." incomplet".($dot_incomplets>1?'s':'')." (dotation)",
        'sb'=>'À compléter'.($_pire!==''?" — ex. « ".h($_pire)." »":''),'go'=>'Voir','lien'=>'locations.php'.(isset($dot_sacs[0]['id'])?'?sel='.$dot_sacs[0]['id']:'')];
}
if (!empty($todo)):
?>
<div class="db-todo">
  <div class="db-todo-head">
    <span style="font-size:1.15rem">🚨</span>
    <span class="t">À traiter maintenant</span>
    <span class="c"><?= count($todo) ?> action<?= count($todo)>1?'s':'' ?></span>
  </div>
  <?php foreach ($todo as $it): ?>
  <a href="<?= $it['lien'] ?>" class="db-todo-item">
    <span class="db-todo-ic" style="background:<?= $it['bg'] ?>"><?= $it['ic'] ?></span>
    <span class="db-todo-tx">
      <span class="db-todo-tt" style="display:block"><?= $it['tt'] ?></span>
      <span class="db-todo-sb" style="display:block"><?= $it['sb'] ?></span>
    </span>
    <span class="db-todo-go"><?= $it['go'] ?> →</span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// ── Chiffres clés (allégés : l'essentiel, 4 max) — étape 2 ──
// Les détails (biomed, SSE, graphique…) restent dans les cartes plus bas ;
// les urgences sont déjà dans « À traiter maintenant » en haut.
$kpis = [];
if (nav_peut($nav_perms,'medicaments')) {
  $kpis[] = ['v'=>$total,'l'=>'Produits en stock','i'=>'💊','c'=>'var(--primary)','link'=>'products.php','alert'=>false];
  $al_per = $expired + $critical;
  $kpis[] = ['v'=>$al_per,'l'=>'Alertes péremption','i'=>'⚠️','c'=>$al_per>0?'#ef4444':'#34d399','link'=>'alerts.php','alert'=>$al_per>0];
}
if (nav_peut($nav_perms,'messagerie')) {
  $kpis[] = ['v'=>$msg_non_lus,'l'=>'Messages non lus','i'=>'💬','c'=>$msg_non_lus>0?'#60a5fa':'var(--text2)','link'=>'messaging.php','alert'=>false];
}
if (count($kpis) < 4 && nav_peut($nav_perms,'biomed') && $biomed_total > 0) {
  $kpis[] = ['v'=>$biomed_hs,'l'=>'Biomédical HS','i'=>'🩺','c'=>$biomed_hs>0?'#ef4444':'#34d399','link'=>'materiel.php','alert'=>$biomed_hs>0];
}
if (count($kpis) < 4 && nav_peut($nav_perms,'sse')) {
  $kpis[] = ['v'=>$sse_alerte,'l'=>'Alertes SSE/NRBC','i'=>'🧪','c'=>$sse_alerte>0?'#8b5cf6':'var(--text2)','link'=>'sse.php','alert'=>$sse_alerte>0];
}
$kpis = array_slice($kpis, 0, 4);
if (!empty($kpis)):
?>
<!-- ── Chiffres clés (essentiel) ── -->
<div class="db-section-title">Chiffres clés</div>
<div class="db-kpi-grid" style="margin-bottom:24px">
  <?php foreach ($kpis as $k): $al = $k['alert']; ?>
  <a href="<?= $k['link'] ?>" class="db-kpi" style="border-top-color:<?= $k['c'] ?>;<?= $al?'background:rgba(239,68,68,.04);border-color:rgba(239,68,68,.25)':'' ?>">
    <div class="db-kpi-icon"><?= $k['i'] ?></div>
    <div class="db-kpi-n" style="color:<?= $k['c'] ?>"><?= $k['v'] ?></div>
    <div class="db-kpi-l"><?= $k['l'] ?></div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Contenu principal ── -->
<div class="db-cols">

<!-- Colonne gauche -->
<div>

  <!-- Produits à surveiller -->
  <?php if (nav_peut($nav_perms,'medicaments') && !empty($urgent)): ?>
  <div class="db-card" style="margin-bottom:20px">
    <div class="db-card-head">
      <div class="db-card-title">⚠️ Produits à surveiller</div>
      <a href="alerts.php" style="font-size:.72rem;color:var(--primary);text-decoration:none">Tout voir →</a>
    </div>
    <table class="db-urg-table">
      <thead><tr>
        <th>Produit</th><th>Emplacement</th><th style="text-align:right">Péremption</th><th style="text-align:right">Statut</th>
      </tr></thead>
      <tbody>
      <?php foreach ($urgent as $u):
        $j = (int)($u['jours'] ?? 0);
        $color = $j<0?'#ef4444':($j<=7?'#f97316':($j<=30?'#eab308':'#10b981'));
        $badge = $j<0?'Périmé':($j===0?'Auj.':($j<=7?$j.'j':date('d/m/Y',strtotime($u['date_peremption']))));
        $low = ($u['quantite_min']>0 && $u['quantite']<=$u['quantite_min']);
      ?>
      <tr>
        <td style="font-weight:600"><?= h($u['nom']) ?></td>
        <td style="color:var(--text2);font-size:.78rem"><?= $u['empl_parent']?h($u['empl_parent']).' › ':'' ?><?= h($u['empl_nom']) ?></td>
        <td style="text-align:right;color:var(--text2);font-size:.78rem"><?= $u['date_peremption']?date('d/m/Y',strtotime($u['date_peremption'])):'—' ?></td>
        <td style="text-align:right">
          <?php if ($low): ?>
          <span style="padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:700;background:rgba(139,92,246,.12);color:#8b5cf6">Stock bas</span>
          <?php else: ?>
          <span style="padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:700;background:<?= $color ?>18;color:<?= $color ?>"><?= $badge ?></span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>


  <?php if (nav_peut($nav_perms,'medicaments')): ?>
  <!-- Graphique péremptions -->
  <div class="db-card" style="margin-bottom:20px">
    <div class="db-card-head">
      <div class="db-card-title">📅 Péremptions par mois</div>
    </div>
    <div style="padding:14px 16px">
      <?php $max_nb = max(max(array_column($chart_mois,'nb')),1); ?>
      <div class="db-chart">
        <?php foreach ($chart_mois as $m):
          $pct = round($m['nb']/$max_nb*100);
          $c = $m['mois']===0?'#ef4444':($m['mois']<=1?'#f97316':($m['mois']<=2?'#eab308':'rgba(var(--primary-rgb),.4)'));
        ?>
        <div class="db-bar">
          <div style="flex:1;width:100%;display:flex;align-items:flex-end">
            <div class="db-bar-fill" title="<?= $m['label'] ?> : <?= $m['nb'] ?>" style="height:<?= max($pct,2) ?>%;background:<?= $c ?>"
                 onmouseover="this.style.opacity='.7'" onmouseout="this.style.opacity='1'"></div>
          </div>
          <?php if ($m['nb']>0): ?><div style="font-size:.55rem;color:var(--text2);font-weight:600"><?= $m['nb'] ?></div><?php endif; ?>
          <div class="db-bar-lbl"><?= $m['label'] ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /col gauche -->

<!-- Colonne droite -->
<div>

  <!-- Cockpit dotation : prêt à partir -->
  <?php if (!empty($dot_sacs)): ?>
  <div class="db-card">
    <div class="db-card-head">
      <div class="db-card-title">🎯 Prêt à partir</div>
      <a href="locations.php" style="font-size:.72rem;color:var(--primary);text-decoration:none">Emplacements →</a>
    </div>
    <div style="padding:12px 16px 4px;display:flex;gap:10px">
      <div style="flex:1;text-align:center;padding:10px;background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.2);border-radius:9px">
        <div style="font-size:1.3rem;font-weight:800;color:#34d399"><?= $dot_prets ?></div>
        <div style="font-size:.68rem;color:var(--text3)">✅ Prêts</div>
      </div>
      <div style="flex:1;text-align:center;padding:10px;background:<?= $dot_incomplets>0?'rgba(245,158,11,.06)':'var(--bg3)' ?>;border:1px solid <?= $dot_incomplets>0?'rgba(245,158,11,.2)':'var(--border)' ?>;border-radius:9px">
        <div style="font-size:1.3rem;font-weight:800;color:<?= $dot_incomplets>0?'#fbbf24':'var(--text)' ?>"><?= $dot_incomplets ?></div>
        <div style="font-size:.68rem;color:var(--text3)">À compléter</div>
      </div>
    </div>
    <?php foreach (array_slice(array_values(array_filter($dot_sacs, fn($s)=>$s['completude']<100)), 0, 5) as $s):
      $c = $s['completude']>=70?'#fbbf24':'#f87171'; ?>
    <a href="locations.php?sel=<?= $s['id'] ?>" class="db-row" style="text-decoration:none;color:var(--text)">
      <div style="flex:1;min-width:0">
        <div style="font-size:.82rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($s['nom']) ?></div>
        <div style="font-size:.68rem;color:var(--text3)"><?= $s['manquant'] ?> manquant<?= $s['manquant']>1?'s':'' ?><?= $s['insuffisant']>0?' · '.$s['insuffisant'].' insuff.':'' ?></div>
      </div>
      <div style="width:64px;height:6px;background:var(--border);border-radius:4px;overflow:hidden;flex-shrink:0"><div style="width:<?= $s['completude'] ?>%;height:100%;background:<?= $c ?>"></div></div>
      <div style="font-size:.78rem;font-weight:700;color:<?= $c ?>;width:36px;text-align:right"><?= $s['completude'] ?>%</div>
    </a>
    <?php endforeach; ?>
    <?php if ($dot_incomplets===0): ?>
    <div style="padding:14px 16px;text-align:center;color:#34d399;font-size:.85rem;font-weight:600">✅ Tous les sacs sont conformes</div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Biomédical -->
  <?php if (nav_peut($nav_perms,'biomed') && $biomed_total > 0): ?>
  <div class="db-card">
    <div class="db-card-head">
      <div class="db-card-title">🩺 Biomédical</div>
      <a href="biomed/dashboard.php" style="font-size:.72rem;color:var(--primary);text-decoration:none">Voir →</a>
    </div>
    <div style="padding:12px 16px;display:flex;gap:10px">
      <div style="flex:1;text-align:center;padding:10px;background:var(--bg3);border-radius:9px">
        <div style="font-size:1.3rem;font-weight:800"><?= $biomed_total ?></div>
        <div style="font-size:.68rem;color:var(--text3)">Total</div>
      </div>
      <div style="flex:1;text-align:center;padding:10px;background:<?= $biomed_hs>0?'rgba(239,68,68,.06)':'var(--bg3)' ?>;border:1px solid <?= $biomed_hs>0?'rgba(239,68,68,.2)':'var(--border)' ?>;border-radius:9px">
        <div style="font-size:1.3rem;font-weight:800;color:<?= $biomed_hs>0?'#f87171':'var(--text)' ?>"><?= $biomed_hs ?></div>
        <div style="font-size:.68rem;color:var(--text3)">HS</div>
      </div>
      <div style="flex:1;text-align:center;padding:10px;background:<?= $biomed_maint>0?'rgba(245,158,11,.06)':'var(--bg3)' ?>;border:1px solid <?= $biomed_maint>0?'rgba(245,158,11,.2)':'var(--border)' ?>;border-radius:9px">
        <div style="font-size:1.3rem;font-weight:800;color:<?= $biomed_maint>0?'#fbbf24':'var(--text)' ?>"><?= $biomed_maint ?></div>
        <div style="font-size:.68rem;color:var(--text3)">Maint.</div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Activité récente (rempotages + fiches + interventions consolidés) -->
  <?php
  $activite = [];
  if (nav_peut($nav_perms,'rempotages') && !empty($recent_remp)) {
    foreach ($recent_remp as $r) $activite[] = [
      'ic'=>'🔄','col'=>'#10b981','d'=>$r['cree_le'],
      'tt'=>'Rempotage — '.h($r['empl_nom'] ?? '—'),
      'sb'=>((int)$r['nb']).' article(s) · '.h($r['prenom'] ?? ''),
    ];
  }
  if (nav_peut($nav_perms,'fiches') && !empty($recent_fiches)) {
    foreach ($recent_fiches as $f) $activite[] = [
      'ic'=>'📋','col'=>$f['statut']==='anomalie'?'#ef4444':'#10b981','d'=>$f['cree_le'],
      'tt'=>h($f['modele_nom']),
      'sb'=>h($f['prenom'] ?? '').' · '.($f['statut']==='anomalie'?'<span style="color:#f87171">Anomalie</span>':'<span style="color:#34d399">OK</span>'),
    ];
  }
  if (nav_peut($nav_perms,'terrain') && !empty($recent_inter)) {
    foreach ($recent_inter as $it) $activite[] = [
      'ic'=>'🚑','col'=>$it['statut']==='termine'?'#10b981':'#f59e0b','d'=>$it['cree_le'],
      'tt'=>'Intervention '.($it['reference']?h($it['reference']):'sans réf.'),
      'sb'=>((int)$it['nb']).' article(s) · '.h($it['prenom'] ?? ''),
    ];
  }
  usort($activite, fn($a,$b)=>strtotime($b['d']) <=> strtotime($a['d']));
  $activite = array_slice($activite, 0, 6);
  if (!empty($activite)):
  ?>
  <div class="db-card">
    <div class="db-card-head">
      <div class="db-card-title">🕑 Activité récente</div>
    </div>
    <?php foreach ($activite as $a): ?>
    <div class="db-row">
      <div class="db-dot" style="background:<?= $a['col'] ?>"></div>
      <div style="flex:1;min-width:0">
        <div style="font-size:.8rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= $a['ic'] ?> <?= $a['tt'] ?></div>
        <div style="font-size:.68rem;color:var(--text3)"><?= $a['sb'] ?></div>
      </div>
      <div style="font-size:.68rem;color:var(--text3);white-space:nowrap"><?= date('d/m H:i',strtotime($a['d'])) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (nav_peut($nav_perms,'sse') && $sse_alerte > 0): ?>
  <div class="db-card" style="background:rgba(99,102,241,.05);border-color:rgba(99,102,241,.25)">
    <div class="db-card-head" style="background:rgba(99,102,241,.08)">
      <div class="db-card-title" style="color:#818cf8">🧪 SSE / NRBC — Alertes</div>
      <a href="sse.php" class="btn btn-sm" style="background:#818cf8;color:white;border:none;padding:3px 10px;font-size:.72rem">Voir →</a>
    </div>
    <div style="padding:12px 16px;font-size:.85rem;color:var(--text2)">
      <?= $sse_alerte ?> consommable<?= $sse_alerte>1?'s':'' ?> à traiter
      <?= $sse_conso_perime>0?" · <strong style='color:#f87171'>$sse_conso_perime périmé(s)</strong>":'' ?>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /col droite -->
</div><!-- /db-cols -->

</div><!-- /desktop-only -->

<?php require_once 'includes/footer.php'; ?>
