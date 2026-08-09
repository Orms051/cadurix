<?php
/**
 * Cadurix v5.1 — Gestion du matériel biomédical
 */
require_once 'includes/auth.php';
$pageTitle = 'Matériel biomédical';

$site_id = site_actif();
if (!$site_id) { header('Location: dashboard.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type = $_POST['type_form'] ?? '';

    if ($type === 'save_materiel' && peut_faire(ROLE_GESTIONNAIRE)) {
        $id = (int)($_POST['id'] ?? 0);
        $f  = [
            'emplacement_id'       => (int)($_POST['emplacement_id']??0)?:null,
            'type_id'              => (int)($_POST['type_id']??0)?:null,
            'nom'                  => trim($_POST['nom']??''),
            'marque'               => trim($_POST['marque']??'')?:null,
            'modele'               => trim($_POST['modele']??'')?:null,
            'numero_serie'         => trim($_POST['numero_serie']??'')?:null,
            'date_mise_en_service' => $_POST['date_mise_en_service']??null?:null,
            'date_fin_garantie'    => $_POST['date_fin_garantie']??null?:null,
            'contrat_maintenance'  => trim($_POST['contrat_maintenance']??'')?:null,
            'contrat_fin'          => $_POST['contrat_fin']??null?:null,
            'contrat_cout_annuel'  => $_POST['contrat_cout_annuel']!==''?(float)$_POST['contrat_cout_annuel']:null,
            'statut'               => $_POST['statut']??'en_service',
            'notes'                => trim($_POST['notes']??'')?:null,
        ];
        if (!$f['nom']) { flashError('Nom obligatoire.'); }
        else {
            if ($id) {
                $pdo->prepare("UPDATE materiels SET emplacement_id=?,type_id=?,nom=?,marque=?,modele=?,numero_serie=?,date_mise_en_service=?,date_fin_garantie=?,contrat_maintenance=?,contrat_fin=?,contrat_cout_annuel=?,statut=?,notes=? WHERE id=? AND site_id=?")
                    ->execute([$f['emplacement_id'],$f['type_id'],$f['nom'],$f['marque'],$f['modele'],$f['numero_serie'],$f['date_mise_en_service'],$f['date_fin_garantie'],$f['contrat_maintenance'],$f['contrat_fin'],$f['contrat_cout_annuel'],$f['statut'],$f['notes'],$id,$site_id]);
                audit_log($pdo,'MODIF_MATERIEL','materiels',$id);
                flashSuccess("Équipement mis à jour.");
            } else {
                $pdo->prepare("INSERT INTO materiels (site_id,emplacement_id,type_id,nom,marque,modele,numero_serie,date_mise_en_service,date_fin_garantie,contrat_maintenance,contrat_fin,contrat_cout_annuel,statut,notes,cree_par) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$site_id,$f['emplacement_id'],$f['type_id'],$f['nom'],$f['marque'],$f['modele'],$f['numero_serie'],$f['date_mise_en_service'],$f['date_fin_garantie'],$f['contrat_maintenance'],$f['contrat_fin'],$f['contrat_cout_annuel'],$f['statut'],$f['notes'],currentUserId()]);
                $newId = (int)$pdo->lastInsertId();
                audit_log($pdo,'CREATION_MATERIEL','materiels',$newId,null,$f);
                flashSuccess("Équipement créé.");
            }
        }
        header('Location: materiel.php'); exit;
    }

    if ($type === 'change_statut') {
        $id     = (int)($_POST['materiel_id']??0);
        $statut = $_POST['statut']??'';
        $valid  = ['commande','reception','en_service','maintenance','hors_service','reforme'];
        if ($id && in_array($statut,$valid)) {
            $pdo->prepare("UPDATE materiels SET statut=?,statut_date=NOW() WHERE id=? AND site_id=?")->execute([$statut,$id,$site_id]);
            if ($statut==='hors_service') {
                $nom = $pdo->prepare("SELECT nom FROM materiels WHERE id=?");
                $nom->execute([$id]); $nom = $nom->fetchColumn();
                // Notification gestionnaire
                $pdo->prepare("INSERT INTO notifications (type,titre,message,lien) VALUES('alerte',?,?,?)")
                    ->execute(["⚠️ Équipement hors service","$nom est hors service","/materiel_fiche.php?id=$id"]);
            }
            audit_log($pdo,'STATUT_MATERIEL','materiels',$id,null,['statut'=>$statut]);
            flashSuccess("Statut mis à jour.");
        }
        header('Location: materiel.php'); exit;
    }
}

// Données
$filtre_statut = $_GET['statut'] ?? '';
$where = "WHERE m.site_id=$site_id";
$params = [];
if ($filtre_statut) { $where .= " AND m.statut=?"; $params[] = $filtre_statut; }

$stmt = $pdo->prepare("
    SELECT m.*,
           mt.nom AS type_nom, mt.icone AS type_icone,
           e.nom AS empl_nom, ep.nom AS empl_parent,
           (SELECT COUNT(*) FROM materiel_interventions i WHERE i.materiel_id=m.id) AS nb_interventions,
           (SELECT SUM(i2.cout) FROM materiel_interventions i2 WHERE i2.materiel_id=m.id) AS cout_total,
           (SELECT MIN(r.prochaine_revision) FROM materiel_revisions r WHERE r.materiel_id=m.id AND r.actif=1 AND r.prochaine_revision IS NOT NULL) AS prochaine_revision
    FROM materiels m
    LEFT JOIN materiel_types mt ON mt.id=m.type_id
    LEFT JOIN emplacements e    ON e.id=m.emplacement_id
    LEFT JOIN emplacements ep   ON ep.id=e.parent_id
    $where
    ORDER BY FIELD(m.statut,'hors_service','maintenance','en_service','commande','reception','reforme'), m.nom
");
$stmt->execute($params);
$materiels = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stats = [];
$st_rows = $pdo->query("SELECT statut, COUNT(*) AS nb FROM materiels WHERE site_id=$site_id GROUP BY statut")->fetchAll(PDO::FETCH_ASSOC);
foreach ($st_rows as $r) $stats[$r['statut']] = $r['nb'];
$total = array_sum($stats);

$revisions_urgentes = $pdo->query("
    SELECT r.*, m.nom AS materiel_nom, m.id AS mat_id,
           DATEDIFF(r.prochaine_revision, CURDATE()) AS jours
    FROM materiel_revisions r
    JOIN materiels m ON m.id=r.materiel_id
    WHERE m.site_id=$site_id AND r.actif=1 AND r.prochaine_revision IS NOT NULL
      AND r.prochaine_revision <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    ORDER BY r.prochaine_revision LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$types = $pdo->query("SELECT * FROM materiel_types WHERE actif=1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$empls_stmt = $pdo->prepare("SELECT e.id,e.nom,ep.nom AS parent_nom FROM emplacements e LEFT JOIN emplacements ep ON e.parent_id=ep.id WHERE e.site_id=? AND e.actif=1 ORDER BY COALESCE(ep.nom,e.nom),e.nom");
$empls_stmt->execute([$site_id]);
$empls = $empls_stmt->fetchAll(PDO::FETCH_ASSOC);

$budget_annee = date('Y');
$budget_row = $pdo->prepare("SELECT * FROM materiel_budget WHERE site_id=? AND annee=?");
$budget_row->execute([$site_id,$budget_annee]);
$budget_row = $budget_row->fetch(PDO::FETCH_ASSOC);
$depenses = (float)($pdo->query("SELECT COALESCE(SUM(i.cout),0) FROM materiel_interventions i JOIN materiels m ON m.id=i.materiel_id WHERE m.site_id=$site_id AND YEAR(i.date_intervention)=$budget_annee")->fetchColumn());

$editing = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM materiels WHERE id=? AND site_id=?");
    $s->execute([(int)$_GET['edit'],$site_id]);
    $editing = $s->fetch(PDO::FETCH_ASSOC);
}

$statuts = [
    'commande'     =>['label'=>'Commandé',    'color'=>'#8b5cf6','icon'=>'📦'],
    'reception'    =>['label'=>'Réceptionné', 'color'=>'#06b6d4','icon'=>'📬'],
    'en_service'   =>['label'=>'En service',  'color'=>'#10b981','icon'=>'✅'],
    'maintenance'  =>['label'=>'Maintenance', 'color'=>'#f59e0b','icon'=>'🔧'],
    'hors_service' =>['label'=>'Hors service','color'=>'#ef4444','icon'=>'🔴'],
    'reforme'      =>['label'=>'Réformé',     'color'=>'#6b7280','icon'=>'⚫'],
];

require_once 'includes/header.php';
?>
<style>
/* ── Tableau biomédical ── */
.mat-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.mat-table thead tr { border-bottom:2px solid var(--border); }
.mat-table th {
  padding:10px 14px; text-align:left; font-size:.72rem; font-weight:700;
  color:var(--text3); text-transform:uppercase; letter-spacing:.5px;
  cursor:pointer; white-space:nowrap; user-select:none;
}
.mat-table th:hover { color:var(--text); }
.mat-table th .sort-icon { opacity:.4; margin-left:4px; font-size:.65rem; }
.mat-table th.sorted .sort-icon { opacity:1; color:var(--primary); }
.mat-table tbody tr {
  border-bottom:1px solid var(--border);
  transition:background .1s;
}
.mat-table tbody tr:hover { background:var(--glass); }
.mat-table td { padding:10px 14px; vertical-align:middle; }
.mat-badge {
  display:inline-flex; align-items:center; gap:4px;
  padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:600;
  white-space:nowrap;
}
.mat-actions { display:flex; gap:4px; flex-wrap:nowrap; }
.mat-name { font-weight:600; color:var(--text); }
.mat-sub  { font-size:.75rem; color:var(--text3); margin-top:1px; }
.mat-serie{ font-family:monospace; font-size:.75rem; color:var(--text2); }

/* Barre recherche + filtres */
.mat-toolbar {
  display:flex; gap:10px; margin-bottom:16px;
  align-items:center; flex-wrap:wrap;
}
.mat-search {
  flex:1; min-width:200px;
  background:var(--bg2); border:1px solid var(--border);
  border-radius:8px; padding:8px 14px; color:var(--text);
  font-family:inherit; font-size:.85rem; outline:none;
}
.mat-search:focus { border-color:var(--primary); }
.mat-filter-btn {
  padding:6px 14px; border-radius:8px; font-size:.78rem; font-weight:600;
  text-decoration:none; border:1px solid var(--border);
  background:var(--glass); color:var(--text2); transition:.15s;
  white-space:nowrap;
}
.mat-filter-btn:hover { border-color:var(--primary); color:var(--primary); }
.mat-filter-btn.active { background:var(--primary); border-color:var(--primary); color:white; }

/* Révision urgente */
.rev-badge {
  font-size:.7rem; padding:1px 7px; border-radius:10px; font-weight:600;
}
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">🔧 Matériel biomédical</div>
    <div class="page-sub">Équipements, révisions et maintenance</div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="/qrcodes.php?type=biomed" class="btn btn-ghost">🏷️ QR Codes</a>
    <?php if (peut_faire(ROLE_GESTIONNAIRE)): ?>
    <button class="btn btn-primary" onclick="toggleForm()">➕ Ajouter</button>
    <?php endif; ?>
  </div>
</div>

<!-- KPIs -->
<div class="grid-4" style="margin-bottom:1.25rem">
  <div class="kpi-card" style="border-top:3px solid #10b981">
    <div class="kpi-label">En service</div>
    <div class="kpi-value" style="color:#10b981"><?= $stats['en_service']??0 ?></div>
    <div class="kpi-foot">Opérationnels</div>
  </div>
  <div class="kpi-card" style="border-top:3px solid #f59e0b">
    <div class="kpi-label">En maintenance</div>
    <div class="kpi-value" style="color:#f59e0b"><?= $stats['maintenance']??0 ?></div>
    <div class="kpi-foot">En cours</div>
  </div>
  <div class="kpi-card" style="border-top:3px solid #ef4444">
    <div class="kpi-label">Hors service</div>
    <div class="kpi-value" style="color:#ef4444"><?= $stats['hors_service']??0 ?></div>
    <div class="kpi-foot">Indisponibles</div>
  </div>
  <div class="kpi-card" style="border-top:3px solid var(--primary)">
    <div class="kpi-label">Total</div>
    <div class="kpi-value"><?= $total ?></div>
    <div class="kpi-foot">Équipements</div>
  </div>
</div>

<!-- Révisions + Budget -->
<?php if (!empty($revisions_urgentes) || $budget_row): ?>
<div class="grid-2" style="margin-bottom:1.25rem">
  <?php if (!empty($revisions_urgentes)): ?>
  <div class="card">
    <div class="card-title">⏰ Révisions à venir (60 jours)</div>
    <?php foreach ($revisions_urgentes as $r):
      $j = (int)$r['jours'];
      $cls = $j<0?'rgba(107,114,128,.08)':($j<=15?'rgba(239,68,68,.08)':($j<=30?'rgba(245,158,11,.08)':'rgba(16,185,129,.06)'));
      $col = $j<0?'#9ca3af':($j<=15?'#ef4444':($j<=30?'#f59e0b':'#10b981'));
      $lbl = $j<0?"En retard ".abs($j)."j":($j===0?"Aujourd'hui !":"J-$j");
    ?>
    <div class="rev-row" style="background:<?= $cls ?>;border:1px solid <?= str_replace('.08','.2',$cls) ?>">
      <div style="flex:1;min-width:0">
        <div style="font-weight:600;font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($r['materiel_nom']) ?></div>
        <div style="color:var(--text3);font-size:.72rem"><?= h($r['nom']) ?></div>
      </div>
      <span style="color:<?= $col ?>;font-weight:700;font-size:.8rem;flex-shrink:0"><?= $lbl ?></span>
      <a href="materiel_fiche.php?id=<?= $r['mat_id'] ?>" class="btn btn-ghost btn-xs">→</a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($budget_row): ?>
  <div class="card">
    <div class="card-title">💰 Budget maintenance <?= $budget_annee ?></div>
    <?php $pct = $budget_row['budget']>0?min(100,round($depenses/$budget_row['budget']*100)):0;
          $col = $pct>=90?'#ef4444':($pct>=70?'#f59e0b':'#10b981'); ?>
    <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.5rem">
      <span style="color:var(--text2)">Consommé</span>
      <span style="font-weight:700;color:<?= $col ?>"><?= number_format($depenses,0,'.',',') ?> € / <?= number_format($budget_row['budget'],0,'.',',') ?> €</span>
    </div>
    <div class="budget-bar"><div class="budget-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
    <div style="font-size:.75rem;color:var(--text3);margin-top:.35rem"><?= $pct ?>% consommé</div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Formulaire -->
<?php if (peut_faire(ROLE_GESTIONNAIRE)): ?>
<div id="form-panel" style="<?= $editing?'':'display:none' ?>;margin-bottom:1.25rem">
  <div class="card">
    <div class="card-title"><?= $editing?'✏️ Modifier':'➕ Nouvel équipement' ?></div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="type_form" value="save_materiel">
      <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Nom *</label>
          <input type="text" name="nom" class="form-control" required value="<?= h($editing['nom']??'') ?>" placeholder="LIFEPAK 15, Hemocue 201+...">
        </div>
        <div class="form-group">
          <label class="form-label">Type</label>
          <select name="type_id" class="form-control">
            <option value="">— Choisir —</option>
            <?php foreach ($types as $t): ?>
            <option value="<?= $t['id'] ?>" <?= ($editing['type_id']??0)==$t['id']?'selected':'' ?>><?= $t['icone'] ?> <?= h($t['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Marque</label>
          <input type="text" name="marque" class="form-control" value="<?= h($editing['marque']??'') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Modèle</label>
          <input type="text" name="modele" class="form-control" value="<?= h($editing['modele']??'') ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Numéro de série</label>
          <input type="text" name="numero_serie" class="form-control" value="<?= h($editing['numero_serie']??'') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Emplacement</label>
          <select name="emplacement_id" class="form-control">
            <option value="">— Choisir —</option>
            <?php foreach ($empls as $e): ?>
            <option value="<?= $e['id'] ?>" <?= ($editing['emplacement_id']??0)==$e['id']?'selected':'' ?>><?= $e['parent_nom']?h($e['parent_nom']).' › ':'' ?><?= h($e['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Mise en service</label>
          <input type="date" name="date_mise_en_service" class="form-control" value="<?= $editing['date_mise_en_service']??'' ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Fin de garantie</label>
          <input type="date" name="date_fin_garantie" class="form-control" value="<?= $editing['date_fin_garantie']??'' ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Contrat maintenance</label>
          <input type="text" name="contrat_maintenance" class="form-control" value="<?= h($editing['contrat_maintenance']??'') ?>" placeholder="Prestataire, n° contrat...">
        </div>
        <div class="form-group">
          <label class="form-label">Fin contrat</label>
          <input type="date" name="contrat_fin" class="form-control" value="<?= $editing['contrat_fin']??'' ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Statut</label>
          <select name="statut" class="form-control">
            <?php foreach ($statuts as $k=>$s): ?>
            <option value="<?= $k ?>" <?= ($editing['statut']??'en_service')===$k?'selected':'' ?>><?= $s['icon'] ?> <?= $s['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Coût annuel contrat (€)</label>
          <input type="number" name="contrat_cout_annuel" class="form-control" step="0.01" value="<?= $editing['contrat_cout_annuel']??'' ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Notes</label>
        <textarea name="notes" class="form-control" rows="2"><?= h($editing['notes']??'') ?></textarea>
      </div>
      <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
        <a href="materiel.php" class="btn btn-ghost">Annuler</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Barre recherche + filtres -->
<div class="mat-toolbar">
  <input type="text" class="mat-search" id="mat-search" placeholder="🔍 Rechercher nom, marque, N° série…" oninput="filtrerTableau(this.value)">
  <a href="materiel.php" class="mat-filter-btn <?= !$filtre_statut?'active':'' ?>">Tous (<?= $total ?>)</a>
  <?php foreach ($statuts as $k=>$s): if (!($stats[$k]??0)) continue; ?>
  <a href="materiel.php?statut=<?= $k ?>" class="mat-filter-btn <?= $filtre_statut===$k?'active':'' ?>">
    <?= $s['icon'] ?> <?= $s['label'] ?> (<?= $stats[$k] ?>)
  </a>
  <?php endforeach; ?>
</div>

<!-- Tableau -->
<?php if (empty($materiels)): ?>
<div class="card" style="text-align:center;padding:3rem">
  <div style="font-size:3rem;margin-bottom:1rem">🔧</div>
  <div style="font-weight:700;margin-bottom:.5rem">Aucun équipement</div>
  <?php if (peut_faire(ROLE_GESTIONNAIRE)): ?>
  <button class="btn btn-primary btn-sm" onclick="toggleForm()">➕ Ajouter</button>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="card" style="padding:0;overflow:hidden">
  <div style="overflow-x:auto">
  <table class="mat-table" id="mat-table">
    <thead>
      <tr>
        <th onclick="trierTableau(0)" data-col="0">Équipement <span class="sort-icon">↕</span></th>
        <th onclick="trierTableau(1)" data-col="1">Marque / Modèle <span class="sort-icon">↕</span></th>
        <th onclick="trierTableau(2)" data-col="2">N° Série <span class="sort-icon">↕</span></th>
        <th onclick="trierTableau(3)" data-col="3">Statut <span class="sort-icon">↕</span></th>
        <th onclick="trierTableau(4)" data-col="4">Garantie <span class="sort-icon">↕</span></th>
        <th onclick="trierTableau(5)" data-col="5">Prochaine révision <span class="sort-icon">↕</span></th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($materiels as $m):
      $st  = $statuts[$m['statut']] ?? ['label'=>$m['statut'],'color'=>'#64748b','icon'=>'🔧'];
      $rev = null;
      if ($m['prochaine_revision']) $rev = (int)((strtotime($m['prochaine_revision'])-time())/86400);
      $rev_col = $rev===null ? null : ($rev<0?'#9ca3af':($rev<=15?'#ef4444':($rev<=30?'#f59e0b':'#10b981')));
      $garantie_exp = $m['date_fin_garantie'] && strtotime($m['date_fin_garantie']) < time();
    ?>
    <tr class="mat-row" data-search="<?= strtolower(h($m['nom'].' '.$m['marque'].' '.$m['modele'].' '.$m['numero_serie'])) ?>">
      <td>
        <div class="mat-name"><?= h($m['type_icone']??'🔧') ?> <?= h($m['nom']) ?></div>
        <?php if ($m['empl_nom']): ?>
        <div class="mat-sub">📍 <?= $m['empl_parent']?h($m['empl_parent']).' › ':'' ?><?= h($m['empl_nom']) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <div style="font-size:.85rem"><?= h($m['marque']??'—') ?></div>
        <div class="mat-sub"><?= h($m['modele']??'') ?></div>
      </td>
      <td><span class="mat-serie"><?= $m['numero_serie'] ? h($m['numero_serie']) : '—' ?></span></td>
      <td>
        <span class="mat-badge" style="background:<?= $st['color'] ?>22;color:<?= $st['color'] ?>;border:1px solid <?= $st['color'] ?>44">
          <?= $st['icon'] ?> <?= $st['label'] ?>
        </span>
      </td>
      <td>
        <?php if ($m['date_fin_garantie']): ?>
        <span style="font-size:.82rem;color:<?= $garantie_exp?'#f87171':'var(--text2)' ?>">
          <?= $garantie_exp ? '❌ Expirée' : '✅ '.date('m/Y',strtotime($m['date_fin_garantie'])) ?>
        </span>
        <?php else: ?>
        <span style="color:var(--text3);font-size:.82rem">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($rev !== null): ?>
        <span class="rev-badge" style="background:<?= $rev_col ?>22;color:<?= $rev_col ?>">
          <?= $rev<0?'⚠️ Retard '.abs($rev).'j':($rev===0?'⚠️ Aujourd\'hui':"J-$rev") ?>
        </span>
        <?php else: ?>
        <span style="color:var(--text3);font-size:.82rem">—</span>
        <?php endif; ?>
      </td>
      <td>
        <div class="mat-actions">
          <a href="materiel_fiche.php?id=<?= $m['id'] ?>" class="btn btn-ghost btn-xs" title="Fiche">📋</a>
          <a href="materiel_intervention.php?materiel_id=<?= $m['id'] ?>" class="btn btn-ghost btn-xs" title="Intervention">🔧</a>
          <?php if (peut_faire(ROLE_GESTIONNAIRE)): ?>
          <a href="materiel.php?edit=<?= $m['id'] ?>" class="btn btn-ghost btn-xs" title="Modifier">✏️</a>
          <?php endif; ?>
          <?php if ($m['statut']==='hors_service'): ?>
          <form method="POST" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="type_form" value="change_statut">
            <input type="hidden" name="materiel_id" value="<?= $m['id'] ?>">
            <input type="hidden" name="statut" value="en_service">
            <button class="btn btn-xs" style="background:rgba(16,185,129,.12);color:#34d399;border:1px solid rgba(16,185,129,.25)" title="Remettre en service">✅</button>
          </form>
          <?php elseif ($m['statut']==='en_service'): ?>
          <form method="POST" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="type_form" value="change_statut">
            <input type="hidden" name="materiel_id" value="<?= $m['id'] ?>">
            <input type="hidden" name="statut" value="hors_service">
            <button class="btn btn-xs" style="background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.2)" onclick="return confirm('Mettre hors service ?')" title="Hors service">🔴</button>
          </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div style="padding:10px 16px;font-size:.75rem;color:var(--text3);border-top:1px solid var(--border)" id="mat-count">
    <?= count($materiels) ?> équipement(s) affiché(s)
  </div>
</div>
<?php endif; ?>

<script>
function toggleForm(){const p=document.getElementById('form-panel');if(p)p.style.display=p.style.display==='none'?'block':'none';}

// Recherche live
function filtrerTableau(q) {
  q = q.toLowerCase().trim();
  const rows = document.querySelectorAll('.mat-row');
  let visible = 0;
  rows.forEach(row => {
    const match = !q || row.dataset.search.includes(q);
    row.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  const cnt = document.getElementById('mat-count');
  if (cnt) cnt.textContent = visible + ' équipement(s) affiché(s)';
}

// Tri colonnes
let sortCol = -1, sortDir = 1;
function trierTableau(col) {
  const tbody = document.querySelector('#mat-table tbody');
  const rows  = Array.from(tbody.querySelectorAll('tr'));
  if (sortCol === col) sortDir *= -1;
  else { sortCol = col; sortDir = 1; }

  document.querySelectorAll('#mat-table th').forEach((th,i) => {
    th.classList.toggle('sorted', i === col);
    const icon = th.querySelector('.sort-icon');
    if (icon) icon.textContent = i === col ? (sortDir===1?'↓':'↑') : '↕';
  });

  rows.sort((a, b) => {
    const ta = a.querySelectorAll('td')[col]?.textContent.trim().toLowerCase() || '';
    const tb = b.querySelectorAll('td')[col]?.textContent.trim().toLowerCase() || '';
    return ta.localeCompare(tb, 'fr') * sortDir;
  });
  rows.forEach(r => tbody.appendChild(r));
}
</script>
<?php require_once 'includes/footer.php'; ?>
