<?php
/**
 * Cadurix v5.4 — Portail biomédical
 * Vue technicien : ce que j'ai à faire aujourd'hui
 */
require_once '../includes/auth.php';
$pageTitle = 'Portail biomédical';

$site_id = site_actif();
if (!$site_id) { header('Location: ../dashboard.php'); exit; }

// ── Révisions dues ou à venir 30j ─────────────────────────
$revisions = $pdo->query("
    SELECT r.*, m.nom AS mat_nom, m.id AS mat_id, m.statut AS mat_statut,
           mt.icone, DATEDIFF(r.prochaine_revision, CURDATE()) AS jours
    FROM materiel_revisions r
    JOIN materiels m ON m.id=r.materiel_id
    LEFT JOIN materiel_types mt ON mt.id=m.type_id
    WHERE m.site_id=$site_id AND r.actif=1
      AND r.prochaine_revision IS NOT NULL
      AND r.prochaine_revision <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY r.prochaine_revision ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Demandes en attente ───────────────────────────────────
$demandes = $pdo->query("
    SELECT d.*, m.nom AS mat_nom, m.id AS mat_id,
           u.prenom, u.nom AS user_nom,
           mt.icone
    FROM materiel_demandes d
    JOIN materiels m ON m.id=d.materiel_id
    LEFT JOIN materiel_types mt ON mt.id=m.type_id
    LEFT JOIN utilisateurs u ON u.id=d.demandeur_id
    WHERE m.site_id=$site_id AND d.statut IN ('en_attente','en_cours')
    ORDER BY FIELD(d.priorite,'urgente','haute','normale'), d.cree_le ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Équipements HS ou maintenance ─────────────────────────
$alertes = $pdo->query("
    SELECT m.*, mt.icone, mt.nom AS type_nom, e.nom AS empl_nom
    FROM materiels m
    LEFT JOIN materiel_types mt ON mt.id=m.type_id
    LEFT JOIN emplacements e ON e.id=m.emplacement_id
    WHERE m.site_id=$site_id AND m.statut IN ('hors_service','maintenance')
    ORDER BY FIELD(m.statut,'hors_service','maintenance'), m.nom
")->fetchAll(PDO::FETCH_ASSOC);

// ── Mes dernières interventions ───────────────────────────
$mes_interventions = $pdo->query("
    SELECT i.*, m.nom AS mat_nom, m.id AS mat_id, mt.icone
    FROM materiel_interventions i
    JOIN materiels m ON m.id=i.materiel_id
    LEFT JOIN materiel_types mt ON mt.id=m.type_id
    WHERE m.site_id=$site_id AND i.user_id=".currentUserId()."
    ORDER BY i.date_intervention DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ── Stats du jour ─────────────────────────────────────────
$nb_rev_en_retard = count(array_filter($revisions, fn($r) => (int)$r['jours'] < 0));
$nb_rev_urgentes  = count(array_filter($revisions, fn($r) => (int)$r['jours'] >= 0 && (int)$r['jours'] <= 7));
$nb_demandes      = count($demandes);
$nb_alertes       = count($alertes);

// ── Garanties & contrats à échéance (≤ 60 j) ──
$garanties = $pdo->query("
    SELECT m.id, m.nom, mt.icone, m.date_fin_garantie, m.contrat_fin, m.contrat_maintenance,
           LEAST(COALESCE(m.date_fin_garantie,'9999-12-31'), COALESCE(m.contrat_fin,'9999-12-31')) AS echeance
    FROM materiels m LEFT JOIN materiel_types mt ON mt.id=m.type_id
    WHERE m.site_id=$site_id AND m.statut NOT IN('reforme','archive')
      AND ((m.date_fin_garantie IS NOT NULL AND m.date_fin_garantie <= DATE_ADD(CURDATE(), INTERVAL 60 DAY))
        OR (m.contrat_fin IS NOT NULL AND m.contrat_fin <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)))
    ORDER BY echeance ASC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
$nb_garanties  = count($garanties);
$cout_contrats = (float)$pdo->query("SELECT COALESCE(SUM(contrat_cout_annuel),0) FROM materiels WHERE site_id=$site_id AND statut NOT IN('reforme','archive')")->fetchColumn();
$cout_inter_an = 0.0;
try { $cout_inter_an = (float)$pdo->query("SELECT COALESCE(SUM(i.cout),0) FROM materiel_interventions i JOIN materiels m ON m.id=i.materiel_id WHERE m.site_id=$site_id AND YEAR(i.date_intervention)=YEAR(NOW())")->fetchColumn(); } catch(Throwable $e){}

// ── Analyse IA de l'état biomédical ──
if (($_GET['ajax'] ?? '') === 'ia') {
    header('Content-Type: application/json');
    if (!function_exists('csrf_verifier') || !csrf_verifier()) { echo json_encode(['error'=>'Session expirée, recharge la page.']); exit; }
    $ai_actif = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='actif'")->fetchColumn();
    $api_key  = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='api_key'")->fetchColumn();
    $model    = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='model'")->fetchColumn() ?: 'claude-haiku-4-5-20251001';
    if ($ai_actif !== '1' || !$api_key) { echo json_encode(['error'=>'IA non configurée (Admin → IA).']); exit; }
    // Coût de maintenance par équipement (réparations + contrat) + pannes + âge → rentabilité
    $couteux = $pdo->query("
        SELECT m.nom, m.date_mise_en_service, COALESCE(m.contrat_cout_annuel,0) AS contrat,
               COUNT(i.id) AS nb_inter,
               SUM(CASE WHEN i.type='reparation' THEN 1 ELSE 0 END) AS nb_pannes,
               COALESCE(SUM(i.cout),0) AS cout_inter
        FROM materiels m LEFT JOIN materiel_interventions i ON i.materiel_id=m.id
        WHERE m.site_id=$site_id AND m.statut NOT IN('reforme','archive')
        GROUP BY m.id
        HAVING cout_inter > 0 OR contrat > 0 OR nb_pannes > 0
        ORDER BY (cout_inter + contrat) DESC
        LIMIT 12
    ")->fetchAll(PDO::FETCH_ASSOC);
    $ctx  = "Module biomedical (SDIS/SSSM).\n";
    $ctx .= "Revisions a 30j : ".count($revisions)." (en retard : $nb_rev_en_retard, urgentes 7j : $nb_rev_urgentes).\n";
    $ctx .= "Demandes d'intervention en attente : $nb_demandes. Equipements HS/maintenance : $nb_alertes.\n";
    $ctx .= "Garanties/contrats a echeance (<=60j) : $nb_garanties. Cout contrats/an : ".round($cout_contrats)." EUR. Cout interventions ".date('Y')." : ".round($cout_inter_an)." EUR.\n";
    if ($revisions){ $ctx.="Revisions a planifier :\n"; foreach(array_slice($revisions,0,8) as $r){ $j=(int)$r['jours']; $ctx.="- ".$r['mat_nom']." (".($j<0?'retard '.abs($j).'j':'J-'.$j).(!empty($r['obligatoire'])?', reglementaire':'').").\n"; } }
    if ($alertes){ $ctx.="Equipements indisponibles :\n"; foreach($alertes as $a){ $ctx.="- ".$a['nom']." (".$a['statut'].").\n"; } }
    if ($garanties){ $ctx.="Echeances garanties/contrats :\n"; foreach($garanties as $g){ $ctx.="- ".$g['nom']." (echeance ".$g['echeance'].").\n"; } }
    if ($couteux){ $ctx.="Cout de possession par equipement (le plus cher d'abord) :\n"; foreach($couteux as $m){ $age=$m['date_mise_en_service']?floor((time()-strtotime($m['date_mise_en_service']))/31536000):null; $ctx.="- ".$m['nom']." : reparations ".round($m['cout_inter'])." EUR sur ".(int)$m['nb_pannes']." panne(s), contrat ".round($m['contrat'])." EUR/an".($age!==null?", age ".$age." ans":"").".\n"; } }
    $prompt = "Tu es le copilote de Cadurix, module biomedical (SDIS/SSSM). A partir des donnees ci-dessous, redige en francais un point clair et priorise, sans markdown, sans rien inventer, pas de conseil medical :\n"
        . "1) 1 phrase de synthese.\n"
        . "2) 3 a 5 actions a mener en priorite (revisions a planifier surtout reglementaires ; equipements HS a reparer ; demandes a traiter ; garanties/contrats a renouveler).\n"
        . "3) RENTABILITE : identifie les equipements qui COUTENT TROP CHER (cout de reparations eleve, pannes repetees, contrat cher, age eleve) et indique clairement lesquels envisager de REFORMER ou REMPLACER, avec la raison chiffree. Si aucun n'est problematique, dis-le.\n\n"
        . "DONNEES :\n$ctx";
    $is_prod = str_contains($_SERVER['HTTP_HOST'] ?? '', 'cadurix.fr');
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode(['model'=>$model,'max_tokens'=>500,'messages'=>[['role'=>'user','content'=>$prompt]]]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-api-key: '.$api_key,'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT=>40, CURLOPT_SSL_VERIFYPEER=>$is_prod, CURLOPT_SSL_VERIFYHOST=>$is_prod?2:0]);
    $resp=curl_exec($ch); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); $cerr=curl_error($ch); curl_close($ch);
    if ($resp===false||$cerr){ echo json_encode(['error'=>"Reseau IA : $cerr"]); exit; }
    $jr=json_decode($resp,true);
    if ($http!==200||empty($jr['content'][0]['text'])){ echo json_encode(['error'=>($jr['error']['message']??"Erreur IA HTTP $http")]); exit; }
    echo json_encode(['ok'=>true,'texte'=>$jr['content'][0]['text']], JSON_UNESCAPED_UNICODE); exit;
}

require_once '../includes/header.php';
?>
<style>
.bio-section-title {
  display:flex; align-items:center; justify-content:space-between;
  margin:22px 2px 12px;
}
.bio-section-title h2 {
  font-size:1rem; font-weight:800; letter-spacing:-.01em; color:var(--text); text-transform:none;
}
/* KPI compacts (style commun) */
.bio-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:8px}
.bio-kpi{position:relative;display:flex;align-items:center;gap:12px;background:var(--bg2);border:1px solid var(--border);border-radius:13px;padding:12px 15px;overflow:hidden;box-shadow:0 1px 2px rgba(20,30,60,.04)}
.bio-kpi::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--kc,var(--primary))}
.bio-kpi .ic{width:36px;height:36px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.05rem}
.bio-kpi .n{font-size:1.5rem;font-weight:800;line-height:1;font-variant-numeric:tabular-nums}
.bio-kpi .l{font-size:.68rem;color:var(--text3);font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-top:3px}
.bio-kpi .f{font-size:.68rem;color:var(--text3);margin-top:1px}
@media(max-width:800px){.bio-kpis{grid-template-columns:repeat(2,1fr)}}
.bio-ia{border:1px solid var(--border);border-radius:14px;background:linear-gradient(135deg,rgba(59,130,246,.09),rgba(139,92,246,.07)),var(--bg2);padding:16px 18px}
.bio-ia-h{display:flex;align-items:center;gap:10px;font-size:.95rem;margin-bottom:8px}
.bio-ia-orb{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#3b82f6,#8b5cf6);font-size:1rem}
.bio-ia-b{font-size:.87rem;color:var(--text2);line-height:1.6;white-space:pre-wrap}
.bio-chip{display:inline-flex;align-items:center;gap:5px;background:var(--bg2);border:1px solid var(--border);border-radius:20px;padding:8px 13px;font-size:.8rem;font-weight:700;color:var(--text2);cursor:pointer}
.bio-chip:hover{border-color:#8b5cf6;color:#8b5cf6}
.bio-gar{display:flex;align-items:center;gap:12px;padding:10px 16px;border-top:1px solid var(--border)}
.bio-gar:first-of-type{border-top:none}
.bio-gar .due{margin-left:auto;font-size:.72rem;font-weight:800;padding:3px 9px;border-radius:20px}
.bio-empty {
  text-align:center; padding:2rem; color:var(--text3);
  background:var(--glass); border:1px solid var(--border);
  border-radius:12px; font-size:.85rem;
}

/* Carte révision */
.rev-card {
  display:flex; align-items:center; gap:12px;
  padding:12px 16px; border-radius:10px;
  border:1px solid var(--border); background:var(--bg2);
  margin-bottom:6px; transition:border-color .15s;
}
.rev-card:hover { border-color:var(--border2); }
.rev-card.urgent { border-color:rgba(239,68,68,.3); background:rgba(239,68,68,.04); }
.rev-card.warning { border-color:rgba(245,158,11,.3); background:rgba(245,158,11,.04); }
.rev-card.overdue { border-color:rgba(107,114,128,.3); background:rgba(107,114,128,.04); }

/* Carte demande */
.dem-card {
  display:flex; align-items:flex-start; gap:12px;
  padding:14px 16px; border-radius:10px;
  border:1px solid var(--border); background:var(--bg2);
  margin-bottom:6px;
}
.dem-card.urgente { border-left:3px solid #ef4444; }
.dem-card.haute   { border-left:3px solid #f59e0b; }
.dem-card.normale { border-left:3px solid #64748b; }
.dem-priorite {
  font-size:.65rem; font-weight:800; text-transform:uppercase;
  letter-spacing:.5px; padding:2px 8px; border-radius:20px;
}

/* Carte alerte équipement */
.eq-alerte {
  display:flex; align-items:center; gap:12px;
  padding:10px 16px; border-radius:10px;
  border:1px solid var(--border); background:var(--bg2);
  margin-bottom:6px;
}
.eq-alerte.hors_service { border-color:rgba(239,68,68,.3); }
.eq-alerte.maintenance  { border-color:rgba(245,158,11,.3); }

/* Timeline interventions */
.inter-item {
  display:flex; align-items:center; gap:12px;
  padding:10px 14px; border-radius:8px;
  background:var(--glass); border:1px solid var(--border);
  margin-bottom:5px; font-size:.82rem;
}
</style>

<!-- Header -->
<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">👨‍🔬 Portail biomédical</div>
    <div class="page-sub">Bonjour <?= h(trim(($_SESSION['user_prenom']??'').' '.($_SESSION['user_nom']??''))) ?> — <?= date('d/m/Y') ?></div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="../materiel_intervention.php" class="btn btn-primary btn-sm">🔧 Nouvelle intervention</a>
  </div>
</div>

<?php $bio_active = 'portail'; include __DIR__.'/_nav.php'; ?>

<!-- KPIs du jour -->
<div class="bio-kpis">
  <?php foreach ([
    ['⏰', $nb_rev_en_retard, 'Révisions en retard', 'à régulariser',   $nb_rev_en_retard>0?'#9ca3af':'#10b981'],
    ['⚠️', $nb_rev_urgentes,  'Révisions urgentes',  'dans 7 jours',    $nb_rev_urgentes>0?'#ef4444':'#10b981'],
    ['📩', $nb_demandes,      'Demandes en attente', 'à traiter',       $nb_demandes>0?'#f59e0b':'#10b981'],
    ['🔴', $nb_alertes,       'Équipements indispo.', 'HS + maintenance', $nb_alertes>0?'#ef4444':'#10b981'],
    ['🛡️', $nb_garanties,     'Garanties / contrats', 'échéance ≤ 60 j', $nb_garanties>0?'#f59e0b':'#10b981'],
    ['💶', number_format($cout_contrats,0,',',' ').'€', 'Contrats / an', 'coût maintenance', '#3b82f6'],
  ] as [$ic,$val,$lbl,$foot,$c]): ?>
  <div class="bio-kpi" style="--kc:<?= $c ?>">
    <span class="ic" style="background:<?= $c ?>1f;color:<?= $c ?>"><?= $ic ?></span>
    <div><div class="n" style="color:<?= $c ?>"><?= $val ?></div><div class="l"><?= $lbl ?></div><div class="f"><?= $foot ?></div></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- IA biomédical : analyse + questions à l'assistant -->
<div style="display:flex;gap:8px;margin:4px 0 10px;flex-wrap:wrap;align-items:center">
  <button type="button" id="bio-ia-btn" onclick="bioIA()" class="btn" style="background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;border:none">🤖 Analyser l'état (IA)</button>
  <span class="bio-chip" onclick="bioAsk('Quelles révisions dois-je planifier en priorité ?')">📅 Révisions à planifier</span>
  <span class="bio-chip" onclick="bioAsk('Quels équipements sont hors service et à réparer ?')">🔴 Équipements à réparer</span>
  <span class="bio-chip" onclick="bioAsk('Quelles garanties ou contrats expirent bientôt ?')">🛡️ Contrats à renouveler</span>
  <span class="bio-chip" onclick="bioIA()">💶 Équipements coûteux / à remplacer</span>
</div>
<div id="bio-ia-panel" style="display:none;margin-bottom:16px"></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

<!-- COLONNE GAUCHE -->
<div>

  <!-- Demandes d'intervention -->
  <div class="bio-section-title">
    <h2>📩 Demandes à traiter <?php if($nb_demandes): ?><span style="background:rgba(245,158,11,.15);color:#fbbf24;font-size:.65rem;padding:1px 7px;border-radius:10px;margin-left:6px"><?= $nb_demandes ?></span><?php endif; ?></h2>
  </div>
  <?php if (empty($demandes)): ?>
  <div class="bio-empty">✅ Aucune demande en attente</div>
  <?php else: foreach ($demandes as $d):
    $pc = match($d['priorite']??'normale') { 'urgente'=>'#ef4444','haute'=>'#f59e0b', default=>'#64748b' };
    $pi = match($d['priorite']??'normale') { 'urgente'=>'⚡','haute'=>'🔺', default=>'·' };
  ?>
  <div class="dem-card <?= h($d['priorite']??'normale') ?>">
    <span style="font-size:1.3rem"><?= h($d['icone']??'🔧') ?></span>
    <div style="flex:1;min-width:0">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
        <span style="font-weight:700;font-size:.88rem"><?= h($d['titre']??'Intervention') ?></span>
        <span class="dem-priorite" style="background:<?= $pc ?>22;color:<?= $pc ?>"><?= $pi ?> <?= h($d['priorite']??'normale') ?></span>
        <?php if ($d['statut']==='en_cours'): ?>
        <span style="font-size:.65rem;padding:1px 7px;border-radius:10px;background:rgba(59,130,246,.15);color:#60a5fa">En cours</span>
        <?php endif; ?>
      </div>
      <div style="font-size:.78rem;color:var(--text2);margin-bottom:2px">🔧 <?= h($d['mat_nom']) ?></div>
      <?php if ($d['description']): ?>
      <div style="font-size:.75rem;color:var(--text3)"><?= h(mb_substr($d['description'],0,80)).(mb_strlen($d['description'])>80?'…':'') ?></div>
      <?php endif; ?>
      <div style="font-size:.72rem;color:var(--text3);margin-top:4px">
        👤 <?= h(trim(($d['prenom']??'').' '.($d['user_nom']??''))) ?>
        · <?= date('d/m/Y', strtotime($d['cree_le'])) ?>
      </div>
    </div>
    <a href="../materiel_intervention.php?materiel_id=<?= $d['mat_id'] ?>" class="btn btn-primary btn-xs" style="flex-shrink:0">🔧 Intervenir</a>
  </div>
  <?php endforeach; endif; ?>

  <!-- Équipements indisponibles -->
  <div class="bio-section-title">
    <h2>🔴 Équipements indisponibles <?php if($nb_alertes): ?><span style="background:rgba(239,68,68,.15);color:#f87171;font-size:.65rem;padding:1px 7px;border-radius:10px;margin-left:6px"><?= $nb_alertes ?></span><?php endif; ?></h2>
  </div>
  <?php if (empty($alertes)): ?>
  <div class="bio-empty">✅ Tous les équipements sont opérationnels</div>
  <?php else: foreach ($alertes as $eq): ?>
  <div class="eq-alerte <?= h($eq['statut']) ?>">
    <span style="font-size:1.4rem"><?= h($eq['icone']??'🔧') ?></span>
    <div style="flex:1;min-width:0">
      <div style="font-weight:600;font-size:.88rem"><?= h($eq['nom']) ?></div>
      <div style="font-size:.75rem;color:var(--text3)">
        <?= h($eq['type_nom']??'') ?>
        <?php if ($eq['empl_nom']): ?> · 📍 <?= h($eq['empl_nom']) ?><?php endif; ?>
      </div>
    </div>
    <span style="font-size:.75rem;font-weight:700;padding:3px 10px;border-radius:20px;
      background:<?= $eq['statut']==='hors_service'?'rgba(239,68,68,.15)':'rgba(245,158,11,.15)' ?>;
      color:<?= $eq['statut']==='hors_service'?'#f87171':'#fbbf24' ?>">
      <?= $eq['statut']==='hors_service'?'🔴 Hors service':'🔧 Maintenance' ?>
    </span>
    <a href="../materiel_fiche.php?id=<?= $eq['id'] ?>" class="btn btn-ghost btn-xs">📋</a>
    <a href="../materiel_intervention.php?materiel_id=<?= $eq['id'] ?>" class="btn btn-primary btn-xs">🔧</a>
  </div>
  <?php endforeach; endif; ?>

</div>

<!-- COLONNE DROITE -->
<div>

  <!-- Révisions à planifier -->
  <div class="bio-section-title">
    <h2>⏰ Révisions à planifier <?php if(count($revisions)): ?><span style="background:rgba(239,68,68,.15);color:#f87171;font-size:.65rem;padding:1px 7px;border-radius:10px;margin-left:6px"><?= count($revisions) ?></span><?php endif; ?></h2>
  </div>
  <?php if (empty($revisions)): ?>
  <div class="bio-empty">✅ Aucune révision dans les 30 prochains jours</div>
  <?php else: foreach ($revisions as $r):
    $j = (int)$r['jours'];
    $cls = $j < 0 ? 'overdue' : ($j <= 7 ? 'urgent' : 'warning');
    $col = $j < 0 ? '#9ca3af' : ($j <= 7 ? '#ef4444' : '#f59e0b');
    $lbl = $j < 0 ? '⏰ Retard '.abs($j).'j' : ($j === 0 ? '⚠️ Aujourd\'hui' : "J-$j");
  ?>
  <div class="rev-card <?= $cls ?>">
    <span style="font-size:1.3rem"><?= h($r['icone']??'🔧') ?></span>
    <div style="flex:1;min-width:0">
      <div style="font-weight:600;font-size:.88rem"><?= h($r['mat_nom']) ?></div>
      <div style="font-size:.75rem;color:var(--text3)">
        <?= h($r['nom']) ?>
        <?php if ($r['obligatoire']): ?><span style="color:#f59e0b"> · ⚠️ Réglementaire</span><?php endif; ?>
        <?php if ($r['organisme']): ?> · <?= h($r['organisme']) ?><?php endif; ?>
      </div>
      <?php if ($r['prochaine_revision']): ?>
      <div style="font-size:.72rem;color:var(--text3);margin-top:2px">📅 <?= date('d/m/Y', strtotime($r['prochaine_revision'])) ?></div>
      <?php endif; ?>
    </div>
    <span style="font-size:.75rem;font-weight:700;color:<?= $col ?>;white-space:nowrap"><?= $lbl ?></span>
    <a href="../materiel_intervention.php?materiel_id=<?= $r['mat_id'] ?>&revision_id=<?= $r['id'] ?>" class="btn btn-primary btn-xs">✅ Effectuer</a>
  </div>
  <?php endforeach; endif; ?>

  <!-- Mes dernières interventions -->
  <div class="bio-section-title">
    <h2>🕐 Mes dernières interventions</h2>
  </div>
  <?php if (empty($mes_interventions)): ?>
  <div class="bio-empty">Aucune intervention enregistrée</div>
  <?php else: foreach ($mes_interventions as $i):
    $types = ['revision_periodique'=>'🔄','reparation'=>'🔨','remplacement_piece'=>'🔩','mise_a_jour'=>'💾','verification'=>'✅','autre'=>'🔧'];
    $ti = $types[$i['type']] ?? '🔧';
  ?>
  <div class="inter-item">
    <span><?= $ti ?></span>
    <div style="flex:1;min-width:0">
      <div style="font-weight:600;font-size:.85rem"><?= h($i['mat_nom']) ?></div>
      <div style="font-size:.72rem;color:var(--text3)"><?= h($i['type_libre'] ?? $i['type']) ?></div>
    </div>
    <span style="font-size:.75rem;color:var(--text3);white-space:nowrap"><?= date('d/m/Y', strtotime($i['date_intervention'])) ?></span>
    <?php if ($i['cout']): ?>
    <span style="font-size:.75rem;color:var(--text2)"><?= number_format((float)$i['cout'],0,'.',',') ?> €</span>
    <?php endif; ?>
    <a href="../materiel_fiche.php?id=<?= $i['mat_id'] ?>" class="btn btn-ghost btn-xs">📋</a>
  </div>
  <?php endforeach; endif; ?>

</div>
</div>

<!-- Garanties & contrats à échéance -->
<?php if (!empty($garanties)): ?>
<div class="card" style="margin-top:16px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span class="card-title">🛡️ Garanties & contrats à échéance (≤ 60 j)</span>
    <span style="font-size:.78rem;color:var(--text3)"><?= number_format($cout_contrats,0,',',' ') ?>€/an · interventions <?= date('Y') ?> : <?= number_format($cout_inter_an,0,',',' ') ?>€</span>
  </div>
  <?php foreach ($garanties as $g):
    $ech = ($g['echeance'] && $g['echeance']!=='9999-12-31') ? $g['echeance'] : null;
    $j   = $ech ? (int)floor((strtotime($ech)-time())/86400) : null;
    $col = ($j!==null && $j<0) ? '#ef4444' : '#f59e0b';
    $isGar = $g['date_fin_garantie'] && $ech===$g['date_fin_garantie'];
  ?>
  <div class="bio-gar">
    <span style="font-size:1.3rem"><?= h($g['icone']??'🔧') ?></span>
    <div style="flex:1;min-width:0">
      <div style="font-weight:600;font-size:.88rem"><?= h($g['nom']) ?></div>
      <div style="font-size:.74rem;color:var(--text3)">
        <?= $isGar ? '🛡️ Fin de garantie' : '📄 Fin de contrat'.($g['contrat_maintenance']?' — '.h($g['contrat_maintenance']):'') ?>
        <?php if ($ech): ?> · <?= date('d/m/Y', strtotime($ech)) ?><?php endif; ?>
      </div>
    </div>
    <span class="due" style="background:<?= $col ?>1f;color:<?= $col ?>"><?= $j===null?'—':($j<0?'expiré':($j===0?'aujourd\'hui':'J-'.$j)) ?></span>
    <a href="/materiel.php" class="btn btn-ghost btn-xs" title="Voir">📋</a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
var BIO_CSRF = <?= json_encode(function_exists('csrf_token') ? csrf_token() : '') ?>;
// Questions à l'assistant complet (outils biomed + action signaler, avec confirmation)
function bioAsk(q){
  if(window.MedAI && typeof MedAI.open==='function' && typeof MedAI.send==='function'){
    MedAI.open(); setTimeout(function(){ MedAI.send(q); }, 150);
  } else { bioIA(); }
}
function bioIA(){
  var b=document.getElementById('bio-ia-btn'), p=document.getElementById('bio-ia-panel');
  if(!p)return;
  p.style.display='block';
  p.innerHTML='<div class="bio-ia"><div class="bio-ia-h"><span class="bio-ia-orb">🤖</span><b>Analyse de l\'état biomédical</b></div><div class="bio-ia-b">⏳ Analyse en cours…</div></div>';
  if(b)b.disabled=true;
  fetch('/biomed/dashboard.php?ajax=ia',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':BIO_CSRF},body:'csrf_token='+encodeURIComponent(BIO_CSRF)})
    .then(function(r){return r.json();})
    .then(function(d){ if(b)b.disabled=false; var x=p.querySelector('.bio-ia-b'); if(d.ok){x.textContent=d.texte;}else{x.innerHTML='<span style="color:#ef4444">⚠️ '+(d.error||'Erreur')+'</span>';} })
    .catch(function(){ if(b)b.disabled=false; var x=p.querySelector('.bio-ia-b'); if(x)x.innerHTML='<span style="color:#ef4444">⚠️ Erreur réseau.</span>'; });
}
</script>
<?php require_once '../includes/footer.php'; ?>
