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

require_once '../includes/header.php';
?>
<style>
.bio-section-title {
  display:flex; align-items:center; justify-content:space-between;
  margin:24px 0 12px; padding-bottom:8px;
  border-bottom:2px solid var(--border);
}
.bio-section-title h2 {
  font-size:.75rem; font-weight:800; text-transform:uppercase;
  letter-spacing:2px; color:var(--text3);
}
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
    <a href="../materiel.php" class="btn btn-ghost btn-sm">📋 Tous les équipements</a>
    <a href="../materiel_intervention.php" class="btn btn-primary btn-sm">🔧 Nouvelle intervention</a>
  </div>
</div>

<!-- KPIs du jour -->
<div class="grid-4" style="margin-bottom:8px">
  <div class="kpi-card" style="border-top:3px solid <?= $nb_rev_en_retard>0?'#9ca3af':'var(--border)' ?>">
    <div class="kpi-label">Révisions en retard</div>
    <div class="kpi-value" style="color:<?= $nb_rev_en_retard>0?'#9ca3af':'var(--text3)' ?>"><?= $nb_rev_en_retard ?></div>
    <div class="kpi-foot">à régulariser</div>
  </div>
  <div class="kpi-card" style="border-top:3px solid <?= $nb_rev_urgentes>0?'#ef4444':'#10b981' ?>">
    <div class="kpi-label">Révisions urgentes</div>
    <div class="kpi-value" style="color:<?= $nb_rev_urgentes>0?'#ef4444':'#10b981' ?>"><?= $nb_rev_urgentes ?></div>
    <div class="kpi-foot">dans 7 jours</div>
  </div>
  <div class="kpi-card" style="border-top:3px solid <?= $nb_demandes>0?'#f59e0b':'#10b981' ?>">
    <div class="kpi-label">Demandes en attente</div>
    <div class="kpi-value" style="color:<?= $nb_demandes>0?'#f59e0b':'#10b981' ?>"><?= $nb_demandes ?></div>
    <div class="kpi-foot">à traiter</div>
  </div>
  <div class="kpi-card" style="border-top:3px solid <?= $nb_alertes>0?'#ef4444':'#10b981' ?>">
    <div class="kpi-label">Équipements indisponibles</div>
    <div class="kpi-value" style="color:<?= $nb_alertes>0?'#ef4444':'#10b981' ?>"><?= $nb_alertes ?></div>
    <div class="kpi-foot">HS + maintenance</div>
  </div>
</div>

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

<?php require_once '../includes/footer.php'; ?>
