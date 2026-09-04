<?php
/**
 * Cadurix v5.1 — Admin : Dashboard maintenance biomédical
 * Accessible : Administrateur, Super Admin
 */
require_once '../includes/auth.php';
requireAdmin();
$pageTitle = 'Admin — Maintenance biomédical';
$site_id   = site_actif();

// ── Budget ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_budget'])) {
    csrf_check();
    $annee  = (int)$_POST['annee'];
    $budget = (float)$_POST['budget'];
    $sid    = (int)$_POST['site_id'];
    $pdo->prepare("INSERT INTO materiel_budget (site_id,annee,budget) VALUES(?,?,?) ON DUPLICATE KEY UPDATE budget=?")->execute([$sid,$annee,$budget,$budget]);
    flashSuccess("Budget $annee mis à jour.");
    header('Location: materiel.php'); exit;
}

// ── Stats globales ────────────────────────────────────────
$stats_statut = $pdo->query("SELECT statut, COUNT(*) AS nb FROM materiels WHERE site_id=".($site_id?:0)." GROUP BY statut")->fetchAll(PDO::FETCH_KEY_PAIR);
$total        = array_sum($stats_statut);

// Révisions urgentes (60 jours)
$revisions = $pdo->query("
    SELECT r.*, m.nom AS mat_nom, m.id AS mat_id, m.site_id,
           s.nom AS site_nom, mt.icone,
           DATEDIFF(r.prochaine_revision, CURDATE()) AS jours
    FROM materiel_revisions r
    JOIN materiels m  ON m.id=r.materiel_id
    JOIN sites s      ON s.id=m.site_id
    LEFT JOIN materiel_types mt ON mt.id=m.type_id
    WHERE r.actif=1 AND r.prochaine_revision IS NOT NULL
      AND r.prochaine_revision <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
      ".($site_id?"AND m.site_id=$site_id":'')."
    ORDER BY r.prochaine_revision ASC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Équipements HS
$hs = $pdo->query("
    SELECT m.*, mt.icone, mt.nom AS type_nom, e.nom AS empl_nom, s.nom AS site_nom,
           DATEDIFF(NOW(), m.statut_date) AS jours_hs
    FROM materiels m
    LEFT JOIN materiel_types mt ON mt.id=m.type_id
    LEFT JOIN emplacements e    ON e.id=m.emplacement_id
    LEFT JOIN sites s           ON s.id=m.site_id
    WHERE m.statut='hors_service'
    ".($site_id?"AND m.site_id=$site_id":'')."
    ORDER BY m.statut_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Interventions récentes
$interventions_recentes = $pdo->query("
    SELECT i.*, m.nom AS mat_nom, m.id AS mat_id,
           u.prenom, u.nom AS user_nom
    FROM materiel_interventions i
    JOIN materiels m ON m.id=i.materiel_id
    LEFT JOIN utilisateurs u ON u.id=i.user_id
    WHERE 1=1 ".($site_id?"AND m.site_id=$site_id":'')."
    ORDER BY i.cree_le DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Demandes en attente
$demandes = $pdo->query("
    SELECT d.*, m.nom AS mat_nom, m.id AS mat_id,
           u.prenom, u.nom AS user_nom
    FROM materiel_demandes d
    JOIN materiels m ON m.id=d.materiel_id
    LEFT JOIN utilisateurs u ON u.id=d.demandeur_id
    WHERE d.statut IN ('en_attente','en_cours')
    ".($site_id?"AND m.site_id=$site_id":'')."
    ORDER BY FIELD(d.priorite,'urgente','haute','normale'), d.cree_le ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Biomédicaux actifs
$biomedicals = $pdo->query("
    SELECT u.*, usp.site_id, s.nom AS site_nom,
           (SELECT COUNT(*) FROM materiel_interventions i JOIN materiels m ON m.id=i.materiel_id WHERE i.user_id=u.id AND m.site_id=usp.site_id) AS nb_interventions
    FROM utilisateurs u
    JOIN user_site_permissions usp ON usp.user_id=u.id AND usp.role='biomedical'
    LEFT JOIN sites s ON s.id=usp.site_id
    WHERE u.actif=1
    ".($site_id?"AND usp.site_id=$site_id":'')."
    ORDER BY u.nom
")->fetchAll(PDO::FETCH_ASSOC);

// Budget
$annee_budget = date('Y');
$budgets = $pdo->query("
    SELECT mb.*, s.nom AS site_nom,
           COALESCE((SELECT SUM(i.cout) FROM materiel_interventions i JOIN materiels m ON m.id=i.materiel_id WHERE m.site_id=mb.site_id AND YEAR(i.date_intervention)=mb.annee),0) AS depenses
    FROM materiel_budget mb
    JOIN sites s ON s.id=mb.site_id
    WHERE mb.annee=$annee_budget
    ".($site_id?"AND mb.site_id=$site_id":'')."
")->fetchAll(PDO::FETCH_ASSOC);

// Sites pour budget
$sites_list = $pdo->query("SELECT id, nom FROM sites WHERE actif=1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>
<style>
.rev-row{display:flex;align-items:center;gap:.75rem;padding:.6rem .875rem;border-radius:8px;margin-bottom:.4rem;font-size:.82rem}
.dem-row{display:flex;align-items:center;gap:.75rem;padding:.6rem .875rem;background:var(--glass);border:1px solid var(--border);border-radius:8px;margin-bottom:.4rem;font-size:.82rem}
.budget-bar{height:6px;background:var(--border2);border-radius:99px;overflow:hidden;margin-top:.3rem}
.budget-fill{height:100%;border-radius:99px}
.biomed-card{background:var(--glass);border:1px solid var(--border);border-radius:10px;padding:.875rem;margin-bottom:.5rem}
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">🔧 Admin — Maintenance biomédical</div>
    <div class="page-sub">Vue globale des équipements, révisions et biomédicaux</div>
  </div>
  <a href="../materiel.php" class="btn btn-primary">🔧 Voir les équipements</a>
</div>

<!-- KPIs -->
<style>
.akpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:1.5rem}
@media(max-width:900px){.akpi-grid{grid-template-columns:1fr 1fr}}
.akpi{position:relative;display:flex;align-items:center;gap:12px;background:var(--bg2);border:1px solid var(--border);border-radius:13px;padding:12px 15px;overflow:hidden;box-shadow:0 1px 2px rgba(20,30,60,.04)}
.akpi::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--kc,var(--primary))}
.akpi .ic{width:36px;height:36px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.05rem}
.akpi .n{font-size:1.5rem;font-weight:800;line-height:1;font-variant-numeric:tabular-nums}
.akpi .l{font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em;font-weight:700;margin-top:3px}
</style>
<div class="akpi-grid">
  <div class="akpi" style="--kc:#10b981"><span class="ic" style="background:#10b9811f;color:#10b981">✅</span><div><div class="n" style="color:#10b981"><?= $stats_statut['en_service']??0 ?></div><div class="l">En service</div></div></div>
  <div class="akpi" style="--kc:#f59e0b"><span class="ic" style="background:#f59e0b1f;color:#f59e0b">🔧</span><div><div class="n" style="color:#f59e0b"><?= $stats_statut['maintenance']??0 ?></div><div class="l">En maintenance</div></div></div>
  <div class="akpi" style="--kc:#ef4444"><span class="ic" style="background:#ef44441f;color:#ef4444">🔴</span><div><div class="n" style="color:#ef4444"><?= count($hs) ?></div><div class="l">Hors service</div></div></div>
  <div class="akpi" style="--kc:#3b82f6"><span class="ic" style="background:#3b82f61f;color:#3b82f6">🩺</span><div><div class="n" style="color:#3b82f6"><?= count($biomedicals) ?></div><div class="l">Biomédicaux</div></div></div>
</div>

<div class="grid-2" style="margin-bottom:1.25rem">

  <!-- Révisions urgentes -->
  <div class="card">
    <div class="card-title">⏰ Révisions à venir (60 jours)</div>
    <?php if (empty($revisions)): ?>
    <p style="color:var(--text3);font-size:.85rem">✅ Aucune révision dans les 60 prochains jours.</p>
    <?php else: foreach ($revisions as $r):
      $j=(int)$r['jours'];
      $bg=$j<0?'rgba(107,114,128,.08)':($j<=15?'rgba(239,68,68,.08)':($j<=30?'rgba(245,158,11,.08)':'rgba(16,185,129,.06)'));
      $col=$j<0?'#9ca3af':($j<=15?'#ef4444':($j<=30?'#f59e0b':'#10b981'));
      $lbl=$j<0?"Retard ".abs($j)."j":($j===0?"Aujourd'hui":"J-$j");
    ?>
    <div class="rev-row" style="background:<?= $bg ?>;border:1px solid <?= str_replace('.08','.2',$bg) ?>">
      <span style="font-size:1rem"><?= h($r['icone']??'🔧') ?></span>
      <div style="flex:1;min-width:0">
        <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($r['mat_nom']) ?></div>
        <div style="font-size:.72rem;color:var(--text3)"><?= h($r['nom']) ?> · <?= h($r['site_nom']) ?></div>
      </div>
      <span style="color:<?= $col ?>;font-weight:700;font-size:.8rem;flex-shrink:0"><?= $lbl ?></span>
      <a href="../materiel_fiche.php?id=<?= $r['mat_id'] ?>&tab=revisions" class="btn btn-ghost btn-xs">→</a>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Équipements HS -->
  <div class="card">
    <div class="card-title">🔴 Équipements hors service (<?= count($hs) ?>)</div>
    <?php if (empty($hs)): ?>
    <p style="color:var(--text3);font-size:.85rem">✅ Aucun équipement hors service.</p>
    <?php else: foreach ($hs as $h): ?>
    <div class="dem-row">
      <span><?= h($h['icone']??'🔧') ?></span>
      <div style="flex:1;min-width:0">
        <div style="font-weight:600"><?= h($h['nom']) ?></div>
        <div style="font-size:.72rem;color:var(--text3)"><?= h($h['site_nom']) ?><?= $h['empl_nom']?' · '.h($h['empl_nom']):'' ?></div>
      </div>
      <span style="color:#9ca3af;font-size:.75rem;flex-shrink:0"><?= $h['jours_hs'] ?>j</span>
      <a href="../materiel_fiche.php?id=<?= $h['id'] ?>" class="btn btn-ghost btn-xs">→</a>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="grid-2" style="margin-bottom:1.25rem">

  <!-- Demandes en attente -->
  <div class="card">
    <div class="card-title">📩 Demandes d'intervention (<?= count($demandes) ?>)</div>
    <?php if (empty($demandes)): ?>
    <p style="color:var(--text3);font-size:.85rem">✅ Aucune demande en attente.</p>
    <?php else: foreach ($demandes as $d):
      $pc=match($d['priorite']){'urgente'=>'#ef4444','haute'=>'#f59e0b',default=>'#64748b'};
    ?>
    <div class="dem-row">
      <span style="color:<?= $pc ?>;font-size:.8rem;font-weight:700;flex-shrink:0"><?= match($d['priorite']){'urgente'=>'⚡','haute'=>'🔺',default=>'·'} ?></span>
      <div style="flex:1;min-width:0">
        <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($d['titre']) ?></div>
        <div style="font-size:.72rem;color:var(--text3)"><?= h($d['mat_nom']) ?> · <?= date('d/m/Y',strtotime($d['cree_le'])) ?></div>
      </div>
      <span class="badge" style="font-size:.68rem;background:<?= $d['statut']==='en_cours'?'rgba(59,130,246,.15)':'rgba(245,158,11,.12)' ?>;color:<?= $d['statut']==='en_cours'?'#60a5fa':'#fbbf24' ?>"><?= $d['statut']==='en_cours'?'En cours':'En attente' ?></span>
      <a href="../materiel_fiche.php?id=<?= $d['mat_id'] ?>&tab=demandes" class="btn btn-ghost btn-xs">→</a>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Interventions récentes -->
  <div class="card">
    <div class="card-title">🔧 Interventions récentes</div>
    <?php if (empty($interventions_recentes)): ?>
    <p style="color:var(--text3);font-size:.85rem">Aucune intervention.</p>
    <?php else: foreach ($interventions_recentes as $i):
      $types=['revision_periodique'=>'🔄','reparation'=>'🔨','remplacement_piece'=>'🔩','mise_a_jour'=>'💾','verification'=>'✅','checklist_reception'=>'📋','autre'=>'🔧'];
    ?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.8rem">
      <span style="font-size:1rem"><?= $types[$i['type']]??'🔧' ?></span>
      <div style="flex:1;min-width:0">
        <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($i['mat_nom']) ?></div>
        <div style="font-size:.72rem;color:var(--text3)"><?= h(($i['prenom']??'').' '.($i['user_nom']??'')) ?> · <?= date('d/m/Y',strtotime($i['date_intervention'])) ?></div>
      </div>
      <?php if($i['cout']): ?><span style="font-size:.75rem;color:var(--text3)"><?= number_format($i['cout'],0,'.',',') ?> €</span><?php endif; ?>
      <a href="../materiel_fiche.php?id=<?= $i['mat_id'] ?>" class="btn btn-ghost btn-xs">→</a>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- Biomédicaux + Budget -->
<div class="grid-2">

  <!-- Biomédicaux -->
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.875rem">
      <div class="card-title" style="margin-bottom:0">👨‍🔬 Biomédicaux actifs</div>
      <a href="users.php?role=biomedical" class="btn btn-ghost btn-sm">+ Ajouter</a>
    </div>
    <?php if (empty($biomedicals)): ?>
    <p style="color:var(--text3);font-size:.85rem">Aucun biomédical assigné.</p>
    <?php else: foreach ($biomedicals as $b): ?>
    <div class="biomed-card">
      <div style="display:flex;align-items:center;gap:.75rem">
        <div style="width:36px;height:36px;border-radius:50%;background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.25);display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;flex-shrink:0">
          <?= strtoupper(substr($b['prenom']??'?',0,1)) ?>
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;font-size:.875rem"><?= h(($b['prenom']??'').' '.($b['nom']??'')) ?></div>
          <div style="font-size:.73rem;color:var(--text3)"><?= h($b['site_nom']??'') ?> · <?= $b['nb_interventions'] ?> intervention(s)</div>
        </div>
        <?php if ($b['expire_le'] && strtotime($b['expire_le'])<time()): ?>
        <span class="badge badge-critical" style="font-size:.68rem">Expiré</span>
        <?php elseif ($b['expire_le']): ?>
        <span style="font-size:.7rem;color:var(--text3)">exp. <?= date('d/m/Y',strtotime($b['expire_le'])) ?></span>
        <?php endif; ?>
        <a href="users.php?edit=<?= $b['id'] ?>" class="btn btn-ghost btn-xs">✏️</a>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Budget -->
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.875rem">
      <div class="card-title" style="margin-bottom:0">💰 Budget maintenance <?= $annee_budget ?></div>
    </div>

    <!-- Budgets existants -->
    <?php foreach ($budgets as $b):
      $pct=$b['budget']>0?min(100,round($b['depenses']/$b['budget']*100)):0;
      $col=$pct>=90?'#ef4444':($pct>=70?'#f59e0b':'#10b981');
    ?>
    <div style="margin-bottom:.875rem;padding:.75rem;background:var(--glass);border:1px solid var(--border);border-radius:8px">
      <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:.3rem">
        <span style="font-weight:600"><?= h($b['site_nom']) ?></span>
        <span style="color:<?= $col ?>;font-weight:700"><?= number_format($b['depenses'],0,'.',',') ?> / <?= number_format($b['budget'],0,'.',',') ?> €</span>
      </div>
      <div class="budget-bar"><div class="budget-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
      <div style="font-size:.7rem;color:var(--text3);margin-top:.25rem"><?= $pct ?>% consommé</div>
    </div>
    <?php endforeach; ?>

    <!-- Formulaire budget -->
    <div style="border-top:1px solid var(--border);padding-top:.875rem;margin-top:.5rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text2)">Définir un budget</div>
      <form method="POST" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
        <?= csrf_field() ?>
        <input type="hidden" name="save_budget" value="1">
        <div style="flex:1;min-width:120px">
          <label class="form-label" style="font-size:.72rem">Site</label>
          <select name="site_id" class="form-control form-control-sm">
            <?php foreach ($sites_list as $s): ?>
            <option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="width:70px">
          <label class="form-label" style="font-size:.72rem">Année</label>
          <input type="number" name="annee" class="form-control form-control-sm" value="<?= $annee_budget ?>" min="2020" max="2099">
        </div>
        <div style="width:100px">
          <label class="form-label" style="font-size:.72rem">Budget (€)</label>
          <input type="number" name="budget" class="form-control form-control-sm" placeholder="5000" step="100" min="0" required>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">💾</button>
      </form>
    </div>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
