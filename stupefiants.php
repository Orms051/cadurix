<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.
// Module Stupéfiants — registre coffre, prise de garde, administration, casse/perte, validation.
require_once 'includes/auth.php';
$site_id = site_actif();
if (!$site_id) { header('Location: dashboard.php'); exit; }
$pageTitle   = 'Stupéfiants';
$currentPage = 'stupefiants';

// ── Permissions du module (avant toute sortie) ──
function stx_niveau($pdo, string $key): int {
    if ($_SESSION['super_admin'] ?? false) return 2;
    $role = function_exists('role_sur_site') ? (role_sur_site() ?? '') : '';
    if (!$role) return 0;
    $n = 0;
    try { $st = $pdo->prepare("SELECT niveau FROM role_permissions WHERE role=? AND module=?"); $st->execute([$role, $key]); $v = $st->fetchColumn(); if ($v !== false) $n = (int)$v; } catch (Exception $e) {}
    $sid = site_actif();
    if ($sid) { try { $st2 = $pdo->prepare("SELECT niveau FROM site_role_permissions WHERE site_id=? AND role=? AND module=?"); $st2->execute([$sid, $role, $key]); $o = $st2->fetchColumn(); if ($o !== false) $n = (int)$o; } catch (Exception $e) {} }
    return $n;
}
$canStup     = stx_niveau($pdo, 'stupefiants') >= 1;
$canRegistre = stx_niveau($pdo, 'stupefiants_registre') >= 1;
$canValider  = stx_niveau($pdo, 'stupefiants_valider') >= 1;
$canEntree   = $canValider || peut_faire(ROLE_GESTIONNAIRE);
if (!$canStup) { accessDenied(); exit; }

$pxQ = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');

// ── Produits stupéfiants du site ──
$stmt = $pdo->prepare("SELECT p.*, e.nom AS emp_nom, e.type AS emp_type
    FROM produits p JOIN emplacements e ON e.id = p.emplacement_id
    WHERE p.stupefiant = 1 AND e.site_id = ? AND p.statut = 'actif'
    ORDER BY COALESCE(NULLIF(p.dci,''), p.nom)");
$stmt->execute([$site_id]);
$stups = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pid = (int)($_POST['produit_id'] ?? $_GET['p'] ?? 0);
$prod = null;
foreach ($stups as $s) { if ((int)$s['id'] === $pid) { $prod = $s; break; } }
if (!$prod && $stups) { $prod = $stups[0]; $pid = (int)$prod['id']; }

// ── POST : actions (PRG) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $prod) {
    csrf_check();
    $action  = $_POST['action'] ?? '';
    $now     = date('Y-m-d H:i:s');
    $periode = date('Y-m');
    $uid     = currentUserId();
    $dose_mg = $prod['dose_mg'] !== null ? (float)$prod['dose_mg'] : null;
    $solde   = (float)$prod['quantite'];
    $redirect = 'stupefiants.php?p=' . $pid;
    $mc = '';

    $logMouvement = function($delta, $avant, $apres, $motif) use ($pdo, $pid, $uid) {
        try {
            $pdo->prepare("INSERT INTO mouvements_stock (produit_id, user_id, type, quantite_avant, quantite_delta, quantite_apres, motif) VALUES(?,?,?,?,?,?,?)")
                ->execute([$pid, $uid, $delta >= 0 ? 'entree' : 'sortie', $avant, $delta, $apres, $motif]);
        } catch (Exception $e) {}
    };

    try {
        $pdo->beginTransaction();

        if ($action === 'administer') {
            $amp   = max(1, (int)($_POST['ampoules'] ?? 1));
            $adm   = (float)str_replace(',', '.', $_POST['admin_mg'] ?? '0');
            $ouv   = $dose_mg !== null ? $dose_mg * $amp : null;
            if ($ouv !== null && $adm > $ouv) $adm = $ouv;
            $detr  = $ouv !== null ? max(0, $ouv - $adm) : null;
            $apres = $solde - $amp;
            $pdo->prepare("UPDATE produits SET quantite=? WHERE id=?")->execute([$apres, $pid]);
            $pdo->prepare("INSERT INTO stupefiants_registre (produit_id, site_id, type, qte_ampoules, solde_apres, ouvert_mg, admin_mg, detruit_mg, intervention_ref, lot, date_exp, periode, user_id, cree_le)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$pid, $site_id, 'administration', -$amp, $apres, $ouv, $adm, $detr,
                    trim($_POST['intervention_ref'] ?? '') ?: null,
                    trim($_POST['lot'] ?? '') ?: null,
                    ($_POST['date_exp'] ?? '') !== '' ? $_POST['date_exp'] : null,
                    $periode, $uid, $now]);
            $logMouvement(-$amp, $solde, $apres, 'Stupéfiant — administration');
            $mc = $apres < 0 ? 'admneg' : 'adm';
        }

        elseif ($action === 'casse') {
            $motifType = ($_POST['motif_type'] ?? 'casse') === 'perte' ? 'perte' : 'casse';
            $amp   = max(1, (int)($_POST['ampoules'] ?? 1));
            $detr  = ($motifType === 'casse' && $dose_mg !== null) ? $dose_mg * $amp : null; // casse = détruite
            $apres = $solde - $amp;
            $pdo->prepare("UPDATE produits SET quantite=? WHERE id=?")->execute([$apres, $pid]);
            $pdo->prepare("INSERT INTO stupefiants_registre (produit_id, site_id, type, qte_ampoules, solde_apres, detruit_mg, lot, date_exp, motif, periode, user_id, cree_le)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$pid, $site_id, $motifType, -$amp, $apres, $detr,
                    trim($_POST['lot'] ?? '') ?: null,
                    ($_POST['date_exp'] ?? '') !== '' ? $_POST['date_exp'] : null,
                    trim($_POST['circonstances'] ?? '') ?: null,
                    $periode, $uid, $now]);
            $logMouvement(-$amp, $solde, $apres, 'Stupéfiant — ' . $motifType);
            audit_log($pdo, 'STUP_' . strtoupper($motifType), 'produits', $pid, ['solde' => $solde], ['solde' => $apres]);
            $mc = $motifType;
        }

        elseif ($action === 'garde') {
            $moment = ($_POST['moment'] ?? 'matin') === 'soir' ? 'soir' : 'matin';
            $compte = (float)str_replace(',', '.', $_POST['compte'] ?? '0');
            $ecart  = $compte - $solde;
            $statut = abs($ecart) < 0.001 ? 'conforme' : 'anomalie';
            $pdo->prepare("INSERT INTO stupefiants_controles (produit_id, site_id, jour, moment, theorique, compte, ecart, statut, note, user_id, cree_le)
                VALUES(?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$pid, $site_id, date('Y-m-d'), $moment, $solde, $compte, $ecart, $statut,
                    trim($_POST['note'] ?? '') ?: null, $uid, $now]);
            if ($statut === 'anomalie') {
                audit_log($pdo, 'STUP_ECART_GARDE', 'produits', $pid, ['theorique' => $solde], ['compte' => $compte, 'ecart' => $ecart]);
                $mc = 'gko';
            } else {
                $mc = 'gok';
            }
        }

        elseif ($action === 'entree' && $canEntree) {
            $amp   = max(1, (int)($_POST['ampoules'] ?? 1));
            $apres = $solde + $amp;
            $pdo->prepare("UPDATE produits SET quantite=? WHERE id=?")->execute([$apres, $pid]);
            $pdo->prepare("INSERT INTO stupefiants_registre (produit_id, site_id, type, qte_ampoules, solde_apres, lot, date_exp, motif, periode, user_id, cree_le)
                VALUES(?,?,?,?,?,?,?,?,?,?)")
                ->execute([$pid, $site_id, 'entree', $amp, $apres,
                    trim($_POST['lot'] ?? '') ?: null,
                    ($_POST['date_exp'] ?? '') !== '' ? $_POST['date_exp'] : null,
                    trim($_POST['motif'] ?? 'Réappro') ?: 'Réappro',
                    $periode, $uid, $now]);
            $logMouvement($amp, $solde, $apres, 'Stupéfiant — entrée / réappro');
            $mc = 'entree';
        }

        elseif ($action === 'valider' && $canValider) {
            $per = preg_match('/^\d{4}-\d{2}$/', $_POST['periode'] ?? '') ? $_POST['periode'] : $periode;
            $pdo->prepare("INSERT INTO stupefiants_validations (produit_id, site_id, periode, solde_reference, user_id, cree_le) VALUES(?,?,?,?,?,?)")
                ->execute([$pid, $site_id, $per, $solde, $uid, $now]);
            audit_log($pdo, 'STUP_VALIDATION', 'produits', $pid, [], ['periode' => $per, 'solde' => $solde]);
            $mc = 'valide';
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mc = 'err';
    }
    header('Location: ' . $redirect . ($mc ? '&m=' . $mc : '')); exit;
}

// ── Données d'affichage ──
$flashMap = [
  'adm'    => ['ok',   'Administration enregistrée au registre.'],
  'admneg' => ['warn', 'Administration enregistrée — ⚠️ solde négatif, à régulariser.'],
  'casse'  => ['warn', 'Casse déclarée et débitée. Administrateur notifié.'],
  'perte'  => ['warn', 'Perte déclarée et débitée. Administrateur notifié.'],
  'gok'    => ['ok',   'Contrôle de prise de garde conforme.'],
  'gko'    => ['crit', '⚠️ Écart détecté au contrôle — administrateur notifié.'],
  'entree' => ['ok',   'Entrée enregistrée au coffre.'],
  'valide' => ['ok',   'Période validée et publiée (référence visible par tous).'],
  'err'    => ['crit', "Erreur lors de l'enregistrement."],
];
$stxFlash = $flashMap[$_GET['m'] ?? ''] ?? null;

$registre = $ctrlToday = []; $lastVal = null; $valPeriods = [];
$stats = ['nb' => 0, 'entrees' => 0, 'sorties' => 0];
if ($prod) {
    if ($canRegistre) {
        $r = $pdo->prepare("SELECT r.*, TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) AS agent
            FROM stupefiants_registre r LEFT JOIN utilisateurs u ON u.id = r.user_id
            WHERE r.produit_id = ? ORDER BY r.cree_le ASC, r.id ASC");
        $r->execute([$pid]);
        $registre = $r->fetchAll(PDO::FETCH_ASSOC);
        $vp = $pdo->prepare("SELECT DISTINCT periode FROM stupefiants_validations WHERE produit_id=?");
        $vp->execute([$pid]);
        $valPeriods = $vp->fetchAll(PDO::FETCH_COLUMN);
    }
    // contrôles du jour
    $c = $pdo->prepare("SELECT * FROM stupefiants_controles WHERE produit_id=? AND jour=? ORDER BY id DESC");
    $c->execute([$pid, date('Y-m-d')]);
    foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $row) { if (!isset($ctrlToday[$row['moment']])) $ctrlToday[$row['moment']] = $row; }
    // dernière validation (référence publique)
    $lv = $pdo->prepare("SELECT v.*, TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) AS agent
        FROM stupefiants_validations v LEFT JOIN utilisateurs u ON u.id=v.user_id
        WHERE v.produit_id=? ORDER BY v.cree_le DESC LIMIT 1");
    $lv->execute([$pid]);
    $lastVal = $lv->fetch(PDO::FETCH_ASSOC) ?: null;
    // récap période courante
    $sc = $pdo->prepare("SELECT COUNT(*) nb,
            COALESCE(SUM(CASE WHEN qte_ampoules>0 THEN qte_ampoules ELSE 0 END),0) entrees,
            COALESCE(SUM(CASE WHEN qte_ampoules<0 THEN -qte_ampoules ELSE 0 END),0) sorties
        FROM stupefiants_registre WHERE produit_id=? AND periode=?");
    $sc->execute([$pid, date('Y-m')]);
    $stats = $sc->fetch(PDO::FETCH_ASSOC) ?: $stats;
    // anomalie ouverte récente
    $an = $pdo->prepare("SELECT * FROM stupefiants_controles WHERE produit_id=? AND statut='anomalie' ORDER BY cree_le DESC LIMIT 1");
    $an->execute([$pid]);
    $lastAnomalie = $an->fetch(PDO::FETCH_ASSOC) ?: null;
} else { $lastAnomalie = null; }

$moisFr = function($p){ if(!$p) return ''; $m=['01'=>'Janvier','02'=>'Février','03'=>'Mars','04'=>'Avril','05'=>'Mai','06'=>'Juin','07'=>'Juillet','08'=>'Août','09'=>'Septembre','10'=>'Octobre','11'=>'Novembre','12'=>'Décembre']; [$y,$mo]=explode('-',$p); return ($m[$mo]??$mo).' '.$y; };
$mvLabel = ['entree'=>'Entrée · réappro','administration'=>'Administration','destruction'=>'Destruction','casse'=>'💥 Casse / bris','perte'=>'📉 Perte'];
$mvClass = ['entree'=>'t-entree','administration'=>'t-admin','destruction'=>'t-destr','casse'=>'t-casse','perte'=>'t-casse'];

require_once 'includes/header.php';
?>
<style>
#stx{padding:0 0 30px;font-family:"Plus Jakarta Sans",system-ui,sans-serif}
#stx .num{font-variant-numeric:tabular-nums}
#stx .top{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:6px}
#stx .brand{display:flex;align-items:center;gap:13px}
#stx .ic-tile{width:46px;height:46px;border-radius:14px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(150deg,#5b9bff,var(--primary));color:#fff;font-size:22px;box-shadow:0 6px 16px -6px var(--primary)}
#stx .eyebrow{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--primary);font-weight:700;margin:0 0 3px}
#stx h1{font-size:26px;font-weight:800;margin:0;letter-spacing:-.02em;color:var(--text)}
#stx .sub{color:var(--text2);font-size:13.5px;margin:3px 0 0}
#stx .selbox{display:flex;align-items:center;gap:8px;background:var(--bg2);border:1px solid var(--border);border-radius:11px;padding:7px 11px;font-size:13.5px;font-weight:600}
#stx select.psel{border:1px solid var(--border);background:var(--bg2);border-radius:9px;padding:8px 12px;font-family:inherit;font-weight:600;color:var(--text);font-size:13.5px;cursor:pointer}
#stx .pill{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:999px;line-height:1.6}
#stx .pill.ok{color:#1D9E75;background:rgba(29,158,117,.14)} #stx .pill.warn{color:#C77C06;background:rgba(239,159,39,.16)} #stx .pill.crit{color:#E24B4A;background:rgba(226,75,74,.14)} #stx .pill.role{color:var(--primary);background:rgba(59,130,246,.12)} #stx .pill.mut{color:var(--text2);background:var(--bg3)}
#stx .flash{padding:12px 15px;border-radius:12px;margin:10px 0;font-size:13.5px;font-weight:600;border:1px solid}
#stx .flash.ok{background:rgba(29,158,117,.10);color:#1D9E75;border-color:rgba(29,158,117,.3)} #stx .flash.warn{background:rgba(239,159,39,.12);color:#C77C06;border-color:rgba(239,159,39,.3)} #stx .flash.crit{background:rgba(226,75,74,.10);color:#E24B4A;border-color:rgba(226,75,74,.3)}
#stx .actions{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 4px}
#stx .action{display:inline-flex;gap:7px;align-items:center;background:var(--glass);border:1px solid var(--border);border-radius:9px;padding:8px 14px;cursor:pointer;font-family:inherit;font-weight:700;color:var(--text2);font-size:13.5px;transition:.15s}
#stx .action:hover{border-color:var(--primary);color:var(--primary)}
#stx .action .ai{font-size:15px}
#stx .action.on{background:var(--primary);border-color:var(--primary);color:#fff}
#stx .action .t{font-weight:700}
#stx .view{display:none} #stx .view.show{display:block}
#stx .pub{display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius);padding:15px 18px;margin:0 0 4px;box-shadow:var(--shadow)}
#stx .pub .eye{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--text3);font-weight:700}
#stx .pub .big{font-size:28px;font-weight:800;letter-spacing:-.02em} #stx .pub .big small{font-size:14px;font-weight:600;color:var(--text2)}
#stx .pub .rt{margin-left:auto;font-size:12.5px;color:var(--text2);max-width:38ch;border-left:1px solid var(--border);padding-left:16px}
#stx .section-h{display:flex;align-items:center;gap:11px;margin:26px 0 12px;flex-wrap:wrap}
#stx .section-h h2{font-size:18px;font-weight:800;margin:0} #stx .section-h .line{flex:1;height:1px;background:var(--border);min-width:20px} #stx .hint{font-size:12.5px;color:var(--text2)}
#stx .panel{background:var(--bg3);border:1px solid var(--border);border-radius:16px;overflow:hidden}
#stx .panel-h{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:transparent}
#stx .panel-h .ic{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;background:rgba(59,130,246,.14);color:var(--primary);font-size:16px}
#stx .panel-h h3{margin:0;font-size:15px;font-weight:800} #stx .panel-b{padding:18px}
#stx .ledger-scroll{overflow-x:auto}
#stx table{border-collapse:collapse;width:100%;min-width:820px;font-size:13.5px}
#stx thead th{text-align:left;font-size:10.5px;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);font-weight:700;padding:12px 14px;background:transparent;border-bottom:1px solid var(--border);white-space:nowrap}
#stx thead th.r,#stx tbody td.r{text-align:right}
#stx tbody td{padding:12px 14px;border-bottom:1px solid var(--border);vertical-align:top}
#stx tbody tr td:first-child{border-left:3px solid transparent}
#stx tr.t-entree td:first-child{border-left-color:#1D9E75} #stx tr.t-admin td:first-child{border-left-color:var(--primary)} #stx tr.t-destr td:first-child{border-left-color:#E24B4A} #stx tr.t-casse td:first-child{border-left-color:#C77C06}
#stx .date b{color:var(--text);font-weight:700;display:block;font-size:13px} #stx .date{white-space:nowrap;color:var(--text2)}
#stx .mv{font-weight:700} #stx .who{color:var(--text2);font-size:12px;margin-top:2px}
#stx .solde{font-weight:800;font-size:15px} #stx .delta.pos{color:#1D9E75;font-weight:700} #stx .delta.neg{color:#E24B4A;font-weight:700}
#stx .rel{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;white-space:nowrap} #stx .rel .b{background:var(--bg3);border-radius:6px;padding:1px 8px;font-weight:700} #stx .rel .arw{color:var(--text3)} #stx .rel .destr{color:#C77C06;font-weight:700} #stx .rel .full{color:#1D9E75;font-weight:700}
#stx tr.period-band td{padding:9px 16px;font-size:12px;font-weight:700;border-left:none!important;background:var(--bg2);color:var(--text2)}
#stx tr.period-band.current td{background:rgba(239,159,39,.10);color:#C77C06}
#stx tr.period-band .lk{display:inline-flex;align-items:center;gap:4px;font-size:10px;text-transform:uppercase;letter-spacing:.05em;font-weight:800;padding:2px 8px;border-radius:999px;margin-right:8px}
#stx tr.period-band.validated .lk{color:#1D9E75;background:rgba(29,158,117,.14)} #stx tr.period-band.current .lk{color:#C77C06;background:rgba(239,159,39,.16)}
#stx .empty{padding:40px 20px;text-align:center;color:var(--text3);font-size:14px}
#stx .locked-note{padding:26px 20px;text-align:center;color:var(--text2)}
#stx .shifts{display:grid;grid-template-columns:1fr 1fr;gap:14px} @media(max-width:560px){#stx .shifts{grid-template-columns:1fr}}
#stx .shift{border:1px solid var(--border);border-radius:13px;padding:14px 15px;background:var(--bg2)} #stx .shift.todo{border-style:dashed;background:var(--bg3)}
#stx .shift .shd{display:flex;align-items:center;gap:8px;font-weight:800;font-size:14px;margin-bottom:11px} #stx .shift .shd .hh{margin-left:auto;font-size:12px;color:var(--text3);font-weight:600}
#stx .cmp{display:flex;align-items:center;justify-content:space-between;font-size:13px;padding:5px 0;color:var(--text2)} #stx .cmp .n{color:var(--text);font-weight:800;font-size:15px;font-variant-numeric:tabular-nums}
#stx .shift .st{margin-top:11px;display:flex;align-items:center;gap:9px;flex-wrap:wrap}
#stx .mand{font-size:12.5px;color:var(--text2);margin:15px 0 0;display:flex;align-items:center;gap:7px} #stx .mand b{color:#C77C06}
#stx .alert{margin-top:16px;display:flex;gap:12px;align-items:flex-start;background:rgba(226,75,74,.10);border:1px solid rgba(226,75,74,.3);border-radius:12px;padding:13px 15px} #stx .alert .body{font-size:13px} #stx .alert .body b{color:#E24B4A} #stx .alert .body .mut{color:var(--text2)}
#stx .field{margin-bottom:14px} #stx .field label{display:block;font-size:12px;font-weight:700;color:var(--text2);margin-bottom:5px} #stx .field label .opt{color:var(--text3);font-weight:500}
#stx .field input,#stx .field select{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg);color:var(--text);font-family:inherit;font-size:14px}
#stx .field input:focus{outline:2px solid var(--primary);outline-offset:1px;border-color:transparent}
#stx .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px} @media(max-width:520px){#stx .row2{grid-template-columns:1fr}}
#stx .amp-config{background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:12px 14px;margin-bottom:16px;display:flex;align-items:center;gap:12px}
#stx .amp-ic{width:34px;height:34px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:17px;background:rgba(59,130,246,.14);color:var(--primary)}
#stx .amp-t{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--text3);font-weight:800} #stx .amp-v{font-size:14px;color:var(--text2)} #stx .amp-v b{color:var(--text);font-weight:800}
#stx .relbox{background:rgba(239,159,39,.12);border:1px solid rgba(239,159,39,.3);border-radius:12px;padding:13px 15px;margin:6px 0 16px}
#stx .relbox .lbl{font-size:11px;text-transform:uppercase;color:#C77C06;font-weight:800;margin-bottom:8px}
#stx .relflow{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap} #stx .relflow .seg{display:flex;flex-direction:column;gap:2px} #stx .relflow .seg .t{font-size:10.5px;text-transform:uppercase;color:var(--text2);font-weight:700} #stx .relflow .seg .n{font-weight:800;font-size:19px;font-variant-numeric:tabular-nums} #stx .relflow .op{color:var(--text3);font-size:18px;padding-bottom:3px} #stx .relflow .seg.out .n{color:#C77C06} #stx .relflow .u{font-size:12px;color:var(--text2);font-weight:600}
#stx .rel-sub{margin-top:10px;font-size:12px;color:var(--text2);border-top:1px dashed rgba(239,159,39,.3);padding-top:9px} #stx .rel-sub b{color:var(--text)}
#stx .stx-btn{display:inline-flex;align-items:center;gap:7px;padding:11px 18px;border-radius:10px;border:none;cursor:pointer;font-family:inherit;font-size:14px;font-weight:700;background:var(--primary);color:#fff;box-shadow:0 4px 14px -5px var(--primary)}
#stx .stx-btn:hover{background:#2563EB} #stx .stx-btn.ghost{background:var(--bg3);color:var(--text);border:1px solid var(--border);box-shadow:none} #stx .stx-btn.lg{padding:13px 22px;font-size:15px}
#stx .form-foot{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:4px} #stx .form-foot .note{font-size:11.5px;color:var(--text3)}
#stx .seg-choice{display:inline-flex;border:1px solid var(--border);border-radius:10px;overflow:hidden} #stx .seg-choice button{padding:9px 18px;border:none;background:var(--bg3);color:var(--text2);font-family:inherit;font-size:13.5px;font-weight:700;cursor:pointer} #stx .seg-choice button+button{border-left:1px solid var(--border)} #stx .seg-choice button.on{background:var(--primary);color:#fff}
#stx .warn-note{display:flex;gap:10px;align-items:flex-start;background:rgba(239,159,39,.12);border:1px solid rgba(239,159,39,.3);border-radius:11px;padding:12px 14px;font-size:12.5px;color:var(--text2);margin:4px 0 16px} #stx .warn-note b{color:var(--text)}
#stx .val-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px} @media(max-width:720px){#stx .val-grid{grid-template-columns:1fr}}
#stx .tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:10px} @media(max-width:420px){#stx .tiles{grid-template-columns:repeat(2,1fr)}}
#stx .tile{background:var(--bg2);border:1px solid var(--border);border-radius:11px;padding:11px 12px} #stx .tile .t{font-size:10px;text-transform:uppercase;color:var(--text3);font-weight:700} #stx .tile .n{font-weight:800;font-size:19px;margin-top:3px;font-variant-numeric:tabular-nums} #stx .tile.hl{border-color:rgba(59,130,246,.35);background:rgba(59,130,246,.08)} #stx .tile.hl .n{color:var(--primary)}
#stx .mini-ttl{font-size:11px;text-transform:uppercase;color:var(--text3);font-weight:800;margin:0 0 10px}
#stx .val-foot{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)} #stx .val-foot .note{font-size:12px;color:var(--text2);max-width:360px} #stx .val-foot .note b{color:var(--text)}
#stx .note-banner{margin-top:26px;background:var(--bg3);border:1px solid var(--border);border-left:3px solid var(--primary);border-radius:13px;padding:16px 18px} #stx .note-banner h4{margin:0 0 9px;font-size:13.5px;font-weight:800} #stx .note-banner ul{margin:0;padding-left:20px;color:var(--text2);font-size:13px} #stx .note-banner li{margin:5px 0} #stx .note-banner b{color:var(--text)}
</style>

<div id="stx">

  <div class="page-header">
    <div class="page-title-group">
      <div class="page-title">🔒 Stupéfiants</div>
      <div class="page-sub">Coffre sécurisé, registre comptable et contrôles de garde</div>
    </div>
    <?php if ($stups): ?>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <select class="psel" onchange="if(this.value)location.href='stupefiants.php?p='+this.value">
        <?php foreach ($stups as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $s['id']==$pid?'selected':'' ?>>💊 <?= h($s['dci'] ?: $s['nom']) ?><?= $s['dose_mg']!==null ? ' — '.$pxQ($s['dose_mg']).' mg' : '' ?> · <?= h($s['emp_nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($stxFlash && is_array($stxFlash)): ?><div class="flash <?= h((string)($stxFlash[0] ?? 'ok')) ?>"><?= h((string)($stxFlash[1] ?? '')) ?></div><?php endif; ?>

  <?php if (!$stups): ?>
    <div class="panel"><div class="empty">Aucun stupéfiant enregistré sur ce site.<br><br>Crée un médicament dans <b>Médicaments</b>, coche <b>🔒 Stupéfiant</b> et place-le dans un emplacement <b>coffre</b>.</div></div>
  <?php else: ?>

  <!-- Boutons -->
  <div class="actions">
    <button class="action" data-view="registre"><span class="ai">🔒</span><span class="t">Registre</span></button>
    <button class="action" data-view="garde"><span class="ai">🕓</span><span class="t">Prise de garde</span></button>
    <button class="action" data-view="admin"><span class="ai">💉</span><span class="t">J'ai administré</span></button>
    <button class="action" data-view="casse"><span class="ai">💥</span><span class="t">Casse / perte</span></button>
    <?php if ($canValider): ?><button class="action" data-view="valider"><span class="ai">🛡️</span><span class="t">Valider la période</span></button><?php endif; ?>
  </div>

  <!-- Référence publique -->
  <div class="pub">
    <div>
      <div class="eye">👁️ Visible par tous · dernière validation</div>
      <div class="big num"><?= $lastVal ? $pxQ($lastVal['solde_reference']).' <small>ampoules</small>' : '—' ?></div>
    </div>
    <div class="rt"><?php if ($lastVal): ?><b>Référence <?= h($moisFr($lastVal['periode'])) ?></b>, validée par <?= h($lastVal['agent'] ?: 'admin') ?>.<?php else: ?>Aucune période validée pour l'instant.<?php endif; ?><br>Le détail du registre reste <b>réservé aux rôles autorisés</b>.</div>
  </div>

  <!-- REGISTRE -->
  <section class="view show" id="view-registre">
    <div class="section-h"><h2>Registre chronologique</h2><div class="line"></div><span class="hint">Solde du coffre : <b><?= $pxQ($prod['quantite']) ?></b> ampoule(s)</span></div>
    <?php if (!$canRegistre): ?>
      <div class="panel"><div class="locked-note">🔒 <b>Registre réservé.</b><br>Seul le dernier solde validé ci-dessus t'est visible. Le détail est accessible aux rôles autorisés par l'administrateur.</div></div>
    <?php else: ?>
      <?php if ($canEntree): ?>
      <div class="panel" style="margin-bottom:14px"><div class="panel-b" style="padding:14px 16px">
        <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
          <?= csrfField() ?><input type="hidden" name="action" value="entree"><input type="hidden" name="produit_id" value="<?= $pid ?>">
          <div class="field" style="margin:0"><label>➕ Entrée / réappro (ampoules)</label><input class="num" type="number" name="ampoules" value="1" min="1" step="1" style="width:120px"></div>
          <div class="field" style="margin:0"><label>N° de lot</label><input class="num" name="lot" placeholder="M4482"></div>
          <div class="field" style="margin:0"><label>Péremption</label><input type="date" name="date_exp"></div>
          <button class="btn btn-ghost" type="submit">Ajouter au coffre</button>
        </form>
      </div></div>
      <?php endif; ?>
      <div class="panel"><div class="ledger-scroll"><table>
        <thead><tr><th>Date</th><th>Mouvement</th><th>Détail</th><th class="r">Qté</th><th class="r">Solde</th><th>Reliquat</th><th>Agent</th></tr></thead>
        <tbody>
        <?php if (!$registre): ?>
          <tr><td colspan="7" class="empty" style="border-left:none">Aucun mouvement pour l'instant.</td></tr>
        <?php else: $lastP=null; foreach ($registre as $row): if ($row['periode']!==$lastP): $lastP=$row['periode']; $isVal=in_array($row['periode'],$valPeriods,true); ?>
          <tr class="period-band <?= $isVal?'validated':'current' ?>"><td colspan="7"><span class="lk"><?= $isVal?'🔒 Verrouillé':'⏳ En cours' ?></span><?= h($moisFr($row['periode'])) ?></td></tr>
        <?php endif; ?>
          <tr class="<?= $mvClass[$row['type']] ?? '' ?>">
            <td class="date"><b><?= date('d/m', strtotime($row['cree_le'])) ?></b><?= date('H:i', strtotime($row['cree_le'])) ?></td>
            <td class="mv"><?= h($mvLabel[$row['type']] ?? $row['type']) ?></td>
            <td>
              <?php if ($row['intervention_ref']): ?>Interv. <span class="num"><?= h($row['intervention_ref']) ?></span><?php endif; ?>
              <?php if ($row['motif']): ?><?= h($row['motif']) ?><?php endif; ?>
              <?php $meta=array_filter([$row['lot']?'Lot '.$row['lot']:'', $row['date_exp']?'exp. '.date('m/Y',strtotime($row['date_exp'])):'']); ?>
              <?php if ($meta): ?><div class="who"><?= h(implode(' · ',$meta)) ?></div><?php endif; ?>
            </td>
            <td class="r num delta <?= $row['qte_ampoules']>=0?'pos':'neg' ?>"><?= ($row['qte_ampoules']>0?'+':'').$pxQ($row['qte_ampoules']) ?></td>
            <td class="r num solde"><?= $pxQ($row['solde_apres']) ?></td>
            <td>
              <?php if ($row['type']==='administration' && $row['ouvert_mg']!==null): ?>
                <span class="rel"><span class="b"><?= $pxQ($row['ouvert_mg']) ?> mg</span><span class="arw">→</span><span class="b"><?= $pxQ($row['admin_mg']) ?> mg</span><span class="arw">→</span><?php if((float)$row['detruit_mg']>0): ?><span class="destr"><?= $pxQ($row['detruit_mg']) ?> mg détruits</span><?php else: ?><span class="full">dose entière</span><?php endif; ?></span>
              <?php elseif ($row['detruit_mg']!==null && (float)$row['detruit_mg']>0): ?>
                <span class="rel"><span class="destr"><?= $pxQ($row['detruit_mg']) ?> mg détruits</span></span>
              <?php else: ?><span class="who">—</span><?php endif; ?>
            </td>
            <td><?= h($row['agent'] ?: '—') ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div></div>
    <?php endif; ?>
  </section>

  <!-- PRISE DE GARDE -->
  <section class="view" id="view-garde">
    <div class="section-h"><h2>Contrôle de prise de garde</h2><div class="line"></div><span class="hint">Comptage physique · écart ⇒ alerte admin</span></div>
    <div class="panel"><div class="panel-h"><span class="ic">🕓</span><h3>Comptage — <?= date('d/m/Y') ?></h3></div><div class="panel-b">
      <div class="shifts">
        <?php foreach (['matin'=>'🌅 Prise de garde · matin','soir'=>'🌙 Relève · soir'] as $mk=>$ml): $ct=$ctrlToday[$mk]??null; ?>
        <div class="shift <?= $ct?'':'todo' ?>">
          <div class="shd"><?= $ml ?> <span class="hh"><?= $mk==='matin'?'07:00':'19:00' ?></span></div>
          <?php if ($ct): ?>
            <div class="cmp"><span>Solde théorique</span><span class="n"><?= $pxQ($ct['theorique']) ?></span></div>
            <div class="cmp"><span>Compté</span><span class="n" style="color:var(--primary)"><?= $pxQ($ct['compte']) ?></span></div>
            <div class="cmp"><span>Écart</span><span class="n" style="color:<?= abs($ct['ecart'])<0.001?'#1D9E75':'#E24B4A' ?>"><?= $pxQ($ct['ecart']) ?></span></div>
            <div class="st"><?php if($ct['statut']==='conforme'): ?><span class="pill ok">✓ Conforme</span><?php else: ?><span class="pill crit">⚠️ Anomalie</span><?php endif; ?></div>
          <?php else: ?>
            <div class="cmp"><span>Solde théorique</span><span class="n"><?= $pxQ($prod['quantite']) ?></span></div>
            <form method="post" style="margin-top:8px;display:flex;gap:8px;align-items:flex-end">
              <?= csrfField() ?><input type="hidden" name="action" value="garde"><input type="hidden" name="produit_id" value="<?= $pid ?>"><input type="hidden" name="moment" value="<?= $mk ?>">
              <div class="field" style="margin:0;flex:1"><label>Compté physiquement</label><input class="num" type="number" name="compte" step="0.5" min="0" required placeholder="<?= $pxQ($prod['quantite']) ?>"></div>
              <button class="btn btn-primary" type="submit">Valider</button>
            </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <p class="mand">🔒 <b>Comptage physique obligatoire</b> à chaque relève.</p>
      <?php if ($lastAnomalie): ?>
      <div class="alert"><span style="font-size:19px">⚠️</span><div class="body"><b>Écart signalé — <?= date('d/m H:i', strtotime($lastAnomalie['cree_le'])) ?> (<?= h($lastAnomalie['moment']) ?>)</b> : théorique <span class="num"><?= $pxQ($lastAnomalie['theorique']) ?></span>, compté <span class="num"><?= $pxQ($lastAnomalie['compte']) ?></span> → <b><?= $pxQ($lastAnomalie['ecart']) ?></b>.<br><span class="mut">Administrateur notifié — à régulariser.</span></div></div>
      <?php endif; ?>
    </div></div>
  </section>

  <!-- ADMINISTRATION -->
  <section class="view" id="view-admin">
    <div class="section-h"><h2>Parcours « J'ai administré »</h2><div class="line"></div><span class="hint">Le reliquat se calcule tout seul</span></div>
    <div class="panel"><div class="panel-h"><span class="ic">💉</span><h3>Déclarer une administration</h3></div><div class="panel-b">
      <form method="post" id="admform">
        <?= csrfField() ?><input type="hidden" name="action" value="administer"><input type="hidden" name="produit_id" value="<?= $pid ?>">
        <div class="amp-config"><span class="amp-ic">💧</span><div><div class="amp-t">Ampoule configurée</div><div class="amp-v"><b><?= $prod['dose_mg']!==null?$pxQ($prod['dose_mg']).' mg':'(dose non configurée)' ?><?= $prod['volume_ml']!==null?' / '.$pxQ($prod['volume_ml']).' mL':'' ?></b> par ampoule</div></div></div>
        <div class="field"><label>N° d'intervention</label><input class="num" name="intervention_ref" placeholder="2026-0431"></div>
        <div class="row2">
          <div class="field"><label>N° de lot</label><input class="num" name="lot" placeholder="M4482"></div>
          <div class="field"><label>Péremption (exp.)</label><input type="date" name="date_exp"></div>
        </div>
        <div class="row2">
          <div class="field"><label>Ampoules ouvertes</label><input class="num" id="f-amp" type="number" name="ampoules" value="1" min="1" step="1"></div>
          <div class="field"><label>Administré au patient (mg)</label><input class="num" id="f-adm" type="number" name="admin_mg" value="" step="0.5" min="0" placeholder="mg"></div>
        </div>
        <div class="relbox" data-dose="<?= $prod['dose_mg']!==null?$pxQ($prod['dose_mg']):'' ?>">
          <div class="lbl">↳ Reliquat à détruire — calcul automatique</div>
          <div class="relflow">
            <div class="seg"><span class="t">Ouvert</span><span class="n" id="r-ouv">0</span></div><span class="op">−</span>
            <div class="seg"><span class="t">Administré</span><span class="n" id="r-adm">0</span></div><span class="op">=</span>
            <div class="seg out"><span class="t">Détruit</span><span class="n" id="r-detr">0</span></div><span class="u">mg</span>
          </div>
          <div class="rel-sub" id="r-sub">Renseigne les ampoules et la dose administrée.</div>
        </div>
        <div class="form-foot"><button class="btn btn-primary" type="submit" data-confirm>🔒 Enregistrer au registre</button><span class="note">Écriture définitive · −1 ampoule</span></div>
      </form>
    </div></div>
  </section>

  <!-- CASSE / PERTE -->
  <section class="view" id="view-casse">
    <div class="section-h"><h2>Déclarer une casse / perte</h2><div class="line"></div><span class="hint">Ampoule cassée, souillée ou perdue</span></div>
    <div class="panel"><div class="panel-h"><span class="ic">💥</span><h3>Casse / perte d'ampoule</h3></div><div class="panel-b">
      <form method="post" id="casseform">
        <?= csrfField() ?><input type="hidden" name="action" value="casse"><input type="hidden" name="produit_id" value="<?= $pid ?>"><input type="hidden" name="motif_type" id="motif_type" value="casse">
        <div class="field"><label>Motif</label><div class="seg-choice" id="casse-motif"><button type="button" class="on" data-m="casse">💥 Casse / bris</button><button type="button" data-m="perte">📉 Perte</button></div></div>
        <div class="row2">
          <div class="field"><label>N° de lot</label><input class="num" name="lot" placeholder="M4482"></div>
          <div class="field"><label>Péremption (exp.)</label><input type="date" name="date_exp"></div>
        </div>
        <div class="field"><label>Nombre d'ampoules</label><input class="num" type="number" name="ampoules" value="1" min="1" step="1" style="max-width:160px"></div>
        <div class="field"><label>Circonstances <span class="opt">— obligatoire</span></label><input name="circonstances" required placeholder="Ex : ampoule tombée à l'ouverture"></div>
        <div class="warn-note">⚠️ <div>L'ampoule est <b>détruite</b> (casse) ou déclarée <b>perdue</b>, le coffre est <b>débité</b> et l'<b>administrateur notifié</b>.</div></div>
        <div class="form-foot"><button class="btn btn-primary" type="submit" data-confirm>🔒 Déclarer et débiter</button><span class="note">−1 ampoule · tracé au registre</span></div>
      </form>
    </div></div>
  </section>

  <!-- VALIDATION -->
  <?php if ($canValider): ?>
  <section class="view" id="view-valider">
    <div class="section-h"><h2>Validation périodique</h2><div class="line"></div><span class="hint">Devient la référence visible par tous</span></div>
    <div class="panel"><div class="panel-h"><span class="ic">🛡️</span><h3>Clôturer &amp; valider — <?= h($moisFr(date('Y-m'))) ?></h3></div><div class="panel-b">
      <div class="val-grid">
        <div><p class="mini-ttl">Récapitulatif de la période</p><div class="tiles">
          <div class="tile"><div class="t">Mouvements</div><div class="n"><?= (int)$stats['nb'] ?></div></div>
          <div class="tile"><div class="t">Entrées</div><div class="n" style="color:#1D9E75">+<?= $pxQ($stats['entrees']) ?></div></div>
          <div class="tile"><div class="t">Sorties</div><div class="n" style="color:#E24B4A">−<?= $pxQ($stats['sorties']) ?></div></div>
          <div class="tile hl"><div class="t">Solde actuel</div><div class="n"><?= $pxQ($prod['quantite']) ?></div></div>
        </div></div>
        <div><p class="mini-ttl">Points de contrôle</p><ul class="note-banner" style="list-style:none;margin:0;padding:0;border:none;box-shadow:none">
          <li style="padding:6px 0">✅ Reliquats tracés au registre</li>
          <li style="padding:6px 0">✅ Contrôles de prise de garde enregistrés</li>
          <li style="padding:6px 0">✅ Vérifie qu'aucun écart n'est en anomalie</li>
        </ul></div>
      </div>
      <form method="post" class="val-foot">
        <?= csrfField() ?><input type="hidden" name="action" value="valider"><input type="hidden" name="produit_id" value="<?= $pid ?>"><input type="hidden" name="periode" value="<?= date('Y-m') ?>">
        <button class="btn btn-primary" type="submit" data-confirm>🔒 Valider et verrouiller <?= h($moisFr(date('Y-m'))) ?></button>
        <span class="note">Publie le <b>solde de référence (<?= $pxQ($prod['quantite']) ?>)</b> visible par tous. Signé <b><?= h(trim(($_SESSION['prenom']??'').' '.($_SESSION['nom']??'')) ?: 'admin') ?></b>.</span>
      </form>
    </div></div>
  </section>
  <?php endif; ?>

  <div class="note-banner">
    <h4>⚖️ Ce que ce module garantit</h4>
    <ul>
      <li><b>Registre confidentiel</b> — visible seulement par les rôles autorisés ; les autres ne voient que le dernier solde validé.</li>
      <li><b>Reliquat tracé</b> · <b>casse/perte déclarée à part</b> · <b>prise de garde</b> matin/soir avec alerte admin sur écart.</li>
      <li><b>Solde recalculé</b> à chaque écriture · registre <b>append-only</b> (correction = écriture inverse).</li>
    </ul>
  </div>

  <?php endif; /* stups */ ?>
</div>

<script>
(function(){
  var btns=[].slice.call(document.querySelectorAll('#stx .action[data-view]'));
  var views={registre:'view-registre',garde:'view-garde',admin:'view-admin',casse:'view-casse',valider:'view-valider'};
  function show(v){ btns.forEach(function(b){b.classList.toggle('on',b.dataset.view===v);}); Object.keys(views).forEach(function(k){var el=document.getElementById(views[k]); if(el) el.classList.toggle('show',k===v);}); }
  btns.forEach(function(b){ b.addEventListener('click',function(){ show(b.dataset.view); }); });
  if(btns.length) show(btns[0].dataset.view);

  // reliquat live
  var box=document.querySelector('#stx .relbox');
  if(box){
    var dose=parseFloat(box.getAttribute('data-dose'))||0;
    var amp=document.getElementById('f-amp'), adm=document.getElementById('f-adm');
    var ro=document.getElementById('r-ouv'), ra=document.getElementById('r-adm'), rd=document.getElementById('r-detr'), sub=document.getElementById('r-sub');
    function fr(n){return (Math.round(n*100)/100).toString().replace('.',',');}
    function upd(){ var n=parseInt(amp.value)||0, a=parseFloat(adm.value)||0, ouv=dose*n; if(a>ouv)a=ouv; var d=Math.round((ouv-a)*100)/100; ro.textContent=fr(ouv); ra.textContent=fr(a); rd.textContent=fr(d); rd.style.color=d>0?'#C77C06':'#1D9E75'; sub.innerHTML='<b>'+n+' ampoule'+(n>1?'s':'')+'</b> = <b>'+fr(ouv)+' mg</b> · débit coffre <b>−'+n+'</b>'; }
    if(amp&&adm){ amp.addEventListener('input',upd); adm.addEventListener('input',upd); upd(); }
  }
  // motif casse
  var seg=document.getElementById('casse-motif'), mt=document.getElementById('motif_type');
  if(seg){ seg.querySelectorAll('button').forEach(function(x){ x.addEventListener('click',function(){ seg.querySelectorAll('button').forEach(function(y){y.classList.remove('on');}); x.classList.add('on'); if(mt) mt.value=x.dataset.m; }); }); }
  // confirmation avant écriture
  document.querySelectorAll('#stx button[data-confirm]').forEach(function(b){
    b.addEventListener('click',function(e){ if(!confirm('Écriture définitive au registre. Confirmer ?')){ e.preventDefault(); } });
  });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
