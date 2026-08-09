<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

require_once 'includes/auth.php';
$pageTitle = 'Fiche équipement';
$site_id = site_actif();
$id = (int)($_GET['id'] ?? 0);
if (!$id || !$site_id) { header('Location: materiel.php'); exit; }

$stmt = $pdo->prepare("SELECT m.*,mt.nom AS type_nom,mt.icone AS type_icone,e.nom AS empl_nom,ep.nom AS empl_parent,s.nom AS site_nom,o.nom AS org_nom FROM materiels m LEFT JOIN materiel_types mt ON mt.id=m.type_id LEFT JOIN emplacements e ON e.id=m.emplacement_id LEFT JOIN emplacements ep ON ep.id=e.parent_id LEFT JOIN sites s ON s.id=m.site_id LEFT JOIN organisations o ON o.id=s.organisation_id WHERE m.id=? AND m.site_id=?");
$stmt->execute([$id,$site_id]);
$mat = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$mat) { header('Location: materiel.php'); exit; }

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $type = $_POST['type_form']??'';
    if ($type==='save_revision') {
        $nom=$_POST['nom']??''; $freq=(int)($_POST['frequence_mois']??12); $dern=$_POST['derniere_revision']??null?:null; $org=trim($_POST['organisme']??'')?:null; $oblig=isset($_POST['obligatoire'])?1:0; $alerte=(int)($_POST['alerte_jours']??30); $ttype=$_POST['type_rev']??'interne';
        $prochaine=null; if($dern) $prochaine=date('Y-m-d',strtotime("$dern +$freq months"));
        if ($nom) { $pdo->prepare("INSERT INTO materiel_revisions (materiel_id,nom,type,frequence_mois,derniere_revision,prochaine_revision,organisme,obligatoire,alerte_jours) VALUES(?,?,?,?,?,?,?,?,?)")->execute([$id,$nom,$ttype,$freq,$dern,$prochaine,$org,$oblig,$alerte]); flashSuccess("Révision ajoutée."); }
        header("Location: materiel_fiche.php?id=$id&tab=revisions"); exit;
    }
    if ($type==='delete_revision') { $pdo->prepare("DELETE FROM materiel_revisions WHERE id=? AND materiel_id=?")->execute([(int)$_POST['rev_id'],$id]); flashSuccess("Supprimée."); header("Location: materiel_fiche.php?id=$id&tab=revisions"); exit; }
    if ($type==='save_consommable') {
        $nom=trim($_POST['nom']??''); if($nom) { $pdo->prepare("INSERT INTO materiel_consommables (materiel_id,nom,quantite,quantite_min,unite) VALUES(?,?,?,?,?)")->execute([$id,$nom,(float)($_POST['quantite']??0),(float)($_POST['quantite_min']??0),trim($_POST['unite']??'unité')]); flashSuccess("Consommable ajouté."); }
        header("Location: materiel_fiche.php?id=$id&tab=consommables"); exit;
    }
    if ($type==='notif_gestionnaire') {
        $inter_id=(int)($_POST['intervention_id']??0); $msg=$_POST['message']??'Intervention sur '.$mat['nom'];
        $pdo->prepare("INSERT INTO notifications (type,titre,message,lien) VALUES('alerte',?,?,?)")->execute(["🔧 Intervention biomédical",$msg,"/materiel_fiche.php?id=$id"]);
        $pdo->prepare("UPDATE materiel_interventions SET notif_gestionnaire=1,notif_le=NOW() WHERE id=?")->execute([$inter_id]);
        flashSuccess("Gestionnaire notifié."); header("Location: materiel_fiche.php?id=$id&tab=carnet"); exit;
    }
}

$tab = $_GET['tab']??'carnet';
$interventions=$pdo->prepare("SELECT i.*,u.prenom,u.nom AS user_nom FROM materiel_interventions i LEFT JOIN utilisateurs u ON u.id=i.user_id WHERE i.materiel_id=? ORDER BY i.date_intervention DESC");
$interventions->execute([$id]); $interventions=$interventions->fetchAll(PDO::FETCH_ASSOC);
$revisions=$pdo->prepare("SELECT * FROM materiel_revisions WHERE materiel_id=? AND actif=1 ORDER BY prochaine_revision");
$revisions->execute([$id]); $revisions=$revisions->fetchAll(PDO::FETCH_ASSOC);
$consommables=$pdo->prepare("SELECT * FROM materiel_consommables WHERE materiel_id=? ORDER BY nom");
$consommables->execute([$id]); $consommables=$consommables->fetchAll(PDO::FETCH_ASSOC);

$cout_total=array_sum(array_column($interventions,'cout'));
$nb_pannes=count(array_filter($interventions,fn($i)=>$i['type']==='reparation'));
$score=$mat['score_fiabilite']??100;
$mat_statuts=['en_service'=>['label'=>'En service','color'=>'#10b981','icon'=>'✅'],'maintenance'=>['label'=>'Maintenance','color'=>'#f59e0b','icon'=>'🔧'],'hors_service'=>['label'=>'Hors service','color'=>'#ef4444','icon'=>'🔴'],'commande'=>['label'=>'Commandé','color'=>'#8b5cf6','icon'=>'📦'],'reception'=>['label'=>'Réceptionné','color'=>'#06b6d4','icon'=>'📬'],'reforme'=>['label'=>'Réformé','color'=>'#6b7280','icon'=>'⚫']];
$mat_st=$mat_statuts[$mat['statut']]??['label'=>$mat['statut'],'color'=>'#64748b','icon'=>'🔧'];
$types_inter=['revision_periodique'=>'🔄 Révision','reparation'=>'🔨 Réparation','remplacement_piece'=>'🔩 Remplacement','mise_a_jour'=>'💾 MAJ','verification'=>'✅ Vérif.','checklist_reception'=>'📋 Réception','autre'=>'🔧 Autre'];
require_once 'includes/header.php';
?>
<style>
.tabs-mat{display:flex;gap:4px;margin-bottom:1.25rem;border-bottom:1px solid var(--border);flex-wrap:wrap}
.tab-mat{padding:.5rem 1rem;font-size:.8rem;font-weight:600;color:var(--text2);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;text-decoration:none;display:inline-block;transition:all .15s}
.tab-mat.active{color:var(--primary);border-bottom-color:var(--primary)}
.tab-pane{display:none}.tab-pane.active{display:block}
.timeline{position:relative;padding-left:2rem}
.timeline::before{content:'';position:absolute;left:8px;top:0;bottom:0;width:2px;background:var(--border)}
.tl-item{position:relative;margin-bottom:1.25rem}
.tl-dot{position:absolute;left:-2rem;top:.3rem;width:16px;height:16px;border-radius:50%;border:2px solid;background:var(--bg);display:flex;align-items:center;justify-content:center;font-size:.55rem}
.tl-card{background:var(--glass);border:1px solid var(--border);border-radius:10px;padding:.875rem 1rem}
</style>

<div style="display:flex;align-items:flex-start;gap:1.25rem;margin-bottom:1.5rem;flex-wrap:wrap">
  <div style="width:70px;height:70px;border-radius:16px;background:rgba(59,130,246,.12);border:2px solid rgba(59,130,246,.2);display:flex;align-items:center;justify-content:center;font-size:2rem;flex-shrink:0"><?= h($mat['type_icone']??'🔧') ?></div>
  <div style="flex:1;min-width:0">
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.3rem">
      <h1 style="font-size:1.35rem;font-weight:800"><?= h($mat['nom']) ?></h1>
      <span class="badge" style="background:<?= $mat_st['color'] ?>22;color:<?= $mat_st['color'] ?>;border:1px solid <?= $mat_st['color'] ?>44"><?= $mat_st['icon'] ?> <?= $mat_st['label'] ?></span>
    </div>
    <div style="font-size:.82rem;color:var(--text2);margin-bottom:.4rem"><?= h($mat['type_icone']??'') ?> <?= h($mat['type_nom']??'') ?><?php if($mat['marque']): ?> · <?= h($mat['marque']) ?><?php endif; ?><?php if($mat['modele']): ?> <?= h($mat['modele']) ?><?php endif; ?></div>
    <div style="display:flex;flex-wrap:wrap;gap:1rem;font-size:.76rem;color:var(--text3)">
      <?php if($mat['numero_serie']): ?><span>🔢 <?= h($mat['numero_serie']) ?></span><?php endif; ?>
      <?php if($mat['empl_nom']): ?><span>📍 <?= $mat['empl_parent']?h($mat['empl_parent']).' › ':'' ?><?= h($mat['empl_nom']) ?></span><?php endif; ?>
      <?php if($mat['date_mise_en_service']): ?><span>📅 <?= date('d/m/Y',strtotime($mat['date_mise_en_service'])) ?></span><?php endif; ?>
    </div>
  </div>
  <div style="text-align:center;flex-shrink:0">
    <div style="position:relative;display:inline-flex;align-items:center;justify-content:center;width:66px;height:66px">
      <svg width="66" height="66" viewBox="0 0 66 66" style="position:absolute;top:0;left:0;transform:rotate(-90deg)">
        <circle cx="33" cy="33" r="27" fill="none" stroke="var(--border2)" stroke-width="6"/>
        <circle cx="33" cy="33" r="27" fill="none" stroke="<?= $score>=80?'#10b981':($score>=60?'#f59e0b':'#ef4444') ?>" stroke-width="6" stroke-dasharray="<?= round(2*M_PI*27*$score/100,1) ?> <?= round(2*M_PI*27,1) ?>" stroke-linecap="round"/>
      </svg>
      <span style="font-size:1rem;font-weight:800;color:<?= $score>=80?'#10b981':($score>=60?'#f59e0b':'#ef4444') ?>;position:relative"><?= $score ?></span>
    </div>
    <div style="font-size:.68rem;color:var(--text3)">Fiabilité</div>
  </div>
  <div style="display:flex;flex-direction:column;gap:.5rem;flex-shrink:0">
    <a href="materiel_intervention.php?materiel_id=<?= $id ?>" class="btn btn-primary btn-sm">🔧 Intervention</a>
    <a href="materiel.php" class="btn btn-ghost btn-sm">← Retour</a>
  </div>
</div>

<div class="grid-4" style="margin-bottom:1.5rem">
  <div class="kpi-card"><div class="kpi-label">Interventions</div><div class="kpi-value"><?= count($interventions) ?></div></div>
  <div class="kpi-card"><div class="kpi-label">Coût total</div><div class="kpi-value" style="font-size:1.2rem"><?= number_format($cout_total,0,'.',',') ?> €</div></div>
  <div class="kpi-card"><div class="kpi-label">Pannes</div><div class="kpi-value" style="color:<?= $nb_pannes>2?'#ef4444':'var(--text)' ?>"><?= $nb_pannes ?></div></div>
  <div class="kpi-card"><div class="kpi-label">Révisions</div><div class="kpi-value"><?= count($revisions) ?></div></div>
</div>

<div class="tabs-mat">
  <a href="#" class="tab-mat active" onclick="return st('carnet',this)">📋 Carnet de vie (<?= count($interventions) ?>)</a>
  <a href="#" class="tab-mat" onclick="return st('revisions',this)">⏰ Révisions (<?= count($revisions) ?>)</a>
  <a href="#" class="tab-mat" onclick="return st('infos',this)">ℹ️ Infos</a>
  <a href="#" class="tab-mat" onclick="return st('consommables',this)">📦 Consommables (<?= count($consommables) ?>)</a>
</div>

<!-- CARNET -->
<div id="tab-carnet" class="tab-pane active">
<?php if(empty($interventions)): ?>
<div class="card" style="text-align:center;padding:3rem;color:var(--text3)">
  <div style="font-size:2.5rem;margin-bottom:.75rem">📋</div>
  Aucune intervention.
  <br><a href="materiel_intervention.php?materiel_id=<?= $id ?>" class="btn btn-primary btn-sm" style="margin-top:1rem">🔧 Créer la première</a>
</div>
<?php else: ?>
<div style="display:flex;justify-content:flex-end;margin-bottom:.75rem">
  <a href="export_pdf.php?type=carnet&id=<?= $id ?>" class="btn btn-ghost btn-sm" target="_blank">📄 Export PDF</a>
</div>
<div class="timeline">
<?php foreach($interventions as $inter):
  $dc=$inter['statut_apres']==='hors_service'?'#ef4444':($inter['statut_apres']==='maintenance'?'#f59e0b':'#10b981');
  $tl=$types_inter[$inter['type']]??$inter['type'];
?>
<div class="tl-item">
  <div class="tl-dot" style="border-color:<?= $dc ?>;color:<?= $dc ?>">●</div>
  <div class="tl-card">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.5rem">
      <div style="flex:1">
        <div style="font-size:.72rem;color:var(--text3);margin-bottom:.2rem"><?= date('d/m/Y',strtotime($inter['date_intervention'])) ?></div>
        <div style="font-weight:600;font-size:.9rem;margin-bottom:.3rem"><?= $tl ?> <?= $inter['type_libre']?'— '.h($inter['type_libre']):'' ?></div>
        <?php if($inter['description']): ?><div style="font-size:.8rem;color:var(--text2);margin-bottom:.4rem"><?= h($inter['description']) ?></div><?php endif; ?>
        <div style="display:flex;flex-wrap:wrap;gap:.4rem;font-size:.73rem;color:var(--text3)">
          <?php if($inter['technicien']): ?><span>👨‍🔧 <?= h($inter['technicien']) ?></span><?php endif; ?>
          <?php if($inter['numero_suivi']): ?><span>🔢 <?= h($inter['numero_suivi']) ?></span><?php endif; ?>
          <?php if($inter['cout']): ?><span>💰 <?= number_format($inter['cout'],2,'.',',') ?> €</span><?php endif; ?>
          <?php if($inter['date_prochaine']): ?><span>⏭️ <?= date('d/m/Y',strtotime($inter['date_prochaine'])) ?></span><?php endif; ?>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:.3rem;align-items:flex-end;flex-shrink:0">
        <span class="badge" style="font-size:.68rem;background:<?= $dc ?>22;color:<?= $dc ?>"><?= match($inter['statut_apres']??'operationnel'){'hors_service'=>'HS','maintenance'=>'Maintenance',default=>'OK'} ?></span>
        <?php if(!$inter['notif_gestionnaire']): ?>
        <form method="POST" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="type_form" value="notif_gestionnaire">
          <input type="hidden" name="intervention_id" value="<?= $inter['id'] ?>">
          <input type="hidden" name="message" value="Intervention <?= h($tl) ?> sur <?= h($mat['nom']) ?>">
          <button class="btn btn-ghost btn-xs">📢 Notifier</button>
        </form>
        <?php else: ?><span style="font-size:.7rem;color:var(--text3)">✓ Notifié</span><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<!-- RÉVISIONS -->
<div id="tab-revisions" class="tab-pane">
<div class="grid-2">
  <div>
  <?php foreach($revisions as $r):
    $j=$r['prochaine_revision']?(int)((strtotime($r['prochaine_revision'])-time())/86400):null;
    $col=$j===null?'var(--text3)':($j<0?'#9ca3af':($j<=15?'#ef4444':($j<=30?'#f59e0b':'#10b981')));
    $lbl=$j===null?'—':($j<0?"Retard ".abs($j)."j":($j===0?"Aujourd'hui !":"J-$j"));
  ?>
  <div style="background:var(--glass);border:1px solid var(--border);border-radius:10px;padding:.875rem;margin-bottom:.5rem">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.5rem;margin-bottom:.4rem">
      <div style="flex:1">
        <div style="font-weight:600;font-size:.875rem"><?= h($r['nom']) ?></div>
        <div style="font-size:.75rem;color:var(--text3);margin-top:.2rem">🔄 <?= $r['frequence_mois'] ?> mois<?php if($r['organisme']): ?> · 🏢 <?= h($r['organisme']) ?><?php endif; ?><?php if($r['obligatoire']): ?> · <span style="color:#f59e0b">⚠️ Réglementaire</span><?php endif; ?></div>
        <?php if($r['derniere_revision']): ?><div style="font-size:.73rem;color:var(--text3)">Dernière : <?= date('d/m/Y',strtotime($r['derniere_revision'])) ?></div><?php endif; ?>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <div style="font-weight:700;color:<?= $col ?>;font-size:.82rem"><?= $lbl ?></div>
        <?php if($r['prochaine_revision']): ?><div style="font-size:.72rem;color:var(--text3)"><?= date('d/m/Y',strtotime($r['prochaine_revision'])) ?></div><?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:.4rem">
      <a href="materiel_intervention.php?materiel_id=<?= $id ?>&revision_id=<?= $r['id'] ?>" class="btn btn-ghost btn-xs">✅ Effectuer</a>
      <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type_form" value="delete_revision"><input type="hidden" name="rev_id" value="<?= $r['id'] ?>"><button class="btn btn-ghost btn-xs" onclick="return confirm('Supprimer ?')">🗑️</button></form>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if(empty($revisions)): ?><div class="card" style="text-align:center;padding:2rem;color:var(--text3)">Aucune révision.</div><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-title">➕ Ajouter une révision</div>
    <form method="POST">
      <?= csrf_field() ?><input type="hidden" name="type_form" value="save_revision">
      <div class="form-group"><label class="form-label">Nom *</label><input type="text" name="nom" class="form-control" required placeholder="Révision annuelle..."></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Type</label><select name="type_rev" class="form-control"><option value="interne">Interne</option><option value="constructeur">Constructeur</option><option value="reglementaire">Réglementaire</option><option value="autre">Autre</option></select></div>
        <div class="form-group"><label class="form-label">Fréquence (mois)</label><input type="number" name="frequence_mois" class="form-control" value="12" min="1"></div>
      </div>
      <div class="form-group"><label class="form-label">Dernière révision</label><input type="date" name="derniere_revision" class="form-control"></div>
      <div class="form-group"><label class="form-label">Organisme</label><input type="text" name="organisme" class="form-control" placeholder="Stryker, technicien interne..."></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Alerte (jours avant)</label><input type="number" name="alerte_jours" class="form-control" value="30" min="1"></div>
        <div class="form-group" style="display:flex;align-items:center;padding-top:1.5rem"><label style="display:flex;align-items:center;gap:8px;font-size:.875rem;cursor:pointer"><input type="checkbox" name="obligatoire" style="accent-color:var(--primary)"> ⚠️ Réglementaire</label></div>
      </div>
      <button type="submit" class="btn btn-primary">💾 Ajouter</button>
    </form>
  </div>
</div>
</div>

<!-- INFOS -->
<div id="tab-infos" class="tab-pane">
<div class="grid-2">
  <div class="card">
    <div class="card-title">📋 Informations techniques</div>
    <?php foreach([['Marque',$mat['marque']],['Modèle',$mat['modele']],['N° série',$mat['numero_serie']],['Mise en service',$mat['date_mise_en_service']?date('d/m/Y',strtotime($mat['date_mise_en_service'])):null],['Fin garantie',$mat['date_fin_garantie']?date('d/m/Y',strtotime($mat['date_fin_garantie'])):null],['Emplacement',$mat['empl_nom']],['Site',$mat['site_nom']]] as [$l,$v]): if(!$v) continue; ?>
    <div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.85rem"><span style="color:var(--text2)"><?= $l ?></span><span><?= h($v) ?></span></div>
    <?php endforeach; ?>
    <?php if($mat['notes']): ?><div style="margin-top:.75rem;font-size:.82rem;color:var(--text2);padding:.5rem;background:var(--glass);border-radius:6px"><?= h($mat['notes']) ?></div><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-title">💰 Finances</div>
    <?php if($mat['contrat_maintenance']): ?>
    <div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.85rem"><span style="color:var(--text2)">Contrat</span><span><?= h($mat['contrat_maintenance']) ?></span></div>
    <?php if($mat['contrat_fin']): ?><div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.85rem"><span style="color:var(--text2)">Fin contrat</span><span style="color:<?= strtotime($mat['contrat_fin'])<time()?'#f87171':'var(--text)' ?>"><?= date('d/m/Y',strtotime($mat['contrat_fin'])) ?></span></div><?php endif; ?>
    <?php if($mat['contrat_cout_annuel']): ?><div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.85rem"><span style="color:var(--text2)">Coût annuel contrat</span><span><?= number_format($mat['contrat_cout_annuel'],2,'.',',') ?> €</span></div><?php endif; ?>
    <?php endif; ?>
    <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border)">
      <div style="font-size:.75rem;color:var(--text3);margin-bottom:.25rem">Coût total de possession</div>
      <div style="font-size:1.5rem;font-weight:800;color:var(--primary)"><?= number_format($cout_total,2,'.',',') ?> €</div>
    </div>
  </div>
</div>
</div>

<!-- CONSOMMABLES -->
<div id="tab-consommables" class="tab-pane">
<div class="grid-2">
  <div>
  <?php foreach($consommables as $c): $isLow=$c['quantite_min']>0&&$c['quantite']<=$c['quantite_min']; ?>
  <div style="display:flex;align-items:center;gap:10px;padding:.55rem .875rem;background:var(--glass);border:1px solid <?= $isLow?'rgba(245,158,11,.3)':'var(--border)' ?>;border-radius:8px;margin-bottom:.4rem;font-size:.82rem">
    <span style="flex:1;font-weight:500"><?= h($c['nom']) ?><?php if($isLow): ?> <span class="badge badge-warning" style="font-size:.68rem">📦 Bas</span><?php endif; ?></span>
    <span style="font-family:var(--mono);font-weight:700;color:<?= $isLow?'#fbbf24':'var(--text)' ?>"><?= $c['quantite'] ?> <?= h($c['unite']) ?></span>
    <?php if($c['quantite_min']): ?><span style="font-size:.72rem;color:var(--text3)">min: <?= $c['quantite_min'] ?></span><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if(empty($consommables)): ?><div class="card" style="text-align:center;padding:2rem;color:var(--text3)">Aucun consommable.</div><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-title">➕ Ajouter</div>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="type_form" value="save_consommable">
      <div class="form-group"><label class="form-label">Nom *</label><input type="text" name="nom" class="form-control" required placeholder="Électrodes, batterie..."></div>
      <div class="form-row"><div class="form-group"><label class="form-label">Quantité</label><input type="number" name="quantite" class="form-control" value="0" step="0.5" min="0"></div><div class="form-group"><label class="form-label">Stock min</label><input type="number" name="quantite_min" class="form-control" value="0" step="0.5" min="0"></div></div>
      <div class="form-group"><label class="form-label">Unité</label><input type="text" name="unite" class="form-control" value="unité"></div>
      <button type="submit" class="btn btn-primary">💾 Ajouter</button>
    </form>
  </div>
</div>
</div>

<script>
function st(name,el){document.querySelectorAll('.tab-pane').forEach(t=>t.classList.remove('active'));document.querySelectorAll('.tab-mat').forEach(t=>t.classList.remove('active'));const p=document.getElementById('tab-'+name);if(p)p.classList.add('active');if(el)el.classList.add('active');return false;}
</script>
<?php require_once 'includes/footer.php'; ?>
