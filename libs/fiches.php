<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

require_once 'includes/auth.php';
$pageTitle = 'Fiches de vérification';
$site_id   = site_actif();
$modele_id = (int)($_GET['modele'] ?? 0);
$mode      = $_GET['mode'] ?? 'complet';
if (!$site_id) { header('Location: dashboard.php'); exit; }

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['valider_fiche'])) {
    csrf_check();
    $mid   = (int)$_POST['modele_id'];
    $notes = trim($_POST['notes']??'')?:null;
    $sig   = $_POST['signature']??null?:null;
    $s = $pdo->prepare("SELECT * FROM modeles_fiches WHERE id=? AND site_id=?");
    $s->execute([$mid,$site_id]);
    $mod = $s->fetch(PDO::FETCH_ASSOC);
    if ($mod) {
        $conformes=$_POST['conforme']??[];
        $hasAnomal=false;
        foreach($conformes as $v) if($v==0){$hasAnomal=true;break;}
        $pdo->prepare("INSERT INTO fiches_remplies (modele_id,modele_version,user_id,site_id,statut,mode,signature,notes,valide_le) VALUES(?,?,?,?,?,?,?,?,NOW())")
            ->execute([$mid,$mod['version'],currentUserId(),$site_id,$hasAnomal?'anomalie':'valide',$_POST['mode']??'complet',$sig,$notes]);
        $fiche_id=(int)$pdo->lastInsertId();
        foreach(($_POST['reponse']??[]) as $pid=>$val) {
            $conf=(int)($conformes[$pid]??1);
            $biomed=isset($_POST['biomed'][$pid])?1:0;
            $comment=trim($_POST['comment'][$pid]??'')?:null;
            $pdo->prepare("INSERT INTO fiche_reponses (fiche_id,point_id,valeur,conforme,signale_biomed,commentaire) VALUES(?,?,?,?,?,?)")->execute([$fiche_id,$pid,$val,$conf,$biomed,$comment]);
            if ($biomed) {
                $pdo->prepare("INSERT INTO notifications (type,titre,message,lien) VALUES('alerte','🔧 Signalement biomédical','Anomalie détectée lors d\'une vérification','/admin/fiches.php')")->execute([]);
            }
        }
        audit_log($pdo,'FICHE_VALIDEE','fiches_remplies',$fiche_id);
        flashSuccess($hasAnomal?"⚠️ Fiche validée avec anomalie(s).":"✅ Fiche validée !");
        header("Location: fiches.php?done=$fiche_id"); exit;
    }
}

$modeles=$pdo->prepare("SELECT m.*,COUNT(DISTINCT sec.id) AS nb_sections,COUNT(DISTINCT pt.id) AS nb_points FROM modeles_fiches m LEFT JOIN modele_sections sec ON sec.modele_id=m.id LEFT JOIN modele_points pt ON pt.section_id=sec.id WHERE m.site_id=? AND m.actif=1 GROUP BY m.id ORDER BY m.nom");
$modeles->execute([$site_id]); $modeles=$modeles->fetchAll(PDO::FETCH_ASSOC);

// ── Consultation fiche remplie ─────────────────────────────
$view_fiche = null; $view_reponses = []; $view_sections = [];
if ($view_id = (int)($_GET['voir'] ?? 0)) {
    $sf = $pdo->prepare("
        SELECT f.*, m.nom AS modele_nom, u.prenom, u.nom AS user_nom
        FROM fiches_remplies f
        JOIN modeles_fiches m ON m.id = f.modele_id
        JOIN utilisateurs u ON u.id = f.user_id
        WHERE f.id = ? AND f.site_id = ?
    ");
    $sf->execute([$view_id, $site_id]);
    $view_fiche = $sf->fetch(PDO::FETCH_ASSOC);
    // Sécurité : admin ou auteur uniquement
    if ($view_fiche && !isAdmin() && $view_fiche['user_id'] !== currentUserId()) {
        $view_fiche = null;
    }
    if ($view_fiche) {
        // Récupérer les réponses avec les libellés
        $reps = $pdo->prepare("
            SELECT fr.*, mp.libelle, mp.type, mp.unite, mp.bloquant, mp.obligatoire,
                   ms.titre AS section_titre, ms.ordre AS section_ordre
            FROM fiche_reponses fr
            JOIN modele_points mp ON mp.id = fr.point_id
            JOIN modele_sections ms ON ms.id = mp.section_id
            WHERE fr.fiche_id = ?
            ORDER BY ms.ordre, mp.ordre
        ");
        $reps->execute([$view_id]);
        foreach ($reps->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $view_sections[$r['section_titre']][] = $r;
        }
    }
}

// ── Liste des fiches remplies (pour admin ou propres fiches) ─
$fiches_remplies = [];
if (!isset($_GET['modele']) && !isset($_GET['done']) && !isset($_GET['voir'])) {
    $canSeeAll = isAdmin();
    if ($canSeeAll) {
        $fr = $pdo->prepare("
            SELECT f.*, m.nom AS modele_nom, u.prenom, u.nom AS user_nom
            FROM fiches_remplies f
            JOIN modeles_fiches m ON m.id = f.modele_id
            JOIN utilisateurs u ON u.id = f.user_id
            WHERE f.site_id = ?
            ORDER BY f.cree_le DESC LIMIT 50
        ");
        $fr->execute([$site_id]);
    } else {
        $fr = $pdo->prepare("
            SELECT f.*, m.nom AS modele_nom, u.prenom, u.nom AS user_nom
            FROM fiches_remplies f
            JOIN modeles_fiches m ON m.id = f.modele_id
            JOIN utilisateurs u ON u.id = f.user_id
            WHERE f.site_id = ? AND f.user_id = ?
            ORDER BY f.cree_le DESC LIMIT 20
        ");
        $fr->execute([$site_id, currentUserId()]);
    }
    $fiches_remplies = $fr->fetchAll(PDO::FETCH_ASSOC);
}

$modele=null; $sections=[]; $prev_reponses=[];
if ($modele_id) {
    $s=$pdo->prepare("SELECT * FROM modeles_fiches WHERE id=? AND site_id=? AND actif=1");
    $s->execute([$modele_id,$site_id]); $modele=$s->fetch(PDO::FETCH_ASSOC);
    if ($modele) {
        $secs=$pdo->prepare("SELECT * FROM modele_sections WHERE modele_id=? ORDER BY ordre");
        $secs->execute([$modele_id]); $secs=$secs->fetchAll(PDO::FETCH_ASSOC);
        foreach ($secs as &$sec) {
            $pts=$pdo->prepare("SELECT * FROM modele_points WHERE section_id=? ORDER BY ordre");
            $pts->execute([$sec['id']]); $pts=$pts->fetchAll(PDO::FETCH_ASSOC);
            if ($mode==='express') $pts=array_values(array_filter($pts,fn($p)=>$p['bloquant']));
            $sec['points']=$pts;
        }
        unset($sec);
        $sections=array_values(array_filter($secs,fn($s)=>!empty($s['points'])));
        $prev=$pdo->prepare("SELECT fr2.* FROM fiches_remplies fr JOIN fiche_reponses fr2 ON fr2.fiche_id=fr.id WHERE fr.modele_id=? AND fr.site_id=? ORDER BY fr.cree_le DESC LIMIT 100");
        $prev->execute([$modele_id,$site_id]);
        foreach($prev->fetchAll(PDO::FETCH_ASSOC) as $r) if(!isset($prev_reponses[$r['point_id']])) $prev_reponses[$r['point_id']]=$r;
    }
}

$done_fiche=null;
if ($done_id=(int)($_GET['done']??0)) {
    $s=$pdo->prepare("SELECT f.*,m.nom AS modele_nom FROM fiches_remplies f JOIN modeles_fiches m ON m.id=f.modele_id WHERE f.id=? AND f.site_id=?");
    $s->execute([$done_id,$site_id]); $done_fiche=$s->fetch(PDO::FETCH_ASSOC);
}

$nb_total=$sections?array_sum(array_map(fn($s)=>count($s['points']),$sections)):0;
require_once 'includes/header.php';
?>
<style>
.fiche-point{background:var(--glass);border:1px solid var(--border);border-radius:12px;padding:1rem;margin-bottom:.75rem;transition:border-color .2s}
.fiche-point.nok{background:rgba(239,68,68,.06);border-color:rgba(239,68,68,.3)}
.fiche-point.ok{background:rgba(16,185,129,.04);border-color:rgba(16,185,129,.2)}
.btn-yn{display:flex;gap:.5rem}
.btn-yn button{flex:1;padding:.75rem;border-radius:10px;border:2px solid var(--border);background:var(--glass);color:var(--text);font-size:.875rem;font-weight:700;cursor:pointer;font-family:var(--font);transition:all .15s}
.btn-yn button.ok{background:rgba(16,185,129,.2);border-color:#10b981;color:#34d399}
.btn-yn button.nok{background:rgba(239,68,68,.2);border-color:#ef4444;color:#f87171}
.prog-bar{height:5px;background:var(--border2);border-radius:99px;overflow:hidden;margin-bottom:1rem}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--primary),#10b981);border-radius:99px;transition:width .3s}
.sec-titre{
  font-size:1rem;font-weight:800;color:var(--text);
  margin:2rem 0 .75rem;padding:.75rem 1rem;
  background:rgba(var(--primary-rgb),.08);
  border-left:4px solid var(--primary);
  border-radius:0 10px 10px 0;
  display:flex;align-items:center;gap:.5rem;
  letter-spacing:-.2px;text-transform:none;
}
.sec-titre:first-child{margin-top:.5rem}
.sec-titre-count{
  margin-left:auto;font-size:.72rem;font-weight:600;
  background:rgba(var(--primary-rgb),.15);color:var(--primary);
  padding:2px 8px;border-radius:20px;
}
.sig-cv{width:100%;height:130px;background:var(--surface2);border:1px solid var(--border);border-radius:12px;touch-action:none;display:block}
.mod-card{background:var(--glass);border:1px solid var(--border);border-radius:12px;padding:1rem;margin-bottom:.75rem;text-decoration:none;color:var(--text);display:block;transition:all .15s}
.mod-card:hover,.mod-card:active{border-color:var(--primary);background:rgba(59,130,246,.06)}

/* ── PRINT / PDF ── */
@media print {
  @page { size: A4 portrait; margin: 1.2cm; }

  /* Masquer tout sauf la fiche */
  nav, .sidebar, header, footer,
  .no-print, .btn-ghost, .btn-primary,
  #ai-btn, #ai-panel,
  .prog-bar, #prog-nok-wrap,
  [class*="flash"], .page-header > *:last-child { display: none !important; }

  body { background: white !important; color: #111 !important; font-size: 9.5pt; }

  /* En-tête print */
  .print-header {
    display: flex !important;
    align-items: center; justify-content: space-between;
    border-bottom: 2px solid #e5e7eb;
    padding-bottom: .6rem; margin-bottom: 1rem;
  }
  .print-header-logo { font-size: 1.1rem; font-weight: 800; color: #111; }
  .print-header-info { font-size: .75rem; color: #6b7280; text-align: right; }

  /* Sections */
  .sec-titre {
    background: #f3f4f6 !important;
    color: #111 !important;
    border-left: 4px solid #3b82f6 !important;
    font-size: 9pt !important;
    padding: .4rem .75rem !important;
    margin: .8rem 0 .4rem !important;
    break-after: avoid;
  }
  .sec-titre-count { background: #e5e7eb !important; color: #374151 !important; }

  /* Points */
  .fiche-point {
    background: white !important;
    border: 1px solid #e5e7eb !important;
    border-radius: 4px !important;
    padding: .35rem .6rem !important;
    margin-bottom: .25rem !important;
    break-inside: avoid;
    display: flex; align-items: center;
    justify-content: space-between; gap: .5rem;
  }
  .fiche-point.ok  { border-left: 3px solid #10b981 !important; }
  .fiche-point.nok { border-left: 3px solid #ef4444 !important; background: #fff5f5 !important; }

  /* Masquer les boutons OK/NOK et inputs dans la vue print */
  .btn-yn, input[type="number"], textarea,
  canvas, .sig-cv, button:not(.print-btn) { display: none !important; }

  /* Réponses en print */
  .print-val { display: inline !important; }

  /* Signature */
  .print-sig {
    margin-top: 1rem; padding-top: .75rem;
    border-top: 1px solid #e5e7eb;
    display: grid !important; grid-template-columns: 1fr 1fr; gap: 1rem;
  }
  .sig-box-print { border: 1px dashed #d1d5db; border-radius: 4px; padding: .5rem; min-height: 60px; }
  .sig-box-label { font-size: .65rem; text-transform: uppercase; letter-spacing: .8px; color: #9ca3af; }

  /* Footer pages */
  .print-footer {
    display: block !important;
    position: fixed; bottom: 0; left: 0; right: 0;
    font-size: .65rem; color: #9ca3af;
    border-top: 1px solid #e5e7eb; padding: .3rem 0;
    text-align: center;
  }
}
/* Masquer les éléments print sur écran */
.print-header, .print-footer, .print-sig { display: none; }
</style>

<?php if ($view_fiche): ?>
<!-- ── HEADER PRINT (visible uniquement à l'impression) ── -->
<div class="print-header">
  <div class="print-header-logo">💊 Cadurix — Fiche de vérification</div>
  <div class="print-header-info">
    <?= h($view_fiche['modele_nom']) ?><br>
    Remplie le <?= date('d/m/Y à H:i', strtotime($view_fiche['cree_le'])) ?><br>
    Par <?= h($view_fiche['prenom'].' '.$view_fiche['user_nom']) ?> ·
    Statut : <?= $view_fiche['statut']==='anomalie' ? '⚠️ Anomalie' : '✅ Validée' ?>
  </div>
</div>

<!-- ── VUE FICHE REMPLIE ── -->
<div class="no-print" style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem">
  <a href="fiches.php" style="color:var(--muted);text-decoration:none;font-size:1.2rem;padding:.25rem">←</a>
  <div style="flex:1">
    <div style="font-weight:700;font-size:.95rem"><?= h($view_fiche['modele_nom']) ?></div>
    <div style="font-size:.73rem;color:var(--muted)">
      Remplie le <?= date('d/m/Y à H:i', strtotime($view_fiche['cree_le'])) ?>
      par <?= h($view_fiche['prenom'].' '.$view_fiche['user_nom']) ?>
    </div>
  </div>
  <span style="padding:.3rem .75rem;border-radius:99px;font-size:.78rem;font-weight:700;
    background:<?= $view_fiche['statut']==='anomalie'?'rgba(239,68,68,.15)':'rgba(16,185,129,.15)' ?>;
    color:<?= $view_fiche['statut']==='anomalie'?'#f87171':'#34d399' ?>">
    <?= $view_fiche['statut']==='anomalie' ? '⚠️ Anomalie' : '✅ Validée' ?>
  </span>
  <a href="fiche_controle.php?fiche=<?= $view_id ?>" target="_blank" class="btn btn-primary btn-sm no-print" style="display:inline-flex;align-items:center;gap:5px">
    🖨️ Imprimer / PDF
  </a>
</div>

<?php foreach ($view_sections as $sec_titre => $reponses): ?>
<div class="sec-titre">📌 <?= h($sec_titre) ?></div>
<?php foreach ($reponses as $r): ?>
<div class="fiche-point <?= $r['conforme']?'ok':'nok' ?>" style="pointer-events:none">
  <div style="font-size:.875rem;font-weight:600;flex:1">
    <?= $r['bloquant']?'🔴 ':'' ?><?= h($r['libelle']) ?>
    <?php if($r['unite']): ?><span style="color:var(--muted);font-size:.78rem"> (<?= h($r['unite']) ?>)</span><?php endif; ?>
    <?php if (!$r['conforme'] && $r['commentaire']): ?>
    <div style="font-size:.78rem;color:#f87171;margin-top:.2rem">📝 <?= h($r['commentaire']) ?></div>
    <?php endif; ?>
    <?php if ($r['signale_biomed']): ?>
    <div style="font-size:.72rem;color:#f87171">🔧 Signalé au biomédical</div>
    <?php endif; ?>
  </div>
  <div style="flex-shrink:0;font-size:.85rem;font-weight:700">
    <?php if ($r['type']==='oui_non'): ?>
      <span style="color:<?= $r['conforme']?'#34d399':'#f87171' ?>">
        <?= $r['conforme'] ? '✅ OK' : '❌ NOK' ?>
      </span>
    <?php else: ?>
      <span style="color:var(--text)"><?= h($r['valeur'] ?? '—') ?></span>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

<?php if ($view_fiche['notes']): ?>
<div class="sec-titre">📝 Notes</div>
<div class="card" style="font-size:.875rem;color:var(--text2)"><?= h($view_fiche['notes']) ?></div>
<?php endif; ?>

<?php if ($view_fiche['signature']): ?>
<div class="sec-titre">✍️ Signature</div>
<div style="background:var(--glass);border:1px solid var(--border);border-radius:12px;padding:.75rem;display:inline-block">
  <img src="<?= h($view_fiche['signature']) ?>" style="max-height:100px;display:block">
</div>
<?php endif; ?>

<!-- Signature print -->
<div class="print-sig">
  <div class="sig-box-print">
    <div class="sig-box-label">Contrôlé par</div>
    <div style="font-size:.85rem;font-weight:600;margin:.3rem 0"><?= h($view_fiche['prenom'].' '.$view_fiche['user_nom']) ?></div>
    <?php if ($view_fiche['signature']): ?>
    <img src="<?= h($view_fiche['signature']) ?>" style="max-height:50px;margin-top:.3rem">
    <?php endif; ?>
  </div>
  <div class="sig-box-print">
    <div class="sig-box-label">Validé par</div>
    <div style="border-bottom:1px solid #d1d5db;margin-top:1.5rem"></div>
  </div>
</div>

<!-- Footer print -->
<div class="print-footer">
  Cadurix · <?= h($view_fiche['modele_nom']) ?> · <?= date('d/m/Y à H:i', strtotime($view_fiche['cree_le'])) ?> · <?= h($view_fiche['prenom'].' '.$view_fiche['user_nom']) ?>
</div>

<div style="margin-top:2rem" class="no-print">
  <a href="fiches.php" class="btn btn-ghost">← Retour aux fiches</a>
  <a href="fiche_controle.php?fiche=<?= $view_id ?>" target="_blank" class="btn btn-primary" style="margin-left:.5rem">🖨️ Imprimer / PDF</a>
</div>

<?php elseif ($done_fiche): ?>
<div style="text-align:center;padding:4rem 1rem">
  <div style="font-size:4rem;margin-bottom:1rem"><?= $done_fiche['statut']==='anomalie'?'⚠️':'✅' ?></div>
  <div style="font-size:1.3rem;font-weight:800;margin-bottom:.5rem"><?= $done_fiche['statut']==='anomalie'?'Fiche validée avec anomalie(s)':'Fiche validée !' ?></div>
  <div style="color:var(--text2);font-size:.875rem;margin-bottom:2.5rem"><?= h($done_fiche['modele_nom']) ?> · <?= date('d/m/Y à H:i') ?></div>
  <div style="display:flex;flex-direction:column;gap:.75rem;max-width:280px;margin:0 auto">
    <a href="fiches.php" class="btn btn-primary">📋 Nouvelle fiche</a>
    <a href="dashboard.php" class="btn btn-ghost">🏠 Dashboard</a>
  </div>
</div>

<?php elseif ($modele && !empty($sections)): ?>
<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem">
  <a href="fiches.php" style="color:var(--muted);text-decoration:none;font-size:1.2rem;padding:.25rem">←</a>
  <div style="flex:1;min-width:0">
    <div style="font-weight:700;font-size:.95rem"><?= h($modele['nom']) ?></div>
    <div style="font-size:.73rem;color:var(--muted)"><?= date('d/m/Y') ?> · <?= h(trim(($_SESSION['user_prenom']??'').' '.($_SESSION['user_nom']??''))) ?></div>
  </div>
  <a href="fiches.php?modele=<?= $modele_id ?>&mode=<?= $mode==='express'?'complet':'express' ?>" class="btn btn-ghost btn-sm">
    <?= $mode==='express'?'📋 Complet':'⚡ Express' ?>
  </a>
</div>

<div style="position:sticky;top:0;z-index:10;background:var(--bg);padding:.5rem 0;margin-bottom:.5rem">
  <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.4rem">
    <span style="font-size:.78rem;color:var(--muted)"><span id="prog-txt">0</span>/<?= $nb_total ?> points</span>
    <span id="prog-nok-wrap" style="display:none;font-size:.78rem;color:#f87171">· <span id="prog-nok">0</span> anomalie(s)</span>
    <span style="margin-left:auto;font-size:.78rem;color:var(--muted)" id="prog-pct">0%</span>
  </div>
  <div class="prog-bar"><div class="prog-fill" id="prog" style="width:0%"></div></div>
</div>

<?php if ($mode==='express'): ?><div class="flash flash-info" style="margin-bottom:1rem">⚡ Mode express — points critiques uniquement</div><?php endif; ?>

<form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="valider_fiche" value="1">
  <input type="hidden" name="modele_id" value="<?= $modele_id ?>">
  <input type="hidden" name="mode" value="<?= $mode ?>">
  <input type="hidden" name="signature" id="sig-data">

  <?php foreach ($sections as $sec): ?>
  <div class="sec-titre">
    📌 <?= h($sec['titre']) ?>
    <span class="sec-titre-count"><?= count($sec['points']) ?> point<?= count($sec['points'])>1?'s':'' ?></span>
  </div>
  <?php foreach ($sec['points'] as $pt):
    $prev = $prev_reponses[$pt['id']]??null;
    $prev_nok = $prev && $prev['conforme']==0;
  ?>
  <div class="fiche-point <?= $prev_nok?'nok':'' ?>" id="pt-<?= $pt['id'] ?>">
    <div style="font-size:.92rem;font-weight:600;margin-bottom:.6rem;line-height:1.4">
      <?php if($pt['bloquant']): ?><span style="background:rgba(239,68,68,.12);color:#f87171;font-size:.7rem;padding:1px 6px;border-radius:4px;margin-right:5px;font-weight:700">BLOQUANT</span><?php endif; ?>
      <?= h($pt['libelle']) ?>
      <?php if($pt['unite']): ?><span style="color:var(--muted);font-size:.8rem;font-weight:500"> — <?= h($pt['unite']) ?></span><?php endif; ?>
      <?php if($pt['obligatoire']): ?><span style="color:#ef4444;font-size:.8rem"> *</span><?php endif; ?>
    </div>
    <?php if ($prev_nok): ?>
    <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:8px;padding:.5rem .75rem;font-size:.76rem;margin-bottom:.75rem;color:#fcd34d">
      ⚠️ NOK au dernier contrôle <?= $prev['commentaire']?'— '.h($prev['commentaire']):'' ?>
    </div>
    <?php endif; ?>

    <?php if ($pt['type']==='oui_non'): ?>
    <input type="hidden" name="reponse[<?= $pt['id'] ?>]" value="oui" id="rep-<?= $pt['id'] ?>">
    <input type="hidden" name="conforme[<?= $pt['id'] ?>]" value="1" id="conf-<?= $pt['id'] ?>">
    <div class="btn-yn">
      <button type="button" class="ok" id="bok-<?= $pt['id'] ?>" onclick="yn(<?= $pt['id'] ?>,true)">✅ OK</button>
      <button type="button" id="bnok-<?= $pt['id'] ?>" onclick="yn(<?= $pt['id'] ?>,false)">❌ NOK</button>
    </div>

    <?php elseif ($pt['type']==='numerique'): ?>
    <input type="hidden" name="conforme[<?= $pt['id'] ?>]" value="1" id="conf-<?= $pt['id'] ?>">
    <div style="display:flex;align-items:center;gap:.5rem;margin-top:.25rem">
      <button type="button" onclick="stepNum(<?= $pt['id'] ?>,-1)"
        style="width:40px;height:40px;border-radius:8px;border:1px solid var(--border);background:var(--glass);color:var(--text);font-size:1.3rem;cursor:pointer;display:flex;align-items:center;justify-content:center;font-weight:700">−</button>
      <input type="number" name="reponse[<?= $pt['id'] ?>]" id="rep-<?= $pt['id'] ?>"
             class="form-control" style="width:80px;font-size:1.2rem;font-weight:800;text-align:center;padding:.4rem" placeholder="0" step="1" min="0"
             oninput="checkNum(<?= $pt['id'] ?>,<?= $pt['valeur_min']??'null' ?>,<?= $pt['valeur_max']??'null' ?>)">
      <button type="button" onclick="stepNum(<?= $pt['id'] ?>,1)"
        style="width:40px;height:40px;border-radius:8px;border:1px solid var(--border);background:var(--glass);color:var(--text);font-size:1.3rem;cursor:pointer;display:flex;align-items:center;justify-content:center;font-weight:700">+</button>
      <?php if($pt['valeur_min']!==null&&$pt['valeur_max']!==null): ?>
      <span style="font-size:.76rem;color:var(--muted);padding:3px 8px;background:var(--glass);border-radius:6px">Ref: <?= $pt['valeur_min'] ?>–<?= $pt['valeur_max'] ?></span>
      <?php elseif($pt['valeur_min']!==null): ?>
      <span style="font-size:.76rem;color:var(--muted);padding:3px 8px;background:var(--glass);border-radius:6px">Min: <?= $pt['valeur_min'] ?></span>
      <?php endif; ?>
    </div>

    <?php elseif ($pt['type']==='texte'): ?>
    <input type="hidden" name="conforme[<?= $pt['id'] ?>]" value="1" id="conf-<?= $pt['id'] ?>">
    <textarea name="reponse[<?= $pt['id'] ?>]" class="form-control" rows="2" placeholder="Observation..."></textarea>

    <?php else: ?>
    <input type="hidden" name="conforme[<?= $pt['id'] ?>]" value="1" id="conf-<?= $pt['id'] ?>">
    <input type="file" name="reponse_photo_<?= $pt['id'] ?>" class="form-control" accept="image/*" capture="environment">
    <?php endif; ?>

    <div id="nok-<?= $pt['id'] ?>" style="display:none;margin-top:.75rem">
      <textarea name="comment[<?= $pt['id'] ?>]" class="form-control" rows="2" placeholder="Décrire l'anomalie..." style="margin-bottom:.4rem"></textarea>
      <?php if ($pt['peut_signaler_biomed']): ?>
      <label style="display:flex;align-items:center;gap:8px;font-size:.875rem;cursor:pointer;padding:.5rem .75rem;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.15);border-radius:8px">
        <input type="checkbox" name="biomed[<?= $pt['id'] ?>]" value="1" style="accent-color:#ef4444">
        🔧 Signaler au biomédical
      </label>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endforeach; ?>

  <div style="margin-top:1.5rem">
    <div class="sec-titre" style="margin-top:0">📝 Notes</div>
    <textarea name="notes" class="form-control" rows="2" placeholder="Remarques générales..."></textarea>
  </div>
  <div style="margin-top:1.25rem">
    <div class="sec-titre" style="margin-top:0">✍️ Signature</div>
    <canvas id="sig-cv" class="sig-cv"></canvas>
    <button type="button" class="btn btn-ghost btn-sm" style="margin-top:.4rem" onclick="clearSig()">🗑️ Effacer</button>
  </div>
  <button type="submit" class="btn btn-primary" style="width:100%;padding:1rem;margin-top:1.5rem;font-size:1rem" onclick="saveSig()">✅ Valider la fiche</button>
</form>

<?php else: ?>
<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">📋 Fiches de vérification</div>
    <div class="page-sub">Choisissez une fiche</div>
  </div>
  <?php if(peut_faire(ROLE_GESTIONNAIRE)): ?><a href="admin/fiches.php" class="btn btn-ghost btn-sm">⚙️ Configurer</a><?php endif; ?>
</div>

<?php if(empty($modeles)): ?>
<div class="card" style="text-align:center;padding:3rem">
  <div style="font-size:2.5rem;margin-bottom:1rem">📋</div>
  <div style="font-weight:700;margin-bottom:.5rem">Aucune fiche configurée</div>
  <?php if(peut_faire(ROLE_GESTIONNAIRE)): ?><a href="admin/fiches.php" class="btn btn-primary btn-sm">⚙️ Configurer</a><?php endif; ?>
</div>
<?php else: ?>
<?php foreach($modeles as $m): ?>
<a href="fiches.php?modele=<?= $m['id'] ?>" class="mod-card">
  <div style="display:flex;align-items:center;gap:.875rem">
    <div style="width:44px;height:44px;border-radius:10px;background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.2);display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0">
      <?= match($m['type']){'emplacement'=>'📦','materiel'=>'🔧','vehicule'=>'🚑',default=>'📋'} ?>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-weight:700"><?= h($m['nom']) ?></div>
      <div style="font-size:.74rem;color:var(--muted);margin-top:.15rem"><?= $m['nb_sections'] ?> section(s) · <?= $m['nb_points'] ?> point(s) · <?= match($m['frequence']){'quotidien'=>'Quotidienne','hebdomadaire'=>'Hebdomadaire','mensuel'=>'Mensuelle',default=>'Ponctuelle'} ?></div>
    </div>
    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.3rem;flex-shrink:0">
      <span style="font-size:.72rem;color:var(--primary)">→</span>
      <a href="fiches.php?modele=<?= $m['id'] ?>&mode=express" onclick="event.stopPropagation()" style="font-size:.68rem;color:var(--muted);text-decoration:none;padding:.15rem .45rem;background:var(--glass);border:1px solid var(--border);border-radius:4px">⚡ Express</a>
    </div>
  </div>
</a>
<?php endforeach; ?>
<?php endif; ?>

<!-- ── Fiches remplies ── -->
<?php if (!empty($fiches_remplies)): ?>
<div style="margin-top:2rem">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
    <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted)">
      Fiches remplies <?= isAdmin() ? '' : '(les miennes)' ?>
    </div>
  </div>
  <?php foreach($fiches_remplies as $fr): ?>
  <a href="fiches.php?voir=<?= $fr['id'] ?>" style="display:block;text-decoration:none;color:var(--text);
    background:var(--glass);border:1px solid var(--border);border-radius:12px;padding:.875rem 1rem;
    margin-bottom:.5rem;transition:all .15s"
    onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'">
    <div style="display:flex;align-items:center;gap:.875rem">
      <div style="font-size:1.3rem"><?= $fr['statut']==='anomalie'?'⚠️':'✅' ?></div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:700;font-size:.875rem"><?= h($fr['modele_nom']) ?></div>
        <div style="font-size:.72rem;color:var(--muted);margin-top:.15rem">
          <?= date('d/m/Y à H:i', strtotime($fr['cree_le'])) ?>
          · <?= h($fr['prenom'].' '.$fr['user_nom']) ?>
        </div>
      </div>
      <span style="font-size:.72rem;font-weight:700;padding:.2rem .6rem;border-radius:99px;
        background:<?= $fr['statut']==='anomalie'?'rgba(239,68,68,.15)':'rgba(16,185,129,.15)' ?>;
        color:<?= $fr['statut']==='anomalie'?'#f87171':'#34d399' ?>">
        <?= $fr['statut']==='anomalie' ? 'Anomalie' : 'Validée' ?>
      </span>
      <span style="color:var(--primary);font-size:.72rem">→</span>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
const nbTotal = <?= $nb_total ?>;
let answered  = new Set();

function yn(id, isOk) {
  document.getElementById('bok-'+id).className  = isOk?'ok':'';
  document.getElementById('bnok-'+id).className = isOk?'':'nok';
  document.getElementById('pt-'+id).className   = 'fiche-point '+(isOk?'ok':'nok');
  document.getElementById('conf-'+id).value     = isOk?'1':'0';
  const nz = document.getElementById('nok-'+id);
  if (nz) nz.style.display = isOk?'none':'block';
  answered.add(id); updateProg();
}

function checkNum(id, min, max) {
  const v = parseFloat(document.getElementById('rep-'+id).value);
  let ok = true;
  if (min!==null && v<min) ok=false;
  if (max!==null && v>max) ok=false;
  document.getElementById('conf-'+id).value=ok?'1':'0';
  const nz=document.getElementById('nok-'+id);
  if(nz) nz.style.display=ok?'none':'block';
  document.getElementById('pt-'+id).className='fiche-point '+(ok?'ok':'nok');
  answered.add(id); updateProg();
}

function stepNum(id, delta) {
  const inp = document.getElementById('rep-'+id);
  if (!inp) return;
  const val = Math.max(0, (parseInt(inp.value)||0) + delta);
  inp.value = val;
  // Déclenche checkNum si valeurs min/max définies
  inp.dispatchEvent(new Event('input'));
  answered.add(id); updateProg();
}

function updateProg() {
  const pct = nbTotal>0?Math.round(answered.size/nbTotal*100):0;
  const f=document.getElementById('prog'); if(f) f.style.width=pct+'%';
  const t=document.getElementById('prog-txt'); if(t) t.textContent=answered.size;
}

// Signature
const cv = document.getElementById('sig-cv');
if (cv) {
  const ctx=cv.getContext('2d'); let drawing=false,hasSig=false;
  cv.width=cv.offsetWidth; cv.height=130;
  ctx.strokeStyle='#e2e8f0'; ctx.lineWidth=2.5; ctx.lineCap=ctx.lineJoin='round';
  const gp=e=>{const r=cv.getBoundingClientRect(),t=e.touches?e.touches[0]:e;return[t.clientX-r.left,t.clientY-r.top]};
  cv.addEventListener('pointerdown',e=>{drawing=true;hasSig=true;const[x,y]=gp(e);ctx.beginPath();ctx.moveTo(x,y)});
  cv.addEventListener('pointermove',e=>{if(!drawing)return;const[x,y]=gp(e);ctx.lineTo(x,y);ctx.stroke()});
  cv.addEventListener('pointerup',()=>drawing=false);
  window.clearSig=()=>{ctx.clearRect(0,0,cv.width,cv.height);hasSig=false};
  window.saveSig=()=>{if(hasSig){const el=document.getElementById('sig-data');if(el)el.value=cv.toDataURL('image/png',.6)}};
} else { window.clearSig=window.saveSig=()=>{}; }
</script>
<?php require_once 'includes/footer.php'; ?>
