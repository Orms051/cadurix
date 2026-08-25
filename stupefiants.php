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
$canCoffre   = stx_niveau($pdo, 'stupefiants_coffre') >= 1;
$canEntree   = $canValider || peut_faire(ROLE_GESTIONNAIRE);
$canAddStup  = $canCoffre || peut_faire(ROLE_GESTIONNAIRE);
if (!$canStup) { accessDenied(); exit; }

// ── Création d'un coffre (n'exige pas de produit sélectionné) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'coffre_create') {
    csrf_check();
    $nom = trim($_POST['coffre_nom'] ?? '');
    if ($canCoffre && $nom !== '') {
        try { $pdo->prepare("INSERT INTO emplacements (site_id,parent_id,nom,type,actif) VALUES(?,?,?,?,1)")->execute([$site_id, null, $nom, 'coffre']); } catch (Exception $e) {}
        header('Location: stupefiants.php?v=coffre&m=coffre'); exit;
    }
    header('Location: stupefiants.php?v=coffre&m=err'); exit;
}

// ── Ajout d'un stupéfiant dans un coffre (le stup se gère ici, pas dans Médicaments) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stup_create') {
    csrf_check();
    $coffre_id = (int)($_POST['coffre_id'] ?? 0);
    $nom = trim($_POST['nom'] ?? ''); $dci = trim($_POST['dci'] ?? '');
    $okCoffre = false;
    if ($coffre_id) { $q = $pdo->prepare("SELECT 1 FROM emplacements WHERE id=? AND site_id=? AND type='coffre'"); $q->execute([$coffre_id, $site_id]); $okCoffre = (bool)$q->fetchColumn(); }
    if ($canAddStup && $okCoffre && ($dci !== '' || $nom !== '')) {
        if ($nom === '') $nom = $dci;
        $doseMg = ($_POST['dose_mg'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['dose_mg']) : null;
        $volMl  = ($_POST['volume_ml'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['volume_ml']) : null;
        $qte    = max(0, (float)str_replace(',', '.', $_POST['quantite'] ?? '0'));
        $lot    = trim($_POST['lot'] ?? '') ?: null;
        $exp    = ($_POST['date_exp'] ?? '') !== '' ? $_POST['date_exp'] : null;
        $now    = date('Y-m-d H:i:s'); $uid = currentUserId();
        try {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO produits (emplacement_id,nom,dci,type_produit,stupefiant,dose_mg,volume_ml,quantite,unite,numero_lot,date_peremption,alerte_jours,cree_par,statut) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$coffre_id, $nom, $dci ?: null, 'medicament', 1, $doseMg, $volMl, $qte, 'ampoule', $lot, $exp, 30, $uid, 'actif']);
            $newId = (int)$pdo->lastInsertId();
            if ($qte > 0) {
                $pdo->prepare("INSERT INTO stupefiants_registre (produit_id,site_id,type,qte_ampoules,solde_apres,lot,date_exp,motif,periode,user_id,cree_le) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$newId, $site_id, 'entree', $qte, $qte, $lot, $exp, 'Stock initial', date('Y-m'), $uid, $now]);
                $pdo->prepare("INSERT INTO mouvements_stock (produit_id,user_id,type,quantite_avant,quantite_delta,quantite_apres,motif) VALUES(?,?,?,?,?,?,?)")
                    ->execute([$newId, $uid, 'entree', 0, $qte, $qte, 'Stupéfiant — stock initial']);
            }
            $pdo->commit();
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); header('Location: stupefiants.php?v=coffre&m=err'); exit; }
        header('Location: stupefiants.php?p=' . $newId . '&v=coffre&m=stup'); exit;
    }
    header('Location: stupefiants.php?v=coffre&m=err'); exit;
}

// ── Suppression d'un coffre (uniquement s'il est vide) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'coffre_delete') {
    csrf_check();
    $cid = (int)($_POST['coffre_id'] ?? 0);
    if ($canCoffre && $cid) {
        $q = $pdo->prepare("SELECT 1 FROM emplacements WHERE id=? AND site_id=? AND type='coffre'"); $q->execute([$cid, $site_id]);
        if ($q->fetchColumn()) {
            $n = $pdo->prepare("SELECT COUNT(*) FROM produits WHERE emplacement_id=? AND statut='actif'"); $n->execute([$cid]);
            if ((int)$n->fetchColumn() === 0) {
                try { $pdo->prepare("DELETE FROM emplacements WHERE id=? AND site_id=? AND type='coffre'")->execute([$cid, $site_id]); } catch (Exception $e) {}
                header('Location: stupefiants.php?v=coffre&m=coffredel'); exit;
            }
            header('Location: stupefiants.php?v=coffre&m=coffrefull'); exit;
        }
    }
    header('Location: stupefiants.php?v=coffre&m=err'); exit;
}

// ── Suppression d'un stupéfiant (produit + son historique registre) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stup_delete') {
    csrf_check();
    $prid = (int)($_POST['produit_id'] ?? 0);
    if ($canCoffre && $prid) {
        $q = $pdo->prepare("SELECT p.id FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.id=? AND p.stupefiant=1 AND e.site_id=? AND e.type='coffre'");
        $q->execute([$prid, $site_id]);
        if ($q->fetchColumn()) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM stupefiants_registre   WHERE produit_id=?")->execute([$prid]);
                $pdo->prepare("DELETE FROM stupefiants_controles   WHERE produit_id=?")->execute([$prid]);
                $pdo->prepare("DELETE FROM stupefiants_validations WHERE produit_id=?")->execute([$prid]);
                $pdo->prepare("DELETE FROM mouvements_stock        WHERE produit_id=?")->execute([$prid]);
                $pdo->prepare("DELETE FROM produits WHERE id=?")->execute([$prid]);
                $pdo->commit();
            } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); header('Location: stupefiants.php?v=coffre&m=err'); exit; }
            header('Location: stupefiants.php?v=coffre&m=stupdel'); exit;
        }
    }
    header('Location: stupefiants.php?v=coffre&m=err'); exit;
}

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

// ── IA : analyse du registre (confidentiel) ──
if (($_GET['ajax'] ?? '') === 'ia_analyse' && $prod) {
    header('Content-Type: application/json');
    if (!$canRegistre) { echo json_encode(['error' => 'Accès refusé.']); exit; }
    $ai_actif = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='actif'")->fetchColumn();
    $api_key  = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='api_key'")->fetchColumn();
    $model    = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='model'")->fetchColumn() ?: 'claude-haiku-4-5-20251001';
    if (!$ai_actif || $ai_actif !== '1' || !$api_key) { echo json_encode(['error' => "IA non configurée (Admin → IA)."]); exit; }
    $reg = $pdo->prepare("SELECT type, qte_ampoules, solde_apres, detruit_mg, motif, cree_le FROM stupefiants_registre WHERE produit_id=? ORDER BY cree_le DESC LIMIT 40");
    $reg->execute([$pid]); $rows = $reg->fetchAll(PDO::FETCH_ASSOC);
    $ctl = $pdo->prepare("SELECT theorique, compte, ecart, statut, cree_le FROM stupefiants_controles WHERE produit_id=? ORDER BY cree_le DESC LIMIT 20");
    $ctl->execute([$pid]); $ctls = $ctl->fetchAll(PDO::FETCH_ASSOC);
    $lignes = []; foreach ($rows as $r) { $lignes[] = $r['cree_le'].' '.$r['type'].' Δ'.$r['qte_ampoules'].' → solde '.$r['solde_apres'].($r['detruit_mg']!==null && (float)$r['detruit_mg']>0?' · détruit '.$r['detruit_mg'].'mg':'').($r['motif']?' ('.$r['motif'].')':''); }
    $lignesC = []; foreach ($ctls as $c) { $lignesC[] = $c['cree_le'].' : théo '.$c['theorique'].' / compté '.$c['compte'].' / écart '.$c['ecart'].' → '.$c['statut']; }
    $nomProd = $prod['dci'] ?: $prod['nom'];
    $prompt = "Tu es un assistant pharmacie pour un service de secours français (SDIS/SSSM). "
        . "Analyse le registre du stupéfiant « $nomProd » (solde actuel " . $pxQ($prod['quantite']) . " ampoules).\n\n"
        . "MOUVEMENTS RÉCENTS :\n" . (implode("\n", $lignes) ?: "(aucun)") . "\n\n"
        . "CONTRÔLES DU COFFRE :\n" . (implode("\n", $lignesC) ?: "(aucun)") . "\n\n"
        . "Fais une synthèse courte et professionnelle (5 à 8 lignes) : consommation, reliquats détruits, écarts de contrôle éventuels, et recommandations concrètes (réappro, points de vigilance). "
        . "Uniquement à partir des données ci-dessus, sans rien inventer ni donner de conseil médical. Pas de titre markdown.";
    $payload = ['model' => $model, 'max_tokens' => 600, 'messages' => [['role' => 'user', 'content' => $prompt]]];
    $is_prod = str_contains($_SERVER['HTTP_HOST'] ?? '', 'cadurix.fr');
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $api_key, 'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT => 40, CURLOPT_SSL_VERIFYPEER => $is_prod, CURLOPT_SSL_VERIFYHOST => $is_prod ? 2 : 0]);
    $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
    if ($resp === false || $cerr) { echo json_encode(['error' => "Réseau IA : $cerr"]); exit; }
    $j = json_decode($resp, true);
    if ($http !== 200 || empty($j['content'][0]['text'])) { echo json_encode(['error' => ($j['error']['message'] ?? "Erreur IA HTTP $http")]); exit; }
    echo json_encode(['ok' => true, 'texte' => $j['content'][0]['text']], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Impressions (registre / déclaration casse-perte) avec logo du site ──
if (isset($_GET['print']) && $canStup && $prod) {
    $mode = $_GET['print'];
    $s = $pdo->prepare("SELECT s.nom AS site_nom, o.nom AS org_nom, o.logo FROM sites s LEFT JOIN organisations o ON o.id=s.organisation_id WHERE s.id=?");
    $s->execute([$site_id]);
    $siteRow = $s->fetch(PDO::FETCH_ASSOC) ?: ['site_nom' => '', 'org_nom' => '', 'logo' => null];
    $logoHtml = !empty($siteRow['logo']) ? '<img src="' . htmlspecialchars($siteRow['logo']) . '" alt="logo">' : '';
    $entete = htmlspecialchars(trim(($siteRow['org_nom'] ?? '') . ' — ' . ($siteRow['site_nom'] ?? ''), ' —'));
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Impression — Stupéfiants</title><style>'
        . '*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,Helvetica,sans-serif;color:#1f2a44;margin:0;padding:0;background:#eef1f7}'
        . '.doc{max-width:820px;margin:22px auto;background:#fff;padding:30px 34px;box-shadow:0 4px 24px rgba(31,42,68,.12)}'
        . '@media print{body{background:#fff}.doc{margin:0;box-shadow:none;padding:0;max-width:none}}'
        . '.dh{display:flex;align-items:center;gap:18px;border-bottom:3px solid #1f2a44;padding-bottom:15px}'
        . '.dh img{max-height:78px;max-width:160px;object-fit:contain}.dh .t{flex:1}.dh h1{margin:0;font-size:22px;letter-spacing:-.3px}'
        . '.dh .site{color:#5a6b8c;font-size:12.5px;font-weight:700;margin-top:3px;text-transform:uppercase;letter-spacing:.05em}'
        . '.dh .ref{text-align:right;font-size:10.5px;color:#8a97b3;line-height:1.5;white-space:nowrap}'
        . '.prodline{font-size:13px;color:#3a465f;margin:14px 0 12px}.prodline b{color:#1f2a44}'
        . '.summary{display:flex;gap:10px;margin:0 0 18px;flex-wrap:wrap}'
        . '.kpi{flex:1;min-width:110px;border:1px solid #dbe3f2;border-radius:10px;padding:9px 12px;background:#f7f9ff}'
        . '.kpi .k{font-size:9px;text-transform:uppercase;letter-spacing:.05em;color:#8a97b3;font-weight:700}.kpi .v{font-size:18px;font-weight:800;margin-top:2px}'
        . 'table{width:100%;border-collapse:collapse;font-size:11.5px}'
        . 'thead th{background:#1f2a44;color:#fff;text-transform:uppercase;font-size:9px;letter-spacing:.04em;padding:8px 9px;text-align:left}thead th.r{text-align:right}'
        . 'tbody td{padding:7px 9px;border-bottom:1px solid #e6eaf3;vertical-align:top}tbody tr:nth-child(even) td{background:#f7f9ff}'
        . 'td.r{text-align:right;font-variant-numeric:tabular-nums}tbody td:first-child{border-left:3px solid transparent;white-space:nowrap}'
        . 'tr.t-entree td:first-child{border-left-color:#1D9E75}tr.t-admin td:first-child{border-left-color:#3B82F6}tr.t-destr td:first-child{border-left-color:#E24B4A}tr.t-casse td:first-child{border-left-color:#C77C06}'
        . '.type{font-weight:700}.who{color:#7a86a3;font-size:10px}.pos{color:#1D9E75;font-weight:700}.neg{color:#E24B4A;font-weight:700}'
        . 'tfoot td{padding:8px 9px;font-weight:800;border-top:2px solid #1f2a44;font-size:11.5px}'
        . '.sign{margin-top:34px;display:flex;gap:44px}.sign .b{flex:1;border-top:1px solid #1f2a44;padding-top:6px;font-size:11.5px;color:#5a6b8c;min-height:54px}'
        . '.faitle{margin-top:26px;font-size:12.5px;color:#3a465f}'
        . '.foot{margin-top:22px;border-top:1px solid #e6eaf3;padding-top:9px;font-size:9.5px;color:#9aa5bf;text-align:center}'
        . '.lead{font-size:14.5px;line-height:1.85;margin:14px 0}.decl-box{border:1px solid #dbe3f2;border-radius:12px;padding:18px 22px;background:#f7f9ff;margin:14px 0}.meta{color:#444;font-size:12px}'
        . '.noprint{margin-bottom:16px}@media print{.noprint{display:none}}.btn{padding:9px 16px;border:none;border-radius:8px;background:#3B82F6;color:#fff;font-weight:700;cursor:pointer;font-size:14px}'
        . '</style></head><body><div class="doc">';

    if ($mode === 'registre' && $canRegistre) {
        $nomP = htmlspecialchars($prod['dci'] ?: $prod['nom']);
        $rr = $pdo->prepare("SELECT r.*, TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) agent FROM stupefiants_registre r LEFT JOIN utilisateurs u ON u.id=r.user_id WHERE r.produit_id=? ORDER BY r.cree_le ASC, r.id ASC");
        $rr->execute([$pid]); $rows = $rr->fetchAll(PDO::FETCH_ASSOC);
        $lbl = ['entree' => 'Entrée', 'administration' => 'Administration', 'destruction' => 'Destruction', 'casse' => 'Casse', 'perte' => 'Perte', 'vol' => 'Vol'];
        $cls = ['entree' => 't-entree', 'administration' => 't-admin', 'destruction' => 't-destr', 'casse' => 't-casse', 'perte' => 't-casse', 'vol' => 't-destr'];
        $tEnt = 0; $tSort = 0; $tDetr = 0;
        foreach ($rows as $r) { if ($r['qte_ampoules'] > 0) $tEnt += $r['qte_ampoules']; else $tSort += abs($r['qte_ampoules']); if ($r['detruit_mg'] !== null) $tDetr += (float)$r['detruit_mg']; }
        echo '<div class="dh">' . $logoHtml . '<div class="t"><h1>Registre des stupéfiants</h1><div class="site">' . $entete . '</div></div><div class="ref">Édité le<br><b>' . date('d/m/Y à H:i') . '</b></div></div>';
        echo '<p class="prodline"><b>Produit suivi :</b> ' . $nomP . ($prod['dose_mg'] !== null ? ' — ' . $pxQ($prod['dose_mg']) . ' mg' : '') . ($prod['volume_ml'] !== null ? ' / ' . $pxQ($prod['volume_ml']) . ' mL' : '') . ' &nbsp;·&nbsp; <b>Coffre :</b> ' . htmlspecialchars($prod['emp_nom'] ?? '') . ' &nbsp;·&nbsp; unité : ampoule</p>';
        echo '<div class="summary">'
            . '<div class="kpi"><div class="k">Solde actuel</div><div class="v">' . $pxQ($prod['quantite']) . '</div></div>'
            . '<div class="kpi"><div class="k">Mouvements</div><div class="v">' . count($rows) . '</div></div>'
            . '<div class="kpi"><div class="k">Entrées</div><div class="v" style="color:#1D9E75">+' . $pxQ($tEnt) . '</div></div>'
            . '<div class="kpi"><div class="k">Sorties</div><div class="v" style="color:#E24B4A">−' . $pxQ($tSort) . '</div></div>'
            . '<div class="kpi"><div class="k">Détruit (mg)</div><div class="v">' . $pxQ($tDetr) . '</div></div>'
            . '</div>';
        echo '<table><thead><tr><th>Date &amp; heure</th><th>Mouvement</th><th>Détail</th><th>Lot / Exp.</th><th class="r">Qté</th><th class="r">Solde</th><th>Reliquat</th><th>Agent</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $det = [];
            if ($r['intervention_ref']) $det[] = 'Interv. ' . $r['intervention_ref'];
            if ($r['motif']) $det[] = $r['motif'];
            $lotCell = htmlspecialchars($r['lot'] ?: '—') . ($r['date_exp'] ? '<span class="who"><br>exp ' . date('m/Y', strtotime($r['date_exp'])) . '</span>' : '');
            $rel = '';
            if ($r['type'] === 'administration' && $r['ouvert_mg'] !== null) $rel = $pxQ($r['ouvert_mg']) . ' → ' . $pxQ($r['admin_mg']) . ' → ' . $pxQ($r['detruit_mg']) . ' mg dét.';
            elseif ($r['detruit_mg'] !== null && (float)$r['detruit_mg'] > 0) $rel = $pxQ($r['detruit_mg']) . ' mg dét.';
            $q = ($r['qte_ampoules'] > 0 ? '+' : '') . $pxQ($r['qte_ampoules']);
            echo '<tr class="' . ($cls[$r['type']] ?? '') . '"><td>' . date('d/m/Y H:i', strtotime($r['cree_le'])) . '</td><td class="type">' . ($lbl[$r['type']] ?? $r['type']) . '</td><td>' . htmlspecialchars(implode(' · ', $det)) . '</td><td>' . $lotCell . '</td><td class="r ' . ($r['qte_ampoules'] >= 0 ? 'pos' : 'neg') . '">' . $q . '</td><td class="r"><b>' . $pxQ($r['solde_apres']) . '</b></td><td>' . htmlspecialchars($rel) . '</td><td>' . htmlspecialchars($r['agent'] ?: '—') . '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="8" style="text-align:center;color:#8a97b3;padding:18px">Aucun mouvement enregistré.</td></tr>';
        echo '</tbody><tfoot><tr><td colspan="4">Totaux</td><td class="r"><span class="pos">+' . $pxQ($tEnt) . '</span> / <span class="neg">−' . $pxQ($tSort) . '</span></td><td class="r">' . $pxQ($prod['quantite']) . '</td><td colspan="2">Détruit : ' . $pxQ($tDetr) . ' mg</td></tr></tfoot></table>';
        echo '<div class="faitle">Fait à ……………………………………………, le ……… / ……… / …………</div>';
        echo '<div class="sign"><div class="b">Le responsable — nom &amp; signature</div><div class="b">Le pharmacien / cadre — nom &amp; signature</div></div>';
        echo '<div class="foot">Registre des stupéfiants — ' . $entete . ' · Document confidentiel, à conserver.</div>';
    } elseif ($mode === 'declaration') {
        $rid = (int)($_GET['id'] ?? 0);
        $dr = $pdo->prepare("SELECT r.*, TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) agent, p.nom pnom, p.dci pdci
            FROM stupefiants_registre r JOIN produits p ON p.id=r.produit_id JOIN emplacements e ON e.id=p.emplacement_id LEFT JOIN utilisateurs u ON u.id=r.user_id
            WHERE r.id=? AND e.site_id=? AND r.type IN ('casse','perte','vol')" . ($canRegistre ? '' : ' AND r.user_id=' . (int)currentUserId()));
        $dr->execute([$rid, $site_id]); $d = $dr->fetch(PDO::FETCH_ASSOC);
        if ($d) {
            $verbe = $d['type'] === 'perte' ? 'perdu' : ($d['type'] === 'vol' ? 'subi le vol de' : 'cassé');
            $titreType = $d['type'] === 'perte' ? 'perte' : ($d['type'] === 'vol' ? 'vol' : 'casse');
            $nb = $pxQ(abs($d['qte_ampoules']));
            $nomP = htmlspecialchars($d['pdci'] ?: $d['pnom']);
            echo '<div class="dh">' . $logoHtml . '<div class="t"><h1>Déclaration de ' . $titreType . ' de stupéfiant</h1><div class="site">' . $entete . '</div></div><div class="ref">Réf. n° ' . (int)$d['id'] . '<br>' . date('d/m/Y', strtotime($d['cree_le'])) . '</div></div>';
            echo '<div class="decl-box">'
                . '<p class="lead" style="margin:0 0 10px">Je soussigné(e) <b>' . htmlspecialchars($d['agent'] ?: '……………………………') . '</b> déclare avoir <b>' . $verbe . '</b> <b>' . $nb . ' ampoule(s)</b> de <b>' . $nomP . '</b>'
                . ($d['lot'] ? ' (lot ' . htmlspecialchars($d['lot']) . ($d['date_exp'] ? ', exp ' . date('m/Y', strtotime($d['date_exp'])) : '') . ')' : '')
                . ', le <b>' . date('d/m/Y à H:i', strtotime($d['cree_le'])) . '</b>.</p>'
                . '<p class="lead" style="margin:0"><b>Circonstances :</b><br>' . nl2br(htmlspecialchars($d['motif'] ?: '………………………………………………………………………………………………')) . '</p>'
                . '</div>';
            echo '<p class="meta">L\'ampoule ' . ($d['type'] === 'perte' ? 'a été déclarée perdue' : ($d['type'] === 'vol' ? 'a été déclarée volée' : 'a été détruite')) . ' et le solde du coffre débité en conséquence. Document à conserver au registre des stupéfiants.</p>';
            echo '<div class="faitle">Fait à ……………………………………………, le ……… / ……… / …………</div>';
            echo '<div class="sign"><div class="b">Le déclarant — nom &amp; signature</div><div class="b">Le responsable — nom &amp; signature</div></div>';
            echo '<div class="foot">Déclaration de ' . $titreType . ' — ' . $entete . ' · Document confidentiel, à conserver.</div>';
        } else { echo '<p>Déclaration introuvable.</p>'; }
    } else { echo '<p>Accès refusé.</p>'; }
    echo '</div></body></html>';
    exit;
}

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
            $motifType = in_array($_POST['motif_type'] ?? 'casse', ['casse','perte','vol'], true) ? $_POST['motif_type'] : 'casse';
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
            $declId = (int)$pdo->lastInsertId();
            $logMouvement(-$amp, $solde, $apres, 'Stupéfiant — ' . $motifType);
            audit_log($pdo, 'STUP_' . strtoupper($motifType), 'produits', $pid, ['solde' => $solde], ['solde' => $apres]);
            $mc = $motifType;
            $redirect .= '&decl=' . $declId;
        }

        elseif ($action === 'garde') {
            $moment = 'controle';
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
  'vol'    => ['warn', 'Vol déclaré et débité. Administrateur notifié.'],
  'gok'    => ['ok',   'Contrôle enregistré (conforme) — horodaté.'],
  'gko'    => ['crit', '⚠️ Écart détecté au contrôle (horodaté) — administrateur notifié.'],
  'entree' => ['ok',   'Entrée enregistrée au coffre.'],
  'valide' => ['ok',   'Période validée et publiée (référence visible par tous).'],
  'coffre' => ['ok',   'Coffre créé.'],
  'stup'   => ['ok',   'Stupéfiant ajouté au coffre.'],
  'stupdel'=> ['ok',   'Stupéfiant supprimé du coffre.'],
  'coffredel'  => ['ok',   'Coffre supprimé.'],
  'coffrefull' => ['warn', 'Coffre non vide : retire d\'abord les stupéfiants qu\'il contient.'],
  'err'    => ['crit', "Erreur lors de l'enregistrement."],
];
$stxFlash = $flashMap[$_GET['m'] ?? ''] ?? null;

$registre = $controles = $monHisto = []; $lastVal = null; $valPeriods = [];
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
    // contrôles récents (horodatés)
    $c = $pdo->prepare("SELECT c.*, TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) AS agent
        FROM stupefiants_controles c LEFT JOIN utilisateurs u ON u.id=c.user_id
        WHERE c.produit_id=? ORDER BY c.cree_le DESC, c.id DESC LIMIT 30");
    $c->execute([$pid]);
    $controles = $c->fetchAll(PDO::FETCH_ASSOC);
    // mon historique (mouvements de l'utilisateur courant)
    $mh = $pdo->prepare("SELECT r.* FROM stupefiants_registre r WHERE r.produit_id=? AND r.user_id=? ORDER BY r.cree_le DESC, r.id DESC LIMIT 60");
    $mh->execute([$pid, currentUserId()]);
    $monHisto = $mh->fetchAll(PDO::FETCH_ASSOC);
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

// Coffres du site + leur contenu (ampoules)
$coffres = [];
try {
    $cf = $pdo->prepare("SELECT id, nom FROM emplacements WHERE site_id=? AND type='coffre' AND actif=1 ORDER BY nom");
    $cf->execute([$site_id]);
    foreach ($cf->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $pp = $pdo->prepare("SELECT id, nom, dci, dose_mg, volume_ml, quantite, date_peremption FROM produits WHERE emplacement_id=? AND stupefiant=1 AND statut='actif' ORDER BY COALESCE(NULLIF(dci,''),nom)");
        $pp->execute([$c['id']]);
        $c['produits'] = $pp->fetchAll(PDO::FETCH_ASSOC);
        $coffres[] = $c;
    }
} catch (Exception $e) {}

$moisFr = function($p){ if(!$p) return ''; $m=['01'=>'Janvier','02'=>'Février','03'=>'Mars','04'=>'Avril','05'=>'Mai','06'=>'Juin','07'=>'Juillet','08'=>'Août','09'=>'Septembre','10'=>'Octobre','11'=>'Novembre','12'=>'Décembre']; [$y,$mo]=explode('-',$p); return ($m[$mo]??$mo).' '.$y; };
$mvLabel = ['entree'=>'Entrée · réappro','administration'=>'Administration','destruction'=>'Destruction','casse'=>'💥 Casse / bris','perte'=>'📉 Perte','vol'=>'🚨 Vol'];
$mvClass = ['entree'=>'t-entree','administration'=>'t-admin','destruction'=>'t-destr','casse'=>'t-casse','perte'=>'t-casse','vol'=>'t-destr'];

$ai_on = false;
if ($canRegistre) { try { $ai_on = ($pdo->query("SELECT valeur FROM ai_settings WHERE cle='actif'")->fetchColumn() === '1'); } catch (Exception $e) {} }

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

/* ── Version mobile (n'affecte que le mobile, le rendu PC reste identique) ── */
@media (max-width: 768px){
  #stx .page-header > div{width:100%;min-width:0}
  #stx select.psel{max-width:100%;width:100%}
  #stx .actions{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:6px;margin:0 -2px 8px}
  #stx .action{flex-shrink:0}
  #stx .pub{flex-direction:column;align-items:flex-start;gap:10px;padding:14px}
  #stx .pub .rt{margin-left:0;border-left:none;padding-left:0;border-top:1px solid var(--border);padding-top:10px;max-width:none;width:100%}
  #stx .pub .big{font-size:24px}
  #stx table{min-width:560px;font-size:12.5px}
  #stx thead th,#stx tbody td{padding:9px 10px}
  #stx .panel-b{padding:14px}
  #stx .section-h{margin:20px 0 10px}
  #stx .section-h h2{font-size:16px}
  #stx .row2{grid-template-columns:1fr}
  #stx .val-grid{grid-template-columns:1fr;gap:14px}
  #stx .relflow{gap:8px}
  #stx .relflow .seg .n{font-size:17px}
}
@media (max-width: 460px){
  #stx table{min-width:480px}
  #stx .tiles{grid-template-columns:repeat(2,1fr)}
  #stx .action{padding:8px 12px;font-size:13px}
  #stx .form-foot{flex-direction:column;align-items:stretch}
  #stx .form-foot .btn{width:100%;justify-content:center}
  #stx .form-foot .note{text-align:center}
}
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

  <!-- Boutons -->
  <div class="actions">
    <?php if ($prod): ?>
    <button class="action" data-view="registre"><span class="ai">🔒</span><span class="t">Registre</span></button>
    <button class="action" data-view="mon"><span class="ai">📄</span><span class="t">Mon historique</span></button>
    <button class="action" data-view="garde"><span class="ai">🕓</span><span class="t">Contrôle</span></button>
    <button class="action" data-view="admin"><span class="ai">💉</span><span class="t">J'ai administré</span></button>
    <button class="action" data-view="casse"><span class="ai">💥</span><span class="t">Casse / perte / vol</span></button>
    <?php if ($canValider): ?><button class="action" data-view="valider"><span class="ai">🛡️</span><span class="t">Valider la période</span></button><?php endif; ?>
    <?php endif; ?>
    <button class="action" data-view="coffre"><span class="ai">🏦</span><span class="t">Coffre</span></button>
  </div>

  <?php if (!$prod): ?>
    <div class="flash warn">Aucun stupéfiant dans un coffre pour l'instant. Ouvre l'onglet <b>🏦 Coffre</b> pour créer un coffre, puis ajoute un médicament coché <b>🔒 Stupéfiant</b> dans ce coffre depuis <b>Médicaments</b>.</div>
  <?php endif; ?>

  <?php if ($prod): ?>

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
      <div style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap">
        <button type="button" class="btn btn-ghost" onclick="stxPrint('stupefiants.php?print=registre&p=<?= $pid ?>')">🖨️ Imprimer le registre</button>
        <?php if ($ai_on): ?><button type="button" class="btn btn-ghost" id="stx-ia-btn" onclick="stxAnalyse()">🤖 Analyser (IA)</button><?php endif; ?>
      </div>
      <?php if ($ai_on): ?><div id="stx-ia-out" style="display:none;margin-bottom:14px" class="panel"><div class="panel-b" style="white-space:pre-wrap;font-size:13.5px;line-height:1.55;color:var(--text)"></div></div><?php endif; ?>
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
            <td class="mv"><?= h($mvLabel[$row['type']] ?? $row['type']) ?><?php if(in_array($row['type'],['casse','perte','vol'],true)): ?> <a href="#" onclick="stxPrint('stupefiants.php?print=declaration&id=<?= (int)$row['id'] ?>');return false" title="Imprimer la déclaration à signer" style="text-decoration:none">🖨️</a><?php endif; ?></td>
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

  <!-- MON HISTORIQUE (chaque utilisateur voit ses propres mouvements) -->
  <section class="view" id="view-mon">
    <div class="section-h"><h2>Mon historique</h2><div class="line"></div><span class="hint">Tes propres mouvements sur ce produit · réimprime tes déclarations</span></div>
    <div class="panel"><div class="ledger-scroll"><table>
      <thead><tr><th>Date &amp; heure</th><th>Mouvement</th><th>Détail</th><th class="r">Qté</th><th>Reliquat</th><th></th></tr></thead>
      <tbody>
      <?php if (!$monHisto): ?>
        <tr><td colspan="6" class="empty" style="border-left:none">Tu n'as encore enregistré aucun mouvement sur ce produit.</td></tr>
      <?php else: foreach ($monHisto as $row): ?>
        <tr class="<?= $mvClass[$row['type']] ?? '' ?>">
          <td class="date"><b><?= date('d/m', strtotime($row['cree_le'])) ?></b><?= date('H:i', strtotime($row['cree_le'])) ?></td>
          <td class="mv"><?= h($mvLabel[$row['type']] ?? $row['type']) ?></td>
          <td>
            <?php if ($row['intervention_ref']): ?>Interv. <span class="num"><?= h($row['intervention_ref']) ?></span> <?php endif; ?><?php if ($row['motif']): ?><?= h($row['motif']) ?><?php endif; ?>
            <?php $meta=array_filter([$row['lot']?'Lot '.$row['lot']:'', $row['date_exp']?'exp '.date('m/Y',strtotime($row['date_exp'])):'']); ?>
            <?php if ($meta): ?><div class="who"><?= h(implode(' · ',$meta)) ?></div><?php endif; ?>
          </td>
          <td class="r num delta <?= $row['qte_ampoules']>=0?'pos':'neg' ?>"><?= ($row['qte_ampoules']>0?'+':'').$pxQ($row['qte_ampoules']) ?></td>
          <td>
            <?php if ($row['type']==='administration' && $row['ouvert_mg']!==null): ?><span class="rel"><span class="b"><?= $pxQ($row['ouvert_mg']) ?></span>→<span class="b"><?= $pxQ($row['admin_mg']) ?></span>→<span class="destr"><?= $pxQ($row['detruit_mg']) ?> mg dét.</span></span>
            <?php elseif ($row['detruit_mg']!==null && (float)$row['detruit_mg']>0): ?><span class="destr"><?= $pxQ($row['detruit_mg']) ?> mg dét.</span><?php else: ?><span class="who">—</span><?php endif; ?>
          </td>
          <td class="r"><?php if(in_array($row['type'],['casse','perte','vol'],true)): ?><a href="#" onclick="stxPrint('stupefiants.php?print=declaration&id=<?= (int)$row['id'] ?>');return false" class="btn btn-ghost" style="padding:5px 10px" title="Réimprimer ma déclaration">🖨️</a><?php endif; ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div></div>
  </section>

  <!-- CONTRÔLE DU COFFRE -->
  <section class="view" id="view-garde">
    <div class="section-h"><h2>Contrôle du coffre</h2><div class="line"></div><span class="hint">Comptage physique · horodaté · écart ⇒ alerte admin</span></div>
    <div class="panel"><div class="panel-h"><span class="ic">🕓</span><h3>Nouveau contrôle</h3></div><div class="panel-b">
      <form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <?= csrfField() ?><input type="hidden" name="action" value="garde"><input type="hidden" name="produit_id" value="<?= $pid ?>">
        <div class="field" style="margin:0;width:120px"><label>Solde théorique</label><input class="num" value="<?= $pxQ($prod['quantite']) ?>" disabled style="font-weight:800"></div>
        <div class="field" style="margin:0;width:170px"><label>Compté (ampoules)</label><input class="num" type="number" name="compte" step="0.5" min="0" required placeholder="<?= $pxQ($prod['quantite']) ?>"></div>
        <button class="btn btn-primary" type="submit">✔️ Valider</button>
      </form>
      <p class="mand">🔒 <b>Comptage physique obligatoire.</b> La validation enregistre la date et l'heure exactes.</p>
      <?php if ($canRegistre && $lastAnomalie): ?>
      <div class="alert"><span style="font-size:19px">⚠️</span><div class="body"><b>Dernier écart — <?= date('d/m/Y à H:i', strtotime($lastAnomalie['cree_le'])) ?></b> : théorique <span class="num"><?= $pxQ($lastAnomalie['theorique']) ?></span>, compté <span class="num"><?= $pxQ($lastAnomalie['compte']) ?></span> → <b><?= $pxQ($lastAnomalie['ecart']) ?></b>.<br><span class="mut">Administrateur notifié — à régulariser.</span></div></div>
      <?php endif; ?>
    </div></div>

    <?php if ($canRegistre): ?>
    <div class="panel" style="margin-top:14px"><div class="panel-h"><span class="ic">🕓</span><h3>Historique des contrôles</h3><span class="pill role" style="margin-left:auto">🔒 Confidentiel</span></div>
      <div class="ledger-scroll"><table>
        <thead><tr><th>Date &amp; heure</th><th class="r">Théorique</th><th class="r">Compté</th><th class="r">Écart</th><th>Résultat</th><th>Agent</th></tr></thead>
        <tbody>
        <?php if (!$controles): ?>
          <tr><td colspan="6" class="empty" style="border-left:none">Aucun contrôle enregistré.</td></tr>
        <?php else: foreach ($controles as $ct): ?>
          <tr>
            <td class="date"><b><?= date('d/m/Y', strtotime($ct['cree_le'])) ?></b><?= date('H:i', strtotime($ct['cree_le'])) ?></td>
            <td class="r num"><?= $pxQ($ct['theorique']) ?></td>
            <td class="r num"><?= $pxQ($ct['compte']) ?></td>
            <td class="r num" style="color:<?= abs($ct['ecart'])<0.001?'#1D9E75':'#E24B4A' ?>;font-weight:700"><?= ($ct['ecart']>0?'+':'').$pxQ($ct['ecart']) ?></td>
            <td><?php if($ct['statut']==='conforme'): ?><span class="pill ok">✓ Conforme</span><?php else: ?><span class="pill crit">⚠️ Anomalie</span><?php endif; ?></td>
            <td><?= h($ct['agent'] ?: '—') ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>
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
    <div class="section-h"><h2>Déclarer une casse / perte / vol</h2><div class="line"></div><span class="hint">Ampoule cassée, souillée, perdue ou volée</span></div>
    <div class="panel"><div class="panel-h"><span class="ic">💥</span><h3>Casse / perte / vol d'ampoule</h3></div><div class="panel-b">
      <form method="post" id="casseform">
        <?= csrfField() ?><input type="hidden" name="action" value="casse"><input type="hidden" name="produit_id" value="<?= $pid ?>"><input type="hidden" name="motif_type" id="motif_type" value="casse">
        <div class="field"><label>Motif</label><div class="seg-choice" id="casse-motif"><button type="button" class="on" data-m="casse">💥 Casse / bris</button><button type="button" data-m="perte">📉 Perte</button><button type="button" data-m="vol">🚨 Vol</button></div></div>
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
          <li style="padding:6px 0">✅ Contrôles du coffre enregistrés</li>
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

  <?php endif; /* prod */ ?>

  <!-- COFFRE -->
  <section class="view" id="view-coffre">
    <div class="section-h"><h2>Coffres à stupéfiants</h2><div class="line"></div><span class="hint">Créer un coffre et voir son contenu (ampoules)</span></div>
    <?php if ($canCoffre): ?>
    <div class="panel" style="margin-bottom:14px"><div class="panel-b" style="padding:14px 16px">
      <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <?= csrfField() ?><input type="hidden" name="action" value="coffre_create">
        <div class="field" style="margin:0;flex:1;min-width:220px"><label>➕ Nouveau coffre — nom</label><input name="coffre_nom" required placeholder="Ex : Coffre — CSP Châlons"></div>
        <button class="btn btn-primary" type="submit">🏦 Créer le coffre</button>
      </form>
    </div></div>
    <?php endif; ?>

    <?php if ($canAddStup && $coffres): ?>
    <div class="panel" style="margin-bottom:14px"><div class="panel-h"><span class="ic">💊</span><h3>Ajouter un stupéfiant dans un coffre</h3></div><div class="panel-b">
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="action" value="stup_create">
        <div class="row2">
          <div class="field"><label>Coffre</label><select name="coffre_id" required><?php foreach($coffres as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['nom']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>DCI (molécule)</label><input name="dci" placeholder="Ex : Morphine" required></div>
        </div>
        <div class="row2">
          <div class="field"><label>Nom commercial <span class="opt">— optionnel</span></label><input name="nom" placeholder="Ex : Morphine Aguettant"></div>
          <div class="field"><label>Quantité initiale (ampoules)</label><input class="num" type="number" name="quantite" value="0" min="0" step="1"></div>
        </div>
        <div class="row2">
          <div class="field"><label>Dose par ampoule (mg)</label><input class="num" type="number" name="dose_mg" step="0.5" min="0" placeholder="10"></div>
          <div class="field"><label>Volume par ampoule (mL)</label><input class="num" type="number" name="volume_ml" step="0.5" min="0" placeholder="10"></div>
        </div>
        <div class="row2">
          <div class="field"><label>N° de lot</label><input class="num" name="lot" placeholder="M4482"></div>
          <div class="field"><label>Péremption</label><input type="date" name="date_exp"></div>
        </div>
        <div class="form-foot"><button class="btn btn-primary" type="submit">💊 Ajouter au coffre</button><span class="note">Crée le stupéfiant dans le coffre choisi (stock initial tracé au registre).</span></div>
      </form>
    </div></div>
    <?php endif; ?>

    <?php if (!$coffres): ?>
      <div class="panel"><div class="empty">Aucun coffre pour l'instant.<?php if($canCoffre): ?> Crée-en un ci-dessus.<?php else: ?> Demande à un administrateur d'en créer un.<?php endif; ?></div></div>
    <?php else: foreach ($coffres as $c): $tot=0; foreach($c['produits'] as $pp2){ $tot+=(float)$pp2['quantite']; } ?>
      <div class="panel" style="margin-bottom:14px">
        <div class="panel-h"><span class="ic">🔒</span><h3><?= h($c['nom']) ?></h3><span class="pill mut" style="margin-left:auto"><?= $pxQ($tot) ?> ampoule(s)</span>
          <?php if ($canCoffre && !$c['produits']): ?>
          <form method="post" onsubmit="return confirm('Supprimer ce coffre vide ?')" style="margin-left:10px">
            <?= csrfField() ?><input type="hidden" name="action" value="coffre_delete"><input type="hidden" name="coffre_id" value="<?= $c['id'] ?>">
            <button class="btn btn-ghost" type="submit" title="Supprimer le coffre">🗑️ Supprimer</button>
          </form>
          <?php endif; ?>
        </div>
        <div class="ledger-scroll"><table>
          <thead><tr><th>Produit</th><th>Dose / ampoule</th><th class="r">Quantité</th><th>Péremption</th><?php if($canCoffre): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
          <?php if (!$c['produits']): ?>
            <tr><td colspan="<?= $canCoffre?5:4 ?>" class="empty" style="border-left:none">Coffre vide — ajoute un stupéfiant ci-dessus.</td></tr>
          <?php else: foreach ($c['produits'] as $pp2): ?>
            <tr>
              <td class="mv"><?= h($pp2['dci'] ?: $pp2['nom']) ?></td>
              <td><?= $pp2['dose_mg']!==null ? $pxQ($pp2['dose_mg']).' mg'.($pp2['volume_ml']!==null?' / '.$pxQ($pp2['volume_ml']).' mL':'') : '—' ?></td>
              <td class="r num solde"><?= $pxQ($pp2['quantite']) ?></td>
              <td><?= $pp2['date_peremption'] ? date('m/Y', strtotime($pp2['date_peremption'])) : '—' ?></td>
              <?php if($canCoffre): ?>
              <td class="r"><form method="post" onsubmit="return confirm('Supprimer ce stupéfiant et tout son historique registre ?')" style="display:inline">
                <?= csrfField() ?><input type="hidden" name="action" value="stup_delete"><input type="hidden" name="produit_id" value="<?= $pp2['id'] ?>">
                <button class="btn btn-ghost" type="submit" title="Supprimer ce stupéfiant">🗑️</button>
              </form></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table></div>
      </div>
    <?php endforeach; endif; ?>
  </section>

  <!-- Fenêtre d'impression (overlay) -->
  <div id="stx-print-modal" style="display:none;position:fixed;inset:0;background:rgba(20,28,48,.55);z-index:3000;align-items:center;justify-content:center;padding:18px" onclick="if(event.target===this)stxPrintClose()">
    <div style="background:#fff;border-radius:14px;width:min(900px,96vw);height:92vh;display:flex;flex-direction:column;box-shadow:0 24px 70px rgba(0,0,0,.45);overflow:hidden">
      <div style="display:flex;align-items:center;gap:10px;padding:11px 15px;border-bottom:1px solid var(--border);background:var(--bg2)">
        <b style="flex:1;font-size:14px">Aperçu du document</b>
        <button class="btn btn-primary" type="button" onclick="stxPrintNow()">🖨️ Imprimer</button>
        <button class="btn btn-ghost" type="button" onclick="stxPrintClose()">✕ Fermer</button>
      </div>
      <iframe id="stx-print-frame" title="Aperçu impression" style="flex:1;border:none;width:100%;background:#eef1f7"></iframe>
    </div>
  </div>

</div>

<script>
function stxPrint(url){
  var m=document.getElementById('stx-print-modal'), f=document.getElementById('stx-print-frame');
  m.style.display='flex'; document.body.style.overflow='hidden';
  f.removeAttribute('src'); f.srcdoc='<p style="font-family:sans-serif;padding:24px;color:#5a6b8c">Chargement du document…</p>';
  fetch(url).then(function(r){return r.text();}).then(function(html){ f.srcdoc=html; }).catch(function(){ f.srcdoc='<p style="font-family:sans-serif;padding:24px;color:#c0392b">Erreur de chargement du document.</p>'; });
}
function stxPrintClose(){ var m=document.getElementById('stx-print-modal'), f=document.getElementById('stx-print-frame'); m.style.display='none'; f.srcdoc=''; document.body.style.overflow=''; }
function stxPrintNow(){ var f=document.getElementById('stx-print-frame'); try{ f.contentWindow.focus(); f.contentWindow.print(); }catch(e){ alert('Impression indisponible.'); } }
function stxAnalyse(){
  var btn=document.getElementById('stx-ia-btn'), out=document.getElementById('stx-ia-out'), body=out.querySelector('.panel-b');
  var old=btn.textContent; btn.disabled=true; btn.textContent='🤖 Analyse en cours…';
  out.style.display='block'; body.textContent='…';
  fetch('stupefiants.php?ajax=ia_analyse&p=<?= (int)$pid ?>')
    .then(function(r){return r.json();})
    .then(function(j){ btn.disabled=false; btn.textContent=old; body.textContent = j.ok ? j.texte : ('⚠️ '+(j.error||'Erreur IA')); })
    .catch(function(){ btn.disabled=false; btn.textContent=old; body.textContent='⚠️ Erreur réseau.'; });
}
(function(){
  var btns=[].slice.call(document.querySelectorAll('#stx .action[data-view]'));
  var views={registre:'view-registre',mon:'view-mon',garde:'view-garde',admin:'view-admin',casse:'view-casse',valider:'view-valider',coffre:'view-coffre'};
  function show(v){ btns.forEach(function(b){b.classList.toggle('on',b.dataset.view===v);}); Object.keys(views).forEach(function(k){var el=document.getElementById(views[k]); if(el) el.classList.toggle('show',k===v);}); }
  btns.forEach(function(b){ b.addEventListener('click',function(){ show(b.dataset.view); }); });
  var initial=(new URLSearchParams(location.search)).get('v');
  var hasInitial=initial && [].some.call(btns,function(b){return b.dataset.view===initial;});
  if(hasInitial) show(initial); else if(btns.length) show(btns[0].dataset.view);
  var decl=(new URLSearchParams(location.search)).get('decl');
  if(decl && /^\d+$/.test(decl)){ stxPrint('stupefiants.php?print=declaration&id='+decl); }

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
