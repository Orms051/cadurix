<?php
// © Cadurix v5.4 — Tous droits réservés.
require_once 'includes/auth.php';
require_once __DIR__ . '/scanner/barcode_lookup.php';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $site_id_ajax = site_actif();
    if (function_exists('app_log')) app_log('DEBUG','[ajax] site_id='.$site_id_ajax.' action='.($_POST['action']??'?'),__FILE__,__LINE__);
    if (($_GET['ajax'] ?? '') === 'search') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { echo '[]'; exit; }
        $stmt = $pdo->prepare("SELECT p.id,p.nom,p.quantite,p.unite,p.date_peremption,p.numero_lot,e.nom AS emplacement,e.id AS emplacement_id FROM produits p LEFT JOIN emplacements e ON e.id=p.emplacement_id WHERE (p.site_id=? OR e.site_id=?) AND p.nom LIKE ? AND p.statut='actif' ORDER BY p.nom LIMIT 10");
        $stmt->execute([$site_id_ajax,$site_id_ajax,"%$q%"]); echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); exit;
    }
    try {
        switch ($_POST['action']) {
            case 'save_intervention':
                if (function_exists('app_log')) app_log('DEBUG','[save_intervention] début',__FILE__,__LINE__,'',['lignes_raw'=>substr($_POST['lignes']??'',0,200)]);
                if (!canEdit()) { echo json_encode(['ok'=>false,'error'=>'Droits insuffisants']); exit; }
                $lignes = json_decode($_POST['lignes'] ?? '[]', true);
                $ref    = trim($_POST['reference'] ?? '');
                $notes  = trim($_POST['notes'] ?? '');
                if (empty($lignes)) { echo json_encode(['ok'=>false,'error'=>'Aucune ligne.']); exit; }
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO interventions_materiel (site_id,user_id,reference,notes,statut) VALUES (?,?,?,?,'en_cours')")->execute([$site_id_ajax,currentUserId(),$ref?:null,$notes?:null]);
                $inter_id = (int)$pdo->lastInsertId();
                $par_empl = [];
                foreach ($lignes as $l) {
                    $nom = trim($l['nom']??''); if(!$nom) continue;
                    $empl = (int)($l['emplacement_id']??0)?:null;
                    $par_empl[$empl??0][] = $l;
                    $pdo->prepare("INSERT INTO interventions_lignes (intervention_id,produit_id,nom,quantite,unite,emplacement_id) VALUES (?,?,?,?,?,?)")->execute([$inter_id,(int)($l['produit_id']??0)?:null,$nom,max(0.1,(float)($l['quantite']??1)),$l['unite']??'unité',$empl]);
                }
                $remp_ids = [];
                foreach ($par_empl as $empl_id => $articles) {
                    $pdo->prepare("INSERT INTO rempotages (emplacement_id,user_id,site_id,statut,notes) VALUES (?,?,?,'en_cours',?)")->execute([$empl_id?:null,currentUserId(),$site_id_ajax,$ref?'Intervention '.$ref:null]);
                    $remp_id = (int)$pdo->lastInsertId(); $remp_ids[] = $remp_id;
                    foreach ($articles as $a) {
                        $nom=trim($a['nom']??''); if(!$nom) continue;
                        $qty=max(0.1,(float)($a['quantite']??1));
                        $prod_id=(int)($a['produit_id']??0)?:null;
                        $lot_ra=$a['lot']??null;
                        $date_ra=$a['date_peremption']??null;
                        $pdo->prepare("INSERT INTO rempotage_articles (rempotage_id,produit_id,action,nom,quantite,unite,numero_lot,date_peremption) VALUES (?,?,'retire',?,?,?,?,?)")->execute([$remp_id,$prod_id,$nom,$qty,$a['unite']??'unité',$lot_ra?:null,$date_ra?:null]);
                        if ($prod_id) {
                            $so=$pdo->prepare("SELECT quantite FROM produits WHERE id=?"); $so->execute([$prod_id]); $oq=(float)$so->fetchColumn(); $nq=max(0,$oq-$qty);
                            $pdo->prepare("UPDATE produits SET quantite=? WHERE id=?")->execute([$nq,$prod_id]);
                            $pdo->prepare("INSERT INTO mouvements_stock (produit_id,user_id,type,quantite_avant,quantite_delta,quantite_apres,motif) VALUES(?,?,'sortie',?,?,?,'Matériel utilisé')")->execute([$prod_id,currentUserId(),$oq,-$qty,$nq]);
                        }
                    }
                }
                $pdo->commit();
                audit_log($pdo,'INTERVENTION_MATERIEL','interventions_materiel',$inter_id);
                echo json_encode(['ok'=>true,'inter_id'=>$inter_id,'remp_ids'=>$remp_ids]);
                break;

            case 'valider_rempotage':
                $inter_id=(int)($_POST['inter_id']??0); $articles=json_decode($_POST['articles']??'[]',true);
                if ($inter_id) {
                    $pdo->prepare("UPDATE interventions_materiel SET statut='termine',modifie_le=NOW() WHERE id=? AND site_id=?")->execute([$inter_id,$site_id_ajax]);
                    foreach ($articles as $a) {
                        $nom_a = trim($a['nom']??''); if(!$nom_a) continue;
                        // Parser la date de péremption
                        $ds=null;
                        if (!empty($a['peremption'])) {
                            if (preg_match('#^(\d{2})/(\d{4})$#',$a['peremption'],$m2)) $ds=$m2[2].'-'.$m2[1].'-01';
                            elseif (preg_match('#^\d{4}-\d{2}-\d{2}$#',$a['peremption'])) $ds=$a['peremption'];
                        }
                        $empl_new = (int)($a['emplacement_id']??0)?:null;
                        $lot_a    = $a['lot']?:null;
                        $qty_a    = (float)($a['quantite_remis']??0);
                        $unite_a  = $a['unite']??'unité';
                        $prod_id  = (int)($a['produit_id']??0)?:null;

                        if ($prod_id) {
                            // Produit existant — mise à jour
                            $pdo->prepare("UPDATE produits SET numero_lot=COALESCE(?,numero_lot),date_peremption=COALESCE(?,date_peremption),quantite=quantite+?,emplacement_id=COALESCE(?,emplacement_id) WHERE id=?")
                                ->execute([$lot_a,$ds,$qty_a,$empl_new,$prod_id]);
                        } else {
                            // Produit inexistant — création automatique dans le stock
                            $pdo->prepare("INSERT INTO produits (nom,quantite,unite,numero_lot,date_peremption,emplacement_id,statut,cree_par,cree_le) VALUES (?,?,?,?,?,?,'actif',?,NOW())")
                                ->execute([$nom_a,$qty_a,$unite_a,$lot_a,$ds,$empl_new,currentUserId()]);
                            $new_prod_id = (int)$pdo->lastInsertId();
                            // Mettre à jour l'intervention_ligne avec le nouveau produit_id
                            $pdo->prepare("UPDATE interventions_lignes SET produit_id=? WHERE intervention_id=? AND nom=?")
                                ->execute([$new_prod_id,$inter_id,$nom_a]);
                        }
                    }
                    echo json_encode(['ok'=>true]);
                } else { echo json_encode(['ok'=>false,'error'=>'ID manquant']); }
                break;

            case 'lookup_barcode':
                $code = trim($_POST['code'] ?? '');
                if (!$code) { echo json_encode(['found'=>false,'code'=>'']); break; }
                $res = barcode_lookup($pdo, $code, (int)$site_id_ajax);
                if ($res['source'] === 'ref') {
                    echo json_encode(['found'=>true,'nom'=>$res['nom'],'lot'=>$res['lot'],'date_exp'=>$res['date_exp']]);
                    break;
                }
                if ($res['source'] === 'stock' && $res['produit']) {
                    $prod = $res['produit'];
                    unset($prod['numero_lot']);
                    echo json_encode(array_merge(['found'=>true], $prod, ['lot'=>$res['lot'],'lot_scan'=>$res['lot'],'date_exp'=>$res['date_exp']]));
                    break;
                }
                echo json_encode(['found'=>false,'code'=>$code,'lot'=>$res['lot'],'date_exp'=>$res['date_exp'],'ean'=>$res['ean']]);
                break;

            default: echo json_encode(['ok'=>false,'error'=>'Action inconnue']);
        }
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (function_exists('app_log')) app_log('ERROR','[terrain.php] '.$e->getMessage(),$e->getFile(),$e->getLine(),$e->getTraceAsString(),['action'=>$_POST['action']??'?']);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'line'=>$e->getLine(),'file'=>basename($e->getFile())]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax']==='search') {
    header('Content-Type: application/json'); $site_id_ajax=site_actif(); $q=trim($_GET['q']??'');
    if(strlen($q)<2){echo'[]';exit;}
    $stmt=$pdo->prepare("SELECT p.id,p.nom,p.quantite,p.unite,p.date_peremption,p.numero_lot,e.nom AS emplacement,e.id AS emplacement_id FROM produits p LEFT JOIN emplacements e ON e.id=p.emplacement_id WHERE (p.site_id=? OR e.site_id=?) AND p.nom LIKE ? AND p.statut='actif' ORDER BY p.nom LIMIT 10");
    $stmt->execute([$site_id_ajax,$site_id_ajax,"%$q%"]); echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); exit;
}

requireCanEdit();
$pageTitle = 'Terrain';
$site_id   = site_actif();
if (!$site_id) { header('Location: dashboard.php'); exit; }

$historique = [];
try {
    if (isAdmin()) {
        $histo=$pdo->prepare("SELECT im.*,CONCAT(u.prenom,' ',u.nom) AS user_nom,COUNT(il.id) AS nb_lignes FROM interventions_materiel im JOIN utilisateurs u ON u.id=im.user_id LEFT JOIN interventions_lignes il ON il.intervention_id=im.id WHERE im.site_id=? GROUP BY im.id ORDER BY im.cree_le DESC LIMIT 30");
        $histo->execute([$site_id]);
    } else {
        $histo=$pdo->prepare("SELECT im.*,CONCAT(u.prenom,' ',u.nom) AS user_nom,COUNT(il.id) AS nb_lignes FROM interventions_materiel im JOIN utilisateurs u ON u.id=im.user_id LEFT JOIN interventions_lignes il ON il.intervention_id=im.id WHERE im.site_id=? AND im.user_id=? GROUP BY im.id ORDER BY im.cree_le DESC LIMIT 30");
        $histo->execute([$site_id,currentUserId()]);
    }
    $historique=$histo->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

$empls = [];
try {
    $stmt=$pdo->prepare("SELECT e.id,e.nom,ep.nom AS parent FROM emplacements e LEFT JOIN emplacements ep ON ep.id=e.parent_id WHERE e.site_id=? AND e.actif=1 ORDER BY COALESCE(ep.nom,e.nom),e.nom");
    $stmt->execute([$site_id]); $empls=$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

require_once 'includes/header.php';
?>
<style>
/* ══ TERRAIN — Mise en page PC + Mobile ══════════════════ */

/* ── Layout PC 2 colonnes ── */
.terrain-wrap {
  display: grid;
  grid-template-columns: 1fr 300px;
  gap: 20px;
  align-items: start;
}

/* ── États tabs ── */
.state  { display:none; }
.state.active { display:block; }
.t-screen { display:none; }
.t-screen.active { display:block; }

/* ── Tabs navigation ── */
.t-tabs {
  display: flex;
  gap: 6px;
  margin-bottom: 20px;
}
.t-tab {
  padding: .5rem 1.1rem;
  border-radius: 10px;
  font-size: .875rem;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid var(--border);
  background: var(--glass);
  color: var(--text2);
  transition: all .15s;
}
.t-tab.active { background:var(--primary); border-color:var(--primary); color:#fff; }
.t-tab .tab-badge {
  display: inline-block;
  background: rgba(255,255,255,.25);
  border-radius: 20px;
  padding: 1px 7px;
  font-size: .7rem;
  margin-left: 5px;
}

/* ══ SAISIE CARD ══════════════════════════════════════════ */
.saisie-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 2px 16px rgba(59,130,246,.06);
}

/* Header ref + notes */
.saisie-header {
  display: flex;
  gap: 10px;
  padding: 16px 20px;
  background: var(--bg3);
  border-bottom: 1px solid var(--border);
  align-items: center;
}
.saisie-input {
  flex: 1;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 9px;
  padding: .6rem .9rem;
  color: var(--text);
  font-size: .875rem;
  outline: none;
  font-family: inherit;
  transition: border-color .15s, box-shadow .15s;
}
.saisie-input:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(59,130,246,.1);
}

/* ══ LIGNES ARTICLES — Vraie grille tableau ════════════ */

/* Colonnes identiques header + lignes : 5 colonnes fixes */
.ligne-header-row,
.ligne-row {
  display: grid;
  grid-template-columns: 1fr 80px 120px 200px 36px;
  align-items: center;
}

/* ── En-tête ── */
.ligne-header-row {
  background: var(--bg3);
  border-top: 1px solid var(--border);
  border-bottom: 2px solid var(--border2);
}
.col-header {
  padding: 9px 12px;
  font-size: .63rem;
  text-transform: uppercase;
  letter-spacing: 1.2px;
  color: var(--text3);
  font-weight: 800;
  border-right: 1px solid var(--border);
}
.col-header:last-child { border-right: none; }

/* ── Lignes ── */
#lignes-wrap { padding: 0; display: flex; flex-direction: column; }

.ligne-row {
  border-bottom: 1px solid var(--border);
  background: var(--bg2);
  transition: background .1s;
  min-height: 48px;
}
.ligne-row:hover { background: rgba(59,130,246,.04); }
.ligne-row:last-child { border-bottom: none; }

/* ── Cellules ── */
.ligne-nom-wrap  { padding: 6px 10px; border-right: 1px solid var(--border); display:flex; align-items:center; }
.ligne-qte-wrap   { padding: 6px 8px; border-right: 1px solid var(--border); display:flex; align-items:center; }
.ligne-unite-wrap { padding: 6px 8px; border-right: 1px solid var(--border); display:flex; align-items:center; }
.ligne-empl-wrap { padding: 6px 8px;  border-right: 1px solid var(--border); display:flex; align-items:center; }
.ligne-del       { display:flex; align-items:center; justify-content:center; padding:4px; }

/* ── Inputs ── */
.td-input {
  width: 100%;
  padding: .4rem .6rem;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 6px;
  color: var(--text);
  font-size: .84rem;
  outline: none;
  font-family: inherit;
  transition: all .12s;
}
.td-input:hover  { border-color: var(--border); background: var(--bg3); }
.td-input:focus  { border-color: var(--primary); background: var(--bg2); box-shadow: 0 0 0 2px rgba(59,130,246,.12); }

/* ── Bouton supprimer ── */
.ligne-del button {
  width: 26px; height: 26px;
  display: flex; align-items: center; justify-content: center;
  background: none; border: none; border-radius: 6px;
  color: var(--text3); cursor: pointer; font-size: .9rem;
  transition: background .12s, color .12s;
}
.ligne-del button:hover { background: rgba(239,68,68,.1); color: #f87171; }

/* ── Bouton ajouter ligne ── */
.btn-add-ligne {
  margin: 8px 16px;
  width: calc(100% - 32px);
  border-style: dashed;
}

<style>
/* ══ TERRAIN — Mise en page PC + Mobile ══════════════════ */

/* ── Layout PC 2 colonnes ── */
.terrain-wrap {
  display: grid;
  grid-template-columns: 1fr 300px;
  gap: 20px;
  align-items: start;
}

/* ── États tabs ── */
.state  { display:none; }
.state.active { display:block; }
.t-screen { display:none; }
.t-screen.active { display:block; }

/* ── Tabs navigation ── */
.t-tabs {
  display: flex;
  gap: 6px;
  margin-bottom: 20px;
}
.t-tab {
  padding: .5rem 1.1rem;
  border-radius: 10px;
  font-size: .875rem;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid var(--border);
  background: var(--glass);
  color: var(--text2);
  transition: all .15s;
}
.t-tab.active { background:var(--primary); border-color:var(--primary); color:#fff; }
.t-tab .tab-badge {
  display: inline-block;
  background: rgba(255,255,255,.25);
  border-radius: 20px;
  padding: 1px 7px;
  font-size: .7rem;
  margin-left: 5px;
}

/* ══ SAISIE CARD ══════════════════════════════════════════ */
.saisie-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 2px 16px rgba(59,130,246,.06);
}

/* Header ref + notes */
.saisie-header {
  display: flex;
  gap: 10px;
  padding: 16px 20px;
  background: var(--bg3);
  border-bottom: 1px solid var(--border);
  align-items: center;
}
.saisie-input {
  flex: 1;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 9px;
  padding: .6rem .9rem;
  color: var(--text);
  font-size: .875rem;
  outline: none;
  font-family: inherit;
  transition: border-color .15s, box-shadow .15s;
}
.saisie-input:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(59,130,246,.1);
}

/* ══ LIGNES ARTICLES ══════════════════════════════════════ */

/* Définir les largeurs fixes des colonnes — partagées entre header et lignes */
:root {
  --col-nom:   1fr;
  --col-qte:   80px;
  --col-unite: 110px;
  --col-empl:  200px;
  --col-del:   40px;
  --col-gap:   12px;
}

/* Table-like : header + lignes alignées */
.ligne-table {
  width: 100%;
}

/* En-tête colonnes */
.ligne-header-row {
  display: grid;
  grid-template-columns: var(--col-nom) var(--col-qte) var(--col-unite) var(--col-empl) var(--col-del);
  gap: var(--col-gap);
  padding: 8px 16px;
  background: var(--bg3);
  border-bottom: 2px solid var(--border);
  border-top: 1px solid var(--border);
  margin-bottom: 0;
}
.col-header {
  font-size: .62rem;
  text-transform: uppercase;
  letter-spacing: 1.3px;
  color: var(--text3);
  font-weight: 800;
  white-space: nowrap;
}

/* Wrapper des lignes */
#lignes-wrap {
  padding: 6px 0;
  display: flex;
  flex-direction: column;
  gap: 0;
}

/* Chaque ligne */
.ligne-row {
  display: grid;
  grid-template-columns: var(--col-nom) var(--col-qte) var(--col-unite) var(--col-empl) var(--col-del);
  gap: var(--col-gap);
  align-items: center;
  padding: 7px 16px;
  border-bottom: 1px solid var(--border);
  transition: background .12s;
}
.ligne-row:hover { background: var(--primary-bg); }
.ligne-row:last-child { border-bottom: none; }

.ligne-nom-wrap  { min-width: 0; position: relative; }
.ligne-qte-wrap  { min-width: 0; }
.ligne-empl-wrap { min-width: 0; }
.ligne-del       { display: flex; align-items: center; justify-content: center; }

/* Inputs */
.td-input {
  width: 100%;
  padding: .45rem .65rem;
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: 7px;
  color: var(--text);
  font-size: .83rem;
  outline: none;
  font-family: inherit;
  transition: border-color .15s, box-shadow .15s;
}
.td-input:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 2px rgba(59,130,246,.1);
  background: var(--bg2);
}

/* Bouton supprimer */
.ligne-del button {
  width: 26px;
  height: 26px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: none;
  border: none;
  border-radius: 6px;
  color: var(--text3);
  cursor: pointer;
  font-size: .9rem;
  transition: background .12s, color .12s;
}
.ligne-del button:hover {
  background: rgba(239,68,68,.12);
  color: #f87171;
}

/* Bouton ajouter ligne */
.btn-ajouter-ligne {
  margin: 8px 16px;
  width: calc(100% - 32px);
}

/* Footer saisie */
.saisie-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 20px;
  background: var(--bg3);
  border-top: 1px solid var(--border);
}

/* ══ SIDEBAR HISTORIQUE ═══════════════════════════════════ */
.terrain-sidebar {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 2px 16px rgba(59,130,246,.06);
  position: sticky;
  top: 80px;
}
.terrain-sidebar-head {
  padding: 12px 16px;
  background: var(--bg3);
  border-bottom: 1px solid var(--border);
  font-size: .65rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 1.2px;
  color: var(--text3);
}
.histo-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-bottom: 1px solid var(--border);
  cursor: pointer;
  transition: background .12s;
  color: var(--text);
}
.histo-item:last-child { border-bottom: none; }
.histo-item:hover { background: var(--primary-bg); }
.histo-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.dot-en_cours { background: #f59e0b; }
.dot-termine  { background: #10b981; }
.dot-annule   { background: #6b7280; }

/* ══ REMPOTAGE ═══════════════════════════════════════════ */
.remp-article {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 12px;
  margin-bottom: 8px;
  transition: all .2s;
}
.remp-article.done {
  opacity: .5;
  background: rgba(16,185,129,.04);
  border-color: rgba(16,185,129,.2);
}
.remp-article-check { width:22px; height:22px; accent-color:var(--primary); flex-shrink:0; cursor:pointer; }
.remp-article-info  { flex:1; min-width:0; }
.remp-article-nom   { font-size:.9rem; font-weight:600; color:var(--text); }
.remp-article-sub   { font-size:.72rem; color:var(--text3); margin-top:2px; }
.remp-scan-btn {
  padding: .4rem .8rem;
  border-radius: 8px;
  font-size: .8rem;
  font-weight: 600;
  border: 1px solid var(--border);
  background: var(--glass);
  color: var(--primary);
  cursor: pointer;
  transition: all .15s;
  white-space: nowrap;
  flex-shrink: 0;
}
.remp-scan-btn:hover { background:var(--primary); color:#fff; border-color:var(--primary); }

/* ══ SCANNER OVERLAY — NE PAS MODIFIER ══════════════════ */
.scan-overlay{display:none;position:fixed;inset:0;z-index:9999;background:#000;flex-direction:column}
.scan-overlay.open{display:flex}
.scan-ui{position:absolute;inset:0;display:flex;flex-direction:column}
.scan-top{display:flex;align-items:center;justify-content:space-between;padding:env(safe-area-inset-top,0px) 16px 12px;padding-top:calc(env(safe-area-inset-top,0px) + 14px);background:linear-gradient(180deg,rgba(0,0,0,.8) 0%,transparent 100%);z-index:2}
.scan-title{color:#fff;font-weight:700;font-size:.95rem}
.scan-close{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);border-radius:99px;color:#fff;padding:6px 14px;font-size:.8rem;cursor:pointer;font-family:var(--font);-webkit-tap-highlight-color:transparent}
.scan-frame-wrap{flex:1;position:relative;overflow:hidden}
#scan-video{width:100%;height:100%;object-fit:cover;display:block}
.scan-overlay canvas{display:none!important}
body>canvas{display:none!important}
.scan-frame{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:72vw;max-width:300px;aspect-ratio:1;border:2px solid var(--primary);border-radius:12px;box-shadow:0 0 0 9999px rgba(0,0,0,.55)}
.scan-corner{position:absolute;width:22px;height:22px;border-color:var(--primary);border-style:solid;border-radius:3px}
.scan-corner.sc-tl{top:-2px;left:-2px;border-width:3px 0 0 3px}
.scan-corner.sc-tr{top:-2px;right:-2px;border-width:3px 3px 0 0}
.scan-corner.sc-bl{bottom:-2px;left:-2px;border-width:0 0 3px 3px}
.scan-corner.sc-br{bottom:-2px;right:-2px;border-width:0 3px 3px 0}
.scan-line{position:absolute;left:5%;right:5%;height:2px;background:linear-gradient(90deg,transparent,var(--primary),transparent);animation:scanline 2s ease-in-out infinite}
@keyframes scanline{0%,100%{top:5%;opacity:0}15%{opacity:1}85%{opacity:1}to{top:95%}}
.scan-bottom{padding:12px 16px;padding-bottom:calc(env(safe-area-inset-bottom,0px) + 12px);background:linear-gradient(0deg,rgba(0,0,0,.8) 0%,transparent 100%);z-index:2}
.scan-cam-btn{padding:7px 16px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:99px;color:#fff;font-size:.75rem;cursor:pointer;font-family:var(--font);-webkit-tap-highlight-color:transparent}
.scan-sheet{position:absolute;bottom:0;left:0;right:0;background:var(--bg2);border-radius:20px 20px 0 0;padding:16px 20px;padding-bottom:calc(80px + env(safe-area-inset-bottom,0px));transform:translateY(100%);transition:transform .3s cubic-bezier(.4,0,.2,1);pointer-events:all;z-index:2}
.scan-sheet.show{transform:translateY(0)}
.scan-sheet-handle{width:36px;height:4px;background:var(--border);border-radius:2px;margin:0 auto 16px}
.scan-sheet-nom{font-weight:700;font-size:.95rem;color:var(--text);margin-bottom:12px}
.scan-sheet-pills{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px}
.sheet-pill{padding:4px 10px;border-radius:99px;font-size:.75rem;font-weight:600}
.sp-lot{background:rgba(139,92,246,.12);color:#a78bfa}
.sp-date{background:rgba(16,185,129,.12);color:#34d399}
.sp-empl{background:rgba(14,165,233,.12);color:#38bdf8}
.scan-sheet-btn{width:100%;padding:14px;background:var(--primary);color:#fff;border:none;border-radius:12px;font-size:.9rem;font-weight:700;cursor:pointer;font-family:var(--font);-webkit-tap-highlight-color:transparent}
.scan-sheet-btn:active{opacity:.85}
.scan-sheet-btn.secondary{background:var(--bg3);color:var(--text);border:1px solid var(--border);margin-top:8px}

/* ══ MOBILE ≤768px ══════════════════════════════════════ */
@media(max-width:768px) {
  .terrain-wrap { grid-template-columns:1fr; }
  .terrain-sidebar { display:none; }
  .ligne-header-row { display:none !important; }
  .saisie-footer { flex-direction:column !important; align-items:stretch !important; gap:8px !important; padding:12px !important; }
  .saisie-footer .btn { width:100% !important; min-height:48px !important; justify-content:center !important; }
  .saisie-header { flex-wrap:wrap; padding:12px !important; }
  .saisie-input  { font-size:16px !important; min-height:44px; }

  /* ── Cartes article mobile ── */
  .m-article-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 14px;
    margin: 0 0 10px 0;
    position: relative;
  }
  .m-article-card:last-child { margin-bottom: 0; }
  .m-article-nom {
    font-size: 15px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 8px;
    padding-right: 32px;
  }
  .m-article-nom input {
    width: 100%;
    background: none;
    border: none;
    border-bottom: 1px solid var(--border);
    color: var(--text);
    font-size: 15px;
    font-weight: 700;
    font-family: var(--font);
    padding: 2px 0 6px;
    outline: none;
  }
  .m-article-nom input:focus { border-bottom-color: var(--primary); }
  .m-article-row {
    display: flex;
    gap: 8px;
    margin-top: 8px;
    align-items: flex-end;
    flex-wrap: wrap;
  }
  .m-article-row label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--text3);
    display: block;
    margin-bottom: 3px;
  }
  .m-article-field {
    flex: 1;
    min-width: 80px;
  }
  .m-article-field input,
  .m-article-field select {
    width: 100%;
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-size: 14px;
    font-family: var(--font);
    padding: 8px 10px;
    min-height: 40px;
    outline: none;
  }
  .m-article-field input:focus,
  .m-article-field select:focus { border-color: var(--primary); }
  .m-article-pills {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 8px;
  }
  .m-pill {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 20px;
    background: var(--bg3);
    border: 1px solid var(--border);
    color: var(--text2);
  }
  .m-pill-lot  { background: rgba(139,92,246,.12); color:#a78bfa; border-color:rgba(139,92,246,.2); }
  .m-pill-date { background: rgba(16,185,129,.12);  color:#34d399; border-color:rgba(16,185,129,.2); }
  .m-pill-empl { background: rgba(59,130,246,.12);  color:#60a5fa; border-color:rgba(59,130,246,.2); }
  .m-article-del {
    position: absolute;
    top: 10px; right: 10px;
    width: 28px; height: 28px;
    border-radius: 50%;
    background: rgba(239,68,68,.1);
    border: none;
    color: #f87171;
    font-size: 14px;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
  }
  .m-article-del:hover { background: rgba(239,68,68,.25); }
}
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">🚑 Terrain</div>
    <div class="page-sub">Matériel utilisé &amp; Rempotage en une seule page</div>
  </div>
  <div style="display:flex;gap:8px;align-items:center;margin-left:auto">
    <button class="btn btn-ghost btn-sm" onclick="window.openScanner&&openScanner('saisie')" title="Scanner un article">📷 Scanner</button>
    <?php if (module_actif('saisie_vocale')): ?>
    <button class="btn btn-ghost btn-sm" onclick="window.toggleVoice&&toggleVoice()" id="btn-voice">🎤 Saisie vocale</button>
    <?php endif; ?>
    <button class="btn btn-ghost btn-sm" onclick="window.showTab&&showTab('histo')">
      📚 Historique <span style="background:var(--primary);color:#fff;border-radius:99px;padding:1px 7px;font-size:.7rem;margin-left:4px"><?= count($historique) ?></span>
    </button>
  </div>
</div>

<!-- Tabs -->
<div class="t-tabs">
  <button class="t-tab active" onclick="showTab('saisie')" id="tab-saisie">📋 Nouvelle intervention</button>
  <button class="t-tab" onclick="showTab('remp')" id="tab-remp" style="display:none">
    🔄 Rempotage en cours <span class="tab-badge" id="remp-badge">0</span>
  </button>
  <button class="t-tab" onclick="showTab('histo')" id="tab-histo">
    📚 Historique <span class="tab-badge"><?= count($historique) ?></span>
  </button>
</div>

<!-- ══ SAISIE ══════════════════════════════════════════ -->
<div class="t-screen active" id="screen-saisie">
  <div class="terrain-wrap">

    <!-- Colonne gauche : formulaire -->
    <div class="saisie-card">
      <div class="saisie-header">
        <input type="text" id="s-ref" class="saisie-input" placeholder="N° intervention (optionnel)" style="max-width:200px">
        <input type="text" id="s-notes" class="saisie-input" placeholder="Notes / motif">
        <button class="btn btn-ghost" onclick="openScanner('saisie')" title="Scanner" style="padding:.6rem .875rem">📷</button>
      </div>

      <!-- En-tête colonnes (PC uniquement) -->
      <div class="ligne-row ligne-header-row" style="margin-bottom:2px">
        <div class="col-header">Médicament / Matériel</div>
        <div class="col-header" style="text-align:center">Qté</div>
        <div class="col-header">Unité</div>
        <div class="col-header">Emplacement</div>
        <div></div>
      </div>

      <div id="lignes-wrap"></div>

      <button onclick="ajouterLigne()" class="btn btn-ghost btn-sm" style="margin-top:8px;width:100%">+ Ajouter une ligne</button>

      <div class="saisie-footer" style="display:flex;align-items:center;justify-content:space-between;margin-top:16px;padding-top:14px;border-top:1px solid var(--border)">
        <span id="nb-lignes" style="font-size:.8rem;color:var(--text3)">0 ligne(s)</span>
        <div style="display:flex;gap:8px">
          <button class="btn btn-ghost btn-sm" onclick="toutEffacer()">🗑️ Effacer</button>
          <button class="btn btn-primary" onclick="enregistrer()" id="btn-enregistrer">✅ Enregistrer &amp; Rempotage</button>
        </div>
      </div>
    </div>

    <!-- Colonne droite : historique rapide (PC) -->
    <div class="terrain-sidebar">
      <div class="terrain-sidebar-head">📚 Interventions récentes</div>
      <?php if (empty($historique)): ?>
      <div style="color:var(--text3);font-size:.82rem;padding:12px">Aucune intervention</div>
      <?php else: foreach (array_slice($historique,0,8) as $h): ?>
      <div class="histo-item" onclick="window.location.href='interventions.php?rempotage=<?= $h['id'] ?>'">
        <div class="histo-dot dot-<?= $h['statut'] ?>"></div>
        <div style="flex:1;min-width:0">
          <div style="font-size:.82rem;font-weight:700;color:var(--text)"><?= h($h['reference'] ?: 'Sans référence') ?></div>
          <div style="font-size:.7rem;color:var(--text3)"><?= (int)$h['nb_lignes'] ?> art. · <?= date('d/m H:i',strtotime($h['cree_le'])) ?></div>
        </div>
        <span style="font-size:.68rem;padding:.2rem .6rem;border-radius:20px;background:<?= $h['statut']==='termine'?'rgba(16,185,129,.12)':'rgba(245,158,11,.12)' ?>;color:<?= $h['statut']==='termine'?'#34d399':'#fbbf24' ?>">
          <?= $h['statut']==='termine'?'✅':'⏳' ?>
        </span>
      </div>
      <?php endforeach; endif; ?>
    </div>

  </div>
</div>

<!-- ══ REMPOTAGE ═══════════════════════════════════════ -->
<div class="t-screen" id="screen-remp">
  <div style="background:var(--glass);border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:16px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
      <div>
        <div style="font-size:1rem;font-weight:700">Rempotage en cours</div>
        <div style="font-size:.78rem;color:var(--text3)" id="remp-sub"></div>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-ghost btn-sm" onclick="openScanner('remp')">📷 Scanner</button>
        <button class="btn btn-primary" onclick="validerRempotage()" id="btn-valider">✅ Tout valider</button>
      </div>
    </div>
    <div style="background:var(--border);border-radius:4px;height:6px;margin-bottom:16px;overflow:hidden">
      <div id="remp-progress-bar" style="height:100%;background:var(--primary);width:0%;transition:width .4s;border-radius:4px"></div>
    </div>
    <div id="remp-articles-list"></div>
  </div>
</div>

<!-- ══ HISTORIQUE ══════════════════════════════════════ -->
<div class="t-screen" id="screen-histo">
  <div style="background:var(--glass);border:1px solid var(--border);border-radius:16px;overflow:hidden">
    <div style="padding:14px 18px;background:var(--bg3);border-bottom:1px solid var(--border);font-size:.78rem;font-weight:700">
      📚 Historique des interventions (<?= count($historique) ?>)
    </div>
    <?php if (empty($historique)): ?>
    <div style="text-align:center;padding:3rem;color:var(--text3)">Aucune intervention</div>
    <?php else: foreach ($historique as $h): ?>
    <div class="histo-item" style="border-radius:0;border-left:none;border-right:none;border-top:none" onclick="window.location.href='interventions.php?rempotage=<?= $h['id'] ?>'">
      <div class="histo-dot dot-<?= $h['statut'] ?>"></div>
      <div style="flex:1;min-width:0">
        <div style="font-size:.875rem;font-weight:700"><?= h($h['reference']?:'Sans référence') ?></div>
        <div style="font-size:.75rem;color:var(--text3)"><?= (int)$h['nb_lignes'] ?> art. · <?= date('d/m/Y H:i',strtotime($h['cree_le'])) ?> · <?= h($h['user_nom']) ?></div>
      </div>
      <span style="font-size:.75rem;padding:.25rem .7rem;border-radius:20px;font-weight:700;background:<?= $h['statut']==='termine'?'rgba(16,185,129,.12)':'rgba(245,158,11,.12)' ?>;color:<?= $h['statut']==='termine'?'#34d399':'#fbbf24' ?>">
        <?= $h['statut']==='termine'?'✅ Rempoté':'⏳ À rempotter' ?>
      </span>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>


<!-- Scanner overlay -->
<div class="scan-overlay" id="scan-overlay">
  <video id="scan-video" playsinline autoplay muted></video>
  <div class="scan-ui">
    <div class="scan-top">
      <div class="scan-title" id="scan-label">Scanner un article</div>
      <button class="scan-close" onclick="closeScanner()">✕</button>
    </div>
    <div class="scan-frame-wrap">
      <div class="scan-frame">
        <div class="scan-line"></div>
        <div class="scan-corner sc-tl"></div>
        <div class="scan-corner sc-tr"></div>
        <div class="scan-corner sc-bl"></div>
        <div class="scan-corner sc-br"></div>
      </div>
    </div>
    <div class="scan-bottom">
      <div class="scan-hint">DataMatrix · EAN13 · Code-barres</div>
      <button class="scan-cam-btn" onclick="switchCam()">🔄 Changer caméra</button>
    </div>
    <div class="scan-sheet" id="scan-sheet">
      <div class="scan-sheet-handle"></div>
      <div id="scan-sheet-content"></div>
    </div>
  </div>
</div>

<script src="/scanner/zxing-0.21.3.min.js"></script>
<script src="/scanner/scanner-zxing.js"></script>
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const EMPLS = <?= json_encode($empls) ?>;
const empls = EMPLS; // alias pour compatibilité formulaire

// State
var lignes = [];
var rempArticles = [];
var currentInterId = null;
var scanMode = 'saisie';
var scanLineIdx = null;
var codeReader = null;
var cameras = [];
var camIdx = 0;
var savedDeviceId = null;
var lastCode = '';
var lastCodeTime = 0;

// ── Utils ─────────────────────────────────────────────────
function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDate(s) {
    if (!s) return '';
    var p = s.split('-');
    if (p.length === 3) return p[2]+'/'+p[1]+'/'+p[0];
    return s;
}
function dateDiff(s) {
    if (!s) return 9999;
    var p = s.split('-');
    var d = p.length===3 ? new Date(parseInt(p[0]),parseInt(p[1])-1,parseInt(p[2])) : new Date(s);
    return (d - new Date()) / (1000*60*60*24);
}

// ── Navigation ────────────────────────────────────────────
function showTab(tab) {
    ['saisie','remp','histo'].forEach(function(t) {
        var s = document.getElementById('screen-'+t);
        var b = document.getElementById('tab-'+t);
        if (s) s.classList.remove('active');
        if (b) b.classList.remove('active');
    });
    var sc = document.getElementById('screen-'+tab);
    var bt = document.getElementById('tab-'+tab);
    if (sc) sc.classList.add('active');
    if (bt) bt.classList.add('active');
    var fs = document.getElementById('footer-saisie'); if (fs) fs.style.display = tab==='saisie' ? 'flex' : 'none';
    var fr = document.getElementById('footer-remp');   if (fr) fr.style.display = tab==='remp'   ? 'flex' : 'none';
}

function toggleOpt() {
    var f = document.getElementById('opt-fields');
    var a = document.getElementById('opt-arrow');
    if (!f || !a) return;
    var open = f.classList.toggle('open');
    a.textContent = open ? '▾' : '▸';
}

// ── Autocomplete ──────────────────────────────────────────
var acTimer = null;
function acSearch(idx, q) {
    clearTimeout(acTimer);
    document.getElementById('ac-'+idx) && document.getElementById('ac-'+idx).remove();
    if (q.length < 2) return;
    acTimer = setTimeout(function() {
        fetch('terrain.php?ajax=search&q='+encodeURIComponent(q))
            .then(function(r){return r.json();})
            .then(function(items){showAc(idx,items);})
            .catch(function(){});
    }, 250);
}
function showAc(idx, items) {
    var inp = document.getElementById('nom-'+idx);
    if (!inp || !items.length) return;
    var rect = inp.getBoundingClientRect();
    var div = document.createElement('div');
    div.id = 'ac-'+idx;
    div.style.cssText = 'position:fixed;top:'+Math.round(rect.bottom+2)+'px;left:'+Math.round(rect.left)+'px;width:'+Math.round(rect.width)+'px;background:var(--bg2);border:1px solid var(--border);border-radius:12px;z-index:800;max-height:180px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,.3)';
    items.forEach(function(p) {
        var item = document.createElement('div');
        item.style.cssText = 'padding:.65rem 1rem;cursor:pointer;font-size:.875rem;border-bottom:1px solid var(--border)';
        item.innerHTML = '<strong>'+esc(p.nom)+'</strong><span style="color:var(--text3);font-size:.75rem"> · '+esc(p.emplacement||'')+'</span>';
        item.onmousedown = function() { selectAc(idx,p); };
        div.appendChild(item);
    });
    document.body.appendChild(div);
}
function closeAc(idx) {
    var el = document.getElementById('ac-'+idx);
    if (el) el.remove();
}
function selectAc(idx, p) {
    lignes[idx].nom = p.nom;
    lignes[idx].produit_id = p.id;
    lignes[idx].emplacement_id = p.emplacement_id;
    renderLignes();
    closeAc(idx);
}

function renderRemp() {
    var done = rempArticles.filter(function(a){return a.done;}).length;
    var total = rempArticles.length;
    document.getElementById('remp-progress-bar').style.width = (total ? Math.round(done/total*100) : 0)+'%';
    var rl=document.getElementById('remp-lbl'); if(rl) rl.textContent = done+' / '+total+' rempotés';
    document.getElementById('remp-badge').textContent = total - done;
    var list = document.getElementById('remp-articles-list');
    var html = '';
    rempArticles.forEach(function(a, i) {
        html += '<div class="t-remp-card'+(a.done?' done':'')+'">';
        html += '<div class="t-remp-inner">';
        html += '<div class="t-check'+(a.done?' done':'')+'" onclick="toggleDone('+i+')">'+(a.done?'✓':'')+'</div>';
        html += '<div class="t-remp-info"><div class="t-remp-name">'+esc(a.nom)+'</div><div class="t-remp-sub">× '+a.quantite+' '+esc(a.unite)+'</div></div>';
        html += '</div>';
        html += '<div class="t-remp-fields">';
        html += '<input type="text" class="t-remp-input" placeholder="N° lot" value="'+esc(a.lot)+'" oninput="rempArticles['+i+'].lot=this.value">';
        html += '<input type="text" class="t-remp-input" placeholder="MM/AAAA" value="'+esc(a.peremption)+'" oninput="rempArticles['+i+'].peremption=this.value">';
        html += '<button class="t-remp-scan" onclick="openScannerRemp('+i+')">📷</button>';
        html += '</div></div>';
    });
    list.innerHTML = html;
}

function toggleDone(i) {
    rempArticles[i].done = !rempArticles[i].done;
    if (navigator.vibrate) navigator.vibrate(rempArticles[i].done ? [50,30,50] : 30);
    renderRemp();
}

function openScannerRemp(idx) {
    scanLineIdx = idx;
    openScanner('remp');
}

function validerRempotage() {
    var btn = document.getElementById('btn-valider');
    btn.disabled = true; btn.textContent = '⏳…';
    var fd = new FormData();
    fd.append('action','valider_rempotage');
    fd.append('csrf_token',CSRF);
    fd.append('inter_id',currentInterId||0);
    fd.append('articles',JSON.stringify(rempArticles));
    fetch('terrain.php',{method:'POST',body:fd})
        .then(function(r){return r.json();})
        .then(function(data) {
            if (data.ok) {
                if (navigator.vibrate) navigator.vibrate([100,50,200]);
                alert('✅ Rempotage validé !');
                rempArticles = []; currentInterId = null;
                document.getElementById('tab-remp').style.display = 'none';
                showTab('saisie');
                location.reload();
            } else { alert('Erreur : '+(data.error||'inconnue')); }
        })
        .catch(function(){alert('Erreur réseau');})
        .finally(function(){btn.disabled=false;btn.textContent='✅ Tout valider';});
}

// ── Scanner ───────────────────────────────────────────────
function openScanner(mode) {
    scanMode = mode;
    document.getElementById('scan-label').textContent = mode==='saisie' ? 'Scanner un article' : 'Scanner lot / péremption';
    document.getElementById('scan-overlay').classList.add('open');
    document.getElementById('scan-sheet').classList.remove('show');
    startScanner();
}

function closeScanner() {
    stopScanner();
    document.getElementById('scan-overlay').classList.remove('open');
}

// ── Scanner caméra : moteur partagé (scanner/scanner-zxing.js) ──
function stopScanner()  { CadurixScanner.stop(); }
function startScanner() {
    CadurixScanner.start('scan-video', handleScan, {
        onError: function (e) { console.error('[Cadurix Scanner] Erreur caméra:', e); },
        canvasContainer: '#scan-overlay'
    });
}
function switchCam()    { CadurixScanner.switchCam(); }

function handleScan(code) {
    var sheet = document.getElementById('scan-sheet');
    var content = document.getElementById('scan-sheet-content');
    sheet.classList.add('show');
    content.innerHTML = '<div style="color:var(--text3);font-size:.85rem;text-align:center;padding:8px">⏳ Recherche…</div>';

    var fd = new FormData();
    fd.append('action','lookup_barcode');
    fd.append('csrf_token',CSRF);
    fd.append('code',code);

    fetch('terrain.php',{method:'POST',body:fd})
        .then(function(r){return r.json();})
        .then(function(data) {
            if (scanMode === 'saisie') {
                showSaisieResult(data, code);
            } else {
                showRempResult(data);
            }
        })
        .catch(function() {
            content.innerHTML = '<div style="color:#f87171;text-align:center">❌ Erreur réseau</div>';
        });
}

function showSaisieResult(data, code) {
    var content = document.getElementById('scan-sheet-content');
        var lotStr = data.lot_scan || data.lot || '';
    var dateStr = data.date_exp ? fmtDate(data.date_exp) : '';
    var pills = '';
    if (lotStr) pills += '<span class="sheet-pill sp-lot">📦 '+esc(lotStr)+'</span>';
    if (dateStr) pills += '<span class="sheet-pill sp-date">📅 '+dateStr+'</span>';
    if (data.emplacement) pills += '<span class="sheet-pill sp-empl">📍 '+esc(data.emplacement)+'</span>';

    // Alerte péremption
    var alert = '';
    var diff = dateDiff(data.date_exp || data.date_peremption);
    if (diff < 0) alert = '<div style="color:#f87171;font-size:.78rem;margin-bottom:8px">🚫 PÉRIMÉ — ne pas utiliser</div>';
    else if (diff < 90) alert = '<div style="color:#fbbf24;font-size:.78rem;margin-bottom:8px">⚠️ Péremption dans '+Math.round(diff)+' jours</div>';

    if (data.found) {
        // Stocker pour le bouton
        window._scanData = data;
        content.innerHTML = alert
            + '<div class="scan-sheet-nom">'+esc(data.nom)+'</div>'
            + (pills ? '<div class="scan-sheet-pills">'+pills+'</div>' : '')
            + '<button class="scan-sheet-btn" id="btn-add-scan">+ Ajouter à l\'intervention</button>';
        document.getElementById('btn-add-scan').onclick = function() {
            ajouterLigne({
                nom: window._scanData.nom,
                produit_id: window._scanData.id,
                emplacement_id: window._scanData.emplacement_id,
                unite: window._scanData.unite || 'unité',
                lot: window._scanData.lot || '',
                date_peremption: window._scanData.date_exp || window._scanData.date_peremption || ''
            });
            closeScanner();
            showTab('saisie');
        };
    } else {
        window._scanManual = {ean: data.ean || code, lot: lotStr, dateExp: data.date_exp || ''};
        content.innerHTML = '<div style="color:var(--text3);font-size:.8rem;margin-bottom:6px">Médicament non reconnu</div>'
            + '<div style="font-size:.85rem;font-weight:600;margin-bottom:10px;word-break:break-all">'+esc(data.ean || code)+'</div>'
            + (pills ? '<div class="scan-sheet-pills" style="margin-bottom:12px">'+pills+'</div>' : '')
            + '<button class="scan-sheet-btn secondary" id="btn-create-scan">+ Créer manuellement</button>';
        document.getElementById('btn-create-scan').onclick = function() {
            var m = window._scanManual;
            ajouterLigne({nom: '', lot: m.lot, date_peremption: m.dateExp});
            closeScanner();
            showTab('saisie');
            // Focus sur le dernier champ nom
            setTimeout(function() {
                var inputs = document.querySelectorAll('[id^="nom-"]');
                if (inputs.length) { inputs[inputs.length-1].focus(); }
            }, 300);
        };
    }
}

function showRempResult(data) {
    var content = document.getElementById('scan-sheet-content');
    var lot = data.lot || '';
    var dateExp = data.date_exp || data.date_peremption || '';
    var pexp = '';
    if (dateExp) {
        var dp = dateExp.split('-');
        if (dp.length===3) pexp = dp[1]+'/'+dp[0];
    }
    if (scanLineIdx !== null) {
        if (lot) rempArticles[scanLineIdx].lot = lot;
        if (pexp) rempArticles[scanLineIdx].peremption = pexp;
    }
    var pills = '';
    if (lot) pills += '<span class="sheet-pill sp-lot">📦 '+esc(lot)+'</span>';
    if (pexp) pills += '<span class="sheet-pill sp-date">📅 '+pexp+'</span>';
    content.innerHTML = '<div class="scan-sheet-nom">✅ Code scanné</div>'
        + (pills ? '<div class="scan-sheet-pills">'+pills+'</div>' : '<div style="color:var(--text3);font-size:.8rem">Aucune info lot/date</div>')
        + '<button class="scan-sheet-btn" id="btn-confirm-remp">✅ Confirmer</button>';
    document.getElementById('btn-confirm-remp').onclick = function() {
        closeScanner();
        renderRemp();
    };
}



// ── Lignes articles ───────────────────────────────────────
function buildEmplOpts(selectedId) {
    var html = '<option value="">📍 Emplacement —</option>';
    EMPLS.forEach(function(e) {
        var label = e.parent ? e.parent+' › '+e.nom : e.nom;
        var sel = (parseInt(e.id) === parseInt(selectedId)) ? ' selected' : '';
        html += '<option value="'+e.id+'"'+sel+'>'+esc(label)+'</option>';
    });
    return html;
}

function ajouterLigne(data) {
    data = data || {};
    lignes.push({
        nom:            data.nom            || '',
        quantite:       data.quantite       || 1,
        unite:          data.unite          || 'unité',
        produit_id:     data.produit_id     || null,
        emplacement_id: data.emplacement_id || null,
        lot:            data.lot            || '',
        date_peremption:data.date_peremption|| '',
    });
    renderLignes();
}

function renderLignes() {
    var wrap = document.getElementById('lignes-wrap');
    if (!wrap) return;
    var html = '';
    var isMobile = window.innerWidth <= 768;
    var unites = ['unité','ml','mg','g','cp','amp','sachet'];

    lignes.forEach(function(l, i) {
        if (isMobile) {
            // ── Carte mobile ──────────────────────────────────
            html += '<div class="m-article-card">';
            html += '<button class="m-article-del" onclick="supprimerLigne('+i+')" title="Supprimer">✕</button>';

            // Nom médicament
            html += '<div class="m-article-nom">';
            html += '<label>💊 Médicament / Matériel</label>';
            html += '<input type="text" id="nom-'+i+'" value="'+esc(l.nom)+'" placeholder="Médicament / matériel..." oninput="lignes['+i+'].nom=this.value;acSearch('+i+',this.value)" onblur="setTimeout(function(){closeAc('+i+')},200)">';
            html += '</div>';

            // Qté + Unité
            html += '<div class="m-article-row">';
            html += '<div class="m-article-field" style="max-width:90px">';
            html += '<label>Qté</label>';
            html += '<input type="number" value="'+l.quantite+'" min="0.1" step="0.1" oninput="lignes['+i+'].quantite=parseFloat(this.value)||1" style="text-align:center">';
            html += '</div>';
            html += '<div class="m-article-field" style="max-width:110px">';
            html += '<label>Unité</label>';
            html += '<select onchange="lignes['+i+'].unite=this.value">';
            unites.forEach(function(u) { html += '<option'+(l.unite===u?' selected':'')+'>'+u+'</option>'; });
            html += '</select>';
            html += '</div>';
            html += '<div class="m-article-field">';
            html += '<label>📍 Emplacement</label>';
            html += '<select onchange="lignes['+i+'].emplacement_id=parseInt(this.value)||null">'+buildEmplOpts(l.emplacement_id)+'</select>';
            html += '</div>';
            html += '</div>';

            // Lot + Date péremption
            html += '<div class="m-article-row">';
            html += '<div class="m-article-field">';
            html += '<label>📦 N° Lot</label>';
            html += '<input type="text" value="'+esc(l.lot||'')+'" placeholder="Numéro de lot" oninput="lignes['+i+'].lot=this.value">';
            html += '</div>';
            html += '<div class="m-article-field">';
            html += '<label>📅 Péremption</label>';
            html += '<input type="date" value="'+esc(l.date_peremption||'')+'" oninput="lignes['+i+'].date_peremption=this.value">';
            html += '</div>';
            html += '</div>';

            html += '</div>'; // .m-article-card

        } else {
            // ── Ligne PC (inchangée) ──────────────────────────
            html += '<div class="ligne-row">';
            html += '<div class="ligne-nom-wrap">';
            html += '<input type="text" id="nom-'+i+'" class="td-input" value="'+esc(l.nom)+'" placeholder="Médicament / matériel..." oninput="lignes['+i+'].nom=this.value;acSearch('+i+',this.value)" onblur="setTimeout(function(){closeAc('+i+')},200)">';
            html += '</div>';
            html += '<div class="ligne-qte-wrap">';
            html += '<input type="number" class="td-input" value="'+l.quantite+'" min="0.1" step="0.1" oninput="lignes['+i+'].quantite=parseFloat(this.value)||1" style="text-align:center" placeholder="Qté">';
            html += '</div>';
            html += '<div class="ligne-unite-wrap">';
            html += '<select class="td-input" onchange="lignes['+i+'].unite=this.value">';
            unites.forEach(function(u) { html += '<option'+(l.unite===u?' selected':'')+'>'+u+'</option>'; });
            html += '</select>';
            html += '</div>';
            html += '<div class="ligne-empl-wrap"><select class="td-input" onchange="lignes['+i+'].emplacement_id=parseInt(this.value)||null">'+buildEmplOpts(l.emplacement_id)+'</select></div>';
            html += '<div class="ligne-del"><button onclick="supprimerLigne('+i+')" title="Supprimer">✕</button></div>';
            html += '</div>';
        }
    });

    wrap.innerHTML = html;
    var nb = document.getElementById('nb-lignes');
    if (nb) nb.textContent = lignes.length + ' ligne(s)';
}

function supprimerLigne(i) {
    if (navigator.vibrate) navigator.vibrate(30);
    lignes.splice(i, 1);
    renderLignes();
}

function toutEffacer() {
    if (!lignes.length || confirm('Effacer tous les articles ?')) {
        lignes = [];
        renderLignes();
        var ref = document.getElementById('s-ref');
        var notes = document.getElementById('s-notes');
        if (ref) ref.value = '';
        if (notes) notes.value = '';
    }
}

function enregistrer() {
    var valid = lignes.filter(function(l){ return l.nom.trim(); });
    if (!valid.length) { alert('Ajoutez au moins un article.'); return; }
    var btn = document.getElementById('btn-enregistrer');
    btn.disabled = true; btn.textContent = '⏳ Enregistrement...';
    var fd = new FormData();
    fd.append('action', 'save_intervention');
    fd.append('csrf_token', CSRF);
    fd.append('reference', document.getElementById('s-ref').value);
    fd.append('notes', document.getElementById('s-notes').value);
    fd.append('lignes', JSON.stringify(valid));
    fetch('terrain.php', {method:'POST', body:fd})
        .then(function(r){
            if (!r.ok) { alert('HTTP ' + r.status + ' ' + r.statusText); return {}; }
            return r.json();
        })
        .then(function(data) {
            if (!data || !data.ok && !data.error) return;
            if (data.ok) {
                currentInterId = data.inter_id;
                rempArticles = valid.map(function(l) {
                    var pexp = '';
                    if (l.date_peremption) {
                        var dp = l.date_peremption.split('-');
                        if (dp.length === 3) pexp = dp[1]+'/'+dp[0];
                    }
                    return {nom:l.nom, quantite:l.quantite, unite:l.unite,
                            produit_id:l.produit_id, emplacement_id:l.emplacement_id,
                            done:false, lot:l.lot||'', peremption:pexp, quantite_remis:l.quantite};
                });
                renderRemp(); lignes = []; renderLignes();
                showTab('remp');
                document.getElementById('tab-remp').style.display = '';
                document.getElementById('remp-badge').textContent = rempArticles.length;
            } else { alert('Erreur : ' + (data.error||'inconnue')); }
        })
        .catch(function(err){ alert('Erreur réseau : ' + (err.message||err)); })
        .finally(function(){ btn.disabled=false; btn.textContent='✅ Enregistrer & Rempotage'; });
}


// ── Saisie vocale IA ─────────────────────────────────────
let recognition = null;
let voiceActive  = false;

function toggleVoice() {
  const btn = document.getElementById('btn-voice');
  if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
    alert('Saisie vocale non supportée sur ce navigateur (utilise Chrome ou Safari).');
    return;
  }
  if (voiceActive) {
    if (recognition) recognition.stop();
    return;
  }
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  recognition = new SR();
  recognition.lang = 'fr-FR';
  recognition.continuous = false;
  recognition.interimResults = false;

  recognition.onstart = function() {
    voiceActive = true;
    btn.innerHTML = '🔴 Écoute…';
    btn.classList.remove('btn-ghost'); btn.classList.add('btn-primary');
  };

  recognition.onresult = async function(e) {
    const transcript = e.results[0][0].transcript.trim();
    voiceActive = false;
    btn.innerHTML = '⏳ Analyse IA…';

    try {
      await voiceIA(transcript);
    } catch(err) {
      // Fallback : ajouter le texte brut + afficher l'erreur
      ajouterLigne({ nom: transcript });
      if (typeof showToast === 'function') showToast('⚠️ IA indisponible : ' + err.message, 'warning');
      console.error('voiceIA error:', err);
    }

    btn.innerHTML = '🎤 Saisie vocale';
    btn.classList.remove('btn-primary'); btn.classList.add('btn-ghost');
  };

  recognition.onerror = function() {
    voiceActive = false;
    btn.innerHTML = '🎤 Saisie vocale';
    btn.classList.remove('btn-primary'); btn.classList.add('btn-ghost');
  };

  recognition.onend = function() {
    voiceActive = false;
    if (btn.innerHTML === '🔴 Écoute…') {
      btn.innerHTML = '🎤 Saisie vocale';
      btn.classList.remove('btn-primary'); btn.classList.add('btn-ghost');
    }
  };

  recognition.start();
}

async function voiceIA(transcript) {
  // Construire la liste des médicaments connus pour aider l'IA
  const medsConnus = empls.length > 0
    ? 'Emplacements disponibles : ' + empls.map(e => e.nom).join(', ')
    : '';

  const prompt = `Tu es un assistant pour secouristes français (SDIS/SAMU/VSAV).
L'utilisateur vient de dire : "${transcript}"
${medsConnus}

Réponds UNIQUEMENT en JSON valide, sans markdown, sans explication :
{
  "nom": "nom exact du médicament ou matériel médical en français",
  "quantite": nombre (défaut 1),
  "unite": "unité|ml|mg|g|cp|amp|sachet"
}

Si tu ne reconnais pas de médicament ou matériel médical, retourne :
{"nom": "${transcript}", "quantite": 1, "unite": "unité"}`;

  // Appel via proxy PHP sécurisé (clé API côté serveur)
  const response = await fetch('/ai/voice_terrain.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'voice_terrain',
      prompt: prompt,
      max_tokens: 150,
      csrf_token: CSRF
    })
  });

  const aiData = await response.json();
  const text   = aiData.content || aiData.text || '{}';
  let parsed;
  try { parsed = JSON.parse(text.replace(/```json|```/g, '').trim()); }
  catch(e) { parsed = { nom: transcript, quantite: 1, unite: 'unité' }; }

  const nom      = parsed.nom      || transcript;
  const quantite = parsed.quantite || 1;
  const unite    = parsed.unite    || 'unité';

  // Chercher dans la BDD via l'autocomplete existant
  let produit_id = null, emplacement_id = null;
  try {
    const r = await fetch('terrain.php?ajax=search&q=' + encodeURIComponent(nom.substring(0, 30)));
    const results = await r.json();
    if (results.length > 0) {
      // Prendre le premier résultat correspondant
      const match = results[0];
      produit_id    = match.id;
      emplacement_id= match.emplacement_id;
    }
  } catch(e) {}

  ajouterLigne({ nom, quantite, unite, produit_id, emplacement_id });

  // Toast de confirmation
  if (typeof showToast === 'function') {
    showToast('🎤 Ajouté : ' + nom + ' (' + quantite + ' ' + unite + ')', 'success');
  }
}

// Init
// Sur mobile on démarre sans lignes vides
window.addEventListener('load', function() { if (window.innerWidth > 768) { ajouterLigne(); ajouterLigne(); ajouterLigne(); } });
</script>





<?php require_once 'includes/footer.php'; ?>
