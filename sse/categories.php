<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

require_once '../includes/auth.php';
$pageTitle = 'SSE — Catégories';
$site_id = site_actif();
if (!$site_id) { header('Location: /sse.php'); exit; }
ob_start();

$success = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $action = $_POST['action']??'';
    if ($action==='save') {
        $id=(int)($_POST['id']??0);
        $d=['nom'=>trim($_POST['nom']??'')?:'Catégorie','type'=>$_POST['type']??'equipement','icone'=>trim($_POST['icone']??'🧪')?:'🧪','description'=>trim($_POST['description']??'')?:null];
        if ($id>0) { $sets=implode(',',array_map(fn($k)=>"$k=?",array_keys($d))); $pdo->prepare("UPDATE sse_categories SET $sets WHERE id=? AND site_id=?")->execute([...array_values($d),$id,$site_id]); }
        else { $keys=implode(',',array_keys($d)).',site_id'; $vals=implode(',',array_fill(0,count($d),'?')).',?'; $pdo->prepare("INSERT INTO sse_categories ($keys) VALUES ($vals)")->execute([...array_values($d),$site_id]); }
        $success='Catégorie enregistrée.';
    } elseif ($action==='delete') { $pdo->prepare("UPDATE sse_categories SET actif=0 WHERE id=? AND site_id=?")->execute([(int)$_POST['id'],$site_id]); $success='Catégorie archivée.'; }
}
$cats=$pdo->prepare("SELECT c.*,(SELECT COUNT(*) FROM sse_equipements e WHERE e.categorie_id=c.id AND e.actif=1) AS nb_eq,(SELECT COUNT(*) FROM sse_consommables s WHERE s.categorie_id=c.id AND s.statut='actif') AS nb_cs FROM sse_categories c WHERE c.site_id=? AND c.actif=1 ORDER BY c.nom"); $cats->execute([$site_id]); $cats=$cats->fetchAll(PDO::FETCH_ASSOC);
$edit=isset($_GET['edit'])?(function() use($pdo,$site_id){ $s=$pdo->prepare("SELECT * FROM sse_categories WHERE id=? AND site_id=?"); $s->execute([(int)$_GET['edit'],$site_id]); return $s->fetch(PDO::FETCH_ASSOC); })():null;
require_once '../includes/header.php';
if (!nav_peut($nav_perms, 'sse')) { ob_end_clean(); accessDenied(); exit; }
$types=['equipement'=>'⚙️ Équipement','consommable'=>'📦 Consommable','appareil'=>'🔬 Appareil','antidote'=>'💊 Antidote','detecteur'=>'🔍 Détecteur','tenue'=>'🥼 Tenue de protection','autre'=>'📋 Autre'];
?>
<div class="page-header">
  <div class="page-title-group"><div class="page-title">🏷️ Catégories SSE / NRBC</div></div>
  <div class="page-actions"><a href="/sse.php" class="btn btn-sm btn-ghost">← Dashboard</a><button onclick="document.getElementById('modal-add').style.display='flex'" class="btn btn-sm btn-primary">+ Ajouter</button></div>
</div>
<?php $sse_active = 'categories'; include __DIR__.'/_nav.php'; ?>
<?php if ($success): ?><div class="flash flash-success">✅ <?= h($success) ?></div><?php endif; ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px">
<?php foreach ($cats as $cat): ?>
<div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:16px">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
    <span style="font-size:2rem"><?= $cat['icone'] ?></span>
    <div><div style="font-weight:700"><?= h($cat['nom']) ?></div><div style="font-size:.75rem;color:var(--text3)"><?= $types[$cat['type']]??$cat['type'] ?></div></div>
  </div>
  <div style="font-size:.78rem;margin-bottom:10px;display:flex;gap:12px">
    <a href="/sse/equipements.php?categorie=<?= $cat['id'] ?>" style="color:var(--text2);text-decoration:none" title="Voir les équipements"><b style="color:var(--text)"><?= $cat['nb_eq'] ?></b> équip.</a>
    <a href="/sse/consommables.php?cat=<?= $cat['id'] ?>" style="color:var(--text2);text-decoration:none" title="Voir les consommables"><b style="color:var(--text)"><?= $cat['nb_cs'] ?></b> conso.</a>
  </div>
  <div style="display:flex;gap:6px">
    <a href="?edit=<?= $cat['id'] ?>" class="btn btn-xs btn-ghost" style="flex:1;text-align:center">✏️</a>
    <form method="post" style="display:inline" onsubmit="return confirm('Archiver ?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $cat['id'] ?>"><?= csrf_field() ?><button type="submit" style="padding:2px 8px;border-radius:6px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#f87171;cursor:pointer;font-size:.78rem">🗑️</button></form>
  </div>
</div>
<?php endforeach; ?>
</div>
<div id="modal-add" style="display:<?= $edit?'flex':'none' ?>;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
<div style="background:var(--bg2);border:1px solid var(--border);border-radius:16px;padding:28px;width:90%;max-width:480px">
  <div style="display:flex;justify-content:space-between;margin-bottom:20px"><b>🏷️ Catégorie</b><button onclick="document.getElementById('modal-add').style.display='none'" style="background:none;border:none;font-size:1.2rem;cursor:pointer">✕</button></div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $edit['id']??0 ?>">
    <?php foreach([['nom','Nom','text'],['icone','Icône (emoji)','text']] as [$n,$l,$t]): ?>
    <div style="margin-bottom:12px"><label style="font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:4px"><?= $l ?></label><input type="<?= $t ?>" name="<?= $n ?>" value="<?= h($edit[$n]??'') ?>" required style="width:100%;background:var(--glass);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 12px"></div>
    <?php endforeach; ?>
    <div style="margin-bottom:12px"><label style="font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:4px">Type</label><select name="type" style="width:100%;background:var(--glass);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 12px"><?php foreach ($types as $k=>$v): ?><option value="<?= $k ?>" <?= ($edit['type']??'equipement')===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div style="margin-bottom:16px"><label style="font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:4px">Description</label><textarea name="description" rows="2" style="width:100%;background:var(--glass);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 12px;resize:vertical"><?= h($edit['description']??'') ?></textarea></div>
    <div style="display:flex;gap:8px"><button type="submit" class="btn btn-primary">💾</button><button type="button" onclick="document.getElementById('modal-add').style.display='none'" class="btn btn-ghost">Annuler</button></div>
  </form>
</div></div>
<?php require_once '../includes/footer.php'; ?>
