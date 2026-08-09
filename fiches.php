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
        // Carte des points (libellé + bloquant) pour statut & notifications
        $ptsMap=[];
        $qpm=$pdo->prepare("SELECT mp.id,mp.libelle,mp.bloquant FROM modele_points mp JOIN modele_sections ms ON ms.id=mp.section_id WHERE ms.modele_id=?");
        $qpm->execute([$mid]);
        foreach($qpm->fetchAll(PDO::FETCH_ASSOC) as $r) $ptsMap[$r['id']]=$r;
        // Règle : anomalie uniquement si un point BLOQUANT est non conforme
        $hasAnomal=false;
        foreach($conformes as $pid=>$v){ if($v==0 && !empty($ptsMap[$pid]['bloquant'])){ $hasAnomal=true; break; } }
        $pdo->prepare("INSERT INTO fiches_remplies (modele_id,modele_version,user_id,site_id,statut,mode,signature,notes,valide_le) VALUES(?,?,?,?,?,?,?,?,NOW())")
            ->execute([$mid,$mod['version'],currentUserId(),$site_id,$hasAnomal?'anomalie':'valide',$_POST['mode']??'complet',$sig,$notes]);
        $fiche_id=(int)$pdo->lastInsertId();
        foreach(($_POST['reponse']??[]) as $pid=>$val) {
            $conf=(int)($conformes[$pid]??1);
            $biomed=isset($_POST['biomed'][$pid])?1:0;
            $prio=(($_POST['biomed_prio'][$pid]??'normal')==='urgent')?'urgent':'normal';
            $comment=trim($_POST['comment'][$pid]??'')?:null;
            // Raison de non-conformité (points oui/non) → préfixe lisible dans le commentaire
            $raison=$_POST['raison'][$pid]??'';
            $prefix='';
            if($raison==='quantite'){ $q=trim($_POST['raison_qty'][$pid]??''); $prefix='Quantité présente : '.($q!==''?$q:'?'); }
            elseif($raison==='perime'){ $dt=trim($_POST['raison_date'][$pid]??''); $prefix='Périmé'.($dt?' ('.$dt.')':''); }
            elseif($raison==='manquant'){ $prefix='Manquant'; }
            elseif($raison==='abime'){ $prefix='Abîmé / inutilisable'; }
            if($prefix!==''){ $comment=$prefix.($comment?' — '.$comment:''); }
            $pdo->prepare("INSERT INTO fiche_reponses (fiche_id,point_id,valeur,conforme,signale_biomed,commentaire) VALUES(?,?,?,?,?,?)")->execute([$fiche_id,$pid,$val,$conf,$biomed,$comment]);
            if ($biomed) {
                $lib=$ptsMap[$pid]['libelle']??'Point';
                $titre=$prio==='urgent'?'\u{1F527} Signalement biomédical — URGENT':'\u{1F527} Signalement biomédical';
                $msg='Défaut signalé sur « '.$lib.' » lors d\'une vérification'.($comment?' : '.$comment:'').'.';
                $pdo->prepare("INSERT INTO notifications (type,titre,message,lien) VALUES('alerte',?,?,?)")->execute([$titre,$msg,'/fiches.php?voir='.$fiche_id]);
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
    $canSeeAll = isGestionnaire(); // admin + gestionnaire voient tout
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

/* ── Flux guidé hybride (remplissage) ── */
.vf-head{display:flex;align-items:center;gap:.6rem;margin-bottom:.8rem}
.vf-back{color:var(--muted);text-decoration:none;font-size:1.3rem;padding:.2rem .4rem}
.vf-title{font-weight:800;font-size:1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.vf-sub{font-size:.72rem;color:var(--muted)}
.vf-progress{position:sticky;top:0;z-index:10;background:var(--bg);padding:.5rem 0 .6rem;margin-bottom:.6rem}
.vf-progress-row{display:flex;font-size:.76rem;color:var(--muted);margin-bottom:.4rem;font-weight:600}
.vstep-head{margin:.2rem 0 1rem}
.vstep-eyebrow{font-size:.68rem;letter-spacing:.14em;text-transform:uppercase;color:var(--primary);font-weight:800}
.vstep-title{font-size:1.25rem;font-weight:800;letter-spacing:-.02em;margin-top:3px}
.vblock-label{font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin:1.3rem .2rem .6rem;display:flex;align-items:center;gap:.5rem}
.vpt-top{display:flex;align-items:flex-start;justify-content:space-between;gap:.6rem;margin-bottom:.6rem}
.vpt-name{font-size:.92rem;font-weight:700;line-height:1.4}
.vpt-unit{color:var(--muted);font-size:.8rem;font-weight:500}
.vprev{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:8px;padding:.45rem .7rem;font-size:.74rem;margin-bottom:.7rem;color:#fcd34d}
.vbadge{font-size:.6rem;font-weight:800;letter-spacing:.05em;padding:3px 7px;border-radius:6px;white-space:nowrap;text-transform:uppercase}
.vbadge-crit{color:#ffd9a1;background:rgba(245,158,11,.14);border:1px solid rgba(245,158,11,.35)}
.vbadge-meas{color:#bcd4ff;background:rgba(59,130,246,.14);border:1px solid rgba(59,130,246,.35)}
.fiche-point[data-crit]{border-left:3px solid #f59e0b}
.fiche-point.ok[data-crit]{border-left-color:#10b981}
.fiche-point.nok[data-crit]{border-left-color:#ef4444}
.vnum{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
.vstep-btn{width:42px;height:42px;border-radius:10px;border:1px solid var(--border);background:var(--glass);color:var(--text);font-size:1.4rem;font-weight:700;cursor:pointer;flex-shrink:0}
.vnum-in{width:84px;text-align:center;font-size:1.2rem;font-weight:800;padding:.45rem}
.vref{font-size:.74rem;color:var(--muted);padding:4px 9px;background:var(--glass);border:1px solid var(--border);border-radius:7px}
.vnok{margin-top:.7rem}
.vbio{display:flex;align-items:center;gap:8px;font-size:.85rem;cursor:pointer;padding:.5rem .7rem;background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.18);border-radius:8px;margin-top:.4rem}
.vprio{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-top:.5rem}
.vprio button{padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--glass);color:var(--muted);font-weight:700;font-size:.82rem;cursor:pointer;font-family:var(--font)}
.vprio button.on{border-color:var(--primary);color:var(--text);background:rgba(59,130,246,.12)}
.vpres-card{background:var(--glass);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.vnorm-wrap{border-bottom:1px solid var(--border)}
.vnorm-wrap:last-child{border-bottom:none}
.vnorm{display:flex;align-items:center;gap:.7rem;padding:.7rem .85rem}
.vnorm-wrap.flagged{background:rgba(239,68,68,.06)}
.vtick{width:22px;height:22px;border-radius:50%;background:rgba(16,185,129,.18);border:1px solid rgba(16,185,129,.4);display:flex;align-items:center;justify-content:center;color:#34d399;font-size:13px;flex-shrink:0}
.vnorm-wrap.flagged .vtick{background:rgba(239,68,68,.18);border-color:rgba(239,68,68,.4);color:#f87171}
.vnm{flex:1;font-size:.9rem;font-weight:500}
.vflag{font-size:.76rem;color:var(--muted);background:none;border:1px solid var(--border);padding:6px 11px;border-radius:9px;cursor:pointer;font-family:var(--font);font-weight:600}
.vflag.on{background:#ef4444;border-color:#ef4444;color:#fff}
.vnok .form-control{margin-bottom:.4rem}
.vnorm-wrap .vnok{padding:0 .85rem .7rem}
.vack{width:100%;margin-top:.7rem;padding:.8rem;border-radius:11px;border:1px dashed var(--border2);background:var(--glass);color:var(--muted);font-weight:700;font-size:.88rem;cursor:pointer;font-family:var(--font)}
.vack.on{border-style:solid;border-color:#10b981;background:rgba(16,185,129,.08);color:#34d399}
.vnav{display:flex;gap:.6rem;margin-top:1.5rem;position:sticky;bottom:0;background:linear-gradient(180deg,transparent,var(--bg) 35%);padding:.8rem 0 1rem}
.vrecap{margin-bottom:1rem}
.vrecap-row{display:flex;gap:.6rem;margin-bottom:.8rem}
.vrecap-chip{flex:1;background:var(--glass);border:1px solid var(--border);border-radius:12px;padding:.7rem;text-align:center}
.vrecap-chip b{font-size:1.4rem;font-weight:800;display:block}
.vrecap-chip span{font-size:.66rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.vrecap-chip.ok b{color:#34d399}
.vrecap-chip.no b{color:#f87171}
.vrecap-list{display:flex;flex-direction:column;gap:.4rem}
.vrecap-item{display:flex;align-items:center;justify-content:space-between;gap:.5rem;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.18);border-radius:9px;padding:.5rem .7rem;font-size:.85rem;color:#fca5a5}
.vrecap-ok{text-align:center;color:#34d399;font-weight:600;padding:1rem;background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.2);border-radius:10px}
.tag-bio{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#bcd4ff;background:rgba(59,130,246,.14);border:1px solid rgba(59,130,246,.35);padding:3px 7px;border-radius:6px}
.vraison{display:grid;grid-template-columns:1fr 1fr;gap:.4rem;margin-bottom:.5rem}
.vraison button{padding:.55rem;border-radius:9px;border:1px solid var(--border);background:var(--glass);color:var(--text2);font-weight:700;font-size:.8rem;cursor:pointer;font-family:var(--font);transition:.15s}
.vraison button.sel{border-color:#ef4444;background:rgba(239,68,68,.12);color:#fca5a5}
.vrfield{margin-bottom:.5rem}
.vigil{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.28);border-radius:12px;padding:.8rem .9rem;margin-bottom:1rem}
.vigil-h{font-weight:800;font-size:.9rem;color:#fbbf24;margin-bottom:.5rem;display:flex;align-items:center;gap:.5rem}
.vigil-x{margin-left:auto;color:#fbbf24;text-decoration:none;font-size:1rem;opacity:.7;padding:0 .2rem;flex-shrink:0}
.vigil-x:hover{opacity:1}
.vigil-off{margin-bottom:1rem}
.vigil-off a{display:inline-flex;align-items:center;gap:.4rem;font-size:.8rem;color:var(--muted);text-decoration:none;border:1px dashed var(--border);border-radius:9px;padding:.45rem .8rem}
.vigil-off a:hover{color:var(--text);border-color:var(--border2)}
.vigil-row{font-size:.84rem;color:#fcd9a0;padding:.25rem 0;display:flex;align-items:flex-start;gap:.5rem;line-height:1.4}
.vigil-row.grave{color:#fca5a5}
.vigil-dot{width:8px;height:8px;border-radius:50%;background:#f59e0b;flex-shrink:0;margin-top:.4rem}
.vigil-dot.g{background:#ef4444}
.vigil-note{font-size:.72rem;color:var(--muted);margin-top:.5rem;font-style:italic}
</style>

<?php if ($view_fiche): ?>
<?php
$all_reps = array_merge(...array_values($view_sections));
$nb_ok_v  = count(array_filter($all_reps, fn($r) => $r['conforme']));
$nb_nok_v = count(array_filter($all_reps, fn($r) => !$r['conforme']));
$nb_tot_v = $nb_ok_v + $nb_nok_v;
$pct_ok   = $nb_tot_v > 0 ? round($nb_ok_v / $nb_tot_v * 100) : 0;
?>

<!-- ── EN-TÊTE ── -->
<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem;flex-wrap:wrap">
  <a href="fiches.php" style="color:var(--muted);text-decoration:none;font-size:1.3rem">←</a>
  <div style="flex:1;min-width:0">
    <div style="font-weight:800;font-size:1.05rem"><?= h($view_fiche['modele_nom']) ?></div>
    <div style="font-size:.73rem;color:var(--muted)"><?= date('d/m/Y à H:i', strtotime($view_fiche['cree_le'])) ?> · <?= h($view_fiche['prenom'].' '.$view_fiche['user_nom']) ?></div>
  </div>
  <span style="padding:.3rem .9rem;border-radius:99px;font-size:.8rem;font-weight:700;
    background:<?= $view_fiche['statut']==='anomalie'?'rgba(239,68,68,.15)':'rgba(16,185,129,.15)' ?>;
    color:<?= $view_fiche['statut']==='anomalie'?'#f87171':'#34d399' ?>">
    <?= $view_fiche['statut']==='anomalie' ? '⚠️ Anomalie' : '✅ Validée' ?>
  </span>
  <a href="fiche_pdf.php?fiche=<?= $view_id ?>" target="_blank" class="btn btn-primary btn-sm">🖨️ PDF</a>
</div>

<!-- ── STATS ── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:1.5rem">
  <div style="background:var(--glass);border:1px solid var(--border);border-radius:12px;padding:.75rem;text-align:center">
    <div style="font-size:1.5rem;font-weight:800"><?= $nb_tot_v ?></div>
    <div style="font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Points</div>
  </div>
  <div style="background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.2);border-radius:12px;padding:.75rem;text-align:center">
    <div style="font-size:1.5rem;font-weight:800;color:#34d399"><?= $nb_ok_v ?></div>
    <div style="font-size:.68rem;color:#34d399;text-transform:uppercase;letter-spacing:.05em">Conformes</div>
  </div>
  <div style="background:<?= $nb_nok_v>0?'rgba(239,68,68,.06)':'var(--glass)' ?>;border:1px solid <?= $nb_nok_v>0?'rgba(239,68,68,.2)':'var(--border)' ?>;border-radius:12px;padding:.75rem;text-align:center">
    <div style="font-size:1.5rem;font-weight:800;color:<?= $nb_nok_v>0?'#f87171':'var(--muted)' ?>"><?= $nb_nok_v ?></div>
    <div style="font-size:.68rem;color:<?= $nb_nok_v>0?'#f87171':'var(--muted)' ?>;text-transform:uppercase;letter-spacing:.05em">Anomalies</div>
  </div>
  <div style="background:var(--glass);border:1px solid var(--border);border-radius:12px;padding:.75rem;text-align:center">
    <div style="font-size:1.5rem;font-weight:800;color:<?= $pct_ok>=100?'#34d399':($pct_ok>=80?'#fbbf24':'#f87171') ?>"><?= $pct_ok ?>%</div>
    <div style="font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Conformité</div>
  </div>
</div>

<!-- ── GRILLE 3 COLONNES ── -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:1.5rem">
<?php foreach ($view_sections as $sec_titre => $reponses):
  $sec_nok = count(array_filter($reponses, fn($r) => !$r['conforme']));
?>
<div style="background:var(--glass);border:1px solid <?= $sec_nok>0?'rgba(239,68,68,.35)':'var(--border)' ?>;border-radius:12px;overflow:hidden">
  <div style="padding:.5rem .75rem;background:<?= $sec_nok>0?'rgba(239,68,68,.08)':'rgba(var(--primary-rgb),.05)' ?>;border-bottom:1px solid <?= $sec_nok>0?'rgba(239,68,68,.2)':'var(--border)' ?>;display:flex;align-items:center;gap:.4rem">
    <span style="font-size:.72rem;font-weight:800;color:<?= $sec_nok>0?'#f87171':'var(--text)' ?>;flex:1;line-height:1.3"><?= h($sec_titre) ?></span>
    <span style="font-size:.62rem;font-weight:700;padding:1px 6px;border-radius:20px;white-space:nowrap;background:<?= $sec_nok>0?'rgba(239,68,68,.15)':'rgba(16,185,129,.12)' ?>;color:<?= $sec_nok>0?'#f87171':'#34d399' ?>">
      <?= $sec_nok>0 ? $sec_nok.' ✗' : '✓ OK' ?>
    </span>
  </div>
  <div style="padding:.35rem .5rem">
    <?php foreach ($reponses as $r): ?>
    <div style="display:flex;align-items:baseline;gap:.3rem;padding:2px .25rem;border-radius:4px;<?= !$r['conforme']?'background:rgba(239,68,68,.05);margin-bottom:1px;':'' ?>">
      <span style="font-size:.7rem;color:var(--text);flex:1;line-height:1.4">
        <?= $r['bloquant']?'<span style="color:#f87171">●</span> ':'' ?><?= h($r['libelle']) ?>
        <?php if($r['unite']): ?><span style="color:var(--muted)"> (<?= h($r['unite']) ?>)</span><?php endif; ?>
      </span>
      <span style="font-size:.72rem;font-weight:700;white-space:nowrap;color:<?= $r['conforme']?'#34d399':'#f87171' ?>">
        <?php if($r['type']==='oui_non'): ?><?= $r['conforme']?'✓':'✗' ?><?php else: ?><?= h($r['valeur']??'—') ?><?php endif; ?>
      </span>
    </div>
    <?php if(!$r['conforme'] && $r['commentaire']): ?>
    <div style="font-size:.63rem;color:#fbbf24;padding:0 .25rem .2rem .8rem;font-style:italic">→ <?= h($r['commentaire']) ?></div>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php if ($view_fiche['notes'] || $view_fiche['signature']): ?>
<div style="display:flex;gap:12px;margin-bottom:1.5rem;flex-wrap:wrap">
  <?php if ($view_fiche['notes']): ?>
  <div style="flex:1;min-width:200px;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.2);border-radius:12px;padding:.875rem">
    <div style="font-size:.7rem;font-weight:700;color:#fbbf24;margin-bottom:.4rem;text-transform:uppercase">📝 Notes</div>
    <div style="font-size:.85rem;color:var(--text2)"><?= h($view_fiche['notes']) ?></div>
  </div>
  <?php endif; ?>
  <?php if ($view_fiche['signature']): ?>
  <div style="background:var(--glass);border:1px solid var(--border);border-radius:12px;padding:.875rem">
    <div style="font-size:.7rem;font-weight:700;color:var(--muted);margin-bottom:.4rem;text-transform:uppercase">✍️ Signature</div>
    <img src="<?= h($view_fiche['signature']) ?>" style="max-height:80px;display:block">
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div style="display:flex;gap:.75rem">
  <a href="fiches.php" class="btn btn-ghost">← Retour</a>
  <a href="fiche_pdf.php?fiche=<?= $view_id ?>" target="_blank" class="btn btn-primary">🖨️ Imprimer / PDF</a>
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
<?php
/* Helpers de rendu du flux guidé hybride */
if (!function_exists('vf_nokblock')) {
  function vf_nokblock($pt){ $id=$pt['id']; ob_start(); ?>
    <div id="nok-<?= $id ?>" class="vnok" style="display:none">
      <?php if ($pt['type']==='oui_non'): ?>
      <div class="vraison" id="raison-<?= $id ?>">
        <button type="button" data-r="quantite" onclick="setRaison(<?= $id ?>,'quantite',this)">🔢 Quantité</button>
        <button type="button" data-r="perime" onclick="setRaison(<?= $id ?>,'perime',this)">⏱️ Périmé</button>
        <button type="button" data-r="manquant" onclick="setRaison(<?= $id ?>,'manquant',this)">📦 Manquant</button>
        <button type="button" data-r="abime" onclick="setRaison(<?= $id ?>,'abime',this)">🩹 Abîmé / HS</button>
      </div>
      <input type="hidden" name="raison[<?= $id ?>]" id="rsn-<?= $id ?>">
      <div class="vrfield" id="rfq-<?= $id ?>" style="display:none"><input type="number" name="raison_qty[<?= $id ?>]" id="rq-<?= $id ?>" min="0" placeholder="Quantité présente (ex. 1)" class="form-control"></div>
      <div class="vrfield" id="rfd-<?= $id ?>" style="display:none"><input type="month" name="raison_date[<?= $id ?>]" id="rd-<?= $id ?>" class="form-control"></div>
      <?php endif; ?>
      <textarea name="comment[<?= $id ?>]" class="form-control" rows="2" placeholder="Précision (optionnel)..."></textarea>
      <?php if (!empty($pt['peut_signaler_biomed'])): ?>
      <label class="vbio"><input type="checkbox" name="biomed[<?= $id ?>]" value="1" onchange="toggleBioPrio(<?= $id ?>)"> 🔧 Signaler au biomédical</label>
      <div id="bioprio-<?= $id ?>" class="vprio" style="display:none">
        <input type="hidden" name="biomed_prio[<?= $id ?>]" id="prio-<?= $id ?>" value="normal">
        <button type="button" class="on" id="prioN-<?= $id ?>" onclick="setPrio(<?= $id ?>,'normal')">Normal</button>
        <button type="button" id="prioU-<?= $id ?>" onclick="setPrio(<?= $id ?>,'urgent')">🚨 Urgent</button>
      </div>
      <?php endif; ?>
    </div>
  <?php return ob_get_clean(); }

  function vf_point($pt,$isCrit,$prev_reponses){
    $id=$pt['id']; $prev=$prev_reponses[$id]??null; $prev_nok=$prev && $prev['conforme']==0;
    $badge=$isCrit?'<span class="vbadge vbadge-crit">Critique</span>':($pt['type']==='numerique'?'<span class="vbadge vbadge-meas">Mesure</span>':'');
    ob_start(); ?>
    <div class="fiche-point <?= $prev_nok?'nok':'' ?>" id="pt-<?= $id ?>" <?= $isCrit?'data-crit="1"':'' ?>>
      <div class="vpt-top">
        <div class="vpt-name"><?= h($pt['libelle']) ?><?php if($pt['unite']): ?><span class="vpt-unit"> — <?= h($pt['unite']) ?></span><?php endif; ?><?php if($pt['obligatoire']): ?><span style="color:#ef4444"> *</span><?php endif; ?></div>
        <?= $badge ?>
      </div>
      <?php if ($prev_nok): ?><div class="vprev">⚠️ NOK au dernier contrôle <?= $prev['commentaire']?'— '.h($prev['commentaire']):'' ?></div><?php endif; ?>
      <?php if ($pt['type']==='oui_non'): ?>
        <input type="hidden" name="reponse[<?= $id ?>]" value="oui" id="rep-<?= $id ?>">
        <input type="hidden" name="conforme[<?= $id ?>]" value="1" id="conf-<?= $id ?>">
        <div class="btn-yn">
          <button type="button" class="ok" id="bok-<?= $id ?>" onclick="yn(<?= $id ?>,true)">✅ Conforme</button>
          <button type="button" id="bnok-<?= $id ?>" onclick="yn(<?= $id ?>,false)">❌ Problème</button>
        </div>
      <?php elseif ($pt['type']==='numerique'): ?>
        <input type="hidden" name="conforme[<?= $id ?>]" value="1" id="conf-<?= $id ?>">
        <div class="vnum">
          <button type="button" class="vstep-btn" onclick="stepNum(<?= $id ?>,-1)">−</button>
          <input type="number" name="reponse[<?= $id ?>]" id="rep-<?= $id ?>" class="form-control vnum-in" placeholder="—" step="1" min="0" oninput="checkNum(<?= $id ?>,<?= $pt['valeur_min']??'null' ?>,<?= $pt['valeur_max']??'null' ?>)">
          <button type="button" class="vstep-btn" onclick="stepNum(<?= $id ?>,1)">+</button>
          <?php if($pt['unite']): ?><span class="vref"><?= h($pt['unite']) ?></span><?php endif; ?>
          <?php if($pt['valeur_min']!==null&&$pt['valeur_max']!==null): ?><span class="vref">réf <?= $pt['valeur_min'] ?>–<?= $pt['valeur_max'] ?></span><?php elseif($pt['valeur_min']!==null): ?><span class="vref">réf ≥ <?= $pt['valeur_min'] ?></span><?php endif; ?>
        </div>
      <?php elseif ($pt['type']==='texte'): ?>
        <input type="hidden" name="conforme[<?= $id ?>]" value="1" id="conf-<?= $id ?>">
        <textarea name="reponse[<?= $id ?>]" class="form-control" rows="2" placeholder="Observation..." <?= $isCrit?'oninput="markAnswered('.$id.')"':'' ?>></textarea>
      <?php else: ?>
        <input type="hidden" name="conforme[<?= $id ?>]" value="1" id="conf-<?= $id ?>">
        <input type="file" name="reponse_photo_<?= $id ?>" class="form-control" accept="image/*" capture="environment" <?= $isCrit?'onchange="markAnswered('.$id.')"':'' ?>>
      <?php endif; ?>
      <?= vf_nokblock($pt) ?>
    </div>
  <?php return ob_get_clean(); }

  function vf_presumed($pt){ $id=$pt['id']; ob_start(); ?>
    <div class="vnorm-wrap" id="pt-<?= $id ?>">
      <div class="vnorm">
        <input type="hidden" name="reponse[<?= $id ?>]" value="oui" id="rep-<?= $id ?>">
        <input type="hidden" name="conforme[<?= $id ?>]" value="1" id="conf-<?= $id ?>">
        <div class="vtick" id="tick-<?= $id ?>">✓</div>
        <div class="vnm"><?= h($pt['libelle']) ?></div>
        <button type="button" class="vflag" id="flag-<?= $id ?>" onclick="vSignal(<?= $id ?>)">Signaler</button>
      </div>
      <?= vf_nokblock($pt) ?>
    </div>
  <?php return ob_get_clean(); }
}
$nbSec=count($sections);
?>
<div class="vf-head">
  <a href="fiches.php" class="vf-back">←</a>
  <div style="flex:1;min-width:0">
    <div class="vf-title"><?= h($modele['nom']) ?></div>
    <div class="vf-sub"><?= date('d/m/Y') ?> · <?= h(trim(($_SESSION['user_prenom']??'').' '.($_SESSION['user_nom']??''))) ?></div>
  </div>
  <a href="fiches.php?modele=<?= $modele_id ?>&mode=<?= $mode==='express'?'complet':'express' ?>" class="btn btn-ghost btn-sm"><?= $mode==='express'?'📋 Complet':'⚡ Express' ?></a>
</div>

<div class="vf-progress">
  <div class="vf-progress-row"><span id="prog-txt">Section 1/<?= $nbSec ?></span><span id="prog-pct" style="margin-left:auto"></span></div>
  <div class="prog-bar"><div class="prog-fill" id="prog" style="width:0%"></div></div>
</div>

<?php if ($mode==='express'): ?><div class="flash flash-info" style="margin-bottom:1rem">⚡ Mode express — points critiques uniquement</div><?php endif; ?>

<?php
/* #2 — Vigilance : ce que Cadurix sait déjà (péremptions + rappels ANSM) */
if (isset($_GET['vigil'])) { $_SESSION['vigil_off'] = ($_GET['vigil']==='off') ? 1 : 0; }
$vigil_off  = !empty($_SESSION['vigil_off']);
$vigil_base = 'fiches.php?modele='.$modele_id.'&mode='.urlencode($mode);
?>
<?php if ($vigil_off): ?>
<div class="vigil-off"><a href="<?= h($vigil_base) ?>&vigil=on">🗄️ Afficher la vigilance péremptions</a></div>
<?php else:
  $vigil_exp=[]; $vigil_rap=[];
  try {
    $qx=$pdo->prepare("SELECT p.nom,p.numero_lot,p.date_peremption,DATEDIFF(p.date_peremption,CURDATE()) AS jours
       FROM produits p JOIN emplacements e ON e.id=p.emplacement_id
       WHERE e.site_id=? AND p.statut='actif'
         AND p.date_peremption <= DATE_ADD(CURDATE(), INTERVAL COALESCE(p.alerte_jours,30) DAY)
       ORDER BY p.date_peremption ASC LIMIT 15");
    $qx->execute([$site_id]); $vigil_exp=$qx->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $vigil_exp=[]; }
  try {
    $qr=$pdo->prepare("SELECT numero_lot,motif,date_rappel FROM lots_rappels
       WHERE site_id=? AND actif=1 AND source_table='produits' ORDER BY date_rappel DESC LIMIT 15");
    $qr->execute([$site_id]); $vigil_rap=$qr->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $vigil_rap=[]; }
  $vigil_n=count($vigil_exp)+count($vigil_rap);
?>
  <?php if ($vigil_n>0): ?>
  <div class="vigil">
    <div class="vigil-h"><span>🗄️ Cadurix a repéré <?= $vigil_n ?> point<?= $vigil_n>1?'s':'' ?> de vigilance sur ce site</span><a class="vigil-x" href="<?= h($vigil_base) ?>&vigil=off" title="Masquer cette option">✕</a></div>
    <?php foreach ($vigil_rap as $r): ?>
      <div class="vigil-row grave"><span class="vigil-dot g"></span><span>🚨 <b>Rappel ANSM</b> — lot <?= h($r['numero_lot']) ?><?= $r['motif']?' : '.h($r['motif']):'' ?></span></div>
    <?php endforeach; ?>
    <?php foreach ($vigil_exp as $pp):
        $j=(int)$pp['jours'];
        $lbl = $j<0 ? 'périmé depuis '.abs($j).' j' : ($j===0?'périme aujourd\'hui':'périme dans '.$j.' j');
        $grave = $j<=7;
    ?>
      <div class="vigil-row <?= $grave?'grave':'' ?>"><span class="vigil-dot <?= $grave?'g':'' ?>"></span><span><b><?= h($pp['nom']) ?></b><?= $pp['numero_lot']?' (lot '.h($pp['numero_lot']).')':'' ?> — <?= $lbl ?></span></div>
    <?php endforeach; ?>
    <div class="vigil-note">Remonté du stock — pense à vérifier / retirer ces produits pendant le contrôle.</div>
  </div>
  <?php endif; ?>
<?php endif; ?>

<form method="POST" id="vform">
  <?= csrf_field() ?>
  <input type="hidden" name="valider_fiche" value="1">
  <input type="hidden" name="modele_id" value="<?= $modele_id ?>">
  <input type="hidden" name="mode" value="<?= $mode ?>">
  <input type="hidden" name="signature" id="sig-data">

  <?php
  $CRIT=[]; $PRES=[]; $LABELS=[];
  foreach ($sections as $sx=>$sec):
    $crit=[];$meas=[];$pres=[];
    foreach($sec['points'] as $pt){
      $LABELS[$pt['id']]=$pt['libelle'];
      if($pt['bloquant']){ $crit[]=$pt; $CRIT[$sx][]=(int)$pt['id']; }
      elseif($pt['type']==='oui_non'){ $pres[]=$pt; $PRES[$sx][]=(int)$pt['id']; }
      else { $meas[]=$pt; }
    }
  ?>
  <section class="vstep" data-step="<?= $sx ?>" style="<?= $sx===0?'':'display:none' ?>">
    <div class="vstep-head">
      <div class="vstep-eyebrow"><?= sprintf('%02d',$sx+1) ?> / <?= sprintf('%02d',$nbSec) ?></div>
      <div class="vstep-title"><?= h($sec['titre']) ?></div>
    </div>
    <?php if($crit): ?><div class="vblock-label">🔶 À confirmer (critique)</div>
      <?php foreach($crit as $pt) echo vf_point($pt,true,$prev_reponses); ?>
    <?php endif; ?>
    <?php if($meas): ?><div class="vblock-label">📊 Mesures &amp; relevés</div>
      <?php foreach($meas as $pt) echo vf_point($pt,false,$prev_reponses); ?>
    <?php endif; ?>
    <?php if($pres): ?><div class="vblock-label">✓ Présumés conformes — signalez l'exception</div>
      <div class="vpres-card"><?php foreach($pres as $pt) echo vf_presumed($pt); ?></div>
      <button type="button" class="vack" data-sec="<?= $sx ?>" onclick="toggleAck(<?= $sx ?>)">☐ J'ai vérifié ces <?= count($pres) ?> point<?= count($pres)>1?'s':'' ?></button>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>

  <section class="vstep" data-step="<?= $nbSec ?>" style="display:none">
    <div class="vstep-head"><div class="vstep-eyebrow">Finaliser</div><div class="vstep-title">Récapitulatif &amp; signature</div></div>
    <div id="vrecap" class="vrecap"></div>
    <div class="vblock-label">📝 Notes</div>
    <textarea name="notes" class="form-control" rows="2" placeholder="Remarques générales..."></textarea>
    <div class="vblock-label">✍️ Signature</div>
    <canvas id="sig-cv" class="sig-cv"></canvas>
    <button type="button" class="btn btn-ghost btn-sm" style="margin-top:.4rem" onclick="clearSig()">🗑️ Effacer</button>
  </section>

  <div class="vnav">
    <button type="button" class="btn btn-ghost" id="vprev" onclick="vGo(-1)" style="display:none">←</button>
    <button type="button" class="btn btn-primary" id="vnext" style="flex:1" onclick="vNext()">Continuer →</button>
    <button type="submit" class="btn btn-primary" id="vsubmit" style="flex:1;display:none" onclick="saveSig()">✅ Valider la fiche</button>
  </div>
</form>

<script>
const CRIT=<?= json_encode($CRIT?:[]) ?>;
const PRES=<?= json_encode($PRES?:[]) ?>;
const LABELS=<?= json_encode($LABELS?:[]) ?>;
const NSTEPS=<?= $nbSec ?>;
</script>

<?php else: ?>
<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">📋 Fiches de vérification</div>
    <div class="page-sub">Choisissez une fiche à compléter</div>
  </div>
</div>

<?php if(empty($modeles)): ?>
<div class="card" style="text-align:center;padding:3rem">
  <div style="font-size:2.5rem;margin-bottom:1rem">📋</div>
  <div style="font-weight:700;margin-bottom:.5rem">Aucune fiche configurée</div>
  <div style="font-size:.85rem;color:var(--text3)">Contactez un administrateur pour configurer les fiches de vérification.</div>
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
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:8px">
    <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted)">
      <?php if (isGestionnaire()): ?>
        📋 Fiches remplies — tous les agents
      <?php else: ?>
        📋 Mes fiches remplies
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:6px">
      <?php
      $nb_anomalies = count(array_filter($fiches_remplies, fn($f) => $f['statut']==='anomalie'));
      $nb_valides   = count(array_filter($fiches_remplies, fn($f) => $f['statut']==='valide'));
      ?>
      <?php if ($nb_valides): ?>
      <span style="font-size:.72rem;font-weight:600;padding:3px 10px;border-radius:20px;background:rgba(16,185,129,.12);color:#34d399;border:1px solid rgba(16,185,129,.2)">✅ <?= $nb_valides ?> validée(s)</span>
      <?php endif; ?>
      <?php if ($nb_anomalies): ?>
      <span style="font-size:.72rem;font-weight:600;padding:3px 10px;border-radius:20px;background:rgba(239,68,68,.12);color:#f87171;border:1px solid rgba(239,68,68,.2)">⚠️ <?= $nb_anomalies ?> anomalie(s)</span>
      <?php endif; ?>
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

// ── Flux guidé hybride ──
if (document.getElementById('vform')) {
  let vstep=0, ack={}, answered=new Set();

  window.markAnswered=function(id){ answered.add(id); };

  window.yn=function(id,isOk){
    const bok=document.getElementById('bok-'+id),bnok=document.getElementById('bnok-'+id);
    if(bok)bok.className=isOk?'ok':''; if(bnok)bnok.className=isOk?'':'nok';
    const pt=document.getElementById('pt-'+id); if(pt){pt.classList.toggle('ok',isOk);pt.classList.toggle('nok',!isOk);}
    document.getElementById('conf-'+id).value=isOk?'1':'0';
    const nz=document.getElementById('nok-'+id); if(nz)nz.style.display=isOk?'none':'block';
    answered.add(id);
  };

  window.vSignal=function(id){
    document.getElementById('conf-'+id).value='0';
    const w=document.getElementById('pt-'+id); if(w)w.classList.add('flagged');
    const t=document.getElementById('tick-'+id); if(t)t.textContent='✕';
    const f=document.getElementById('flag-'+id); if(f){f.textContent='Annuler';f.classList.add('on');f.setAttribute('onclick','vUnsignal('+id+')');}
    const nz=document.getElementById('nok-'+id); if(nz)nz.style.display='block';
  };
  window.vUnsignal=function(id){
    document.getElementById('conf-'+id).value='1';
    const w=document.getElementById('pt-'+id); if(w)w.classList.remove('flagged');
    const t=document.getElementById('tick-'+id); if(t)t.textContent='✓';
    const f=document.getElementById('flag-'+id); if(f){f.textContent='Signaler';f.classList.remove('on');f.setAttribute('onclick','vSignal('+id+')');}
    const nz=document.getElementById('nok-'+id); if(nz)nz.style.display='none';
  };

  window.checkNum=function(id,min,max){
    const el=document.getElementById('rep-'+id); const raw=el.value;
    const pt=document.getElementById('pt-'+id); const nz=document.getElementById('nok-'+id);
    if(raw===''){ document.getElementById('conf-'+id).value='1'; if(nz)nz.style.display='none'; if(pt){pt.classList.remove('nok','ok');} return; }
    const v=parseFloat(raw); let ok=true;
    if(min!==null&&v<min)ok=false; if(max!==null&&v>max)ok=false;
    document.getElementById('conf-'+id).value=ok?'1':'0';
    if(nz)nz.style.display=ok?'none':'block';
    if(pt){pt.classList.toggle('nok',!ok);pt.classList.toggle('ok',ok);}
    answered.add(id);
  };
  window.stepNum=function(id,d){ const inp=document.getElementById('rep-'+id); if(!inp)return; const v=Math.max(0,(parseInt(inp.value)||0)+d); inp.value=v; inp.dispatchEvent(new Event('input')); answered.add(id); };

  window.toggleBioPrio=function(id){ const cb=document.querySelector('input[name="biomed['+id+']"]'); const box=document.getElementById('bioprio-'+id); if(box)box.style.display=(cb&&cb.checked)?'grid':'none'; };
  window.setPrio=function(id,p){ const h=document.getElementById('prio-'+id); if(h)h.value=p; const n=document.getElementById('prioN-'+id),u=document.getElementById('prioU-'+id); if(n)n.classList.toggle('on',p==='normal'); if(u)u.classList.toggle('on',p==='urgent'); };

  window.setRaison=function(id,r,btn){
    const hh=document.getElementById('rsn-'+id); if(hh)hh.value=r;
    document.querySelectorAll('#raison-'+id+' button').forEach(b=>b.classList.remove('sel'));
    if(btn)btn.classList.add('sel');
    const q=document.getElementById('rfq-'+id), d=document.getElementById('rfd-'+id);
    if(q)q.style.display=(r==='quantite')?'block':'none';
    if(d)d.style.display=(r==='perime')?'block':'none';
  };
  window.toggleAck=function(s){ ack[s]=!ack[s]; const b=document.querySelector('.vack[data-sec="'+s+'"]'); if(b){ b.classList.toggle('on',ack[s]); const n=(PRES[s]||[]).length; b.textContent=ack[s]?'✓ Section vérifiée':('☐ J’ai vérifié ces '+n+' point'+(n>1?'s':'')); } };

  function stepOK(s){
    const crit=(CRIT[s]||[]);
    for(const pid of crit){ if(!answered.has(pid) && !answered.has(+pid)) return false; }
    const pres=(PRES[s]||[]);
    if(pres.length){
      const flagged=pres.some(pid=>{const c=document.getElementById('conf-'+pid);return c&&c.value==='0';});
      if(!ack[s]&&!flagged) return false;
    }
    return true;
  }
  window.vNext=function(){ if(vstep<NSTEPS){ if(!stepOK(vstep)){ pulse(); return; } showStep(vstep+1); } };
  window.vGo=function(d){ showStep(vstep+d); };
  function pulse(){ const b=document.getElementById('vnext'); if(!b)return; const o=b.textContent; b.textContent='⚠ Confirmez les points critiques'; setTimeout(()=>{b.textContent=o;},1400); }

  function showStep(n){
    vstep=Math.max(0,Math.min(NSTEPS,n));
    document.querySelectorAll('.vstep').forEach(el=>{ el.style.display=(parseInt(el.dataset.step,10)===vstep)?'block':'none'; });
    document.getElementById('vprev').style.display=vstep>0?'':'none';
    const last=vstep===NSTEPS;
    document.getElementById('vnext').style.display=last?'none':'';
    document.getElementById('vsubmit').style.display=last?'':'none';
    const pct=NSTEPS>0?Math.round(vstep/NSTEPS*100):0;
    const f=document.getElementById('prog'); if(f)f.style.width=pct+'%';
    const t=document.getElementById('prog-txt'); if(t)t.textContent=last?'Récapitulatif':('Section '+(vstep+1)+'/'+NSTEPS);
    const pc=document.getElementById('prog-pct'); if(pc)pc.textContent=pct+'%';
    if(last)buildRecap();
    window.scrollTo({top:0,behavior:'smooth'});
  }

  function buildRecap(){
    let anomalies=[],nonreleve=[];
    Object.keys(LABELS).forEach(pid=>{
      const c=document.getElementById('conf-'+pid);
      if(c&&c.value==='0'){ const bio=document.querySelector('input[name="biomed['+pid+']"]'); anomalies.push({lib:LABELS[pid],bio:bio&&bio.checked}); }
    });
    document.querySelectorAll('input[type=number][id^="rep-"]').forEach(inp=>{ if(inp.value===''){ const pid=inp.id.replace('rep-',''); nonreleve.push(LABELS[pid]||pid); } });
    const tot=Object.keys(LABELS).length;
    let html='<div class="vrecap-row">'
      +'<div class="vrecap-chip ok"><b>'+(tot-anomalies.length)+'</b><span>Conformes</span></div>'
      +'<div class="vrecap-chip '+(anomalies.length?'no':'')+'"><b>'+anomalies.length+'</b><span>Anomalies</span></div>'
      +'<div class="vrecap-chip"><b>'+nonreleve.length+'</b><span>Non relevés</span></div></div>';
    if(anomalies.length){ html+='<div class="vrecap-list">'+anomalies.map(a=>'<div class="vrecap-item"><span>⚠ '+a.lib+'</span>'+(a.bio?'<span class="tag-bio">→ biomed</span>':'')+'</div>').join('')+'</div>'; }
    else { html+='<div class="vrecap-ok">✅ Aucune anomalie — tout est conforme</div>'; }
    const box=document.getElementById('vrecap'); if(box)box.innerHTML=html;
  }

  showStep(0);
}
</script>
<?php require_once 'includes/footer.php'; ?>
